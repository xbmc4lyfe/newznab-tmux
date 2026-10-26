<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\Video;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvMazeClient;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Support\RustPipelineLibrary;
use DariusIII\TVMaze\TVShow;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolve a TV show by external ID (tvdb, tvmaze, tmdb, trakt, imdb) and
 * persist it into the local videos / tv_info / videos_aliases tables.
 *
 * Each branch reuses the existing provider's formatShowInfo() + add() so the
 * data shape, dedupe, and observer-driven search-index inserts stay consistent
 * with the rest of the TV processing pipeline.
 */
class TvShowAdder
{
    public const SUPPORTED_SOURCES = ['tvdb', 'tvmaze', 'tmdb', 'trakt', 'imdb'];

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_tv_show_adder_normalize_id(const char *source, size_t source_len, const char *id, size_t id_len);
        int32_t nntmux_tv_show_adder_video_column(const char *source, size_t source_len);
        C;

    /** Column order returned by nntmux_tv_show_adder_video_column(). */
    private const array NATIVE_VIDEO_COLUMNS = ['tvdb', 'tvmaze', 'tmdb', 'trakt', 'imdb'];

    private const string INVALID_IMDB_MESSAGE = 'IMDB ID must look like "tt1234567" or its numeric part.';

    /** Null selects the benchmark-qualified production default (PHP; see RustTvShowAdderAndMetadataParityTest). */
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

    /**
     * Resolve + persist. Returns metadata about the operation.
     *
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     *
     * @throws InvalidArgumentException when the source is unsupported.
     * @throws RuntimeException when no provider could resolve the id or the
     *                          required provider is not configured.
     */
    public function add(string $source, string $id, int $type = 0): array
    {
        $source = strtolower(trim($source));
        $id = $this->normalizeId($source, $id);

        if (! in_array($source, self::SUPPORTED_SOURCES, true)) {
            throw new InvalidArgumentException("Unsupported external source: {$source}");
        }

        // Short-circuit if we already have this video by external id.
        $column = $this->videoColumnForSource($source);
        $existing = Video::query()->where($column, $id)->first(['id', 'title']);
        if ($existing !== null) {
            return [
                'videoId' => (int) $existing->id,
                'existed' => true,
                'source' => $source,
                'externalId' => (string) $id,
                'title' => (string) $existing->title,
            ];
        }

        return DB::transaction(function () use ($source, $id, $type) {
            return $this->dispatch($source, $id, $type);
        });
    }

    /**
     * Dry-run resolve. Returns the formatted show array (without persisting)
     * for preview UIs. Returns null when nothing was found.
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $source, string $id): ?array
    {
        $source = strtolower(trim($source));
        $id = $this->normalizeId($source, $id);

        switch ($source) {
            case 'tvdb':
                $provider = new TvdbProvider;
                $show = $this->fetchTvdb($provider, (int) $id);

                return $show ? $provider->formatShowInfo($show) : null;
            case 'tvmaze':
                $provider = new TvMazeProvider;
                $show = $this->fetchTvMaze((int) $id, 'tvmaze');

                return $show ? $provider->formatShowInfo($show) : null;
            case 'tmdb':
                $provider = new TmdbProvider;
                $show = $this->fetchTmdb((int) $id);

                return $show ? $provider->formatShowInfo($show) : null;
            case 'trakt':
                $provider = new TraktProvider;
                $show = $this->fetchTrakt($id);

                return $show ? $provider->formatShowInfo($show) : null;
            case 'imdb':
                return $this->previewImdb($id);
        }

        return null;
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function dispatch(string $source, string $id, int $type): array
    {
        switch ($source) {
            case 'tvdb':
                return $this->addViaTvdb((int) $id, $type);
            case 'tvmaze':
                return $this->addViaTvMaze((int) $id, $type);
            case 'tmdb':
                return $this->addViaTmdb((int) $id, $type);
            case 'trakt':
                return $this->addViaTrakt($id, $type);
            case 'imdb':
                return $this->addViaImdb($id, $type);
        }

        throw new InvalidArgumentException("Unsupported external source: {$source}");
    }

    // -------------------------------------------------------------------------
    // TVDB
    // -------------------------------------------------------------------------

    private function fetchTvdb(TvdbProvider $provider, int $tvdbId): ?object
    {
        try {
            $extended = $provider->seriesExtended($tvdbId);
        } catch (Throwable $e) {
            Log::warning('TvShowAdder: TVDB lookup failed', ['id' => $tvdbId, 'error' => $e->getMessage()]);

            return null;
        }

        if ($extended === null) {
            return null;
        }

        // TvdbProvider::formatShowInfo() expects fields shaped like SearchResult
        // (tvdb_id, name, overview, first_air_time, aliases). The extended
        // endpoint returns SeriesExtendedRecord (camelCase). Adapt it.
        $aliases = [];
        if (! empty($extended->aliases) && is_array($extended->aliases)) {
            foreach ($extended->aliases as $alias) {
                if (is_object($alias) && isset($alias->name)) {
                    $aliases[] = (string) $alias->name;
                } elseif (is_string($alias)) {
                    $aliases[] = $alias;
                }
            }
        }

        return (object) [
            'tvdb_id' => (string) ($extended->id ?? $tvdbId),
            'name' => (string) ($extended->name ?? ''),
            'overview' => (string) ($extended->overview ?? ''),
            'first_air_time' => (string) ($extended->firstAired ?? ''),
            'aliases' => $aliases,
        ];
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function addViaTvdb(int $tvdbId, int $type): array
    {
        $provider = new TvdbProvider;
        $show = $this->fetchTvdb($provider, $tvdbId);
        if ($show === null) {
            throw new RuntimeException("No TVDB show found for ID {$tvdbId}.");
        }

        $data = $provider->formatShowInfo($show);
        $data['type'] = $type;

        return $this->persist($provider, $data, 'tvdb', (string) $tvdbId);
    }

    // -------------------------------------------------------------------------
    // TVMaze
    // -------------------------------------------------------------------------

    /**
     * @param  string  $kind  'tvmaze' for a TVMaze id, otherwise the lookup site ('thetvdb', 'tvrage' or 'imdb')
     */
    private function fetchTvMaze(int|string $id, string $kind): ?TVShow
    {
        try {
            $client = app(TvMazeClient::class);
            $show = $kind === 'tvmaze' ? $client->showById((int) $id) : $client->lookup($kind, $id);
        } catch (Throwable $e) {
            Log::warning('TvShowAdder: TVMaze lookup failed', ['id' => $id, 'kind' => $kind, 'error' => $e->getMessage()]);

            return null;
        }

        return $show !== null && ! $show->isEmpty() ? $show : null;
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function addViaTvMaze(int $tvmazeId, int $type): array
    {
        $provider = new TvMazeProvider;
        $show = $this->fetchTvMaze($tvmazeId, 'tvmaze');
        if ($show === null) {
            throw new RuntimeException("No TVMaze show found for ID {$tvmazeId}.");
        }

        $data = $provider->formatShowInfo($show);
        $data['type'] = $type;

        return $this->persist($provider, $data, 'tvmaze', (string) $tvmazeId);
    }

    // -------------------------------------------------------------------------
    // TMDB
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTmdb(int $tmdbId): ?array
    {
        $client = app(TmdbClient::class);
        if (! $client->isConfigured()) {
            throw new RuntimeException('TMDB API is not configured.');
        }

        $show = $client->getTvShow($tmdbId, ['external_ids', 'alternative_titles']);
        if ($show === null) {
            return null;
        }

        // Mirror the enrichment that TmdbProvider::matchShowInfo() does so
        // formatShowInfo() has the keys it expects.
        $alternativeTitles = [];
        $altRoot = TmdbClient::getArray($show, 'alternative_titles');
        $results = TmdbClient::getArray($altRoot, 'results');
        foreach ($results as $aka) {
            if (is_array($aka) && isset($aka['title'])) {
                $alternativeTitles[] = $aka['title'];
            }
        }
        $show['alternative_titles'] = $alternativeTitles;

        $networks = array_values(TmdbClient::getArray($show, 'networks'));
        $firstNetwork = $networks[0] ?? null;
        $show['network'] = is_array($firstNetwork) && ! empty($firstNetwork['name']) ? (string) $firstNetwork['name'] : '';

        return $show;
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function addViaTmdb(int $tmdbId, int $type): array
    {
        $provider = new TmdbProvider;
        $show = $this->fetchTmdb($tmdbId);
        if ($show === null) {
            throw new RuntimeException("No TMDB show found for ID {$tmdbId}.");
        }

        $data = $provider->formatShowInfo($show);
        $data['type'] = $type;

        return $this->persist($provider, $data, 'tmdb', (string) $tmdbId);
    }

    // -------------------------------------------------------------------------
    // Trakt
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTrakt(int|string $id): ?array
    {
        $client = app(TraktService::class);
        if (! $client->isConfigured()) {
            throw new RuntimeException('Trakt API is not configured.');
        }

        $show = $client->getShowSummary((string) $id, 'full');

        return is_array($show) && ! empty($show['ids']) ? $show : null;
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function addViaTrakt(int|string $traktId, int $type): array
    {
        $provider = new TraktProvider;
        $show = $this->fetchTrakt($traktId);
        if ($show === null) {
            throw new RuntimeException("No Trakt show found for ID {$traktId}.");
        }

        $data = $provider->formatShowInfo($show);
        $data['type'] = $type;

        return $this->persist($provider, $data, 'trakt', (string) $traktId);
    }

    // -------------------------------------------------------------------------
    // IMDB (fallback chain: TMDB → Trakt → TVMaze)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function previewImdb(string $imdbId): ?array
    {
        // Try TMDB first
        try {
            $client = app(TmdbClient::class);
            if ($client->isConfigured()) {
                $found = $client->findTvByExternalId('tt'.$imdbId, 'imdb_id');
                if ($found !== null) {
                    $tmdbId = TmdbClient::getInt($found, 'id');
                    if ($tmdbId > 0) {
                        $provider = new TmdbProvider;
                        $show = $this->fetchTmdb($tmdbId);
                        if ($show !== null) {
                            return $provider->formatShowInfo($show);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::info('TvShowAdder: IMDB→TMDB preview failed', ['imdb' => $imdbId, 'error' => $e->getMessage()]);
        }

        // Then Trakt
        try {
            $trakt = app(TraktService::class);
            if ($trakt->isConfigured()) {
                $results = $trakt->searchById($imdbId, 'imdb', 'show');
                $resultRows = is_array($results) ? array_values($results) : [];
                $firstResult = $resultRows[0] ?? null;
                if (is_array($firstResult) && ! empty($firstResult['show']['ids']['trakt'])) {
                    $traktId = (int) $firstResult['show']['ids']['trakt'];
                    $provider = new TraktProvider;
                    $show = $this->fetchTrakt($traktId);
                    if ($show !== null) {
                        return $provider->formatShowInfo($show);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::info('TvShowAdder: IMDB→Trakt preview failed', ['imdb' => $imdbId, 'error' => $e->getMessage()]);
        }

        // Then TVMaze
        try {
            $provider = new TvMazeProvider;
            $show = $this->fetchTvMaze('tt'.$imdbId, 'imdb');
            if ($show !== null) {
                return $provider->formatShowInfo($show);
            }
        } catch (Throwable $e) {
            Log::info('TvShowAdder: IMDB→TVMaze preview failed', ['imdb' => $imdbId, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function addViaImdb(string $imdbId, int $type): array
    {
        // TMDB
        try {
            $client = app(TmdbClient::class);
            if ($client->isConfigured()) {
                $found = $client->findTvByExternalId('tt'.$imdbId, 'imdb_id');
                if ($found !== null) {
                    $tmdbId = TmdbClient::getInt($found, 'id');
                    if ($tmdbId > 0) {
                        return $this->addViaTmdb($tmdbId, $type);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::info('TvShowAdder: IMDB→TMDB add failed', ['imdb' => $imdbId, 'error' => $e->getMessage()]);
        }

        // Trakt
        try {
            $trakt = app(TraktService::class);
            if ($trakt->isConfigured()) {
                $results = $trakt->searchById($imdbId, 'imdb', 'show');
                $resultRows = is_array($results) ? array_values($results) : [];
                $firstResult = $resultRows[0] ?? null;
                if (is_array($firstResult) && ! empty($firstResult['show']['ids']['trakt'])) {
                    return $this->addViaTrakt((int) $firstResult['show']['ids']['trakt'], $type);
                }
            }
        } catch (Throwable $e) {
            Log::info('TvShowAdder: IMDB→Trakt add failed', ['imdb' => $imdbId, 'error' => $e->getMessage()]);
        }

        // TVMaze (last resort, no extra API key required)
        $provider = new TvMazeProvider;
        $show = $this->fetchTvMaze('tt'.$imdbId, 'imdb');
        if ($show !== null) {
            $data = $provider->formatShowInfo($show);
            $data['type'] = $type;

            return $this->persist($provider, $data, 'imdb', $imdbId);
        }

        throw new RuntimeException("No show found for IMDB ID tt{$imdbId} via TMDB, Trakt, or TVMaze.");
    }

    // -------------------------------------------------------------------------
    // Persistence helper
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array{videoId: int, existed: bool, source: string, externalId: string, title: ?string}
     */
    private function persist(AbstractTvProvider $provider, array $data, string $source, string $externalId): array
    {
        // Ensure required keys formatShowInfo callers might omit
        $data += [
            'country' => '',
            'aliases' => '',
            'localzone' => '',
            'imdb' => '',
            'tvdb' => 0,
            'trakt' => 0,
            'tvrage' => 0,
            'tvmaze' => 0,
            'tmdb' => 0,
            'summary' => '',
            'publisher' => '',
            'started' => null,
            'type' => 0,
            'source' => 0,
            'title' => '',
        ];

        if (empty($data['title'])) {
            throw new RuntimeException('Provider returned a show without a title.');
        }

        $videoId = $provider->add($data);

        if ($videoId > 0) {
            try {
                $provider->getPoster($videoId);
            } catch (Throwable $e) {
                Log::info('TvShowAdder: poster fetch failed', ['videoId' => $videoId, 'error' => $e->getMessage()]);
            }
        }

        return [
            'videoId' => (int) $videoId,
            'existed' => false,
            'source' => $source,
            'externalId' => $externalId,
            'title' => (string) $data['title'],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function videoColumnForSource(string $source): string
    {
        return self::usesRustFfi()
            ? $this->videoColumnForSourceNative($source)
            : $this->videoColumnForSourcePhp($source);
    }

    public function videoColumnForSourceNative(string $source): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->videoColumnForSourcePhp($source);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_tv_show_adder_video_column($source, \strlen($source));
        } catch (Throwable) {
            return $this->videoColumnForSourcePhp($source);
        }
        if ($index === -2) {
            throw new InvalidArgumentException("Unsupported source: {$source}");
        }

        return \is_int($index) && isset(self::NATIVE_VIDEO_COLUMNS[$index])
            ? self::NATIVE_VIDEO_COLUMNS[$index]
            : $this->videoColumnForSourcePhp($source);
    }

    public function videoColumnForSourcePhp(string $source): string
    {
        return match ($source) {
            'tvdb' => 'tvdb',
            'tvmaze' => 'tvmaze',
            'tmdb' => 'tmdb',
            'trakt' => 'trakt',
            'imdb' => 'imdb',
            default => throw new InvalidArgumentException("Unsupported source: {$source}"),
        };
    }

    private function normalizeId(string $source, string $id): string
    {
        return self::usesRustFfi()
            ? $this->normalizeIdNative($source, $id)
            : $this->normalizeIdPhp($source, $id);
    }

    public function normalizeIdNative(string $source, string $id): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->normalizeIdPhp($source, $id);
        }
        try {
            // @phpstan-ignore method.notFound
            $span = $ffi->nntmux_tv_show_adder_normalize_id($source, \strlen($source), $id, \strlen($id));
        } catch (Throwable) {
            return $this->normalizeIdPhp($source, $id);
        }
        if ($span === -2) {
            throw new InvalidArgumentException(self::INVALID_IMDB_MESSAGE);
        }
        if ($span === -3) {
            throw new InvalidArgumentException(strtoupper($source).' ID must be a positive integer.');
        }
        if (! \is_int($span) || $span < 0) {
            return $this->normalizeIdPhp($source, $id);
        }

        return substr($id, $span >> 32, $span & 0xFFFFFFFF);
    }

    public function normalizeIdPhp(string $source, string $id): string
    {
        $id = trim($id);
        if ($source === 'imdb') {
            // Accept "tt0944947" or "0944947" — store/lookup as the numeric portion.
            if (preg_match('/^tt?(\d{5,10})$/i', $id, $m)) {
                return $m[1];
            }
            if (ctype_digit($id)) {
                return $id;
            }
            throw new InvalidArgumentException(self::INVALID_IMDB_MESSAGE);
        }

        if (! ctype_digit($id) || (int) $id <= 0) {
            throw new InvalidArgumentException(strtoupper($source).' ID must be a positive integer.');
        }

        return $id;
    }

    // ---------------------------------------------------------------------
    // Wave 49.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.62x, p99 2.29x; uncommon mean 3.10x, p99 3.08x); see RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_show_adder_request_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * add() request normalization for a batch: two ints per request, [column, id].
     *
     * The column indexes SUPPORTED_SOURCES for strtolower(trim(source)) (-1 when unsupported).
     * The id word is normalizeIdPhp()'s result as offset << 32 | length within the raw id, or
     * -2 for an invalid IMDB id and -3 for an id that isn't a positive integer.
     *
     * @param  list<string>  $sources
     * @param  list<string>  $ids
     * @return list<int>
     */
    public static function normalizeRequestBatch(array $sources, array $ids): array
    {
        return self::usesW49RustFfi()
            ? self::normalizeRequestBatchNative($sources, $ids)
            : self::normalizeRequestBatchPhp($sources, $ids);
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>  $ids
     * @return list<int>
     */
    public static function normalizeRequestBatchNative(array $sources, array $ids): array
    {
        self::w49AssertColumns($sources, $ids);
        if ($sources === []) {
            return [];
        }

        return self::w49Call(implode("\0", $sources)."\0".implode("\0", $ids), '', \count($sources))
            ?? self::normalizeRequestBatchPhp($sources, $ids);
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>  $ids
     * @return list<int>
     */
    public static function normalizeRequestBatchPhp(array $sources, array $ids): array
    {
        self::w49AssertColumns($sources, $ids);
        $out = [];
        foreach ($sources as $i => $source) {
            $column = match (strtolower(trim($source))) {
                'tvdb' => 0,
                'tvmaze' => 1,
                'tmdb' => 2,
                'trakt' => 3,
                'imdb' => 4,
                default => -1,
            };
            $raw = $ids[$i];
            $id = trim($raw);
            $offset = strspn($raw, " \t\n\r\0\x0B");
            if ($column === 4) {
                if (preg_match('/^tt?(\d{5,10})$/i', $id, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $word = (($offset + $match[1][1]) << 32) | \strlen($match[1][0]);
                } else {
                    $word = ctype_digit($id) ? ($offset << 32) | \strlen($id) : -2;
                }
            } else {
                $word = ctype_digit($id) && (int) $id > 0 ? ($offset << 32) | \strlen($id) : -3;
            }
            array_push($out, $column, $word);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 scalars,
     * 2 int64 words back per record. Null when the library is missing or declines.
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
            $need = $count * 2;
            $out = self::$w49Out;
            if ($out === null || self::$w49OutCap < $need) {
                self::$w49OutCap = max(1024, $need);
                $out = self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W49_FFI_CDEF.)
            $written = $ffi->nntmux_w49_tv_show_adder_request_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.76x, p99 6.66x; uncommon mean 5.94x, p99 4.46x); see RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_TV_SHOW_ADDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w633_tv_show_adder_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 63.3 batch. */
    public const int W63_TV_SHOW_ADDER_TRAILER = 16;

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
     * Runs $callback with the TvShowAdder Wave 63 Rust path forced on or off, then restores the previous setting.
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
     * Wave 63.3 add()/persist() show, alias, info and deduplication decision plans. Each item is `source|id|type|existingId|
     * title|aliases|started|country`. The source is 0 empty, 1-5 a SUPPORTED_SOURCES entry (trimmed, any case) or 6
     * other; the id follows normalizeIdPhp(): 0 empty, 1 valid (imdb: `t`/`tt` (any case) and 5-10 digits keep the
     * digits, else all digits; others: all digits, not all zeros), 2 invalid. Per item `source | idState << 3 |
     * typeState << 5 | type << 7 | existing << 9 | titleState << 10 | startedState << 12 | country << 14 | decision << 15 |
     * min(aliases, 255) << 18 | min(idLength, 63) << 26 | min(titleLength, 255) << 32 | min(droppedAliases, 255) << 40`:
     * the type is 0 empty (TYPE_TV), 1 a trimmed `0`-`2`, 2 other; existing marks a positive existing video id; the title
     * state is 0 empty, 1 up to 255 trimmed bytes without control bytes, 2 otherwise; the started state is 0 empty, 1 a
     * `YYYY-MM-DD` (`-`, `.` or `/`) in 1900-2099, 2 other; country marks two letters; the comma-separated aliases are
     * trimmed, cut to 180 bytes and kept when non-empty, distinct (any case) and not the title, otherwise dropped; the
     * decision is 0 an unsupported source, 1 an invalid id or type, 2 an existing video, 3 a repeat of an earlier
     * (source, id) request in the batch, 4 no valid title, else 5 an insert. Then the six decision and seven source
     * counts, the distinct (source, id) requests, the kept alias total and the inserts with aliases.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvShowAdderPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63TvShowAdderPlanBatchNative($items) : self::w63TvShowAdderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvShowAdderPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_TV_SHOW_ADDER_TRAILER) ?? self::w63TvShowAdderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TvShowAdderPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0];
        $sources = [0, 0, 0, 0, 0, 0, 0];
        $aliasTotal = $withAliases = 0;
        $requests = [];
        foreach ($items as $item) {
            [$rawSource, $rawId, $rawType, $rawExisting, $rawTitle, $rawAliases, $rawStarted, $rawCountry]
                = explode('|', (string) $item, 8) + ['', '', '', '', '', '', '', ''];
            $sourceText = strtolower(trim($rawSource));
            $position = array_search($sourceText, self::SUPPORTED_SOURCES, true);
            $source = $sourceText === '' ? 0 : ($position === false ? 6 : $position + 1);
            [$idState, $id] = self::w63AdderId($source, $rawId);
            $typeText = trim($rawType);
            [$typeState, $type] = $typeText === '' ? [0, 0] : (\in_array($typeText, ['0', '1', '2'], true) ? [1, (int) $typeText] : [2, 0]);
            $existing = self::w63PositiveState($rawExisting)[0] === 1 ? 1 : 0;
            $title = trim($rawTitle);
            $titleState = self::w63TextState($title);
            $started = self::w63AirState($rawStarted);
            $country = self::w63Country2(trim($rawCountry)) ? 1 : 0;
            $seen = [];
            $dropped = 0;
            if ($rawAliases !== '') {
                $lowerTitle = strtolower($title);
                foreach (explode(',', $rawAliases) as $piece) {
                    $alias = trim($piece);
                    if ($alias === '') {
                        continue;
                    }
                    $lower = strtolower(substr($alias, 0, 180));
                    if ($lower === $lowerTitle || isset($seen[$lower])) {
                        $dropped++;

                        continue;
                    }
                    $seen[$lower] = true;
                }
            }
            $kept = \count($seen);
            if ($source === 0 || $source === 6) {
                $decision = 0;
            } elseif ($idState !== 1 || $typeState === 2) {
                $decision = 1;
            } else {
                $key = $source.':'.$id;
                $fresh = ! isset($requests[$key]);
                $requests[$key] = true;
                $decision = $existing === 1 ? 2 : (! $fresh ? 3 : ($titleState !== 1 ? 4 : 5));
            }
            $decisions[$decision]++;
            $sources[$source]++;
            $aliasTotal += $kept;
            $withAliases += $decision === 5 && $kept > 0 ? 1 : 0;
            $out[] = $source | $idState << 3 | $typeState << 5 | $type << 7 | $existing << 9 | $titleState << 10 | $started << 12
                | $country << 14 | $decision << 15 | min($kept, 255) << 18 | min(\strlen($id), 63) << 26 | min(\strlen($title), 255) << 32
                | min($dropped, 255) << 40;
        }
        array_push($out, ...$decisions, ...$sources);
        array_push($out, \count($requests), $aliasTotal, $withAliases);

        return $out;
    }

    /**
     * (0 empty, 1 valid, 2 invalid; the normalized id) as normalizeIdPhp() for the source index.
     *
     * @return array{int, string}
     */
    private static function w63AdderId(int $source, string $text): array
    {
        $text = trim($text);
        $length = \strlen($text);
        if ($length === 0) {
            return [0, ''];
        }
        $allDigits = strspn($text, '0123456789') === $length;
        if ($source === 5) {
            $body = '';
            if (strtolower($text[0]) === 't') {
                $body = $length > 1 && strtolower($text[1]) === 't' ? substr($text, 2) : substr($text, 1);
            }
            $bodyLength = \strlen($body);
            if ($bodyLength >= 5 && $bodyLength <= 10 && strspn($body, '0123456789') === $bodyLength) {
                return [1, $body];
            }

            return [$allDigits ? 1 : 2, $text];
        }

        return [$allDigits && strspn($text, '0') !== $length ? 1 : 2, $text];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w63Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
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
     * (0 empty, 1 positive, 2 other; the value) for w63IntState().
     *
     * @return array{int, int}
     */
    private static function w63PositiveState(string $text): array
    {
        [$state, $value] = self::w63IntState($text);

        return $state === 0 ? [0, 0] : ($state === 1 && $value > 0 ? [1, $value] : [2, 0]);
    }

    /** 0 empty, 1 a trimmed `YYYY-MM-DD` (`-`, `.` or `/` twice) in 1900-2099, 2 other. */
    private static function w63AirState(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        if (\strlen($text) !== 10 || ! \in_array($text[4], ['-', '.', '/'], true) || $text[7] !== $text[4]) {
            return 2;
        }
        $y = self::w63Digits(substr($text, 0, 4), 4, 4);
        $m = self::w63Digits(substr($text, 5, 2), 2, 2);
        $d = self::w63Digits(substr($text, 8, 2), 2, 2);

        return $y !== null && $m !== null && $d !== null && $y >= 1900 && $y <= 2099 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31 ? 1 : 2;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w633', self::W63_TV_SHOW_ADDER_FFI_CDEF) ?? false;

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
     * Calls the Wave 63.3 TvShowAdder export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W63_TV_SHOW_ADDER_FFI_CDEF.)
            $written = $ffi->nntmux_w633_tv_show_adder_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
