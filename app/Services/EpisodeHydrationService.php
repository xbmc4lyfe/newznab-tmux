<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TvEpisode;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use FFI\CData;
use Illuminate\Support\Collection;
use Throwable;

class EpisodeHydrationService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_episode_parse_searchname(const char *searchname, size_t searchname_len, uint8_t flags, int32_t *out_series, int32_t *out_episode);
        ssize_t nntmux_episode_hydrate_batch_nul(const char *searchnames, size_t searchnames_len, const char *firstaired, size_t firstaired_len, const char *flags, size_t flags_len, size_t count, char *out_buf, size_t out_cap);
        C;

    private ?FFI $ffi = null;

    private bool $ffiResolved = false;

    private ?CData $singleOut = null;

    private ?CData $seriesOutPtr = null;

    private ?CData $episodeOutPtr = null;

    public function __construct(
        private readonly ?string $libraryPath = null,
    ) {}

    /**
     * Hydrate missing season/episode numbers on release objects.
     * Uses episode metadata from the database, then falls back to regex parsing of searchname.
     *
     * @param  iterable<int, object>  $releases
     */
    public function hydrateEpisodeMetadata(iterable $releases): void
    {
        $this->hydrateEpisodeMetadataNative($releases);
    }

    /**
     * Hydrate release episode metadata via the batch Rust FFI accelerator, falling back to PHP.
     *
     * @param  iterable<int, object>  $releases
     */
    public function hydrateEpisodeMetadataNative(iterable $releases, bool &$supported = false): void
    {
        $supported = false;
        $items = is_array($releases) ? array_values($releases) : iterator_to_array($releases, false);
        $ffi = $this->ffi();
        if ($ffi === null) {
            $this->hydrateEpisodeMetadataPhp($items);

            return;
        }
        if ($items === []) {
            $supported = true;

            return;
        }

        $episodeIds = [];
        foreach ($items as $r) {
            $epId = (int) ($r->tv_episodes_id ?? 0);
            if ($epId > 0) {
                $episodeIds[$epId] = $epId;
            }
        }

        /** @var Collection<int, TvEpisode>|null $episodeMeta */
        $episodeMeta = $episodeIds !== []
            ? TvEpisode::whereIn('id', array_values($episodeIds))->get()->keyBy('id')
            : null;

        $pending = [];
        $searchnames = [];
        $firstAireds = [];
        $flags = [];
        $hasFirstAired = false;
        $hasNonDefaultFlags = false;

        foreach ($items as $r) {
            $epId = $episodeMeta !== null ? (int) ($r->tv_episodes_id ?? 0) : 0;
            $firstAired = '';

            if ($epId > 0 && $episodeMeta !== null && $episodeMeta->has($epId)) {
                $meta = $episodeMeta[$epId];
                if ($this->isMissing($r->series ?? null)) {
                    $r->series = (int) $meta->series;
                }
                if ($this->isMissing($r->episode ?? null)) {
                    $r->episode = (int) $meta->episode;
                }
                if ((! $meta->series || (int) $meta->series === 0) && ! empty($meta->firstaired)) {
                    $firstAired = (string) $meta->firstaired;
                }
            } elseif (! empty($r->firstaired) && (int) ($r->tv_episodes_id ?? 0) <= 0) {
                $firstAired = (string) $r->firstaired;
            }

            $missingSeries = $this->isMissing($r->series ?? null);
            $missingEpisode = $this->isMissing($r->episode ?? null);
            if (! $missingSeries && ! $missingEpisode) {
                continue;
            }

            $searchname = ! empty($r->searchname) ? (string) $r->searchname : '';
            if ($firstAired === '' && $searchname === '') {
                continue;
            }

            $flag = ($missingSeries ? 1 : 0) | ($missingEpisode ? 2 : 0);
            if ($flag !== 3) {
                $hasNonDefaultFlags = true;
            }
            if ($firstAired !== '') {
                $hasFirstAired = true;
            }

            $pending[] = $r;
            $searchnames[] = $searchname;
            $firstAireds[] = $firstAired;
            $flags[] = chr($flag);
        }

        $pendingCount = count($pending);
        if ($pendingCount === 0) {
            $supported = true;

            return;
        }

        $joinedSearchnames = implode("\0", $searchnames);
        if (substr_count($joinedSearchnames, "\0") !== $pendingCount - 1) {
            $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

            return;
        }

        $joinedFirstAired = '';
        if ($hasFirstAired) {
            $joinedFirstAired = implode("\0", $firstAireds);
            if (substr_count($joinedFirstAired, "\0") !== $pendingCount - 1) {
                $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

                return;
            }
        }

        $flagsStr = $hasNonDefaultFlags ? implode('', $flags) : '';
        $needed = $pendingCount * 8 + 16;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

            return;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_episode_hydrate_batch_nul(
                $joinedSearchnames,
                strlen($joinedSearchnames),
                $joinedFirstAired,
                strlen($joinedFirstAired),
                $flagsStr,
                strlen($flagsStr),
                $pendingCount,
                $scratch,
                $cap,
            );
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

                    return;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_episode_hydrate_batch_nul(
                    $joinedSearchnames,
                    strlen($joinedSearchnames),
                    $joinedFirstAired,
                    strlen($joinedFirstAired),
                    $flagsStr,
                    strlen($flagsStr),
                    $pendingCount,
                    $scratch,
                    $cap,
                );
            }
        } catch (Throwable) {
            $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

            return;
        }

        if (! is_int($written) || $written !== $pendingCount * 8) {
            $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

            return;
        }

        /** @var array<int, int>|false $unpacked */
        $unpacked = unpack('l*', FFI::string($scratch, $written));
        if ($unpacked === false) {
            $this->hydratePendingPhp($pending, $episodeMeta ?? collect());

            return;
        }

        $supported = true;
        $pos = 1;
        foreach ($pending as $r) {
            $s = $unpacked[$pos++];
            $e = $unpacked[$pos++];
            if ($s === -2) {
                if ($episodeMeta !== null && (int) ($r->tv_episodes_id ?? 0) > 0) {
                    $this->hydrateFromMetadata($r, $episodeMeta);
                }
                $this->hydrateFromFirstAired($r);
                $this->hydrateFromSearchname($r);

                continue;
            }
            if ($s >= 0) {
                $r->series = $s;
            }
            if ($e >= 0) {
                $r->episode = $e;
            }
        }
    }

    /**
     * Pure PHP reference implementation of episode metadata hydration.
     *
     * @param  iterable<int, object>  $releases
     */
    public function hydrateEpisodeMetadataPhp(iterable $releases): void
    {
        $episodeIds = collect($releases)->pluck('tv_episodes_id')->filter(fn ($v) => $v > 0)->unique()->values();
        $episodeMeta = $episodeIds->isNotEmpty()
            ? TvEpisode::whereIn('id', $episodeIds)->get()->keyBy('id')
            : collect();

        foreach ($releases as $r) {
            $this->hydrateFromMetadata($r, $episodeMeta);
            $this->hydrateFromFirstAired($r);
            $this->hydrateFromSearchname($r);
        }
    }

    /**
     * Parse a single searchname via `nntmux_episode_parse_searchname`, falling back to PHP.
     *
     * @return array{series: int|null, episode: int|null}
     */
    public function parseSearchnameNative(
        string $searchname,
        bool $missingSeries = true,
        bool $missingEpisode = true,
        bool &$supported = false,
    ): array {
        $supported = false;
        $ffi = $this->ffi();
        if ($ffi === null || $this->seriesOutPtr === null || $this->episodeOutPtr === null || $this->singleOut === null) {
            return $this->parseSearchnamePhp($searchname, $missingSeries, $missingEpisode);
        }

        $flags = ($missingSeries ? 1 : 0) | ($missingEpisode ? 2 : 0);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_episode_parse_searchname(
                $searchname,
                strlen($searchname),
                $flags,
                $this->seriesOutPtr,
                $this->episodeOutPtr,
            );
        } catch (Throwable) {
            return $this->parseSearchnamePhp($searchname, $missingSeries, $missingEpisode);
        }

        if (! is_int($status) || $status < 0) {
            return $this->parseSearchnamePhp($searchname, $missingSeries, $missingEpisode);
        }

        $supported = true;
        $s = (int) $this->singleOut[0];
        $e = (int) $this->singleOut[1];

        return [
            'series' => $s >= 0 ? $s : null,
            'episode' => $e >= 0 ? $e : null,
        ];
    }

    /**
     * Parse a single searchname using the PHP regex cascade.
     *
     * @return array{series: int|null, episode: int|null}
     */
    public function parseSearchnamePhp(
        string $searchname,
        bool $missingSeries = true,
        bool $missingEpisode = true,
    ): array {
        $probe = (object) [
            'searchname' => $searchname,
            'series' => $missingSeries ? null : 1,
            'episode' => $missingEpisode ? null : 1,
        ];
        $this->hydrateFromSearchname($probe);

        return [
            'series' => $missingSeries && is_int($probe->series) ? $probe->series : null,
            'episode' => $missingEpisode && is_int($probe->episode) ? $probe->episode : null,
        ];
    }

    /**
     * @param  list<object>  $pending
     * @param  Collection<int, TvEpisode>  $episodeMeta
     */
    private function hydratePendingPhp(array $pending, Collection $episodeMeta): void
    {
        foreach ($pending as $r) {
            if ((int) ($r->tv_episodes_id ?? 0) > 0) {
                $this->hydrateFromMetadata($r, $episodeMeta);
            }
            $this->hydrateFromFirstAired($r);
            $this->hydrateFromSearchname($r);
        }
    }

    private function ffi(): ?FFI
    {
        if (! $this->ffiResolved) {
            $this->ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('episode_hydration', self::FFI_CDEF, $this->libraryPath);
            if ($ffi !== null) {
                try {
                    $out = $ffi->new('int32_t[2]');
                    $this->seriesOutPtr = FFI::addr($out[0]);
                    $this->episodeOutPtr = FFI::addr($out[1]);
                    $this->singleOut = $out;
                    $this->ffi = $ffi;
                } catch (Throwable) {
                    $this->ffi = null;
                }
            }
        }

        return $this->ffi;
    }

    /**
     * @param  Collection<int, TvEpisode>  $episodeMeta
     */
    private function hydrateFromMetadata(object $r, Collection $episodeMeta): void
    {
        if ($r->tv_episodes_id <= 0 || ! $episodeMeta->has($r->tv_episodes_id)) {
            return;
        }

        $meta = $episodeMeta[$r->tv_episodes_id];

        if ($this->isMissing($r->series)) {
            $r->series = (int) $meta->series;
        }
        if ($this->isMissing($r->episode)) {
            $r->episode = (int) $meta->episode;
        }

        if ((! $meta->series || (int) $meta->series === 0) && ! empty($meta->firstaired)) {
            if ($this->isMissing($r->series)) {
                $r->series = (int) Carbon::parse($meta->firstaired)->format('Y');
            }
            if ($this->isMissing($r->episode)) {
                $r->episode = (int) Carbon::parse($meta->firstaired)->format('md');
            }
        }
    }

    private function hydrateFromFirstAired(object $r): void
    {
        if (empty($r->firstaired) || $r->tv_episodes_id > 0) {
            return;
        }

        if ($this->isMissing($r->series)) {
            $r->series = (int) Carbon::parse($r->firstaired)->format('Y');
        }
        if ($this->isMissing($r->episode)) {
            $r->episode = (int) Carbon::parse($r->firstaired)->format('md');
        }
    }

    private function hydrateFromSearchname(object $r): void
    {
        if (! $this->needsSearchnameParsing($r)) {
            return;
        }

        $patterns = [
            '/\bS(\d{1,2})E(\d{1,3})\b/i',
            '/\b(\d{1,2})x(\d{1,3})\b/i',
            '/\bSeason[\s._-]*(\d{1,2})[\s._-]*Episode[\s._-]*(\d{1,3})\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $r->searchname, $m)) {
                if ($this->isMissing($r->series)) {
                    $r->series = (int) $m[1];
                }
                if ($this->isMissing($r->episode)) {
                    $r->episode = (int) $m[2];
                }

                return;
            }
        }

        // Compact format: 102 = S1E02
        if (preg_match('/\b(\d)(0[1-9]|[1-9]\d)\b/', $r->searchname, $m)) {
            if ($this->isMissing($r->series) && $this->isMissing($r->episode)) {
                $r->series = (int) $m[1];
                $r->episode = (int) $m[2];

                return;
            }
        }

        // Date-based: 2024.01.15
        if (preg_match('/\b(\d{4})[._-](\d{2})[._-](\d{2})\b/', $r->searchname, $m)) {
            if ($this->isMissing($r->series)) {
                $r->series = (int) $m[1];
            }
            if ($this->isMissing($r->episode)) {
                $r->episode = (int) ($m[2].$m[3]);
            }

            return;
        }

        // Part/Pt N
        if (preg_match('/\b(?:Part|Pt)[\s._-]*(\d{1,3})\b/i', $r->searchname, $m)) {
            if ($this->isMissing($r->episode)) {
                $r->episode = (int) $m[1];
                if ($this->isMissing($r->series)) {
                    $r->series = 1;
                }

                return;
            }
        }

        // Ep/E N
        if ($this->isMissing($r->episode) && preg_match('/\bEp?[\s._-]*(\d{1,3})\b/i', $r->searchname, $m)) {
            $r->episode = (int) $m[1];
            if ($this->isMissing($r->series)) {
                $r->series = 1;
            }
        }
    }

    private function isMissing(mixed $value): bool
    {
        return empty($value) || (int) $value === 0;
    }

    private function needsSearchnameParsing(object $r): bool
    {
        return ($this->isMissing($r->series) || $this->isMissing($r->episode)) && ! empty($r->searchname);
    }

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 3.79x, p99 3.59x; uncommon mean 3.96x, p99 3.37x); see RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w503_episode_searchname_hydrate_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    /** Library-free instance whose hydrateFromSearchname() is the PHP reference. */
    private static ?self $w50Reference = null;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 Rust path forced on or off, then restores the previous setting. */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Runs the hydrateFromSearchname() cascade over a batch of rows. Three values per row: the
     * resulting series and episode (`(int)` casts) and flags (-1 skipped because neither number
     * is missing or the searchname is empty per `empty()`, else 1 series set plus 2 episode set,
     * 0 when no rule applied). Then the skipped, unresolved and resolved counts. A number is
     * missing when it casts to 0, as isMissing() decides for scalars.
     *
     * @param  list<array<string, mixed>>  $rows  searchname, series, episode
     * @return list<int>
     */
    public static function w50SearchnameHydrateBatch(array $rows): array
    {
        return self::usesW50RustFfi() ? self::w50SearchnameHydrateBatchNative($rows) : self::w50SearchnameHydrateBatchPhp($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w50SearchnameHydrateBatchNative(array $rows): array
    {
        $count = \count($rows);
        $columns = self::w50Columns($rows, ['searchname' => '', 'series' => 0, 'episode' => 0]);

        return self::w50Invoke(
            implode("\0", $columns['searchname']),
            pack('q*', ...$columns['series']).pack('q*', ...$columns['episode']),
            $count,
            $count * 3 + 3,
        )
            ?? self::w50SearchnameHydrateBatchPhp($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w50SearchnameHydrateBatchPhp(array $rows): array
    {
        $reference = self::$w50Reference ??= new self;
        $out = [];
        $skipped = $unresolved = $resolved = 0;
        foreach ($rows as $row) {
            $searchname = (string) ($row['searchname'] ?? '');
            $series = (int) ($row['series'] ?? 0);
            $episode = (int) ($row['episode'] ?? 0);
            if (($series !== 0 && $episode !== 0) || empty($searchname)) {
                $skipped++;
                array_push($out, $series, $episode, -1);

                continue;
            }
            $probe = (object) ['searchname' => $searchname, 'series' => $series === 0 ? null : $series, 'episode' => $episode === 0 ? null : $episode];
            $reference->hydrateFromSearchname($probe);
            $flags = ($series === 0 && $probe->series !== null ? 1 : 0) | ($episode === 0 && $probe->episode !== null ? 2 : 0);
            $flags === 0 ? $unresolved++ : $resolved++;
            array_push($out, (int) $probe->series, (int) $probe->episode, $flags);
        }
        array_push($out, $skipped, $unresolved, $resolved);

        return $out;
    }

    /**
     * One column per key of $defaults. Rows missing a key read its default; present nulls stay
     * null, which pack() and implode() read as 0 and '' like the defaults they replace.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     * @return array<string, list<mixed>>
     */
    private static function w50Columns(array $rows, array $defaults): array
    {
        $count = \count($rows);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($rows, $key);
            if (\count($column) !== $count) {
                $filled = [];
                foreach ($rows as $row) {
                    $filled[] = $row + $defaults;
                }
                $columns = [];
                foreach ($defaults as $name => $unused) {
                    $columns[$name] = array_column($filled, $name);
                }

                return $columns;
            }
            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * Calls the Wave 50 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing or the call declines (for example a string holding a NUL byte), so the
     * caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w50Invoke(string $blob, string $ints, int $count, int $words): ?array
    {
        self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w50', self::W50_FFI_CDEF) ?? false;
        $ffi = self::$w50Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w50Out === null || self::$w50OutCap < $words) {
                self::$w50OutCap = max(1024, $words * 2);
                self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w503_episode_searchname_hydrate_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w50Out, self::$w50OutCap);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w50Out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** Disabled: common mean 1.47-1.52x and p99 1.12-1.70x missed 1.5x in five paired runs; uncommon passed (mean >= 1.82x, p99 >= 1.75x). See the W43 parity test. */
    public const bool W434_RUST_FFI_DEFAULT = false;

    public const string W434_FFI_CDEF = <<<'C'
        ssize_t nntmux_w434_episode_searchname_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W434_SUMMARY_KEYS = ['total_searchnames', 'empty_searchname_count', 'sxxexx_count', 'cross_count', 'season_episode_count', 'compact_count', 'date_count', 'part_count', 'episode_only_count', 'unresolved_count'];

    private static ?bool $w434UseRustFfi = null;

    private static FFI|false|null $w434Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW434RustFfi(?bool $enable = true): void
    {
        self::$w434UseRustFfi = $enable;
    }

    public static function usesW434RustFfi(): bool
    {
        return self::$w434UseRustFfi ?? self::W434_RUST_FFI_DEFAULT;
    }

    public static function resetW434RustFfi(): void
    {
        self::$w434UseRustFfi = null;
        self::$w434Ffi = null;
    }

    /**
     * Count which hydrateFromSearchname() rule first resolves each trimmed
     * searchname for a release missing both numbers: SxxEyy, NxNN, Season/Episode
     * words, three-digit compact, dotted date, Part/Pt and Ep/E, else unresolved.
     * Entries come from strings, integers, or the searchname or name array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeEpisodeSearchnameBatch(array $entries): array
    {
        return self::usesW434RustFfi()
            ? self::summarizeEpisodeSearchnameBatchNative($entries)
            : self::summarizeEpisodeSearchnameBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeEpisodeSearchnameBatchNative(array $entries): array
    {
        return self::w434Call(self::w434Entries($entries)) ?? self::summarizeEpisodeSearchnameBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeEpisodeSearchnameBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W434_SUMMARY_KEYS, 0);
        foreach (self::w434Entries($entries) as $entry) {
            $summary['total_searchnames']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_searchname_count']++;

                continue;
            }
            $rule = 7;
            foreach (self::W434_EPISODE_RULES as $index => $pattern) {
                if (preg_match($pattern, $entry) === 1) {
                    $rule = $index;
                    break;
                }
            }
            $summary[self::W434_SUMMARY_KEYS[2 + $rule]]++;
        }

        return $summary;
    }

    /** The hydrateFromSearchname() cascade, in order. */
    private const array W434_EPISODE_RULES = [
        '/\bS(\d{1,2})E(\d{1,3})\b/i',
        '/\b(\d{1,2})x(\d{1,3})\b/i',
        '/\bSeason[\s._-]*(\d{1,2})[\s._-]*Episode[\s._-]*(\d{1,3})\b/i',
        '/\b(\d)(0[1-9]|[1-9]\d)\b/',
        '/\b(\d{4})[._-](\d{2})[._-](\d{2})\b/',
        '/\b(?:Part|Pt)[\s._-]*(\d{1,3})\b/i',
        '/\bEp?[\s._-]*(\d{1,3})\b/i',
    ];

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w434Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['searchname'] ?? $item['name'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w434Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w434Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w434', self::W434_FFI_CDEF) ?? false;
            $ffi = self::$w434Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
            $written = $ffi->nntmux_w434_episode_searchname_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
                $written = $ffi->nntmux_w434_episode_searchname_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W434_SUMMARY_KEYS || $result['total_searchnames'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W434_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---- Wave 67.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 12.866x/9.769x, uncommon 13.803x/10.08x); see RustRuntimeStateConsolesEpisodeHydrationFanartForkingW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_EPISODE_HYDRATION_FFI_CDEF = <<<'C'
        intptr_t nntmux_w672_episode_hydration_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W67_EPISODE_HYDRATION_TAIL = 19;

    /**
     * Wave 67 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w67', self::W67_EPISODE_HYDRATION_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w67RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w67EpisodeHydrationPlanBatch() to Rust until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Routes w67EpisodeHydrationPlanBatch() to PHP until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w67EpisodeHydrationPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67EpisodeHydrationPlanBatch(array $rows): array
    {
        return self::usesW67RustFfi() ? self::w67EpisodeHydrationPlanBatchNative($rows) : self::w67EpisodeHydrationPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67EpisodeHydrationPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W67_EPISODE_HYDRATION_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w67RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w67EpisodeHydrationPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W67_EPISODE_HYDRATION_FFI_CDEF.)
            $written = $ffi->nntmux_w672_episode_hydration_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w67EpisodeHydrationPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w67Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w67Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /**
     * 0 empty, 1 a valid `YYYY-MM-DD[( |T)HH:MM:SS]` date in 1900-2099, 2 invalid, 3 a zero date;
     * then the year, month and day of a valid date.
     *
     * @return array{int, int, int, int}
     */
    private static function w67Airdate(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [0, 0, 0, 0];
        }
        $length = \strlen($value);
        if (($length !== 10 && $length !== 19) || $value[4] !== '-' || $value[7] !== '-'
            || strspn($value, '0123456789', 0, 4) !== 4 || strspn($value, '0123456789', 5, 2) !== 2 || strspn($value, '0123456789', 8, 2) !== 2) {
            return [2, 0, 0, 0];
        }
        $h = $i = $s = 0;
        if ($length === 19) {
            if (($value[10] !== ' ' && $value[10] !== 'T') || $value[13] !== ':' || $value[16] !== ':'
                || strspn($value, '0123456789', 11, 2) !== 2 || strspn($value, '0123456789', 14, 2) !== 2 || strspn($value, '0123456789', 17, 2) !== 2) {
                return [2, 0, 0, 0];
            }
            [$h, $i, $s] = [(int) substr($value, 11, 2), (int) substr($value, 14, 2), (int) substr($value, 17, 2)];
        }
        [$y, $m, $d] = [(int) substr($value, 0, 4), (int) substr($value, 5, 2), (int) substr($value, 8, 2)];
        if ($y === 0 && $m === 0 && $d === 0) {
            return [3, 0, 0, 0];
        }
        if ($y < 1900 || $y > 2099 || ! checkdate($m, $d, $y) || $h > 23 || $i > 59 || $s > 59) {
            return [2, 0, 0, 0];
        }

        return [1, $y, $m, $d];
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date (year >= 1). */
    private static function w67DaysFromCivil(int $y, int $m, int $d): int
    {
        $y = $m <= 2 ? $y - 1 : $y;
        $era = intdiv($y, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m > 2 ? $m - 3 : $m + 9) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * Wave 67.2 episode hydration plan. Per `videos_id\tseries\tepisode\tfirstaired\t
     * tv_episodes_id` row `action | source << 3 | airdate_state << 5 | daily << 7 | min(series,
     * 4095) << 8 | min(episode, 4095) << 20 | min(leader, 2^20 - 1) << 32 | (crc32(key) & 0x7FF)
     * << 52`. Video, series, episode and tv episode ids are w67Int() fields of 9, 4, 4 and 9
     * digits, the airdate a w67Airdate() state. No positive video id, an unparsable number or an
     * invalid airdate is malformed (0). A positive series and episode are explicit (source 1);
     * otherwise a valid airdate fills a missing series with its year and a missing episode with
     * `month * 100 + day` (source 2, as hydrateFromFirstAired()); anything else is unresolved
     * (1). A series from 1900 is daily and needs a series up to 2099 and an episode in 101-1231,
     * otherwise the series must be 1-99 and the episode 1-999; out of range rejects (5). The
     * `video:series:episode` key upserts: a repeated key merges into its leader (4), a positive
     * tv episode id updates (3) and the rest insert (2). Then six action, three source and four
     * airdate counts, distinct videos and keys, daily rows, the first and last airdate in days
     * since 1970-01-01 (well-formed rows) and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67EpisodeHydrationPlanBatchPhp(array $rows): array
    {
        $out = [];
        $actions = array_fill(0, 6, 0);
        $sources = array_fill(0, 3, 0);
        $dates = array_fill(0, 4, 0);
        $videos = $keys = [];
        $dailyRows = $minDay = $maxDay = 0;
        $dated = false;
        $index = 0;
        foreach ($rows as $row) {
            [$video, $series, $episode, $firstAired, $tvEpisode] = explode("\t", (string) $row, 5) + ['', '', '', '', ''];
            $video = self::w67Int($video, 9);
            $series = self::w67Int($series, 4);
            $episode = self::w67Int($episode, 4);
            $tvEpisode = self::w67Int($tvEpisode, 9);
            [$dateState, $y, $m, $d] = self::w67Airdate($firstAired);
            $vid = \is_int($video) ? $video : 0;
            $malformed = $vid === 0 || $series === false || $episode === false || $tvEpisode === false || $dateState === 2;
            $s = \is_int($series) ? $series : 0;
            $e = \is_int($episode) ? $episode : 0;
            if ($s > 0 && $e > 0) {
                $source = 1;
            } elseif ($dateState === 1) {
                $s = $s === 0 ? $y : $s;
                $e = $e === 0 ? $m * 100 + $d : $e;
                $source = 2;
            } else {
                $source = 0;
            }
            $daily = $s >= 1900;
            $inRange = $daily ? $s <= 2099 && $e >= 101 && $e <= 1231 : $s >= 1 && $s <= 99 && $e >= 1 && $e <= 999;
            $leader = $hash = 0;
            if ($malformed) {
                $action = 0;
            } elseif ($source === 0) {
                $action = 1;
            } elseif (! $inRange) {
                $action = 5;
            } else {
                $key = $vid.':'.$s.':'.$e;
                $hash = crc32($key) & 0x7FF;
                if (isset($keys[$key])) {
                    [$action, $leader] = [4, $keys[$key]];
                } else {
                    $keys[$key] = $leader = $index;
                    $action = \is_int($tvEpisode) && $tvEpisode > 0 ? 3 : 2;
                }
            }
            if (! $malformed) {
                $videos[$vid] = true;
                $dailyRows += (int) ($daily && $source !== 0);
                if ($dateState === 1) {
                    $day = self::w67DaysFromCivil($y, $m, $d);
                    if ($dated) {
                        $minDay = min($minDay, $day);
                        $maxDay = max($maxDay, $day);
                    } else {
                        [$minDay, $maxDay, $dated] = [$day, $day, true];
                    }
                }
            }
            $actions[$action]++;
            $sources[$source]++;
            $dates[$dateState]++;
            $out[] = $action | $source << 3 | $dateState << 5 | (int) $daily << 7 | min($s, 4095) << 8 | min($e, 4095) << 20 | min($leader, 0xFFFFF) << 32 | $hash << 52;
            $index++;
        }
        array_push($out, ...$actions, ...$sources, ...$dates);
        array_push($out, \count($videos), \count($keys), $dailyRows, $minDay, $maxDay);
        $out[] = self::w67Digest($out);

        return $out;
    }
}
