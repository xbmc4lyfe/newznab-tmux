<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class MusicProcessor
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

    private const string FFI_SYMBOL = 'nntmux_w34_media_catalogue_processor_plan';

    private const int RUST_PROCESSOR = 5;

    private const string LABEL = 'Music';

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

    public function process(string $groupID = '', string $guidChar = ''): void
    {
        $plan = self::dispatchPlan((int) app(ConfigurationProvider::class)->metadata()->musicLookup->value, $this->echooutput);
        if ($plan['dispatch']) {
            (new MusicService)->processMusicReleases(false, $groupID, $guidChar);
        }
    }

    /**
     * Resolves the music lookup gate, echo configuration and dispatch summary.
     *
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlan(int $lookupValue, bool $echoOutput): array
    {
        return self::usesRustFfi()
            ? self::dispatchPlanNative($lookupValue, $echoOutput)
            : self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanNative(int $lookupValue, bool $echoOutput): array
    {
        $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, 0, 0, null, 0, 1, $lookupValue, (int) $echoOutput]);

        return ($fields === null ? null : self::decodePlan($fields))
            ?? self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanPhp(int $lookupValue, bool $echoOutput): array
    {
        $dispatch = $lookupValue !== 0;

        return [
            'dispatch' => $dispatch,
            'mode' => $lookupValue,
            'echo' => $echoOutput,
            'source' => 'setting',
            'summary' => sprintf(
                '%s lookup: mode=%d source=setting dispatch=%s echo=%s',
                self::LABEL,
                $lookupValue,
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
            || $fields[3] !== 'setting'
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
    private static function callNative(string $symbol, array $args): ?array
    {
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

    /** Enabled: paired common and uncommon mean/p99 gains all reach 1.5x in five runs (see the W42 parity test). */
    public const bool W424_RUST_FFI_DEFAULT = true;

    public const string W424_FFI_CDEF = <<<'C'
        ssize_t nntmux_w424_music_release_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W424_SUMMARY_KEYS = ['total_releases', 'empty_title_count', 'lossless_count', 'lossy_count', 'bitrate_tagged_count', 'source_tagged_count', 'compilation_count', 'year_tagged_count'];

    private const array W424_MUSIC_TOKENS = [
        'FLAC' => 1, 'ALAC' => 1, 'WAV' => 1, 'APE' => 1, 'WV' => 1, 'DSD' => 1, 'DSF' => 1, 'AIFF' => 1,
        'MP3' => 2, 'AAC' => 2, 'OGG' => 2, 'OPUS' => 2, 'M4A' => 2, 'WMA' => 2,
        'CBR' => 4, 'VBR' => 4,
        'CD' => 8, 'CDDA' => 8, 'CDM' => 8, 'CDS' => 8, 'CDR' => 8, 'WEB' => 8, 'VINYL' => 8, 'LP' => 8, 'VLS' => 8, 'SACD' => 8, 'DVDA' => 8, 'TAPE' => 8,
        'VA' => 16, 'OST' => 16, 'SOUNDTRACK' => 16, 'DISCOGRAPHY' => 16, 'COMPILATION' => 16, 'ANTHOLOGY' => 16,
    ];

    private static ?bool $w424UseRustFfi = null;

    private static FFI|false|null $w424Ffi = null;

    public static function useW424RustFfi(bool $enable = true): void
    {
        self::$w424UseRustFfi = $enable;
    }

    public static function usesW424RustFfi(): bool
    {
        return self::$w424UseRustFfi ?? self::W424_RUST_FFI_DEFAULT;
    }

    public static function resetW424RustFfi(): void
    {
        self::$w424UseRustFfi = null;
        self::$w424Ffi = null;
    }

    /**
     * Summarize music release titles: lossless and lossy format tokens,
     * bitrate tokens (CBR, VBR, V0-V2, 2-3 digit K or KBPS), media source
     * tokens, compilation tokens and 19xx/20xx year tokens. Tokens are
     * maximal ASCII alphanumeric runs compared case-insensitively.
     * Entries come from strings, integers, or the searchname, name or title array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeMusicReleaseBatch(array $entries): array
    {
        return self::usesW424RustFfi()
            ? self::summarizeMusicReleaseBatchNative($entries)
            : self::summarizeMusicReleaseBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeMusicReleaseBatchNative(array $entries): array
    {
        return self::w424Call(self::w424Entries($entries)) ?? self::summarizeMusicReleaseBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeMusicReleaseBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W424_SUMMARY_KEYS, 0);
        foreach (self::w424Entries($entries) as $entry) {
            $summary['total_releases']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $flags = 0;
            foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($entry), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $flags |= self::W424_MUSIC_TOKENS[$token] ?? 0;
                $flags |= preg_match('/^(?:\d{2,3}K(?:BPS)?|V[0-2])\z/', $token) === 1 ? 4 : 0;
                $flags |= preg_match('/^(?:19|20)\d{2}\z/', $token) === 1 ? 32 : 0;
            }
            $summary['lossless_count'] += $flags & 1;
            $summary['lossy_count'] += ($flags >> 1) & 1;
            $summary['bitrate_tagged_count'] += ($flags >> 2) & 1;
            $summary['source_tagged_count'] += ($flags >> 3) & 1;
            $summary['compilation_count'] += ($flags >> 4) & 1;
            $summary['year_tagged_count'] += ($flags >> 5) & 1;
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w424Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['searchname'] ?? $item['name'] ?? $item['title'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w424Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w424Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w424', self::W424_FFI_CDEF) ?? false;
            $ffi = self::$w424Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
            $written = $ffi->nntmux_w424_music_release_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
                $written = $ffi->nntmux_w424_music_release_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W424_SUMMARY_KEYS || $result['total_releases'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W424_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
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

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 7.33x, p99 4.39x; uncommon mean 8.29x, p99 5.88x); see RustMoviesMusicProcessorMusicPostProcessTvMazeW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        ssize_t nntmux_w554_music_title_tag_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * summarizeMusicReleaseBatchPhp() per title instead of as totals. Each title yields four
     * words: flags (lossless 1, lossy 2, bitrate 4, source 8, compilation 16, 19xx/20xx year
     * 32, empty after trim() 64), the ASCII alphanumeric token count, the first year token
     * (-1) and the trimmed span (leading offset << 32 | trimmed length).
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function musicTitleTagRowBatch(array $titles): array
    {
        return self::usesW55RustFfi()
            ? self::musicTitleTagRowBatchNative($titles)
            : self::musicTitleTagRowBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function musicTitleTagRowBatchNative(array $titles): array
    {
        self::w55AssertColumns($titles);
        $count = \count($titles);
        if ($count === 0) {
            return [];
        }

        return self::w55Call(implode("\0", $titles), '', $count, 4)
            ?? self::musicTitleTagRowBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function musicTitleTagRowBatchPhp(array $titles): array
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
                $flags |= self::W424_MUSIC_TOKENS[$token] ?? 0;
                $flags |= preg_match('/^(?:\d{2,3}K(?:BPS)?|V[0-2])\z/', $token) === 1 ? 4 : 0;
                if (preg_match('/^(?:19|20)\d{2}\z/', $token) === 1) {
                    $flags |= 32;
                    if ($year < 0) {
                        $year = (int) $token;
                    }
                }
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
            $written = $ffi->nntmux_w554_music_title_tag_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
