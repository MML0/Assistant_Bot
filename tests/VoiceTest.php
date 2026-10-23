<?php

use Bot\Entitlements;
use Bot\JsonStore;
use Bot\Settings;
use Bot\VoiceTranscriber;

const PREMIUM_ID = 111;
const FREE_ID    = 222;
const OGG = "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00fake-opus-audio-bytes";

function voiceDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Same columns as Entitlements::mysqlSchema(), SQLite syntax.
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, chat_id INTEGER, is_pro INTEGER DEFAULT 0, pro_expire TEXT, model TEXT)');
    $db->exec('CREATE TABLE premium_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_user_id INTEGER NOT NULL, feature TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'active\', source TEXT NOT NULL, payment_ref TEXT, starts_at TEXT NOT NULL, expires_at TEXT NOT NULL,
        granted_by INTEGER NOT NULL, created_at TEXT NOT NULL, cancelled_at TEXT)');
    $db->exec('CREATE TABLE voice_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_user_id INTEGER NOT NULL, user_id INTEGER, update_id INTEGER,
        duration_seconds INTEGER NOT NULL, file_bytes INTEGER, provider TEXT NOT NULL, model TEXT NOT NULL, status TEXT NOT NULL,
        estimated_cost_usd REAL NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
    // A "PRO" user who got PRO for free (referral / /start / hourly code) — must NOT unlock voice.
    $db->exec('INSERT INTO users (id, chat_id, is_pro, pro_expire, model) VALUES (1, ' . FREE_ID . ', 1, NULL, \'gpt-4.1-mini\')');
    $db->exec('INSERT INTO users (id, chat_id, is_pro, pro_expire, model) VALUES (2, ' . PREMIUM_ID . ', 1, NULL, \'gpt-4.1-mini\')');
    return $db;
}

final class VoiceRig
{
    public PDO $db;
    public FakeHttp $http;
    public array $replies = [];
    public array $alerts = [];
    public VoiceTranscriber $voice;
    public Entitlements $ent;
    public JsonStore $store;
    public float $t;
    public string $audio = OGG;
    public int $providerStatus = 200;
    public array $settings;

    public function __construct(array $overrides = [])
    {
        $this->t  = (float) strtotime('2026-10-23 12:00:00');
        $now      = fn () => $this->t;
        $this->db = voiceDb();
        $this->settings = Settings::fromConfig(Settings::mergeDeep(['transcription' => ['api_key' => 'sk-test-transcription-key-0000']], $overrides));
        $this->store = new JsonStore(tmpdir());
        $this->http  = new FakeHttp(function ($req) {
            $u = (string) $req->getUri();
            if (str_contains($u, '/getFile')) {
                return json(200, ['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga', 'file_size' => strlen($this->audio)]]);
            }
            if (str_contains($u, '/file/bot')) {
                return new GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string) strlen($this->audio)], $this->audio);
            }
            if (str_contains($u, '/audio/transcriptions')) {
                return $this->providerStatus === 200 ? json(200, ['text' => 'سلام، این یک تست است']) : json($this->providerStatus, ['error' => 'boom']);
            }
            return json(404, []);
        });
        $this->ent   = new Entitlements($this->db, $now);
        $this->voice = new VoiceTranscriber($this->db, $this->http->client, $this->settings, $this->ent, $this->store, '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi',
            function ($chat, $text) {
                $this->replies[] = $text;
            }, function ($t) {
                $this->alerts[] = $t;
            }, $now);
    }

    public function msg(int $from, array $voice = [], string $chatType = 'private'): array
    {
        return [
            'message_id' => 5,
            'from' => ['id' => $from, 'first_name' => 'Premium', 'username' => 'premium_vip_user'],
            'chat' => ['id' => $chatType === 'private' ? $from : -100500, 'type' => $chatType],
            'voice' => $voice + ['file_id' => 'FILEID', 'file_unique_id' => 'UNIQ' . mt_rand(), 'duration' => 12, 'mime_type' => 'audio/ogg', 'file_size' => strlen(OGG)],
        ];
    }

    public function providerCalls(): int
    {
        return $this->http->count('/audio/transcriptions');
    }

    public function telegramCalls(): int
    {
        return $this->http->count('api.telegram.org');
    }
}

function tmpFilesLeft(): int
{
    return count(glob(sys_get_temp_dir() . '/tg_voice_*'));
}

test('ordinary user (even with free PRO) cannot transcribe; nothing downloaded or sent', function () {
    $rig = new VoiceRig();
    T::eq('denied', $rig->voice->handle($rig->msg(FREE_ID), 1));
    T::eq(0, count($rig->http->history), 'no Telegram download, no provider call');
    T::ok(str_contains($rig->replies[0], 'premium'), 'friendly premium message');
});

test('/start, referral friend, premium-looking name: still no access', function () {
    $rig = new VoiceRig();
    $m = $rig->msg(FREE_ID);
    $m['from']['username'] = 'premium_paid_vip';
    $m['from']['is_premium'] = true;         // Telegram Premium ≠ our paid premium
    $m['caption'] = 'I already paid!';
    T::eq('denied', $rig->voice->handle($m, 2));
    T::eq(0, $rig->providerCalls());
});

test('verified paid premium user can transcribe; usage + cost recorded; temp file deleted', function () {
    $rig = new VoiceRig();
    $before = tmpFilesLeft();
    $rig->ent->grant(PREMIUM_ID, 30, 999, 'zarinpal-123');
    T::eq('transcribed', $rig->voice->handle($rig->msg(PREMIUM_ID), 3, 2));
    T::eq(1, $rig->providerCalls());
    T::ok(str_contains(end($rig->replies), 'سلام، این یک تست است'));
    $row = $rig->db->query('SELECT * FROM voice_usage')->fetch(PDO::FETCH_ASSOC);
    T::eq('ok', $row['status']);
    T::eq(PREMIUM_ID, (int) $row['telegram_user_id']);
    T::ok((float) $row['estimated_cost_usd'] > 0, 'cost estimate stored for monitoring');
    T::eq($before, tmpFilesLeft(), 'audio not retained');
    $sent = $rig->http->history[2]['request'];
    T::ok(str_contains($sent->getHeaderLine('Authorization'), 'sk-test-transcription-key'), 'key from config, not source');
    T::ok(str_contains((string) $sent->getBody(), 'gapgpt/whisper-1'), 'existing provider model kept');
});

test('expired, cancelled and unverifiable premium are denied', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 1, 999);
    $rig->t += 2 * 86400;
    T::eq('denied', $rig->voice->handle($rig->msg(PREMIUM_ID), 10), 'expired');

    $rig2 = new VoiceRig();
    $rig2->ent->grant(PREMIUM_ID, 30, 999);
    $rig2->ent->revoke(PREMIUM_ID);
    T::eq('denied', $rig2->voice->handle($rig2->msg(PREMIUM_ID), 11), 'cancelled');

    $rig3 = new VoiceRig();
    $rig3->db->exec("INSERT INTO premium_entitlements (telegram_user_id, feature, status, source, starts_at, expires_at, granted_by, created_at)
        VALUES (" . PREMIUM_ID . ", 'premium', 'active', 'referral', '2026-01-01 00:00:00', '2030-01-01 00:00:00', 0, '2026-01-01 00:00:00')");
    T::eq('denied', $rig3->voice->handle($rig3->msg(PREMIUM_ID), 12), 'non-payment source ignored');

    $rig4 = new VoiceRig();
    $rig4->ent->grant(PREMIUM_ID, 30, 999);
    $rig4->db->exec('DROP TABLE premium_entitlements');
    T::eq('denied', $rig4->voice->handle($rig4->msg(PREMIUM_ID), 13), 'DB error fails closed');
    T::eq(0, $rig->providerCalls() + $rig2->providerCalls() + $rig3->providerCalls() + $rig4->providerCalls());
});

test('oversized, too long and malformed audio are rejected safely', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('too_large', $rig->voice->handle($rig->msg(PREMIUM_ID, ['file_size' => 50 * 1024 * 1024]), 20));
    T::eq('too_long', $rig->voice->handle($rig->msg(PREMIUM_ID, ['duration' => 3600]), 21));
    T::eq('invalid_format', $rig->voice->handle($rig->msg(PREMIUM_ID, ['mime_type' => 'application/x-msdownload']), 22));
    T::eq(0, count($rig->http->history), 'rejected from metadata before download');

    $rig->audio = "MZ\x90\x00 this is an exe not audio";
    T::eq('invalid_format', $rig->voice->handle($rig->msg(PREMIUM_ID), 23), 'content sniffing');
    T::eq(0, $rig->providerCalls(), 'malformed file never reaches the provider');

    $rig->audio = OGG . str_repeat('x', 200);
    $small = new VoiceRig(['transcription' => ['max_file_bytes' => 100]]);
    $small->audio = $rig->audio;
    $small->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('too_large', $small->voice->handle($small->msg(PREMIUM_ID, ['file_size' => 50]), 24), 'real size checked after Telegram lies');
    T::eq(0, $small->providerCalls());
});

test('duplicate updates and duplicate audio are not processed twice', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    $m = $rig->msg(PREMIUM_ID, ['file_unique_id' => 'SAME']);
    T::eq('transcribed', $rig->voice->handle($m, 30));
    T::eq('duplicate_update', $rig->voice->handle($m, 30), 'webhook retry');
    T::eq('duplicate_audio', $rig->voice->handle($m, 31), 'same audio forwarded again');
    T::eq(1, $rig->providerCalls());
});

test('per-user hourly rate limit and daily minutes quota', function () {
    $rig = new VoiceRig(['transcription' => ['max_requests_per_hour' => 2, 'max_minutes_per_day' => 30]]);
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('transcribed', $rig->voice->handle($rig->msg(PREMIUM_ID), 40));
    T::eq('transcribed', $rig->voice->handle($rig->msg(PREMIUM_ID), 41));
    T::eq('rate_limited', $rig->voice->handle($rig->msg(PREMIUM_ID), 42));
    T::eq(2, $rig->providerCalls());

    $q = new VoiceRig(['transcription' => ['max_minutes_per_day' => 1]]);
    $q->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('transcribed', $q->voice->handle($q->msg(PREMIUM_ID, ['duration' => 50]), 43));
    T::eq('quota_exceeded', $q->voice->handle($q->msg(PREMIUM_ID, ['duration' => 20]), 44));
});

test('concurrent request from the same user is refused', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    $lock = $rig->store->tryLock('voice_user_' . PREMIUM_ID); // simulate a job in progress
    T::eq('busy', $rig->voice->handle($rig->msg(PREMIUM_ID), 50));
    $rig->store->release($lock);
    T::eq(0, $rig->providerCalls());
});

test('provider failure: friendly message, no key or raw error leaked, usage marked', function () {
    $rig = new VoiceRig();
    $rig->providerStatus = 500;
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('provider_error', $rig->voice->handle($rig->msg(PREMIUM_ID), 60));
    $last = end($rig->replies);
    T::ok(str_contains($last, "couldn't transcribe") && !str_contains($last, 'boom'), 'no raw provider error to user');
    T::eq('http_500', $rig->db->query('SELECT status FROM voice_usage')->fetchColumn());
    T::eq(0.0, (float) $rig->db->query('SELECT estimated_cost_usd FROM voice_usage')->fetchColumn(), 'failed call not billed in estimate');
});

test('groups: denied silently by default', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    T::eq('ignored_group', $rig->voice->handle($rig->msg(PREMIUM_ID, [], 'supergroup'), 70));
    T::eq([], $rig->replies);
});

test('usage cost can be monitored per user', function () {
    $rig = new VoiceRig();
    $rig->ent->grant(PREMIUM_ID, 30, 999);
    $rig->voice->handle($rig->msg(PREMIUM_ID, ['duration' => 60]), 80);
    $rig->voice->handle($rig->msg(PREMIUM_ID, ['duration' => 30]), 81);
    $sum = (float) $rig->db->query('SELECT SUM(estimated_cost_usd) FROM voice_usage WHERE telegram_user_id = ' . PREMIUM_ID)->fetchColumn();
    T::ok(abs($sum - 0.009) < 1e-6, 'sum of per-minute estimates (0.006 + 0.003)');
});

test('grant extends an active entitlement instead of replacing it', function () {
    $rig = new VoiceRig();
    $first  = $rig->ent->grant(PREMIUM_ID, 10, 999);
    $second = $rig->ent->grant(PREMIUM_ID, 10, 999);
    T::eq(20 * 86400, strtotime($second) - strtotime('2026-10-23 12:00:00'));
    T::ok($first < $second);
});
