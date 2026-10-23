<?php

namespace Bot;

use PDO;

/**
 * Paid premium entitlements (voice transcription).
 *
 * Deliberately separate from users.is_pro, which is also handed out for free
 * (referrals, the hourly "pro" code). Rows here are created only by the admin's
 * /grantpremium command after the admin has verified a payment. Every check runs on
 * the server against this table; nothing a user sends can set it. Any error = denied.
 */
final class Entitlements
{
    public const FEATURE = 'premium';
    public const SOURCE_ADMIN_VERIFIED_PAYMENT = 'admin_verified_payment';

    private PDO $db;
    /** @var callable */
    private $now;

    public function __construct(PDO $db, ?callable $now = null)
    {
        $this->db  = $db;
        $this->now = $now ?? fn () => microtime(true);
    }

    public function hasActivePaidPremium($telegramUserId): bool
    {
        if (!is_numeric($telegramUserId) || (int) $telegramUserId <= 0) {
            return false;
        }
        try {
            $now  = $this->ts();
            $stmt = $this->db->prepare(
                "SELECT 1 FROM premium_entitlements
                 WHERE telegram_user_id = ? AND feature = ? AND status = 'active' AND source = ?
                   AND starts_at <= ? AND expires_at > ?
                 LIMIT 1"
            );
            $stmt->execute([(int) $telegramUserId, self::FEATURE, self::SOURCE_ADMIN_VERIFIED_PAYMENT, $now, $now]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            error_log('[entitlements] check failed, denying: ' . Redactor::redact($e->getMessage(), 200));
            return false; // fail closed
        }
    }

    /** Extends from the later of now / current expiry. Returns the new expiry. */
    public function grant(int $telegramUserId, int $days, int $grantedBy, ?string $paymentRef = null): string
    {
        $now = (int) ($this->now)();
        $current = $this->activeExpiry($telegramUserId);
        $base = $current !== null ? max($now, strtotime($current)) : $now;
        $expires = date('Y-m-d H:i:s', $base + $days * 86400);

        $stmt = $this->db->prepare(
            "INSERT INTO premium_entitlements (telegram_user_id, feature, status, source, payment_ref, starts_at, expires_at, granted_by, created_at)
             VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$telegramUserId, self::FEATURE, self::SOURCE_ADMIN_VERIFIED_PAYMENT, $paymentRef, date('Y-m-d H:i:s', $now), $expires, $grantedBy, date('Y-m-d H:i:s', $now)]);
        return $expires;
    }

    /** Cancels every active entitlement of the user. Returns the number of rows changed. */
    public function revoke(int $telegramUserId): int
    {
        $stmt = $this->db->prepare("UPDATE premium_entitlements SET status = 'cancelled', cancelled_at = ? WHERE telegram_user_id = ? AND status = 'active'");
        $stmt->execute([$this->ts(), $telegramUserId]);
        return $stmt->rowCount();
    }

    public function activeExpiry(int $telegramUserId): ?string
    {
        $now  = $this->ts();
        $stmt = $this->db->prepare(
            "SELECT MAX(expires_at) FROM premium_entitlements
             WHERE telegram_user_id = ? AND feature = ? AND status = 'active' AND source = ? AND starts_at <= ? AND expires_at > ?"
        );
        $stmt->execute([$telegramUserId, self::FEATURE, self::SOURCE_ADMIN_VERIFIED_PAYMENT, $now, $now]);
        $v = $stmt->fetchColumn();
        return $v ?: null;
    }

    private function ts(): string
    {
        return date('Y-m-d H:i:s', (int) ($this->now)());
    }

    /** MySQL DDL used by migrate.php / migrate_upgrade.php. */
    public static function mysqlSchema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS premium_entitlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id BIGINT NOT NULL,
                feature VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                source VARCHAR(32) NOT NULL,
                payment_ref VARCHAR(128) NULL,
                starts_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                granted_by BIGINT NOT NULL,
                created_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                INDEX idx_ent_user (telegram_user_id, status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS voice_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id BIGINT NOT NULL,
                user_id INT NULL,
                update_id BIGINT NULL,
                duration_seconds INT NOT NULL,
                file_bytes INT NULL,
                provider VARCHAR(64) NOT NULL,
                model VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_voice_user (telegram_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}
