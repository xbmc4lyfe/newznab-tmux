<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
