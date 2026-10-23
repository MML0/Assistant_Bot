<?php
// Minimal self-contained test harness (no PHPUnit dependency). Run: php tests/run.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

final class T
{
    public static int $pass = 0;
    public static array $fail = [];
    public static string $current = '';

    public static function ok($cond, string $msg = ''): void
    {
        if ($cond) {
            self::$pass++;
        } else {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
            self::$fail[] = self::$current . ': ' . $msg . ' (line ' . $bt['line'] . ')';
        }
    }

    public static function eq($expected, $actual, string $msg = ''): void
    {
        $ok = $expected === $actual;
        if (!$ok) {
            $msg .= ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true);
        }
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        if ($ok) {
            self::$pass++;
        } else {
            self::$fail[] = self::$current . ': ' . $msg . ' (line ' . $bt['line'] . ')';
        }
    }
}

function test(string $name, callable $fn): void
{
    T::$current = $name;
    $before = count(T::$fail);
    try {
        $fn();
    } catch (\Throwable $e) {
        T::$fail[] = "$name: exception " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
    echo (count(T::$fail) === $before ? "  ✔ " : "  ✘ ") . $name . "\n";
}

function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/botlib_test_' . bin2hex(random_bytes(5));
    mkdir($d, 0700, true);
    return $d;
}

/** A Guzzle client whose responses come from a queue or a callable; records every request. */
final class FakeHttp
{
    public Client $client;
    public array $history = [];
    private $responder;

    /** @param callable(\Psr\Http\Message\RequestInterface, array): mixed $responder returns Response or Throwable */
    public function __construct(callable $responder)
    {
        $this->responder = $responder;
        $handler = function ($request, $options) {
            $r = ($this->responder)($request, $options);
            if ($r instanceof \Throwable) {
                return \GuzzleHttp\Promise\Create::rejectionFor($r);
            }
            if (isset($options['sink']) && is_string($options['sink'])) {
                file_put_contents($options['sink'], (string) $r->getBody());
                $r->getBody()->rewind();
            }
            if (isset($options['on_headers'])) {
                try {
                    ($options['on_headers'])($r);
                } catch (\Throwable $e) {
                    return \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\RequestException($e->getMessage(), $request, $r, $e));
                }
            }
            return \GuzzleHttp\Promise\Create::promiseFor($r);
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['handler' => $stack]);
    }

    public function urls(): array
    {
        return array_map(fn ($h) => (string) $h['request']->getUri(), $this->history);
    }

    public function count(string $needle): int
    {
        return count(array_filter($this->urls(), fn ($u) => str_contains($u, $needle)));
    }

    public function bodies(string $needle): array
    {
        $out = [];
        foreach ($this->history as $h) {
            if (str_contains((string) $h['request']->getUri(), $needle)) {
                $out[] = json_decode((string) $h['request']->getBody(), true);
            }
        }
        return $out;
    }
}

function json(int $status, $body, array $headers = []): Response
{
    return new Response($status, $headers + ['Content-Type' => 'application/json'], is_string($body) ? $body : json_encode($body));
}

function chatOk(string $text, string $model, ?float $cost = 0.0): Response
{
    $usage = ['prompt_tokens' => 50, 'completion_tokens' => 20];
    if ($cost !== null) {
        $usage['cost'] = $cost;
    }
    return json(200, ['model' => $model, 'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => $usage]);
}

function orError(int $status, string $message, array $extra = []): Response
{
    return json($status, ['error' => ['code' => $status, 'message' => $message] + $extra]);
}

function freeModel(string $id, float $intel = 50, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'name' => $id, 'context_length' => 128000, 'max_completion_tokens' => null,
        'input_modalities' => ['text'], 'supported_parameters' => ['max_tokens'],
        'intelligence' => $intel, 'coding' => null, 'rank_quality' => $intel, 'quality_estimated' => false,
        'free_verified' => true, 'source' => 'discovery',
    ];
}

/** Builds a Router over a temp store with a fixed catalogue and a fake clock. */
final class RouterRig
{
    public array $settings;
    public Bot\JsonStore $store;
    public FakeHttp $http;
    public Bot\ModelHealth $health;
    public Bot\ModelCatalog $catalog;
    public Bot\PaidBudget $budget;
    public Bot\RoutingLog $log;
    public Bot\Router $router;
    public array $alerts = [];
    public float $t = 1_800_000_000.0;

    public function __construct(array $models, callable $responder, array $overrides = [])
    {
        $now = fn () => $this->t;
        $config = Bot\Settings::mergeDeep([
            'gpt' => ['api_key' => 'tpsg-test-paid-key-000000000', 'base_url' => 'https://paid.example/openai/v1'],
            'openrouter' => ['api_key' => 'sk-or-test-key-0000000000000000', 'base_url' => 'https://openrouter.example/api/v1'],
            'telegram' => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi'],
        ], $overrides);
        $this->settings = Bot\Settings::fromConfig($config);
        $this->settings['storage_dir'] = tmpdir();
        $this->store   = new Bot\JsonStore($this->settings['storage_dir']);
        $this->http    = new FakeHttp($responder);
        $this->health  = new Bot\ModelHealth($this->store, $this->settings, $now);
        $this->catalog = new Bot\ModelCatalog($this->store, $this->http->client, $this->settings, $this->health, $now);
        if ($models) {
            $this->store->write('model_catalog.json', ['models' => $models, 'fetched_at' => $this->t, 'last_attempt_at' => $this->t, 'last_error' => null]);
        }
        $this->budget = new Bot\PaidBudget($this->store, $this->settings, $now);
        $this->log    = new Bot\RoutingLog($this->store, $now);
        $sleep = function (float $s) {
            $this->t += $s;
        };
        $this->router = new Bot\Router(
            $this->settings,
            $this->catalog,
            $this->health,
            new Bot\OpenRouterClient($this->http->client, $this->settings, $now),
            new Bot\PaidClient($this->http->client, $this->settings, $this->budget, $now, $sleep),
            $this->log,
            $this->store,
            function ($text) {
                $this->alerts[] = $text;
            },
            $now
        );
    }

    public function freeCalls(): int
    {
        return $this->http->count('openrouter.example/api/v1/chat/completions');
    }

    public function paidCalls(): int
    {
        return $this->http->count('paid.example');
    }

    public function attemptedModels(): array
    {
        return array_map(fn ($b) => $b['model'], $this->http->bodies('openrouter.example/api/v1/chat/completions'));
    }
}

function convo(string $last = 'Hello, how are you?'): array
{
    return [
        ['role' => 'system', 'content' => 'You are a helpful AI assistant.'],
        ['role' => 'user', 'content' => 'My name is Sara.'],
        ['role' => 'assistant', 'content' => 'Nice to meet you, Sara!'],
        ['role' => 'user', 'content' => $last],
    ];
}

function modelOf($request): ?string
{
    return json_decode((string) $request->getBody(), true)['model'] ?? null;
}
