<?php

namespace Bot;

use GuzzleHttp\Exception\RequestException;

/**
 * Turns an HTTP failure or transport exception into a routing decision.
 *
 * scope = 'model'   → only this model is affected; try the next candidate.
 *         'account' → every free model on this key is affected; stop the free chain.
 *         'request' → the request itself is the problem; switching models will not help.
 */
final class ErrorClassifier
{
    public const RATE_LIMIT      = 'rate_limit';          // upstream provider limit for one model
    public const ACCOUNT_DAILY   = 'account_daily_quota';  // free-models-per-day exhausted
    public const ACCOUNT_RATE    = 'account_rate_limit';   // free-models-per-minute exceeded
    public const PROVIDER        = 'provider_unavailable';
    public const TIMEOUT         = 'timeout';
    public const NETWORK         = 'network';
    public const INVALID_MODEL   = 'invalid_model';
    public const UNSUPPORTED     = 'unsupported';
    public const AUTH            = 'auth';
    public const CREDITS         = 'insufficient_credits';
    public const INVALID_REQUEST = 'invalid_request';
    public const CONTEXT         = 'context_length';
    public const REFUSAL         = 'content_refusal';
    public const EMPTY           = 'empty_response';
    public const BUDGET          = 'paid_budget_exhausted';
    public const UNKNOWN         = 'unknown';

    private const SCOPES = [
        self::RATE_LIMIT => 'model', self::PROVIDER => 'model', self::TIMEOUT => 'model',
        self::INVALID_MODEL => 'model', self::UNSUPPORTED => 'model', self::CONTEXT => 'model',
        self::EMPTY => 'model', self::UNKNOWN => 'model',
        self::ACCOUNT_DAILY => 'account', self::ACCOUNT_RATE => 'account', self::CREDITS => 'account',
        self::AUTH => 'account', self::NETWORK => 'account', self::BUDGET => 'account',
        self::INVALID_REQUEST => 'request', self::REFUSAL => 'request',
    ];

    public static function scope(string $class): string
    {
        return self::SCOPES[$class] ?? 'model';
    }

    public static function fromException(\Throwable $e, float $now): array
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $r = $e->getResponse();
            return self::fromHttp($r->getStatusCode(), (string) $r->getBody(), $r->getHeaders(), $now);
        }
        $msg = $e->getMessage();
        $m   = strtolower($msg);
        $readTimeout = str_contains($m, 'operation timed out') || (str_contains($m, 'timed out') && str_contains($m, 'bytes received'));
        if ($readTimeout) {
            // The provider may have processed (and counted) the request even though we lost the answer.
            return self::make(self::TIMEOUT, 0, $msg, null, null, true);
        }
        if (preg_match('~could not resolve|failed to connect|connection refused|connection timed out|resolving timed out|ssl|tls|network is unreachable|connection reset~', $m)) {
            return self::make(self::NETWORK, 0, $msg);
        }
        if (str_contains($m, 'timed out') || str_contains($m, 'timeout')) {
            return self::make(self::TIMEOUT, 0, $msg, null, null, true);
        }
        return self::make(self::UNKNOWN, 0, $msg);
    }

    /** @param array<string,string[]|string> $headers */
    public static function fromHttp(int $status, string $body, array $headers, float $now): array
    {
        $json = json_decode($body, true);
        $err  = is_array($json) ? ($json['error'] ?? null) : null;
        $msg  = '';
        $metaHeaders = [];
        if (is_array($err)) {
            $msg = (string) ($err['message'] ?? '');
            $raw = $err['metadata']['raw'] ?? null;
            if (is_string($raw)) {
                $msg .= ' | ' . $raw;
            } elseif (is_array($raw)) {
                $msg .= ' | ' . json_encode($raw);
            }
            if (isset($err['metadata']['headers']) && is_array($err['metadata']['headers'])) {
                $metaHeaders = $err['metadata']['headers'];
            }
            if ($status === 200 && isset($err['code']) && is_numeric($err['code'])) {
                $status = (int) $err['code'];
            }
        } elseif (is_string($err)) {
            $msg = $err;
        } else {
            $msg = mb_substr($body, 0, 300);
        }

        $h = self::lowerHeaders($headers + $metaHeaders);
        [$retryAt, $remaining] = self::retryInfo($h, $now);
        $m = strtolower($msg);

        $isContext = (bool) preg_match('~context length|context_length|maximum context|context window|too many tokens|prompt is too long|reduce the length|token limit|maximum.*tokens~', $m);
        $isUnsupported = (bool) preg_match('~does not support|not supported|unsupported|no endpoints found that support|modalit|image input|tool use|tool_choice~', $m);

        switch (true) {
            case $status === 401:
                return self::make(self::AUTH, $status, $msg);
            case $status === 402:
                return self::make(self::CREDITS, $status, $msg);
            case $status === 403:
                if (preg_match('~flagged|moderat|content policy|safety~', $m)) {
                    return self::make(self::REFUSAL, $status, $msg);
                }
                if (preg_match('~region|not available|unavailable~', $m)) {
                    return self::make(self::PROVIDER, $status, $msg, $retryAt);
                }
                return self::make(self::AUTH, $status, $msg);
            case $status === 404:
                return self::make($isUnsupported ? self::UNSUPPORTED : self::INVALID_MODEL, $status, $msg);
            case $status === 408:
                return self::make(self::TIMEOUT, $status, $msg, $retryAt, null, true);
            case $status === 413:
                return self::make(self::CONTEXT, $status, $msg);
            case $status === 429:
                if (preg_match('~per-day|per day|daily|free-models-per-day~', $m)) {
                    return self::make(self::ACCOUNT_DAILY, $status, $msg, $retryAt, $remaining);
                }
                if (preg_match('~per-min|per minute|free-models-per-min~', $m)) {
                    return self::make(self::ACCOUNT_RATE, $status, $msg, $retryAt, $remaining);
                }
                return self::make(self::RATE_LIMIT, $status, $msg, $retryAt, $remaining);
            case $status === 400 || $status === 422:
                if ($isContext) {
                    return self::make(self::CONTEXT, $status, $msg);
                }
                // "The model `gpt-4.1-nano` has been deprecated", "The model `x` does not exist…"
                if (preg_match('~not a valid model|invalid model|model not found|model_not_found|unknown model|model\b.{0,60}\b(deprecated|decommissioned|retired|does not exist|no longer (available|supported))~', $m)) {
                    return self::make(self::INVALID_MODEL, $status, $msg);
                }
                if ($isUnsupported) {
                    return self::make(self::UNSUPPORTED, $status, $msg);
                }
                return self::make(self::INVALID_REQUEST, $status, $msg);
            case $status >= 500:
                return self::make(self::PROVIDER, $status, $msg, $retryAt);
        }
        return self::make(self::UNKNOWN, $status, $msg, $retryAt);
    }

    private static function make(string $class, int $status, string $detail, ?float $retryAt = null, ?int $remaining = null, bool $possiblyProcessed = false): array
    {
        return [
            'class'              => $class,
            'scope'              => self::scope($class),
            'status'             => $status,
            'retry_at'           => $retryAt,
            'remaining'          => $remaining,
            'possibly_processed' => $possiblyProcessed,
            'detail'             => Redactor::redact($detail, 300),
        ];
    }

    public static function lowerHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower((string) $k)] = is_array($v) ? (string) reset($v) : (string) $v;
        }
        return $out;
    }

    /** @return array{0:?float,1:?int} absolute retry time and remaining count, when the API tells us */
    public static function retryInfo(array $h, float $now): array
    {
        $retryAt = null;
        if (isset($h['retry-after']) && $h['retry-after'] !== '') {
            $ra = $h['retry-after'];
            if (is_numeric($ra)) {
                $retryAt = $now + max(0.0, (float) $ra);
            } elseif (($ts = strtotime($ra)) !== false) {
                $retryAt = (float) $ts;
            }
        }
        if ($retryAt === null && isset($h['x-ratelimit-reset']) && is_numeric($h['x-ratelimit-reset'])) {
            $reset = (float) $h['x-ratelimit-reset'];
            if ($reset > 1e11) {
                $reset /= 1000; // OpenRouter reports milliseconds since epoch
            }
            if ($reset > $now) {
                $retryAt = $reset;
            }
        }
        $remaining = isset($h['x-ratelimit-remaining']) && is_numeric($h['x-ratelimit-remaining'])
            ? (int) $h['x-ratelimit-remaining'] : null;
        return [$retryAt, $remaining];
    }
}
