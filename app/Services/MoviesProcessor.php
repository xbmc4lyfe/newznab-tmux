<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

class MoviesProcessor
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_media_catalogue_processor_plan(
            uint32_t processor,
            uint32_t override_kind,
            int64_t override_int,
            const char *override_ptr,
            size_t override_len,
            uint32_t has_default,
            int64_t default_lookup,
            uint32_t echo_output,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustMediaCatalogueProcessorsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int NEED_DEFAULT = -2;

    private const string FFI_SYMBOL = 'nntmux_w34_media_catalogue_processor_plan';

    private const int RUST_PROCESSOR = 4;

    private const string LABEL = 'Movies';

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private bool $echooutput;

    public function __construct(bool $echooutput)
    {
        $this->echooutput = $echooutput;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * @param  int|string|null  $processMovies  0/1/2 or '' to read from settings
     *
     * @throws GuzzleException
     */
    public function process(string $groupID = '', string $guidChar = '', int|string|null $processMovies = ''): void
    {
        $plan = self::dispatchPlan(
            $processMovies,
            static fn (): int => (int) app(ConfigurationProvider::class)->metadata()->movieLookup->value,
            $this->echooutput,
        );
        if ($plan['dispatch']) {
            (new MovieService)->processMovieReleases($groupID, $guidChar, $plan['mode']);
        }
    }

    /**
     * Resolves the movie lookup mode (a numeric `$processMovies` overrides the setting), the
     * dispatch gate, echo configuration and dispatch summary. The default is only read when
     * the override is not numeric.
     *
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlan(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        return self::usesRustFfi()
            ? self::dispatchPlanNative($processMovies, $defaultLookup, $echoOutput)
            : self::dispatchPlanPhp($processMovies, $defaultLookup, $echoOutput);
    }

    /**
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanNative(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        $kind = match (true) {
            $processMovies === null => 0,
            \is_int($processMovies) => 1,
            default => 2,
        };
        $intValue = \is_int($processMovies) ? $processMovies : 0;
        $text = \is_string($processMovies) ? $processMovies : null;
        $length = $text === null ? 0 : strlen($text);
        $default = \is_int($defaultLookup) ? $defaultLookup : null;

        $status = 0;
        $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, $kind, $intValue, $text, $length, $default === null ? 0 : 1, $default ?? 0, (int) $echoOutput], $status);
        if ($fields === null && $status === self::NEED_DEFAULT && $defaultLookup instanceof Closure) {
            $default = $defaultLookup();
            $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, $kind, $intValue, $text, $length, 1, $default, (int) $echoOutput], $status);
        }

        return ($fields === null ? null : self::decodePlan($fields))
            ?? self::dispatchPlanPhp($processMovies, $default ?? $defaultLookup, $echoOutput);
    }

    /**
     * @param  int|Closure(): int  $defaultLookup
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanPhp(int|string|null $processMovies, int|Closure $defaultLookup, bool $echoOutput): array
    {
        $fromOverride = is_numeric($processMovies);
        $mode = $fromOverride
            ? (int) $processMovies
            : ($defaultLookup instanceof Closure ? $defaultLookup() : $defaultLookup);
        $dispatch = $mode > 0;
        $source = $fromOverride ? 'override' : 'setting';

        return [
            'dispatch' => $dispatch,
            'mode' => $mode,
            'echo' => $echoOutput,
            'source' => $source,
            'summary' => sprintf(
                '%s lookup: mode=%d source=%s dispatch=%s echo=%s',
                self::LABEL,
                $mode,
                $source,
                $dispatch ? 'yes' : 'no',
                $echoOutput ? 'on' : 'off',
            ),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}|null
     */
    private static function decodePlan(array $fields): ?array
    {
        if (count($fields) !== 5
            || ! in_array($fields[0], ['0', '1'], true)
            || ! in_array($fields[2], ['0', '1'], true)
            || ! in_array($fields[3], ['setting', 'override'], true)
            || (string) (int) $fields[1] !== $fields[1]) {
            return null;
        }

        return [
            'dispatch' => $fields[0] === '1',
            'mode' => (int) $fields[1],
            'echo' => $fields[2] === '1',
            'source' => $fields[3],
            'summary' => $fields[4],
        ];
    }

    /**
     * @param  non-empty-string  $symbol
     * @param  list<int|string|null>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int &$status = 0): ?array
    {
        $status = 0;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -2) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                $status = is_int($written) ? $written : 0;

                return null;
            }

            return explode("\x1F", FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** Enabled: paired common and uncommon mean/p99 gains all reach 1.5x (see the W41 parity test). */
    public const bool W414_RUST_FFI_DEFAULT = true;

    public const string W414_FFI_CDEF = <<<'C'
        ssize_t nntmux_w414_movie_release_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W414_SUMMARY_KEYS = ['total_releases', 'empty_title_count', 'resolution_tagged_count', 'source_tagged_count', 'codec_tagged_count', 'hdr_tagged_count', 'year_tagged_count', 'complete_tag_count'];

    private const array W414_RESOLUTION_TOKENS = ['480P' => true, '576P' => true, '720P' => true, '1080P' => true, '1080I' => true, '2160P' => true, '4K' => true, 'UHD' => true];

    private const array W414_SOURCE_TOKENS = ['BLURAY' => true, 'BDRIP' => true, 'BRRIP' => true, 'WEBDL' => true, 'WEBRIP' => true, 'WEB' => true, 'HDTV' => true, 'DVDRIP' => true, 'DVD' => true, 'REMUX' => true, 'HDRIP' => true];

    private const array W414_CODEC_TOKENS = ['X264' => true, 'X265' => true, 'H264' => true, 'H265' => true, 'HEVC' => true, 'AVC' => true, 'XVID' => true, 'DIVX' => true, 'AV1' => true, 'VP9' => true];

    private const array W414_HDR_TOKENS = ['HDR' => true, 'HDR10' => true, 'HDR10PLUS' => true, 'DV' => true, 'DOVI' => true, 'DOLBYVISION' => true, 'HLG' => true];

    private static ?bool $w414UseRustFfi = null;

    private static FFI|false|null $w414Ffi = null;

    public static function useW414RustFfi(bool $enable = true): void
    {
        self::$w414UseRustFfi = $enable;
    }

    public static function usesW414RustFfi(): bool
    {
        return self::$w414UseRustFfi ?? self::W414_RUST_FFI_DEFAULT;
    }

    public static function resetW414RustFfi(): void
    {
        self::$w414UseRustFfi = null;
        self::$w414Ffi = null;
    }

    /**
     * Summarize movie release titles by resolution, source, codec, HDR and
     * 19xx/20xx year tokens; complete tags have all but HDR.
     * Titles come from strings, integers, or the searchname, name or title
     * array field; other values (including objects) count as empty titles.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeMovieReleaseBatch(array $releases): array
    {
        return self::usesW414RustFfi()
            ? self::summarizeMovieReleaseBatchNative($releases)
            : self::summarizeMovieReleaseBatchPhp($releases);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeMovieReleaseBatchNative(array $releases): array
    {
        return self::w414Call(self::w414Titles($releases)) ?? self::summarizeMovieReleaseBatchPhp($releases);
    }

    /**
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeMovieReleaseBatchPhp(array $releases): array
    {
        $summary = array_fill_keys(self::W414_SUMMARY_KEYS, 0);
        foreach (self::w414Titles($releases) as $title) {
            $summary['total_releases']++;
            $title = trim($title);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $found = [false, false, false, false, false];
            foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $found[0] = $found[0] || isset(self::W414_RESOLUTION_TOKENS[$token]);
                $found[1] = $found[1] || isset(self::W414_SOURCE_TOKENS[$token]);
                $found[2] = $found[2] || isset(self::W414_CODEC_TOKENS[$token]);
                $found[3] = $found[3] || isset(self::W414_HDR_TOKENS[$token]);
                $found[4] = $found[4] || (strlen($token) === 4 && strspn($token, '0123456789') === 4 && (str_starts_with($token, '19') || str_starts_with($token, '20')));
            }
            $summary['resolution_tagged_count'] += (int) $found[0];
            $summary['source_tagged_count'] += (int) $found[1];
            $summary['codec_tagged_count'] += (int) $found[2];
            $summary['hdr_tagged_count'] += (int) $found[3];
            $summary['year_tagged_count'] += (int) $found[4];
            $summary['complete_tag_count'] += (int) ($found[0] && $found[1] && $found[2] && $found[4]);
        }

        return $summary;
    }

    /** Only integer and string titles participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w414Titles(array $input): array
    {
        $titles = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['searchname'] ?? $item['name'] ?? $item['title'] ?? null;
            }
            $titles[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $titles;
    }

    /** @param list<string> $titles
     * @return array<string, int>|null
     */
    private static function w414Call(array $titles): ?array
    {
        try {
            $input = serialize([$titles]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w414Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w414', self::W414_FFI_CDEF) ?? false;
            $ffi = self::$w414Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
            $written = $ffi->nntmux_w414_movie_release_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
                $written = $ffi->nntmux_w414_movie_release_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W414_SUMMARY_KEYS || $result['total_releases'] !== count($titles)) {
                return null;
            }
            $summary = [];
            foreach (self::W414_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($titles)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 55.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 6.36x, p99 3.87x; uncommon mean 7.05x, p99 5.47x); see RustMoviesMusicProcessorMusicPostProcessTvMazeW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        ssize_t nntmux_w554_movie_title_tag_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * summarizeMovieReleaseBatchPhp() per title instead of as totals. Each title yields four
     * words: flags (resolution 1, source 2, codec 4, HDR 8, 19xx/20xx year 16, complete
     * 32 = all but HDR, empty after trim() 64), the ASCII alphanumeric token count, the first
     * year token (-1) and the trimmed span (leading offset << 32 | trimmed length).
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function movieTitleTagRowBatch(array $titles): array
    {
        return self::usesW55RustFfi()
            ? self::movieTitleTagRowBatchNative($titles)
            : self::movieTitleTagRowBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function movieTitleTagRowBatchNative(array $titles): array
    {
        self::w55AssertColumns($titles);
        $count = \count($titles);
        if ($count === 0) {
            return [];
        }

        return self::w55Call(implode("\0", $titles), '', $count, 4)
            ?? self::movieTitleTagRowBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function movieTitleTagRowBatchPhp(array $titles): array
    {
        self::w55AssertColumns($titles);
        $out = [];
        foreach ($titles as $raw) {
            $title = trim($raw);
            $lead = \strlen($raw) - \strlen(ltrim($raw));
            if ($title === '') {
                array_push($out, 64, 0, -1, $lead << 32);

                continue;
            }
            $flags = 0;
            $year = -1;
            $tokens = preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                $flags |= isset(self::W414_RESOLUTION_TOKENS[$token]) ? 1 : 0;
                $flags |= isset(self::W414_SOURCE_TOKENS[$token]) ? 2 : 0;
                $flags |= isset(self::W414_CODEC_TOKENS[$token]) ? 4 : 0;
                $flags |= isset(self::W414_HDR_TOKENS[$token]) ? 8 : 0;
                if (\strlen($token) === 4 && strspn($token, '0123456789') === 4 && (str_starts_with($token, '19') || str_starts_with($token, '20'))) {
                    $flags |= 16;
                    if ($year < 0) {
                        $year = (int) $token;
                    }
                }
            }
            if (($flags & 23) === 23) {
                $flags |= 32;
            }
            array_push($out, $flags, \count($tokens), $year, ($lead << 32) | \strlen($title));
        }

        return $out;
    }

    /**
     * Equal-length list columns, or InvalidArgumentException.
     *
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w55AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (! array_is_list($column) || \count($column) !== $count) {
                throw new \InvalidArgumentException('W55 batch columns must be lists of equal length.');
            }
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W55 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('enabled');
        self::w55RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 header in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w55Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w55RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w55', self::W55_FFI_CDEF) ?? false;
                self::w55RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w55RustFfiState('out', $out);
                self::w55RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w554_movie_title_tag_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W55 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
