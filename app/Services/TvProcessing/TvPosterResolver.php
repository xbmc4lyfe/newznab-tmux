<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Services\FanartTvService;
use App\Services\TmdbClient;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks a poster URL for a show: the provider's own poster, then TMDB, then Fanart.tv.
 */
class TvPosterResolver
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_poster_extract_show_meta(const char *ser, size_t ser_len, int64_t *out_tmdb, int64_t *out_tvdb, int64_t *out_tvmaze, char *out_buf, size_t out_cap);
        ssize_t nntmux_tv_poster_select_url(const char *p1, size_t p1_len, const char *p2, size_t p2_len, const char *p3, size_t p3_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvIgdbSteamMetadataParityTest). */
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
        private readonly ?TmdbClient $tmdb = null,
        private readonly ?FanartTvService $fanart = null,
    ) {}

    /**
     * @param  array<string, mixed>  $show  AbstractTvProvider::formatShowInfo() output
     */
    public function resolve(array $show): string
    {
        $meta = self::extractShowMetadata($show);
        if ($meta['poster'] !== '') {
            return $meta['poster'];
        }

        try {
            $tmdbId = $meta['tmdb'];
            $tmdb = $this->tmdb ?? app(TmdbClient::class);
            if ($tmdbId > 0 && $tmdb->isConfigured()) {
                $path = TmdbClient::getString($tmdb->getTvShow($tmdbId) ?? [], 'poster_path');
                if ($path !== '') {
                    return $tmdb->getImageUrl($path);
                }
            }

            $tvdbId = $meta['tvdb'];
            $fanart = $this->fanart ?? new FanartTvService;
            if ($tvdbId > 0 && $fanart->isConfigured()) {
                return (string) $fanart->getBestTvPoster($tvdbId);
            }
        } catch (Throwable $e) {
            Log::info('TV poster lookup failed', ['title' => $show['title'] ?? '', 'error' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * Extract candidate poster URL (`poster` then `image`) and normalized provider IDs
     * (`tmdb`, `tvdb`/`thetvdb`, `tvmaze`) from a show metadata array.
     *
     * @param  array<string, mixed>  $show
     * @return array{poster: string, tmdb: int, tvdb: int, tvmaze: int}
     */
    public static function extractShowMetadata(array $show): array
    {
        return self::usesRustFfi()
            ? self::extractShowMetadataNative($show)
            : self::extractShowMetadataPhp($show);
    }

    /**
     * @param  array<string, mixed>  $show
     * @return array{poster: string, tmdb: int, tvdb: int, tvmaze: int}
     */
    public static function extractShowMetadataNative(array $show): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::extractShowMetadataPhp($show);
        }
        try {
            $subset = array_intersect_key($show, [
                'poster' => true,
                'image' => true,
                'tmdb' => true,
                'tvdb' => true,
                'thetvdb' => true,
                'tvmaze' => true,
            ]);
            foreach ($subset as $val) {
                if (\is_object($val)) {
                    return self::extractShowMetadataPhp($show);
                }
            }
            $ser = serialize($subset);
            $serLen = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $serLen + 32), $capacity);
            if ($buffer === null) {
                return self::extractShowMetadataPhp($show);
            }
            $outTmdb = $ffi->new('int64_t[1]');
            $outTvdb = $ffi->new('int64_t[1]');
            $outTvmaze = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_poster_extract_show_meta(
                $ser,
                $serLen,
                $outTmdb,
                $outTvdb,
                $outTvmaze,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::extractShowMetadataPhp($show);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_poster_extract_show_meta(
                    $ser,
                    $serLen,
                    $outTmdb,
                    $outTvdb,
                    $outTvmaze,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::extractShowMetadataPhp($show);
            }

            return [
                'poster' => $written === 0 ? '' : FFI::string($buffer, $written),
                'tmdb' => (int) $outTmdb[0],
                'tvdb' => (int) $outTvdb[0],
                'tvmaze' => (int) $outTvmaze[0],
            ];
        } catch (Throwable) {
            return self::extractShowMetadataPhp($show);
        }
    }

    /**
     * @param  array<string, mixed>  $show
     * @return array{poster: string, tmdb: int, tvdb: int, tvmaze: int}
     */
    public static function extractShowMetadataPhp(array $show): array
    {
        $poster = self::extractPosterFieldPhp($show['poster'] ?? null);
        if ($poster === '') {
            $poster = self::extractPosterFieldPhp($show['image'] ?? null);
        }

        $tvdb = (int) ($show['tvdb'] ?? 0);
        if ($tvdb === 0) {
            $tvdb = (int) ($show['thetvdb'] ?? 0);
        }

        return [
            'poster' => $poster,
            'tmdb' => (int) ($show['tmdb'] ?? 0),
            'tvdb' => $tvdb,
            'tvmaze' => (int) ($show['tvmaze'] ?? 0),
        ];
    }

    private static function extractPosterFieldPhp(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            $original = $value['original'] ?? null;
            if (\is_string($original) && $original !== '') {
                return $original;
            }
            $medium = $value['medium'] ?? null;
            if (\is_string($medium) && $medium !== '') {
                return $medium;
            }

            return '';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Select the highest-priority non-empty poster URL from provider, TMDB, and Fanart.tv candidates.
     */
    public static function selectPosterUrl(
        string $providerPoster,
        string $tmdbPoster = '',
        string $fanartPoster = '',
    ): string {
        return self::usesRustFfi()
            ? self::selectPosterUrlNative($providerPoster, $tmdbPoster, $fanartPoster)
            : self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
    }

    public static function selectPosterUrlNative(
        string $providerPoster,
        string $tmdbPoster = '',
        string $fanartPoster = '',
    ): string {
        if (($ffi = self::ffi()) === null) {
            return self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
        }
        $l1 = \strlen($providerPoster);
        $l2 = \strlen($tmdbPoster);
        $l3 = \strlen($fanartPoster);
        $maxLen = max($l1, $l2, $l3);
        if ($maxLen === 0) {
            return '';
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $maxLen + 16), $capacity);
        if ($buffer === null) {
            return self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_poster_select_url(
                $providerPoster,
                $l1,
                $tmdbPoster,
                $l2,
                $fanartPoster,
                $l3,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_poster_select_url(
                    $providerPoster,
                    $l1,
                    $tmdbPoster,
                    $l2,
                    $fanartPoster,
                    $l3,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::selectPosterUrlPhp($providerPoster, $tmdbPoster, $fanartPoster);
        }
    }

    public static function selectPosterUrlPhp(
        string $providerPoster,
        string $tmdbPoster = '',
        string $fanartPoster = '',
    ): string {
        if ($providerPoster !== '') {
            return $providerPoster;
        }
        if ($tmdbPoster !== '') {
            return $tmdbPoster;
        }

        return $fanartPoster;
    }
}
