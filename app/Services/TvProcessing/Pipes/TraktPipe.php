<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for Trakt API lookups.
 */
class TraktPipe extends AbstractApiProviderPipe
{
    public const int PIPE_KIND = 4;

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

    protected int $priority = 50;

    /** Wave 49.3 batch default (benchmark-gated; see tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php). */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_trakt_ids_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Five values per Trakt search result: the trakt id (negative ids read as 0), the imdb number
     * from a trailing `tt` plus 6+ digits (-1 beyond 18 digits, 0 without one), the year (from a
     * `YYYY-MM-DD` first_aired prefix, else `year` clamped to [0, 9999]), flags (1 trakt, 2 tvdb,
     * 4 tmdb, 8 imdb, 16 tvrage ids present, 32 title had a ` (19xx)`/` (20xx)` suffix, 64 empty
     * normalized title) and the FNV-1a hash of the normalized title without that suffix. Then the
     * resolvable (trakt id) count, the fully linked (trakt, tvdb, tmdb and imdb) count and the
     * first resolvable index (-1 when none). Missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $results  title, first_aired, imdb, year, trakt, tvdb, tmdb, tvrage
     * @return list<int>
     */
    public static function w49IdsBatch(array $results): array
    {
        return self::usesW49RustFfi() ? self::w49IdsBatchNative($results) : self::w49IdsBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49IdsBatchNative(array $results): array
    {
        $strings = self::w48Columns($results, ['title' => '', 'first_aired' => '', 'imdb' => '']);
        $ints = self::w48Columns($results, ['year' => 0, 'trakt' => 0, 'tvdb' => 0, 'tmdb' => 0, 'tvrage' => 0]);
        $count = \count($results);

        return self::w49Invoke(
            implode("\0", array_merge(...array_values($strings))),
            pack('q*', ...array_merge(...array_values($ints))),
            $count,
            $count * 5 + 3,
        )
            ?? self::w49IdsBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49IdsBatchPhp(array $results): array
    {
        $out = [];
        $resolvable = 0;
        $linked = 0;
        $first = -1;
        $index = 0;
        foreach ($results as $item) {
            $title = (string) ($item['title'] ?? '');
            $stripped = (string) preg_replace('/ \((19|20)\d{2}\)$/D', '', $title);
            $normalized = self::w48Normalize($stripped);
            $trakt = max(0, (int) ($item['trakt'] ?? 0));
            $imdb = preg_match('/tt(\d{6,})$/iD', (string) ($item['imdb'] ?? ''), $m) === 1
                ? (\strlen($m[1]) <= 18 ? (int) $m[1] : -1)
                : 0;
            $year = preg_match('/^(\d{4})-\d{2}-\d{2}/', (string) ($item['first_aired'] ?? ''), $m) === 1
                ? (int) $m[1]
                : max(0, min(9999, (int) ($item['year'] ?? 0)));
            $flags = ($trakt > 0 ? 1 : 0)
                | ((int) ($item['tvdb'] ?? 0) > 0 ? 2 : 0)
                | ((int) ($item['tmdb'] ?? 0) > 0 ? 4 : 0)
                | ($imdb > 0 ? 8 : 0)
                | ((int) ($item['tvrage'] ?? 0) > 0 ? 16 : 0)
                | ($stripped !== $title ? 32 : 0)
                | ($normalized === '' ? 64 : 0);
            if ($trakt > 0) {
                $resolvable++;
                if ($first < 0) {
                    $first = $index;
                }
            }
            if (($flags & 15) === 15) {
                $linked++;
            }
            array_push($out, $trakt, $imdb, $year, $flags, self::w48Fnv($normalized));
            $index++;
        }
        array_push($out, $resolvable, $linked, $first);

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
            $written = $ffi->nntmux_w49_tv_trakt_ids_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w49Out, self::$w49OutCap);
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
        return new TraktProvider;
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
            'name' => 'Trakt',
            'site_column' => 'trakt',
            'priority' => 50,
            'source_key' => MetadataSources::TRAKT,
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
