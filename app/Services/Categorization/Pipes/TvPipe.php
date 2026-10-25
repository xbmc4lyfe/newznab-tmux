<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Pipe for TV content categorization.
 */
class TvPipe extends AbstractCategorizationPipe
{
    /**
     * Wave 41.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationPipelineResultMusicPcTvW41ParityTest.php measures 1.99-2.04x mean
     * but only 1.33-1.93x p99 on the common batch (3.94-4.04x mean / 3.23-3.45x p99 uncommon), so the
     * common p99 misses the 1.5x adoption threshold and Rust stays behind useW413RustFfi(true).
     * Named W413 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W413_RUST_FFI_DEFAULT = false;

    public const string W413_FFI_CDEF = <<<'C'
        ssize_t nntmux_w413_episode_token_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Episode token in a lowercased name; its leftmost match supplies season and episode. */
    public const string W413_EPISODE_PATTERN = '/(?<![a-z0-9])s(\d{1,2})[ ._-]?e(\d{1,3})(?!\d)/';

    /** Daily air date (1900-2099, valid month and day 01-31) in a name. */
    public const string W413_DAILY_PATTERN = '/(?<!\d)(?:19|20)\d\d[ ._-](?:0[1-9]|1[0-2])[ ._-](?:0[1-9]|[12]\d|3[01])(?!\d)/';

    /** Season token without an episode in a lowercased name. */
    public const string W413_SEASON_PACK_PATTERN = '/(?<![a-z0-9])s\d{1,2}(?![\de])/';

    /** Exactly 7 0x1F-separated decimals of 1-19 digits. */
    private const string W413_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){6}\d{1,19}\z/';

    private static ?bool $w413RustFfiOverride = null;

    private static FFI|false|null $w413RustFfiHandle = null;

    public const string PIPE_SPEC_FFI_CDEF = <<<'C'
        ssize_t nntmux_categorize_pipes_spec_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_categorize_pipes_spec_priority(uint8_t kind);
        int32_t nntmux_categorize_pipes_spec_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in this pipe's category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    private const int PIPE_SPEC_KIND = 4;

    private const string PIPE_NAME = 'TV';

    private const int PIPE_PRIORITY = 20;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeSpecFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private TvCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new TvCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeSpecFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeSpecFfi = $ffi;
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
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipeNamePhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 16, $cap);
            if ($scratch === null) {
                return self::pipeNamePhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_categorize_pipes_spec_name(self::PIPE_SPEC_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return self::pipeNamePhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::pipeNamePhp();
        }
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
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_categorize_pipes_spec_priority(self::PIPE_SPEC_KIND);
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
        if (! ($confidence <= 0) && ! ($categoryId === Category::OTHER_MISC && $matchedBy === 'no_match')) {
            $mask |= self::RESULT_SUCCESSFUL;
        }
        if ($categoryId >= Category::TV_ROOT && $categoryId <= Category::TV_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_categorize_pipes_spec_evaluate_result(
                self::PIPE_SPEC_KIND,
                $categoryId,
                $confidence,
                $matchedBy,
                \strlen($matchedBy)
            );
        } catch (Throwable) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        return \is_int($mask) && $mask >= 0 ? $mask : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    protected function shouldSkip(ReleaseContext $context): bool
    {
        return $this->categorizer->shouldSkip($context);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    private static function pipeSpecFfi(): ?FFI
    {
        if (self::$pipeSpecFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeSpecFfi = RustPipelineLibrary::resolveDomain(
                    'categorize',
                    self::PIPE_SPEC_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeSpecFfi = false;
            }
        }

        return self::$pipeSpecFfi === false ? null : self::$pipeSpecFfi;
    }

    public static function useW413RustFfi(bool $enable = true): void
    {
        self::$w413RustFfiOverride = $enable;
    }

    public static function usesW413RustFfi(): bool
    {
        return self::$w413RustFfiOverride ?? self::W413_RUST_FFI_DEFAULT;
    }

    public static function resetW413RustFfi(): void
    {
        self::$w413RustFfiOverride = null;
        self::$w413RustFfiHandle = null;
    }

    /**
     * Episode token summary for a batch of TV release names. Each lowercased name is an episode
     * when it matches W413_EPISODE_PATTERN, else daily for W413_DAILY_PATTERN, else a season pack for
     * W413_SEASON_PACK_PATTERN, else none. Max season and episode come from each episode name's
     * leftmost token. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, episode: int, daily: int, season_pack: int, none: int, max_season: int, max_episode: int}
     */
    public static function summarizeEpisodeTokenBatch(array $releaseNames): array
    {
        return (self::usesW413RustFfi() ? self::summarizeEpisodeTokenBatchNative($releaseNames) : null)
            ?? self::summarizeEpisodeTokenBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, episode: int, daily: int, season_pack: int, none: int, max_season: int, max_episode: int}
     */
    public static function summarizeEpisodeTokenBatchPhp(array $releaseNames): array
    {
        $episode = 0;
        $daily = 0;
        $seasonPack = 0;
        $none = 0;
        $maxSeason = 0;
        $maxEpisode = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            if (preg_match(self::W413_EPISODE_PATTERN, $name, $match) === 1) {
                $episode++;
                $maxSeason = max($maxSeason, (int) $match[1]);
                $maxEpisode = max($maxEpisode, (int) $match[2]);
            } elseif (preg_match(self::W413_DAILY_PATTERN, $name) === 1) {
                $daily++;
            } elseif (preg_match(self::W413_SEASON_PACK_PATTERN, $name) === 1) {
                $seasonPack++;
            } else {
                $none++;
            }
        }

        return [
            'total' => \count($releaseNames),
            'episode' => $episode,
            'daily' => $daily,
            'season_pack' => $seasonPack,
            'none' => $none,
            'max_season' => $maxSeason,
            'max_episode' => $maxEpisode,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, episode: int, daily: int, season_pack: int, none: int, max_season: int, max_episode: int}|null
     */
    public static function summarizeEpisodeTokenBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW413Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'episode' => (int) $fields[1],
            'daily' => (int) $fields[2],
            'season_pack' => (int) $fields[3],
            'none' => (int) $fields[4],
            'max_season' => (int) $fields[5],
            'max_episode' => (int) $fields[6],
        ];
    }

    /**
     * Calls this class's Wave 41.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W413_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW413Native(string $blob, string $lens): ?array
    {
        $ffi = self::w413RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w413_episode_token_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w413_episode_token_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W413_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w413RustFfi(): ?FFI
    {
        if (self::$w413RustFfiHandle === null) {
            self::$w413RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w413', self::W413_FFI_CDEF) ?? false;
        }

        return self::$w413RustFfiHandle === false ? null : self::$w413RustFfiHandle;
    }
}
