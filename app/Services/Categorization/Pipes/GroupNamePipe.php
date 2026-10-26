<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for group-based categorization.
 */
class GroupNamePipe extends AbstractCategorizationPipe
{
    /**
     * Wave 40.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures at least 4.0x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable. Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = true;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_group_name_routing(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Routing hint substrings, in match order: erotica, games, music, tv, movies. The first hint with
     * a substring of the trimmed, lowercased group name wins.
     *
     * @var list<list<string>>
     */
    public const array W403_ROUTING_HINTS = [
        ['erotica', 'xxx', 'porn', 'sex'],
        ['games', 'console', 'xbox', 'nintendo', 'playstation', 'wii'],
        ['music', 'mp3', 'sounds', 'flac', 'audio'],
        ['teevee', 'tv', 'series'],
        ['moovee', 'movies', 'dvd', 'bluray', 'divx', 'x264', 'x265'],
    ];

    private static ?bool $w403RustFfiOverride = null;

    private static FFI|false|null $w403RustFfiHandle = null;

    public const string PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_cat_pipe_cmd_pipe_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_cat_pipe_cmd_pipe_priority(uint8_t kind);
        int32_t nntmux_cat_pipe_cmd_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        ssize_t nntmux_cat_pipe_cmd_group_name_summary(int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len, char *out_buf, size_t out_cap);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The result was produced by a `group_name_*` rule. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    private const int PIPE_KIND = 0;

    private const string PIPE_NAME = 'GroupName';

    private const int PIPE_PRIORITY = 5;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private GroupNameCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new GroupNameCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeFfi = $ffi;
    }

    public function getName(): string
    {
        return self::pipeName();
    }

    public static function pipeName(): string
    {
        return self::$useRustFfi ? self::pipeNameNative() : self::pipeNamePhp();
    }

    public static function pipeNamePhp(): string
    {
        return self::PIPE_NAME;
    }

    public static function pipeNameNative(): string
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::pipeNamePhp();
        }

        return self::callForString($ffi, 'nntmux_cat_pipe_cmd_pipe_name', 16, self::PIPE_KIND) ?? self::pipeNamePhp();
    }

    public static function pipePriority(): int
    {
        return self::$useRustFfi ? self::pipePriorityNative() : self::pipePriorityPhp();
    }

    public static function pipePriorityPhp(): int
    {
        return self::PIPE_PRIORITY;
    }

    public static function pipePriorityNative(): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_cat_pipe_cmd_pipe_priority(self::PIPE_KIND);
        } catch (Throwable) {
            return self::pipePriorityPhp();
        }

        return \is_int($priority) && $priority >= 0 ? $priority : self::pipePriorityPhp();
    }

    /**
     * Evaluate a categorization result against this pipe as a `RESULT_*` bit mask.
     */
    public static function evaluateResult(int $categoryId, float $confidence, string $matchedBy): int
    {
        return self::$useRustFfi
            ? self::evaluateResultNative($categoryId, $confidence, $matchedBy)
            : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    public static function evaluateResultPhp(int $categoryId, float $confidence, string $matchedBy): int
    {
        $mask = 0;
        if (self::isSuccessfulPhp($categoryId, $confidence, $matchedBy)) {
            $mask |= self::RESULT_SUCCESSFUL;
        }
        if (str_starts_with($matchedBy, 'group_name_')) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_cat_pipe_cmd_evaluate_result(self::PIPE_KIND, $categoryId, $confidence, $matchedBy, \strlen($matchedBy));
        } catch (Throwable) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        return \is_int($mask) && $mask >= 0 ? $mask : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    /**
     * Normalized one-line summary of a group-name categorization result.
     */
    public static function resultSummary(int $categoryId, float $confidence, string $matchedBy): string
    {
        return self::$useRustFfi
            ? self::resultSummaryNative($categoryId, $confidence, $matchedBy)
            : self::resultSummaryPhp($categoryId, $confidence, $matchedBy);
    }

    public static function resultSummaryPhp(int $categoryId, float $confidence, string $matchedBy): string
    {
        if (! self::isSuccessfulPhp($categoryId, $confidence, $matchedBy)) {
            return self::PIPE_NAME.' -> no match';
        }

        return self::PIPE_NAME.' -> '.$categoryId.' ('.$matchedBy.')';
    }

    public static function resultSummaryNative(int $categoryId, float $confidence, string $matchedBy): string
    {
        $ffi = self::pipeFfi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::resultSummaryPhp($categoryId, $confidence, $matchedBy);
        }

        return self::callForString($ffi, 'nntmux_cat_pipe_cmd_group_name_summary', \strlen($matchedBy) + 48, $categoryId, $confidence, $matchedBy, \strlen($matchedBy))
            ?? self::resultSummaryPhp($categoryId, $confidence, $matchedBy);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    /**
     * Mirrors `CategorizationResult::isSuccessfulPhp()`.
     */
    private static function isSuccessfulPhp(int $categoryId, float $confidence, string $matchedBy): bool
    {
        return ! ($confidence <= 0) && ! ($categoryId === Category::OTHER_MISC && $matchedBy === 'no_match');
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function pipeFfi(): ?FFI
    {
        if (self::$pipeFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeFfi = RustPipelineLibrary::resolveDomain(
                    'w29-categorization-pipes-commands',
                    self::PIPE_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeFfi = false;
            }
        }

        return self::$pipeFfi === false ? null : self::$pipeFfi;
    }

    public static function useW403RustFfi(?bool $enabled): void
    {
        self::$w403RustFfiOverride = $enabled;
    }

    public static function usesW403RustFfi(): bool
    {
        return self::$w403RustFfiOverride ?? self::W403_RUST_FFI_DEFAULT;
    }

    public static function resetW403RustFfi(): void
    {
        self::$w403RustFfiOverride = null;
        self::$w403RustFfiHandle = null;
    }

    /**
     * Routing summary for a batch of newsgroup names. Each name is trimmed (PHP's default set) and
     * lowercased; alt.binaries./a.b. names are binary, other non-empty names text-only. Names
     * containing "boneless" are counted as boneless; each name adds to its first W403_ROUTING_HINTS
     * hint, and is unknown when it has neither a hint nor "boneless". Non-string names count as empty.
     *
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, binary: int, text_only: int, boneless: int, erotica: int, games: int, music: int, tv: int, movies: int, unknown: int}
     */
    public static function summarizeGroupNameRouting(array $groupNames): array
    {
        return (self::usesW403RustFfi() ? self::summarizeGroupNameRoutingNative($groupNames) : null)
            ?? self::summarizeGroupNameRoutingPhp($groupNames);
    }

    /**
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, binary: int, text_only: int, boneless: int, erotica: int, games: int, music: int, tv: int, movies: int, unknown: int}
     */
    public static function summarizeGroupNameRoutingPhp(array $groupNames): array
    {
        $binary = 0;
        $textOnly = 0;
        $boneless = 0;
        $unknown = 0;
        $hints = [0, 0, 0, 0, 0];
        foreach ($groupNames as $groupName) {
            $name = strtolower(trim(\is_string($groupName) ? $groupName : ''));
            if (str_starts_with($name, 'alt.binaries.') || str_starts_with($name, 'a.b.')) {
                $binary++;
            } elseif ($name !== '') {
                $textOnly++;
            }
            $isBoneless = str_contains($name, 'boneless');
            if ($isBoneless) {
                $boneless++;
            }
            foreach (self::W403_ROUTING_HINTS as $hint => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $hints[$hint]++;

                        continue 3;
                    }
                }
            }
            if (! $isBoneless) {
                $unknown++;
            }
        }

        return [
            'total' => \count($groupNames),
            'binary' => $binary,
            'text_only' => $textOnly,
            'boneless' => $boneless,
            'erotica' => $hints[0],
            'games' => $hints[1],
            'music' => $hints[2],
            'tv' => $hints[3],
            'movies' => $hints[4],
            'unknown' => $unknown,
        ];
    }

    /**
     * Declines (null) for non-string names so PHP counts them.
     *
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, binary: int, text_only: int, boneless: int, erotica: int, games: int, music: int, tv: int, movies: int, unknown: int}|null
     */
    public static function summarizeGroupNameRoutingNative(array $groupNames): ?array
    {
        $lengths = [];
        foreach ($groupNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $groupNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW403Native('nntmux_w403_group_name_routing', [$blob, \strlen($blob), $lens, \strlen($lens)], 128, 10);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'binary' => (int) $fields[1],
            'text_only' => (int) $fields[2],
            'boneless' => (int) $fields[3],
            'erotica' => (int) $fields[4],
            'games' => (int) $fields[5],
            'music' => (int) $fields[6],
            'tv' => (int) $fields[7],
            'movies' => (int) $fields[8],
            'unknown' => (int) $fields[9],
        ];
    }

    /**
     * Calls a Wave 40.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount, all of them decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW403Native(string $symbol, array $args, int $minBytes, int $fieldCount): ?array
    {
        $ffi = self::w403RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (\count($fields) !== $fieldCount) {
                return null;
            }
            foreach ($fields as $field) {
                if (\strlen($field) > 19 || ! ctype_digit($field)) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w403RustFfi(): ?FFI
    {
        if (self::$w403RustFfiHandle === null) {
            self::$w403RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w403', self::W403_FFI_CDEF) ?? false;
        }

        return self::$w403RustFfiHandle === false ? null : self::$w403RustFfiHandle;
    }

    /** Wave 54.3 paired benchmarks decide this default; see RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w543_group_name_route_flag_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Per uppercased group name token: 2 binaries, 4 TV, 8 movies, 16 audio, 32 games, 64 books, 128 adult, 256 PC and 512 anime. */
    private const array W54_GROUP_TOKENS = [
        'BINARIES' => 2, 'BINARY' => 2, 'TV' => 4, 'TEEVEE' => 4, 'HDTV' => 4, 'SERIES' => 4,
        'TELEVISION' => 4, 'MOVIES' => 8, 'MOOVEE' => 8, 'DVD' => 8, 'DVDR' => 8, 'BLURAY' => 8,
        'DIVX' => 8, 'XVID' => 8, 'X264' => 8, 'X265' => 8, 'HD' => 8, 'SOUNDS' => 16, 'MP3' => 16,
        'MUSIC' => 16, 'FLAC' => 16, 'LOSSLESS' => 16, 'AUDIO' => 16, 'GAMES' => 32, 'GAME' => 32,
        'CONSOLE' => 32, 'CONSOLES' => 32, 'XBOX' => 32, 'XBOX360' => 32, 'NINTENDO' => 32,
        'PLAYSTATION' => 32, 'WII' => 32, 'PS3' => 32, 'PS4' => 32, 'PSP' => 32, 'NDS' => 32,
        'EBOOK' => 64, 'EBOOKS' => 64, 'BOOKS' => 64, 'AUDIOBOOKS' => 64, 'COMICS' => 64,
        'MAGAZINES' => 64, 'EROTICA' => 128, 'XXX' => 128, 'PORN' => 128, 'SEX' => 128,
        'ADULT' => 128, 'APPS' => 256, 'WAREZ' => 256, 'SOFTWARE' => 256, 'PC' => 256, 'MAC' => 256,
        'LINUX' => 256, 'ANIME' => 512, 'MANGA' => 512, 'CARTOONS' => 512,
    ];

    public static function usesW54RustFfi(): bool
    {
        $override = self::w54RustFfiState();

        return \is_bool($override) ? $override : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 54 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 54.3 group routing flags. Per group name `flags | tokens << 16`, with flags 1 empty (after
     * trim()), 2 binaries, 4 TV, 8 movies, 16 audio, 32 games, 64 books, 128 adult, 256 PC and 512
     * anime, and `tokens` the token count; then the ten flag counts. Tokens are maximal ASCII
     * alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $groupNames
     * @return list<int>
     */
    public static function w54GroupNameRouteFlagBatch(array $groupNames): array
    {
        return self::usesW54RustFfi() ? self::w54GroupNameRouteFlagBatchNative($groupNames) : self::w54GroupNameRouteFlagBatchPhp($groupNames);
    }

    /**
     * @param  array<array-key, string>  $groupNames
     * @return list<int>
     */
    public static function w54GroupNameRouteFlagBatchNative(array $groupNames): array
    {
        return self::w54Invoke($groupNames, \count($groupNames) + 10) ?? self::w54GroupNameRouteFlagBatchPhp($groupNames);
    }

    /**
     * @param  array<array-key, string>  $groupNames
     * @return list<int>
     */
    public static function w54GroupNameRouteFlagBatchPhp(array $groupNames): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($groupNames as $groupName) {
            $groupName = trim((string) $groupName);
            if ($groupName === '') {
                $word = 1;
            } else {
                $tokens = preg_split('/[^A-Za-z0-9]+/', strtoupper($groupName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $flags = 0;
                foreach ($tokens as $token) {
                    $flags |= self::W54_GROUP_TOKENS[$token] ?? 0;
                }
                $word = $flags | (\count($tokens) << 16);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $totals[6] += ($word >> 6) & 1;
            $totals[7] += ($word >> 7) & 1;
            $totals[8] += ($word >> 8) & 1;
            $totals[9] += ($word >> 9) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);

        return $out;
    }

    /**
     * Wave 54 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out'
     * returns an `int64_t` output buffer of at least $val words.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w54', self::W54_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w54RustFfiState('ffi');
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
     * Calls the Wave 54.3 export with the NUL-joined $items. Null when the library is missing, the
     * call declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>|null
     */
    private static function w54Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w54RustFfiState('ffi');
            $out = self::w54RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $blob = implode("\0", $items);
            if (substr_count($blob, "\0") !== max(0, \count($items) - 1)) {
                // An item holds a NUL byte: substitute a space, which classifies identically (trim() strips both bytes and both split tokens).
                $blob = implode("\0", str_replace("\0", ' ', $items));
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w543_group_name_route_flag_batch($blob, \strlen($blob), \count($items), $out, $words);
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

    /** C signature of the Wave 66.5 {@see self::w66GroupNamePipePlanBatch()} helper. */
    private const string W66_GROUP_NAME_PIPE_PLAN_FFI_CDEF = 'int64_t nntmux_w665_group_name_pipe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.48/6.54/3.67/3.57x); see tests/Unit/RustCategorizationBookPassableConsoleGroupNameMiscPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /**
     * Wave 66.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w66_5', self::W66_GROUP_NAME_PIPE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W66_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 66.5 plan batch to Rust until disabled or reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Route the Wave 66.5 plan batch to PHP until enabled or reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restore W66_RUST_FFI_DEFAULT routing and drop the cached Wave 66.5 handle and buffer. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('set', null);
        self::w66RustFfiState('reset', null);
    }

    public static function usesW66RustFfi(): bool
    {
        return self::w66RustFfiState() === true;
    }

    /**
     * Runs $callback with w66GroupNamePipePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 66.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w66Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w66RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w665_group_name_pipe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w66Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /** Wave 66.5 group rules in match order: category, permille confidence and lowercase keywords (rule = index + 1). */
    private const array W66_GROUP_RULES = [
        [Category::TV_ANIME, 800, ['anime']],
        [Category::TV_OTHER, 600, ['tv']],
        [Category::MOVIE_OTHER, 600, ['movie', 'dvd', 'bluray', 'x264']],
        [Category::XXX_OTHER, 700, ['erotica', 'xxx']],
        [Category::MUSIC_OTHER, 600, ['sound', 'mp3', 'music', 'lossless']],
        [Category::GAME_OTHER, 600, ['game', 'console', 'psx', 'nintendo']],
        [Category::PC_0DAY, 600, ['warez', '0day', 'app', 'software']],
        [Category::BOOKS_EBOOK, 500, ['ebook', 'e-book', 'comic']],
    ];

    /** `CategorizationResult::isProtectedMiscResultPhp()` for a category id and matched-by label. */
    private static function w66ProtectedMisc(int $category, string $label): bool
    {
        return $category === Category::OTHER_HASHED || ($category === Category::OTHER_MISC && (str_starts_with($label, 'hash_')
            || str_starts_with($label, 'obfuscated_') || str_starts_with($label, 'gibberish_') || $label === 'group_only_low_signal'));
    }

    /**
     * `CategorizationResult::shouldOverridePhp()` on permille confidences: 2 blocked by the protected-misc guard,
     * 1 replaces the best result, 0 keeps it.
     */
    private static function w66OverrideDecision(int $category, int $confidence, int $bestCategory, int $bestConfidence, string $bestLabel): int
    {
        if ($category !== Category::OTHER_MISC && $category !== Category::OTHER_HASHED && $confidence < 600
            && self::w66ProtectedMisc($bestCategory, $bestLabel)) {
            return 2;
        }

        return $confidence > $bestConfidence || ($confidence === $bestConfidence && $category !== Category::OTHER_MISC && $bestCategory === Category::OTHER_MISC) ? 1 : 0;
    }

    /**
     * Batch group-name override plan over `groupName\tcurrentCategory\tcurrentConfidence\tcurrentMatchedBy`,
     * confidences in permille. Seven words per record: `[rule, category, confidence, decision, target, segments,
     * errors]`. The trimmed name is lowercased (ASCII); the text after its first `alt.binaries.` (else after a leading
     * `a.b.`, error 16) selects the first W66_GROUP_RULES rule with a keyword in it, like categorizePhp() without
     * line-break handling. Decision/target: 0 no rule (keeps the current category, default OTHER_MISC), 4 the current
     * result already stops (>= 950), 2 blocked by shouldOverridePhp()'s protected-misc guard, 1 the rule overrides the
     * current result, 3 the current result wins. Segments counts the non-empty dot-separated parts of the trimmed name.
     * Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid number, 2 not exactly four fields, 4 no
     * `alt.binaries.` prefix, 8 empty name, 16 abbreviated `a.b.` prefix.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66GroupNamePipePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66GroupNamePipePlanBatchNative($records) : self::w66GroupNamePipePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66GroupNamePipePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 7 * $count) ?? self::w66GroupNamePipePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66GroupNamePipePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 4 ? 0 : 2;
            $current = self::w66Number($fields[1] ?? '', Category::OTHER_MISC, $errors);
            $currentConfidence = self::w66Number($fields[2] ?? '', 0, $errors);
            $currentLabel = $fields[3] ?? '';
            $name = trim($fields[0]);
            $segments = 0;
            foreach (explode('.', $name) as $segment) {
                $segments += $segment !== '' ? 1 : 0;
            }
            $lower = strtolower($name);
            $rest = null;
            if ($name === '') {
                $errors |= 8;
            } elseif (($at = strpos($lower, 'alt.binaries.')) !== false) {
                $rest = substr($lower, $at + 13);
            } elseif (str_starts_with($lower, 'a.b.')) {
                $errors |= 16;
                $rest = substr($lower, 4);
            } else {
                $errors |= 4;
            }
            $rule = $category = $confidence = 0;
            if ($rest !== null) {
                foreach (self::W66_GROUP_RULES as $index => [$ruleCategory, $ruleConfidence, $keywords]) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($rest, $keyword)) {
                            [$rule, $category, $confidence] = [$index + 1, $ruleCategory, $ruleConfidence];
                            break 2;
                        }
                    }
                }
            }
            [$decision, $target] = match (true) {
                $rule === 0 => [0, $current],
                $currentConfidence >= 950 => [4, $current],
                default => match (self::w66OverrideDecision($category, $confidence, $current, $currentConfidence, $currentLabel)) {
                    2 => [2, $current],
                    1 => [1, $category],
                    default => [3, $current],
                },
            };
            array_push($out, $rule, $category, $confidence, $decision, $target, $segments, $errors);
        }

        return $out;
    }
}
