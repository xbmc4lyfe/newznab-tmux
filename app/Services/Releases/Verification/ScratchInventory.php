<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate and audit scratch are registered under the worker lock before creation.
 * Unregistered external directories are never discovered or deleted here, and each worker
 * host only measures, cleans or forgets the entries it created.
 */
final class ScratchInventory
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w16_scratch(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Candidate helpers retain PHP defaults until every paired mean/p99 gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialize() can invoke user code; bound cycles and nesting. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w16_scratch($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function scratchName(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): string
    {
        return self::scratchNameNative($auditReleaseId, $candidateId, $version, $id) ?? self::scratchNamePhp($auditReleaseId, $candidateId, $version, $id);
    }

    public static function scratchNamePhp(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): string
    {
        return $auditReleaseId === null ? $candidateId.'-'.$version : 'audit-'.$auditReleaseId.'-'.$id;
    }

    public static function scratchNameNative(int|string|null $auditReleaseId, int|string $candidateId, int|string $version, int|string $id): ?string
    {
        $result = self::computeNative(0, [$auditReleaseId, $candidateId, $version, $id]);

        return is_string($result) ? $result : null;
    }

    public static function filenameClass(string $filename): string
    {
        return self::filenameClassNative($filename) ?? self::filenameClassPhp($filename);
    }

    public static function filenameClassPhp(string $filename): string
    {
        return preg_match('/^audit-[0-9]+-[0-9]+$/D', $filename) === 1 ? 'audit' : (preg_match('/^[0-9]+-[0-9]+$/D', $filename) === 1 ? 'candidate' : 'unknown');
    }

    public static function filenameClassNative(string $filename): ?string
    {
        $result = self::computeNative(1, [$filename]);

        return is_string($result) ? $result : null;
    }

    public static function expired(int $modified, int $now, int $maxAge): bool
    {
        return self::expiredNative($modified, $now, $maxAge) ?? self::expiredPhp($modified, $now, $maxAge);
    }

    public static function expiredPhp(int $modified, int $now, int $maxAge): bool
    {
        return $now - $modified > max(0, $maxAge);
    }

    public static function expiredNative(int $modified, int $now, int $maxAge): ?bool
    {
        $result = self::computeNative(2, [$modified, $now, $maxAge]);

        return is_bool($result) ? $result : null;
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}
     */
    public static function summary(array $entries): array
    {
        return self::summaryNative($entries) ?? self::summaryPhp($entries);
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}
     */
    public static function summaryPhp(array $entries): array
    {
        $summary = ['total' => count($entries), 'candidate' => 0, 'audit' => 0, 'terminal' => 0];
        foreach ($entries as $entry) {
            $summary[$entry['audit_release_id'] === null ? 'candidate' : 'audit']++;
            $summary['terminal'] += (int) $entry['terminal'];
        }

        return $summary;
    }

    /**
     * @param  list<array{audit_release_id: ?int, terminal: bool}>  $entries
     * @return array{total: int, candidate: int, audit: int, terminal: int}|null
     */
    public static function summaryNative(array $entries): ?array
    {
        $result = self::computeNative(3, [$entries]);

        return is_array($result) ? $result : null;
    }

    /** The worker host owning scratch created here; the primary host is ''. */
    public static function host(): string
    {
        return (string) config('release_verification.host', '');
    }

    public function register(int $candidateId, int $version): void
    {
        DB::table('release_verification_scratch')->insertOrIgnore(['host' => self::host(), 'candidate_id' => $candidateId, 'version' => $version]);
    }

    /** This host's inventory entry for a candidate version. */
    public function entryId(int $candidateId, int $version): int
    {
        return (int) DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)
            ->where('version', $version)->value('id');
    }

    public function registerAudit(int $releaseId): \stdClass
    {
        $active = DB::table('release_verification_scratch')->where('host', self::host())->where('audit_release_id', $releaseId)
            ->where('terminal', false)->first();
        if ($active !== null) {
            return $active;
        }
        $id = DB::table('release_verification_scratch')->insertGetId(['host' => self::host(), 'audit_release_id' => $releaseId]);

        return DB::table('release_verification_scratch')->where('id', $id)->sole();
    }

    public function finishAudit(int $id): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update(['terminal' => true]);
    }

    public function recordAudit(int $id, string $verdict, string $reason): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update([
            'audit_verdict' => $verdict, 'audit_reason' => mb_strcut($reason, 0, 2000, 'UTF-8'),
        ]);
    }

    public function directoryName(object $entry): string
    {
        return $entry->audit_release_id === null
            ? self::scratchName(null, $entry->candidate_id, $entry->version, 0)
            : self::scratchName($entry->audit_release_id, 0, 0, $entry->id);
    }

    /** @return Collection<int, \stdClass> */
    public function olderVersions(int $candidateId, int $version): Collection
    {
        return DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)->where('version', '<', $version)
            ->orderBy('version')->limit(100)->get();
    }

    public function forget(int $id): void
    {
        if (Schema::hasTable('release_verification_scratch_budget')) {
            app(ScratchBudget::class)->forget($id);
        } else {
            DB::table('release_verification_scratch')->where('id', $id)->delete();
        }
    }

    public const string W58_FFI_CDEF = 'int64_t nntmux_w585_inventory_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W58.5 parity test's QUALIFICATION). */
    private const bool W58_RUST_FFI_DEFAULT = true;

    /**
     * Wave 58.5 routing and FFI cache, kept in function-local statics (readonly verification classes cannot declare
     * static properties, so every W58 class follows the same shape). Without $op it reports the routing; 'set' swaps
     * the override for $val and returns the previous one; 'reset' replaces the cached handle with $val (null
     * re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room for at least $val words,
     * or null without the library.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w58_5', self::W58_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W58_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        return self::w58RustFfiState() === true;
    }

    /**
     * Runs $callback with inventoryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 58.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w58Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w58RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w585_inventory_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch inventory rollup over newline-separated lines: a `now<TAB>max_age` header, then
     * `filename<TAB>modified<TAB>bytes` entries (empty lines skipped). Six words per record: `[candidate, audit,
     * unknown, expired, bytes, flags]`. Names classify as filenameClassPhp(), entries expire as expiredPhp() decides
     * and bytes saturate at PHP_INT_MAX. Flags: 1 header not two 1-18 digit fields (unparsed values read 0), 2
     * malformed entry (not three fields or modified/bytes not 1-18 digits; skipped), 4 expired unknown (orphan) entry.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inventoryPlanBatch(array $records): array
    {
        return self::usesW58RustFfi() ? self::inventoryPlanBatchNative($records) : self::inventoryPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inventoryPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $lines = explode("\n", $record);
            $header = explode("\t", $lines[0]);
            $now = $header[0];
            $maxAge = $header[1] ?? '';
            $nowValid = \strlen($now) <= 18 && ctype_digit($now);
            $maxAgeValid = \strlen($maxAge) <= 18 && ctype_digit($maxAge);
            $flags = $nowValid && $maxAgeValid && \count($header) === 2 ? 0 : 1;
            $now = $nowValid ? (int) $now : 0;
            $maxAge = $maxAgeValid ? (int) $maxAge : 0;
            $totals = ['candidate' => 0, 'audit' => 0, 'unknown' => 0];
            $expired = $bytes = 0;
            for ($i = 1, $n = \count($lines); $i < $n; $i++) {
                if ($lines[$i] === '') {
                    continue;
                }
                $parts = explode("\t", $lines[$i]);
                if (\count($parts) !== 3 || \strlen($parts[1]) > 18 || ! ctype_digit($parts[1])
                    || \strlen($parts[2]) > 18 || ! ctype_digit($parts[2])) {
                    $flags |= 2;

                    continue;
                }
                $class = self::filenameClassPhp($parts[0]);
                $totals[$class]++;
                if (self::expiredPhp((int) $parts[1], $now, $maxAge)) {
                    $expired++;
                    if ($class === 'unknown') {
                        $flags |= 4;
                    }
                }
                $size = (int) $parts[2];
                $bytes = $bytes > PHP_INT_MAX - $size ? PHP_INT_MAX : $bytes + $size;
            }
            array_push($out, $totals['candidate'], $totals['audit'], $totals['unknown'], $expired, $bytes, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inventoryPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w58Words($count, implode("\0", $records), 6 * $count) ?? self::inventoryPlanBatchPhp($records);
    }
}
