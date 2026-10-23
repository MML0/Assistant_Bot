<?php

namespace Bot;

/** Remembers recently seen keys (Telegram update_id, audio file ids) so retries are not processed twice. */
final class UpdateDeduper
{
    private JsonStore $store;
    private string $file;
    private int $ttl;
    /** @var callable */
    private $now;

    public function __construct(JsonStore $store, string $file = 'seen_updates.json', int $ttl = 86400, ?callable $now = null)
    {
        $this->store = $store;
        $this->file  = $file;
        $this->ttl   = $ttl;
        $this->now   = $now ?? fn () => microtime(true);
    }

    /** Returns true the first time a key is seen within the TTL, false for duplicates. */
    public function firstSeen(string $key): bool
    {
        $now = ($this->now)();
        $ttl = $this->ttl;
        return $this->store->update($this->file, function (&$d) use ($key, $now, $ttl) {
            foreach ($d as $k => $t) {
                if ($t < $now - $ttl) {
                    unset($d[$k]);
                }
            }
            if (isset($d[$key])) {
                return false;
            }
            $d[$key] = $now;
            if (count($d) > 5000) {
                asort($d);
                $d = array_slice($d, -5000, null, true);
            }
            return true;
        });
    }
}
