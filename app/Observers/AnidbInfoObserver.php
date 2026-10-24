<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Facades\Log;

/**
 * When AniList-backed metadata changes, refresh all title rows for that anime in the search index.
 */
class AnidbInfoObserver
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_anidb_info_observer_normalize_title_row(
    int64_t anidb_id,
    const char *title_type,
    size_t title_type_len,
    const char *lang,
    size_t lang_len,
    const char *title,
    size_t title_len,
    int64_t *out_doc_id,
    int32_t *out_valid
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

    public function __construct(
        private readonly AnidbTitleObserver $titleObserver
    ) {}

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(\FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function created(AnidbInfo $anidbInfo): void
    {
        $this->reindexTitles($anidbInfo);
    }

    public function updated(AnidbInfo $anidbInfo): void
    {
        $this->reindexTitles($anidbInfo);
    }

    public function deleted(AnidbInfo $anidbInfo): void
    {
        try {
            foreach (AnidbTitle::query()->where('anidbid', $anidbInfo->anidbid)->cursor() as $titleRow) {
                $normalized = self::normalizeAnidbTitleRow(
                    (int) $titleRow->anidbid,
                    (string) $titleRow->type,
                    (string) $titleRow->lang,
                    (string) $titleRow->title
                );
                Search::deleteSecondary(SecondarySearchIndex::Anime, $normalized['document_id']);
            }
        } catch (\Throwable $e) {
            Log::error('AnidbInfoObserver: delete from search index failed', [
                'anidbid' => $anidbInfo->anidbid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{anidbid: int, type: string, lang: string, title: string, document_id: int, valid: bool}
     */
    public static function normalizeAnidbTitleRow(
        int $anidbId,
        string $type,
        string $lang,
        string $title
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeAnidbTitleRowNative($anidbId, $type, $lang, $title);
        }

        return self::normalizeAnidbTitleRowPhp($anidbId, $type, $lang, $title);
    }

    /**
     * @return array{anidbid: int, type: string, lang: string, title: string, document_id: int, valid: bool}
     */
    public static function normalizeAnidbTitleRowPhp(
        int $anidbId,
        string $type,
        string $lang,
        string $title
    ): array {
        return [
            'anidbid' => $anidbId,
            'type' => $type,
            'lang' => $lang,
            'title' => $title,
            'document_id' => SecondarySearchIndex::animeTitleDocumentId($anidbId, $type, $lang, $title),
            'valid' => $anidbId > 0 && $title !== '',
        ];
    }

    /**
     * @return array{anidbid: int, type: string, lang: string, title: string, document_id: int, valid: bool}
     */
    public static function normalizeAnidbTitleRowNative(
        int $anidbId,
        string $type,
        string $lang,
        string $title
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::normalizeAnidbTitleRowPhp($anidbId, $type, $lang, $title);
        }

        try {
            $outDocId = $ffi->new('int64_t[1]');
            $outValid = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_anidb_info_observer_normalize_title_row(
                $anidbId,
                $type,
                strlen($type),
                $lang,
                strlen($lang),
                $title,
                strlen($title),
                $outDocId,
                $outValid
            );
            $docId = $outDocId[0];
            $validFlag = $outValid[0];
            if ($rc !== 1 || ! is_int($docId) || ! is_int($validFlag)) {
                return self::normalizeAnidbTitleRowPhp($anidbId, $type, $lang, $title);
            }

            return [
                'anidbid' => $anidbId,
                'type' => $type,
                'lang' => $lang,
                'title' => $title,
                'document_id' => $docId,
                'valid' => $validFlag === 1,
            ];
        } catch (\Throwable) {
            return self::normalizeAnidbTitleRowPhp($anidbId, $type, $lang, $title);
        }
    }

    private function reindexTitles(AnidbInfo $anidbInfo): void
    {
        try {
            foreach (AnidbTitle::query()->where('anidbid', $anidbInfo->anidbid)->cursor() as $titleRow) {
                $this->titleObserver->upsert($titleRow);
            }
        } catch (\Throwable $e) {
            Log::error('AnidbInfoObserver: reindex titles failed', [
                'anidbid' => $anidbInfo->anidbid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function additionalFfi(): ?\FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
