<?php

namespace Bot;

use GuzzleHttp\ClientInterface;

/** One chat-completion attempt against one OpenRouter model. No retries here: the Router decides. */
final class OpenRouterClient
{
    private ClientInterface $http;
    private array $cfg;
    /** @var callable */
    private $now;

    public function __construct(ClientInterface $http, array $settings, ?callable $now = null)
    {
        $this->http = $http;
        $this->cfg  = $settings['openrouter'];
        $this->now  = $now ?? fn () => microtime(true);
    }

    public function hasKey(): bool
    {
        return $this->cfg['api_key'] !== '';
    }

    /**
     * @param array $model catalogue entry
     * @return array ok=true: content, model_used, tokens_in, tokens_out, cost, remaining
     *               ok=false: error (ErrorClassifier result)
     */
    public function chat(array $model, array $messages, string $task, int $maxTokens, float $timeout): array
    {
        $body = [
            'model'      => $model['id'],
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ];
        $params = $model['supported_parameters'] ?? [];
        $effort = $this->cfg['reasoning_effort'][$task] ?? null;
        if ($effort && in_array('reasoning', $params, true)) {
            $body['reasoning'] = ['effort' => $effort, 'exclude' => true];
        }
        if ($this->cfg['usage_accounting']) {
            $body['usage'] = ['include' => true];
        }
        if (!empty($this->cfg['provider_preferences'])) {
            $body['provider'] = $this->cfg['provider_preferences'];
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->cfg['api_key'],
            'Content-Type'  => 'application/json',
        ];
        if ($this->cfg['app_url'] !== '') {
            $headers['HTTP-Referer'] = $this->cfg['app_url'];
        }
        if ($this->cfg['app_title'] !== '') {
            $headers['X-Title'] = $this->cfg['app_title'];
        }

        $start = ($this->now)();
        try {
            $res = $this->http->request('POST', rtrim($this->cfg['base_url'], '/') . '/chat/completions', [
                'headers'         => $headers,
                'json'            => $body,
                'timeout'         => max(1.0, $timeout),
                'connect_timeout' => min((float) $this->cfg['connect_timeout'], max(1.0, $timeout)),
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => ErrorClassifier::fromException($e, ($this->now)()), 'latency_ms' => self::ms($start, ($this->now)())];
        }
        $latency = self::ms($start, ($this->now)());
        $status  = $res->getStatusCode();
        $raw     = (string) $res->getBody();
        $headersOut = $res->getHeaders();

        if ($status !== 200) {
            return ['ok' => false, 'error' => ErrorClassifier::fromHttp($status, $raw, $headersOut, ($this->now)()), 'latency_ms' => $latency];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => ErrorClassifier::fromHttp(502, $raw, $headersOut, ($this->now)()), 'latency_ms' => $latency];
        }
        if (isset($data['error'])) {
            return ['ok' => false, 'error' => ErrorClassifier::fromHttp(200, $raw, $headersOut, ($this->now)()), 'latency_ms' => $latency];
        }

        $choice  = $data['choices'][0] ?? [];
        $content = $choice['message']['content'] ?? null;
        if (is_array($content)) {
            $content = TaskClassifier::textOf($content);
        }
        $content = is_string($content) ? trim($content) : '';
        if (($choice['finish_reason'] ?? null) === 'error' || $content === '') {
            $err = ErrorClassifier::fromHttp(200, json_encode(['error' => ['message' => 'empty or errored completion']]), [], ($this->now)());
            $err['class'] = ErrorClassifier::EMPTY;
            $err['scope'] = 'model';
            return ['ok' => false, 'error' => $err, 'latency_ms' => $latency];
        }

        [, $remaining] = ErrorClassifier::retryInfo(ErrorClassifier::lowerHeaders($headersOut), ($this->now)());
        $usage = $data['usage'] ?? [];
        return [
            'ok'         => true,
            'content'    => $content,
            'model_used' => (string) ($data['model'] ?? $model['id']),
            'provider'   => $data['provider'] ?? null,
            'tokens_in'  => $usage['prompt_tokens'] ?? null,
            'tokens_out' => $usage['completion_tokens'] ?? null,
            'cost'       => isset($usage['cost']) && is_numeric($usage['cost']) ? (float) $usage['cost'] : null,
            'remaining'  => $remaining,
            'truncated'  => ($choice['finish_reason'] ?? null) === 'length',
            'latency_ms' => $latency,
        ];
    }

    private static function ms(float $a, float $b): int
    {
        return (int) round(($b - $a) * 1000);
    }
}
