<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class ConsolesProcessor
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

    private const int RUST_PROCESSOR = 2;

    private const string LABEL = 'Consoles';

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
        $plan = self::dispatchPlan((int) app(ConfigurationProvider::class)->metadata()->gameLookup->value, $this->echooutput);
        if ($plan['dispatch']) {
            (new ConsoleService)->processConsoleReleases($groupID, $guidChar);
        }
    }

    /**
     * Resolves the console lookup gate, echo configuration and dispatch summary.
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

    /** Enabled: paired common and uncommon mean/p99 gains all reach 1.5x (see the W41 parity test). */
    public const bool W414_RUST_FFI_DEFAULT = true;

    public const string W414_FFI_CDEF = <<<'C'
        ssize_t nntmux_w414_console_release_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W414_SUMMARY_KEYS = ['total_releases', 'empty_title_count', 'platform_tagged_count', 'region_tagged_count', 'multi_language_count', 'dump_format_count', 'update_dlc_count'];

    private const array W414_PLATFORM_TOKENS = ['NINTENDO' => true, 'SWITCH' => true, 'NSW' => true, 'WII' => true, 'WIIU' => true, 'NDS' => true, '3DS' => true, 'GBA' => true, 'NES' => true, 'SNES' => true, 'N64' => true, 'NGC' => true, 'GAMECUBE' => true, 'PSX' => true, 'PS1' => true, 'PS2' => true, 'PS3' => true, 'PS4' => true, 'PS5' => true, 'PSP' => true, 'PSV' => true, 'VITA' => true, 'XBOX' => true, 'X360' => true, 'XBOXONE' => true, 'XONE' => true, 'XSX' => true, 'DREAMCAST' => true, 'GENESIS' => true, 'MEGADRIVE' => true, 'SATURN' => true];

    private const array W414_REGION_TOKENS = ['USA' => true, 'EUR' => true, 'EUROPE' => true, 'JPN' => true, 'JAP' => true, 'JAPAN' => true, 'PAL' => true, 'NTSC' => true, 'NTSCU' => true, 'NTSCJ' => true, 'ASIA' => true, 'KOR' => true, 'REGIONFREE' => true];

    private const array W414_DUMP_TOKENS = ['ISO' => true, 'NSP' => true, 'XCI' => true, 'CIA' => true, 'WBFS' => true, 'RVZ' => true, 'CSO' => true, 'CHD' => true, 'PKG' => true, 'NSZ' => true, 'XCZ' => true];

    private const array W414_UPDATE_TOKENS = ['UPDATE' => true, 'DLC' => true, 'PATCH' => true];

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
     * Summarize console release titles by platform, region, MULTI<n>
     * language, dump format and update/DLC/patch tokens.
     * Titles come from strings, integers, or the searchname, name or title
     * array field; other values (including objects) count as empty titles.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeConsoleReleaseBatch(array $releases): array
    {
        return self::usesW414RustFfi()
            ? self::summarizeConsoleReleaseBatchNative($releases)
            : self::summarizeConsoleReleaseBatchPhp($releases);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeConsoleReleaseBatchNative(array $releases): array
    {
        return self::w414Call(self::w414Titles($releases)) ?? self::summarizeConsoleReleaseBatchPhp($releases);
    }

    /**
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeConsoleReleaseBatchPhp(array $releases): array
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
                $found[0] = $found[0] || isset(self::W414_PLATFORM_TOKENS[$token]);
                $found[1] = $found[1] || isset(self::W414_REGION_TOKENS[$token]);
                $found[2] = $found[2] || (str_starts_with($token, 'MULTI') && strspn($token, '0123456789', 5) === strlen($token) - 5);
                $found[3] = $found[3] || isset(self::W414_DUMP_TOKENS[$token]);
                $found[4] = $found[4] || isset(self::W414_UPDATE_TOKENS[$token]);
            }
            $summary['platform_tagged_count'] += (int) $found[0];
            $summary['region_tagged_count'] += (int) $found[1];
            $summary['multi_language_count'] += (int) $found[2];
            $summary['dump_format_count'] += (int) $found[3];
            $summary['update_dlc_count'] += (int) $found[4];
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
            $written = $ffi->nntmux_w414_console_release_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
                $written = $ffi->nntmux_w414_console_release_batch($input, strlen($input), $out, $capacity);
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

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 4.95-5.67x, p99 4.35-5.10x; uncommon mean 4.14-4.41x, p99 3.22-4.54x); see RustTvPipeBookServiceBooksConsolesGamesProcessorW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w533_console_title_flag_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public static function usesW53RustFfi(): bool
    {
        $override = self::w53RustFfiState();

        return \is_bool($override) ? $override : self::W53_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 53 Rust path forced on or off, then restores the previous setting. */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * Per uppercased token: 2 | code << 8 for a platform (code is its 1-based position in
     * W414_PLATFORM_TOKENS), 4 region, 16 dump format and 32 update/DLC/patch.
     */
    private const array W53_CONSOLE_TOKEN_FLAGS = [
        'NINTENDO' => 2 | 1 << 8, 'SWITCH' => 2 | 2 << 8, 'NSW' => 2 | 3 << 8, 'WII' => 2 | 4 << 8, 'WIIU' => 2 | 5 << 8,
        'NDS' => 2 | 6 << 8, '3DS' => 2 | 7 << 8, 'GBA' => 2 | 8 << 8, 'NES' => 2 | 9 << 8, 'SNES' => 2 | 10 << 8,
        'N64' => 2 | 11 << 8, 'NGC' => 2 | 12 << 8, 'GAMECUBE' => 2 | 13 << 8, 'PSX' => 2 | 14 << 8, 'PS1' => 2 | 15 << 8,
        'PS2' => 2 | 16 << 8, 'PS3' => 2 | 17 << 8, 'PS4' => 2 | 18 << 8, 'PS5' => 2 | 19 << 8, 'PSP' => 2 | 20 << 8,
        'PSV' => 2 | 21 << 8, 'VITA' => 2 | 22 << 8, 'XBOX' => 2 | 23 << 8, 'X360' => 2 | 24 << 8, 'XBOXONE' => 2 | 25 << 8,
        'XONE' => 2 | 26 << 8, 'XSX' => 2 | 27 << 8, 'DREAMCAST' => 2 | 28 << 8, 'GENESIS' => 2 | 29 << 8,
        'MEGADRIVE' => 2 | 30 << 8, 'SATURN' => 2 | 31 << 8,
        'USA' => 4, 'EUR' => 4, 'EUROPE' => 4, 'JPN' => 4, 'JAP' => 4, 'JAPAN' => 4, 'PAL' => 4, 'NTSC' => 4,
        'NTSCU' => 4, 'NTSCJ' => 4, 'ASIA' => 4, 'KOR' => 4, 'REGIONFREE' => 4,
        'ISO' => 16, 'NSP' => 16, 'XCI' => 16, 'CIA' => 16, 'WBFS' => 16, 'RVZ' => 16, 'CSO' => 16, 'CHD' => 16,
        'PKG' => 16, 'NSZ' => 16, 'XCZ' => 16,
        'UPDATE' => 32, 'DLC' => 32, 'PATCH' => 32,
    ];

    /**
     * Classifies console release titles: one word per trimmed title with `flags | code << 8`, flags 1
     * empty, 2 platform, 4 region, 8 MULTI<n> language, 16 dump format and 32 update/DLC/patch
     * tokens, and `code` the first platform token's W53_CONSOLE_TOKEN_FLAGS code (0 without one);
     * then the six flag counts. Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53ConsoleTitleFlagBatch(array $titles): array
    {
        return self::usesW53RustFfi() ? self::w53ConsoleTitleFlagBatchNative($titles) : self::w53ConsoleTitleFlagBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53ConsoleTitleFlagBatchNative(array $titles): array
    {
        return self::w533Invoke($titles, \count($titles) + 6) ?? self::w53ConsoleTitleFlagBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53ConsoleTitleFlagBatchPhp(array $titles): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0];
        foreach ($titles as $title) {
            $title = trim((string) $title);
            if ($title === '') {
                $word = 1;
            } else {
                $flags = 0;
                $code = 0;
                foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    $found = self::W53_CONSOLE_TOKEN_FLAGS[$token] ?? 0;
                    if (str_starts_with($token, 'MULTI') && strspn($token, '0123456789', 5) === \strlen($token) - 5) {
                        $flags |= 8;
                    }
                    $flags |= $found & 0xFF;
                    if ($code === 0) {
                        $code = $found >> 8;
                    }
                }
                $word = $flags | ($code << 8);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);

        return $out;
    }

    /**
     * Wave 53 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out'
     * returns an `int64_t` output buffer of at least $val words.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w53', self::W53_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w53RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }

        return $override;
    }

    /**
     * Calls the Wave 53.3 export with the NUL-joined $items. Null when the library is missing, the
     * call declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>|null
     */
    private static function w533Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w53RustFfiState('ffi');
            $out = self::w53RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $blob = implode("\0", $items);
            if (substr_count($blob, "\0") !== max(0, \count($items) - 1)) {
                // An item holds a NUL byte: substitute a space, which classifies identically (trim() strips both bytes and both split tokens).
                $blob = implode("\0", str_replace("\0", ' ', $items));
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w533_console_title_flag_batch($blob, \strlen($blob), \count($items), $out, $words);
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

    // ---- Wave 67.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.475x/6.917x, uncommon 8.701x/8.247x); see RustRuntimeStateConsolesEpisodeHydrationFanartForkingW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_CONSOLES_PROCESSOR_FFI_CDEF = <<<'C'
        intptr_t nntmux_w672_consoles_processor_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W67_CONSOLES_PROCESSOR_TAIL = 26;

    /**
     * Wave 67 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w67', self::W67_CONSOLES_PROCESSOR_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w67RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w67ConsolesProcessorPlanBatch() to Rust until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Routes w67ConsolesProcessorPlanBatch() to PHP until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w67ConsolesProcessorPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ConsolesProcessorPlanBatch(array $rows): array
    {
        return self::usesW67RustFfi() ? self::w67ConsolesProcessorPlanBatchNative($rows) : self::w67ConsolesProcessorPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ConsolesProcessorPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W67_CONSOLES_PROCESSOR_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w67RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w67ConsolesProcessorPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W67_CONSOLES_PROCESSOR_FFI_CDEF.)
            $written = $ffi->nntmux_w672_consoles_processor_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w67ConsolesProcessorPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w67Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w67Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** 0 empty or 0/false/off/no, 1 for 1/true/on/yes, 2 any other value (trimmed, any case). */
    private static function w67Flag(string $value): int
    {
        return match (strtolower(trim($value))) {
            '', '0', 'false', 'off', 'no' => 0,
            '1', 'true', 'on', 'yes' => 1,
            default => 2,
        };
    }

    /** Upper-cased title token => platform code. */
    private const array W67_CONSOLE_PLATFORMS = ['NDS' => 1, '3DS' => 2, 'WII' => 3, 'WIIU' => 4, 'SWITCH' => 5, 'NSW' => 5, 'PSP' => 6, 'PSV' => 7, 'VITA' => 7,
        'PSX' => 8, 'PS1' => 8, 'PS2' => 9, 'PS3' => 10, 'PS4' => 11, 'PS5' => 12, 'XBOX' => 13, 'X360' => 14, 'XBOX360' => 14, 'XBOXONE' => 15, 'XONE' => 15,
        'XSX' => 16, 'GBA' => 17, 'NGC' => 18, 'GAMECUBE' => 18, 'N64' => 19, 'SNES' => 20, 'NES' => 21, 'DREAMCAST' => 22];

    /** Upper-cased title token => region code (1 NTSC-U, 2 PAL, 3 NTSC-J, 4 Asia, 5 region free). */
    private const array W67_CONSOLE_REGIONS = ['USA' => 1, 'NTSC' => 1, 'NTSCU' => 1, 'EUR' => 2, 'EUROPE' => 2, 'PAL' => 2, 'JPN' => 3, 'JAP' => 3, 'JAPAN' => 3,
        'NTSCJ' => 3, 'ASIA' => 4, 'KOR' => 4, 'REGIONFREE' => 5];

    private const int W67_CONSOLE_MAX_ATTEMPTS = 3;

    /** Retry backoff in hours per previous attempt count. */
    private const array W67_CONSOLE_BACKOFF_HOURS = [1, 4, 12];

    /** 0 none, 1 Nintendo, 2 Sony, 3 Microsoft, 4 Sega. */
    private static function w67ConsoleFamily(int $platform): int
    {
        return match (true) {
            $platform === 0 => 0,
            $platform <= 5, $platform >= 17 && $platform <= 21 => 1,
            $platform <= 12 => 2,
            $platform <= 16 => 3,
            default => 4,
        };
    }

    /**
     * Wave 67.2 console release plan. Per `searchname\tcategory\tconsoleinfo_id\tattempts\tmode\t
     * renamed` row `action | platform << 4 | region << 9 | min(title_tokens, 15) << 12 |
     * min(next_attempt, 7) << 16 | backoff_hours << 19 | duplicate << 23 | min(leader, 2^20 - 1)
     * << 24 | (crc32(key) & 0x7FFFF) << 44`. The searchname splits into ASCII alphanumeric tokens;
     * the first W67_CONSOLE_PLATFORMS and W67_CONSOLE_REGIONS tokens set platform and region, and
     * the tokens before the first of either are the title. Category, consoleinfo id, attempts and
     * mode are w67Int() fields of 6, 9, 3 and 1 digits, renamed a w67Flag(). An empty searchname,
     * a missing or unparsable category, an unparsable id, attempts or mode, a mode above 2 or an
     * unparsable renamed flag is malformed (0). Otherwise: a category outside 1000-1999 skips (1),
     * a positive consoleinfo id is matched (2), mode 0 disables lookups (3), mode 2 skips a release
     * not renamed (4), W67_CONSOLE_MAX_ATTEMPTS attempts are exhausted (5), no platform and fewer
     * than two title tokens is unidentifiable (8, next attempt W67_CONSOLE_MAX_ATTEMPTS), and the
     * rest look up IGDB with a platform (6) or Amazon without one (7) after the
     * W67_CONSOLE_BACKOFF_HOURS backoff, unless the `chr(platform):title` key (lower-cased title
     * tokens joined by spaces) already appeared, which coalesces into its leader (9). Then ten
     * action, five platform-family and six region counts, distinct lookup keys, coalesced rows,
     * total and highest attempts (well-formed rows) and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ConsolesProcessorPlanBatchPhp(array $rows): array
    {
        $out = [];
        $actions = array_fill(0, 10, 0);
        $families = array_fill(0, 5, 0);
        $regions = array_fill(0, 6, 0);
        $keys = [];
        $dupRows = $totalAttempts = $maxAttempts = 0;
        $index = 0;
        foreach ($rows as $row) {
            [$name, $category, $status, $attempts, $mode, $renamed] = explode("\t", (string) $row, 6) + ['', '', '', '', '', ''];
            $platform = $region = 0;
            $title = [];
            $titled = true;
            foreach (preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $upper = strtoupper($token);
                $p = self::W67_CONSOLE_PLATFORMS[$upper] ?? 0;
                $g = self::W67_CONSOLE_REGIONS[$upper] ?? 0;
                if ($p !== 0 || $g !== 0) {
                    $titled = false;
                    $platform = $platform === 0 ? $p : $platform;
                    $region = $region === 0 ? $g : $region;
                } elseif ($titled) {
                    $title[] = strtolower($token);
                }
            }
            $titleTokens = \count($title);
            $category = self::w67Int($category, 6);
            $status = self::w67Int($status, 9);
            $attempts = self::w67Int($attempts, 3);
            $mode = self::w67Int($mode, 1);
            $renamed = self::w67Flag($renamed);
            $cat = \is_int($category) ? $category : 0;
            $st = \is_int($status) ? $status : 0;
            $a = \is_int($attempts) ? $attempts : 0;
            $m = \is_int($mode) ? $mode : 0;
            $malformed = trim($name) === '' || ! \is_int($category) || $status === false || $attempts === false || $mode === false || $m > 2 || $renamed === 2;
            $next = $backoff = $leader = $hash = 0;
            $dup = false;
            if ($malformed) {
                $action = 0;
            } elseif ($cat < 1000 || $cat > 1999) {
                $action = 1;
            } elseif ($st > 0) {
                $action = 2;
            } elseif ($m === 0) {
                $action = 3;
            } elseif ($m === 2 && $renamed !== 1) {
                $action = 4;
            } elseif ($a >= self::W67_CONSOLE_MAX_ATTEMPTS) {
                $action = 5;
            } elseif ($platform === 0 && $titleTokens < 2) {
                [$action, $next] = [8, self::W67_CONSOLE_MAX_ATTEMPTS];
            } else {
                $key = \chr($platform).':'.implode(' ', $title);
                $hash = crc32($key) & 0x7FFFF;
                if (isset($keys[$key])) {
                    [$action, $dup, $leader] = [9, true, $keys[$key]];
                } else {
                    $keys[$key] = $leader = $index;
                    $next = $a + 1;
                    $backoff = self::W67_CONSOLE_BACKOFF_HOURS[$a];
                    $action = $platform !== 0 ? 6 : 7;
                }
            }
            if (! $malformed) {
                $totalAttempts += $a;
                $maxAttempts = max($maxAttempts, $a);
            }
            $actions[$action]++;
            $families[self::w67ConsoleFamily($platform)]++;
            $regions[$region]++;
            $dupRows += (int) $dup;
            $out[] = $action | $platform << 4 | $region << 9 | min($titleTokens, 15) << 12 | min($next, 7) << 16 | $backoff << 19 | (int) $dup << 23
                | min($leader, 0xFFFFF) << 24 | $hash << 44;
            $index++;
        }
        array_push($out, ...$actions, ...$families, ...$regions);
        array_push($out, \count($keys), $dupRows, $totalAttempts, $maxAttempts);
        $out[] = self::w67Digest($out);

        return $out;
    }
}
