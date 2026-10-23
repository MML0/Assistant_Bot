<?php

namespace Bot;

/**
 * Tiny file-based state store with flock(), shared by concurrent webhook processes.
 * Holds the model catalogue cache, model health, budgets and dedupe sets.
 */
final class JsonStore
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        // The storage directory must never be served over HTTP.
        $ht = $this->dir . '/.htaccess';
        if (is_dir($this->dir) && !file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function read(string $name, array $default = []): array
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return $default;
        }
        $fh = @fopen($path, 'r');
        if (!$fh) {
            return $default;
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * Atomically read-modify-write a document. $fn receives the data by reference
     * and its return value is passed through.
     */
    public function update(string $name, callable $fn, array $default = [])
    {
        $fh = @fopen($this->path($name), 'c+');
        if (!$fh) {
            $data = $default;
            return $fn($data);
        }
        flock($fh, LOCK_EX);
        $raw  = stream_get_contents($fh);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = $default;
        }
        $result = $fn($data);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $result;
    }

    public function write(string $name, array $data): bool
    {
        return $this->update($name, function (&$d) use ($data) {
            $d = $data;
            return true;
        });
    }

    public function appendLine(string $name, array $record): void
    {
        $path = $this->path($name);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Non-blocking exclusive lock; returns a handle or null when already held. */
    public function tryLock(string $name)
    {
        $fh = @fopen($this->path($name . '.lock'), 'c');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function path(string $name): string
    {
        $name = preg_replace('~[^A-Za-z0-9_./-]~', '_', $name);
        $name = str_replace('..', '_', $name);
        return $this->dir . '/' . ltrim($name, '/');
    }
}
