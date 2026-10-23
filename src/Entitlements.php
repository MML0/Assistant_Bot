<?php

namespace Bot;

use PDO;

/**
 * Paid premium entitlements (voice-to-text).
 *
 * Separate from users.is_pro, which is also handed out for free (referral links, the
 * hourly "pro" code). Rows here are created only for a Bale payment that
 * inquireTransaction confirmed as `paid`, or by the admin's /grantpremium command.
 * Every check runs on the server against this table. Any error = denied.
 */
final class Entitlements
{
    public const FEATURE = 'premium';
    public const SOURCE_BALE_PAYMENT = 'bale_payment';
    public const SOURCE_ADMIN_VERIFIED_PAYMENT = 'admin_verified_payment';
    private const SOURCES = [self::SOURCE_BALE_PAYMENT, self::SOURCE_ADMIN_VERIFIED_PAYMENT];

    private PDO $db;
    private string $table;
    /** @var callable */
    private $now;

    public function __construct(PDO $db, string $prefix = 'v2_', ?callable $now = null)
    {
        $this->db    = $db;
        $this->table = $prefix . 'premium_entitlements';
        $this->now   = $now ?? fn () => microtime(true);
    }

    public function hasActivePaidPremium($telegramUserId): bool
    {
        if (!is_numeric($telegramUserId) || (int) $telegramUserId <= 0) {
            return false;
        }
        try {
            return $this->activeExpiry((int) $telegramUserId) !== null;
        } catch (\Throwable $e) {
            error_log('[entitlements] check failed, denying: ' . Redactor::redact($e->getMessage(), 200));
            return false; // fail closed
        }
    }

    /**
     * Extends from the later of now / current paid expiry. Returns the new expiry.
     * A payment reference is recorded once: granting the same reference again is a no-op.
     */
    public function grant(int $telegramUserId, int $days, int $grantedBy, ?string $paymentRef = null, string $source = self::SOURCE_ADMIN_VERIFIED_PAYMENT): string
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('unknown entitlement source');
        }
        if ($paymentRef !== null) {
            $stmt = $this->db->prepare("SELECT expires_at FROM {$this->table} WHERE payment_ref = ? AND source = ? LIMIT 1");
            $stmt->execute([$paymentRef, $source]);
            if (($existing = $stmt->fetchColumn()) !== false) {
                return (string) $existing;
            }
        }
        $now = (int) ($this->now)();
        $current = $this->activeExpiry($telegramUserId);
        $base = $current !== null ? max($now, strtotime($current)) : $now;
        $expires = date('Y-m-d H:i:s', $base + $days * 86400);

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} (telegram_user_id, feature, status, source, payment_ref, starts_at, expires_at, granted_by, created_at)
             VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$telegramUserId, self::FEATURE, $source, $paymentRef, date('Y-m-d H:i:s', $now), $expires, $grantedBy, date('Y-m-d H:i:s', $now)]);
        return $expires;
    }

    /** Cancels every active entitlement of the user. Returns the number of rows changed. */
    public function revoke(int $telegramUserId): int
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET status = 'cancelled', cancelled_at = ? WHERE telegram_user_id = ? AND status = 'active'");
        $stmt->execute([$this->ts(), $telegramUserId]);
        return $stmt->rowCount();
    }

    public function activeExpiry(int $telegramUserId): ?string
    {
        $now  = $this->ts();
        $in   = implode(',', array_fill(0, count(self::SOURCES), '?'));
        $stmt = $this->db->prepare(
            "SELECT MAX(expires_at) FROM {$this->table}
             WHERE telegram_user_id = ? AND feature = ? AND status = 'active' AND source IN ($in) AND starts_at <= ? AND expires_at > ?"
        );
        $stmt->execute(array_merge([$telegramUserId, self::FEATURE], self::SOURCES, [$now, $now]));
        $v = $stmt->fetchColumn();
        return $v ?: null;
    }

    private function ts(): string
    {
        return date('Y-m-d H:i:s', (int) ($this->now)());
    }
}
