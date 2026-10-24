<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MovieService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FetchMovieByImdb extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_fetch_movie_by_imdb_normalize_id(
        const char *raw_ptr,
        size_t raw_len,
        uint32_t *out_valid,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_fetch_movie_by_imdb_cache_keys(
        const char *imdb_ptr,
        size_t imdb_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_fetch_movie_by_imdb_format_details(
        uint32_t has_title,
        const char *title_ptr,
        size_t title_len,
        const char *year_ptr,
        size_t year_len,
        const char *imdb_ptr,
        size_t imdb_len,
        uint32_t has_tmdb,
        const char *tmdb_ptr,
        size_t tmdb_len,
        uint32_t has_trakt,
        const char *trakt_ptr,
        size_t trakt_len,
        uint32_t has_rating,
        const char *rating_ptr,
        size_t rating_len,
        uint32_t has_genre,
        const char *genre_ptr,
        size_t genre_len,
        uint32_t has_cover,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMovieAnimeTvReprocessCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * Accepts either a numeric IMDb id (eg: 1234567) or prefixed (tt1234567). Will strip 'tt'.
     */
    protected $signature = 'nntmux:fetch-movie {imdbid : IMDb id with or without tt prefix}';

    /**
     * The console command description.
     */
    protected $description = 'Force fetch/update movie data for a specific IMDb id from external providers (TMDB/IMDB/Trakt/OMDB/iTunes/Fanart), always updating local data.';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function handle(): int
    {
        $raw = (string) $this->argument('imdbid');
        $imdbId = self::normalizeImdbId($raw);

        if ($imdbId === null) {
            $this->error('Invalid IMDb id provided: '.$raw.' (parsed: '.self::extractImdbDigits($raw).')');

            return self::FAILURE;
        }

        $movie = app(MovieService::class);
        $movie->echooutput = true;
        $movie->service = 'console';

        $this->info('Force fetching movie data for IMDb id: tt'.$imdbId.' ...');

        // Clear all caches for this movie to force fresh data retrieval
        foreach (self::buildMovieCacheKeys($imdbId) as $cacheKey) {
            Cache::forget($cacheKey);
        }

        $ok = $movie->updateMovieInfo($imdbId);

        if (! $ok) {
            $this->error('Failed to fetch/update movie data for tt'.$imdbId.'.');

            return self::FAILURE;
        }

        $updated = $movie->getMovieInfo($imdbId);
        if ($updated === null) {
            $this->error('Movie info not found after update for tt'.$imdbId.'.');

            return self::FAILURE;
        }

        $lines = self::formatMovieDetailsLines(
            $updated->title ?? null,
            $updated->year,
            $imdbId,
            $updated->tmdbid ?? null,
            $updated->traktid ?? null,
            $updated->rating ?? null,
            $updated->genre ?? null,
            $updated->cover ?? null,
        );

        $this->line('');
        $this->info((string) array_shift($lines));
        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * The digits left after `strtolower(trim($raw))`, removing a leading `tt` and every non-digit.
     */
    public static function extractImdbDigits(string $raw): string
    {
        if (self::$useRustFfi) {
            return self::extractImdbDigitsNative($raw);
        }

        return self::extractImdbDigitsPhp($raw);
    }

    public static function extractImdbDigitsPhp(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        $normalized = (string) preg_replace('/^tt/i', '', $normalized); // remove leading tt if present

        return (string) preg_replace('/\D/', '', $normalized); // keep digits only
    }

    public static function extractImdbDigitsNative(string $raw): string
    {
        return self::parseImdbIdNative($raw)[0] ?? self::extractImdbDigitsPhp($raw);
    }

    /**
     * The parsed IMDb digits, or `null` when fewer than five digits remain.
     */
    public static function normalizeImdbId(string $raw): ?string
    {
        if (self::$useRustFfi) {
            return self::normalizeImdbIdNative($raw);
        }

        return self::normalizeImdbIdPhp($raw);
    }

    public static function normalizeImdbIdPhp(string $raw): ?string
    {
        $imdbId = self::extractImdbDigitsPhp($raw);

        if ($imdbId === '' || ! ctype_digit($imdbId) || strlen($imdbId) < 5) {
            return null;
        }

        return $imdbId;
    }

    public static function normalizeImdbIdNative(string $raw): ?string
    {
        $parsed = self::parseImdbIdNative($raw);
        if ($parsed === null) {
            return self::normalizeImdbIdPhp($raw);
        }

        return $parsed[1] ? $parsed[0] : null;
    }

    /**
     * Cache keys the providers store movie lookups under; forgotten to force a fresh fetch.
     *
     * @return list<string>
     */
    public static function buildMovieCacheKeys(string $imdbId): array
    {
        if (self::$useRustFfi) {
            return self::buildMovieCacheKeysNative($imdbId);
        }

        return self::buildMovieCacheKeysPhp($imdbId);
    }

    /**
     * @return list<string>
     */
    public static function buildMovieCacheKeysPhp(string $imdbId): array
    {
        return [
            'tmdb_movie_'.md5('tt'.$imdbId),
            'trakt_movie_'.md5($imdbId),
            'imdb_movie_'.md5($imdbId),
            'imdb_scrape_id_'.$imdbId,
            'omdb_movie_'.md5($imdbId),
        ];
    }

    /**
     * @return list<string>
     */
    public static function buildMovieCacheKeysNative(string $imdbId): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildMovieCacheKeysPhp($imdbId);
        }

        try {
            $lens = $ffi->new('size_t[5]');
            $out = self::callBufferFfi(
                $ffi,
                5 * strlen($imdbId) + 256,
                'nntmux_fetch_movie_by_imdb_cache_keys',
                $imdbId,
                strlen($imdbId),
                $lens,
            );

            return $out === null ? self::buildMovieCacheKeysPhp($imdbId) : self::splitByLengths($out, $lens, 5);
        } catch (Throwable) {
            return self::buildMovieCacheKeysPhp($imdbId);
        }
    }

    /**
     * The updated-movie summary: the first line is printed as info, the rest as plain lines.
     *
     * @return list<string>
     */
    public static function formatMovieDetailsLines(
        mixed $title,
        mixed $year,
        string $imdbId,
        mixed $tmdbid,
        mixed $traktid,
        mixed $rating,
        mixed $genre,
        mixed $cover,
    ): array {
        if (self::$useRustFfi) {
            return self::formatMovieDetailsLinesNative($title, $year, $imdbId, $tmdbid, $traktid, $rating, $genre, $cover);
        }

        return self::formatMovieDetailsLinesPhp($title, $year, $imdbId, $tmdbid, $traktid, $rating, $genre, $cover);
    }

    /**
     * @return list<string>
     */
    public static function formatMovieDetailsLinesPhp(
        mixed $title,
        mixed $year,
        string $imdbId,
        mixed $tmdbid,
        mixed $traktid,
        mixed $rating,
        mixed $genre,
        mixed $cover,
    ): array {
        return [
            'Updated movie: '.($title ?? 'Unknown Title').' ('.$year.')',
            'IMDb: tt'.$imdbId,
            'TMDB ID: '.($tmdbid ?? 'N/A'),
            'Trakt ID: '.($traktid ?? 'N/A'),
            'Rating: '.($rating ?? 'N/A'),
            'Genre: '.($genre ?? 'N/A'),
            'Cover: '.(($cover ?? 0) == 1 ? 'Yes' : 'No'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function formatMovieDetailsLinesNative(
        mixed $title,
        mixed $year,
        string $imdbId,
        mixed $tmdbid,
        mixed $traktid,
        mixed $rating,
        mixed $genre,
        mixed $cover,
    ): array {
        // Only null and scalars are stringified here; anything else keeps PHP's own conversion.
        $fields = [$title, $year, $tmdbid, $traktid, $rating, $genre];
        $scalarFields = array_filter($fields, static fn (mixed $field): bool => $field === null || is_scalar($field));
        $ffi = count($scalarFields) === count($fields) ? self::ffi() : null;
        if ($ffi === null) {
            return self::formatMovieDetailsLinesPhp($title, $year, $imdbId, $tmdbid, $traktid, $rating, $genre, $cover);
        }

        [$titleStr, $yearStr, $tmdbStr, $traktStr, $ratingStr, $genreStr] = array_map(
            static fn (mixed $field): string => (string) $field,
            $fields,
        );

        try {
            $lens = $ffi->new('size_t[7]');
            $out = self::callBufferFfi(
                $ffi,
                strlen($titleStr.$yearStr.$imdbId.$tmdbStr.$traktStr.$ratingStr.$genreStr) + 256,
                'nntmux_fetch_movie_by_imdb_format_details',
                $title !== null ? 1 : 0,
                $titleStr,
                strlen($titleStr),
                $yearStr,
                strlen($yearStr),
                $imdbId,
                strlen($imdbId),
                $tmdbid !== null ? 1 : 0,
                $tmdbStr,
                strlen($tmdbStr),
                $traktid !== null ? 1 : 0,
                $traktStr,
                strlen($traktStr),
                $rating !== null ? 1 : 0,
                $ratingStr,
                strlen($ratingStr),
                $genre !== null ? 1 : 0,
                $genreStr,
                strlen($genreStr),
                ($cover ?? 0) == 1 ? 1 : 0,
                $lens,
            );

            return $out === null
                ? self::formatMovieDetailsLinesPhp($title, $year, $imdbId, $tmdbid, $traktid, $rating, $genre, $cover)
                : self::splitByLengths($out, $lens, 7);
        } catch (Throwable) {
            return self::formatMovieDetailsLinesPhp($title, $year, $imdbId, $tmdbid, $traktid, $rating, $genre, $cover);
        }
    }

    /**
     * `[digits, valid]` from Rust, or `null` when the library is unavailable or declines.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function parseImdbIdNative(string $raw): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $valid = $ffi->new('uint32_t[1]');
            $out = self::callBufferFfi(
                $ffi,
                strlen($raw) + 1,
                'nntmux_fetch_movie_by_imdb_normalize_id',
                $raw,
                strlen($raw),
                $valid,
            );

            return $out === null ? null : [$out, ((int) $valid[0]) === 1];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function splitByLengths(string $out, FFI\CData $lens, int $count): array
    {
        $parts = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $len = (int) $lens[$i];
            $parts[] = substr($out, $offset, $len);
            $offset += $len;
        }

        return $parts;
    }

    /**
     * Runs a buffer-writing export, appending the scratch buffer and its capacity; `null` when it declines.
     */
    private static function callBufferFfi(FFI $ffi, int $minBytes, string $symbol, mixed ...$args): ?string
    {
        $cap = 0;
        $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $minBytes), $cap);
        if ($buf === null) {
            return null;
        }

        $rc = (int) $ffi->$symbol(...[...$args, $buf, $cap]);
        if ($rc < 0) {
            return null;
        }

        return $rc > 0 ? FFI::string($buf, $rc) : '';
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
