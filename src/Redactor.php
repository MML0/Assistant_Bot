<?php

namespace Bot;

/** Strips credentials from text before it reaches logs or admin alerts. */
final class Redactor
{
    /** @var string[] */
    private static array $secrets = [];

    public static function registerSecrets(array $values): void
    {
        foreach ($values as $v) {
            if (is_string($v) && strlen($v) >= 8) {
                self::$secrets[$v] = true;
            }
        }
    }

    public static function redact(string $text, int $maxLen = 500): string
    {
        foreach (array_keys(self::$secrets) as $s) {
            $text = str_replace($s, '[REDACTED]', $text);
        }
        $text = preg_replace([
            '~bot\d+:[A-Za-z0-9_-]{20,}~',          // Telegram bot tokens inside URLs
            '~\b\d{6,}:[A-Za-z0-9_-]{30,}\b~',       // bare Telegram tokens
            '~\bsk-[A-Za-z0-9_-]{16,}~',             // OpenAI/OpenRouter style keys
            '~\btpsg-[A-Za-z0-9_-]{16,}~',           // MetisAI keys
            '~Bearer\s+[A-Za-z0-9._-]+~i',
        ], ['bot[REDACTED]', '[REDACTED]', 'sk-[REDACTED]', 'tpsg-[REDACTED]', 'Bearer [REDACTED]'], $text);

        if (mb_strlen($text) > $maxLen) {
            $text = mb_substr($text, 0, $maxLen) . '…';
        }
        return $text;
    }
}
