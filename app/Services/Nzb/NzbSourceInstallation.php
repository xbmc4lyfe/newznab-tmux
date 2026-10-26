<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // Rust is the default only while the paired W56.6 benchmark clears 1.5x on all four metrics.
    private const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        int32_t nntmux_w566_install_pair_batch(const char *fields, size_t fields_len, const char *field_twin, size_t field_twin_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W56_PAIR_HEADER = 11;

    private const int W56_MAX_ITEMS = 1_048_576;

    /**
     * Classify installation path pairs as normalizeSourcePathPairPhp() does, and whether each
     * destination is its source's staged generation (`<source basename>.c<digits>-<16 hex>.next`
     * per NzbSourceGuard::STAGED_NAME); missing destinations are empty. Status 0 unchanged, 1
     * in-place generation (ready to settle), 2 in-place other name, 3 relocated, 4 an empty path.
     *
     * Layout: `[0]` count, `[1..5]` per status, `[6]` staged, `[7]` sibling, `[8]` canonical
     * (`<guid>.nzb.gz`) sources, `[9]` first index with status 2 or more (-1 when none), `[10]`
     * FNV-1a digest; then per pair `status | staged << 3 | sibling << 4 | empty << 5 | canonical
     * << 6 | generation << 7 | min(candidate digits, 255) << 8 | min(destination bytes, 2^20-1)
     * << 16`.
     *
     * @param  array<array-key, string>  $sources
     * @param  array<array-key, string>  $destinations
     * @return list<int>
     */
    public static function classifyPathPairBatch(array $sources, array $destinations): array
    {
        return (self::usesW56RustFfi() ? self::classifyPathPairBatchNative($sources, $destinations) : null)
            ?? self::classifyPathPairBatchPhp($sources, $destinations);
    }

    /**
     * @param  array<array-key, string>  $sources
     * @param  array<array-key, string>  $destinations
     * @return list<int>
     */
    public static function classifyPathPairBatchPhp(array $sources, array $destinations): array
    {
        $destinations = array_values($destinations);
        $out = array_fill(0, self::W56_PAIR_HEADER, 0);
        $out[0] = \count($sources);
        $out[9] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($sources as $source) {
            $destination = $destinations[$index] ?? '';
            $slash = strrpos($source, '/');
            $sourceParent = $slash === false ? '' : substr($source, 0, $slash + 1);
            $sourceBase = $slash === false ? $source : substr($source, $slash + 1);
            $slash = strrpos($destination, '/');
            $destinationParent = $slash === false ? '' : substr($destination, 0, $slash + 1);
            $destinationBase = $slash === false ? $destination : substr($destination, $slash + 1);
            $staged = $source !== $destination ? 1 : 0;
            $empty = $source === '' || $destination === '' ? 1 : 0;
            $sibling = $empty === 0 && $sourceParent === $destinationParent ? 1 : 0;
            $canonical = \strlen($sourceBase) > 7 && str_ends_with($sourceBase, '.nzb.gz') ? 1 : 0;
            $digits = preg_match('/^(.+)\.nzb\.gz\.c(\d+)-[0-9a-f]{16}\.next$/D', $destinationBase, $match) === 1 && $sourceBase === $match[1].'.nzb.gz'
                ? \strlen($match[2]) : null;
            $generation = $digits === null ? 0 : 1;
            $status = match (true) {
                $empty === 1 => 4,
                $staged === 0 => 0,
                $sibling === 1 => $generation === 1 ? 1 : 2,
                default => 3,
            };
            $out[1 + $status]++;
            $out[6] += $staged;
            $out[7] += $sibling;
            $out[8] += $canonical;
            if ($status >= 2 && $out[9] < 0) {
                $out[9] = $index;
            }
            $row = $status | $staged << 3 | $sibling << 4 | $empty << 5 | $canonical << 6 | $generation << 7 | min($digits ?? 0, 255) << 8
                | min(\strlen($destination), 1_048_575) << 16;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        $out[10] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $sources
     * @param  array<array-key, string>  $destinations
     * @return list<int>|null
     */
    public static function classifyPathPairBatchNative(array $sources, array $destinations): ?array
    {
        $count = \count($sources);
        if ($count > self::W56_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w56Strings([...array_values($sources), ...self::w56Fit($destinations, $count, '')]);
        $fields = self::W56_PAIR_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w56Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w566_install_pair_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields));
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
