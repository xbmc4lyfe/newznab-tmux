<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A remote worker host's queue of verified candidates waiting to be published, as files under
 * storage_path/publication-outbox:
 *
 *   pending/<id>-<version>.json    handed off by a worker, unowned
 *   inflight/<K>/<id>-<version>.json   claimed by forwarder K (renamed from pending)
 *   forwarder-<K>.alive            touched by forwarder K while it works
 *
 * Every transition is an atomic rename or an atomic replace (a temporary file, then rename), so a
 * crash leaves each entry whole in exactly one place, or in two when a requeue was interrupted;
 * the primary treats a resent entry idempotently. Nothing here is the only record of a candidate:
 * the candidate row and its lease remain authoritative, and an entry nobody publishes is
 * re-verified once that lease expires.
 */
final class PublicationOutbox
{
    /**
     * Seconds within which a forwarder's alive file proves it is running, when not configured: longer
     * than a batch round trip (the primary's 15 s budget plus the item in flight), since a forwarder
     * touches its file before and after a request, not during it.
     */
    public const int ALIVE_SECONDS = 30;

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (string) config('release_verification.storage_path').'/publication-outbox';
    }

    /** Whether this host hands verified results to a forwarder: flag on, publishing remotely, worker-only. */
    public static function enabled(): bool
    {
        $url = config('release_verification.publish_url');

        return (bool) config('release_verification.publish_async') && is_string($url) && $url !== ''
            && (bool) config('release_verification.worker_only');
    }

    public static function forwarders(): int
    {
        return max(1, min(VerificationWorkerLocks::MAX_SLOTS, (int) config('release_verification.publish_forwarders', 4)));
    }

    /** Seconds an entry may wait before it is finished unknown and left to be re-verified. */
    public static function maxAgeSeconds(): float
    {
        return (float) max(30, (int) config('release_verification.lease_seconds') - 60);
    }

    public function directory(): string
    {
        return $this->root;
    }

    /** Create the outbox directories, refusing links, and with $slot also that forwarder's in-flight directory. */
    public function prepare(?int $slot = null): void
    {
        $storage = dirname($this->root);
        foreach ([$storage, $this->root, $this->root.'/pending', $this->root.'/inflight'] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('The publication outbox cannot be a symlink.');
            }
            File::ensureDirectoryExists($directory, 0700);
        }
        if ($slot !== null) {
            $inflight = $this->inflightDirectory($slot);
            if (is_link($inflight)) {
                throw new RuntimeException('The publication outbox cannot be a symlink.');
            }
            File::ensureDirectoryExists($inflight, 0700);
        }
    }

    public function inflightDirectory(int $slot): string
    {
        return $this->root.'/inflight/'.$slot;
    }

    /**
     * Hand a verified candidate to the forwarders. The entry appears whole or not at all.
     *
     * @param  array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>}  $evidence
     */
    public function enqueue(object $claim, string $reason, array $evidence): string
    {
        $this->prepare();
        $name = PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version);
        $entry = new PublicationOutboxEntry($name, $this->root.'/pending/'.$name, ['id' => (int) $claim->id, 'version' => (int) $claim->version,
            'manifest_hash' => (string) $claim->manifest_hash, 'lease_token' => (string) $claim->lease_token], $reason, $evidence, microtime(true));
        $this->write($entry, $this->root.'/pending');

        return $name;
    }

    /** Entries waiting or being sent. */
    public function depth(): int
    {
        $count = count($this->names($this->root.'/pending'));
        foreach (@scandir($this->root.'/inflight') ?: [] as $slot) {
            if ($slot !== '.' && $slot !== '..' && is_dir($this->root.'/inflight/'.$slot)) {
                $count += count($this->names($this->root.'/inflight/'.$slot));
            }
        }

        return $count;
    }

    /** Whether some forwarder touched its alive file within $window seconds. */
    public function forwarderAlive(?int $now = null, ?int $window = null): bool
    {
        $now ??= time();
        $window ??= max(1, (int) config('release_verification.publish_forwarder_alive_seconds', self::ALIVE_SECONDS));
        clearstatcache();
        foreach (@scandir($this->root) ?: [] as $file) {
            if (preg_match('/\Aforwarder-\d+\.alive\z/D', $file) === 1) {
                $modified = @filemtime($this->root.'/'.$file);
                if ($modified !== false && $now - $modified <= $window) {
                    return true;
                }
            }
        }

        return false;
    }

    public function touchAlive(int $slot): void
    {
        $path = $this->root.'/forwarder-'.$slot.'.alive';
        if (is_link($path) || (! touch($path))) {
            throw new RuntimeException('Cannot mark the publication forwarder alive.');
        }
    }

    public function clearAlive(int $slot): void
    {
        @unlink($this->root.'/forwarder-'.$slot.'.alive');
    }

    /**
     * Wait, while keeping the caller's lease fresh, until the outbox has room.
     *
     * @param  callable(): void  $heartbeat
     * @return bool false when the wait ended without room, or no forwarder is alive any longer: publish synchronously
     */
    public function awaitRoom(callable $heartbeat, float $seconds, ?int $limit = null): bool
    {
        $limit ??= max(1, (int) config('release_verification.publish_outbox_max', 256));
        $deadline = microtime(true) + $seconds;
        while (true) {
            if (! $this->forwarderAlive()) {
                return false;
            }
            if ($this->depth() < $limit) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            $heartbeat();
            usleep(100_000);
        }
    }

    /**
     * Every valid entry of a directory, oldest hand-off first. A document that cannot be decoded can
     * never be published, so it is removed; its candidate is re-verified once the lease expires.
     *
     * @return list<PublicationOutboxEntry>
     */
    public function entries(string $directory): array
    {
        $entries = [];
        clearstatcache();
        foreach ($this->names($directory) as $name) {
            $entry = $this->read($directory.'/'.$name);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (PublicationOutboxEntry $left, PublicationOutboxEntry $right): int => [$left->enqueuedAt, $left->name] <=> [$right->enqueuedAt, $right->name]);

        return $entries;
    }

    /** @return list<PublicationOutboxEntry> */
    public function pending(): array
    {
        return $this->entries($this->root.'/pending');
    }

    /** @return list<PublicationOutboxEntry> */
    public function inflight(int $slot): array
    {
        return $this->entries($this->inflightDirectory($slot));
    }

    /**
     * Take the entries forwarder $slot sends next: those it already owns (left by an interrupted
     * run), then up to $limit - owned of the oldest pending entries that are due. Entries that have
     * outlived their age limit are claimed too, without counting against the limit, so that one
     * forwarder finishes them. The rename arbitrates between forwarders: the loser skips the entry.
     *
     * @return list<PublicationOutboxEntry>
     */
    public function claim(int $slot, int $limit, float $now): array
    {
        $taken = array_slice($this->inflight($slot), 0, max(1, $limit));
        $room = $limit - count($taken);
        $maxAge = self::maxAgeSeconds();
        foreach ($this->pending() as $entry) {
            $expired = $entry->olderThan($maxAge, $now);
            if (! $expired && ($room <= 0 || $entry->notBefore > $now)) {
                continue;
            }
            $path = $this->inflightDirectory($slot).'/'.$entry->name;
            if (! @rename($entry->path, $path)) {
                continue;
            }
            $claimed = $this->read($path);
            if ($claimed === null) {
                continue;
            }
            $taken[] = $claimed;
            if (! $expired) {
                $room--;
            }
        }

        return $taken;
    }

    /** Return a claimed entry to the pending queue after a failed send, atomically replacing its document. */
    public function requeue(PublicationOutboxEntry $entry): void
    {
        // A newer hand-off of the same candidate generation (after this lease expired) is not replaced by a dead one.
        if (! file_exists($this->root.'/pending/'.$entry->name)) {
            $this->write($entry, $this->root.'/pending');
        }
        if ($entry->path !== $this->root.'/pending/'.$entry->name) {
            @unlink($entry->path);
        }
    }

    public function remove(PublicationOutboxEntry $entry): void
    {
        @unlink($entry->path);
    }

    /** Delete stray temporary files that a crashed writer left behind. */
    public function sweepTemporaryFiles(int $olderThanSeconds = 600): void
    {
        $now = time();
        $directories = [$this->root.'/pending'];
        foreach (@scandir($this->root.'/inflight') ?: [] as $slot) {
            if ($slot !== '.' && $slot !== '..' && is_dir($this->root.'/inflight/'.$slot)) {
                $directories[] = $this->root.'/inflight/'.$slot;
            }
        }
        foreach ($directories as $directory) {
            foreach (@scandir($directory) ?: [] as $file) {
                $path = $directory.'/'.$file;
                if (str_starts_with($file, '.') && str_ends_with($file, '.tmp') && is_file($path) && ! is_link($path)
                    && ($modified = @filemtime($path)) !== false && $now - $modified > $olderThanSeconds) {
                    @unlink($path);
                }
            }
        }
    }

    /** @return list<string> entry document names in a directory */
    private function names(string $directory): array
    {
        $names = [];
        foreach (@scandir($directory) ?: [] as $file) {
            if (! str_starts_with($file, '.') && str_ends_with($file, '.json')) {
                $names[] = $file;
            }
        }

        return $names;
    }

    private function read(string $path): ?PublicationOutboxEntry
    {
        if (is_link($path)) {
            @unlink($path);

            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            // Claimed by another forwarder between listing and reading.
            return null;
        }
        $entry = PublicationOutboxEntry::decode(basename($path), $path, $json, (int) @filemtime($path));
        if ($entry === null) {
            @unlink($path);
        }

        return $entry;
    }

    private function write(PublicationOutboxEntry $entry, string $directory): void
    {
        $temporary = $directory.'/.'.$entry->name.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            if (file_put_contents($temporary, json_encode($entry->toArray(), JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($temporary, $directory.'/'.$entry->name)) {
                throw new RuntimeException('Cannot write the publication outbox entry.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
