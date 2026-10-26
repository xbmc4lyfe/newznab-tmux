<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 62.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs reached 2.78x common mean, 2.48x common p99, 2.16x uncommon mean and 2.00x uncommon p99; see RustTvMazeTvdbPipeOutcomeWriterPosterResolverRunStateW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_TVMAZE_PIPE_FFI_CDEF = 'ssize_t nntmux_w624_tvmaze_lookup_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per tvMazeLookupPlanRowBatch() record. */
    public const int W62_ROW_WORDS = 6;

    /** int64 summary words after the tvMazeLookupPlanRowBatch() records (none for an empty batch). */
    public const int W62_TRAILER_WORDS = 8;

    private const string W62_DIGITS = '0123456789';

    private const string W62_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * TVMaze lookup plan rows for [title, airdate, country, videos id, tvmaze id, season, episode,
     * season pack, consecutive transients] items.
     *
     * The title is normalized as w48Normalize() does; a trailing ` 1900`-` 2099` token is cut into
     * the year when a query remains. Airdates are trimmed `YYYY-MM-DD` (or '.'/'/' separated) valid
     * dates (`YYYYMMDD`, else 0). Countries are trimmed, uppercased two-letter codes (class 0 empty,
     * 1 valid packed as `c0 << 8 | c1`, 2 otherwise). Season and episode clamp to 0..9999 and
     * 0..99999; tvmaze ids of 1..99999999 are valid. Status: 1 empty query, 2 circuit open (3+
     * transients), 3 show search, 4 season pack, 5 missing site id, 6 episode number lookup
     * (key `season * 100000 + episode`), 7 airdate lookup (key the airdate), 8 no episode reference.
     *
     * Rows [status | title class << 4 (0 empty, 1 ok, 2 over 120 bytes) | country class << 6 |
     * flags << 8 (1 year cut, 2 non-ASCII title, 4 unparsable airdate) | query bytes << 16 | year
     * << 24, FNV-1a 32 query hash (0 empty), airdate, episode key (-1), tvmaze id (-1), country],
     * then the trailer [skipped, searches, season packs, missing site ids, episode lookups, airdate
     * lookups, no reference, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function tvMazeLookupPlanRowBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::tvMazeLookupPlanRowBatchNative($items) : self::tvMazeLookupPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function tvMazeLookupPlanRowBatchPhp(array $items): array
    {
        self::w62Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$title, $airdate, $country, $videoId, $siteId, $season, $episode, $seasonPack, $transients]) {
            $query = self::w48Normalize($title);
            $length = \strlen($query);
            $year = 0;
            if ($length >= 6 && $query[$length - 5] === ' ' && strspn($query, self::W62_DIGITS, $length - 4) === 4) {
                $candidate = (int) substr($query, -4);
                if ($candidate >= 1900 && $candidate <= 2099) {
                    $year = $candidate;
                    $length -= 5;
                    $query = substr($query, 0, $length);
                }
            }
            $flags = ($year > 0 ? 1 : 0) | (mb_check_encoding($title, 'ASCII') ? 0 : 2);
            $day = trim($airdate);
            $air = self::w62Ymd($day);
            if ($day !== '' && $air === 0) {
                $flags |= 4;
            }
            $code = strtoupper(trim($country));
            if ($code === '') {
                $countryClass = $packed = 0;
            } elseif (\strlen($code) === 2 && strspn($code, self::W62_UPPER) === 2) {
                $countryClass = 1;
                $packed = \ord($code[0]) << 8 | \ord($code[1]);
            } else {
                $countryClass = 2;
                $packed = 0;
            }
            $season = max(0, min(9999, $season));
            $episode = max(0, min(99_999, $episode));
            $siteOk = $siteId >= 1 && $siteId <= 99_999_999;
            $status = match (true) {
                $length === 0 => 1,
                $transients >= 3 => 2,
                $videoId <= 0 => 3,
                $seasonPack !== 0 => 4,
                ! $siteOk => 5,
                $episode > 0 => 6,
                $air > 0 => 7,
                default => 8,
            };
            $key = match ($status) {
                6 => $season * 100_000 + $episode,
                7 => $air,
                default => -1,
            };
            $hash = $length === 0 ? 0 : self::w48Fnv($query);
            $titleClass = $length === 0 ? 0 : ($length > 120 ? 2 : 1);
            $word = $status | $titleClass << 4 | $countryClass << 6 | $flags << 8 | min(255, $length) << 16 | $year << 24;
            array_push($out, $word, $hash, $air, $key, $siteOk ? $siteId : -1, $packed);
            $summary[$status <= 2 ? 0 : $status - 2]++;
            $value = $word ^ $hash << 20 ^ $key;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function tvMazeLookupPlanRowBatchNative(array $items): array
    {
        self::w62Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w62Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w62Call($blob, $twin, $ints, $count, $count * self::W62_ROW_WORDS + self::W62_TRAILER_WORDS)
            ?? self::tvMazeLookupPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w62Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W62 TVMaze lookup plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W62 TVMaze lookup plan batch items must be [string title, string airdate, string country, int videos id, int tvmaze id, int season, int episode, int season pack, int consecutive transients] lists.');
            }
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W62 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('enabled');
        self::w62RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w62Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w62Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w62RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w62', self::W62_TVMAZE_PIPE_FFI_CDEF) ?? false;
            self::w62RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w62RustFfiState('out', $out);
                self::w62RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W62_TVMAZE_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w624_tvmaze_lookup_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w62RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W62 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** A trimmed `YYYY-MM-DD` (or '.'/'/' separated) valid date with a positive year as `YYYYMMDD`, else 0. */
    private static function w62Ymd(string $day): int
    {
        if (\strlen($day) !== 10 || strspn($day, self::W62_DIGITS, 0, 4) !== 4 || strspn($day, self::W62_DIGITS, 5, 2) !== 2
            || strspn($day, self::W62_DIGITS, 8, 2) !== 2) {
            return 0;
        }
        $separator = $day[4];
        if (($separator !== '-' && $separator !== '.' && $separator !== '/') || $day[7] !== $separator) {
            return 0;
        }
        $year = (int) substr($day, 0, 4);
        $month = (int) substr($day, 5, 2);
        $date = (int) substr($day, 8, 2);

        return $year > 0 && checkdate($month, $date, $year) ? $year * 10_000 + $month * 100 + $date : 0;
    }
}
