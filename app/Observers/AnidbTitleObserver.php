<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnidbTitleObserver
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_anidb_title_observer_doc_id(int64_t anidbid, const char *type_ptr, size_t type_len, const char *lang_ptr, size_t lang_len, const char *title_ptr, size_t title_len);
        ssize_t nntmux_anidb_title_observer_search_doc(const char *title_ser_ptr, size_t title_ser_len, const char *info_ser_ptr, size_t info_ser_len, char *out_buf, size_t out_cap);
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
     * Compute the unsigned CRC32 document ID for a composite `(anidbid, type, lang, title)` row.
     */
    public static function computeDocumentId(int $anidbid, string $type, string $lang, string $title): int
    {
        return self::usesRustFfi()
            ? self::computeDocumentIdNative($anidbid, $type, $lang, $title)
            : self::computeDocumentIdPhp($anidbid, $type, $lang, $title);
    }

    public static function computeDocumentIdNative(int $anidbid, string $type, string $lang, string $title): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::computeDocumentIdPhp($anidbid, $type, $lang, $title);
        }
        try {
            // @phpstan-ignore method.notFound
            $docId = $ffi->nntmux_anidb_title_observer_doc_id(
                $anidbid,
                $type,
                \strlen($type),
                $lang,
                \strlen($lang),
                $title,
                \strlen($title),
            );

            return \is_int($docId) && $docId >= 0
                ? $docId
                : self::computeDocumentIdPhp($anidbid, $type, $lang, $title);
        } catch (Throwable) {
            return self::computeDocumentIdPhp($anidbid, $type, $lang, $title);
        }
    }

    public static function computeDocumentIdPhp(int $anidbid, string $type, string $lang, string $title): int
    {
        $payload = $anidbid.'|'.$type.'|'.$lang.'|'.$title;

        return (int) sprintf('%u', crc32($payload));
    }

    /**
     * Resolve the secondary search index document ID for an `AnidbTitle` model.
     */
    public static function resolveDocumentId(AnidbTitle $anidbTitle): int
    {
        return self::usesRustFfi()
            ? self::resolveDocumentIdNative($anidbTitle)
            : self::resolveDocumentIdPhp($anidbTitle);
    }

    public static function resolveDocumentIdNative(AnidbTitle $anidbTitle): int
    {
        return self::resolveDocumentIdFromAttributesNative(self::extractTitleAttributes($anidbTitle));
    }

    public static function resolveDocumentIdPhp(AnidbTitle $anidbTitle): int
    {
        return self::resolveDocumentIdFromAttributesPhp(self::extractTitleAttributes($anidbTitle));
    }

    /**
     * Resolve the secondary search index document ID from raw `AnidbTitle` attributes.
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
        return self::computeDocumentIdNative(
            (int) ($attributes['anidbid'] ?? 0),
            (string) ($attributes['type'] ?? ''),
            (string) ($attributes['lang'] ?? ''),
            (string) ($attributes['title'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributesPhp(array $attributes): int
    {
        return self::computeDocumentIdPhp(
            (int) ($attributes['anidbid'] ?? 0),
            (string) ($attributes['type'] ?? ''),
            (string) ($attributes['lang'] ?? ''),
            (string) ($attributes['title'] ?? ''),
        );
    }

    /**
     * Build the normalized 8-key secondary search document for an `AnidbTitle` row.
     *
     * @return array<string, mixed>
     */
    public static function buildSearchDocument(
        AnidbTitle $anidbTitle,
        ?AnidbInfo $info = null,
        bool $resolveMissingInfo = true,
    ): array {
        return self::usesRustFfi()
            ? self::buildSearchDocumentNative($anidbTitle, $info, $resolveMissingInfo)
            : self::buildSearchDocumentPhp($anidbTitle, $info, $resolveMissingInfo);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentNative(
        AnidbTitle $anidbTitle,
        ?AnidbInfo $info = null,
        bool $resolveMissingInfo = true,
    ): array {
        $resolvedInfo = self::resolveAnidbInfo($anidbTitle, $info, $resolveMissingInfo);

        return self::normalizeSearchDocumentNative(
            self::extractTitleAttributes($anidbTitle),
            self::extractInfoAttributes($resolvedInfo),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentPhp(
        AnidbTitle $anidbTitle,
        ?AnidbInfo $info = null,
        bool $resolveMissingInfo = true,
    ): array {
        $resolvedInfo = self::resolveAnidbInfo($anidbTitle, $info, $resolveMissingInfo);

        return self::normalizeSearchDocumentPhp(
            self::extractTitleAttributes($anidbTitle),
            self::extractInfoAttributes($resolvedInfo),
        );
    }

    /**
     * Normalize raw `AnidbTitle` and optional `AnidbInfo` attributes into the 8-key search document.
     *
     * @param  array<string, mixed>  $titleAttributes
     * @param  array<string, mixed>  $infoAttributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocument(array $titleAttributes, array $infoAttributes = []): array
    {
        return self::usesRustFfi()
            ? self::normalizeSearchDocumentNative($titleAttributes, $infoAttributes)
            : self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
    }

    /**
     * @param  array<string, mixed>  $titleAttributes
     * @param  array<string, mixed>  $infoAttributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentNative(array $titleAttributes, array $infoAttributes = []): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
        }
        $remaining = 256;
        if (
            ! self::isSafeSerializableValue($titleAttributes, $remaining, 0)
            || ($infoAttributes !== [] && ! self::isSafeSerializableValue($infoAttributes, $remaining, 0))
        ) {
            return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
        }
        try {
            $titleSerialized = serialize($titleAttributes);
            $titleLen = \strlen($titleSerialized);
            $infoSerialized = $infoAttributes === [] ? '' : serialize($infoAttributes);
            $infoLen = \strlen($infoSerialized);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $titleLen + $infoLen + 256), $capacity);
            if ($buffer === null) {
                return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_title_observer_search_doc(
                $titleSerialized,
                $titleLen,
                $infoSerialized,
                $infoLen,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_title_observer_search_doc(
                    $titleSerialized,
                    $titleLen,
                    $infoSerialized,
                    $infoLen,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);

            return $result;
        } catch (Throwable) {
            return self::normalizeSearchDocumentPhp($titleAttributes, $infoAttributes);
        }
    }

    /**
     * @param  array<string, mixed>  $titleAttributes
     * @param  array<string, mixed>  $infoAttributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentPhp(array $titleAttributes, array $infoAttributes = []): array
    {
        return [
            'title' => (string) ($titleAttributes['title'] ?? ''),
            'anidbid' => (int) ($titleAttributes['anidbid'] ?? 0),
            'anilist_id' => (int) ($infoAttributes['anilist_id'] ?? 0),
            'mal_id' => (int) ($infoAttributes['mal_id'] ?? 0),
            'lang' => (string) ($titleAttributes['lang'] ?? ''),
            'title_type' => (string) ($titleAttributes['type'] ?? ''),
            'media_type' => (string) ($infoAttributes['media_type'] ?? ''),
            'status' => (string) ($infoAttributes['status'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractTitleAttributes(AnidbTitle $anidbTitle): array
    {
        $raw = $anidbTitle->getAttributes();

        return [
            'title' => $raw['title'] ?? null,
            'anidbid' => $raw['anidbid'] ?? null,
            'lang' => $raw['lang'] ?? null,
            'type' => $raw['type'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractInfoAttributes(?AnidbInfo $info): array
    {
        if ($info === null) {
            return [];
        }
        $raw = $info->getAttributes();

        return [
            'anilist_id' => $raw['anilist_id'] ?? null,
            'mal_id' => $raw['mal_id'] ?? null,
            'media_type' => $raw['media_type'] ?? null,
            'status' => $raw['status'] ?? null,
        ];
    }

    private static function resolveAnidbInfo(
        AnidbTitle $anidbTitle,
        ?AnidbInfo $info,
        bool $resolveMissingInfo,
    ): ?AnidbInfo {
        if ($info !== null || ! $resolveMissingInfo) {
            return $info;
        }
        if ($anidbTitle->relationLoaded('info')) {
            $loaded = $anidbTitle->getRelation('info');
            if ($loaded instanceof AnidbInfo) {
                return $loaded;
            }
            if ($loaded instanceof Collection) {
                $first = $loaded->first();

                return $first instanceof AnidbInfo ? $first : null;
            }

            return null;
        }

        return AnidbInfo::query()->where('anidbid', $anidbTitle->anidbid)->first();
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

    public function created(AnidbTitle $anidbTitle): void
    {
        $this->upsert($anidbTitle);
    }

    public function updated(AnidbTitle $anidbTitle): void
    {
        $this->upsert($anidbTitle);
    }

    public function upsert(AnidbTitle $anidbTitle): void
    {
        $this->sync($anidbTitle);
    }

    public function deleted(AnidbTitle $anidbTitle): void
    {
        try {
            $docId = self::resolveDocumentId($anidbTitle);
            Search::deleteSecondary(SecondarySearchIndex::Anime, $docId);
        } catch (Throwable $e) {
            Log::error('AnidbTitleObserver: delete from search index failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sync(AnidbTitle $anidbTitle): void
    {
        try {
            $docId = self::resolveDocumentId($anidbTitle);
            Search::insertSecondary(
                SecondarySearchIndex::Anime,
                $docId,
                self::buildSearchDocument($anidbTitle)
            );
        } catch (Throwable $e) {
            Log::error('AnidbTitleObserver: sync to search index failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
