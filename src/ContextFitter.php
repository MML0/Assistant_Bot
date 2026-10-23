<?php

namespace Bot;

/**
 * Fits a conversation into a model's context window without changing its meaning:
 * system prompt, any stored summary and the newest user turn are always kept;
 * older turns are dropped oldest-first. Token counts are conservative estimates.
 */
final class ContextFitter
{
    public static function estimateTokens($content): int
    {
        $text  = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE);
        $total = mb_strlen($text);
        $ascii = preg_match_all('/[\x00-\x7F]/', $text);
        $other = $total - $ascii;
        // ~3 ASCII chars per token; Persian/other scripts tokenise much worse.
        return (int) ceil($ascii / 3 + $other / 1.5);
    }

    public static function messagesTokens(array $messages): int
    {
        $n = 3;
        foreach ($messages as $m) {
            $n += 5 + self::estimateTokens($m['content'] ?? '');
        }
        return $n;
    }

    /**
     * @return array|null fitted messages, or null when even the essentials do not fit
     */
    public static function fit(array $messages, int $contextLength, int $maxOutput, float $safety = 0.9): ?array
    {
        $budget = (int) floor($contextLength * $safety) - $maxOutput;
        if ($budget <= 0) {
            return null;
        }
        if (self::messagesTokens($messages) <= $budget) {
            return $messages;
        }

        $pinned = [];
        $rest   = [];
        foreach ($messages as $i => $m) {
            $isSystem  = ($m['role'] ?? '') === 'system';
            $isSummary = ($m['role'] ?? '') === 'assistant' && is_string($m['content'] ?? null)
                && str_starts_with($m['content'], 'Summary of our chat so far');
            if ($isSystem || $isSummary) {
                $pinned[$i] = $m;
            } else {
                $rest[$i] = $m;
            }
        }
        // Keep the newest user turn no matter what.
        $lastKey = array_key_last($rest);
        if ($lastKey === null) {
            return self::messagesTokens($pinned) <= $budget ? array_values($pinned) : null;
        }
        $keep = [$lastKey => $rest[$lastKey]];
        unset($rest[$lastKey]);
        if (self::messagesTokens($pinned + $keep) > $budget) {
            return null;
        }
        foreach (array_reverse($rest, true) as $i => $m) {
            $try = $pinned + $keep + [$i => $m];
            if (self::messagesTokens($try) > $budget) {
                break;
            }
            $keep[$i] = $m;
        }
        $all = $pinned + $keep;
        ksort($all);
        $out = array_values($all);
        // Drop an assistant reply whose question was trimmed away (summaries are kept).
        while (count($out) > 1 && ($out[1]['role'] ?? '') === 'assistant'
            && !str_starts_with((string) ($out[1]['content'] ?? ''), 'Summary of our chat so far')
            && ($out[0]['role'] ?? '') === 'system') {
            array_splice($out, 1, 1);
        }
        return $out;
    }
}
