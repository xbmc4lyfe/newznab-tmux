<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 49.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 5.61x, p99 3.42x; uncommon mean 4.56x, p99 3.90x); see RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_local_db_show_key_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W49 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /**
     * Local DB show lookup keys for a batch: three ints per show, [key hash, year, flags].
     *
     * The name is trimmed; a trailing ' 2019', '.2019', '(2019' or '(2019)' (19xx/20xx) is
     * stripped and used as the year unless the explicit year is positive. The remaining name is
     * lowercased, every run of non-alphanumerics becomes one space, a leading 'the ' is dropped
     * and the key is hashed with FNV-1a 64 (0 when empty). Flags: 1 non-empty name, 2 year
     * suffix stripped, 4 explicit year used, 8 two-letter country, with the uppercased country
     * bytes in bits 24-31 and 16-23.
     *
     * @param  list<string>  $names
     * @param  list<int>  $years
     * @param  list<string>  $countries
     * @return list<int>
     */
    public static function showLookupKeyBatch(array $names, array $years, array $countries): array
    {
        return self::usesW49RustFfi()
            ? self::showLookupKeyBatchNative($names, $years, $countries)
            : self::showLookupKeyBatchPhp($names, $years, $countries);
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $years
     * @param  list<string>  $countries
     * @return list<int>
     */
    public static function showLookupKeyBatchNative(array $names, array $years, array $countries): array
    {
        self::w49AssertColumns($names, $years, $countries);
        if ($names === []) {
            return [];
        }

        return self::w49Call(implode("\0", $names)."\0".implode("\0", $countries), pack('P*', ...$years), \count($names))
            ?? self::showLookupKeyBatchPhp($names, $years, $countries);
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $years
     * @param  list<string>  $countries
     * @return list<int>
     */
    public static function showLookupKeyBatchPhp(array $names, array $years, array $countries): array
    {
        self::w49AssertColumns($names, $years, $countries);
        $out = [];
        foreach ($names as $i => $raw) {
            $name = trim($raw);
            $flags = $name === '' ? 0 : 1;
            $base = $name;
            $year = 0;
            if (preg_match('/[ .(]((?:19|20)\d{2})\)?$/D', $name, $match, PREG_OFFSET_CAPTURE) === 1) {
                $base = substr($name, 0, $match[0][1]);
                $year = (int) $match[1][0];
                $flags |= 2;
            }
            if ($years[$i] > 0) {
                $year = $years[$i];
                $flags |= 4;
            }
            $key = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($base)), ' ');
            if (str_starts_with($key, 'the ')) {
                $key = substr($key, 4);
            }
            $country = strtoupper(trim($countries[$i]));
            if (\strlen($country) === 2 && strspn($country, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') === 2) {
                $flags |= 8 | (\ord($country[0]) << 24) | (\ord($country[1]) << 16);
            }
            $hash = 0;
            if ($key !== '') {
                /** @var array{1: int} $digest */
                $digest = unpack('J', hash('fnv1a64', $key, true));
                $hash = $digest[1];
            }
            array_push($out, $hash, $year, $flags);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 scalars,
     * 3 int64 words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w49Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w49', self::W49_FFI_CDEF) ?? false;
            $ffi = self::$w49Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 3;
            $out = self::$w49Out;
            if ($out === null || self::$w49OutCap < $need) {
                self::$w49OutCap = max(1024, $need);
                $out = self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W49_FFI_CDEF.)
            $written = $ffi->nntmux_w49_tv_local_db_show_key_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w49AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count || ! array_is_list($column)) {
                throw new InvalidArgumentException('W49 batch columns must be lists of equal length.');
            }
        }
    }
}
