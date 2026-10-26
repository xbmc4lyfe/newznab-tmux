<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\Runners\PostProcessRunner;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AdditionalProcessingDiagnostics
{
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private const int OVERSIZED_IN_FLIGHT_THRESHOLD = 500;

    private const int OVERSIZED_THREAD_THRESHOLD = 32;

    /**
     * @var list<string>
     */
    private const array REQUIRED_CLAIM_INDEX_COLUMNS = [
        'passwordstatus',
        'haspreview',
        'nzbstatus',
        'leftguid',
        'postdate',
        'id',
        'additional_pp_claimed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        $warnings = [];
        $configuration = app(ConfigurationProvider::class)->postProcessing();
        $releaseTimeout = $configuration->releaseTimeoutSeconds;
        $threads = max(1, $configuration->postThreads);
        $batchSize = max(1, $configuration->maxAdditionalProcessed);
        $childTimeout = (int) (config('nntmux.concurrency_timeout')
            ?? config('nntmux.multiprocessing_max_child_time', 1800));
        $claimTtl = AdditionalCandidateQuery::claimTtlSeconds();
        $backlog = $this->backlog($warnings);
        $indexes = $this->indexes($warnings);
        $tempPath = $this->tempPath($warnings);
        $capacity = self::claimIndexMatches([], $childTimeout, $releaseTimeout, $batchSize, $threads);
        $maximumClaimLifetime = $capacity['maximumClaimLifetime'];

        if ($backlog['stale_claims'] > 0) {
            $warnings[] = $this->warning(
                'stale-claims',
                $backlog['stale_claims'].' additional-processing claim(s) are stale and eligible for recovery.',
            );
        }

        if ($claimTtl <= $maximumClaimLifetime) {
            $warnings[] = $this->warning(
                'claim-ttl',
                'Claim TTL ('.$claimTtl.'s) does not exceed the estimated claimed-batch lifetime ('
                .$maximumClaimLifetime.'s); another worker may reclaim active work.',
            );
        }

        $maxInFlight = $capacity['maxInFlight'];
        if ($threads > self::OVERSIZED_THREAD_THRESHOLD || $maxInFlight > self::OVERSIZED_IN_FLIGHT_THRESHOLD) {
            $warnings[] = $this->warning(
                'oversized-capacity',
                'postthreads='.$threads.' and maxaddprocessed='.$batchSize.' allow up to '.$maxInFlight
                .' claimed releases at once; reduce threads or batch size and benchmark end-to-end throughput.',
            );
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'database_driver' => DB::getDriverName(),
            'backlog' => $backlog,
            'settings' => [
                'pipeline' => 'v2',
                'postthreads' => $threads,
                'maxaddprocessed' => $batchSize,
                'releaseprocessingtimeout' => $releaseTimeout,
                'maxpptimeoutcount' => $configuration->maxTimeoutCount,
                'worker_max_batches' => PostProcessRunner::ADDITIONAL_WORKER_MAX_BATCHES,
                'multiprocessing_child_timeout' => $childTimeout,
            ],
            'claims' => [
                'ttl_seconds' => $claimTtl,
                'stale_before' => AdditionalCandidateQuery::claimStaleBefore()->toIso8601String(),
                'estimated_claimed_batch_lifetime_seconds' => $maximumClaimLifetime,
            ],
            'capacity' => [
                'threads' => $threads,
                'releases_per_batch' => $batchSize,
                'max_in_flight' => $maxInFlight,
                'max_per_worker_lifetime' => $batchSize * PostProcessRunner::ADDITIONAL_WORKER_MAX_BATCHES,
            ],
            'indexes' => $indexes,
            'temp_path' => $tempPath,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array{total: int, available: int, active_claims: int, stale_claims: int, buckets: list<array{bucket: string, total: int, available: int}>}
     */
    private function backlog(array &$warnings): array
    {
        $empty = [
            'total' => 0,
            'available' => 0,
            'active_claims' => 0,
            'stale_claims' => 0,
            'buckets' => [],
        ];

        if (! Schema::hasTable('releases') || ! Schema::hasTable('categories')) {
            $warnings[] = $this->warning(
                'missing-processing-tables',
                'The releases or categories table is missing; backlog health cannot be inspected.',
            );

            return $empty;
        }

        $counts = AdditionalCandidateQuery::backlogCounts();
        $activeClaims = 0;
        $staleClaims = 0;

        if (AdditionalCandidateQuery::supportsClaims()) {
            $claimQuery = AdditionalCandidateQuery::baseBuilder(includeClaimed: true)
                ->whereNotNull('r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN);

            $activeClaims = (clone $claimQuery)
                ->where('r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN, '>=', AdditionalCandidateQuery::claimStaleBefore())
                ->count('r.id');
            $staleClaims = (clone $claimQuery)
                ->where('r.'.AdditionalCandidateQuery::CLAIMED_AT_COLUMN, '<', AdditionalCandidateQuery::claimStaleBefore())
                ->count('r.id');
        }

        return [
            ...$counts,
            'active_claims' => $activeClaims,
            'stale_claims' => $staleClaims,
            'buckets' => AdditionalCandidateQuery::bucketBacklog(),
        ];
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array{required_columns: list<string>, present: list<string>, claim_queue_index: string|null, missing: bool}
     */
    private function indexes(array &$warnings): array
    {
        $present = [];
        $claimQueueIndex = null;

        if (Schema::hasTable('releases')) {
            foreach (Schema::getIndexes('releases') as $index) {
                $name = (string) ($index['name'] ?? '');
                $columns = array_map(
                    static fn (mixed $column): string => strtolower((string) $column),
                    is_array($index['columns'] ?? null) ? $index['columns'] : [],
                );

                if ($name !== '') {
                    $present[] = $name;
                }
                if (self::claimIndexMatches($columns)['matches']) {
                    $claimQueueIndex = $name;
                }
            }
        }

        sort($present);
        $missing = $claimQueueIndex === null;
        if ($missing) {
            $warnings[] = $this->warning(
                'missing-index',
                'The releases claim-queue index is missing or has the wrong column order; run pending migrations before processing.',
            );
        }

        return [
            'required_columns' => self::REQUIRED_CLAIM_INDEX_COLUMNS,
            'present' => $present,
            'claim_queue_index' => $claimQueueIndex,
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array{path: string, exists: bool, writable: bool}
     */
    private function tempPath(array &$warnings): array
    {
        $path = rtrim((string) config('nntmux.tmp_unrar_path'), '/\\');
        $exists = $path !== '' && is_dir($path);
        $writable = $exists ? is_writable($path) : $this->nearestExistingDirectoryIsWritable($path);

        if (! $writable) {
            $warnings[] = $this->warning(
                'unwritable-temp-path',
                'Additional-processing temp path '.$path.' is not writable and cannot be created by the current user.',
            );
        }

        return [
            'path' => $path,
            'exists' => $exists,
            'writable' => $writable,
        ];
    }

    private function nearestExistingDirectoryIsWritable(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $candidate = $path;
        while (! is_dir($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }
            $candidate = $parent;
        }

        return is_writable($candidate);
    }

    /**
     * @return array{code: string, message: string}
     */
    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param  list<string>  $columns  Already normalized by the schema inspector.
     * @return array{matches: bool, maximumClaimLifetime: int, maxInFlight: int}
     */
    public static function claimIndexMatches(array $columns, int $childTimeout = 1, int $releaseTimeout = 1, int $batchSize = 1, int $threads = 1): array
    {
        return self::$useRustFfi ? (self::claimIndexMatchesNative($columns, $childTimeout, $releaseTimeout, $batchSize, $threads) ?? self::claimIndexMatchesPhp($columns, $childTimeout, $releaseTimeout, $batchSize, $threads)) : self::claimIndexMatchesPhp($columns, $childTimeout, $releaseTimeout, $batchSize, $threads);
    }

    /**
     * @param  list<string>  $columns
     * @return array{matches: bool, maximumClaimLifetime: int, maxInFlight: int}
     */
    public static function claimIndexMatchesPhp(array $columns, int $childTimeout = 1, int $releaseTimeout = 1, int $batchSize = 1, int $threads = 1): array
    {
        return [
            'matches' => $columns === self::REQUIRED_CLAIM_INDEX_COLUMNS,
            'maximumClaimLifetime' => min(max(1, $childTimeout), max(1, $releaseTimeout) * max(1, $batchSize)),
            'maxInFlight' => max(1, $threads) * max(1, $batchSize),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return array{matches: bool, maximumClaimLifetime: int, maxInFlight: int}|null
     */
    public static function claimIndexMatchesNative(array $columns, int $childTimeout = 1, int $releaseTimeout = 1, int $batchSize = 1, int $threads = 1): ?array
    {
        $value = self::nativeJson(compact('columns', 'childTimeout', 'releaseTimeout', 'batchSize', 'threads'));

        return is_array($value) ? $value : null;
    }

    /** @param array<mixed> $input */
    private static function nativeJson(array $input): mixed
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('additional',
                'ssize_t nntmux_additional_claim_index_matches_json(const char *input, size_t len, char *out, size_t cap);');
            if ($ffi === null) {
                return null;
            }
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($json)), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_additional_claim_index_matches_json($json, strlen($json), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (domain C ABI)
                $written = $ffi->nntmux_additional_claim_index_matches_json($json, strlen($json), $scratch, $cap);
            }
            if ($written < 0 || $written > $cap) {
                return null;
            }

            return json_decode(FFI::string($scratch, $written), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    public const bool W416_RUST_FFI_DEFAULT = false;

    private const string W416_FFI_CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private static ?bool $w416UseRustFfi = null;

    private static FFI|false|null $w416Ffi = null;

    public static function useW416RustFfi(bool $enable = true): void
    {
        self::$w416UseRustFfi = $enable;
    }

    public static function usesW416RustFfi(): bool
    {
        return self::$w416UseRustFfi ?? self::W416_RUST_FFI_DEFAULT;
    }

    public static function resetW416RustFfi(): void
    {
        self::$w416UseRustFfi = null;
        self::$w416Ffi = null;
    }

    /** @return list<int>|null */
    private static function w416Slots(int $operation, string $input, int $count): ?array
    {
        if (\strlen($input) > 16_777_216) {
            return null;
        }
        try {
            self::$w416Ffi ??= RustPipelineLibrary::resolveDomain('additional', self::W416_FFI_CDEF) ?? false;
            if (self::$w416Ffi === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = self::$w416Ffi->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = self::$w416Ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            foreach ($values as $value) {
                if (! \is_int($value) || $value < 0) {
                    return null;
                }
            }

            return array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    private static function w416Add(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }

    private static function w416Multiply(int $left, int $right): int
    {
        return $right !== 0 && $left > intdiv(PHP_INT_MAX, $right) ? PHP_INT_MAX : $left * $right;
    }

    /**
     * @param  array<array-key, mixed>  $samples
     * @return array{total: int, stale_claims: int, max_in_flight: int, unsafe_ttl: int, oversized: int, invalid: int}
     */
    public static function summarizeDiagnosticCapacityBatch(array $samples, int $claimTtl = 3600): array
    {
        return self::usesW416RustFfi()
            ? self::summarizeDiagnosticCapacityBatchNative($samples, $claimTtl)
            : self::summarizeDiagnosticCapacityBatchPhp($samples, $claimTtl);
    }

    /**
     * @param  array<array-key, mixed>  $samples
     * @return array{total: int, stale_claims: int, max_in_flight: int, unsafe_ttl: int, oversized: int, invalid: int}
     */
    public static function summarizeDiagnosticCapacityBatchPhp(array $samples, int $claimTtl = 3600): array
    {
        $result = ['total' => \count($samples), 'stale_claims' => 0, 'max_in_flight' => 0, 'unsafe_ttl' => 0, 'oversized' => 0, 'invalid' => 0];
        foreach ($samples as $row) {
            if (! \is_array($row) || ! \is_int($row['stale_claims'] ?? null) || ! \is_int($row['batch_size'] ?? null) || ! \is_int($row['threads'] ?? null) || ! \is_int($row['child_timeout'] ?? null) || ! \is_int($row['release_timeout'] ?? null)) {
                $result['invalid']++;

                continue;
            }
            $batch = max(1, $row['batch_size']);
            $threads = max(1, $row['threads']);
            $capacity = self::w416Multiply($batch, $threads);
            $lifetime = self::w416Add(max(0, $row['child_timeout']), self::w416Multiply(max(0, $row['release_timeout']), $batch));
            $result['stale_claims'] = self::w416Add($result['stale_claims'], max(0, $row['stale_claims']));
            $result['max_in_flight'] = max($result['max_in_flight'], $capacity);
            $result['unsafe_ttl'] += (int) ($claimTtl <= $lifetime);
            $result['oversized'] += (int) ($capacity > 500 || $threads > 32);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $samples
     * @return array{total: int, stale_claims: int, max_in_flight: int, unsafe_ttl: int, oversized: int, invalid: int}
     */
    public static function summarizeDiagnosticCapacityBatchNative(array $samples, int $claimTtl = 3600): array
    {
        $total = \count($samples);
        if ($total > 100_000) {
            return self::summarizeDiagnosticCapacityBatchPhp($samples, $claimTtl);
        }
        $frame = pack('qqV', $claimTtl, 0, $total);
        foreach ($samples as $row) {
            if (! \is_array($row) || ! \is_int($row['stale_claims'] ?? null) || ! \is_int($row['batch_size'] ?? null) || ! \is_int($row['threads'] ?? null) || ! \is_int($row['child_timeout'] ?? null) || ! \is_int($row['release_timeout'] ?? null)) {
                return self::summarizeDiagnosticCapacityBatchPhp($samples, $claimTtl);
            }
            $frame .= pack('qqqqq', $row['stale_claims'], $row['batch_size'], $row['threads'], $row['child_timeout'], $row['release_timeout']);
        }
        $slots = self::w416Slots(2, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[5] !== 0 || $slots[3] > $total || $slots[4] > $total) {
            return self::summarizeDiagnosticCapacityBatchPhp($samples, $claimTtl);
        }

        return [
            'total' => $slots[0],
            'stale_claims' => $slots[1],
            'max_in_flight' => $slots[2],
            'unsafe_ttl' => $slots[3],
            'oversized' => $slots[4],
            'invalid' => $slots[5],
        ];
    }

    // Rust is the default only while the paired W51.6 benchmark clears 1.5x on all four metrics.
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        int32_t nntmux_w516_claim_index_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *columns, size_t columns_len, const char *column_twin, size_t column_twin_len, const char *counts, size_t counts_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W51_INDEX_HEADER = 8;

    private const int W51_MAX_ITEMS = 1_048_576;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51Capacity = 0;

    /**
     * Check a batch of schema indexes against the claim-queue column order, comparing
     * columns after `strtolower()` as {@see self::indexes()} does.
     *
     * `$columnLists[$i]` holds the columns of `$names[$i]` (missing lists are empty). Layout:
     * `[0]` count, `[1]` exact matches, `[2]` last exact index (the one `inspect()` reports,
     * -1 when none), `[3]` longest leading run of required columns, `[4]` first index with it
     * (-1 when 0), `[5]` indexes holding every required column, `[6]` total columns, `[7]`
     * FNV-1a digest; then per index `exact | prefix << 1 | required_present << 4 | named << 7 |
     * columns << 8` (columns clamped to 2^20-1).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, list<string>>  $columnLists
     * @return list<int>
     */
    public static function matchClaimIndexBatch(array $names, array $columnLists): array
    {
        return (self::usesW51RustFfi() ? self::matchClaimIndexBatchNative($names, $columnLists) : null) ?? self::matchClaimIndexBatchPhp($names, $columnLists);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, list<string>>  $columnLists
     * @return list<int>
     */
    public static function matchClaimIndexBatchPhp(array $names, array $columnLists): array
    {
        $names = array_values($names);
        $columnLists = array_values($columnLists);
        $out = [\count($names), 0, -1, 0, -1, 0, 0, 0];
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($names as $index => $name) {
            $columns = array_map(strtolower(...), array_values($columnLists[$index] ?? []));
            $prefix = 0;
            foreach (self::REQUIRED_CLAIM_INDEX_COLUMNS as $position => $required) {
                if (($columns[$position] ?? null) !== $required) {
                    break;
                }
                $prefix++;
            }
            $exact = $columns === self::REQUIRED_CLAIM_INDEX_COLUMNS;
            $present = \count(array_intersect(self::REQUIRED_CLAIM_INDEX_COLUMNS, $columns));
            $total = \count($columns);
            if ($exact) {
                $out[1]++;
                $out[2] = $index;
            }
            if ($prefix > $out[3]) {
                $out[3] = $prefix;
                $out[4] = $index;
            }
            $out[5] += (int) ($present === \count(self::REQUIRED_CLAIM_INDEX_COLUMNS));
            $out[6] += $total;
            $row = (int) $exact | $prefix << 1 | $present << 4 | (int) ($name !== '') << 7 | min($total, 0xFFFFF) << 8;
            $digest = (($digest ^ $row) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust matching, or null when PHP must compute it (unavailable library, mismatched lists or
     * rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, list<string>>  $columnLists
     * @return list<int>|null
     */
    public static function matchClaimIndexBatchNative(array $names, array $columnLists): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W51_INDEX_HEADER + $count;
        $binding = $count <= self::W51_MAX_ITEMS && \count($columnLists) === $count ? self::w51Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $columnLists = array_values($columnLists);
        $counts = array_map(\count(...), $columnLists);
        $columns = array_merge(...$columnLists);
        if (\count($columns) !== array_sum($counts)) {
            return null;
        }
        [$joinedNames, $nameTwin] = self::w51Strings($names);
        [$joinedColumns, $columnTwin] = self::w51Strings($columns);
        $packed = pack('q*', ...$counts);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w516_claim_index_batch($joinedNames, \strlen($joinedNames), $nameTwin, \strlen($nameTwin), $joinedColumns, \strlen($joinedColumns), $columnTwin, \strlen($columnTwin), $packed, \strlen($packed), $count, $out, $fields);

            return self::w51Decode(\is_int($status) ? $status : -1, $out, $fields);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 51.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 51.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w51Binding(int $fields): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('additional_w51', self::W51_FFI_CDEF) ?? false;
            if (self::$w51Ffi === false) {
                return null;
            }
            $out = self::$w51Out;
            if ($out === null || self::$w51Capacity < $fields) {
                self::$w51Capacity = max(256, $fields);
                $out = self::$w51Out = self::$w51Ffi->new('int64_t['.self::$w51Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w51Ffi, $out];
    }

    /**
     * Decode `$fields` output values after a successful Wave 51.6 call.
     *
     * @return list<int>|null
     */
    private static function w51Decode(int $status, CData $out, int $fields): ?array
    {
        if ($status !== 1) {
            return null;
        }
        $values = unpack('P*', FFI::string($out, $fields * 8));

        return $values === false ? null : array_values($values);
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  array<array-key, string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w51Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // ========================================================================
    // WAVE 64.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.38x, p99 3.79x; uncommon mean 5.07x, p99 3.90x); see RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_DIAGNOSTICS_FFI_CDEF = <<<'C'
        ssize_t nntmux_w641_diagnostics_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w64DiagnosticsPlanBatch(). */
    public const int W64_DIAGNOSTICS_TRAILER = 57;

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /** Forces the AdditionalProcessingDiagnostics Wave 64 Rust path on until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Forces the AdditionalProcessingDiagnostics Wave 64 PHP path until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Runs $callback with the AdditionalProcessingDiagnostics Wave 64 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.1 telemetry plans, one per stage record `stage|outcome|duration_ms|error|bytes|requests` (trimmed fields).
     * Stage (lowercased): 0 empty, 1-12 setup, download, archive, password, nfo, media, sample, jpg, audio, par2, persist,
     * search, 15 unknown. Outcome: 0 empty, 1-8 processed, skipped, failed, timeout, unsupported, awaiting, notfound,
     * passworded, 15 unknown. Error class (lowercased text): 0 none, 1 timeout, 2 NNTP or article, 3 unrar, 7z or
     * archive, 4 disk, space or permission, 5 deadlock, SQLSTATE or lock wait, 6 other. Severity: 3 class 4 or 5, 2
     * failed or timed out, 1 unsupported, awaiting, not found, slow (30 s or more), malformed or unknown, else 0. Flags:
     * 1 malformed number, 2 slow, 4 error text, 8 error text on a processed or skipped outcome, 16 non-ASCII, 32 control
     * byte in the error, 64 processed in 0 ms, 128 bytes without requests. Per item `stage | outcome << 4 | severity << 8
     * | class << 10 | flags << 13 | bitLength(duration) << 21 | bitLength(bytes) << 27 | min(requests, 1023) << 33 |
     * min(errorLength, 1023) << 43 | (fnv1a32(stage.':'.outcome) & 0x3FF) << 53`; then 16 stage, 16 outcome, 4
     * severity, 7 error class and 8 flag counts, the duration sum and maximum, the byte and request sums, the distinct
     * errors and the stage of the slowest record.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DiagnosticsPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64DiagnosticsPlanBatchNative($items) : self::w64DiagnosticsPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DiagnosticsPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_DIAGNOSTICS_TRAILER) ?? self::w64DiagnosticsPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DiagnosticsPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W64_DIAGNOSTICS_TRAILER, 0);
        $errors = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawStage, $rawOutcome, $rawDuration, $rawError, $rawBytes, $rawRequests] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $stageName = strtolower(trim($rawStage));
            $outcomeName = strtolower(trim($rawOutcome));
            $stage = $stageName === '' ? 0 : (self::W64_STAGES[$stageName] ?? 15);
            $outcome = $outcomeName === '' ? 0 : (self::W64_OUTCOMES[$outcomeName] ?? 15);
            $error = trim($rawError);
            $errorLength = \strlen($error);
            $lower = strtolower($error);
            $class = 0;
            if ($lower !== '') {
                $class = 6;
                foreach (self::W64_ERROR_CLASSES as $index => $needles) {
                    foreach ($needles as $needle) {
                        if (str_contains($lower, $needle)) {
                            $class = $index + 1;
                            break 2;
                        }
                    }
                }
            }
            $duration = self::w64Uint($rawDuration, 0);
            $bytes = self::w64Uint($rawBytes, 0);
            $requests = self::w64Uint($rawRequests, 0);
            $malformed = $duration < 0 || $bytes < 0 || $requests < 0;
            $severity = match (true) {
                $class === 4 || $class === 5 => 3,
                $outcome === 3 || $outcome === 4 => 2,
                ($outcome >= 5 && $outcome <= 7) || $duration >= 30000 || $malformed || $stage === 15 || $outcome === 15 => 1,
                default => 0,
            };
            $flags = ($malformed ? 1 : 0) | ($duration >= 30000 ? 2 : 0) | ($error !== '' ? 4 : 0)
                | ($error !== '' && ($outcome === 1 || $outcome === 2) ? 8 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 16 : 0)
                | (strcspn($error, self::W64_CONTROL_BYTES) !== $errorLength ? 32 : 0) | ($outcome === 1 && $duration === 0 ? 64 : 0)
                | ($bytes > 0 && $requests === 0 ? 128 : 0);
            $tail[$stage]++;
            $tail[16 + $outcome]++;
            $tail[32 + $severity]++;
            $tail[36 + $class]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[43 + $b] += $flags >> $b & 1;
            }
            $tail[51] += max(0, min($duration, 1 << 40));
            if ($duration > $tail[52]) {
                $tail[52] = $duration;
                $tail[56] = $stage;
            }
            $tail[53] += max(0, min($bytes, 1 << 40));
            $tail[54] += max(0, min($requests, 1 << 40));
            if ($lower !== '') {
                $errors[$lower] = true;
            }
            $out[] = $stage | $outcome << 4 | $severity << 8 | $class << 10 | $flags << 13 | self::w64BitLength($duration) << 21
                | self::w64BitLength($bytes) << 27 | max(0, min($requests, 1023)) << 33 | min($errorLength, 1023) << 43
                | ((int) hexdec(hash('fnv1a32', $stageName.':'.$outcomeName)) & 0x3FF) << 53;
        }
        $tail[55] = \count($errors);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W64_STAGES = ['setup' => 1, 'download' => 2, 'archive' => 3, 'password' => 4, 'nfo' => 5, 'media' => 6, 'sample' => 7,
        'jpg' => 8, 'audio' => 9, 'par2' => 10, 'persist' => 11, 'search' => 12];

    /** @var array<string, int> */
    private const array W64_OUTCOMES = ['processed' => 1, 'skipped' => 2, 'failed' => 3, 'timeout' => 4, 'unsupported' => 5, 'awaiting' => 6,
        'notfound' => 7, 'passworded' => 8];

    /** @var list<list<string>> */
    private const array W64_ERROR_CLASSES = [['timed out', 'timeout'], ['nntp', 'article'], ['unrar', '7z', 'archive'], ['disk', 'space', 'permission'],
        ['deadlock', 'sqlstate', 'lock wait']];

    private const string W64_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const string W64_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w64Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W64_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w64BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_diagnostics_w641', self::W64_DIAGNOSTICS_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 64.1 AdditionalProcessingDiagnostics export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_DIAGNOSTICS_FFI_CDEF.)
            $written = $ffi->nntmux_w641_diagnostics_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
