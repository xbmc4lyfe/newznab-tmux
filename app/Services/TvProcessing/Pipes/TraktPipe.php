<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for Trakt API lookups.
 */
class TraktPipe extends AbstractApiProviderPipe
{
    public const int PIPE_KIND = 4;

    public const string FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_tv_pipe_resolve_spec(
        int32_t pipe_kind,
        int64_t *out_priority,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_pipe_is_available(
        int32_t pipe_kind,
        uint32_t has_unavailable_reason,
        const char *reason_ptr,
        size_t reason_len
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustTvProcessingPipesProviderParityTest.php.
     */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected int $priority = 50;

    /** Wave 49.3 batch default (benchmark-gated; see tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php). */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_trakt_ids_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 Rust path forced on or off, then restores the previous setting. */
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
     * Five values per Trakt search result: the trakt id (negative ids read as 0), the imdb number
     * from a trailing `tt` plus 6+ digits (-1 beyond 18 digits, 0 without one), the year (from a
     * `YYYY-MM-DD` first_aired prefix, else `year` clamped to [0, 9999]), flags (1 trakt, 2 tvdb,
     * 4 tmdb, 8 imdb, 16 tvrage ids present, 32 title had a ` (19xx)`/` (20xx)` suffix, 64 empty
     * normalized title) and the FNV-1a hash of the normalized title without that suffix. Then the
     * resolvable (trakt id) count, the fully linked (trakt, tvdb, tmdb and imdb) count and the
     * first resolvable index (-1 when none). Missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $results  title, first_aired, imdb, year, trakt, tvdb, tmdb, tvrage
     * @return list<int>
     */
    public static function w49IdsBatch(array $results): array
    {
        return self::usesW49RustFfi() ? self::w49IdsBatchNative($results) : self::w49IdsBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49IdsBatchNative(array $results): array
    {
        $strings = self::w48Columns($results, ['title' => '', 'first_aired' => '', 'imdb' => '']);
        $ints = self::w48Columns($results, ['year' => 0, 'trakt' => 0, 'tvdb' => 0, 'tmdb' => 0, 'tvrage' => 0]);
        $count = \count($results);

        return self::w49Invoke(
            implode("\0", array_merge(...array_values($strings))),
            pack('q*', ...array_merge(...array_values($ints))),
            $count,
            $count * 5 + 3,
        )
            ?? self::w49IdsBatchPhp($results);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    public static function w49IdsBatchPhp(array $results): array
    {
        $out = [];
        $resolvable = 0;
        $linked = 0;
        $first = -1;
        $index = 0;
        foreach ($results as $item) {
            $title = (string) ($item['title'] ?? '');
            $stripped = (string) preg_replace('/ \((19|20)\d{2}\)$/D', '', $title);
            $normalized = self::w48Normalize($stripped);
            $trakt = max(0, (int) ($item['trakt'] ?? 0));
            $imdb = preg_match('/tt(\d{6,})$/iD', (string) ($item['imdb'] ?? ''), $m) === 1
                ? (\strlen($m[1]) <= 18 ? (int) $m[1] : -1)
                : 0;
            $year = preg_match('/^(\d{4})-\d{2}-\d{2}/', (string) ($item['first_aired'] ?? ''), $m) === 1
                ? (int) $m[1]
                : max(0, min(9999, (int) ($item['year'] ?? 0)));
            $flags = ($trakt > 0 ? 1 : 0)
                | ((int) ($item['tvdb'] ?? 0) > 0 ? 2 : 0)
                | ((int) ($item['tmdb'] ?? 0) > 0 ? 4 : 0)
                | ($imdb > 0 ? 8 : 0)
                | ((int) ($item['tvrage'] ?? 0) > 0 ? 16 : 0)
                | ($stripped !== $title ? 32 : 0)
                | ($normalized === '' ? 64 : 0);
            if ($trakt > 0) {
                $resolvable++;
                if ($first < 0) {
                    $first = $index;
                }
            }
            if (($flags & 15) === 15) {
                $linked++;
            }
            array_push($out, $trakt, $imdb, $year, $flags, self::w48Fnv($normalized));
            $index++;
        }
        array_push($out, $resolvable, $linked, $first);

        return $out;
    }

    /**
     * Calls the Wave 49 export with NUL-joined row-major strings and packed `i64` integers. Null
     * when the library is missing or the call declines (for example a string holding a NUL byte),
     * so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w49Invoke(string $blob, string $ints, int $count, int $words): ?array
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w49', self::W49_FFI_CDEF) ?? false;
        $ffi = self::$w49Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w49Out === null || self::$w49OutCap < $words) {
                self::$w49OutCap = max(1024, $words * 2);
                self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_tv_trakt_ids_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
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
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function getName(): string
    {
        return self::resolvePipeSpec()['name'];
    }

    protected function siteColumn(): string
    {
        return self::resolvePipeSpec()['site_column'];
    }

    protected function createProvider(): AbstractTvProvider
    {
        return new TraktProvider;
    }

    protected function isAvailable(): bool
    {
        $sourceKey = self::resolvePipeSpec()['source_key'];

        return self::evaluateAvailability(MetadataSources::unavailableReason($sourceKey));
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpec(): array
    {
        if (self::usesRustFfi()) {
            return self::resolvePipeSpecNative();
        }

        return self::resolvePipeSpecPhp();
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecPhp(): array
    {
        return [
            'name' => 'Trakt',
            'site_column' => 'trakt',
            'priority' => 50,
            'source_key' => MetadataSources::TRAKT,
        ];
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolvePipeSpecPhp();
        }

        try {
            $outPriority = $ffi->new('int64_t[1]');
            $outLens = $ffi->new('size_t[3]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($buf === null) {
                return self::resolvePipeSpecPhp();
            }

            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::resolvePipeSpecPhp();
                }
                $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            }
            if ($rc < 0) {
                return self::resolvePipeSpecPhp();
            }

            $nameLen = (int) $outLens[0];
            $colLen = (int) $outLens[1];
            $srcLen = (int) $outLens[2];
            $packed = $rc === 0 ? '' : FFI::string($buf, $rc);

            return [
                'name' => substr($packed, 0, $nameLen),
                'site_column' => substr($packed, $nameLen, $colLen),
                'priority' => (int) $outPriority[0],
                'source_key' => substr($packed, $nameLen + $colLen, $srcLen),
            ];
        } catch (Throwable) {
            return self::resolvePipeSpecPhp();
        }
    }

    public static function evaluateAvailability(?string $unavailableReason): bool
    {
        if (self::usesRustFfi()) {
            return self::evaluateAvailabilityNative($unavailableReason);
        }

        return self::evaluateAvailabilityPhp($unavailableReason);
    }

    public static function evaluateAvailabilityPhp(?string $unavailableReason): bool
    {
        return $unavailableReason === null;
    }

    public static function evaluateAvailabilityNative(?string $unavailableReason): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }

        try {
            $hasReason = $unavailableReason !== null ? 1 : 0;
            $str = $unavailableReason ?? '';
            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', self::PIPE_KIND, $hasReason, $str, strlen($str));
            if ($rc < 0) {
                return self::evaluateAvailabilityPhp($unavailableReason);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    // ========================================================================
    // WAVE 62.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 9.91x, p99 6.33x; uncommon mean 7.64x, p99 5.08x); see RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_TRAKT_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w623_trakt_pipe_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 62.3 batch. */
    public const int W62_TRAKT_PIPE_TRAILER = 12;

    /** C0 control bytes and DEL. */
    private const string W62_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the TraktPipe Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.3 Trakt lookup plans. Each item is `title|slug|traktId|tvdb|imdb|season|episode|unavailableReason`, every
     * field trimmed. Per item `titleState | slugState << 2 | traktState << 4 | tvdbState << 6 | imdbState << 8 |
     * seasonState << 10 | episodeState << 12 | unavailable << 14 | route << 15 | queryKind << 18 |
     * min(slugLength, 255) << 20 | season << 28 | episode << 38`: the title is 0 empty, 1 up to 255 bytes without
     * control bytes or 2 invalid; the slug is 0 empty, 1 up to 100 `[A-Za-z0-9-]` bytes without edge or doubled
     * hyphens or 2 invalid; ids are 0 empty, 1 a positive 1-10 digit id (IMDb also `tt` and 7-10 digits) or 2
     * invalid; the season is a 1-3 and the episode a 1-4 digit number (0 empty, 2 invalid); a non-empty reason marks
     * the pipe unavailable. A valid title derives a slug from its lowercase `[a-z0-9]+` tokens joined by `-`; the
     * slug length is the valid slug's, else the derived one's. The route is 0 unavailable, 1 skip, 2 Trakt id, 3 slug,
     * 4 IMDb lookup, 5 TVDB lookup or 6 derived-slug search; the query kind is 0 for routes 0-1, else 3 episode
     * (season and an episode above 0), 2 season or 1 show. Then the seven route and four query kind counts and the
     * distinct lowercase slugs of routes 3 and 6.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TraktPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62TraktPlanBatchNative($items) : self::w62TraktPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TraktPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_TRAKT_PIPE_TRAILER) ?? self::w62TraktPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TraktPlanBatchPhp(array $items): array
    {
        $out = [];
        $routes = [0, 0, 0, 0, 0, 0, 0];
        $queries = [0, 0, 0, 0];
        $slugs = [];
        foreach ($items as $item) {
            [$rawTitle, $rawSlug, $rawTrakt, $rawTvdb, $rawImdb, $rawSeason, $rawEpisode, $reason] = explode('|', (string) $item, 8)
                + ['', '', '', '', '', '', '', ''];
            $title = trim($rawTitle);
            $titleState = self::w62TextState($title);
            $slug = trim($rawSlug);
            $slugLength = \strlen($slug);
            $slugState = $slugLength === 0 ? 0 : ($slugLength <= 100 && strspn($slug, self::W62_SLUG_CHARS) === $slugLength && $slug[0] !== '-'
                && $slug[-1] !== '-' && ! str_contains($slug, '--') ? 1 : 2);
            $derived = $titleState === 1 ? implode('-', self::w62Tokens($title)) : '';
            [$traktState] = self::w62IdState(trim($rawTrakt));
            [$tvdbState] = self::w62IdState(trim($rawTvdb));
            [$imdbState] = self::w62ImdbState(trim($rawImdb));
            $text = trim($rawSeason);
            $value = self::w62Digits($text, 1, 3);
            [$seasonState, $season] = $text === '' ? [0, 0] : ($value === null ? [2, 0] : [1, $value]);
            $text = trim($rawEpisode);
            $value = self::w62Digits($text, 1, 4);
            [$episodeState, $episode] = $text === '' ? [0, 0] : ($value === null ? [2, 0] : [1, $value]);
            $unavailable = trim($reason) !== '' ? 1 : 0;
            $route = match (true) {
                $unavailable === 1 => 0,
                $traktState === 1 => 2,
                $slugState === 1 => 3,
                $imdbState === 1 => 4,
                $tvdbState === 1 => 5,
                $derived !== '' => 6,
                default => 1,
            };
            $query = match (true) {
                $route <= 1 => 0,
                $seasonState === 1 && $episodeState === 1 && $episode > 0 => 3,
                $seasonState === 1 => 2,
                default => 1,
            };
            if ($route === 3) {
                $slugs[strtolower($slug)] = true;
            } elseif ($route === 6) {
                $slugs[$derived] = true;
            }
            $routes[$route]++;
            $queries[$query]++;
            $out[] = $titleState | $slugState << 2 | $traktState << 4 | $tvdbState << 6 | $imdbState << 8 | $seasonState << 10 | $episodeState << 12
                | $unavailable << 14 | $route << 15 | $query << 18 | min($slugState === 1 ? $slugLength : \strlen($derived), 255) << 20
                | min($season, 1023) << 28 | min($episode, 16383) << 38;
        }
        array_push($out, ...$routes, ...$queries);
        $out[] = \count($slugs);

        return $out;
    }

    /** Bytes a Trakt slug may hold. */
    private const string W62_SLUG_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-';

    /**
     * (0 empty, 1 a positive 1-10 digit id, 2 invalid; the id or 0).
     *
     * @return array{int, int}
     */
    private static function w62IdState(string $text): array
    {
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w62Digits($text, 1, 10);

        return $value !== null && $value > 0 ? [1, $value] : [2, 0];
    }

    /**
     * As w62IdState(), also accepting `tt` (any case) and 7-10 digits.
     *
     * @return array{int, int}
     */
    private static function w62ImdbState(string $text): array
    {
        if (\strlen($text) >= 2 && strtolower(substr($text, 0, 2)) === 'tt') {
            $value = self::w62Digits(substr($text, 2), 7, 10);

            return $value !== null && $value > 0 ? [1, $value] : [2, 0];
        }

        return self::w62IdState($text);
    }

    /** 0 empty, 1 up to 255 bytes without control bytes, 2 otherwise. */
    private static function w62TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W62_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w62Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w62Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w62Digits($text, 4, 4) : null;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w62Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w623', self::W62_TRAKT_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.3 TraktPipe export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_TRAKT_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w623_trakt_pipe_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
