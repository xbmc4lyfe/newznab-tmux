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

    // ========================================================================
    // WAVE 63.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.80x, p99 6.18x; uncommon mean 4.92x, p99 4.25x); see RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_LOCAL_DB_PROVIDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w633_local_db_provider_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 63.3 batch. */
    public const int W63_LOCAL_DB_PROVIDER_TRAILER = 17;

    private const string W63_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

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
     * Runs $callback with the LocalDbProvider Wave 63 Rust path forced on or off, then restores the previous setting.
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
     * Wave 63.3 local DB show/episode lookup and candidate ranking plans. Each item is `name|year|country|siteId|season|
     * episode|videoId|candidateTitle|candidateAka|candidateYear|candidateCountry`. The trimmed name loses a trailing
     * `[ .(]YYYY` or `[ .(]YYYY)` (19xx/20xx) suffix, whose year applies unless the trimmed year field is a 19xx/20xx year;
     * key tokens are the lowercase `[a-z0-9]+` runs of the rest less a leading `the` while another token follows. Ids,
     * season and episode are 0 empty, 1 a trimmed `[+-]?\d{1,18}` or 2 other. Per item `nameState | suffix << 2 |
     * explicitYear << 3 | country << 4 | siteState << 5 | seasonState << 7 | episodeState << 9 | videoState << 11 |
     * valid << 13 | query << 14 | source << 17 | exact << 19 | yearCode << 20 | countryCode << 22 | rank << 24 |
     * min(score, 511) << 26 | jaccard << 35 | min(keyTokens, 255) << 42 | (year - 1900 or 0) << 50`: the name state is 0
     * empty, 1 up to 255 bytes without control bytes, 2 otherwise; country marks a two-letter country; valid follows
     * normalizeEpisodeLookup() (a positive video and season and episode neither invalid nor negative); the query is 2 all
     * episodes (season and episode 0), 3 one episode (positive episode), 4 a season (valid otherwise), 1 a show lookup
     * (valid name with key tokens), else 0; the jaccard is the better distinct-token percent of the candidate title and aka
     * tokens and the source is 0 when both are empty, 2 when the aka scores strictly higher, else 1; exact marks key tokens
     * equal to either; year and country codes are 0 unless both are set, then 1 equal, 2 one year apart or differing, 3 a
     * year conflict; the score is the jaccard + 20/10 for year codes 1/2 + 10 for an equal country - 40 for a year conflict
     * - 20 for a country conflict, at least 0, ranked 2 from 110, 1 from 80, else 0. Then the five query, three rank and
     * three source counts, the 1-based index of the first best score (0 when empty), that score, the distinct key token
     * lists, the distinct positive video ids, the valid count and the exact count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63LocalDbProviderPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63LocalDbProviderPlanBatchNative($items) : self::w63LocalDbProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63LocalDbProviderPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_LOCAL_DB_PROVIDER_TRAILER) ?? self::w63LocalDbProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63LocalDbProviderPlanBatchPhp(array $items): array
    {
        $out = [];
        $queries = [0, 0, 0, 0, 0];
        $ranks = [0, 0, 0];
        $sources = [0, 0, 0];
        $keys = $videos = [];
        $best = $bestScore = $valids = $exacts = $index = 0;
        foreach ($items as $item) {
            $index++;
            [$rawName, $rawYear, $rawCountry, $rawSite, $rawSeason, $rawEpisode, $rawVideo, $rawTitle, $rawAka, $rawCandidateYear, $rawCandidateCountry]
                = explode('|', (string) $item, 11) + ['', '', '', '', '', '', '', '', '', '', ''];
            $name = trim($rawName);
            $nameState = self::w63TextState($name);
            $n = \strlen($name);
            $base = $name;
            $suffixYear = null;
            if ($n >= 6 && $name[$n - 1] === ')' && str_contains(' .(', $name[$n - 6]) && ($y = self::w63Year(substr($name, $n - 5, 4))) !== null) {
                $base = substr($name, 0, $n - 6);
                $suffixYear = $y;
            } elseif ($n >= 5 && str_contains(' .(', $name[$n - 5]) && ($y = self::w63Year(substr($name, $n - 4))) !== null) {
                $base = substr($name, 0, $n - 5);
                $suffixYear = $y;
            }
            $explicit = self::w63Year(trim($rawYear));
            $effective = $explicit ?? $suffixYear;
            $key = self::w63LocalTokens($base);
            $countryOk = self::w63Country2(trim($rawCountry)) ? 1 : 0;
            [$siteState] = self::w63IntState($rawSite);
            [$seasonState, $season] = self::w63IntState($rawSeason);
            [$episodeState, $episode] = self::w63IntState($rawEpisode);
            [$videoState, $video] = self::w63IntState($rawVideo);
            $valid = $videoState === 1 && $video > 0 && $seasonState !== 2 && $episodeState !== 2 && $season >= 0 && $episode >= 0 ? 1 : 0;
            $query = match (true) {
                $valid === 1 && $season === 0 && $episode === 0 => 2,
                $valid === 1 && $episode > 0 => 3,
                $valid === 1 => 4,
                $nameState === 1 && $key !== [] => 1,
                default => 0,
            };
            $title = self::w63LocalTokens($rawTitle);
            $aka = self::w63LocalTokens($rawAka);
            $jt = self::w63Jaccard($key, $title);
            $ja = self::w63Jaccard($key, $aka);
            $source = $title === [] && $aka === [] ? 0 : ($ja > $jt ? 2 : 1);
            $bestJ = max($jt, $ja);
            $exact = $key !== [] && ($key === $title || $key === $aka) ? 1 : 0;
            $yearCode = self::w63YearCode($effective, self::w63Year(trim($rawCandidateYear)));
            $countryCode = self::w63CountryCode($rawCountry, $rawCandidateCountry);
            $score = max(0, $bestJ + [0, 20, 10, 0][$yearCode] + ($countryCode === 1 ? 10 : 0) - ($yearCode === 3 ? 40 : 0) - ($countryCode === 2 ? 20 : 0));
            $rank = $score >= 110 ? 2 : ($score >= 80 ? 1 : 0);
            $queries[$query]++;
            $ranks[$rank]++;
            $sources[$source]++;
            if ($best === 0 || $score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
            if ($videoState === 1 && $video > 0) {
                $videos[$video] = true;
            }
            $valids += $valid;
            $exacts += $exact;
            if ($key !== []) {
                $keys[implode(' ', $key)] = true;
            }
            $out[] = $nameState | ($suffixYear !== null ? 1 : 0) << 2 | ($explicit !== null ? 1 : 0) << 3 | $countryOk << 4 | $siteState << 5
                | $seasonState << 7 | $episodeState << 9 | $videoState << 11 | $valid << 13 | $query << 14 | $source << 17 | $exact << 19
                | $yearCode << 20 | $countryCode << 22 | $rank << 24 | min($score, 511) << 26 | $bestJ << 35 | min(\count($key), 255) << 42
                | ($effective !== null ? $effective - 1900 : 0) << 50;
        }
        array_push($out, ...$queries, ...$ranks, ...$sources);
        array_push($out, $best, $bestScore, \count($keys), \count($videos), $valids, $exacts);

        return $out;
    }

    /**
     * Tokens without a leading `the` while another token follows.
     *
     * @return list<string>
     */
    private static function w63LocalTokens(string $text): array
    {
        $tokens = self::w63Tokens($text);
        if (\count($tokens) > 1 && $tokens[0] === 'the') {
            array_shift($tokens);
        }

        return $tokens;
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

    /** 0 empty, 1 up to 255 bytes without C0 controls or DEL, 2 otherwise. */
    private static function w63TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W63_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /** Two ASCII letters. */
    private static function w63Country2(string $text): bool
    {
        return \strlen($text) === 2 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 2;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w63Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The distinct-token Jaccard percent (0 when both are empty).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function w63Jaccard(array $a, array $b): int
    {
        $setA = array_flip($a);
        $setB = array_flip($b);
        $shared = \count(array_intersect_key($setA, $setB));
        $union = \count($setA) + \count($setB) - $shared;

        return $union === 0 ? 0 : intdiv(100 * $shared, $union);
    }

    /** 0 unless both years are set, then 1 equal, 2 one apart or 3 conflicting. */
    private static function w63YearCode(?int $a, ?int $b): int
    {
        return $a === null || $b === null ? 0 : ($a === $b ? 1 : (abs($a - $b) === 1 ? 2 : 3));
    }

    /** 0 unless both trimmed fields are two letters, then 1 equal (any case) or 2 differing. */
    private static function w63CountryCode(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        return self::w63Country2($a) && self::w63Country2($b) ? (strtolower($a) === strtolower($b) ? 1 : 2) : 0;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w633', self::W63_LOCAL_DB_PROVIDER_FFI_CDEF) ?? false;

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
     * Calls the Wave 63.3 LocalDbProvider export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W63_LOCAL_DB_PROVIDER_FFI_CDEF.)
            $written = $ffi->nntmux_w633_local_db_provider_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
