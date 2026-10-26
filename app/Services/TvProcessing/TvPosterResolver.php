<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Services\FanartTvService;
use App\Services\TmdbClient;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 49.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.54x, p99 2.13x; uncommon mean 3.82x, p99 2.82x); see RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_poster_pick_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Poster candidate picks for a batch: three ints per show, [chosen index, span, mask].
     *
     * Candidates are tried in selectPosterUrl() order (provider, TMDB, Fanart.tv). A trimmed
     * candidate qualifies when it starts with http://, https:// or // (case-insensitive),
     * names a host, and its path before any '?' or '#' ends in .jpg, .jpeg, .png or .webp.
     * The index is the first qualifying candidate (-1 when none) and the span its trimmed
     * URL as offset << 32 | length (0 when none). Mask bit i marks qualifying candidate i;
     * bit 3 + i marks a non-empty one.
     *
     * @param  list<string>  $providerPosters
     * @param  list<string>  $tmdbPosters
     * @param  list<string>  $fanartPosters
     * @return list<int>
     */
    public static function pickPosterBatch(array $providerPosters, array $tmdbPosters, array $fanartPosters): array
    {
        return self::usesW49RustFfi()
            ? self::pickPosterBatchNative($providerPosters, $tmdbPosters, $fanartPosters)
            : self::pickPosterBatchPhp($providerPosters, $tmdbPosters, $fanartPosters);
    }

    /**
     * @param  list<string>  $providerPosters
     * @param  list<string>  $tmdbPosters
     * @param  list<string>  $fanartPosters
     * @return list<int>
     */
    public static function pickPosterBatchNative(array $providerPosters, array $tmdbPosters, array $fanartPosters): array
    {
        self::w49AssertColumns($providerPosters, $tmdbPosters, $fanartPosters);
        if ($providerPosters === []) {
            return [];
        }
        $text = implode("\0", $providerPosters)."\0".implode("\0", $tmdbPosters)."\0".implode("\0", $fanartPosters);

        return self::w49Call($text, '', \count($providerPosters))
            ?? self::pickPosterBatchPhp($providerPosters, $tmdbPosters, $fanartPosters);
    }

    /**
     * @param  list<string>  $providerPosters
     * @param  list<string>  $tmdbPosters
     * @param  list<string>  $fanartPosters
     * @return list<int>
     */
    public static function pickPosterBatchPhp(array $providerPosters, array $tmdbPosters, array $fanartPosters): array
    {
        self::w49AssertColumns($providerPosters, $tmdbPosters, $fanartPosters);
        $out = [];
        foreach ($providerPosters as $i => $providerPoster) {
            $chosen = -1;
            $span = 0;
            $mask = 0;
            foreach ([$providerPoster, $tmdbPosters[$i], $fanartPosters[$i]] as $index => $candidate) {
                $url = trim($candidate);
                if ($url === '') {
                    continue;
                }
                $mask |= 8 << $index;
                $lower = strtolower($url);
                $rest = str_starts_with($lower, 'https://') ? 8 : (str_starts_with($lower, 'http://') ? 7 : (str_starts_with($lower, '//') ? 2 : 0));
                if ($rest === 0 || ! isset($lower[$rest]) || $lower[$rest] === '/') {
                    continue;
                }
                $path = substr($lower, 0, strcspn($lower, '?#'));
                if (! str_ends_with($path, '.jpg') && ! str_ends_with($path, '.jpeg') && ! str_ends_with($path, '.png') && ! str_ends_with($path, '.webp')) {
                    continue;
                }
                $mask |= 1 << $index;
                if ($chosen < 0) {
                    $chosen = $index;
                    $span = (strspn($candidate, " \t\n\r\0\x0B") << 32) | \strlen($url);
                }
            }
            array_push($out, $chosen, $span, $mask);
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
            $written = $ffi->nntmux_w49_tv_poster_pick_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
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
    // WAVE 62.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs reached 2.88x common mean, 2.58x common p99, 2.40x uncommon mean and 2.23x uncommon p99; see RustTvMazeTvdbPipeOutcomeWriterPosterResolverRunStateW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_POSTER_RESOLVER_FFI_CDEF = 'ssize_t nntmux_w624_tv_poster_resolution_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per posterResolutionPlanRowBatch() record. */
    public const int W62_ROW_WORDS = 5;

    /** int64 summary words after the posterResolutionPlanRowBatch() records (none for an empty batch). */
    public const int W62_TRAILER_WORDS = 7;

    /** Control bytes and the space, which no poster URL or path may hold. */
    private const string W62_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f";

    private const int W62_MAX_BYTES = 20_971_520;

    private const int W62_MAX_DIMENSION = 20_000;

    /** Score per source: none, provider, TMDB, Fanart.tv. */
    private const array W62_SOURCE_SCORES = [0, 300, 200, 100];

    /**
     * TV poster resolution plan rows for [provider poster, TMDB path, Fanart.tv URL, content type,
     * width, height, TMDB id, TVDB id, bytes] items.
     *
     * Candidates are trimmed and classified by w62Candidate(). The fallback chain selects an
     * http(s) provider poster (source 1), else an https or root-relative TMDB path with a TMDB id
     * (2), else an http(s) Fanart.tv URL with a TVDB id (3), else none (0). Content types ignore
     * parameters: 0 empty, 1 jpeg, 2 png, 3 webp, 4 gif, 5 other image, 6 otherwise. Dimensions of
     * 1..20000 give the aspect (width * 1000 / height; class 1 poster-shaped 600..750, 2 other),
     * 0 x 0 is class 0 and anything else class 3.
     *
     * Rows [source | provider class << 4 | TMDB class << 8 | Fanart.tv class << 12 | selected
     * extension << 16 | content type << 20 | dimension class << 24 | flags << 28 (1 http, 2
     * extension/content type mismatch, 4 over 20 MiB, 8 negative bytes or bad dimensions, 16
     * invalid provider poster), selected hash (0), selected bytes, aspect (-1), score], then the
     * trailer [provider, TMDB, Fanart.tv, none, poster-shaped, max bytes, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function posterResolutionPlanRowBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::posterResolutionPlanRowBatchNative($items) : self::posterResolutionPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function posterResolutionPlanRowBatchPhp(array $items): array
    {
        self::w62Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$providerPoster, $tmdbPath, $fanartUrl, $contentType, $width, $height, $tmdbId, $tvdbId, $bytes]) {
            $provider = trim($providerPoster);
            $tmdb = trim($tmdbPath);
            $fanart = trim($fanartUrl);
            [$providerClass, $providerExt] = self::w62Candidate($provider);
            [$tmdbClass, $tmdbExt] = self::w62Candidate($tmdb);
            [$fanartClass, $fanartExt] = self::w62Candidate($fanart);
            if ($providerClass === 1 || $providerClass === 2) {
                [$source, $selected, $class, $ext] = [1, $provider, $providerClass, $providerExt];
            } elseif (($tmdbClass === 1 || $tmdbClass === 3) && $tmdbId > 0) {
                [$source, $selected, $class, $ext] = [2, $tmdb, $tmdbClass, $tmdbExt];
            } elseif (($fanartClass === 1 || $fanartClass === 2) && $tvdbId > 0) {
                [$source, $selected, $class, $ext] = [3, $fanart, $fanartClass, $fanartExt];
            } else {
                [$source, $selected, $class, $ext] = [0, '', 0, 0];
            }
            $type = strtolower(trim($contentType));
            $base = trim(substr($type, 0, strcspn($type, ';')));
            $contentClass = match (true) {
                $base === '' => 0,
                $base === 'image/jpeg', $base === 'image/jpg' => 1,
                $base === 'image/png' => 2,
                $base === 'image/webp' => 3,
                $base === 'image/gif' => 4,
                \strlen($base) > 6 && str_starts_with($base, 'image/') => 5,
                default => 6,
            };
            if ($width === 0 && $height === 0) {
                $dimension = 0;
                $aspect = -1;
            } elseif ($width >= 1 && $width <= self::W62_MAX_DIMENSION && $height >= 1 && $height <= self::W62_MAX_DIMENSION) {
                $aspect = intdiv($width * 1000, $height);
                $dimension = $aspect >= 600 && $aspect <= 750 ? 1 : 2;
            } else {
                $dimension = 3;
                $aspect = -1;
            }
            $mismatch = $ext > 0 && $contentClass > 0 && $ext !== $contentClass;
            $flags = ($class === 2 ? 1 : 0) | ($mismatch ? 2 : 0) | ($bytes > self::W62_MAX_BYTES ? 4 : 0)
                | ($bytes < 0 || $dimension === 3 ? 8 : 0) | ($providerClass >= 3 ? 16 : 0);
            $score = self::W62_SOURCE_SCORES[$source] + ($dimension === 1 ? 50 : 0) + ($class === 1 ? 20 : 0) - ($mismatch ? 30 : 0);
            $hash = $selected === '' ? 0 : self::w62Fnv($selected);
            $word = $source | $providerClass << 4 | $tmdbClass << 8 | $fanartClass << 12 | $ext << 16 | $contentClass << 20
                | $dimension << 24 | $flags << 28;
            array_push($out, $word, $hash, \strlen($selected), $aspect, $score);
            $summary[$source === 0 ? 3 : $source - 1]++;
            if ($dimension === 1) {
                $summary[4]++;
            }
            if ($bytes >= 0 && $bytes <= self::W62_MAX_BYTES && $bytes > $summary[5]) {
                $summary[5] = $bytes;
            }
            $value = $word ^ $hash << 24 ^ $score;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[6] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * [class, extension] of a trimmed poster candidate: class 0 empty, 1 https URL, 2 http URL,
     * 3 root-relative path, 4 invalid (control bytes or spaces, another scheme, or a host without
     * an inner dot); extension 1 jpg/jpeg, 2 png, 3 webp, 4 gif, 0 other.
     *
     * @return array{0: int, 1: int}
     */
    private static function w62Candidate(string $value): array
    {
        $length = \strlen($value);
        if ($length === 0) {
            return [0, 0];
        }
        if (strcspn($value, self::W62_CONTROL) !== $length) {
            return [4, 0];
        }
        $head = strtolower(substr($value, 0, 8));
        if (str_starts_with($head, 'https://')) {
            $class = 1;
            $rest = substr($value, 8);
        } elseif (str_starts_with($head, 'http://')) {
            $class = 2;
            $rest = substr($value, 7);
        } elseif ($value[0] === '/' && ($length === 1 || $value[1] !== '/')) {
            $class = 3;
            $rest = $value;
        } else {
            return [4, 0];
        }
        if ($class !== 3) {
            $hostLength = strcspn($rest, '/?#');
            $host = substr($rest, 0, $hostLength);
            if ($host === '' || ! str_contains($host, '.') || $host[0] === '.' || $host[$hostLength - 1] === '.') {
                return [4, 0];
            }
            $rest = substr($rest, $hostLength);
        }
        $path = strtolower(substr($rest, 0, strcspn($rest, '?#')));
        $ext = match (true) {
            str_ends_with($path, '.jpg'), str_ends_with($path, '.jpeg') => 1,
            str_ends_with($path, '.png') => 2,
            str_ends_with($path, '.webp') => 3,
            str_ends_with($path, '.gif') => 4,
            default => 0,
        };

        return [$class, $ext];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function posterResolutionPlanRowBatchNative(array $items): array
    {
        self::w62Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w62Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3)], $count);
        $ints = pack('P*', ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w62Call($blob, $twin, $ints, $count, $count * self::W62_ROW_WORDS + self::W62_TRAILER_WORDS)
            ?? self::posterResolutionPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w62Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W62 TV poster resolution plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W62 TV poster resolution plan batch items must be [string provider poster, string tmdb path, string fanart url, string content type, int width, int height, int tmdb id, int tvdb id, int bytes] lists.');
            }
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W62 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('enabled');
        self::w62RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w62Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w62Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w62RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w62', self::W62_POSTER_RESOLVER_FFI_CDEF) ?? false;
            self::w62RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w62RustFfiState('out', $out);
                self::w62RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W62_POSTER_RESOLVER_FFI_CDEF.)
            $written = $ffi->nntmux_w624_tv_poster_resolution_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w62RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W62 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int. */
    private static function w62Fnv(string $bytes): int
    {
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
