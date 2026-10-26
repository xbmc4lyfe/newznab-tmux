<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Serializes retained NZB replacement and naming observations. The stable lock
 * files are shared with the NZBs and must never be removed during cleanup.
 *
 * @phpstan-type SourceSnapshot array{guid: string, path: string, contents: string, digest: string}
 * @phpstan-type InodeDeletionAnchor array{version: 1, kind: 'inode', guid: string, path: string, anchor: string, dev: string, ino: string}
 * @phpstan-type AbsentDeletionAnchor array{version: 1, kind: 'absent', guid: string, path: string}
 * @phpstan-type DatabaseDeletionAnchor array{version: 1, kind: 'database', guid: string, path: string, generation: string|null}
 * @phpstan-type DeletionAnchor InodeDeletionAnchor|AbsentDeletionAnchor|DatabaseDeletionAnchor
 */
final class NzbSourceGuard
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_nzb_source_digest(const char *ptr, size_t len, char *out, size_t out_cap);
        int32_t nntmux_nzb_source_validate_anchor(uint32_t absent, const char *guid_ptr, size_t guid_len, const char *path_ptr, size_t path_len, const char *anchor_ptr, size_t anchor_len, const char *dev_ptr, size_t dev_len, const char *ino_ptr, size_t ino_len);
        int32_t nntmux_nzb_source_matches_anchor(int64_t mode, int64_t dev, int64_t ino, const char *receipt_dev_ptr, size_t receipt_dev_len, const char *receipt_ino_ptr, size_t receipt_ino_len);
        int64_t nntmux_nzb_source_staged_guid_len(const char *ptr, size_t len);
        C;

    /** @var array<string, true> */
    private static array $lockedPaths = [];

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public function __construct(private readonly NzbService $nzb) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** Rust (hardware SHA-256) when the library is available: 7.8x mean / 6.5x p99 on 0.5 MB NZBs, 4x on tiny ones. */
    public static function digest(string $decoded): string
    {
        return self::digestNative($decoded) ?? self::digestPhp($decoded);
    }

    public static function digestPhp(string $decoded): string
    {
        return hash('sha256', "nntmux:nzb-archive-naming:1\0".$decoded);
    }

    /** Rust implementation of {@see digestPhp()}; null when the library is unavailable or disabled. */
    public static function digestNative(string $decoded): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_source_digest($decoded, \strlen($decoded), $scratch, $capacity);

        return $written === 64 ? FFI::string($scratch, 64) : null;
    }

    /** Partial upgrades stage their generation as `<canonical>.c<candidate>-<16 hex>.next` until they commit. */
    public const string STAGED_NAME = '/^(.+)\.nzb\.gz\.c\d+-[0-9a-f]{16}\.next$/D';

    /** The GUID a staged generation's basename names, or null when it is not a staged generation. */
    public static function stagedGuid(string $basename): ?string
    {
        return self::stagedGuidPhp($basename);
    }

    public static function stagedGuidPhp(string $basename): ?string
    {
        return preg_match(self::STAGED_NAME, $basename, $match) === 1 ? $match[1] : null;
    }

    /** Rust candidate for {@see stagedGuidPhp()}; `$supported` is false when the library is unavailable. */
    public static function stagedGuidNative(string $basename, bool &$supported = false): ?string
    {
        $supported = false;
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $length = $ffi->nntmux_nzb_source_staged_guid_len($basename, \strlen($basename));
        if (! \is_int($length) || $length === -1) {
            return null;
        }
        $supported = true;

        return $length < 1 ? null : substr($basename, 0, $length);
    }

    /** @return SourceSnapshot|null */
    public function snapshot(string $guid, bool $beginNamingInspection = false): ?array
    {
        return DB::transaction(function () use ($guid, $beginNamingInspection): ?array {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);

            return $release === null ? null : $this->withSettledSource($release, $beginNamingInspection, static function (?array $source) use ($release, $beginNamingInspection): ?array {
                if ($source !== null && $beginNamingInspection) {
                    // First inspection must leave legacy admission before any
                    // producer can mistake one readable file for the whole NZB.
                    app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($release, $source['digest']);
                }

                return $source;
            });
        });
    }

    /**
     * The caller owns its transaction and any canonical/candidate locks. No
     * callback may acquire a different release while holding this source lock.
     * The source is the committed generation: one still staged beside the
     * canonical file is put in place first (null while that cannot be done).
     *
     * @template TResult
     *
     * @param  callable(SourceSnapshot|null): TResult  $callback
     * @return TResult
     */
    public function withLockedSource(object $release, callable $callback): mixed
    {
        return $this->withSettledSource($release, false, $callback);
    }

    /**
     * With the caller's transaction, install the committed generation if it is still staged, and report the result:
     * 'settled' when the canonical file is the committed source (or none is recorded), 'failed' when the committed
     * generation is staged but could not be put in place (worth retrying), 'unresolved' when neither file is it.
     */
    public function settleLocked(object $release): string
    {
        return $this->withSettledSource($release, false, function (?array $source) use ($release): string {
            $committed = app(ReleaseArchiveNamingEvidence::class)->committedSourceDigestLocked($release);
            if ($committed === null || ($source !== null && hash_equals($committed, $source['digest']))) {
                return 'settled';
            }
            foreach ($this->stagedSiblings((string) $release->guid) as $path) {
                $digest = NzbSourceInstallation::isPendingStaged($path, DB::connection()->getPdo()) ? null : self::fileDigest($path);
                if ($digest !== null && hash_equals($committed, $digest)) {
                    return 'failed';
                }
            }

            return 'unresolved';
        });
    }

    public function installCompressed(object $release, string $body, ?string $expectedDigest = null): NzbSourceInstallation
    {
        $digest = app(DatabaseNzbStore::class)->putLocked($release, $body, $expectedDigest);

        return new NzbSourceInstallation($this, (string) $release->guid, NzbService::databaseReference((string) $release->guid),
            $digest, null, DB::connection()->getPdo(), database: true);
    }

    /**
     * Caller registers receipt rollback/close with its outer transaction.
     * The optional digest is a compare-and-swap for parser repairs.
     *
     * @param  (callable(string, string): bool)|null  $move
     * @param  int|null  $stageFor  The candidate whose in-place upgrade replaces this source. The new generation is
     *                              staged beside the canonical file, which keeps the committed one until the
     *                              receipt's close() after commit swaps it in; rollback only removes the staged file.
     */
    public function install(object $release, string $temporary, string $destination, ?string $expectedDigest = null, ?callable $move = null, ?int $stageFor = null): NzbSourceInstallation
    {
        $this->lockRelease($release);
        if (DatabaseNzbStore::enabled()) {
            $body = file_get_contents($temporary);
            if ($body === false) {
                throw new RuntimeException('Cannot read legacy NZB for database installation.');
            }

            return $this->installCompressed($release, $body, $expectedDigest);
        }

        return $this->withGuidLock((string) $release->guid, function () use ($release, $temporary, $destination, $expectedDigest, $move, $stageFor): NzbSourceInstallation {
            // A repair compares against the committed generation, never one an earlier upgrade has yet to swap in.
            if ($expectedDigest !== null && ($this->readSettledLocked($release)['digest'] ?? null) !== $expectedDigest) {
                throw new RuntimeException('NZB source changed before replacement.');
            }

            return $this->installFile((string) $release->guid, $temporary, $destination,
                static function (string $digest) use ($release): void {
                    app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($release, $digest);
                }, $move, $stageFor === null ? null : $destination.'.c'.$stageFor.'-'.bin2hex(random_bytes(8)).'.next');
        });
    }

    /**
     * Settle a staged generation left beside its release (a crash or failed callback between commit and swap,
     * or a rolled-back upgrade), in installation's lock order; see settleStagedLocked(). A dry run reports.
     *
     * @return 'applied'|'deleted'|'kept'
     */
    public function reconcileStaged(string $path, bool $apply = true): string
    {
        $guid = self::stagedGuid(basename($path));
        if ($guid === null) {
            return 'kept';
        }

        return DB::transaction(function () use ($path, $guid, $apply): string {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
            $outcome = $this->withGuidLock($guid, fn (): string => $this->settleStagedLocked($release, $guid, $path, $apply));

            return $outcome === 'failed' ? 'kept' : $outcome;
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(SourceSnapshot|null): TResult  $callback
     * @return TResult
     */
    private function withSettledSource(object $release, bool $deferUnresolved, callable $callback): mixed
    {
        $this->lockRelease($release);

        return $this->withGuidLock((string) $release->guid, fn (): mixed => $callback($this->readSettledLocked($release, $deferUnresolved)));
    }

    /**
     * With the release row and source locks held. Every installation records its digest in its own transaction,
     * so a canonical file differing from the committed digest may have the committed generation staged beside it
     * still: that is put in place before anything reads, or records evidence for, the source.
     *
     * @return SourceSnapshot|null
     */
    private function readSettledLocked(object $release, bool $deferUnresolved = false): ?array
    {
        $guid = (string) $release->guid;
        $source = $this->readCurrent($guid);
        $committed = app(ReleaseArchiveNamingEvidence::class)->committedSourceDigestLocked($release);
        if ($committed === null || ($source !== null && hash_equals($committed, $source['digest']))) {
            return $source;
        }
        $outcomes = [];
        foreach ($this->stagedSiblings($guid) as $path) {
            // Staged by this very transaction: its evidence is not committed yet, and its close() settles it.
            if (! NzbSourceInstallation::isPendingStaged($path, DB::connection()->getPdo())) {
                $outcomes[] = $this->settleStagedLocked($release, $guid, $path, true);
            }
        }
        $current = in_array('applied', $outcomes, true) ? $this->readCurrent($guid) : $source;
        // Judged on the result, not the order siblings were visited: a stray one kept before the committed one was
        // applied leaves the canonical file correct.
        $inPlace = $current !== null && hash_equals($committed, $current['digest']);
        if ($deferUnresolved && ! $inPlace && array_intersect($outcomes, ['kept', 'failed']) !== []) {
            // Recording the current file's digest now could later cost the only copy of the committed source.
            throw new RuntimeException('A staged NZB generation is unresolved; naming inspection deferred.');
        }
        if (! $inPlace && in_array('failed', $outcomes, true)) {
            // The committed generation exists but is not in place: the canonical file is not the source.
            return null;
        }

        return $current;
    }

    /**
     * With the release row and source locks held. The staged file is put in place when it is the committed
     * generation and the canonical file is not; it goes when the canonical file is the committed generation (the
     * upgrade rolled back, or was already swapped in) or its release no longer exists. Anything else, including
     * a release without a committed digest, is kept for an operator. A dry run reports without changing files.
     *
     * @return 'applied'|'deleted'|'kept'|'failed'
     */
    private function settleStagedLocked(?object $release, string $guid, string $path, bool $apply): string
    {
        if (! is_file($path)) {
            return 'kept';
        }
        if ($release === null) {
            return ! $apply || @unlink($path) ? 'deleted' : 'kept';
        }
        $canonical = dirname($path).DIRECTORY_SEPARATOR.$guid.'.nzb.gz';
        $committed = app(ReleaseArchiveNamingEvidence::class)->committedSourceDigestLocked($release);
        if ($committed !== null) {
            $current = is_file($canonical) ? self::fileDigest($canonical) : null;
            // The committed source is already what readers resolve, beside this file or on another search path:
            // whatever is staged here is obsolete.
            $resolved = $this->nzb->nzbPath($guid);
            if (($current !== null && hash_equals($committed, $current))
                || (is_string($resolved) && $resolved !== $canonical && ($digest = self::fileDigest($resolved)) !== null && hash_equals($committed, $digest))) {
                return ! $apply || @unlink($path) ? 'deleted' : 'kept';
            }
            $staged = self::fileDigest($path);
            if ($staged !== null && hash_equals($committed, $staged)) {
                if (! $apply || @rename($path, $canonical)) {
                    if ($apply && ! self::syncDirectory($canonical)) {
                        Log::warning('Could not sync the directory of a swapped-in NZB generation.', ['source' => $canonical]);
                    }
                    if ($apply) {
                        $this->removeShadowedCopies($guid, $canonical);
                    }

                    return 'applied';
                }
                Log::warning('Could not put a committed staged NZB generation in place; kept.', ['staged_source' => $path]);

                return 'failed';
            }
        }
        Log::warning('Staged NZB generation matches neither the committed release nor its current source; kept.', [
            'staged_source' => $path,
        ]);

        return 'kept';
    }

    /**
     * Settle every staged generation left beside this release's NZB (see reconcileStaged()); true when none remains,
     * so the canonical file is the committed source.
     */
    public function settleStagedGenerations(string $guid): bool
    {
        foreach ($this->stagedSiblings($guid) as $path) {
            // Staged by this very transaction: its evidence is not committed yet, and its close() settles it.
            if (! NzbSourceInstallation::isPendingStaged($path, DB::connection()->getPdo())) {
                $this->reconcileStaged($path);
            }
        }

        return $this->stagedSiblings($guid) === [];
    }

    /** Whether a staged generation is left beside this release's NZB (reading the directory only, no locks). */
    public function hasStagedGenerations(string $guid): bool
    {
        return $this->stagedSiblings($guid) !== [];
    }

    /**
     * The staged generations left beside this release's NZB (reading the directory only, no locks).
     *
     * @return list<string>
     */
    public function stagedGenerations(string $guid): array
    {
        return $this->stagedSiblings($guid);
    }

    /** @return list<string> */
    private function stagedSiblings(string $guid): array
    {
        if (DatabaseNzbStore::enabled()) {
            return []; // Generations commit atomically; no filesystem staging remains.
        }
        // Staged beside the installation path, or beside an existing copy readers found first at another base path
        // or split level, which may have gone missing since: every directory readers search.
        $directories = array_unique(array_map(dirname(...), [$this->nzb->getNzbPath($guid), ...$this->nzb->nzbPathCandidates($guid)]));
        $paths = [];
        foreach ($directories as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.$guid.'.nzb.gz.c*-*.next') ?: [] as $path) {
                if (self::stagedGuid(basename($path)) === $guid) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Once a committed generation is in place, an older copy elsewhere on the search path (left by a changed split
     * level or base path) is removed where it can be, so it can never be read again for this release. A read-only
     * copy stays; readers find the installed generation first.
     */
    private function removeShadowedCopies(string $guid, string $installed): void
    {
        $candidates = $this->nzb->nzbPathCandidates($guid);
        if (! in_array($installed, $candidates, true)) {
            // Installed where readers never look (a staged file a tree scan found): the copies they can reach stay.
            return;
        }
        $inode = @fileinode($installed);
        foreach ($candidates as $candidate) {
            if ($candidate !== $installed && is_file($candidate) && @fileinode($candidate) !== $inode && ! @unlink($candidate)) {
                Log::info('Could not remove an older copy of an upgraded NZB.', ['source' => $candidate]);
            }
        }
    }

    /** Make a rename in $path's directory durable: on Linux and macOS a file's fsync does not cover its name. */
    private static function syncDirectory(string $path): bool
    {
        $directory = @fopen(dirname($path), 'r');
        if ($directory === false) {
            return false;
        }
        try {
            return @fsync($directory);
        } finally {
            fclose($directory);
        }
    }

    private static function fileDigest(string $file): ?string
    {
        $contents = @file_get_contents($file);
        $decoded = $contents === false ? false : @gzdecode($contents);

        return $decoded === false || $decoded === '' ? null : self::digest($decoded);
    }

    /** Final source deletion uses the same release-to-source order as installation. */
    public function delete(string $guid, bool $onlyIfOrphan = false): bool
    {
        return DB::transaction(function () use ($guid, $onlyIfOrphan): bool {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
            if ($release === null || $onlyIfOrphan) {
                return false;
            }

            if (DatabaseNzbStore::enabled()) {
                DB::table('release_nzb_contents')->where('guid', $guid)->delete();
                DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->delete();

                return true;
            }

            return $this->withGuidLock($guid, function () use ($guid): bool {
                $path = $this->nzb->nzbPath($guid);

                return $path !== false && @unlink($path);
            });
        });
    }

    /** Retain unqualified orphan paths, including an uncommitted publisher's file. */
    public function deleteOrphan(string $guid, string $path): bool
    {
        if (! in_array(basename($path), [$guid.'.nzb.gz', $guid.'.nzb'], true)) {
            throw new RuntimeException('Orphan NZB path does not match its GUID.');
        }

        // TODO: qualify orphan ownership across concurrent publication before
        // deleting. An absent row/current snapshot cannot exclude an uncommitted
        // publisher under READ COMMITTED, even with a striped filesystem lock.
        return false;
    }

    /**
     * Pin the audited inode before deleting its release in the caller's SQL
     * transaction. The hard link survives worker restarts and prevents inode
     * reuse; identical content at a successor inode is never deletion evidence.
     *
     * @return DeletionAnchor
     */
    public function captureDeletionAnchor(object $release): array
    {
        $this->lockRelease($release);

        if (DatabaseNzbStore::enabled()) {
            $row = DB::table('release_nzb_contents')->where('guid', $release->guid)->lockForUpdate()->first(['generation']);

            return ['version' => 1, 'kind' => 'database', 'guid' => (string) $release->guid,
                'path' => NzbService::databaseReference((string) $release->guid), 'generation' => $row === null ? null : (string) $row->generation];
        }

        return $this->withGuidLock((string) $release->guid, function () use ($release): array {
            $guid = (string) $release->guid;
            $path = $this->nzb->nzbPath($guid);
            if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $guid) !== 1) {
                throw new RuntimeException('Audited NZB identity is unavailable.');
            }
            if ($path === false) {
                return ['version' => 1, 'kind' => 'absent', 'guid' => $guid, 'path' => $this->nzb->getNzbPath($guid)];
            }
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) {
                return ['version' => 1, 'kind' => 'absent', 'guid' => $guid, 'path' => $path];
            }
            if (($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Audited NZB is not a regular file.');
            }
            $anchor = dirname($path).DIRECTORY_SEPARATOR.'.archive-audit-'.$guid.'-'.bin2hex(random_bytes(16)).'.anchor';
            if (! @link($path, $anchor)) {
                throw new RuntimeException('Cannot pin audited NZB ownership.');
            }
            $receipt = ['version' => 1, 'kind' => 'inode', 'guid' => $guid, 'path' => $path, 'anchor' => $anchor,
                'dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino']];
            clearstatcache(true, $anchor);
            if (! $this->matchesAnchor(@lstat($anchor), $receipt)) {
                @unlink($anchor);
                throw new RuntimeException('Audited NZB anchor identity changed.');
            }
            try {
                DB::afterRollBack(fn () => $this->discardDeletionAnchor($receipt));
            } catch (Throwable $exception) {
                // Registration can fail without a transaction manager. We still
                // own this stripe, so remove only the newly pinned private link.
                clearstatcache(true, $anchor);
                if ($this->matchesAnchor(@lstat($anchor), $receipt) && ! @unlink($anchor)) {
                    try {
                        Log::warning('Failed audit registration retained its ownership anchor.', ['release_guid' => $guid]);
                    } catch (Throwable) {
                        // Preserve the original registration exception.
                    }
                }
                throw $exception;
            }

            return $receipt;
        });
    }

    /**
     * The pinned inode supplies custody after the release has gone. No database
     * locks are acquired under the source stripe; a concurrent publisher's new
     * inode is retained even before its release transaction becomes visible.
     *
     * @param  array<string, mixed>  $receipt
     */
    public function deleteAnchoredSource(array $receipt): bool
    {
        if (($receipt['kind'] ?? null) === 'database') {
            if (($receipt['version'] ?? null) !== 1 || ! is_string($receipt['guid'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $receipt['guid']) !== 1
                || ($receipt['path'] ?? null) !== NzbService::databaseReference($receipt['guid'])
                || (($receipt['generation'] ?? null) !== null && preg_match('/^[a-f0-9]{32}$/D', (string) $receipt['generation']) !== 1)) {
                throw new RuntimeException('Malformed database NZB ownership receipt.');
            }

            // The release foreign key already deleted its source in the audit transaction. A new publication
            // may reuse the GUID, but it can never reuse the random generation owned by this receipt.
            return ! DB::table('release_nzb_contents')->where('guid', $receipt['guid'])
                ->where('generation', $receipt['generation'] ?? '')->exists();
        }
        $anchor = $this->validateAnchor($receipt);
        if ($anchor['kind'] === 'absent') {
            // Absence means the audit owned no source, never permission to
            // inspect or delete a later publisher's file at the retained path.
            return true;
        }

        return $this->withGuidLock($anchor['guid'], function () use ($anchor): bool {
            $parent = dirname($anchor['path']);
            if (! is_dir($parent) || ! is_readable($parent) || ! is_executable($parent)) {
                throw new RuntimeException('Audited NZB directory is inaccessible.');
            }
            clearstatcache(true, $anchor['path']);
            clearstatcache(true, $anchor['anchor']);
            $current = @lstat($anchor['path']);
            $pinned = @lstat($anchor['anchor']);
            if ($pinned === false) {
                return $current === false;
            }
            if (! $this->matchesAnchor($pinned, $anchor)) {
                return false;
            }
            if ($this->matchesAnchor($current, $anchor) && ! @unlink($anchor['path'])) {
                return false;
            }

            return @unlink($anchor['anchor']);
        });
    }

    /** @param InodeDeletionAnchor $receipt */
    private function discardDeletionAnchor(array $receipt): void
    {
        try {
            $this->withGuidLock($receipt['guid'], function () use ($receipt): void {
                clearstatcache(true, $receipt['anchor']);
                if ($this->matchesAnchor(@lstat($receipt['anchor']), $receipt) && ! @unlink($receipt['anchor'])) {
                    throw new RuntimeException('Cannot remove rolled-back NZB audit anchor.');
                }
            });
        } catch (Throwable $exception) {
            // Do not interrupt rollback-manager cleanup or replace its exception.
            try {
                Log::warning('NZB audit rollback retained its ownership anchor.', [
                    'release_guid' => $receipt['guid'], 'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging must not escape the rollback callback either.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return InodeDeletionAnchor|AbsentDeletionAnchor
     */
    private function validateAnchor(array $receipt): array
    {
        return self::validateAnchorPhp($receipt);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return InodeDeletionAnchor|AbsentDeletionAnchor
     */
    public static function validateAnchorPhp(array $receipt): array
    {
        if (($receipt['version'] ?? null) !== 1 || ! in_array($receipt['kind'] ?? null, ['inode', 'absent'], true)) {
            throw new RuntimeException('Unsupported NZB audit ownership receipt.');
        }
        $keys = $receipt['kind'] === 'absent' ? ['guid', 'path'] : ['guid', 'path', 'anchor', 'dev', 'ino'];
        foreach ($keys as $key) {
            if (! isset($receipt[$key]) || ! is_string($receipt[$key]) || $receipt[$key] === '' || str_contains($receipt[$key], "\0")) {
                throw new RuntimeException('Malformed NZB audit ownership receipt.');
            }
        }
        if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $receipt['guid']) !== 1
            || ! in_array(basename($receipt['path']), [$receipt['guid'].'.nzb.gz', $receipt['guid'].'.nzb'], true)) {
            throw new RuntimeException('NZB audit ownership receipt does not match its source.');
        }
        if ($receipt['kind'] === 'absent') {
            return ['version' => 1, 'kind' => 'absent', 'guid' => $receipt['guid'], 'path' => $receipt['path']];
        }
        if (dirname($receipt['path']) !== dirname($receipt['anchor'])
            || preg_match('/^\.archive-audit-'.preg_quote($receipt['guid'], '/').'\-[a-f0-9]{32}\.anchor$/D', basename($receipt['anchor'])) !== 1
            || ! ctype_digit($receipt['dev']) || ! ctype_digit($receipt['ino'])) {
            throw new RuntimeException('NZB audit ownership receipt does not match its source.');
        }

        return ['version' => 1, 'kind' => 'inode', 'guid' => $receipt['guid'], 'path' => $receipt['path'], 'anchor' => $receipt['anchor'],
            'dev' => $receipt['dev'], 'ino' => $receipt['ino']];
    }

    /**
     * Rust candidate for {@see validateAnchorPhp()}; null (with `$supported` false) when the library is unavailable.
     *
     * @param  array<string, mixed>  $receipt
     * @return InodeDeletionAnchor|AbsentDeletionAnchor|null
     */
    public static function validateAnchorNative(array $receipt, bool &$supported = false): ?array
    {
        $supported = false;
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        $supported = true;
        if (($receipt['version'] ?? null) !== 1 || ! in_array($receipt['kind'] ?? null, ['inode', 'absent'], true)) {
            throw new RuntimeException('Unsupported NZB audit ownership receipt.');
        }
        $absent = $receipt['kind'] === 'absent';
        foreach ($absent ? ['guid', 'path'] : ['guid', 'path', 'anchor', 'dev', 'ino'] as $key) {
            if (! isset($receipt[$key]) || ! is_string($receipt[$key])) {
                throw new RuntimeException('Malformed NZB audit ownership receipt.');
            }
        }
        $anchor = $absent ? '' : $receipt['anchor'];
        $dev = $absent ? '' : $receipt['dev'];
        $ino = $absent ? '' : $receipt['ino'];
        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_nzb_source_validate_anchor($absent ? 1 : 0, $receipt['guid'], \strlen($receipt['guid']), $receipt['path'], \strlen($receipt['path']),
            $anchor, \strlen($anchor), $dev, \strlen($dev), $ino, \strlen($ino));
        if ($code === 1) {
            throw new RuntimeException('Malformed NZB audit ownership receipt.');
        }
        if ($code === 2) {
            throw new RuntimeException('NZB audit ownership receipt does not match its source.');
        }
        if ($code !== 0) {
            $supported = false;

            return null;
        }

        return $absent ? ['version' => 1, 'kind' => 'absent', 'guid' => $receipt['guid'], 'path' => $receipt['path']]
            : ['version' => 1, 'kind' => 'inode', 'guid' => $receipt['guid'], 'path' => $receipt['path'], 'anchor' => $anchor,
                'dev' => $dev, 'ino' => $ino];
    }

    /**
     * @param  array<string|int, int>|false  $stat
     * @param  InodeDeletionAnchor  $receipt
     */
    private function matchesAnchor(array|false $stat, array $receipt): bool
    {
        return self::matchesAnchorPhp($stat, $receipt);
    }

    /**
     * @param  array<string|int, int>|false  $stat
     * @param  array{dev: string, ino: string}  $receipt
     */
    public static function matchesAnchorPhp(array|false $stat, array $receipt): bool
    {
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && (string) $stat['dev'] === $receipt['dev'] && (string) $stat['ino'] === $receipt['ino'];
    }

    /**
     * Rust candidate for {@see matchesAnchorPhp()}; null when the library is unavailable.
     *
     * @param  array<string|int, int>|false  $stat
     * @param  array{dev: string, ino: string}  $receipt
     */
    public static function matchesAnchorNative(array|false $stat, array $receipt): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        if ($stat === false) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_nzb_source_matches_anchor($stat['mode'], $stat['dev'], $stat['ino'],
            $receipt['dev'], \strlen($receipt['dev']), $receipt['ino'], \strlen($receipt['ino']));

        return $code < 0 ? null : $code === 1;
    }

    /** Ownership-aware cleanup, including when another writer installed identical bytes. */
    public function rollbackInstallation(NzbSourceInstallation $installation): void
    {
        $connection = DB::connection();
        $pdo = $installation->pdo;
        if ($connection->getPdo() !== $pdo) {
            throw new RuntimeException('NZB cleanup connection identity changed.');
        }
        $ownsTransaction = $connection->transactionLevel() === 0;
        if ($pdo->inTransaction() === $ownsTransaction) {
            throw new RuntimeException('NZB cleanup transaction identity changed.');
        }
        // Laravel invokes rollback callbacks before removing their transaction
        // record. Do not reenter its manager, reconnect, or retry from here.
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $suffix = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $query = $pdo->prepare('SELECT id FROM releases WHERE guid = ?'.$suffix);
            $query->execute([$installation->guid]);
            $query->fetchAll();
            $query->closeCursor();
            $this->withGuidLock($installation->guid, static function () use ($installation): void {
                if ($installation->staged !== null) {
                    // A staged generation never reached the canonical path, which keeps its committed source.
                    if ($installation->ownsStagedPath() && ! @unlink($installation->staged)) {
                        throw new RuntimeException('Cannot remove a rolled-back staged NZB generation.');
                    }

                    return;
                }
                if ($installation->ownsCurrentPath() && ! @unlink($installation->path)) {
                    throw new RuntimeException('Cannot remove owned rolled-back NZB installation.');
                }
            });
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function lockRelease(object $release): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('NZB source operations require the caller transaction.');
        }
        $actual = DB::table('releases')->where('id', $release->id)->where('guid', $release->guid)->lockForUpdate()->first(['id']);
        if ($actual === null) {
            throw new RuntimeException('NZB release identity changed.');
        }
    }

    /** @return SourceSnapshot|null */
    private function readCurrent(string $guid): ?array
    {
        if (DatabaseNzbStore::enabled()) {
            $contents = $this->nzb->readNzbContents($guid);

            return is_string($contents) ? ['guid' => $guid, 'path' => NzbService::databaseReference($guid),
                'contents' => $contents, 'digest' => self::digest($contents)] : null;
        }
        $path = $this->nzb->nzbPath($guid);
        if ($path === false) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        if (str_ends_with(strtolower($path), '.gz')) {
            $contents = @gzdecode($contents);
        }
        if ($contents === false || $contents === '') {
            return null;
        }

        return ['guid' => $guid, 'path' => $path, 'contents' => $contents, 'digest' => self::digest($contents)];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withGuidLock(string $guid, callable $callback): mixed
    {
        if (DatabaseNzbStore::enabled()) {
            return $callback(); // The caller holds the release row lock on the shared database.
        }
        $root = rtrim($this->nzb->buildNzbPath($guid, 0, true), DIRECTORY_SEPARATOR);
        $path = $root.DIRECTORY_SEPARATOR.'.archive-source-'.substr(hash('sha256', $guid), 0, 2).'.lock';
        if (isset(self::$lockedPaths[$path])) {
            throw new RuntimeException('Nested NZB source locks are not supported.');
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open shared NZB source lock.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire shared NZB source lock.');
            }
            self::$lockedPaths[$path] = true;

            return $callback();
        } finally {
            unset(self::$lockedPaths[$path]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  (callable(string): void)|null  $beforeRename
     * @param  (callable(string, string): bool)|null  $move
     * @param  string|null  $staged  Where the new generation waits for its transaction to commit, instead of $destination.
     */
    private function installFile(string $guid, string $temporary, string $destination, ?callable $beforeRename = null, ?callable $move = null, ?string $staged = null): NzbSourceInstallation
    {
        $target = $staged ?? $destination;
        $handle = @fopen($temporary, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open staged NZB installation.');
        }
        try {
            $contents = stream_get_contents($handle);
            if ($contents !== false && str_ends_with(strtolower($destination), '.gz')) {
                $contents = @gzdecode($contents);
            }
            if ($contents === false || $contents === '') {
                throw new RuntimeException('Cannot decode staged NZB installation.');
            }
            $digest = self::digest($contents);
            if ($beforeRename !== null) {
                $beforeRename($digest);
            }
            if (! ($move === null ? @rename($temporary, $target) : $move($temporary, $target))) {
                throw new RuntimeException('Unable to move temporary NZB into place.');
            }
            if ($staged !== null && ! self::syncDirectory($target)) {
                // The evidence committed with it names this file; it must survive a power loss once committed.
                throw new RuntimeException('Cannot make the staged NZB generation durable.');
            }
            clearstatcache(true, $target);
            $owned = fstat($handle);
            $current = @lstat($target);
            if ($owned === false || $current === false || $owned['dev'] !== $current['dev'] || $owned['ino'] !== $current['ino']) {
                throw new RuntimeException('Staged NZB installation identity changed.');
            }

            return new NzbSourceInstallation($this, $guid, $destination, $digest, $handle, DB::connection()->getPdo(), $staged);
        } catch (Throwable $exception) {
            fclose($handle);
            // The staged name is this installation's own; the canonical file was never touched.
            if ($staged !== null && is_file($staged)) {
                @unlink($staged);
            }
            throw $exception;
        }
    }

    /** The Wave 42.2 export shared by the staged name, path pair, upload filename, metadata type and part offset helpers. */
    public const string W422_FFI_CDEF = <<<'C'
        int32_t nntmux_w422_nzb_source_guard_install_staging_xml_par2(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W422_FFI_DOMAIN = 'nfo_w422';

    /**
     * Default for the Wave 42.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php is below 1.5x.
     */
    public const bool W422_RUST_FFI_DEFAULT = false;

    public const int W422_MAX_INPUT = 16777216;

    /** The canonical basename of a retained source, as validateAnchorPhp() requires it. */
    public const string W422_CANONICAL_NAME = '/^[a-zA-Z0-9_-]{1,40}\.nzb(\.gz)?$/D';

    private static ?bool $useW422RustFfi = null;

    public static function useW422RustFfi(bool $enable = true): void
    {
        self::$useW422RustFfi = $enable;
    }

    public static function usesW422RustFfi(): bool
    {
        return self::$useW422RustFfi ?? self::W422_RUST_FFI_DEFAULT;
    }

    public static function resetW422RustFfi(): void
    {
        self::$useW422RustFfi = null;
    }

    /**
     * Run one Wave 42.2 operation; null whenever the library, input or answer is unusable, so callers fall back to PHP.
     *
     * @return list<int>|null
     */
    public static function evaluateW422Native(int $operation, string $input, int $slots): ?array
    {
        try {
            if ($operation < 0 || $operation > 0xFFFFFFFF || $slots < 1 || $input === '' || \strlen($input) > self::W422_MAX_INPUT) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain(self::W422_FFI_DOMAIN, self::W422_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $needed = $slots * 8;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $output = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w422_nzb_source_guard_install_staging_xml_par2($operation, 0, $input, \strlen($input), $output, $capacity);
                if (! \is_int($written) || $written === -1 || $written > $capacity) {
                    return null;
                }
                if ($written < 0) {
                    // Too small: retry once with the reported capacity.
                    $needed = -$written;

                    continue;
                }
                if ($written !== $slots * 8) {
                    return null;
                }
                $values = array_values(unpack('q'.$slots, FFI::string($output, $written)) ?: []);

                return \count($values) === $slots ? $values : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Pack `[$lead, rows]`, one length slot per text (-1 for a non-string), `$width` texts per row, then the texts after
     * `$prefix`; null when the packed input would exceed W422_MAX_INPUT.
     *
     * @param  list<string|null>  $texts
     */
    public static function packW422Texts(int $lead, array $texts, string $prefix = '', int $width = 1): ?string
    {
        $size = 16 + \count($texts) * 8 + \strlen($prefix);
        if ($width < 1 || \count($texts) % $width !== 0 || $size > self::W422_MAX_INPUT) {
            return null;
        }
        $words = [$lead, intdiv(\count($texts), $width)];
        $tail = [$prefix];
        foreach ($texts as $text) {
            if ($text === null) {
                $words[] = -1;

                continue;
            }
            $words[] = \strlen($text);
            $tail[] = $text;
            $size += \strlen($text);
            if ($size > self::W422_MAX_INPUT) {
                return null;
            }
        }

        return pack('q*', ...$words).implode('', $tail);
    }

    /**
     * Classify NZB source basenames: invalid (not a string), staged (stagedGuidPhp() names a GUID, whose byte lengths are
     * summed and maximized), canonical (`<guid>.nzb` or `<guid>.nzb.gz` with a 1-40 character `[a-zA-Z0-9_-]` GUID) or other.
     *
     * @param  array<array-key, mixed>  $basenames
     * @return array{names: int, invalid: int, staged: int, canonical: int, other: int, guid_bytes: int, max_guid_bytes: int}
     */
    public static function summarizeStagedNameBatch(array $basenames): array
    {
        return (self::usesW422RustFfi() ? self::summarizeStagedNameBatchNative($basenames) : null)
            ?? self::summarizeStagedNameBatchPhp($basenames);
    }

    /**
     * @param  array<array-key, mixed>  $basenames
     * @return array{names: int, invalid: int, staged: int, canonical: int, other: int, guid_bytes: int, max_guid_bytes: int}
     */
    public static function summarizeStagedNameBatchPhp(array $basenames): array
    {
        $invalid = $staged = $canonical = $other = $bytes = $max = 0;
        foreach ($basenames as $name) {
            if (! \is_string($name)) {
                $invalid++;
            } elseif (($guid = self::stagedGuidPhp($name)) !== null) {
                $staged++;
                $bytes += \strlen($guid);
                $max = max($max, \strlen($guid));
            } elseif (preg_match(self::W422_CANONICAL_NAME, $name) === 1) {
                $canonical++;
            } else {
                $other++;
            }
        }

        return ['names' => \count($basenames), 'invalid' => $invalid, 'staged' => $staged, 'canonical' => $canonical, 'other' => $other,
            'guid_bytes' => $bytes, 'max_guid_bytes' => $max];
    }

    /**
     * @param  array<array-key, mixed>  $basenames
     * @return array{names: int, invalid: int, staged: int, canonical: int, other: int, guid_bytes: int, max_guid_bytes: int}|null
     */
    public static function summarizeStagedNameBatchNative(array $basenames): ?array
    {
        $texts = [];
        foreach ($basenames as $name) {
            $texts[] = \is_string($name) ? $name : null;
        }
        $input = self::packW422Texts(0, $texts);
        $slots = $input === null ? null : self::evaluateW422Native(0, $input, 7);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $staged, $canonical, $other, $bytes, $max] = $slots;
        if ($count !== \count($basenames) || min($slots) < 0 || $invalid + $staged + $canonical + $other !== $count || $max > $bytes) {
            return null;
        }

        return ['names' => $count, 'invalid' => $invalid, 'staged' => $staged, 'canonical' => $canonical, 'other' => $other,
            'guid_bytes' => $bytes, 'max_guid_bytes' => $max];
    }

    private const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        int32_t nntmux_w566_anchor_receipt_batch(const char *versions, size_t versions_len, const char *fields, size_t fields_len, const char *field_twin, size_t field_twin_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W56_ANCHOR_HEADER = 9;

    private const int W56_MAX_ITEMS = 1_048_576;

    /**
     * Check many deletion receipts as validateAnchorPhp() does, without throwing, from a version
     * column and six string columns (kind, guid, path, anchor, dev, ino; missing values are 0 or
     * empty). Status 0 valid inode, 1 valid absent, 2 unsupported (its version or kind), 3 malformed,
     * 4 not matching its source.
     *
     * Layout: `[0]` count, `[1..5]` per status, `[6]` valid compressed (`.nzb.gz`) sources, `[7]`
     * first rejected index (-1 when none), `[8]` FNV-1a digest; then per receipt `status |
     * compressed << 3 | min(guid bytes, 255) << 4 | min(path bytes, 2^20-1) << 12`.
     *
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, string>  $anchors
     * @param  array<array-key, string>  $devs
     * @param  array<array-key, string>  $inos
     * @return list<int>
     */
    public static function validateAnchorBatch(array $versions, array $kinds, array $guids, array $paths, array $anchors, array $devs, array $inos): array
    {
        return (self::usesW56RustFfi() ? self::validateAnchorBatchNative($versions, $kinds, $guids, $paths, $anchors, $devs, $inos) : null)
            ?? self::validateAnchorBatchPhp($versions, $kinds, $guids, $paths, $anchors, $devs, $inos);
    }

    /**
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, string>  $anchors
     * @param  array<array-key, string>  $devs
     * @param  array<array-key, string>  $inos
     * @return list<int>
     */
    public static function validateAnchorBatchPhp(array $versions, array $kinds, array $guids, array $paths, array $anchors, array $devs, array $inos): array
    {
        $versions = array_values($versions);
        $kinds = array_values($kinds);
        $guids = array_values($guids);
        $paths = array_values($paths);
        $anchors = array_values($anchors);
        $devs = array_values($devs);
        $inos = array_values($inos);
        $count = \count($versions);
        $out = array_fill(0, self::W56_ANCHOR_HEADER, 0);
        $out[0] = $count;
        $out[7] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $kinds[$i] ?? '';
            $guid = $guids[$i] ?? '';
            $path = $paths[$i] ?? '';
            $base = '';
            if ($versions[$i] !== 1 || ($kind !== 'inode' && $kind !== 'absent')) {
                $status = 2;
            } else {
                $absent = $kind === 'absent';
                $anchor = $anchors[$i] ?? '';
                $dev = $devs[$i] ?? '';
                $ino = $inos[$i] ?? '';
                $base = basename($path);
                $anchorBase = $absent ? '' : basename($anchor);
                $status = match (true) {
                    $guid === '' || $path === '' || str_contains($guid, "\0") || str_contains($path, "\0"),
                    ! $absent && ($anchor === '' || $dev === '' || $ino === '' || str_contains($anchor, "\0") || str_contains($dev, "\0") || str_contains($ino, "\0")) => 3,
                    preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $guid) !== 1 || ($base !== $guid.'.nzb.gz' && $base !== $guid.'.nzb') => 4,
                    $absent => 1,
                    dirname($path) !== dirname($anchor)
                        || \strlen($anchorBase) !== \strlen($guid) + 55
                        || ! str_starts_with($anchorBase, '.archive-audit-'.$guid.'-')
                        || ! str_ends_with($anchorBase, '.anchor')
                        || strspn($anchorBase, '0123456789abcdef', \strlen($guid) + 16, 32) !== 32
                        || ! ctype_digit($dev) || ! ctype_digit($ino) => 4,
                    default => 0,
                };
            }
            $compressed = $status <= 1 && str_ends_with($base, '.gz') ? 1 : 0;
            $out[1 + $status]++;
            $out[6] += $compressed;
            if ($status >= 2 && $out[7] < 0) {
                $out[7] = $i;
            }
            $row = $status | $compressed << 3 | min(\strlen($guid), 255) << 4 | min(\strlen($path), 1_048_575) << 12;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[8] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust validation, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, string>  $anchors
     * @param  array<array-key, string>  $devs
     * @param  array<array-key, string>  $inos
     * @return list<int>|null
     */
    public static function validateAnchorBatchNative(array $versions, array $kinds, array $guids, array $paths, array $anchors, array $devs, array $inos): ?array
    {
        $count = \count($versions);
        if ($count > self::W56_MAX_ITEMS) {
            return null;
        }
        $ints = pack('q*', ...array_values($versions));
        [$joined, $twin] = self::w56Strings([...self::w56Fit($kinds, $count, ''), ...self::w56Fit($guids, $count, ''), ...self::w56Fit($paths, $count, ''),
            ...self::w56Fit($anchors, $count, ''), ...self::w56Fit($devs, $count, ''), ...self::w56Fit($inos, $count, '')]);
        $fields = self::W56_ANCHOR_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w56Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w566_anchor_receipt_batch($ints, \strlen($ints), $joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 56.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    public static function usesW56RustFfi(): bool
    {
        $forced = self::w56RustFfiState();

        return \is_bool($forced) ? $forced : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 56.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w56` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w56', self::W56_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 56.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w56Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w56RustFfiState('binding', $fields);
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

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w56Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$pad`.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $values
     * @param  TValue  $pad
     * @return list<TValue>
     */
    private static function w56Fit(array $values, int $count, mixed $pad): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $pad);
    }
}
