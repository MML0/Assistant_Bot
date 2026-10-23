<?php

namespace Bot;

use GuzzleHttp\ClientInterface;

/**
 * The existing paid (MetisAI, OpenAI-compatible) integration, moved out of api.php unchanged
 * in behaviour: gpt-5* models go through `responses` (sent as gpt-4o, as before), everything
 * else through `chat/completions`. Added: budget reservation, deadline, error classification,
 * jittered backoff and no retries on errors that cannot succeed.
 */
final class PaidClient
{
    private ClientInterface $http;
    private array $settings;
    private PaidBudget $budget;
    /** @var callable */
    private $now;
    /** @var callable */
    private $sleep;

    public function __construct(ClientInterface $http, array $settings, PaidBudget $budget, ?callable $now = null, ?callable $sleep = null)
    {
        $this->http     = $http;
        $this->settings = $settings;
        $this->budget   = $budget;
        $this->now      = $now ?? fn () => microtime(true);
        $this->sleep    = $sleep ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function isConfigured(): bool
    {
        return ($this->settings['gpt']['api_key'] ?? '') !== '' && ($this->settings['gpt']['base_url'] ?? '') !== '';
    }

    public function complete(string $model, array $messages, float $deadline, ?callable $onAttempt = null): array
    {
        $cfg     = $this->settings['paid'];
        $apiKey  = $this->settings['gpt']['api_key'];
        $baseUrl = rtrim($this->settings['gpt']['base_url'], '/');
        $isGpt5  = str_starts_with($model, 'gpt-5');
        $sentModel = $isGpt5 ? 'gpt-4o' : $model; // existing behaviour: gpt-5* is served by gpt-4o via `responses`
        $maxOut  = (int) $cfg['max_output_tokens'];
        $tokensIn = ContextFitter::messagesTokens($messages);
        $last    = null;

        for ($attempt = 1; $attempt <= $cfg['max_attempts']; $attempt++) {
            $remaining = $deadline - ($this->now)();
            if ($remaining < 2) {
                break;
            }
            $worst = $this->budget->estimateCost($sentModel, $tokensIn, $maxOut);
            [$allowed, $why] = $this->budget->reserve($worst);
            if (!$allowed) {
                return ['ok' => false, 'error' => ['class' => ErrorClassifier::BUDGET, 'scope' => 'account', 'status' => 0, 'detail' => $why, 'retry_at' => null, 'remaining' => null, 'possibly_processed' => false], 'model_used' => $sentModel];
            }

            $start = ($this->now)();
            $result = $this->attempt($isGpt5, $baseUrl, $apiKey, $sentModel, $messages, $maxOut, min((float) $cfg['attempt_timeout'], $remaining));
            $result['latency_ms'] = (int) round((($this->now)() - $start) * 1000);
            $result['attempt']    = $attempt;
            $result['model_used'] = $sentModel;

            if ($result['ok']) {
                $cost = $this->budget->estimateCost($sentModel, $result['tokens_in'] ?? $tokensIn, $result['tokens_out'] ?? ContextFitter::estimateTokens($result['content']));
                $this->budget->settle($worst, $cost);
                $result['cost'] = $cost;
                $result['cost_source'] = 'estimated';
                if ($onAttempt) {
                    $onAttempt($result);
                }
                return $result;
            }

            $err = $result['error'];
            // Only a lost response may have been billed; keep its reservation, release the rest.
            $this->budget->settle($worst, $err['possibly_processed'] ? $worst : 0.0);
            $result['cost'] = $err['possibly_processed'] ? $worst : 0.0;
            $result['cost_source'] = 'estimated';
            if ($onAttempt) {
                $onAttempt($result);
            }
            $last = $result;

            // Like the old bot: whatever the error, try once more. MetisAI forwards to a pool of
            // upstream keys, and a request it refuses (400/401/403/429…) often succeeds on the
            // next try. A Retry-After is honoured up to 5 seconds.
            $wait = 0.3 * $attempt + mt_rand(0, 200) / 1000;
            if ($err['retry_at']) {
                $wait = max($wait, min(5.0, $err['retry_at'] - ($this->now)()));
            }
            if (($this->now)() + $wait + 2 > $deadline) {
                break;
            }
            ($this->sleep)($wait);
        }
        return $last ?? ['ok' => false, 'error' => ['class' => ErrorClassifier::TIMEOUT, 'scope' => 'model', 'status' => 0, 'detail' => 'no time left for paid attempt', 'retry_at' => null, 'remaining' => null, 'possibly_processed' => false], 'model_used' => $sentModel];
    }

    private function attempt(bool $isGpt5, string $baseUrl, string $apiKey, string $model, array $messages, int $maxOut, float $timeout): array
    {
        $headers = ['Authorization' => "Bearer {$apiKey}", 'Content-Type' => 'application/json'];
        $opts = [
            'headers'         => $headers,
            'timeout'         => max(1.0, $timeout),
            'connect_timeout' => min((float) $this->settings['paid']['connect_timeout'], max(1.0, $timeout)),
            'http_errors'     => false,
        ];
        try {
            if ($isGpt5) {
                $opts['json'] = ['model' => $model, 'input' => $messages, 'max_output_tokens' => $maxOut];
                $res = $this->http->request('POST', $baseUrl . '/responses', $opts);
            } else {
                $opts['json'] = ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxOut];
                $res = $this->http->request('POST', $baseUrl . '/chat/completions', $opts);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => ErrorClassifier::fromException($e, ($this->now)())];
        }

        $raw = (string) $res->getBody();
        if ($res->getStatusCode() !== 200) {
            return ['ok' => false, 'error' => ErrorClassifier::fromHttp($res->getStatusCode(), $raw, $res->getHeaders(), ($this->now)())];
        }
        $data = json_decode($raw, true);
        $text = $isGpt5 ? ($data['output'][0]['content'][0]['text'] ?? null) : ($data['choices'][0]['message']['content'] ?? null);
        if ($isGpt5 && !$text && isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $item) { // reasoning items may come before the message
                if (($item['type'] ?? '') === 'message' && !empty($item['content'][0]['text'])) {
                    $text = $item['content'][0]['text'];
                    break;
                }
            }
        }
        if (!is_string($text) || trim($text) === '') {
            $err = ErrorClassifier::fromHttp(502, '{"error":{"message":"No content returned."}}', [], ($this->now)());
            $err['class'] = ErrorClassifier::EMPTY;
            $err['scope'] = 'model';
            return ['ok' => false, 'error' => $err];
        }
        $u = $data['usage'] ?? [];
        return [
            'ok'         => true,
            'content'    => $text,
            'tokens_in'  => $u['prompt_tokens'] ?? $u['input_tokens'] ?? null,
            'tokens_out' => $u['completion_tokens'] ?? $u['output_tokens'] ?? null,
        ];
    }
}
