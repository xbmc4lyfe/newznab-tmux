<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Lookup states stored in releases.tv_episodes_id while no episode is linked.
 *
 * Positive values are tv_episodes ids. The values deliberately avoid the legacy
 * -1..-4 / -6 provider hand-off codes so `tv:repair-links --legacy` can tell them apart.
 */
enum TvLookupStatus: int
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_lookup_status_classify(int64_t value);
        ssize_t nntmux_tv_lookup_status_text(int64_t value, uint32_t kind, char *out_buf, size_t out_cap);
        ssize_t nntmux_tv_lookup_status_reprocessable_values(uint32_t include_legacy_pending, uint32_t include_legacy_exhausted, uint32_t include_show_not_found, uint32_t include_parse_failed, int64_t *out_values, size_t values_cap);
        C;

    public const int FLAG_KNOWN_ENUM = 1 << 0;

    public const int FLAG_RETRYABLE = 1 << 1;

    public const int FLAG_TERMINAL = 1 << 2;

    public const int FLAG_REQUIRES_SHOW = 1 << 3;

    public const int FLAG_MATCHED_EPISODE = 1 << 4;

    public const int FLAG_LEGACY_PENDING = 1 << 5;

    public const int FLAG_LEGACY_EXHAUSTED = 1 << 6;

    /** Waiting for the bucket workers (transient failures also wait here until tv_lookup_retry_at). */
    case Pending = 0;

    /** Every provider confirmed it has no matching show. */
    case ShowNotFound = -10;

    /** The show is linked but the episode is not available yet; retried on a schedule. */
    case EpisodeMissing = -11;

    /** Full-season release linked to its show by design. */
    case SeasonPack = -12;

    /** The release name could not be parsed into show and episode information. */
    case ParseFailed = -100;

    /** Legacy statuses that meant "waiting for the next provider". */
    public const array LEGACY_PENDING = [-1, -2, -3];

    /** Legacy statuses that meant "every provider gave up". */
    public const array LEGACY_EXHAUSTED = [-4, -6];

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    /**
     * Get short human-readable label for this lookup status.
     */
    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelNative(): string
    {
        return $this->textNative(0) ?? $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::ShowNotFound => 'Show Not Found',
            self::EpisodeMissing => 'Episode Missing',
            self::SeasonPack => 'Season Pack',
            self::ParseFailed => 'Parse Failed',
        };
    }

    /**
     * Get detailed description of this lookup status.
     */
    public function description(): string
    {
        return self::usesRustFfi() ? $this->descriptionNative() : $this->descriptionPhp();
    }

    public function descriptionNative(): string
    {
        return $this->textNative(1) ?? $this->descriptionPhp();
    }

    public function descriptionPhp(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for TV lookup worker or scheduled retry',
            self::ShowNotFound => 'No TV provider matched the parsed show',
            self::EpisodeMissing => 'Show matched; waiting for episode availability',
            self::SeasonPack => 'Full-season pack linked to show without single episode',
            self::ParseFailed => 'Release name could not be parsed into show and episode',
        };
    }

    /**
     * Check whether this lookup status is scheduled for automatic retry.
     */
    public function isRetryable(): bool
    {
        return self::usesRustFfi() ? $this->isRetryableNative() : $this->isRetryablePhp();
    }

    public function isRetryableNative(): bool
    {
        $flags = self::classifyNativeRaw($this->value);
        if ($flags === null) {
            return $this->isRetryablePhp();
        }

        return ($flags & self::FLAG_RETRYABLE) !== 0;
    }

    public function isRetryablePhp(): bool
    {
        return $this === self::Pending || $this === self::EpisodeMissing;
    }

    /**
     * Check whether this lookup status is terminal (not retried automatically).
     */
    public function isTerminal(): bool
    {
        return self::usesRustFfi() ? $this->isTerminalNative() : $this->isTerminalPhp();
    }

    public function isTerminalNative(): bool
    {
        $flags = self::classifyNativeRaw($this->value);
        if ($flags === null) {
            return $this->isTerminalPhp();
        }

        return ($flags & self::FLAG_TERMINAL) !== 0;
    }

    public function isTerminalPhp(): bool
    {
        return match ($this) {
            self::ShowNotFound, self::SeasonPack, self::ParseFailed => true,
            self::Pending, self::EpisodeMissing => false,
        };
    }

    /**
     * Check whether this status expects `releases.videos_id` to link to a matched show.
     */
    public function requiresLinkedShow(): bool
    {
        return self::usesRustFfi() ? $this->requiresLinkedShowNative() : $this->requiresLinkedShowPhp();
    }

    public function requiresLinkedShowNative(): bool
    {
        $flags = self::classifyNativeRaw($this->value);
        if ($flags === null) {
            return $this->requiresLinkedShowPhp();
        }

        return ($flags & self::FLAG_REQUIRES_SHOW) !== 0;
    }

    public function requiresLinkedShowPhp(): bool
    {
        return $this === self::EpisodeMissing || $this === self::SeasonPack;
    }

    /**
     * Classify any stored `releases.tv_episodes_id` integer into a bitmask of `FLAG_*` constants.
     */
    public static function classifyValue(int $value): int
    {
        return self::usesRustFfi()
            ? self::classifyValueNative($value)
            : self::classifyValuePhp($value);
    }

    public static function classifyValueNative(int $value): int
    {
        return self::classifyNativeRaw($value) ?? self::classifyValuePhp($value);
    }

    public static function classifyValuePhp(int $value): int
    {
        if ($value > 0) {
            return self::FLAG_MATCHED_EPISODE;
        }

        return match ($value) {
            0 => self::FLAG_KNOWN_ENUM | self::FLAG_RETRYABLE,
            -11 => self::FLAG_KNOWN_ENUM | self::FLAG_RETRYABLE | self::FLAG_REQUIRES_SHOW,
            -10, -100 => self::FLAG_KNOWN_ENUM | self::FLAG_TERMINAL,
            -12 => self::FLAG_KNOWN_ENUM | self::FLAG_TERMINAL | self::FLAG_REQUIRES_SHOW,
            -1, -2, -3 => self::FLAG_LEGACY_PENDING,
            -4, -6 => self::FLAG_LEGACY_EXHAUSTED,
            default => 0,
        };
    }

    /**
     * Check whether a stored `releases.tv_episodes_id` value refers to a matched `tv_episodes.id`.
     */
    public static function isMatchedEpisodeId(int $value): bool
    {
        return self::usesRustFfi()
            ? self::isMatchedEpisodeIdNative($value)
            : self::isMatchedEpisodeIdPhp($value);
    }

    public static function isMatchedEpisodeIdNative(int $value): bool
    {
        $flags = self::classifyNativeRaw($value);
        if ($flags === null) {
            return self::isMatchedEpisodeIdPhp($value);
        }

        return ($flags & self::FLAG_MATCHED_EPISODE) !== 0;
    }

    public static function isMatchedEpisodeIdPhp(int $value): bool
    {
        return $value > 0;
    }

    /**
     * Check whether a stored `releases.tv_episodes_id` value is a legacy pending provider hand-off code.
     */
    public static function isLegacyPending(int $value): bool
    {
        return self::usesRustFfi()
            ? self::isLegacyPendingNative($value)
            : self::isLegacyPendingPhp($value);
    }

    public static function isLegacyPendingNative(int $value): bool
    {
        $flags = self::classifyNativeRaw($value);
        if ($flags === null) {
            return self::isLegacyPendingPhp($value);
        }

        return ($flags & self::FLAG_LEGACY_PENDING) !== 0;
    }

    public static function isLegacyPendingPhp(int $value): bool
    {
        return \in_array($value, self::LEGACY_PENDING, true);
    }

    /**
     * Check whether a stored `releases.tv_episodes_id` value is a legacy exhausted provider code.
     */
    public static function isLegacyExhausted(int $value): bool
    {
        return self::usesRustFfi()
            ? self::isLegacyExhaustedNative($value)
            : self::isLegacyExhaustedPhp($value);
    }

    public static function isLegacyExhaustedNative(int $value): bool
    {
        $flags = self::classifyNativeRaw($value);
        if ($flags === null) {
            return self::isLegacyExhaustedPhp($value);
        }

        return ($flags & self::FLAG_LEGACY_EXHAUSTED) !== 0;
    }

    public static function isLegacyExhaustedPhp(int $value): bool
    {
        return \in_array($value, self::LEGACY_EXHAUSTED, true);
    }

    /**
     * Normalize a stored or legacy `releases.tv_episodes_id` status value into a `TvLookupStatus` case.
     */
    public static function normalizeLegacyStatus(int $value): ?self
    {
        return self::usesRustFfi()
            ? self::normalizeLegacyStatusNative($value)
            : self::normalizeLegacyStatusPhp($value);
    }

    public static function normalizeLegacyStatusNative(int $value): ?self
    {
        $flags = self::classifyNativeRaw($value);
        if ($flags === null) {
            return self::normalizeLegacyStatusPhp($value);
        }
        if (($flags & self::FLAG_KNOWN_ENUM) !== 0) {
            return self::tryFrom($value);
        }
        if (($flags & self::FLAG_LEGACY_PENDING) !== 0) {
            return self::Pending;
        }
        if (($flags & self::FLAG_LEGACY_EXHAUSTED) !== 0) {
            return self::ShowNotFound;
        }

        return null;
    }

    public static function normalizeLegacyStatusPhp(int $value): ?self
    {
        $known = self::tryFrom($value);
        if ($known !== null) {
            return $known;
        }
        if (\in_array($value, self::LEGACY_PENDING, true)) {
            return self::Pending;
        }
        if (\in_array($value, self::LEGACY_EXHAUSTED, true)) {
            return self::ShowNotFound;
        }

        return null;
    }

    /**
     * Build the ordered list of `releases.tv_episodes_id` status values eligible for reprocessing.
     *
     * @return list<int>
     */
    public static function reprocessableValues(
        bool $includeLegacyPending = true,
        bool $includeLegacyExhausted = false,
        bool $includeShowNotFound = false,
        bool $includeParseFailed = false,
    ): array {
        return self::usesRustFfi()
            ? self::reprocessableValuesNative(
                $includeLegacyPending,
                $includeLegacyExhausted,
                $includeShowNotFound,
                $includeParseFailed,
            )
            : self::reprocessableValuesPhp(
                $includeLegacyPending,
                $includeLegacyExhausted,
                $includeShowNotFound,
                $includeParseFailed,
            );
    }

    /**
     * @return list<int>
     */
    public static function reprocessableValuesNative(
        bool $includeLegacyPending = true,
        bool $includeLegacyExhausted = false,
        bool $includeShowNotFound = false,
        bool $includeParseFailed = false,
    ): array {
        if (($ffi = self::ffi()) === null) {
            return self::reprocessableValuesPhp(
                $includeLegacyPending,
                $includeLegacyExhausted,
                $includeShowNotFound,
                $includeParseFailed,
            );
        }
        try {
            $outValues = $ffi->new('int64_t[12]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_lookup_status_reprocessable_values(
                $includeLegacyPending ? 1 : 0,
                $includeLegacyExhausted ? 1 : 0,
                $includeShowNotFound ? 1 : 0,
                $includeParseFailed ? 1 : 0,
                $outValues,
                12,
            );
            if (\is_int($written) && $written >= 2 && $written <= 12) {
                $result = [];
                for ($i = 0; $i < $written; $i++) {
                    $result[] = (int) $outValues[$i];
                }

                return $result;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::reprocessableValuesPhp(
            $includeLegacyPending,
            $includeLegacyExhausted,
            $includeShowNotFound,
            $includeParseFailed,
        );
    }

    /**
     * @return list<int>
     */
    public static function reprocessableValuesPhp(
        bool $includeLegacyPending = true,
        bool $includeLegacyExhausted = false,
        bool $includeShowNotFound = false,
        bool $includeParseFailed = false,
    ): array {
        $values = [self::Pending->value, self::EpisodeMissing->value];
        if ($includeLegacyPending) {
            foreach (self::LEGACY_PENDING as $code) {
                $values[] = $code;
            }
        }
        if ($includeLegacyExhausted) {
            foreach (self::LEGACY_EXHAUSTED as $code) {
                $values[] = $code;
            }
        }
        if ($includeShowNotFound) {
            $values[] = self::ShowNotFound->value;
        }
        if ($includeParseFailed) {
            $values[] = self::ParseFailed->value;
        }

        return $values;
    }

    private function textNative(int $kind): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_lookup_status_text($this->value, $kind, $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function classifyNativeRaw(int $value): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_tv_lookup_status_classify($value);
        } catch (Throwable) {
            return null;
        }

        return \is_int($code) && $code >= 0 ? $code : null;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W63.6 benchmark clears 1.5x on all four metrics.
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_TV_FFI_CDEF = <<<'C'
        int32_t nntmux_w636_tv_lookup_status_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t max_attempts, int64_t *out, size_t out_cap);
        C;

    public const int W63_TV_HEADER = 25;

    private const int W63_MAX_ITEMS = 1_048_576;

    /** Attempt clamp (2^16 - 1), elapsed clamp (2^40) and cooldown cap (7 days). */
    private const int W63_ATTEMPT_CAP = 65_535;

    private const int W63_ELAPSED_CAP = 1_099_511_627_776;

    private const int W63_COOLDOWN_CAP = 604_800;

    /** Attempt limit used for a limit at or below 0. */
    private const int W63_TV_ATTEMPTS = 10;

    /** Non-positive stored value => case. */
    private const array W63_TV_VALUES = [0 => 0, -10 => 1, -11 => 2, -12 => 3, -100 => 4, -1 => 6, -2 => 6, -3 => 6, -4 => 7, -6 => 7];

    /** `classifyValuePhp()` flags per case. */
    private const array W63_TV_FLAGS = [3, 5, 11, 13, 5, 16, 32, 64, 0];

    /** Case name => case. */
    private const array W63_TV_NAMES = ['Pending' => 0, 'ShowNotFound' => 1, 'EpisodeMissing' => 2, 'SeasonPack' => 3, 'ParseFailed' => 4];

    /** Lowercased trimmed alias => case. */
    private const array W63_TV_ALIASES = [
        'pending' => 0, 'waiting' => 0, 'queued' => 0,
        'show_not_found' => 1, 'show-not-found' => 1, 'not_found' => 1, 'notfound' => 1,
        'episode_missing' => 2, 'episode-missing' => 2, 'no_episode' => 2,
        'season_pack' => 3, 'season-pack' => 3, 'pack' => 3,
        'parse_failed' => 4, 'parse-failed' => 4, 'unparseable' => 4, 'unparsable' => 4,
    ];

    /**
     * Plan TV lookup retries in order. A canonical integer status (`(string) (int) $s === $s`) classifies by
     * value (source 0); otherwise by case name (1), an alias of `strtolower(trim($status))` (2), else unknown
     * text (3). Cases 0..4 are Pending, ShowNotFound, EpisodeMissing, SeasonPack and ParseFailed, 5 a matched
     * episode id, 6 legacy pending (normalized to Pending), 7 legacy exhausted (normalized to ShowNotFound), 8
     * unknown. Attempts and the limit (at or below 0 means 10) clamp to 0..2^16-1, seconds since the last
     * attempt to 0..2^40. Action 0 skip (matched), 1 dispatch, 2 cooldown, 3 give up (Pending becomes
     * ShowNotFound, EpisodeMissing parks), 4 terminal, 5 invalid. A retryable status's cooldown is 0 before its
     * first attempt, else its base (Pending 900, EpisodeMissing 21,600 seconds) doubled per further attempt up
     * to 6 times, capped at 7 days. Target 15 when none.
     *
     * Layout: `[0]` count, `[1..9]` per case, `[10..13]` per source, `[14..19]` per action, `[20]` normalized,
     * `[21]` shortest wait (-1), `[22]` first invalid (-1), `[23]` total attempts, `[24]` FNV-1a digest; then
     * per status `case | source << 4 | flags << 6 | action << 13 | target << 16 | normalized << 20 | wait << 21
     * | attempts << 41 | min(token bytes, 63) << 57`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $elapsed
     * @return list<int>
     */
    public static function w63TvLookupStatusPlanBatch(array $statuses, array $attempts, array $elapsed, int $maxAttempts = self::W63_TV_ATTEMPTS): array
    {
        return (self::usesW63RustFfi() ? self::w63TvLookupStatusPlanBatchNative($statuses, $attempts, $elapsed, $maxAttempts) : null)
            ?? self::w63TvLookupStatusPlanBatchPhp($statuses, $attempts, $elapsed, $maxAttempts);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $elapsed
     * @return list<int>
     */
    public static function w63TvLookupStatusPlanBatchPhp(array $statuses, array $attempts, array $elapsed, int $maxAttempts = self::W63_TV_ATTEMPTS): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $attempts = self::w63Column($attempts, $count);
        $elapsed = self::w63Column($elapsed, $count);
        $limit = $maxAttempts <= 0 ? self::W63_TV_ATTEMPTS : min($maxAttempts, self::W63_ATTEMPT_CAP);
        $out = array_fill(0, self::W63_TV_HEADER, 0);
        $out[0] = $count;
        $out[21] = $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            if (\is_int($status)) {
                $value = $status;
                $status = (string) $status;
                $numeric = true;
            } else {
                $status = (string) $status;
                $value = (int) $status;
                $numeric = (string) $value === $status;
            }
            if ($numeric) {
                $case = $value > 0 ? 5 : (self::W63_TV_VALUES[$value] ?? 8);
                $source = 0;
            } else {
                $case = self::W63_TV_NAMES[$status] ?? null;
                $source = 1;
                if ($case === null) {
                    $case = self::W63_TV_ALIASES[strtolower(trim($status))] ?? 8;
                    $source = $case === 8 ? 3 : 2;
                }
            }
            $tries = max(0, min((int) $attempts[$at], self::W63_ATTEMPT_CAP));
            $since = max(0, min((int) $elapsed[$at], self::W63_ELAPSED_CAP));
            $base = match ($case) {
                6 => 0,
                7 => 1,
                default => $case,
            };
            $normalized = $case === 6 || $case === 7 ? 1 : 0;
            $wait = 0;
            if ($base === 5) {
                $action = 0;
                $target = 15;
            } elseif ($base === 8) {
                $action = 5;
                $target = 15;
            } elseif ($base === 1 || $base === 3 || $base === 4) {
                $action = 4;
                $target = $base;
            } elseif ($tries >= $limit) {
                $action = 3;
                $target = $base === 0 ? 1 : 2;
            } else {
                $cooldown = $tries === 0 ? 0 : min(($base === 0 ? 900 : 21_600) << min($tries - 1, 6), self::W63_COOLDOWN_CAP);
                $target = $base;
                if ($since >= $cooldown) {
                    $action = 1;
                } else {
                    $action = 2;
                    $wait = $cooldown - $since;
                }
            }
            $out[1 + $case]++;
            $out[10 + $source]++;
            $out[14 + $action]++;
            $out[20] += $normalized;
            if ($wait > 0 && ($out[21] < 0 || $wait < $out[21])) {
                $out[21] = $wait;
            }
            if ($action === 5 && $out[22] < 0) {
                $out[22] = $at;
            }
            $out[23] += $tries;
            $row = $case | $source << 4 | self::W63_TV_FLAGS[$case] << 6 | $action << 13 | $target << 16 | $normalized << 20 | $wait << 21 | $tries << 41
                | min(\strlen($status), 63) << 57;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[24] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $elapsed
     * @return list<int>|null
     */
    public static function w63TvLookupStatusPlanBatchNative(array $statuses, array $attempts, array $elapsed, int $maxAttempts = self::W63_TV_ATTEMPTS): ?array
    {
        $count = \count($statuses);
        if ($count > self::W63_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w63Strings(array_values($statuses));
        $ints = pack('q*', ...self::w63Column($attempts, $count), ...self::w63Column($elapsed, $count));
        $words = self::W63_TV_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w63Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w636_tv_lookup_status_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $maxAttempts, $out, $words));
    }

    /**
     * Route the Wave 63.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /**
     * Route the Wave 63.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 63.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $forced = self::w63RustFfiState();

        return \is_bool($forced) ? $forced : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 63.6 Rust batch path forced on or off, then restore the previous routing.
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
     * Wave 63.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w636', self::W63_TV_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 63.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w63Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w63RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w63Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w63Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
