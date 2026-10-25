<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for TVMaze API lookups.
 */
class TvMazePipe extends AbstractApiProviderPipe
{
    public const int PIPE_KIND = 2;

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

    protected int $priority = 30;

    /** Wave 49.3 batch default (benchmark-gated; see tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php). */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_tvmaze_episode_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Five values per TVMaze episode: the series and episode (negatives read as 0), the airdate as
     * `YYYYMMDD` (a trimmed, valid `YYYY-MM-DD` with a non-zero year, else 0), the kind (0 dropped:
     * no episode number and no airdate; 1 new numbered episode; 2 new special keyed by airdate;
     * 3 duplicate key) and the type (0 regular or empty, 1 significant_special,
     * 2 insignificant_special, 3 other). Then the numbered, special, dropped and duplicate counts
     * and the max series. Missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $episodes  airdate, type, season, number
     * @return list<int>
     */
    public static function w49EpisodeBatch(array $episodes): array
    {
        return self::usesW49RustFfi() ? self::w49EpisodeBatchNative($episodes) : self::w49EpisodeBatchPhp($episodes);
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return list<int>
     */
    public static function w49EpisodeBatchNative(array $episodes): array
    {
        $strings = self::w48Columns($episodes, ['airdate' => '', 'type' => '']);
        $ints = self::w48Columns($episodes, ['season' => 0, 'number' => 0]);
        $count = \count($episodes);

        return self::w49Invoke(
            implode("\0", array_merge($strings['airdate'], $strings['type'])),
            pack('q*', ...$ints['season'], ...$ints['number']),
            $count,
            $count * 5 + 5,
        )
            ?? self::w49EpisodeBatchPhp($episodes);
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return list<int>
     */
    public static function w49EpisodeBatchPhp(array $episodes): array
    {
        $out = [];
        $seen = [];
        $numbered = 0;
        $specials = 0;
        $dropped = 0;
        $duplicates = 0;
        $maxSeries = 0;
        foreach ($episodes as $item) {
            $series = max(0, (int) ($item['season'] ?? 0));
            $number = max(0, (int) ($item['number'] ?? 0));
            $airdate = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', trim((string) ($item['airdate'] ?? '')), $m) === 1
                && (int) $m[1] > 0 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $airdate = (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3];
            }
            if ($number === 0 && $airdate === 0) {
                $kind = 0;
                $dropped++;
            } else {
                $key = $number > 0 ? $series.'x'.$number : '@'.$airdate;
                if (isset($seen[$key])) {
                    $kind = 3;
                    $duplicates++;
                } else {
                    $seen[$key] = true;
                    $kind = $number > 0 ? 1 : 2;
                    $number > 0 ? $numbered++ : $specials++;
                }
            }
            $type = match ((string) ($item['type'] ?? '')) {
                'regular', '' => 0,
                'significant_special' => 1,
                'insignificant_special' => 2,
                default => 3,
            };
            $maxSeries = max($maxSeries, $series);
            array_push($out, $series, $number, $airdate, $kind, $type);
        }
        array_push($out, $numbered, $specials, $dropped, $duplicates, $maxSeries);

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
            $written = $ffi->nntmux_w49_tv_tvmaze_episode_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w49Out, self::$w49OutCap);
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
        return new TvMazeProvider;
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
            'name' => 'TVMaze',
            'site_column' => 'tvmaze',
            'priority' => 30,
            'source_key' => '',
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

    public static function evaluateAvailability(?string $unavailableReason = null): bool
    {
        if (self::usesRustFfi()) {
            return self::evaluateAvailabilityNative($unavailableReason);
        }

        return self::evaluateAvailabilityPhp($unavailableReason);
    }

    public static function evaluateAvailabilityPhp(?string $unavailableReason = null): bool
    {
        return $unavailableReason === null;
    }

    public static function evaluateAvailabilityNative(?string $unavailableReason = null): bool
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
