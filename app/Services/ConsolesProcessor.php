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
}
