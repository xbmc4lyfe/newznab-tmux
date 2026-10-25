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

    /**
     * Default for the Wave 42.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php is below 1.5x.
     */
    public const bool W422_RUST_FFI_DEFAULT = false;

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
     * Summarize installation path pairs (`source`, `destination`) the way normalizeSourcePathPairPhp() classifies one:
     * invalid unless both are strings, otherwise unchanged (not staged), in_place (staged sibling) or relocated (staged
     * elsewhere). `sibling` counts every sibling pair, `empty_paths` every pair with an empty path.
     *
     * @param  array<array-key, mixed>  $pairs
     * @return array{pairs: int, invalid: int, unchanged: int, in_place: int, relocated: int, sibling: int, empty_paths: int}
     */
    public static function summarizeSourcePathPairs(array $pairs): array
    {
        return (self::usesW422RustFfi() ? self::summarizeSourcePathPairsNative($pairs) : null)
            ?? self::summarizeSourcePathPairsPhp($pairs);
    }

    /**
     * @param  array<array-key, mixed>  $pairs
     * @return array{pairs: int, invalid: int, unchanged: int, in_place: int, relocated: int, sibling: int, empty_paths: int}
     */
    public static function summarizeSourcePathPairsPhp(array $pairs): array
    {
        $invalid = $unchanged = $inPlace = $relocated = $sibling = $empty = 0;
        foreach ($pairs as $pair) {
            [$source, $destination] = self::pathPairFields($pair);
            if ($source === null || $destination === null) {
                $invalid++;

                continue;
            }
            $normalized = self::normalizeSourcePathPairPhp($source, $destination);
            if (! $normalized['staged']) {
                $unchanged++;
            } elseif ($normalized['sibling']) {
                $inPlace++;
            } else {
                $relocated++;
            }
            $sibling += $normalized['sibling'] ? 1 : 0;
            $empty += $source === '' || $destination === '' ? 1 : 0;
        }

        return ['pairs' => \count($pairs), 'invalid' => $invalid, 'unchanged' => $unchanged, 'in_place' => $inPlace, 'relocated' => $relocated,
            'sibling' => $sibling, 'empty_paths' => $empty];
    }

    /**
     * @param  array<array-key, mixed>  $pairs
     * @return array{pairs: int, invalid: int, unchanged: int, in_place: int, relocated: int, sibling: int, empty_paths: int}|null
     */
    public static function summarizeSourcePathPairsNative(array $pairs): ?array
    {
        $texts = [];
        foreach ($pairs as $pair) {
            array_push($texts, ...self::pathPairFields($pair));
        }
        $input = NzbSourceGuard::packW422Texts(0, $texts, '', 2);
        $slots = $input === null ? null : NzbSourceGuard::evaluateW422Native(1, $input, 7);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $unchanged, $inPlace, $relocated, $sibling, $empty] = $slots;
        if ($count !== \count($pairs) || min($slots) < 0 || $invalid + $unchanged + $inPlace + $relocated !== $count || $sibling > $count - $invalid
            || $empty > $count - $invalid) {
            return null;
        }

        return ['pairs' => $count, 'invalid' => $invalid, 'unchanged' => $unchanged, 'in_place' => $inPlace, 'relocated' => $relocated,
            'sibling' => $sibling, 'empty_paths' => $empty];
    }

    /** @return array{string|null, string|null} both paths, each null unless it is a string */
    private static function pathPairFields(mixed $pair): array
    {
        if (! \is_array($pair)) {
            return [null, null];
        }
        $source = $pair['source'] ?? null;
        $destination = $pair['destination'] ?? null;

        return [\is_string($source) ? $source : null, \is_string($destination) ? $destination : null];
    }
}
