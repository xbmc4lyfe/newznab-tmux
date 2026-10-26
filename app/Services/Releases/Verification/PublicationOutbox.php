<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

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
    // No candidate is admitted without all four paired caller metrics >= 1.5x.
    private static bool $useRustFfi = false;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_wave15_outbox_folder(const char *, size_t, uint32_t, int64_t);
        int32_t nntmux_wave15_outbox_state(double, double, int64_t, int64_t, double, double);
        ssize_t nntmux_wave15_outbox_normalize(const char *, size_t, char *, size_t);
        C;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * The least the configured alive window can be: it must outlast the seconds between a forwarder's
     * scheduled exit (10 s before a tick) and its replacement's first touch, with a margin.
     */
    public const int MINIMUM_ALIVE_SECONDS = 20;

    /**
     * Seconds within which a forwarder's alive file proves it is running, when not configured. A
     * forwarder touches it every few seconds, also while a request waits, so this covers a stall and
     * the gap between a scheduled exit and the replacement's start.
     */
    public const int ALIVE_SECONDS = 30;

    /** The shortest forwarder restart period: the one-minute scheduler cadence, twice over (a run, then its restart). */
    public const int MINIMUM_PERIOD_SECONDS = 120;

    /** Seconds a quarantined entry is kept for inspection. */
    private const int QUARANTINE_SECONDS = 3600;

    private readonly string $root;

    /** Names this process's alive marker, so only its owner removes it (a replacement forwarder's is left alone). */
    private readonly string $markerToken;

    /** @var array<string, true> Entries that could neither be removed nor moved; this process leaves them alone. */
    private array $stuck = [];

    /** @var array<string, true> Documents that could not be decoded; {@see purgeInvalid()} removes them. */
    private array $invalid = [];

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (string) config('release_verification.storage_path').'/publication-outbox';
        $this->markerToken = bin2hex(random_bytes(8));
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

    /** Longest linger a forwarder may wait for a fuller batch, in milliseconds. */
    public const int MAX_LINGER_MILLISECONDS = 30_000;

    /** Milliseconds a forwarder waits for a batch to fill once work is due; zero sends at once. */
    public static function lingerMilliseconds(): int
    {
        return max(0, min(self::MAX_LINGER_MILLISECONDS, (int) config('release_verification.publish_forward_linger_ms', 0)));
    }

    /** Entries (waiting or being sent) at which workers stop handing off and wait for room. */
    public static function capacity(): int
    {
        return max(1, (int) config('release_verification.publish_outbox_max', 256));
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
        // Optional, but never to be followed: sweeping and quarantining act on it.
        if (is_link($this->root.'/failed')) {
            throw new RuntimeException('The publication outbox cannot be a symlink.');
        }
        if ($slot !== null) {
            $inflight = $this->inflightDirectory($slot);
            if (is_link($inflight)) {
                throw new RuntimeException('The publication outbox cannot be a symlink.');
            }
            File::ensureDirectoryExists($inflight, 0700);
        }
    }

    /**
     * Whether a directory of the outbox, its parent or the outbox itself is a symlink. Links are never
     * followed: scanning, sweeping and quarantining through one would act outside the outbox.
     */
    private function linked(string $directory): bool
    {
        return is_link($directory) || is_link(dirname($directory)) || is_link($this->root);
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
        $name = PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version, (string) $claim->lease_token);
        $now = microtime(true);
        // The worker renewed the lease just before handing off, so the schedule starts here.
        $entry = new PublicationOutboxEntry($name, $this->root.'/pending/'.$name, ['id' => (int) $claim->id, 'version' => (int) $claim->version,
            'manifest_hash' => (string) $claim->manifest_hash, 'lease_token' => (string) $claim->lease_token], $reason, $evidence, $now, heartbeatAt: $now);
        $this->write($entry, $this->root.'/pending');

        return $name;
    }

    /** Entries waiting or being sent. */
    public function depth(): int
    {
        $count = count($this->names($this->root.'/pending'));
        foreach ($this->slotDirectories() as $directory) {
            $count += count($this->names($directory));
        }

        return $count;
    }

    /**
     * The in-flight folders, never following a link: a symlinked slot would lead scans, sweeps and
     * adoption outside the outbox.
     *
     * @return list<string>
     */
    private function slotDirectories(): array
    {
        return self::$useRustFfi ? $this->slotDirectoriesNative() : $this->slotDirectoriesPhp();
    }

    /** @return list<string> */
    public function slotDirectoriesPhp(): array
    {
        return $this->slotDirectoriesUsing(false);
    }

    /** @return list<string> */
    public function slotDirectoriesNative(): array
    {
        return $this->slotDirectoriesUsing(true);
    }

    /** @return list<string> */
    private function slotDirectoriesUsing(bool $native): array
    {
        $directories = [];
        if ($this->linked($this->root.'/inflight')) {
            return [];
        }
        foreach (@scandir($this->root.'/inflight') ?: [] as $slot) {
            $path = $this->root.'/inflight/'.$slot;
            if (($native ? self::folderNative($slot, 0, 0) : $slot !== '.' && $slot !== '..') && ! is_link($path) && is_dir($path)) {
                $directories[] = $path;
            }
        }

        return $directories;
    }

    /** Whether some forwarder touched its alive file within $window seconds. */
    public function forwarderAlive(?int $now = null, ?int $window = null): bool
    {
        if ($this->linked($this->root)) {
            return false;
        }
        $now ??= time();
        $window ??= max(self::MINIMUM_ALIVE_SECONDS, (int) config('release_verification.publish_forwarder_alive_seconds', self::ALIVE_SECONDS));
        clearstatcache();
        foreach (@scandir($this->root) ?: [] as $file) {
            // A linked marker is never followed, as touching and removing refuse it too.
            if (preg_match('/\Aforwarder-\d+\.alive\z/D', $file) === 1 && ! is_link($this->root.'/'.$file)) {
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
        if ($this->linked($this->root) || is_link($path)) {
            throw new RuntimeException('Cannot mark the publication forwarder alive through a symlink.');
        }
        // The marker carries its owner's token, written when it is first claimed and left alone while it matches.
        $written = @file_get_contents($path) === $this->markerToken ? touch($path) : (file_put_contents($path, $this->markerToken) !== false);
        if (! $written) {
            throw new RuntimeException('Cannot mark the publication forwarder alive.');
        }
    }

    /** Touch the marker unless it was touched within $seconds, as when called many times during one request. */
    public function touchAliveIfStale(int $slot, int $seconds): void
    {
        clearstatcache(true, $this->root.'/forwarder-'.$slot.'.alive');
        $modified = @filemtime($this->root.'/forwarder-'.$slot.'.alive');
        if ($modified === false || time() - $modified >= $seconds) {
            $this->touchAlive($slot);
        }
    }

    /** Remove this process's own marker; one a replacement forwarder wrote, or one behind a link, is left alone. */
    public function clearAlive(int $slot): void
    {
        $path = $this->root.'/forwarder-'.$slot.'.alive';
        if ($this->linked($this->root) || is_link($path) || @file_get_contents($path) !== $this->markerToken) {
            return;
        }
        @unlink($path);
    }

    /**
     * Wait, while keeping the caller's lease fresh, until the outbox has room.
     *
     * @param  callable(): void  $heartbeat
     * @return bool false when the wait ended without room, or no forwarder is alive any longer: publish synchronously
     */
    public function awaitRoom(callable $heartbeat, float $seconds, ?int $limit = null): bool
    {
        $limit ??= self::capacity();
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
     * Every valid entry of a directory, oldest hand-off first. Reading changes nothing on disk. A
     * document that cannot be decoded can never be published; {@see purgeInvalid()} removes it, and
     * its candidate is re-verified once the lease expires.
     *
     * @return list<PublicationOutboxEntry>
     */
    public function entries(string $directory): array
    {
        return $this->entriesUsing($directory, self::$useRustFfi);
    }

    /** @return list<PublicationOutboxEntry> */
    private function entriesUsing(string $directory, bool $native): array
    {
        $entries = [];
        clearstatcache();
        foreach ($this->names($directory) as $name) {
            if (isset($this->stuck[$directory.'/'.$name])) {
                continue;
            }
            $entry = $this->read($directory.'/'.$name, $native);
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
     * Whether {@see claim()} would take anything: this forwarder owns an entry already, or a pending
     * one is due or has outlived its age limit. Reads only.
     */
    public function hasDue(int $slot, float $now): bool
    {
        return self::$useRustFfi ? $this->hasDueNative($slot, $now) : $this->hasDuePhp($slot, $now);
    }

    public function hasDuePhp(int $slot, float $now): bool
    {
        if ($this->entriesUsing($this->inflightDirectory($slot), false) !== []) {
            return true;
        }
        $maxAge = self::maxAgeSeconds();
        foreach ($this->entriesUsing($this->root.'/pending', false) as $entry) {
            if ($entry->notBefore <= $now || $entry->olderThan($maxAge, $now)) {
                return true;
            }
        }

        return false;
    }

    public function hasDueNative(int $slot, float $now): bool
    {
        if ($this->entriesUsing($this->inflightDirectory($slot), true) !== []) {
            return true;
        }
        $maxAge = self::maxAgeSeconds();
        foreach ($this->entriesUsing($this->root.'/pending', true) as $entry) {
            if ((self::entryStateNative($entry, $now, $maxAge) & 3) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * One read-only pass over what a forwarder needs to decide whether, and how long, to wait for a
     * fuller batch. Returns early once the answer cannot change: the batch is full ($cap fresh entries)
     * or something is urgent or already tried.
     *
     * - fresh: pending entries that are due and not expired, counted up to $cap.
     * - urgent: this slot already owns an entry, or a pending one has outlived its age limit.
     * - retried: a due, unexpired pending entry was tried before (attempts or deferrals).
     * - oldest: the earliest hand-off time among the fresh entries.
     * - wake: the earliest moment any unexpired pending entry needs attention: when a backed-off one
     *   becomes due, or $margin seconds before any of them reaches its age limit.
     *
     * @return array{fresh: int, urgent: bool, retried: bool, oldest: ?float, wake: ?float}
     */
    public function survey(float $now, int $slot, int $cap, float $margin = 0.0): array
    {
        return self::$useRustFfi ? $this->surveyNative($now, $slot, $cap, $margin) : $this->surveyPhp($now, $slot, $cap, $margin);
    }

    /** @return array{fresh: int, urgent: bool, retried: bool, oldest: ?float, wake: ?float} */
    public function surveyPhp(float $now, int $slot, int $cap, float $margin = 0.0): array
    {
        return $this->surveyUsing($now, $slot, $cap, $margin, false);
    }

    /** @return array{fresh: int, urgent: bool, retried: bool, oldest: ?float, wake: ?float} */
    public function surveyNative(float $now, int $slot, int $cap, float $margin = 0.0): array
    {
        return $this->surveyUsing($now, $slot, $cap, $margin, true);
    }

    /** @return array{fresh: int, urgent: bool, retried: bool, oldest: ?float, wake: ?float} */
    private function surveyUsing(float $now, int $slot, int $cap, float $margin, bool $native): array
    {
        $result = ['fresh' => 0, 'urgent' => false, 'retried' => false, 'oldest' => null, 'wake' => null];
        clearstatcache();
        $owned = $this->inflightDirectory($slot);
        foreach ($this->names($owned) as $name) {
            if (! isset($this->stuck[$owned.'/'.$name]) && $this->read($owned.'/'.$name, $native) !== null) {
                $result['urgent'] = true;

                return $result;
            }
        }
        $maxAge = self::maxAgeSeconds();
        $directory = $this->root.'/pending';
        foreach ($this->names($directory) as $name) {
            if (isset($this->stuck[$directory.'/'.$name])) {
                continue;
            }
            $entry = $this->read($directory.'/'.$name, $native);
            if ($entry === null) {
                continue;
            }
            $state = $native ? self::entryStateNative($entry, $now, $maxAge) : self::entryStatePhp($entry, $now, $maxAge);
            if (($state & 2) !== 0) {
                $result['urgent'] = true;

                return $result;
            }
            $needs = $entry->enqueuedAt + $maxAge - $margin;
            if ($entry->notBefore > $now) {
                $needs = min($needs, $entry->notBefore);
            } else {
                if (($state & 4) !== 0) {
                    $result['retried'] = true;

                    return $result;
                }
                $result['fresh']++;
                $result['oldest'] = $result['oldest'] === null ? $entry->enqueuedAt : min($result['oldest'], $entry->enqueuedAt);
                if ($result['fresh'] >= $cap) {
                    return $result;
                }
            }
            $result['wake'] = $result['wake'] === null ? $needs : min($result['wake'], $needs);
        }

        return $result;
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
        $this->prepare($slot);
        $taken = array_slice($this->inflight($slot), 0, max(1, $limit));
        $room = $limit - count($taken);
        $maxAge = self::maxAgeSeconds();
        foreach ($this->pending() as $entry) {
            $expired = $entry->olderThan($maxAge, $now);
            if (! $expired && ($room <= 0 || $entry->notBefore > $now)) {
                continue;
            }
            $claimed = $this->claimOne($slot, $entry, $now);
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

    /**
     * Claim one listed entry by renaming it into forwarder $slot's folder. The listing may be stale:
     * between it and the rename another forwarder can have claimed the entry, failed it and requeued
     * it with a backoff. The document that was actually won is read again, and one that is not yet due
     * (and not expired) goes straight back to pending, unpublished.
     */
    public function claimOne(int $slot, PublicationOutboxEntry $listed, float $now): ?PublicationOutboxEntry
    {
        $path = $this->inflightDirectory($slot).'/'.$listed->name;
        if (! @rename($listed->path, $path)) {
            return null;
        }
        $claimed = $this->read($path);
        if ($claimed === null) {
            return null;
        }
        if ($claimed->notBefore > $now && ! $claimed->olderThan(self::maxAgeSeconds(), $now)) {
            @rename($path, $listed->path);

            return null;
        }

        return $claimed;
    }

    /**
     * Return a claimed entry to the pending queue after a failed send: its document is replaced in
     * place, atomically, and then renamed into pending. The name carries the lease token, so no
     * other hand-off can have that name; a crash between the two steps leaves the entry in flight,
     * where its forwarder (or an orphan adopter) finds it.
     */
    public function requeue(PublicationOutboxEntry $entry): void
    {
        $this->write($entry, dirname($entry->path));
        if (! @rename($entry->path, $this->root.'/pending/'.$entry->name)) {
            throw new RuntimeException('Cannot return the publication outbox entry to pending.');
        }
    }

    /**
     * In-flight folders of other forwarder numbers that hold entries.
     *
     * @return list<int>
     */
    public function foreignSlots(int $except): array
    {
        return self::$useRustFfi ? $this->foreignSlotsNative($except) : $this->foreignSlotsPhp($except);
    }

    /** @return list<int> */
    public function foreignSlotsPhp(int $except): array
    {
        return $this->foreignSlotsUsing($except, false);
    }

    /** @return list<int> */
    public function foreignSlotsNative(int $except): array
    {
        return $this->foreignSlotsUsing($except, true);
    }

    /** @return list<int> */
    private function foreignSlotsUsing(int $except, bool $native): array
    {
        $slots = [];
        if ($this->linked($this->root.'/inflight')) {
            return [];
        }
        foreach (@scandir($this->root.'/inflight') ?: [] as $folder) {
            if (($native ? self::folderNative($folder, 1, $except) : preg_match('/\A[1-9]\d*\z/D', $folder) === 1 && (int) $folder !== $except) && ! is_link($this->root.'/inflight/'.$folder)
                && $this->names($this->root.'/inflight/'.$folder) !== []) {
                $slots[] = (int) $folder;
            }
        }

        return $slots;
    }

    /**
     * Move the entries of a forwarder number nobody runs back to pending. The caller holds that
     * number's lock, so no forwarder is using the folder.
     */
    public function adopt(int $slot): int
    {
        $moved = 0;
        $directory = $this->inflightDirectory($slot);
        if ($this->linked($directory) || $this->linked($this->root.'/pending')) {
            return 0;
        }
        foreach ($this->names($directory) as $name) {
            if (@rename($directory.'/'.$name, $this->root.'/pending/'.$name)) {
                $moved++;
            }
        }
        if ($slot > self::forwarders()) {
            @rmdir($directory);
        }

        return $moved;
    }

    /**
     * Seconds forwarder $number runs before the scheduler restarts it: never more than the configured
     * period (so a container stop need not wait for it), 10 s short of whole minutes so the restart tick
     * follows at once, and staggered by whole minutes inside that period so the forwarders do not all
     * restart together. The period counts in whole minutes (131 s is two), because the scheduler ticks once a
     * minute, and is at least {@see MINIMUM_PERIOD_SECONDS}.
     */
    public static function forwarderLifetime(int $number): int
    {
        // The scheduler starts forwarders once a minute, so a run must span a whole tick and the restart after it:
        // below two minutes, a forwarder would leave a gap of most of every minute with none running.
        $period = max(self::MINIMUM_PERIOD_SECONDS, (int) config('release_verification.publish_forwarder_seconds', 300));
        // Whole minutes only: a run that ends 10 s before a tick is restarted by it at once, while one that ends
        // between ticks waits for the next, and the forwarders sharing such a length would all be gone together.
        $minutes = intdiv($period, 60);
        $lives = max(1, $minutes - 1);

        return 60 * ($minutes - ((max(1, $number) - 1) % $lives)) - 10;
    }

    /**
     * Drop an entry. False when it could not be removed and was parked instead: moved to failed/, or,
     * if even that failed, remembered so this process never claims it again. Either way it no longer
     * counts as work, and the candidate's lease decides its fate.
     */
    public function remove(PublicationOutboxEntry $entry): bool
    {
        return $this->discard($entry->path, $entry->name);
    }

    /** Whether a read has met documents that could not be decoded and {@see purgeInvalid()} has yet to remove. */
    public function hasInvalid(): bool
    {
        return $this->invalid !== [];
    }

    /**
     * Remove the documents that could not be decoded, which can never be published (the caller must
     * hold the worker group). Those that could not be removed are parked, as {@see remove()} does.
     *
     * @return array{removed: int, parked: int}
     */
    public function purgeInvalid(): array
    {
        $count = ['removed' => 0, 'parked' => 0];
        foreach (array_keys($this->invalid) as $path) {
            unset($this->invalid[$path]);
            if (file_exists($path) || is_link($path)) {
                $count[$this->discard($path, basename($path)) ? 'removed' : 'parked']++;
            }
        }

        return $count;
    }

    private function discard(string $path, string $name): bool
    {
        if (@unlink($path) || (! file_exists($path) && ! is_link($path))) {
            return true;
        }
        $this->stuck[$path] = true;
        $failed = $this->root.'/failed';
        if (! $this->linked($failed) && ! $this->linked(dirname($path)) && (is_dir($failed) || @mkdir($failed, 0700)) && @rename($path, $failed.'/'.$name)) {
            unset($this->stuck[$path]);
        }

        return false;
    }

    /**
     * Stray temporary files that a crashed writer left behind for longer than $olderThanSeconds, and
     * quarantined entries past their retention. Reads only; links are never followed.
     *
     * @return list<string>
     */
    public function staleFiles(int $olderThanSeconds = 600): array
    {
        $now = time();
        $stale = [];
        foreach ($this->linked($this->root.'/failed') ? [] : (@scandir($this->root.'/failed') ?: []) as $file) {
            $path = $this->root.'/failed/'.$file;
            if ($file !== '.' && $file !== '..' && ! is_link($path) && is_file($path) && ($modified = @filemtime($path)) !== false
                && $now - $modified > self::QUARANTINE_SECONDS) {
                $stale[] = $path;
            }
        }
        foreach ([$this->root.'/pending', ...$this->slotDirectories()] as $directory) {
            foreach ($this->linked($directory) ? [] : (@scandir($directory) ?: []) as $file) {
                $path = $directory.'/'.$file;
                if (str_starts_with($file, '.') && str_ends_with($file, '.tmp') && is_file($path) && ! is_link($path)
                    && ($modified = @filemtime($path)) !== false && $now - $modified > $olderThanSeconds) {
                    $stale[] = $path;
                }
            }
        }

        return $stale;
    }

    /** Delete what {@see staleFiles()} lists (the caller must hold the worker group). */
    public function sweepTemporaryFiles(int $olderThanSeconds = 600): void
    {
        foreach ($this->staleFiles($olderThanSeconds) as $path) {
            @unlink($path);
        }
    }

    /** @return list<string> entry document names in a directory */
    private function names(string $directory): array
    {
        $names = [];
        foreach ($this->linked($directory) ? [] : (@scandir($directory) ?: []) as $file) {
            if (! str_starts_with($file, '.') && str_ends_with($file, '.json')) {
                $names[] = $file;
            }
        }

        return $names;
    }

    private function read(string $path, ?bool $native = null): ?PublicationOutboxEntry
    {
        if (is_link($path)) {
            $this->invalid[$path] = true;

            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            // Claimed by another forwarder between listing and reading.
            return null;
        }
        $entry = ($native ?? self::$useRustFfi)
            ? self::decodeEntryNative(basename($path), $path, $json, (int) @filemtime($path))
            : self::decodeEntryPhp(basename($path), $path, $json, (int) @filemtime($path));
        if ($entry === null) {
            $this->invalid[$path] = true;
        }

        return $entry;
    }

    public static function decodeEntryPhp(string $name, string $path, string $json, int $modifiedAt): ?PublicationOutboxEntry
    {
        return PublicationOutboxEntry::decodePhp($name, $path, $json, $modifiedAt);
    }

    public static function decodeEntryNative(string $name, string $path, string $json, int $modifiedAt): ?PublicationOutboxEntry
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        $data = json_decode($json, true);
        if ($ffi === null || ! is_array($data)) {
            return self::decodeEntryPhp($name, $path, $json, $modifiedAt);
        }
        // Preserve PHP JSON parsing, duplicate keys and number classification.
        $input = serialize($data);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 512, $capacity);
        if ($buffer !== null) {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave15_outbox_normalize($input, strlen($input), $buffer, $capacity);
            if ($written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_wave15_outbox_normalize($input, strlen($input), $buffer, $capacity);
                }
            }
            if ($buffer !== null && $written > 0 && $written <= $capacity) {
                $row = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);
                if (is_array($row) && count($row) === 8) {
                    return new PublicationOutboxEntry($name, $path, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $modifiedAt, $row[6], $row[7]);
                }
            }
        }

        return self::decodeEntryPhp($name, $path, $json, $modifiedAt);
    }

    private static function folderNative(string $folder, int $mode, int $except): bool
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi !== null) {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_wave15_outbox_folder($folder, strlen($folder), $mode, $except);
            if ($result >= 0) {
                return $result === 1;
            }
        }

        return $mode === 0 ? $folder !== '.' && $folder !== '..'
            : preg_match('/\A[1-9]\d*\z/D', $folder) === 1 && (int) $folder !== $except;
    }

    private static function entryStatePhp(PublicationOutboxEntry $entry, float $now, float $maxAge): int
    {
        return (int) ($entry->notBefore <= $now) | ((int) $entry->olderThan($maxAge, $now) << 1)
            | ((int) ($entry->attempts > 0 || $entry->deferrals > 0) << 2);
    }

    private static function entryStateNative(PublicationOutboxEntry $entry, float $now, float $maxAge): int
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return self::entryStatePhp($entry, $now, $maxAge);
        }

        // @phpstan-ignore method.notFound
        return $ffi->nntmux_wave15_outbox_state($entry->enqueuedAt, $entry->notBefore, $entry->attempts, $entry->deferrals, $now, $maxAge);
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

    // Rust is the default only while the paired W57.6 benchmark clears 1.5x on all four metrics.
    private const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        int32_t nntmux_w576_outbox_window_batch(const char *floats, size_t floats_len, const char *ints, size_t ints_len, size_t count, double now, double max_age, double margin, double max_backoff, int64_t cap, int64_t *out, size_t out_cap);
        C;

    private const int W57_MAX_ITEMS = 1_048_576;

    public const int W57_WINDOW_HEADER = 14;

    public const int W57_WINDOW_WIDTH = 2;

    private const int W57_COUNT_CAP = 2_147_483_647;

    private const float W57_MS_CAP = 4.5e15;

    /**
     * Classify pending entries the way {@see survey()} and {@see claim()} see them, given their
     * enqueued-at and not-before times, attempts and deferrals (each clamped to 0..2^31-1). State bits
     * as `entryStatePhp()`: due 1, expired 2, retried 4. Class 0 waiting (not due), 1 fresh, 2 expired,
     * 3 retried; expired entries are always taken, due ones while fewer than `$cap` were.
     *
     * Layout: `[0]` count, `[1]` due, `[2]` expired, `[3]` retried, `[4]` fresh, `[5]` waiting, `[6]`
     * taken, `[7]` first expired and `[8]` first retried index, `[9]` oldest due unexpired index, `[10]`
     * earliest wake index (-1 when none), `[11]` earliest wake in ms from `$now` (0 when none), `[12]`
     * failures, `[13]` FNV-1a digest; then two words per entry: `state | class << 3 | taken << 5 |
     * min(failures, 2^20-1) << 6 | backoff_ms << 26` (the next backoff `min($maxBackoff, 2 ** (failures
     * + 1))` in ms, clamped to 0..2^36-1) and the wake in ms (`enqueued + maxAge - margin`, or the
     * earlier not-before, minus `$now`; 0 when expired).
     *
     * @param  array<array-key, float>  $enqueuedAt
     * @param  array<array-key, float>  $notBefore
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>
     */
    public static function classifyEntryWindowBatch(array $enqueuedAt, array $notBefore, array $attempts, array $deferrals,
        float $now, float $maxAge, float $margin = 0.0, float $maxBackoff = 30.0, int $cap = PHP_INT_MAX): array
    {
        return (self::usesW57RustFfi() ? self::classifyEntryWindowBatchNative($enqueuedAt, $notBefore, $attempts, $deferrals, $now, $maxAge, $margin, $maxBackoff, $cap) : null)
            ?? self::classifyEntryWindowBatchPhp($enqueuedAt, $notBefore, $attempts, $deferrals, $now, $maxAge, $margin, $maxBackoff, $cap);
    }

    /**
     * @param  array<array-key, float>  $enqueuedAt
     * @param  array<array-key, float>  $notBefore
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>
     */
    public static function classifyEntryWindowBatchPhp(array $enqueuedAt, array $notBefore, array $attempts, array $deferrals,
        float $now, float $maxAge, float $margin = 0.0, float $maxBackoff = 30.0, int $cap = PHP_INT_MAX): array
    {
        $enqueuedAt = array_values($enqueuedAt);
        $notBefore = array_values($notBefore);
        $attempts = array_values($attempts);
        $deferrals = array_values($deferrals);
        $count = \count($enqueuedAt);
        $out = array_fill(0, self::W57_WINDOW_HEADER, 0);
        $out[0] = $count;
        $out[7] = $out[8] = $out[9] = $out[10] = -1;
        $claimed = $wake = 0;
        $oldest = 0.0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($at = 0; $at < $count; $at++) {
            $enqueued = (float) $enqueuedAt[$at];
            $waitUntil = (float) ($notBefore[$at] ?? 0.0);
            $tried = (int) ($attempts[$at] ?? 0);
            $tried = $tried < 0 ? 0 : ($tried > self::W57_COUNT_CAP ? self::W57_COUNT_CAP : $tried);
            $deferred = (int) ($deferrals[$at] ?? 0);
            $deferred = $deferred < 0 ? 0 : ($deferred > self::W57_COUNT_CAP ? self::W57_COUNT_CAP : $deferred);
            $due = $waitUntil <= $now;
            $expired = $now - $enqueued > $maxAge;
            $retried = $tried > 0 || $deferred > 0;
            $state = (int) $due | (int) $expired << 1 | (int) $retried << 2;
            if ($expired) {
                $out[2]++;
                if ($out[7] < 0) {
                    $out[7] = $at;
                }
                [$class, $taken] = [2, 1];
            } elseif ($due) {
                $taken = $claimed < $cap ? 1 : 0;
                $claimed += $taken;
                if ($retried) {
                    $out[3]++;
                    if ($out[8] < 0) {
                        $out[8] = $at;
                    }
                } else {
                    $out[4]++;
                }
                if ($out[9] < 0 || $enqueued < $oldest) {
                    $out[9] = $at;
                    $oldest = $enqueued;
                }
                $class = $retried ? 3 : 1;
            } else {
                $out[5]++;
                [$class, $taken] = [0, 0];
            }
            $out[1] += (int) $due;
            $out[6] += $taken;
            $wakeMs = 0;
            if (! $expired) {
                $needs = $enqueued + $maxAge - $margin;
                if (! $due && $waitUntil < $needs) {
                    $needs = $waitUntil;
                }
                $wakeMs = self::w57Ms($needs - $now);
                if ($out[10] < 0 || $wakeMs < $wake) {
                    $out[10] = $at;
                    $wake = $wakeMs;
                }
            }
            $failures = $tried + $deferred;
            $out[12] += $failures;
            $power = 2.0 ** ($failures + 1);
            $backoffMs = self::w57Ms($power < $maxBackoff ? $power : $maxBackoff);
            $backoffMs = $backoffMs < 0 ? 0 : min($backoffMs, 68_719_476_735);
            $row = $state | $class << 3 | $taken << 5 | min($failures, 1_048_575) << 6 | $backoffMs << 26;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $row = $wakeMs;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[11] = $wake;
        $out[13] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, float>  $enqueuedAt
     * @param  array<array-key, float>  $notBefore
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>|null
     */
    public static function classifyEntryWindowBatchNative(array $enqueuedAt, array $notBefore, array $attempts, array $deferrals,
        float $now, float $maxAge, float $margin = 0.0, float $maxBackoff = 30.0, int $cap = PHP_INT_MAX): ?array
    {
        $count = \count($enqueuedAt);
        if ($count > self::W57_MAX_ITEMS || \count($notBefore) !== $count || \count($attempts) !== $count || \count($deferrals) !== $count) {
            return null;
        }
        $floats = pack('e*', ...array_values($enqueuedAt), ...array_values($notBefore));
        $ints = pack('q*', ...array_values($attempts), ...array_values($deferrals));
        $fields = self::W57_WINDOW_HEADER + self::W57_WINDOW_WIDTH * $count;

        // @phpstan-ignore method.notFound
        return self::w57Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w576_outbox_window_batch($floats, \strlen($floats), $ints, \strlen($ints), $count, $now, $maxAge, $margin, $maxBackoff, $cap, $out, $fields));
    }

    /** `$seconds * 1000.0` truncated toward zero, NaN as 0, clamped to ±4.5e15. */
    private static function w57Ms(float $seconds): int
    {
        $ms = $seconds * 1000.0;
        if (is_nan($ms)) {
            return 0;
        }

        return (int) ($ms < -self::W57_MS_CAP ? -self::W57_MS_CAP : ($ms > self::W57_MS_CAP ? self::W57_MS_CAP : $ms));
    }

    /**
     * Run `$callback` with the Wave 57.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $forced = self::w57RustFfiState();

        return \is_bool($forced) ? $forced : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 57.6 routing and binding in function statics (shared by every Wave 57.6 target, including readonly classes and enums).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w57` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w57', self::W57_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 57.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w57Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w57RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }
}
