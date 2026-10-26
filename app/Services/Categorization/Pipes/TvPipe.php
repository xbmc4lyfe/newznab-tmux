<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 2.72-2.86x, p99 2.42-2.70x; uncommon mean 2.63-2.71x, p99 2.23-2.58x); see RustTvPipeBookServiceBooksConsolesGamesProcessorW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w533_tv_episode_token_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
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
     * Classifies TV release names by W413_EPISODE_PATTERN, then W413_DAILY_PATTERN, then
     * W413_SEASON_PACK_PATTERN on the lowercased name: one word per name with
     * `kind | season << 2 | episode << 16` (kind 0 none, 1 episode, 2 daily, 3 season pack; season and
     * episode come from the leftmost episode token), then the episode, daily, season-pack and none counts.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w53EpisodeTokenBatch(array $names): array
    {
        return self::usesW53RustFfi() ? self::w53EpisodeTokenBatchNative($names) : self::w53EpisodeTokenBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w53EpisodeTokenBatchNative(array $names): array
    {
        return self::w533Invoke($names, \count($names) + 4) ?? self::w53EpisodeTokenBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w53EpisodeTokenBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0];
        foreach ($names as $name) {
            $name = strtolower((string) $name);
            if (preg_match(self::W413_EPISODE_PATTERN, $name, $match) === 1) {
                $word = 1 | ((int) $match[1] << 2) | ((int) $match[2] << 16);
            } elseif (preg_match(self::W413_DAILY_PATTERN, $name) === 1) {
                $word = 2;
            } elseif (preg_match(self::W413_SEASON_PACK_PATTERN, $name) === 1) {
                $word = 3;
            } else {
                $word = 0;
            }
            $totals[$word & 3]++;
            $out[] = $word;
        }
        $out[] = $totals[1];
        $out[] = $totals[2];
        $out[] = $totals[3];
        $out[] = $totals[0];

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
                // An item holds a NUL byte: substitute 0x01, which classifies identically (none of the episode, daily or season-pack patterns match either byte).
                $blob = implode("\0", str_replace("\0", "\x01", $items));
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w533_tv_episode_token_batch($blob, \strlen($blob), \count($items), $out, $words);
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

    /** C signature of the Wave 66.6 {@see self::w66TvPipePlanBatch()} helper. */
    private const string W66_TV_PIPE_PLAN_FFI_CDEF = 'int64_t nntmux_w666_tv_pipe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.6 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 7.44/4.96/5.61/4.40x); see tests/Unit/RustCategorizationMiscSafetyMovieMusicPcTvPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /** `S\d{1,3}(?:E\d{1,4})?` over a whole uppercased token. */
    private const string W66_SEASON_PATTERN = '/\AS(\d{1,3})(?:E(\d{1,4}))?\z/';

    /** Wave 66.6 flags per uppercased token: 4 broadcast, 8 HD, 16 UHD, 32 SD, 64 anime, 128 sport, 256 documentary, 512 WEB, 1024 x265, 2048 foreign, 4096 movie source. */
    private const array W66_TV_TOKENS = [
        'SDTV' => 4 | 32,
        'HDTV' => 4, 'PDTV' => 4, 'DSR' => 4, 'TVRIP' => 4,
        '720P' => 8, '1080P' => 8, '1080I' => 8,
        '2160P' => 16, '4K' => 16, 'UHD' => 16,
        '480P' => 32, '576P' => 32, 'XVID' => 32,
        'ANIME' => 64, 'FANSUB' => 64, 'HORRIBLESUBS' => 64, 'SUBSPLEASE' => 64, 'ERAIRAWS' => 64,
        'NFL' => 128, 'NBA' => 128, 'NHL' => 128, 'MLB' => 128, 'UFC' => 128, 'WWE' => 128, 'F1' => 128, 'FORMULA1' => 128, 'EPL' => 128, 'UEFA' => 128, 'SPORT' => 128, 'SPORTS' => 128, 'BOXING' => 128, 'MOTOGP' => 128,
        'DOCU' => 256, 'DOCUMENTARY' => 256, 'DOCUMENTARIES' => 256, 'NATGEO' => 256, 'DISCOVERY' => 256,
        'WEB' => 512, 'WEBDL' => 512, 'WEBRIP' => 512,
        'X265' => 1024, 'H265' => 1024, 'HEVC' => 1024,
        'GERMAN' => 2048, 'FRENCH' => 2048, 'SPANISH' => 2048, 'ITALIAN' => 2048, 'DUTCH' => 2048, 'SWEDISH' => 2048, 'NORDIC' => 2048, 'DANISH' => 2048, 'FINNISH' => 2048, 'NORWEGIAN' => 2048, 'POLISH' => 2048, 'RUSSIAN' => 2048,
        'BLURAY' => 4096, 'BDRIP' => 4096, 'BRRIP' => 4096, 'REMUX' => 4096, 'BDMV' => 4096,
    ];

    /** Wave 66.6 proposals in priority order (once a series, anime, sport or documentary marker is present): flag bits => category. */
    private const array W66_TV_PROPOSALS = [
        128 => Category::TV_SPORT,
        64 => Category::TV_ANIME,
        256 => Category::TV_DOCU,
        2048 => Category::TV_FOREIGN,
        16 => Category::TV_UHD,
        1024 => Category::TV_X265,
        512 => Category::TV_WEBDL,
        8 => Category::TV_HD,
        32 => Category::TV_SD,
    ];

    /**
     * Wave 66.6 routing and FFI cache, kept in function-local statics (no properties). Without $op it
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
                    $ffi = RustPipelineLibrary::resolveDomain('w66_6', self::W66_TV_PIPE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W66_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 66.6 plan batch to Rust until disabled or reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Route the Wave 66.6 plan batch to PHP until enabled or reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restore W66_RUST_FFI_DEFAULT routing and drop the cached Wave 66.6 handle and buffer. */
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
     * Runs $callback with w66TvPipePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
     * Exactly $words output words from the Wave 66.6 Rust helper over $count NUL-joined records, or null
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
            $written = $ffi->nntmux_w666_tv_pipe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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

    /**
     * Batch TV pipe plan over `subject\tcategoryId\tconfidence\tmatchedBy\tcurrentCategory`, confidences in
     * permille. Eight words per record: `[tokens, flags, proposed, mask, decision, target, extra, errors]`. Tokens are
     * maximal ASCII alphanumeric runs of the subject compared case-insensitively; flags: 1 episode token (`S\d{1,3}E\d{1,4}`), 2 season pack token
     * (`S\d{1,3}`), W66_TV_TOKENS bits, 8192 a season token seen. Proposed is the
     * first W66_TV_PROPOSALS category whose flag bits are set once a series (1, 2, 4), anime, sport or documentary flag is set
     * (TV_OTHER without a match; 0 without such a flag). Mask: 1 successful (like evaluateResultPhp()),
     * 2 in domain, 4 terminal (>= 950), 8 proposed equals the result category. Decision/target: 3 keeps an
     * OTHER_HASHED current category, 1 takes a successful in-domain result, 4 keeps the current category when a movie source marker
     * appears without a series marker,
     * 2 moves an empty, OTHER_MISC or TV_OTHER current category to the proposal, 0 keeps the current category
     * (default OTHER_MISC). Extra: `season << 16 | episode` of the first season token (0 none). Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid number, 2 not
     * exactly five fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66TvPipePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66TvPipePlanBatchNative($records) : self::w66TvPipePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66TvPipePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 8 * $count) ?? self::w66TvPipePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66TvPipePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 5 ? 0 : 2;
            $tokens = $flags = $extra = 0;
            foreach (preg_split('/[^A-Z0-9]+/', strtoupper($fields[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens++;
                $flags |= self::W66_TV_TOKENS[$token] ?? 0;
                if ($token[0] === 'S' && preg_match(self::W66_SEASON_PATTERN, $token, $match) === 1) {
                    $flags |= isset($match[2]) ? 1 : 2;
                    if (($flags & 8192) === 0) {
                        $flags |= 8192;
                        $extra = (int) $match[1] << 16 | (int) ($match[2] ?? 0);
                    }
                }
            }
            $proposed = 0;
            if (($flags & (1 | 2 | 4 | 64 | 128 | 256)) !== 0) {
                $proposed = Category::TV_OTHER;
                foreach (self::W66_TV_PROPOSALS as $bits => $candidate) {
                    if (($flags & $bits) !== 0) {
                        $proposed = $candidate;
                        break;
                    }
                }
            }
            $category = self::w66Number($fields[1] ?? '', 0, $errors);
            $confidence = self::w66Number($fields[2] ?? '', 0, $errors);
            $label = $fields[3] ?? '';
            $current = self::w66Number($fields[4] ?? '', Category::OTHER_MISC, $errors);
            $success = $confidence > 0 && ! ($category === Category::OTHER_MISC && $label === 'no_match');
            $inDomain = $category >= Category::TV_ROOT && $category <= Category::TV_ROOT + 999;
            [$decision, $target] = match (true) {
                $current === Category::OTHER_HASHED => [3, $current],
                $success && $inDomain => [1, $category],
                ($flags & 4096) !== 0 && ($flags & 7) === 0 => [4, $current],
                $proposed !== 0 && ($current === 0 || $current === Category::OTHER_MISC || $current === Category::TV_OTHER) => [2, $proposed],
                default => [0, $current],
            };
            $mask = ($success ? 1 : 0) | ($inDomain ? 2 : 0) | ($confidence >= 950 ? 4 : 0) | ($proposed !== 0 && $proposed === $category ? 8 : 0);
            array_push($out, $tokens, $flags, $proposed, $mask, $decision, $target, $extra, $errors);
        }

        return $out;
    }
}
