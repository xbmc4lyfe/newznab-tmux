<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Support\ReleaseArchiveNamingEvidence;
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
 * @phpstan-type DeletionAnchor InodeDeletionAnchor|AbsentDeletionAnchor
 */
final class NzbSourceGuard
{
    /** @var array<string, true> */
    private static array $lockedPaths = [];

    public function __construct(private readonly NzbService $nzb) {}

    public static function digest(string $decoded): string
    {
        return hash('sha256', "nntmux:nzb-archive-naming:1\0".$decoded);
    }

    /** @return SourceSnapshot|null */
    public function snapshot(string $guid, bool $beginNamingInspection = false): ?array
    {
        return DB::transaction(function () use ($guid, $beginNamingInspection): ?array {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);

            return $release === null ? null : $this->withLockedSource($release, static function (?array $source) use ($release, $beginNamingInspection): ?array {
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
     *
     * @template TResult
     *
     * @param  callable(SourceSnapshot|null): TResult  $callback
     * @return TResult
     */
    public function withLockedSource(object $release, callable $callback): mixed
    {
        $this->lockRelease($release);

        return $this->withGuidLock((string) $release->guid, fn (): mixed => $callback($this->readCurrent((string) $release->guid)));
    }

    /**
     * Caller registers receipt rollback/close with its outer transaction.
     * The optional digest is a compare-and-swap for parser repairs.
     *
     * @param  (callable(string, string): bool)|null  $move
     */
    public function install(object $release, string $temporary, string $destination, ?string $expectedDigest = null, ?callable $move = null, bool $preserve = false): NzbSourceInstallation
    {
        $this->lockRelease($release);

        return $this->withGuidLock((string) $release->guid, function () use ($release, $temporary, $destination, $expectedDigest, $move, $preserve): NzbSourceInstallation {
            if ($expectedDigest !== null && ($this->readCurrent((string) $release->guid)['digest'] ?? null) !== $expectedDigest) {
                throw new RuntimeException('NZB source changed before replacement.');
            }

            return $this->installFile((string) $release->guid, $temporary, $destination,
                static function (string $digest) use ($release): void {
                    app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($release, $digest);
                }, $move, $preserve);
        });
    }

    /** Final source deletion uses the same release-to-source order as installation. */
    public function delete(string $guid, bool $onlyIfOrphan = false): bool
    {
        return DB::transaction(function () use ($guid, $onlyIfOrphan): bool {
            $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
            if ($release === null || $onlyIfOrphan) {
                return false;
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
     * @return DeletionAnchor
     */
    private function validateAnchor(array $receipt): array
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
     * @param  array<string|int, int>|false  $stat
     * @param  InodeDeletionAnchor  $receipt
     */
    private function matchesAnchor(array|false $stat, array $receipt): bool
    {
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && (string) $stat['dev'] === $receipt['dev'] && (string) $stat['ino'] === $receipt['ino'];
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
                if (! $installation->ownsCurrentPath()) {
                    return;
                }
                // A rolled-back replacement restores the source it replaced; a new installation is removed.
                if ($installation->previous !== null && is_file($installation->previous)) {
                    if (! @rename($installation->previous, $installation->path)) {
                        throw new RuntimeException('Cannot restore the NZB a rolled-back replacement displaced.');
                    }
                } elseif (! @unlink($installation->path)) {
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
     */
    private function installFile(string $guid, string $temporary, string $destination, ?callable $beforeRename = null, ?callable $move = null, bool $preserve = false): NzbSourceInstallation
    {
        $previous = null;
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
            if ($preserve && is_file($destination)) {
                // Keep the replaced source reachable so a rolled-back replacement can restore it.
                $previous = $destination.'.'.bin2hex(random_bytes(8)).'.prev';
                if (! @link($destination, $previous)) {
                    throw new RuntimeException('Cannot preserve the NZB being replaced.');
                }
            }
            if (! ($move === null ? @rename($temporary, $destination) : $move($temporary, $destination))) {
                throw new RuntimeException('Unable to move temporary NZB into place.');
            }
            clearstatcache(true, $destination);
            $owned = fstat($handle);
            $current = @lstat($destination);
            if ($owned === false || $current === false || $owned['dev'] !== $current['dev'] || $owned['ino'] !== $current['ino']) {
                throw new RuntimeException('Staged NZB installation identity changed.');
            }

            return new NzbSourceInstallation($this, $guid, $destination, $digest, $handle, DB::connection()->getPdo(), $previous);
        } catch (Throwable $exception) {
            fclose($handle);
            if ($previous !== null && is_file($previous)) {
                @unlink($previous);
            }
            throw $exception;
        }
    }
}
