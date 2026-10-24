<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\GamesInfo;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Support\Facades\Log;
use Stringable;
use Throwable;

class GamesInfoObserver
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_games_info_observer_doc_id(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, int64_t *out_doc_id);
        int32_t nntmux_games_info_observer_releasedate_ts(const char *input_ptr, size_t input_len, uint32_t has_input, uint32_t tz_is_utc, int64_t *out_ts);
        ssize_t nntmux_games_info_observer_search_doc(const char *ser_ptr, size_t ser_len, uint32_t tz_is_utc, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustSecondaryIndexObserversMetadataParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
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
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Resolve the integer secondary index document ID for a `GamesInfo` model.
     */
    public static function resolveDocumentId(GamesInfo $gamesInfo): int
    {
        return self::usesRustFfi()
            ? self::resolveDocumentIdNative($gamesInfo)
            : self::resolveDocumentIdPhp($gamesInfo);
    }

    public static function resolveDocumentIdNative(GamesInfo $gamesInfo): int
    {
        return self::resolveDocumentIdFromAttributesNative($gamesInfo->getAttributes());
    }

    public static function resolveDocumentIdPhp(GamesInfo $gamesInfo): int
    {
        return self::resolveDocumentIdFromAttributesPhp($gamesInfo->getAttributes());
    }

    /**
     * Resolve the integer secondary index document ID from raw `GamesInfo` attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributes(array $attributes): int
    {
        return self::usesRustFfi()
            ? self::resolveDocumentIdFromAttributesNative($attributes)
            : self::resolveDocumentIdFromAttributesPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributesNative(array $attributes): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }
        $rawId = $attributes['id'] ?? null;
        if ($rawId === null) {
            $idKind = 0;
            $idInt = 0;
            $idStr = '';
        } elseif (\is_int($rawId) || \is_bool($rawId)) {
            $idKind = 1;
            $idInt = (int) $rawId;
            $idStr = '';
        } elseif (\is_string($rawId)) {
            $idKind = 2;
            $idInt = 0;
            $idStr = $rawId;
        } else {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }

        try {
            $outDocId = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_games_info_observer_doc_id(
                $idKind,
                $idInt,
                $idStr,
                \strlen($idStr),
                $outDocId,
            );

            return $rc === 1 && isset($outDocId[0]) && \is_int($outDocId[0])
                ? $outDocId[0]
                : self::resolveDocumentIdFromAttributesPhp($attributes);
        } catch (Throwable) {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributesPhp(array $attributes): int
    {
        return (int) ($attributes['id'] ?? 0);
    }

    /**
     * Convert a `releasedate` attribute value into a Unix timestamp (`int`).
     */
    public static function releasedateToTimestamp(mixed $releasedate): int
    {
        return self::usesRustFfi()
            ? self::releasedateToTimestampNative($releasedate)
            : self::releasedateToTimestampPhp($releasedate);
    }

    public static function releasedateToTimestampNative(mixed $releasedate): int
    {
        if ($releasedate instanceof Carbon) {
            return $releasedate->getTimestamp();
        }
        if (($ffi = self::ffi()) === null) {
            return self::releasedateToTimestampPhp($releasedate);
        }
        if ($releasedate === null || $releasedate === '') {
            $hasInput = 0;
            $str = '';
        } elseif (\is_string($releasedate) || \is_int($releasedate) || \is_float($releasedate) || $releasedate instanceof Stringable) {
            $str = (string) $releasedate;
            $hasInput = $str === '' ? 0 : 1;
        } else {
            return self::releasedateToTimestampPhp($releasedate);
        }

        try {
            $outTs = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_games_info_observer_releasedate_ts(
                $str,
                \strlen($str),
                $hasInput,
                self::isUtcTimezone() ? 1 : 0,
                $outTs,
            );

            return $rc === 1 && isset($outTs[0]) && \is_int($outTs[0])
                ? $outTs[0]
                : self::releasedateToTimestampPhp($releasedate);
        } catch (Throwable) {
            return self::releasedateToTimestampPhp($releasedate);
        }
    }

    public static function releasedateToTimestampPhp(mixed $releasedate): int
    {
        if ($releasedate instanceof Carbon) {
            return $releasedate->getTimestamp();
        }
        if ($releasedate !== null && $releasedate !== '') {
            try {
                return Carbon::parse((string) $releasedate)->getTimestamp();
            } catch (Throwable) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * Build the normalized 4-key games search document passed to `Search::insertSecondary()`.
     *
     * @return array<string, mixed>
     */
    public static function buildSearchDocument(GamesInfo $gamesInfo): array
    {
        return self::usesRustFfi()
            ? self::buildSearchDocumentNative($gamesInfo)
            : self::buildSearchDocumentPhp($gamesInfo);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentNative(GamesInfo $gamesInfo): array
    {
        return self::normalizeSearchDocumentNative(self::extractAttributes($gamesInfo));
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentPhp(GamesInfo $gamesInfo): array
    {
        return self::normalizeSearchDocumentPhp(self::extractAttributes($gamesInfo));
    }

    /**
     * Normalize raw `GamesInfo` attributes into the 4-key secondary search document.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocument(array $attributes): array
    {
        return self::usesRustFfi()
            ? self::normalizeSearchDocumentNative($attributes)
            : self::normalizeSearchDocumentPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentNative(array $attributes): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        $remaining = 256;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        try {
            $serialized = serialize($attributes);
            $serLen = \strlen($serialized);
            $tzIsUtc = self::isUtcTimezone() ? 1 : 0;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $serLen + 256), $capacity);
            if ($buffer === null) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_games_info_observer_search_doc($serialized, $serLen, $tzIsUtc, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeSearchDocumentPhp($attributes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_games_info_observer_search_doc($serialized, $serLen, $tzIsUtc, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizeSearchDocumentPhp($attributes);

            return $result;
        } catch (Throwable) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentPhp(array $attributes): array
    {
        return [
            'title' => (string) ($attributes['title'] ?? ''),
            'genres_id' => (int) ($attributes['genres_id'] ?? 0),
            'releasedate_ts' => self::releasedateToTimestampPhp($attributes['releasedate'] ?? null),
            'cover' => (int) ($attributes['cover'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractAttributes(GamesInfo $gamesInfo): array
    {
        $raw = $gamesInfo->getAttributes();

        return [
            'title' => $raw['title'] ?? null,
            'genres_id' => $raw['genres_id'] ?? null,
            'releasedate' => $raw['releasedate'] ?? null,
            'cover' => $raw['cover'] ?? null,
        ];
    }

    private static function isUtcTimezone(): bool
    {
        $tz = date_default_timezone_get();

        return $tz === 'UTC' || $tz === '+00:00' || $tz === 'Z';
    }

    private static function isSafeSerializableValue(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 16) {
            return false;
        }
        if (\is_array($value)) {
            foreach ($value as $v) {
                if (! self::isSafeSerializableValue($v, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_int($value) || \is_string($value) || \is_bool($value);
    }

    public function created(GamesInfo $gamesInfo): void
    {
        $this->sync($gamesInfo);
    }

    public function updated(GamesInfo $gamesInfo): void
    {
        $this->sync($gamesInfo);
    }

    public function deleted(GamesInfo $gamesInfo): void
    {
        try {
            Search::deleteSecondary(SecondarySearchIndex::Games, self::resolveDocumentId($gamesInfo));
        } catch (Throwable $e) {
            Log::error('GamesInfoObserver: delete from search index failed', [
                'id' => $gamesInfo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sync(GamesInfo $gamesInfo): void
    {
        try {
            Search::insertSecondary(
                SecondarySearchIndex::Games,
                self::resolveDocumentId($gamesInfo),
                self::buildSearchDocument($gamesInfo)
            );
        } catch (Throwable $e) {
            Log::error('GamesInfoObserver: sync to search index failed', [
                'id' => $gamesInfo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
