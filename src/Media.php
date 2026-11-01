<?php

namespace Bot;

use GuzzleHttp\ClientInterface;
use PDO;

/**
 * Image generation and image understanding through the provider order set in the dashboard:
 * each provider is tried in turn and any failure moves to the next one.
 * Usage is written before the call, so limits hold even if a response is lost.
 */
final class Media
{
    private const PENDING_FILE = 'image_pending.json';
    private const PENDING_SECONDS = 300;
    private const MAX_IMAGE_BYTES = 10485760;

    private ?PDO $db;
    private ClientInterface $http;
    private array $s;
    private JsonStore $store;
    private string $botToken;
    private string $table;

    public function __construct(?PDO $db, ClientInterface $http, array $settings, JsonStore $store, string $botToken = '')
    {
        $this->db       = $db;
        $this->http     = $http;
        $this->s        = $settings;
        $this->store    = $store;
        $this->botToken = $botToken;
        $this->table    = ($settings['table_prefix'] ?? 'v2_') . 'media_usage';
    }

    // ------------------------------ limits ------------------------------

    public function used(int $userId, string $action, string $since): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE telegram_user_id = ? AND action = ? AND created_at >= ? AND status IN ('ok', 'pending')");
        $stmt->execute([$userId, $action, $since]);
        return (int) $stmt->fetchColumn();
    }

    /** @return string|null why the action is refused (Persian), null when it may run */
    public function refusal(int $userId, string $action, array $limits): ?string
    {
        if ($action === 'image') {
            if (empty($this->s['media']['images_enabled']) || $limits['image_per_day'] <= 0) {
                return "🖼 ساخت تصویر در پلن فعلی شما فعال نیست.\n\nبرای دیدن پلن‌ها: /buypro";
            }
            if ($this->used($userId, 'image', date('Y-m-d 00:00:00')) >= $limits['image_per_day']) {
                return "⏳ سهمیه امروز ساخت تصویر شما ({$limits['image_per_day']} تصویر) تمام شده است.";
            }
            $week = (int) ($limits['image_per_week'] ?? 0);
            if ($week > 0 && $this->used($userId, 'image', date('Y-m-d H:i:s', time() - 7 * 86400)) >= $week) {
                return "⏳ سهمیه این هفته ساخت تصویر شما ({$week} تصویر) تمام شده است.\n\nبرای سهمیه بیشتر: /buypro";
            }
            if ($limits['image_per_month'] > 0 && $this->used($userId, 'image', date('Y-m-01 00:00:00')) >= $limits['image_per_month']) {
                return "⏳ سهمیه این ماه ساخت تصویر شما ({$limits['image_per_month']} تصویر) تمام شده است.";
            }
            return null;
        }
        if (empty($this->s['media']['vision_enabled']) || $limits['vision_per_day'] <= 0) {
            return "👁 تحلیل تصویر در پلن فعلی شما فعال نیست.\n\nبرای دیدن پلن‌ها: /buypro";
        }
        if ($this->used($userId, 'vision', date('Y-m-d 00:00:00')) >= $limits['vision_per_day']) {
            return "⏳ سهمیه امروز تحلیل تصویر شما ({$limits['vision_per_day']} تصویر) تمام شده است.";
        }
        return null;
    }

    public function begin(int $userId, string $action): int
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->table} (telegram_user_id, action, provider, model, status, created_at) VALUES (?, ?, '', '', 'pending', ?)");
        $stmt->execute([$userId, $action, date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    /** A failed request gets a status other than ok/pending, so it does not use up the user's quota. */
    public function finish(int $id, array $result): void
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET provider = ?, model = ?, status = ? WHERE id = ?");
        $stmt->execute([(string) ($result['provider'] ?? ''), (string) ($result['model'] ?? ''), !empty($result['ok']) ? 'ok' : 'failed', $id]);
    }

    // ------------------------------ "send me the description" step ------------------------------

    public function setPending(int $userId): void
    {
        $until = time() + self::PENDING_SECONDS;
        $this->store->update(self::PENDING_FILE, function (&$d) use ($userId, $until) {
            $d = array_filter($d, fn ($t) => $t > time());
            $d[(string) $userId] = $until;
        });
    }

    public function takePending(int $userId): bool
    {
        $pending = $this->store->read(self::PENDING_FILE, []);
        if (!isset($pending[(string) $userId])) {
            return false;
        }
        $this->store->update(self::PENDING_FILE, function (&$d) use ($userId) {
            unset($d[(string) $userId]);
        });
        return $pending[(string) $userId] > time();
    }

    // ------------------------------ providers ------------------------------

    /** @return array{ok:bool,bytes?:string,provider?:string,model?:string,errors:array} */
    public function generateImage(string $prompt, ?string $only = null): array
    {
        $cfg = $this->s['media']['image'];
        $errors = [];
        foreach (Settings::mediaChain($this->s, 'image', $only) as $p) {
            $body = ['model' => $p['model'], 'prompt' => $prompt, 'n' => 1];
            if (!empty($cfg['size'])) {
                $body['size'] = $cfg['size'];
            }
            if (!empty($cfg['quality']) && str_starts_with($p['model'], 'gpt-image')) {
                $body['quality'] = $cfg['quality'];
            }
            try {
                $res = $this->http->request('POST', rtrim($p['base_url'], '/') . '/images/generations', [
                    'headers' => ['Authorization' => 'Bearer ' . $p['api_key'], 'Content-Type' => 'application/json'],
                    'json' => $body, 'timeout' => (float) ($cfg['timeout'] ?? 120), 'connect_timeout' => 8, 'http_errors' => false,
                ]);
                $raw = (string) $res->getBody();
                if ($res->getStatusCode() !== 200) {
                    $errors[] = "{$p['name']} {$p['model']}: HTTP {$res->getStatusCode()} " . Redactor::redact($raw, 200);
                    continue;
                }
                $item = json_decode($raw, true)['data'][0] ?? [];
                $bytes = null;
                if (!empty($item['b64_json'])) {
                    $bytes = base64_decode($item['b64_json'], true) ?: null;
                } elseif (!empty($item['url'])) {
                    $img = $this->http->request('GET', $item['url'], ['timeout' => 40, 'connect_timeout' => 8, 'http_errors' => false]);
                    $bytes = $img->getStatusCode() === 200 ? (string) $img->getBody() : null;
                }
                if ($bytes === null || self::imageMime($bytes) === null) {
                    $errors[] = "{$p['name']} {$p['model']}: no usable image in the answer";
                    continue;
                }
                return ['ok' => true, 'bytes' => $bytes, 'provider' => $p['name'], 'model' => $p['model'], 'errors' => $errors];
            } catch (\Throwable $e) {
                $errors[] = "{$p['name']} {$p['model']}: " . Redactor::redact($e->getMessage(), 200);
            }
        }
        return ['ok' => false, 'errors' => $errors ?: ['no provider configured']];
    }

    /** @return array{ok:bool,text?:string,provider?:string,model?:string,errors:array} */
    public function describeImage(string $bytes, string $mime, string $question, ?string $only = null): array
    {
        $errors = [];
        $content = [
            ['type' => 'text', 'text' => $question],
            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64," . base64_encode($bytes)]],
        ];
        foreach (Settings::mediaChain($this->s, 'vision', $only) as $p) {
            $body = ['model' => $p['model'], 'messages' => [
                ['role' => 'system', 'content' => $this->s['prompts']['vision']],
                ['role' => 'user', 'content' => $content],
            ]];
            $body[PaidClient::isReasoningModel($p['model']) ? 'max_completion_tokens' : 'max_tokens'] = 1500;
            try {
                $res = $this->http->request('POST', rtrim($p['base_url'], '/') . '/chat/completions', [
                    'headers' => ['Authorization' => 'Bearer ' . $p['api_key'], 'Content-Type' => 'application/json'],
                    'json' => $body, 'timeout' => 60, 'connect_timeout' => 8, 'http_errors' => false,
                ]);
                $raw = (string) $res->getBody();
                $text = json_decode($raw, true)['choices'][0]['message']['content'] ?? null;
                if ($res->getStatusCode() === 200 && is_string($text) && trim($text) !== '') {
                    return ['ok' => true, 'text' => trim($text), 'provider' => $p['name'], 'model' => $p['model'], 'errors' => $errors];
                }
                $errors[] = "{$p['name']} {$p['model']}: HTTP {$res->getStatusCode()} " . Redactor::redact($raw, 200);
            } catch (\Throwable $e) {
                $errors[] = "{$p['name']} {$p['model']}: " . Redactor::redact($e->getMessage(), 200);
            }
        }
        return ['ok' => false, 'errors' => $errors ?: ['no provider configured']];
    }

    /** @return array{0:string,1:string}|null bytes and MIME type of a photo sent to the bot */
    public function downloadPhoto(array $sizes): ?array
    {
        usort($sizes, fn ($a, $b) => ($a['file_size'] ?? 0) <=> ($b['file_size'] ?? 0));
        $sizes = array_values(array_filter($sizes, fn ($x) => ($x['file_size'] ?? 0) <= self::MAX_IMAGE_BYTES));
        $photo = end($sizes);
        if (!$photo || empty($photo['file_id'])) {
            return null;
        }
        $base = rtrim($this->s['telegram_api_base'] ?? 'https://tapi.bale.ai', '/');
        try {
            $res = $this->http->request('GET', "{$base}/bot{$this->botToken}/getFile", ['query' => ['file_id' => $photo['file_id']], 'timeout' => 15, 'connect_timeout' => 5, 'http_errors' => false]);
            $path = json_decode((string) $res->getBody(), true)['result']['file_path'] ?? null;
            if (!is_string($path) || $path === '' || str_contains($path, '..')) {
                return null;
            }
            $res = $this->http->request('GET', "{$base}/file/bot{$this->botToken}/{$path}", ['timeout' => 30, 'connect_timeout' => 5, 'http_errors' => false]);
            $bytes = (string) $res->getBody();
        } catch (\Throwable $e) {
            return null;
        }
        $mime = self::imageMime($bytes);
        return $res->getStatusCode() === 200 && $mime && strlen($bytes) <= self::MAX_IMAGE_BYTES ? [$bytes, $mime] : null;
    }

    /** The real file type, not what was claimed. */
    public static function imageMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }
}
