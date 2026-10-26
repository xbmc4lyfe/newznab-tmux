<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Value object containing release information for TV processing.
 */
class TvReleaseContext
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_release_context_from_serialized(const char *ser, size_t ser_len, int64_t *out_release_id, int64_t *out_groups_id, int64_t *out_categories_id, int64_t *out_videos_id, int64_t *out_tv_episodes_id, int32_t *out_flags, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvBookSeriesLookupParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        public readonly int $releaseId,
        public readonly string $searchName,
        public readonly int $groupsId,
        public readonly int $categoriesId,
        public readonly int $videosId = 0,
        public readonly int $tvEpisodesId = 0,
        public readonly ?string $guid = null,
        public readonly ?string $leftGuid = null,
    ) {}

    /**
     * Create from a Release model array/object.
     *
     * @param  array<string, mixed>|object  $release
     */
    public static function fromRelease(array|object $release): self
    {
        return self::usesRustFfi()
            ? self::fromReleaseNative($release)
            : self::fromReleasePhp($release);
    }

    /**
     * @param  array<string, mixed>|object  $release
     */
    public static function fromReleaseNative(array|object $release): self
    {
        return self::fromDataNative(self::extractDataArray($release));
    }

    /**
     * @param  array<string, mixed>|object  $release
     */
    public static function fromReleasePhp(array|object $release): self
    {
        return self::fromDataPhp(self::extractDataArray($release));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromData(array $data): self
    {
        return self::usesRustFfi()
            ? self::fromDataNative($data)
            : self::fromDataPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromDataNative(array $data): self
    {
        if (($ffi = self::ffi()) === null) {
            return self::fromDataPhp($data);
        }
        try {
            $ser = serialize($data);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $capacity);
            if ($buffer === null) {
                return self::fromDataPhp($data);
            }
            $outReleaseId = $ffi->new('int64_t[1]');
            $outGroupsId = $ffi->new('int64_t[1]');
            $outCategoriesId = $ffi->new('int64_t[1]');
            $outVideosId = $ffi->new('int64_t[1]');
            $outTvEpisodesId = $ffi->new('int64_t[1]');
            $outFlags = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_release_context_from_serialized(
                $ser,
                $len,
                $outReleaseId,
                $outGroupsId,
                $outCategoriesId,
                $outVideosId,
                $outTvEpisodesId,
                $outFlags,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::fromDataPhp($data);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_release_context_from_serialized(
                    $ser,
                    $len,
                    $outReleaseId,
                    $outGroupsId,
                    $outCategoriesId,
                    $outVideosId,
                    $outTvEpisodesId,
                    $outFlags,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 2 || $written > $capacity) {
                return self::fromDataPhp($data);
            }
            $parts = explode("\0", FFI::string($buffer, $written), 3);
            if (\count($parts) !== 3) {
                return self::fromDataPhp($data);
            }
            $flags = (int) $outFlags[0];

            return new self(
                releaseId: (int) $outReleaseId[0],
                searchName: $parts[0],
                groupsId: (int) $outGroupsId[0],
                categoriesId: (int) $outCategoriesId[0],
                videosId: (int) $outVideosId[0],
                tvEpisodesId: (int) $outTvEpisodesId[0],
                guid: ($flags & 1) !== 0 ? $parts[1] : null,
                leftGuid: ($flags & 2) !== 0 ? $parts[2] : null,
            );
        } catch (Throwable) {
            return self::fromDataPhp($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromDataPhp(array $data): self
    {
        return new self(
            releaseId: (int) ($data['id'] ?? 0),
            searchName: (string) ($data['searchname'] ?? ''),
            groupsId: (int) ($data['groups_id'] ?? 0),
            categoriesId: (int) ($data['categories_id'] ?? 0),
            videosId: (int) ($data['videos_id'] ?? 0),
            tvEpisodesId: (int) ($data['tv_episodes_id'] ?? 0),
            guid: $data['guid'] ?? null,
            leftGuid: $data['leftguid'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>|object  $release
     * @return array<string, mixed>
     */
    private static function extractDataArray(array|object $release): array
    {
        // Handle Eloquent models properly - use toArray() instead of casting
        if ($release instanceof Model) {
            return $release->toArray();
        }

        return \is_array($release) ? $release : (array) $release;
    }

    /**
     * Wave 47.3 batch metadata normalization and eligibility scoring. Defaults to Rust: the
     * paired benchmark cleared 1.5x on common and uncommon mean and p99;
     * useW47ReleaseContextRustFfi(false) selects PHP.
     */
    public const bool W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_release_context_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Values written per context, followed by the eligible count and the score sum. */
    public const int W47_FIELDS_PER_CONTEXT = 4;

    /** Scores at or above this count as eligible for a provider lookup. */
    public const int W47_ELIGIBLE_SCORE = 50;

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT. */
    public static function useW47ReleaseContextRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47ReleaseContextRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per context (normalized searchname length, its FNV-1a 32 digest, flag bits and
     * the 0-100 eligibility score), then the eligible count and the score sum. The normalized
     * name lowercases ASCII and collapses `.`, `_`, space and tab runs into single spaces.
     *
     * Flags: 1 TV category, 2 has show, 4 has episode, 8 SxxEyy marker, 16 name shorter than 5,
     * 32 non-ASCII name, 64 invalid release id, 128 invalid group id.
     *
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatch(array $contexts): array
    {
        return self::usesW47ReleaseContextRustFfi()
            ? self::w47EligibilityBatchNative($contexts)
            : self::w47EligibilityBatchPhp($contexts);
    }

    /**
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatchNative(array $contexts): array
    {
        $count = \count($contexts);
        $blob = implode("\0", array_column($contexts, 'searchName'));
        if (($count > 0 && substr_count($blob, "\0") !== $count - 1) || ($ffi = self::w47RustFfi()) === null) {
            return self::w47EligibilityBatchPhp($contexts);
        }
        try {
            $ints = pack(
                'q*',
                ...array_column($contexts, 'releaseId'),
                ...array_column($contexts, 'groupsId'),
                ...array_column($contexts, 'categoriesId'),
                ...array_column($contexts, 'videosId'),
                ...array_column($contexts, 'tvEpisodesId'),
            );
            $bytes = ($count * self::W47_FIELDS_PER_CONTEXT + 2) * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null || \strlen($ints) !== $count * 40) {
                return self::w47EligibilityBatchPhp($contexts);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_release_context_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47EligibilityBatchPhp($contexts);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47EligibilityBatchPhp($contexts);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47EligibilityBatchPhp($contexts);
        }
    }

    /**
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatchPhp(array $contexts): array
    {
        $out = [];
        $eligible = 0;
        $scoreSum = 0;
        foreach ($contexts as $context) {
            $name = strtolower(trim((string) preg_replace('/[._ \t]+/', ' ', $context->searchName), ' '));
            $length = \strlen($name);
            $marker = preg_match('/(?:^| )s\d+e\d/', $name) === 1;
            $nonAscii = preg_match('/[\x80-\xFF]/', $name) === 1;
            $tv = $context->categoriesId >= 5000 && $context->categoriesId <= 5999;
            $hasShow = $context->videosId > 0;
            $hasEpisode = $context->tvEpisodesId > 0;
            $short = $length < 5;
            $badRelease = $context->releaseId <= 0;
            $badGroup = $context->groupsId <= 0;
            $flags = ($tv ? 1 : 0) | ($hasShow ? 2 : 0) | ($hasEpisode ? 4 : 0) | ($marker ? 8 : 0)
                | ($short ? 16 : 0) | ($nonAscii ? 32 : 0) | ($badRelease ? 64 : 0) | ($badGroup ? 128 : 0);
            $score = 0;
            if ($tv && ! $badRelease) {
                $score = 50 + ($marker ? 30 : 0) - ($short ? 40 : 0) + ($hasShow ? 0 : 10)
                    - ($hasEpisode ? 50 : 0) - ($nonAscii ? 10 : 0) - ($badGroup ? 5 : 0);
                $score = max(0, min(100, $score));
            }
            if ($score >= self::W47_ELIGIBLE_SCORE) {
                $eligible++;
            }
            $scoreSum += $score;
            array_push($out, $length, self::w47Fnv($name), $flags, $score);
        }
        $out[] = $eligible;
        $out[] = $scoreSum;

        return $out;
    }

    private static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w47RustFfi(): ?FFI
    {
        if (self::$w47RustFfiHandle === null) {
            self::$w47RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w47', self::W47_FFI_CDEF) ?? false;
        }

        return self::$w47RustFfiHandle === false ? null : self::$w47RustFfiHandle;
    }

    // ========================================================================
    // WAVE 63.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.42x, p99 5.27x; uncommon mean 9.74x, p99 8.78x); see RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_TV_RELEASE_CONTEXT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w633_tv_release_context_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 63.3 batch. */
    public const int W63_TV_RELEASE_CONTEXT_TRAILER = 16;

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the TvReleaseContext Wave 63 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.3 release context eligibility, category, airdate, season pack and lookup status plans. Each item is
     * `releaseId|searchName|groupsId|categoriesId|videosId|tvEpisodesId|guid|leftGuid|airdate`. Ids are 0 empty, 1 a
     * positive trimmed `[+-]?\d{1,18}` or 2 other; TV means a category in 5000-5999. The searchname lowercases ASCII and
     * collapses `.`, `_`, space and tab runs into single spaces (as w47EligibilityBatch()); its first space-separated
     * word that is a marker sets the kind: 1 an `s\d{1,3}e\d` prefix (season, first four episode digits), 4 an
     * `\d{1,2}x\d{2,3}` word, 2 an `s\d{1,3}` season pack, 3 a 19xx/20xx year followed by 1-2 digit month 1-12 and day
     * 1-31 words. Per item `releaseState | groupState << 2 | videoState << 4 | episodeState << 6 | tv << 8 | kind << 9 |
     * airState << 12 | guidState << 14 | leftState << 16 | status << 18 | short << 21 | nonAscii << 22 | score << 23 |
     * min(length, 1023) << 30 | min(season, 1023) << 40 | min(episode, 8191) << 50`: the air state is 0 empty, 1 a trimmed
     * `YYYY-MM-DD` (`-`, `.` or `/`) in 1900-2099, 2 other; the guid is 0 empty, 1 forty hex digits, 2 other; the left
     * guid is 0 empty, 1 the guid's first character (any case), 2 other; the status is 0 an invalid release, 1 not TV, 2
     * processed (show and episode), 3 needs its episode, 4 needs a show lookup (a marker or airdate), else 5 needs
     * parsing; short marks fewer than 5 bytes and nonAscii a byte from 0x80; TV releases with a valid id score 50 + 30 for
     * a marker - 40 short + 10 without a show - 50 with an episode - 10 non-ASCII - 5 without a valid group, clamped to
     * 0-100, others 0. Then the six status and five kind counts, the eligible count (score from 50), the score sum, the
     * distinct non-empty normalized names, the distinct valid group ids and the TV count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvReleaseContextPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63TvReleaseContextPlanBatchNative($items) : self::w63TvReleaseContextPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvReleaseContextPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_TV_RELEASE_CONTEXT_TRAILER) ?? self::w63TvReleaseContextPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvReleaseContextPlanBatchPhp(array $items): array
    {
        $out = [];
        $statuses = [0, 0, 0, 0, 0, 0];
        $kinds = [0, 0, 0, 0, 0];
        $eligible = $scoreSum = $tvs = 0;
        $names = $groups = [];
        foreach ($items as $item) {
            [$rawRelease, $rawName, $rawGroup, $rawCategory, $rawVideo, $rawEpisode, $rawGuid, $rawLeft, $rawAir]
                = explode('|', (string) $item, 9) + ['', '', '', '', '', '', '', '', ''];
            [$releaseState] = self::w63PositiveState($rawRelease);
            [$groupState, $group] = self::w63PositiveState($rawGroup);
            [$videoState] = self::w63PositiveState($rawVideo);
            [$episodeState] = self::w63PositiveState($rawEpisode);
            [$categoryState, $category] = self::w63IntState($rawCategory);
            $tv = $categoryState === 1 && $category >= 5000 && $category <= 5999 ? 1 : 0;
            $name = strtolower(trim((string) preg_replace('/[._ \t]+/', ' ', $rawName), ' '));
            $length = \strlen($name);
            $short = $length < 5 ? 1 : 0;
            $nonAscii = preg_match('/[\x80-\xFF]/', $name) === 1 ? 1 : 0;
            [$kind, $season, $episode] = self::w63ContextMarker(explode(' ', $name));
            $air = self::w63AirState($rawAir);
            $guid = trim($rawGuid);
            $guidState = $guid === '' ? 0 : (\strlen($guid) === 40 && strspn($guid, '0123456789abcdefABCDEF') === 40 ? 1 : 2);
            $left = trim($rawLeft);
            $leftState = $left === '' ? 0 : (\strlen($left) === 1 && $guidState === 1 && strtolower($left) === strtolower($guid[0]) ? 1 : 2);
            $status = match (true) {
                $releaseState !== 1 => 0,
                $tv === 0 => 1,
                $videoState === 1 && $episodeState === 1 => 2,
                $videoState === 1 => 3,
                $kind > 0 || $air === 1 => 4,
                default => 5,
            };
            $score = 0;
            if ($tv === 1 && $releaseState === 1) {
                $score = max(0, min(100, 50 + ($kind > 0 ? 30 : 0) - ($short === 1 ? 40 : 0) + ($videoState === 1 ? 0 : 10)
                    - ($episodeState === 1 ? 50 : 0) - ($nonAscii === 1 ? 10 : 0) - ($groupState === 1 ? 0 : 5)));
            }
            $statuses[$status]++;
            $kinds[$kind]++;
            $eligible += $score >= 50 ? 1 : 0;
            $scoreSum += $score;
            $tvs += $tv;
            if ($name !== '') {
                $names[$name] = true;
            }
            if ($groupState === 1) {
                $groups[$group] = true;
            }
            $out[] = $releaseState | $groupState << 2 | $videoState << 4 | $episodeState << 6 | $tv << 8 | $kind << 9 | $air << 12
                | $guidState << 14 | $leftState << 16 | $status << 18 | $short << 21 | $nonAscii << 22 | $score << 23
                | min($length, 1023) << 30 | min($season, 1023) << 40 | min($episode, 8191) << 50;
        }
        array_push($out, ...$statuses, ...$kinds);
        array_push($out, $eligible, $scoreSum, \count($names), \count($groups), $tvs);

        return $out;
    }

    /**
     * (kind, season, episode) of the first marker word: 1 an `s\d{1,3}e\d` prefix, 2 an `s\d{1,3}` season pack, 3 a
     * `YYYY MM DD` daily date, 4 an `\d{1,2}x\d{2,3}` word; else 0.
     *
     * @param  list<string>  $words
     * @return array{int, int, int}
     */
    private static function w63ContextMarker(array $words): array
    {
        $count = \count($words);
        foreach ($words as $k => $word) {
            $length = \strlen($word);
            if ($length > 0 && $word[0] === 's') {
                $run = strspn($word, '0123456789', 1);
                if ($run >= 1 && $run <= 3) {
                    $season = (int) substr($word, 1, $run);
                    if (1 + $run === $length) {
                        return [2, $season, 0];
                    }
                    if ($word[1 + $run] === 'e') {
                        $episodeRun = strspn($word, '0123456789', 2 + $run);
                        if ($episodeRun > 0) {
                            return [1, $season, (int) substr($word, 2 + $run, min($episodeRun, 4))];
                        }
                    }
                }
            }
            $run = strspn($word, '0123456789');
            if ($run >= 1 && $run <= 2 && $run < $length && $word[$run] === 'x' && ($episode = self::w63Digits(substr($word, $run + 1), 2, 3)) !== null) {
                return [4, (int) substr($word, 0, $run), $episode];
            }
            if (self::w63Year($word) !== null && $k + 2 < $count) {
                $month = self::w63Digits($words[$k + 1], 1, 2);
                $day = self::w63Digits($words[$k + 2], 1, 2);
                if ($month !== null && $day !== null && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    return [3, 0, 0];
                }
            }
        }

        return [0, 0, 0];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w63Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w63Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w63Digits($text, 4, 4) : null;
    }

    /**
     * (0 empty, 1 a trimmed `[+-]?\d{1,18}`, 2 other; its signed value).
     *
     * @return array{int, int}
     */
    private static function w63IntState(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $negative = $text[0] === '-';
        $value = self::w63Digits($negative || $text[0] === '+' ? substr($text, 1) : $text, 1, 18);

        return $value === null ? [2, 0] : [1, $negative ? -$value : $value];
    }

    /**
     * (0 empty, 1 positive, 2 other; the value) for w63IntState().
     *
     * @return array{int, int}
     */
    private static function w63PositiveState(string $text): array
    {
        [$state, $value] = self::w63IntState($text);

        return $state === 0 ? [0, 0] : ($state === 1 && $value > 0 ? [1, $value] : [2, 0]);
    }

    /** 0 empty, 1 a trimmed `YYYY-MM-DD` (`-`, `.` or `/` twice) in 1900-2099, 2 other. */
    private static function w63AirState(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        if (\strlen($text) !== 10 || ! \in_array($text[4], ['-', '.', '/'], true) || $text[7] !== $text[4]) {
            return 2;
        }
        $y = self::w63Digits(substr($text, 0, 4), 4, 4);
        $m = self::w63Digits(substr($text, 5, 2), 2, 2);
        $d = self::w63Digits(substr($text, 8, 2), 2, 2);

        return $y !== null && $m !== null && $d !== null && $y >= 1900 && $y <= 2099 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31 ? 1 : 2;
    }

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w633', self::W63_TV_RELEASE_CONTEXT_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w63RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 63.3 TvReleaseContext export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_TV_RELEASE_CONTEXT_FFI_CDEF.)
            $written = $ffi->nntmux_w633_tv_release_context_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
