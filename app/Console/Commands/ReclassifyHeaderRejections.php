<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Candidates recorded before header_rejected existed keep retrying as unknown. Move those whose
 * current reason is a header defect out of the claim queue, in short primary-key ranges, skipping
 * any a worker holds and any newly admitted version still awaiting its first check.
 */
class ReclassifyHeaderRejections extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w30_nzb_release_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    protected $signature = 'releases:reclassify-header-rejections {--chunk=5000 : Candidate ids per update} {--after=0 : Resume after this candidate id} {--max-seconds=0 : Stop after this duration; zero runs to the end}';

    protected $description = 'Stop retrying unknown verification candidates whose headers cannot pass';

    /**
     * @return array{chunk: int, after: int, through: int}
     */
    public static function normalizeChunkWindow(int $chunk, int $after): array
    {
        return self::$useRustFfi ? self::normalizeChunkWindowNative($chunk, $after) : self::normalizeChunkWindowPhp($chunk, $after);
    }

    /**
     * @return array{chunk: int, after: int, through: int}
     */
    public static function normalizeChunkWindowPhp(int $chunk, int $after): array
    {
        return ['chunk' => max(1, $chunk), 'after' => max(0, $after), 'through' => max(0, $after) + max(1, $chunk)];
    }

    /**
     * @return array{chunk: int, after: int, through: int}
     */
    public static function normalizeChunkWindowNative(int $chunk, int $after): array
    {
        if (self::callNative(['reclassify.normalize_chunk_window', $chunk, $after], $result)
            && is_array($result) && array_keys($result) === ['chunk', 'after', 'through']
            && is_int($result['chunk']) && is_int($result['after']) && is_int($result['through'])) {
            return ['chunk' => $result['chunk'], 'after' => $result['after'], 'through' => $result['through']];
        }

        return self::normalizeChunkWindowPhp($chunk, $after);
    }

    public static function hasExceededMaxSeconds(int $maxSeconds, float|int $elapsedSeconds): bool
    {
        return self::$useRustFfi
            ? self::hasExceededMaxSecondsNative($maxSeconds, $elapsedSeconds)
            : self::hasExceededMaxSecondsPhp($maxSeconds, $elapsedSeconds);
    }

    public static function hasExceededMaxSecondsPhp(int $maxSeconds, float|int $elapsedSeconds): bool
    {
        return $maxSeconds > 0 && $elapsedSeconds >= $maxSeconds;
    }

    public static function hasExceededMaxSecondsNative(int $maxSeconds, float|int $elapsedSeconds): bool
    {
        if (self::callNative(['reclassify.has_exceeded_max_seconds', $maxSeconds, $elapsedSeconds], $result) && is_bool($result)) {
            return $result;
        }

        return self::hasExceededMaxSecondsPhp($maxSeconds, $elapsedSeconds);
    }

    public static function formatSummaryLine(int $scanned, int $reclassified, int $deleted, int $lastId): string
    {
        return self::$useRustFfi
            ? self::formatSummaryLineNative($scanned, $reclassified, $deleted, $lastId)
            : self::formatSummaryLinePhp($scanned, $reclassified, $deleted, $lastId);
    }

    public static function formatSummaryLinePhp(int $scanned, int $reclassified, int $deleted, int $lastId): string
    {
        return "Reclassified {$reclassified} candidates with header defects (scanned {$scanned} ids through {$lastId}, deleted {$deleted}).";
    }

    public static function formatSummaryLineNative(int $scanned, int $reclassified, int $deleted, int $lastId): string
    {
        if (self::callNative(['reclassify.format_summary_line', $scanned, $reclassified, $deleted, $lastId], $result) && is_string($result)) {
            return $result;
        }

        return self::formatSummaryLinePhp($scanned, $reclassified, $deleted, $lastId);
    }

    public function handle(): int
    {
        $window = self::normalizeChunkWindow((int) $this->option('chunk'), (int) $this->option('after'));
        $chunk = $window['chunk'];
        $start = $window['after'];
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $started = microtime(true);
        $ceiling = (int) DB::table('release_verification_candidates')->max('id');
        $changed = 0;
        $lastId = $start;
        for ($after = $start; $after < $ceiling; $after += $chunk) {
            if (self::hasExceededMaxSeconds($maxSeconds, microtime(true) - $started)) {
                $this->warn('Stopped after '.$maxSeconds.' seconds; continue with --after='.$after.'.');
                break;
            }
            $range = self::normalizeChunkWindow($chunk, $after);
            $changed += $this->reclassify($range['after'], $range['through']);
            $lastId = min($range['through'], $ceiling);
        }
        $this->info(self::formatSummaryLine(max(0, $lastId - $start), $changed, 0, $lastId));

        return self::SUCCESS;
    }

    /** Ingestion and workers may hold candidates in another order; take only available rows. */
    private function reclassify(int $after, int $through): int
    {
        $query = DB::table('release_verification_candidates')->where('id', '>', $after)->where('id', '<=', $through)
            ->where('status', 'unknown')->whereNull('release_id')->whereNull('lease_token')->where('header_check_pending', false)
            ->whereIn('reason', CandidateHeaderInventory::REJECTION_REASONS);
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->update(['status' => CandidateHeaderInventory::REJECTED]);
        }

        return DB::transaction(static function () use ($query): int {
            // Both the locking read and the update stay on the primary key, inside this id range.
            $ids = (clone $query)->forceIndex('PRIMARY')->lock('FOR UPDATE SKIP LOCKED')->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }
            // UPDATE ignores SELECT index hints; bound its scan to the rows just locked, never a
            // status-index range that could reach rows the read skipped.
            $connection = DB::connection();
            $table = DB::raw($connection->getQueryGrammar()->wrapTable('release_verification_candidates').' FORCE INDEX (PRIMARY)');

            return $connection->table($table)->whereIn('id', $ids)->where('status', 'unknown')->whereNull('release_id')
                ->whereNull('lease_token')->where('header_check_pending', false)->update(['status' => CandidateHeaderInventory::REJECTED]);
        }, 3);
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w30_nzb_release_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
