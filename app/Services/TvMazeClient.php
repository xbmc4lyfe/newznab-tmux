<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TvProviderException;
use App\Support\RustPipelineLibrary;
use DariusIII\TVMaze\Episode;
use DariusIII\TVMaze\TVShow;
use FFI;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TVMaze API client built on Laravel's HTTP client.
 *
 * Replaces the vendor curl transport (no timeout, rate-limit bodies returned as data)
 * while keeping the vendor TVShow/Episode value objects the TV providers already use.
 * TVMaze needs no API key; it allows about 20 calls per 10 seconds per IP.
 */
class TvMazeClient
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tvmaze_extract_aka_names(const char *ser, size_t ser_len, char *out, size_t cap);
        ssize_t nntmux_tvmaze_normalize_airdate(const char *airdate, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
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

    protected const BASE_URL = 'https://api.tvmaze.com';

    /** Minimum seconds between requests from one process. */
    protected const MIN_INTERVAL_SECONDS = 1.0;

    private static float $lastRequestAt = 0.0;

    protected int $timeout;

    protected int $retryTimes;

    public function __construct()
    {
        $this->timeout = (int) config('nntmux_api.tvmaze_timeout', 30);
        $this->retryTimes = (int) config('nntmux_api.tvmaze_retry_times', 2);
    }

    /**
     * @return list<TVShow>
     */
    public function searchShows(string $name): array
    {
        $shows = [];
        foreach ($this->getList('/search/shows', ['q' => $name]) as $result) {
            if (\is_array($result['show'] ?? null)) {
                $shows[] = new TVShow($result['show']);
            }
        }

        return $shows;
    }

    /** The single best match for a name, with its AKAs embedded. */
    public function singleSearchAkas(string $name): ?TVShow
    {
        $show = $this->get('/singlesearch/shows', ['q' => $name, 'embed' => 'akas']);

        return $show !== null ? new TVShow($show) : null;
    }

    public function showById(int $id): ?TVShow
    {
        $show = $this->get('/shows/'.$id, ['embed' => 'akas']);

        return $show !== null ? new TVShow($show) : null;
    }

    /**
     * Look up a show by another site's ID.
     *
     * @param  string  $site  'thetvdb', 'tvrage' or 'imdb' (with the tt prefix)
     */
    public function lookup(string $site, int|string $id): ?TVShow
    {
        $show = $this->get('/lookup/shows', [strtolower($site) => (string) $id]);

        return $show !== null ? new TVShow($show) : null;
    }

    /**
     * @return list<string>
     */
    public function akas(int $showId): array
    {
        return self::extractAkaNames($this->getList('/shows/'.$showId.'/akas'));
    }

    /**
     * @param  array<array-key, mixed>  $akas
     * @return list<string>
     */
    public static function extractAkaNames(array $akas): array
    {
        return self::usesRustFfi()
            ? self::extractAkaNamesNative($akas)
            : self::extractAkaNamesPhp($akas);
    }

    /**
     * @param  array<array-key, mixed>  $akas
     * @return list<string>
     */
    public static function extractAkaNamesNative(array $akas): array
    {
        if ($akas === []) {
            return [];
        }
        if (($ffi = self::ffi()) === null) {
            return self::extractAkaNamesPhp($akas);
        }
        try {
            $ser = serialize($akas);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len), $capacity);
            if ($buffer === null) {
                return self::extractAkaNamesPhp($akas);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tvmaze_extract_aka_names($ser, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::extractAkaNamesPhp($akas);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tvmaze_extract_aka_names($ser, $len, $buffer, $capacity);
            }
            if ($written === 0) {
                return [];
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::extractAkaNamesPhp($akas);
            }

            return explode("\0", FFI::string($buffer, $written));
        } catch (Throwable) {
            return self::extractAkaNamesPhp($akas);
        }
    }

    /**
     * @param  array<array-key, mixed>  $akas
     * @return list<string>
     */
    public static function extractAkaNamesPhp(array $akas): array
    {
        $names = [];
        foreach ($akas as $aka) {
            if (\is_array($aka) && \is_string($aka['name'] ?? null) && $aka['name'] !== '') {
                $names[] = $aka['name'];
            }
        }

        return $names;
    }

    /**
     * @return list<Episode>
     */
    public function episodesByShow(int $showId, bool $specials = true): array
    {
        return array_map(
            static fn (array $episode): Episode => new Episode($episode),
            $this->getList('/shows/'.$showId.'/episodes', $specials ? ['specials' => 1] : []),
        );
    }

    public function episodeByNumber(int $showId, int $season, int $episode): ?Episode
    {
        $data = $this->get('/shows/'.$showId.'/episodebynumber', ['season' => $season, 'number' => $episode]);

        return $data !== null ? new Episode($data) : null;
    }

    /**
     * @return list<Episode>
     */
    public function episodesByDate(int $showId, string $airdate): array
    {
        $normalizedDate = self::normalizeAirdate($airdate);
        if ($normalizedDate === null) {
            return [];
        }

        return array_map(
            static fn (array $episode): Episode => new Episode($episode),
            $this->getList('/shows/'.$showId.'/episodesbydate', ['date' => $normalizedDate]),
        );
    }

    public static function normalizeAirdate(string $airdate): ?string
    {
        return self::usesRustFfi()
            ? self::normalizeAirdateNative($airdate)
            : self::normalizeAirdatePhp($airdate);
    }

    public static function normalizeAirdateNative(string $airdate): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeAirdatePhp($airdate);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 32, $capacity);
        if ($buffer === null) {
            return self::normalizeAirdatePhp($airdate);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tvmaze_normalize_airdate($airdate, \strlen($airdate), $buffer, $capacity);
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeAirdatePhp($airdate);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizeAirdatePhp($airdate);
        }
    }

    public static function normalizeAirdatePhp(string $airdate): ?string
    {
        $timestamp = strtotime($airdate);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function getList(string $path, array $query = []): array
    {
        $data = $this->get($path, $query);
        if ($data === null || ! array_is_list($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * GET a JSON document; null when TVMaze has nothing for it.
     *
     * @param  array<string, scalar>  $query
     * @return array<int|string, mixed>|null
     *
     * @throws TvProviderException on rate limits, server errors and connection failures
     */
    protected function get(string $path, array $query = []): ?array
    {
        $this->throttle();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout(10)
                ->retry($this->retryTimes, 500, static fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new TvProviderException('tvmaze', 'TVMaze connection failed: '.$e->getMessage(), null, null, $e);
        }

        return $this->decode($response, $path);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function decode(Response $response, string $path): ?array
    {
        $status = $response->status();

        if (TvProviderException::isTransientStatus($status)) {
            throw new TvProviderException(
                'tvmaze',
                sprintf('TVMaze returned %d for %s', $status, $path),
                $status,
                TvProviderException::parseRetryAfter($response->header('Retry-After')),
            );
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return \is_array($data) && $data !== [] ? $data : null;
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - self::$lastRequestAt;
        if (self::$lastRequestAt > 0.0 && $elapsed < self::MIN_INTERVAL_SECONDS && ! app()->runningUnitTests()) {
            usleep((int) ((self::MIN_INTERVAL_SECONDS - $elapsed) * 1_000_000));
        }
        self::$lastRequestAt = microtime(true);
    }

    /** Disabled: one of seven paired runs missed the common p99 gate (min common mean 1.97x, p99 1.31x; uncommon mean 3.51x, p99 3.17x; see the W46 parity test). useW464RustFfi(true) opts in. */
    public const bool W464_RUST_FFI_DEFAULT = false;

    public const string W464_FFI_CDEF = <<<'C'
        ssize_t nntmux_w464_tvmaze_aka_name_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W464_SUMMARY_KEYS = ['total_entries', 'empty_count', 'blank_count', 'padded_count', 'article_prefix_count', 'year_suffix_count', 'country_suffix_count', 'punctuation_count', 'digit_count', 'non_ascii_count', 'multi_space_count'];

    /** unpack() format for the eleven little-endian int64 counters, in W464_SUMMARY_KEYS order. */
    private const string W464_UNPACK = 'Ptotal_entries/Pempty_count/Pblank_count/Ppadded_count/Particle_prefix_count/Pyear_suffix_count/Pcountry_suffix_count/Ppunctuation_count/Pdigit_count/Pnon_ascii_count/Pmulti_space_count';

    private static ?bool $w464UseRustFfi = null;

    private static FFI|false|null $w464Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW464RustFfi(?bool $enable = true): void
    {
        self::$w464UseRustFfi = $enable;
    }

    public static function usesW464RustFfi(): bool
    {
        return self::$w464UseRustFfi ?? self::W464_RUST_FFI_DEFAULT;
    }

    public static function resetW464RustFfi(): void
    {
        self::$w464UseRustFfi = null;
        self::$w464Ffi = null;
    }

    /**
     * Summarize AKA names: empty names extractAkaNames() drops, then on the
     * trimmed name blank and padded names, leading articles, (YYYY) and
     * (country) suffixes, :!?& punctuation, digits, non-ASCII bytes and
     * whitespace runs. Entries come from strings, integers, or the name
     * field; other values count as empty. Byte classes are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTvMazeAkaNameBatch(array $entries): array
    {
        return self::usesW464RustFfi()
            ? self::summarizeTvMazeAkaNameBatchNative($entries)
            : self::summarizeTvMazeAkaNameBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTvMazeAkaNameBatchNative(array $entries): array
    {
        return self::w464Call(self::w464Entries($entries)) ?? self::summarizeTvMazeAkaNameBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTvMazeAkaNameBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W464_SUMMARY_KEYS, 0);
        foreach (self::w464Entries($entries) as $entry) {
            $summary['total_entries']++;
            if ($entry === '') {
                $summary['empty_count']++;

                continue;
            }
            $name = trim($entry);
            $summary['blank_count'] += (int) ($name === '');
            $summary['padded_count'] += (int) ($name !== $entry);
            $summary['article_prefix_count'] += (int) (preg_match('/\A(?:[Tt][Hh][Ee]|[Aa][Nn]?)[ \t\n\r\x0b\f]/', $name) === 1);
            $summary['year_suffix_count'] += (int) (preg_match('/\([0-9]{4}\)\z/', $name) === 1);
            $summary['country_suffix_count'] += (int) (preg_match('/\((?:US|UK|AU|CA|NZ|JP|KR)\)\z/', $name) === 1);
            $summary['punctuation_count'] += (int) (preg_match('/[:!?&]/', $name) === 1);
            $summary['digit_count'] += (int) (preg_match('/[0-9]/', $name) === 1);
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xff]/', $name) === 1);
            $summary['multi_space_count'] += (int) (preg_match('/[ \t\n\r\x0b\f]{2}/', $name) === 1);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w464Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w464Call(array $entries): ?array
    {
        try {
            $input = serialize($entries);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w464Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w464', self::W464_FFI_CDEF) ?? false;
            $ffi = self::$w464Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 88 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 88);
            // @phpstan-ignore method.notFound (Declared by W464_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w464_tvmaze_aka_name_batch($input, strlen($input), $out, 88) !== 88) {
                return null;
            }
            /** @var array<string, int>|false $summary */
            $summary = unpack(self::W464_UNPACK, FFI::string($out, 88));
            $total = count($entries);
            if ($summary === false || $summary['total_entries'] !== $total || min($summary) < 0 || max($summary) > $total) {
                return null;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }
}
