<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Class LocalDbProvider -- the provider-independent parts of TV processing.
 *
 * Parses release names and matches them against already scraped shows and
 * episodes, without calling any external API.
 */
class LocalDbProvider extends AbstractTvProvider
{
    public const string FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_tv_local_db_normalize_show_lookup(
        const char *name_ptr,
        size_t name_len,
        uint32_t has_year,
        int64_t year,
        const char *country_ptr,
        size_t country_len,
        int64_t *out_scalars,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_local_db_normalize_episode_lookup(
        int64_t site_id,
        int64_t season,
        int64_t episode,
        int64_t video_id,
        int64_t *out_scalars
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustTvProcessingPipesProviderParityTest.php.
     */
    private static bool $useLocalDbRustFfi = false;

    private static FFI|false|null $localDbFfi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useLocalDbRustFfi = (bool) $use;
        if ($use === null) {
            self::$localDbFfi = null;
        }
    }

    public static function enableRustFfi(): void
    {
        self::$useLocalDbRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useLocalDbRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useLocalDbRustFfi = false;
        self::$localDbFfi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useLocalDbRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$localDbFfi = $ffi;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getShowInfo(string $name, ?int $year = null, string $country = ''): array|false
    {
        self::normalizeShowLookup($name, $year, $country);

        return false;
    }

    public function fetchAllEpisodes(int $siteId, int $videoId): int
    {
        self::normalizeEpisodeLookup($siteId, 0, 0, $videoId);

        return 0;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchEpisode(int $siteId, int $season, int $episode, int $videoId = 0): array|false
    {
        self::normalizeEpisodeLookup($siteId, $season, $episode, $videoId);

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatShowInfo(mixed $show): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEpisodeInfo(mixed $episode): array
    {
        return [];
    }

    /**
     * @return array{name: string, year: ?int, country: string, has_name: bool}
     */
    public static function normalizeShowLookup(string $name, ?int $year = null, string $country = ''): array
    {
        if (self::$useLocalDbRustFfi) {
            return self::normalizeShowLookupNative($name, $year, $country);
        }

        return self::normalizeShowLookupPhp($name, $year, $country);
    }

    /**
     * @return array{name: string, year: ?int, country: string, has_name: bool}
     */
    public static function normalizeShowLookupPhp(string $name, ?int $year = null, string $country = ''): array
    {
        $trimmedName = trim($name);
        $trimmedCountry = strtoupper(trim($country));

        return [
            'name' => $trimmedName,
            'year' => ($year !== null && $year > 0) ? $year : null,
            'country' => $trimmedCountry,
            'has_name' => $trimmedName !== '',
        ];
    }

    /**
     * @return array{name: string, year: ?int, country: string, has_name: bool}
     */
    public static function normalizeShowLookupNative(string $name, ?int $year = null, string $country = ''): array
    {
        $ffi = self::localDbFfi();
        if ($ffi === null) {
            return self::normalizeShowLookupPhp($name, $year, $country);
        }

        try {
            $hasYear = $year !== null ? 1 : 0;
            $yearVal = $year ?? 0;
            $outScalars = $ffi->new('int64_t[3]');
            $outLens = $ffi->new('size_t[2]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($name) + strlen($country) + 16), $cap);
            if ($buf === null) {
                return self::normalizeShowLookupPhp($name, $year, $country);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_tv_local_db_normalize_show_lookup',
                $name,
                strlen($name),
                $hasYear,
                $yearVal,
                $country,
                strlen($country),
                $outScalars,
                $outLens,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeShowLookupPhp($name, $year, $country);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_tv_local_db_normalize_show_lookup',
                    $name,
                    strlen($name),
                    $hasYear,
                    $yearVal,
                    $country,
                    strlen($country),
                    $outScalars,
                    $outLens,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeShowLookupPhp($name, $year, $country);
            }

            $nameLen = (int) $outLens[0];
            $countryLen = (int) $outLens[1];
            $packed = $rc === 0 ? '' : FFI::string($buf, $rc);

            return [
                'name' => substr($packed, 0, $nameLen),
                'year' => ((int) $outScalars[0]) === 1 ? (int) $outScalars[1] : null,
                'country' => substr($packed, $nameLen, $countryLen),
                'has_name' => ((int) $outScalars[2]) === 1,
            ];
        } catch (Throwable) {
            return self::normalizeShowLookupPhp($name, $year, $country);
        }
    }

    /**
     * @return array{site_id: int, season: int, episode: int, video_id: int, valid: bool}
     */
    public static function normalizeEpisodeLookup(int $siteId, int $season, int $episode, int $videoId = 0): array
    {
        if (self::$useLocalDbRustFfi) {
            return self::normalizeEpisodeLookupNative($siteId, $season, $episode, $videoId);
        }

        return self::normalizeEpisodeLookupPhp($siteId, $season, $episode, $videoId);
    }

    /**
     * @return array{site_id: int, season: int, episode: int, video_id: int, valid: bool}
     */
    public static function normalizeEpisodeLookupPhp(int $siteId, int $season, int $episode, int $videoId = 0): array
    {
        return [
            'site_id' => max(0, $siteId),
            'season' => max(0, $season),
            'episode' => max(0, $episode),
            'video_id' => max(0, $videoId),
            'valid' => $videoId > 0 && $season >= 0 && $episode >= 0,
        ];
    }

    /**
     * @return array{site_id: int, season: int, episode: int, video_id: int, valid: bool}
     */
    public static function normalizeEpisodeLookupNative(int $siteId, int $season, int $episode, int $videoId = 0): array
    {
        $ffi = self::localDbFfi();
        if ($ffi === null) {
            return self::normalizeEpisodeLookupPhp($siteId, $season, $episode, $videoId);
        }

        try {
            $outScalars = $ffi->new('int64_t[5]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_tv_local_db_normalize_episode_lookup',
                $siteId,
                $season,
                $episode,
                $videoId,
                $outScalars
            );
            if ($rc < 0) {
                return self::normalizeEpisodeLookupPhp($siteId, $season, $episode, $videoId);
            }

            return [
                'site_id' => (int) $outScalars[0],
                'season' => (int) $outScalars[1],
                'episode' => (int) $outScalars[2],
                'video_id' => (int) $outScalars[3],
                'valid' => ((int) $outScalars[4]) === 1,
            ];
        } catch (Throwable) {
            return self::normalizeEpisodeLookupPhp($siteId, $season, $episode, $videoId);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function localDbFfi(): ?FFI
    {
        if (self::$localDbFfi === false) {
            return null;
        }

        if (self::$localDbFfi instanceof FFI) {
            return self::$localDbFfi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$localDbFfi = $ffi ?? false;

        return $ffi;
    }
}
