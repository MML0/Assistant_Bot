<?php

namespace Bot;

/**
 * Cheap local heuristics (no model call) that decide how valuable a request is:
 * 'ordinary' chat, 'coding', or 'hard' reasoning. Works for English and Persian.
 */
final class TaskClassifier
{
    public static function classify(array $messages, string $purpose = 'chat'): string
    {
        if ($purpose === 'summary') {
            return 'ordinary';
        }
        $text = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $text = self::textOf($messages[$i]['content'] ?? '');
                break;
            }
        }
        $lower = mb_strtolower($text);

        $codeSignals = [
            '```', 'traceback', 'exception', 'stack trace', 'syntaxerror', 'typeerror', 'undefined index',
            'function ', 'def ', 'class ', 'import ', '#include', 'console.log', '<?php', 'select ', 'regex',
            'javascript', 'python', 'php', 'typescript', 'golang', 'rust', 'java ', 'c++', 'sql', 'api ',
            'compile', 'debug', 'bug', 'refactor', 'docker', 'kubernetes', 'git ',
            'کد', 'برنامه نویسی', 'برنامه‌نویسی', 'باگ', 'دیباگ', 'خطای', 'تابع',
        ];
        $code = 0;
        foreach ($codeSignals as $s) {
            if (str_contains($lower, $s)) {
                $code++;
            }
        }
        if (preg_match('~[{};]\s*$|=>|->|\(\)\s*{|\$[a-z_]+\s*=~m', $text)) {
            $code++;
        }
        if ($code >= 2 || str_contains($text, '```')) {
            return 'coding';
        }

        $hardSignals = [
            'prove', 'proof', 'derive', 'step by step', 'step-by-step', 'theorem', 'integral', 'derivative',
            'equation', 'optimi', 'architecture', 'trade-off', 'tradeoff', 'algorithm', 'complexity',
            'analyze', 'analyse', 'compare', 'strategy', 'probability', 'statistic', 'design a', 'plan for',
            'اثبات', 'معادله', 'انتگرال', 'مشتق', 'الگوریتم', 'تحلیل', 'مقایسه', 'احتمال', 'معماری', 'استدلال', 'گام به گام',
        ];
        $hard = 0;
        foreach ($hardSignals as $s) {
            if (str_contains($lower, $s)) {
                $hard++;
            }
        }
        if (preg_match('~[∫∑√≤≥≠∞]|\d+\s*[\^*/]\s*\d+|\b(sin|cos|log|lim)\b~u', $text)) {
            $hard++;
        }
        if (mb_strlen($text) > 1200) {
            $hard++;
        }
        if ($code === 1) {
            $hard++;
        }
        return $hard >= 2 ? 'hard' : 'ordinary';
    }

    /** Required input capabilities of a request. */
    public static function requirements(array $messages, array $tools = []): array
    {
        $req = ['image' => false, 'file' => false, 'audio' => false, 'tools' => !empty($tools)];
        foreach ($messages as $m) {
            if (!is_array($m['content'] ?? null)) {
                continue;
            }
            foreach ($m['content'] as $part) {
                $t = $part['type'] ?? '';
                if ($t === 'image_url') {
                    $req['image'] = true;
                } elseif ($t === 'file') {
                    $req['file'] = true;
                } elseif ($t === 'input_audio') {
                    $req['audio'] = true;
                }
            }
        }
        return $req;
    }

    public static function textOf($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $out = '';
        foreach ((array) $content as $part) {
            if (($part['type'] ?? '') === 'text') {
                $out .= $part['text'] . "\n";
            }
        }
        return $out;
    }
}
