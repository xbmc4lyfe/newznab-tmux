<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
