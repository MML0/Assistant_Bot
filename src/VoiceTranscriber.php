<?php

namespace Bot;

use GuzzleHttp\ClientInterface;
use PDO;

/**
 * Premium-only voice → text (Persian replies), adapted from the original GapGPT Whisper snippet.
 *
 * Order matters: the paid-entitlement check runs before anything is downloaded or sent
 * to the (paid) transcription provider. Then size/duration/format limits, per-user rate
 * limit and daily quota, duplicate and concurrency guards. Temp audio is always deleted.
 */
final class VoiceTranscriber
{
    private PDO $db;
    private ClientInterface $http;
    private array $cfg;
    private Entitlements $entitlements;
    private JsonStore $store;
    private string $botToken;
    private string $telegramBase;
    private string $usageTable;
    /** @var callable|null fn(int $telegramUserId): bool — active PRO of any kind (used only if allow_referral_pro) */
    private $proCheck;
    /** @var callable fn(int|string $chatId, string $text): void */
    private $reply;
    /** @var callable|null */
    private $alert;
    /** @var callable */
    private $now;
    /** @var callable|null */
    private $access = null;
    /** @var callable|null */
    private $weeklyLimit = null;
    private bool $keepText = false;
    public string $text = '';

    public function __construct(PDO $db, ClientInterface $http, array $settings, Entitlements $entitlements, JsonStore $store, string $botToken, callable $reply, ?callable $alert = null, ?callable $now = null, ?callable $proCheck = null)
    {
        $this->db           = $db;
        $this->http         = $http;
        $this->cfg          = $settings['transcription'];
        $this->entitlements = $entitlements;
        $this->store        = $store;
        $this->botToken     = $botToken;
        $this->telegramBase = rtrim($settings['telegram_api_base'] ?? 'https://tapi.bale.ai', '/');
        $this->usageTable   = ($settings['table_prefix'] ?? 'v2_') . 'voice_usage';
        $this->proCheck     = $proCheck;
        $this->reply        = $reply;
        $this->alert        = $alert;
        $this->now          = $now ?? fn () => microtime(true);
    }

    /** @param callable $access telegram user id => daily minutes allowed; 0 = no access */
    public function withAccess(callable $access): self
    {
        $this->access = $access;
        return $this;
    }

    public static function isAudioMessage(array $message): bool
    {
        return isset($message['voice']) || isset($message['audio']) || self::audioDocument($message) !== null;
    }

    /** @return string outcome code (for logs/tests); the user always gets a short friendly reply. */
    public function handle(array $message, ?int $updateId = null, ?int $internalUserId = null): string
    {
        $chatId   = $message['chat']['id'] ?? null;
        $senderId = (int) ($message['from']['id'] ?? 0);
        $isGroup  = in_array($message['chat']['type'] ?? 'private', ['group', 'supergroup', 'channel'], true);
        $media    = $message['voice'] ?? $message['audio'] ?? self::audioDocument($message);
        if ($chatId === null || $senderId <= 0 || !is_array($media)) {
            return 'ignored';
        }
        if ($isGroup && !$this->cfg['allow_in_groups']) {
            return 'ignored_group';
        }

        // 1) Paid entitlement, server-side, before any download or provider call.
        $minutesAllowed = $this->access ? (int) ($this->access)($senderId) : null;
        $this->logMeta($media, $senderId, $minutesAllowed);
        if ($minutesAllowed !== null ? $minutesAllowed <= 0 : !$this->isEntitled($senderId)) {
            if (!$isGroup) {
                $this->send($chatId, "🎤 تبدیل پیام صوتی به متن فقط برای کاربرانی فعال است که اشتراک PRO را خریداری کرده‌اند.\n\nبرای خرید اشتراک: /buypro");
            }
            return 'denied';
        }
        if ($minutesAllowed !== null) {
            $this->cfg['max_minutes_per_day'] = $minutesAllowed;
        }
        $weekly = $this->weeklyLimit ? (int) ($this->weeklyLimit)($senderId) : 0;
        if ($weekly > 0 && $this->usedThisWeek($senderId) >= $weekly) {
            $this->send($chatId, "⏳ سهمیه این هفته تبدیل صدا به متن شما ({$weekly} پیام) تمام شده است.\n\nبرای سهمیه بیشتر: /buypro");
            return 'weekly_quota';
        }
        if (!$this->cfg['enabled'] || !$this->chain()) {
            $this->send($chatId, '❌ تبدیل صدا به متن فعلاً در دسترس نیست.');
            return 'disabled';
        }

        $dedupe = new UpdateDeduper($this->store, 'voice_seen.json', max(86400, (int) $this->cfg['duplicate_window_seconds']), $this->now);
        if ($updateId !== null && !$dedupe->firstSeen('u:' . $updateId)) {
            return 'duplicate_update'; // Telegram webhook retry: already handled
        }

        // 2) Metadata limits (Telegram tells us size/duration before we download).
        $size     = isset($media['file_size']) ? (int) $media['file_size'] : null;
        $duration = self::realSeconds((int) ($media['duration'] ?? 0), $size, (int) $this->cfg['max_duration_seconds']);
        $mime = strtolower((string) ($media['mime_type'] ?? ''));
        if ($duration > $this->cfg['max_duration_seconds']) {
            $this->send($chatId, "⚠️ فقط پیام‌های صوتی تا {$this->cfg['max_duration_seconds']} ثانیه قابل تبدیل به متن هستند.");
            return 'too_long';
        }
        if ($size !== null && $size > $this->cfg['max_file_bytes']) {
            $this->send($chatId, '⚠️ حجم این فایل صوتی برای تبدیل به متن زیاد است.');
            return 'too_large';
        }
        // A missing MIME type is allowed; the real content is checked after download.
        if ($mime !== '' && !in_array($mime, $this->cfg['allowed_mime_types'], true) && !preg_match('~^(audio/|video/(ogg|mp4|webm)$|application/(ogg|octet-stream)$)~', $mime)) {
            $this->send($chatId, '⚠️ این فرمت صوتی پشتیبانی نمی‌شود.');
            return 'invalid_format';
        }

        // 3) Per-user rate limit and daily allowance.
        try {
            [$lastHour, $minutesToday] = $this->usage($senderId);
        } catch (\Throwable $e) {
            $this->send($chatId, '❌ تبدیل صدا به متن فعلاً در دسترس نیست.');
            return 'usage_unavailable'; // cannot meter usage → do not spend
        }
        if ($lastHour >= $this->cfg['max_requests_per_hour']) {
            $this->send($chatId, '⏳ به سقف تبدیل صوت در این ساعت رسیدید. لطفاً کمی بعد دوباره تلاش کنید.');
            return 'rate_limited';
        }
        if ($minutesToday + $duration / 60 > $this->cfg['max_minutes_per_day']) {
            $this->send($chatId, "⏳ سهمیه امروز تبدیل صوت شما ({$this->cfg['max_minutes_per_day']} دقیقه) تمام شده است.");
            return 'quota_exceeded';
        }

        // 4) Concurrency: one job per user, a few globally.
        $userLock = $this->store->tryLock('voice_user_' . $senderId);
        if (!$userLock) {
            $this->send($chatId, '⏳ پیام صوتی قبلی شما در حال پردازش است. لطفاً کمی صبر کنید.');
            return 'busy';
        }
        $slot = null;
        for ($i = 0; $i < max(1, (int) $this->cfg['max_concurrent_global']); $i++) {
            if ($slot = $this->store->tryLock('voice_slot_' . $i)) {
                break;
            }
        }
        if (!$slot) {
            $this->store->release($userLock);
            $this->send($chatId, '⏳ سرویس تبدیل صوت مشغول است. لطفاً یک دقیقه دیگر تلاش کنید.');
            return 'busy';
        }

        $tmp = null;
        $usageId = null;
        try {
            // Bale sends an empty file_unique_id, which must not make every voice look the same.
            if (!empty($media['file_unique_id']) && !$dedupe->firstSeen('f:' . $senderId . ':' . $media['file_unique_id'])) {
                $this->send($chatId, 'ℹ️ این فایل صوتی قبلاً به متن تبدیل شده است.');
                return 'duplicate_audio';
            }

            $tmp = tempnam(sys_get_temp_dir(), 'tg_voice_');
            $bytes = $this->download((string) $media['file_id'], $tmp);
            if ($bytes === null) {
                $this->send($chatId, '❌ دریافت پیام صوتی ممکن نشد.');
                return 'download_failed';
            }
            if ($bytes > $this->cfg['max_file_bytes']) {
                $this->send($chatId, '⚠️ حجم این فایل صوتی برای تبدیل به متن زیاد است.');
                return 'too_large';
            }
            $kind = self::sniff($tmp);
            if ($kind === null) {
                $this->send($chatId, '⚠️ فایل صوتی نامعتبر یا خراب است.');
                return 'invalid_format';
            }

            // Count usage before the paid call, so a lost response is still metered.
            $cost = round($duration / 60 * (float) $this->cfg['cost_per_minute_usd'], 6);
            $usageId = $this->recordUsage($senderId, $internalUserId, $updateId, $duration, $bytes, 'pending', $cost);

            [$ok, $text, $why, $usedName, $usedModel] = $this->transcribe($tmp, $kind);
            $this->db->prepare("UPDATE {$this->usageTable} SET provider = ?, model = ? WHERE id = ?")->execute([$usedName, $usedModel, $usageId]);
            $this->setUsageStatus($usageId, $ok ? 'ok' : $why, $ok || $why === 'timeout' ? $cost : 0.0);
            if (!$ok) {
                $this->notify("🚨 Transcription failed ({$why}).");
                $this->send($chatId, '❌ متأسفانه تبدیل این پیام صوتی انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
                return 'provider_error';
            }
            if ($this->keepText) {
                $this->text = trim($text);
                if ($this->text === '') {
                    $this->send($chatId, '🎤 صحبتی در این پیام صوتی تشخیص داده نشد. لطفاً دوباره بفرستید.');
                }
                return 'transcribed';
            }
            $text = trim($text) === '' ? '(صحبتی تشخیص داده نشد)' : trim($text);
            foreach (self::chunks("📝 متن پیام صوتی:\n\n" . $text, 4000) as $part) {
                $this->send($chatId, $part);
            }
            return 'transcribed';
        } catch (\Throwable $e) {
            if ($usageId !== null) {
                $this->setUsageStatus($usageId, 'error', 0.0);
            }
            $this->notify('🚨 Transcription error: ' . Redactor::redact($e->getMessage(), 300));
            $this->send($chatId, '❌ متأسفانه تبدیل این پیام صوتی انجام نشد. لطفاً بعداً دوباره تلاش کنید.');
            return 'error';
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
            $this->store->release($slot);
            $this->store->release($userLock);
        }
    }

    /** @return array{0:int,1:float} requests in the last hour, minutes used today */
    private function usage(int $senderId): array
    {
        $now = (int) ($this->now)();
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->usageTable} WHERE telegram_user_id = ? AND created_at > ?");
        $stmt->execute([$senderId, date('Y-m-d H:i:s', $now - 3600)]);
        $hour = (int) $stmt->fetchColumn();
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) FROM {$this->usageTable} WHERE telegram_user_id = ? AND created_at >= ? AND status IN ('ok', 'pending', 'timeout')");
        $stmt->execute([$senderId, date('Y-m-d 00:00:00', $now)]);
        return [$hour, ((int) $stmt->fetchColumn()) / 60];
    }

    /**
     * Seconds of audio. Bale can report a duration far beyond what the file could hold (a one-second
     * voice arrived as over 300), so a value the size rules out is read as milliseconds.
     */
    public static function realSeconds(int $reported, ?int $size, int $max): int
    {
        if ($reported <= 0) {
            // Some clients omit the duration: estimate conservatively from size (~16 kbit/s voice).
            return $size ? max(1, (int) ceil($size / 2000)) : $max;
        }
        $impossible = $size !== null ? $reported > max(5, (int) ceil($size / 500)) : $reported > $max;
        if (!$impossible) {
            return $reported;
        }
        $fromMs = (int) ceil($reported / 1000);
        return $size !== null ? max($fromMs, (int) ceil($size / 2000)) : $fromMs;
    }

    /** A voice sent as a file arrives as a document with an audio type. */
    private static function audioDocument(array $message): ?array
    {
        $doc = $message['document'] ?? null;
        return is_array($doc) && str_starts_with(strtolower((string) ($doc['mime_type'] ?? '')), 'audio/') ? $doc : null;
    }

    /** The caller answers the voice itself: the words are kept in $text instead of being sent back. */
    public function keepingText(): self
    {
        $this->keepText = true;
        return $this;
    }

    /** Sets the voices-per-week allowance per user; 0 means no weekly limit. */
    public function withWeeklyLimit(callable $limit): self
    {
        $this->weeklyLimit = $limit;
        return $this;
    }

    /** Voices in the last 7 days. Failed ones do not count. */
    public function usedThisWeek(int $senderId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->usageTable} WHERE telegram_user_id = ? AND created_at >= ? AND status IN ('ok', 'pending', 'timeout')");
        $stmt->execute([$senderId, date('Y-m-d H:i:s', (int) ($this->now)() - 7 * 86400)]);
        return (int) $stmt->fetchColumn();
    }

    private function logMeta(array $media, int $senderId, ?int $minutes): void
    {
        if (defined('BOT_TESTING')) {
            return;
        }
        unset($media['file_id'], $media['file_unique_id']);
        $media += ['sender' => $senderId, 'minutes_allowed' => $minutes];
        @file_put_contents(dirname(__DIR__) . '/storage/logs/voice_meta.jsonl', date('Y-m-d H:i:s ') . json_encode($media, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function recordUsage(int $senderId, ?int $userId, ?int $updateId, int $duration, int $bytes, string $status, float $cost): int
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->usageTable} (telegram_user_id, user_id, update_id, duration_seconds, file_bytes, provider, model, status, estimated_cost_usd, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$senderId, $userId, $updateId, $duration, $bytes, parse_url($this->cfg['base_url'], PHP_URL_HOST) ?: 'unknown', $this->cfg['model'], $status, $cost, date('Y-m-d H:i:s', (int) ($this->now)())]);
        return (int) $this->db->lastInsertId();
    }

    private function setUsageStatus(int $id, string $status, float $cost): void
    {
        $stmt = $this->db->prepare("UPDATE {$this->usageTable} SET status = ?, estimated_cost_usd = ? WHERE id = ?");
        $stmt->execute([$status, $cost, $id]);
    }

    /** @return int|null bytes written, null on failure */
    private function download(string $fileId, string $dest): ?int
    {
        $max = (int) $this->cfg['max_file_bytes'];
        try {
            $res = $this->http->request('GET', "{$this->telegramBase}/bot{$this->botToken}/getFile", [
                'query' => ['file_id' => $fileId], 'timeout' => 15, 'connect_timeout' => 5, 'http_errors' => false,
            ]);
            $info = json_decode((string) $res->getBody(), true);
            $path = $info['result']['file_path'] ?? null;
            if (!is_string($path) || $path === '' || str_contains($path, '..')) {
                return null;
            }
            if (isset($info['result']['file_size']) && (int) $info['result']['file_size'] > $max) {
                return $max + 1;
            }
            $res = $this->http->request('GET', "{$this->telegramBase}/file/bot{$this->botToken}/{$path}", [
                'sink' => $dest, 'timeout' => 30, 'connect_timeout' => 5, 'http_errors' => false,
                'on_headers' => function ($response) use ($max) {
                    $len = $response->getHeaderLine('Content-Length');
                    if ($len !== '' && (int) $len > $max) {
                        throw new \RuntimeException('audio exceeds size limit');
                    }
                },
                'progress' => function ($total, $downloaded) use ($max) {
                    if ($downloaded > $max) {
                        throw new \RuntimeException('audio exceeds size limit');
                    }
                },
            ]);
            if ($res->getStatusCode() !== 200) {
                return null;
            }
            clearstatcache(true, $dest);
            return (int) filesize($dest);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'size limit')) {
                return $max + 1;
            }
            return null;
        }
    }

    /** Validates the real file content, not the claimed MIME type. */
    public static function sniff(string $path): ?array
    {
        $h = @file_get_contents($path, false, null, 0, 16);
        if ($h === false || strlen($h) < 12) {
            return null;
        }
        if (str_starts_with($h, 'OggS')) {
            return ['voice.ogg', 'audio/ogg'];
        }
        if (str_starts_with($h, 'ID3') || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) {
            return ['audio.mp3', 'audio/mpeg'];
        }
        if (substr($h, 4, 4) === 'ftyp') {
            return ['audio.m4a', 'audio/mp4'];
        }
        if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WAVE') {
            return ['audio.wav', 'audio/wav'];
        }
        if (str_starts_with($h, "\x1A\x45\xDF\xA3")) {
            return ['audio.webm', 'audio/webm'];
        }
        return null;
    }

    /** Providers in order; the single configured one when no order is set. */
    private function chain(): array
    {
        if (!empty($this->cfg['providers'])) {
            return $this->cfg['providers'];
        }
        return $this->cfg['api_key'] !== '' && $this->cfg['base_url'] !== ''
            ? [['name' => parse_url($this->cfg['base_url'], PHP_URL_HOST) ?: 'unknown', 'base_url' => $this->cfg['base_url'], 'api_key' => $this->cfg['api_key'], 'model' => $this->cfg['model']]]
            : [];
    }

    /** @return array{0:bool,1:string,2:string,3:string,4:string} ok, text, last failure reason, provider, model */
    private function transcribe(string $path, array $kind): array
    {
        $why = 'no_provider';
        $last = ['name' => '', 'model' => ''];
        foreach ($this->chain() as $p) {
            $last = $p;
            [$ok, $text, $why] = $this->transcribeWith($p, $path, $kind);
            if ($ok) {
                return [true, $text, '', $p['name'], $p['model']];
            }
        }
        return [false, '', $why, $last['name'], $last['model']];
    }

    /** @return array{0:bool,1:string,2:string} ok, text, failure-reason */
    private function transcribeWith(array $provider, string $path, array $kind): array
    {
        $parts = [
            ['name' => 'model', 'contents' => $provider['model']],
            ['name' => 'file', 'contents' => (string) file_get_contents($path), 'filename' => $kind[0], 'headers' => ['Content-Type' => $kind[1]]],
        ];
        if (!empty($this->cfg['language'])) {
            $parts[] = ['name' => 'language', 'contents' => $this->cfg['language']];
        }
        try {
            $res = $this->http->request('POST', rtrim($provider['base_url'], '/') . '/audio/transcriptions', [
                'headers'   => ['Authorization' => 'Bearer ' . $provider['api_key']],
                'multipart' => $parts,
                'timeout'   => (float) $this->cfg['timeout'],
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $err = ErrorClassifier::fromException($e, ($this->now)());
            return [false, '', $err['class']];
        }
        if ($res->getStatusCode() !== 200) {
            return [false, '', 'http_' . $res->getStatusCode()];
        }
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json)) {
            return [false, '', 'bad_response'];
        }
        return [true, (string) ($json['text'] ?? ''), ''];
    }

    public static function chunks(string $text, int $size): array
    {
        $out = [];
        while (mb_strlen($text) > $size) {
            $out[] = mb_substr($text, 0, $size);
            $text  = mb_substr($text, $size);
        }
        $out[] = $text;
        return $out;
    }

    private function isEntitled(int $senderId): bool
    {
        if ($this->entitlements->hasActivePaidPremium($senderId)) {
            return true;
        }
        if (!empty($this->cfg['allow_referral_pro']) && $this->proCheck) {
            try {
                return (bool) ($this->proCheck)($senderId);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    private function send($chatId, string $text): void
    {
        ($this->reply)($chatId, $text);
    }

    private function notify(string $text): void
    {
        if ($this->alert) {
            try {
                ($this->alert)(Redactor::redact($text, 500));
            } catch (\Throwable $e) {
            }
        }
    }
}
