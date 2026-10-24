<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/** A retained descriptor prevents the installed inode being reused before cleanup. */
final class NzbSourceInstallation
{
    public const string FFI_CDEF = DirectorySyncBridge::FFI_CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /** @var resource|null */
    private mixed $handle;

    private bool $finished = false;

    /**
     * Staged generations of this process's installations whose transactions have not finished, with their connection.
     *
     * @var array<string, PDO>
     */
    private static array $pendingStaged = [];

    /** @param resource|null $handle */
    public function __construct(
        private readonly NzbSourceGuard $sources,
        public readonly string $guid,
        public readonly string $path,
        public readonly string $digest,
        mixed $handle,
        public readonly PDO $pdo,
        // An in-place upgrade's generation, beside $path until its transaction commits: close() swaps it in.
        public readonly ?string $staged = null,
        public readonly bool $database = false,
    ) {
        $this->handle = $handle;
        if ($staged !== null) {
            self::$pendingStaged[self::normalizeSourcePathPair($path, $staged)['destination']] = $pdo;
        }
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * An installation's canonical source and the path its new generation waits at: staged when they differ,
     * sibling when both name entries of the same directory (as an in-place upgrade's generation must).
     *
     * @return array{source: string, destination: string, staged: bool, sibling: bool}
     */
    public static function normalizeSourcePathPair(string $sourcePath, string $destinationPath): array
    {
        return self::usesRustFfi()
            ? self::normalizeSourcePathPairNative($sourcePath, $destinationPath)
            : self::normalizeSourcePathPairPhp($sourcePath, $destinationPath);
    }

    /** @return array{source: string, destination: string, staged: bool, sibling: bool} */
    public static function normalizeSourcePathPairPhp(string $sourcePath, string $destinationPath): array
    {
        $parent = static function (string $path): string {
            $slash = strrpos($path, '/');

            return $slash === false ? '' : substr($path, 0, $slash + 1);
        };

        return [
            'source' => $sourcePath,
            'destination' => $destinationPath,
            'staged' => $destinationPath !== $sourcePath,
            'sibling' => $sourcePath !== '' && $destinationPath !== '' && $parent($sourcePath) === $parent($destinationPath),
        ];
    }

    /** @return array{source: string, destination: string, staged: bool, sibling: bool} */
    public static function normalizeSourcePathPairNative(string $sourcePath, string $destinationPath): array
    {
        if (DirectorySyncBridge::transformNative(1, [$sourcePath, $destinationPath], $result) && \is_array($result)
            && array_keys($result) === ['source', 'destination', 'staged', 'sibling']
            && $result['source'] === $sourcePath && $result['destination'] === $destinationPath
            && \is_bool($result['staged']) && \is_bool($result['sibling'])) {
            /** @var array{source: string, destination: string, staged: bool, sibling: bool} $result */
            return $result;
        }

        return self::normalizeSourcePathPairPhp($sourcePath, $destinationPath);
    }

    /**
     * Whether $path is a generation staged by a transaction still open on $pdo: only its own close() settles it. A
     * reconnected connection's transaction cannot be the one that staged it.
     */
    public static function isPendingStaged(string $path, PDO $pdo): bool
    {
        return (self::$pendingStaged[$path] ?? null) === $pdo && $pdo->inTransaction();
    }

    public function ownsCurrentPath(): bool
    {
        if ($this->database) {
            $digest = DB::table('release_nzb_contents')->where('guid', $this->guid)->value('source_digest');

            return is_string($digest) && hash_equals($this->digest, $digest);
        }

        return $this->owns($this->path);
    }

    public function ownsStagedPath(): bool
    {
        return $this->staged !== null && $this->owns($this->staged);
    }

    public function rollback(): void
    {
        if ($this->database) {
            $this->finished = true;

            return; // SQL rollback already restores the previous source and queued job.
        }
        // Rolled back: a staged generation is only ever removed, never swapped in.
        $this->finished = true;
        try {
            $this->sources->rollbackInstallation($this);
        } catch (Throwable $exception) {
            // Cleanup must not interrupt Laravel's rollback-manager unwind or
            // replace the original publication exception. A retained source is
            // still checked against its actual digest before naming admission.
            try {
                Log::warning('NZB rollback cleanup retained its source.', [
                    'release_guid' => $this->guid,
                    'staged_source' => $this->staged,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging failure must not recursively reenter rollback either.
            }
        } finally {
            $this->close();
        }
    }

    /**
     * After commit: a staged generation is swapped in only when it is the committed one (see
     * NzbSourceGuard::reconcileStaged()); a failed COMMIT can still run after-commit callbacks.
     */
    public function close(): void
    {
        $this->release();
        if ($this->staged === null || $this->finished) {
            return;
        }
        $this->finished = true;
        if (! is_file($this->staged)) {
            // Already settled by a reader or the next installation.
            return;
        }
        try {
            $this->sources->reconcileStaged($this->staged);
        } catch (Throwable $exception) {
            try {
                Log::warning('Could not swap in a staged NZB generation after commit.', [
                    'staged_source' => $this->staged, 'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Settled later by naming inspection or nzbclean.
            }
        }
    }

    public function __destruct()
    {
        // Only the descriptor: a staged generation left unsettled is reconciled later.
        $this->release();
    }

    private function owns(string $path): bool
    {
        if (! is_resource($this->handle)) {
            return false;
        }
        clearstatcache(true, $path);
        $owned = fstat($this->handle);
        $current = @lstat($path);

        return $owned !== false && $current !== false
            && $owned['dev'] === $current['dev'] && $owned['ino'] === $current['ino'];
    }

    private function release(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        if ($this->staged !== null) {
            unset(self::$pendingStaged[$this->staged]);
        }
    }
}
