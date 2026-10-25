<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for TVDB API lookups.
 */
class TvdbPipe extends AbstractApiProviderPipe
{
    public const int PIPE_KIND = 1;

    public const string FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_tv_pipe_resolve_spec(
        int32_t pipe_kind,
        int64_t *out_priority,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_pipe_is_available(
        int32_t pipe_kind,
        uint32_t has_unavailable_reason,
        const char *reason_ptr,
        size_t reason_len
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustTvProcessingPipesProviderParityTest.php.
     */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected int $priority = 20;

    /** Wave 49.3 batch default (benchmark-gated; see tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php). */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_tvdb_search_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 Rust path forced on or off, then restores the previous setting. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /**
     * Four values per TVDB search result: the id (`[series-]` plus 1-18 digits, else 0), the year
     * (a 4-digit `year`, else a 4-digit first_air_time prefix, else 0), the name match against the
     * query without a ` (19xx)`/` (20xx)` suffix, both normalized (3 equal, 2 one is the other plus
     * further tokens, 1 a shared token, 0 none or either empty) and the score (match * 100 plus
     * +20/+5/-10 for a year delta of 0/1/more when both years are known; $year is clamped to
     * [0, 9999]). Then the best index (first strictly highest score among results with an id and a
     * match, -1 when none), the candidate (id) count and the exact-match count. Missing keys read
     * as ''.
     *
     * @param  list<array<string, mixed>>  $results  tvdb_id, name, year, first_air_time
     * @return list<int>
     */
    public static function w49SearchBatch(array $results, string $query, int $year = 0): array
    {
        return self::usesW49RustFfi()
            ? self::w49SearchBatchNative($results, $query, $year)
            : self::w49SearchBatchPhp($results, $query, $year);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49SearchBatchNative(array $results, string $query, int $year = 0): array
    {
        $strings = self::w48Columns($results, ['tvdb_id' => '', 'name' => '', 'year' => '', 'first_air_time' => '']);
        $count = \count($results);

        return self::w49Invoke(
            implode("\0", array_merge([$query], ...array_values($strings))),
            pack('q', $year),
            $count,
            $count * 4 + 3,
        )
            ?? self::w49SearchBatchPhp($results, $query, $year);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49SearchBatchPhp(array $results, string $query, int $year = 0): array
    {
        $query = self::w48Normalize((string) preg_replace('/ \((19|20)\d{2}\)$/D', '', $query));
        $queryTokens = $query === '' ? [] : array_flip(explode(' ', $query));
        $year = max(0, min(9999, $year));
        $out = [];
        $best = -1;
        $bestScore = 0;
        $candidates = 0;
        $exact = 0;
        $index = 0;
        foreach ($results as $item) {
            $id = preg_match('/^(?:series-)?(\d{1,18})$/D', (string) ($item['tvdb_id'] ?? ''), $m) === 1 ? (int) $m[1] : 0;
            $rawYear = (string) ($item['year'] ?? '');
            $resultYear = match (true) {
                preg_match('/^\d{4}$/D', $rawYear) === 1 => (int) $rawYear,
                preg_match('/^\d{4}/', (string) ($item['first_air_time'] ?? ''), $m) === 1 => (int) $m[0],
                default => 0,
            };
            $name = self::w48Normalize((string) ($item['name'] ?? ''));
            $match = match (true) {
                $query === '' || $name === '' => 0,
                $name === $query => 3,
                str_starts_with($name, $query.' ') || str_starts_with($query, $name.' ') => 2,
                array_intersect_key(array_flip(explode(' ', $name)), $queryTokens) !== [] => 1,
                default => 0,
            };
            $bonus = 0;
            if ($year > 0 && $resultYear > 0) {
                $bonus = match (abs($resultYear - $year)) {
                    0 => 20,
                    1 => 5,
                    default => -10,
                };
            }
            $score = $match * 100 + $bonus;
            if ($id > 0) {
                $candidates++;
                if ($match > 0 && ($best < 0 || $score > $bestScore)) {
                    $best = $index;
                    $bestScore = $score;
                }
            }
            if ($match === 3) {
                $exact++;
            }
            array_push($out, $id, $resultYear, $match, $score);
            $index++;
        }
        array_push($out, $best, $candidates, $exact);

        return $out;
    }

    /**
     * Calls the Wave 49 export with NUL-joined row-major strings and packed `i64` integers. Null
     * when the library is missing or the call declines (for example a string holding a NUL byte),
     * so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w49Invoke(string $blob, string $ints, int $count, int $words): ?array
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w49', self::W49_FFI_CDEF) ?? false;
        $ffi = self::$w49Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w49Out === null || self::$w49OutCap < $words) {
                self::$w49OutCap = max(1024, $words * 2);
                self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_tv_tvdb_search_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function getName(): string
    {
        return self::resolvePipeSpec()['name'];
    }

    protected function siteColumn(): string
    {
        return self::resolvePipeSpec()['site_column'];
    }

    protected function createProvider(): AbstractTvProvider
    {
        return new TvdbProvider;
    }

    protected function isAvailable(): bool
    {
        $sourceKey = self::resolvePipeSpec()['source_key'];

        return self::evaluateAvailability(MetadataSources::unavailableReason($sourceKey));
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpec(): array
    {
        if (self::usesRustFfi()) {
            return self::resolvePipeSpecNative();
        }

        return self::resolvePipeSpecPhp();
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecPhp(): array
    {
        return [
            'name' => 'TVDB',
            'site_column' => 'tvdb',
            'priority' => 20,
            'source_key' => MetadataSources::TVDB,
        ];
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolvePipeSpecPhp();
        }

        try {
            $outPriority = $ffi->new('int64_t[1]');
            $outLens = $ffi->new('size_t[3]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($buf === null) {
                return self::resolvePipeSpecPhp();
            }

            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::resolvePipeSpecPhp();
                }
                $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            }
            if ($rc < 0) {
                return self::resolvePipeSpecPhp();
            }

            $nameLen = (int) $outLens[0];
            $colLen = (int) $outLens[1];
            $srcLen = (int) $outLens[2];
            $packed = $rc === 0 ? '' : FFI::string($buf, $rc);

            return [
                'name' => substr($packed, 0, $nameLen),
                'site_column' => substr($packed, $nameLen, $colLen),
                'priority' => (int) $outPriority[0],
                'source_key' => substr($packed, $nameLen + $colLen, $srcLen),
            ];
        } catch (Throwable) {
            return self::resolvePipeSpecPhp();
        }
    }

    public static function evaluateAvailability(?string $unavailableReason): bool
    {
        if (self::usesRustFfi()) {
            return self::evaluateAvailabilityNative($unavailableReason);
        }

        return self::evaluateAvailabilityPhp($unavailableReason);
    }

    public static function evaluateAvailabilityPhp(?string $unavailableReason): bool
    {
        return $unavailableReason === null;
    }

    public static function evaluateAvailabilityNative(?string $unavailableReason): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }

        try {
            $hasReason = $unavailableReason !== null ? 1 : 0;
            $str = $unavailableReason ?? '';
            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', self::PIPE_KIND, $hasReason, $str, strlen($str));
            if ($rc < 0) {
                return self::evaluateAvailabilityPhp($unavailableReason);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
