<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Enums\SecondarySearchIndex;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class ManticoreIndexRegistry
{
    /**
     * Rust stays opt-in for the Wave 42.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchOutboxElasticManticoreSupportW42ParityTest.php.
     */
    public const bool W425_RUST_FFI_DEFAULT = false;

    private static ?bool $useW425RustFfi = null;

    /**
     * @return array<string, array{settings: array<string, int|string>, columns: array<string, array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        $settings = self::fullTextSettings();

        return [
            'releases' => ['settings' => $settings, 'columns' => [
                'guid' => ['type' => 'string'], 'name' => ['type' => 'text'],
                'searchname' => ['type' => 'text'], 'plainsearchname' => ['type' => 'text'],
                'fromname' => ['type' => 'text'], 'filename' => ['type' => 'text'],
                'category_name' => ['type' => 'string'], 'parent_category' => ['type' => 'string'],
                'sub_category' => ['type' => 'string'], 'group_name' => ['type' => 'string'],
                'categories_id' => ['type' => 'integer'], 'imdbid' => ['type' => 'string'],
                'tmdbid' => ['type' => 'integer'], 'traktid' => ['type' => 'integer'],
                'tvdb' => ['type' => 'integer'], 'tvmaze' => ['type' => 'integer'],
                'tvrage' => ['type' => 'integer'], 'trakt' => ['type' => 'integer'],
                'imdb' => ['type' => 'string'], 'tmdb' => ['type' => 'integer'],
                'videos_id' => ['type' => 'integer'],
                'tv_episodes_id' => ['type' => 'integer'], 'movieinfo_id' => ['type' => 'integer'],
                'anidbid' => ['type' => 'integer'], 'parentid' => ['type' => 'integer'],
                'episode_title' => ['type' => 'text'], 'series' => ['type' => 'integer'],
                'episode' => ['type' => 'integer'], 'firstaired_ts' => ['type' => 'bigint'],
                'size' => ['type' => 'bigint'],
                'postdate_ts' => ['type' => 'bigint'], 'adddate_ts' => ['type' => 'bigint'],
                'totalpart' => ['type' => 'integer'], 'grabs' => ['type' => 'integer'],
                'comments' => ['type' => 'integer'], 'nfostatus' => ['type' => 'integer'],
                'jpgstatus' => ['type' => 'integer'], 'nfoid' => ['type' => 'integer'],
                'reid' => ['type' => 'integer'],
                'passwordstatus' => ['type' => 'bigint'], 'groups_id' => ['type' => 'integer'],
                'nzbstatus' => ['type' => 'integer'], 'haspreview' => ['type' => 'bigint'],
                'media_movie_name' => ['type' => 'text'], 'media_file_name' => ['type' => 'text'],
                'media_unique_id' => ['type' => 'string'], 'media_container_format' => ['type' => 'text'],
                'media_video_format' => ['type' => 'text'], 'media_video_codec' => ['type' => 'text'],
                'media_video_width' => ['type' => 'integer'], 'media_video_height' => ['type' => 'integer'],
                'media_audio_format' => ['type' => 'text'], 'media_audio_channels' => ['type' => 'text'],
                'media_audio_language' => ['type' => 'text'], 'media_subtitle_language' => ['type' => 'text'],
                'has_media_info' => ['type' => 'integer'],
            ]],
            'predb' => ['settings' => $settings, 'columns' => [
                'title' => ['type' => 'text', 'attribute' => true],
                'filename' => ['type' => 'text', 'attribute' => true],
                'source' => ['type' => 'string', 'attribute' => true],
            ]],
            'movies' => ['settings' => $settings, 'columns' => [
                'imdbid' => ['type' => 'string'], 'tmdbid' => ['type' => 'integer'],
                'traktid' => ['type' => 'integer'], 'title' => ['type' => 'text'],
                'year' => ['type' => 'text'], 'genre' => ['type' => 'text'],
                'actors' => ['type' => 'text'], 'director' => ['type' => 'text'],
                'rating' => ['type' => 'text'], 'plot' => ['type' => 'text'],
            ]],
            'tvshows' => ['settings' => $settings, 'columns' => [
                'title' => ['type' => 'text'], 'tvdb' => ['type' => 'integer'],
                'trakt' => ['type' => 'integer'], 'tvmaze' => ['type' => 'integer'],
                'tvrage' => ['type' => 'integer'], 'imdb' => ['type' => 'string'],
                'tmdb' => ['type' => 'integer'], 'started' => ['type' => 'text'],
                'type' => ['type' => 'integer'],
            ]],
            ...self::secondaryDefinitions($settings),
        ];
    }

    /** @return array{ranker: string, fields: array<string, int>, fuzzy_distance: int} */
    public static function profile(string $logical): array
    {
        $fields = match ($logical) {
            'releases' => ['searchname' => 12, 'plainsearchname' => 10, 'name' => 8, 'filename' => 5, 'fromname' => 1],
            'predb' => ['title' => 12, 'filename' => 5],
            'movies' => ['title' => 12, 'director' => 5, 'actors' => 3, 'genre' => 2, 'plot' => 1],
            'tvshows' => ['title' => 12],
            'music' => ['title' => 12, 'artist' => 9],
            'books' => ['title' => 12, 'author' => 9],
            'console' => ['title' => 12],
            'steam' => ['name' => 12],
            'games', 'anime' => ['title' => 12],
            default => [],
        };

        return ['ranker' => 'sph04', 'fields' => $fields, 'fuzzy_distance' => 2];
    }

    /** @return list<string> */
    public static function autocompleteFields(string $logical): array
    {
        return match ($logical) {
            'releases' => ['searchname', 'name'],
            'predb' => ['title', 'filename'],
            'movies', 'tvshows', 'music', 'books', 'games', 'console', 'anime' => ['title'],
            'steam' => ['name'],
            default => [],
        };
    }

    /** @return array<string, int|string> */
    public static function inspectableSettings(): array
    {
        // Manticore 28 omits defaults/no-op settings such as min_prefix_len=0
        // and exact_words from SHOW TABLE ... SETTINGS / SHOW CREATE TABLE.
        return ['min_infix_len' => 2, 'index_field_lengths' => 1];
    }

    /** @param array<string, string> $configuredIndexes */
    public static function logicalName(string $table, array $configuredIndexes = [], bool $useRustFfi = false): ?string
    {
        // Null is a valid result as well as the unavailable-native sentinel.
        return ($useRustFfi ? self::logicalNameNative($table, $configuredIndexes) : null)
            ?? self::logicalNamePhp($table, $configuredIndexes);
    }

    /** @param array<string, string> $configuredIndexes */
    public static function logicalNameNative(string $table, array $configuredIndexes = []): ?string
    {
        foreach ($configuredIndexes as $logical => $configuredTable) {
            if ($configuredTable === $table) {
                return (string) $logical;
            }
        }
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_manticore_registry_logical_name(const char *input, size_t len, char *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->nntmux_manticore_registry_logical_name($table, strlen($table), $scratch, $cap);

            return is_int($written) && $written > 0 && $written <= $cap ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, string> $configuredIndexes */
    public static function logicalNamePhp(string $table, array $configuredIndexes = []): ?string
    {
        foreach ($configuredIndexes as $logical => $configuredTable) {
            if ($configuredTable === $table) {
                return (string) $logical;
            }
        }

        return str_ends_with($table, '_rt') ? array_search($table, self::defaultTableNames(), true) ?: null : null;
    }

    /** @return array<string, string> */
    public static function defaultTableNames(): array
    {
        $names = [];
        foreach (array_keys(self::definitions()) as $logical) {
            $names[$logical] = $logical.'_rt';
        }

        return $names;
    }

    public static function useW425RustFfi(bool $enable = true): void
    {
        self::$useW425RustFfi = $enable;
    }

    public static function usesW425RustFfi(): bool
    {
        return self::$useW425RustFfi ?? self::W425_RUST_FFI_DEFAULT;
    }

    public static function resetW425RustFfi(): void
    {
        self::$useW425RustFfi = null;
    }

    /**
     * Resolves a batch of table names with the precedence of {@see logicalNamePhp()}: the first
     * configured logical name whose table matches, then the default `*_rt` table names. Non-string
     * tables are invalid; `distinct_logical` counts the distinct resolved logical names.
     *
     * @param  array<array-key, mixed>  $tables
     * @param  array<array-key, mixed>  $configuredIndexes
     * @return array{tables: int, invalid: int, configured: int, defaults: int, unresolved: int, distinct_logical: int}
     */
    public static function summarizeTableNameBatch(array $tables, array $configuredIndexes = []): array
    {
        return self::usesW425RustFfi()
            ? self::summarizeTableNameBatchNative($tables, $configuredIndexes)
            : self::summarizeTableNameBatchPhp($tables, $configuredIndexes);
    }

    /**
     * @param  array<array-key, mixed>  $tables
     * @param  array<array-key, mixed>  $configuredIndexes
     * @return array{tables: int, invalid: int, configured: int, defaults: int, unresolved: int, distinct_logical: int}
     */
    public static function summarizeTableNameBatchPhp(array $tables, array $configuredIndexes = []): array
    {
        $configured = self::w425FirstLogicalByTable($configuredIndexes);
        $defaults = self::w425FirstLogicalByTable(self::defaultTableNames());
        $invalid = 0;
        $fromConfig = 0;
        $fromDefaults = 0;
        $unresolved = 0;
        $logical = [];
        foreach ($tables as $table) {
            if (! \is_string($table)) {
                $invalid++;

                continue;
            }
            if (isset($configured[$table])) {
                $fromConfig++;
                $logical[$configured[$table]] = true;

                continue;
            }
            $name = str_ends_with($table, '_rt') ? ($defaults[$table] ?? '') : '';
            if ($name === '' || $name === '0') {
                $unresolved++;

                continue;
            }
            $fromDefaults++;
            $logical[$name] = true;
        }

        return [
            'tables' => \count($tables),
            'invalid' => $invalid,
            'configured' => $fromConfig,
            'defaults' => $fromDefaults,
            'unresolved' => $unresolved,
            'distinct_logical' => \count($logical),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $tables
     * @param  array<array-key, mixed>  $configuredIndexes
     * @return array{tables: int, invalid: int, configured: int, defaults: int, unresolved: int, distinct_logical: int}
     */
    public static function summarizeTableNameBatchNative(array $tables, array $configuredIndexes = []): array
    {
        $parts = [];
        foreach ([$configuredIndexes, self::defaultTableNames()] as $map) {
            $pairs = [];
            foreach ($map as $name => $table) {
                if (\is_string($table)) {
                    array_push($pairs, (string) $name, $table);
                }
            }
            array_push($parts, (string) intdiv(\count($pairs), 2), ...$pairs);
        }
        foreach ($tables as $table) {
            array_push($parts, ...(\is_string($table) ? ['1', $table] : ['0', '']));
        }
        $fields = ElasticsearchResponseHelper::evaluateW425Native(5, $parts);
        if ($fields === null || \count($fields) !== 6) {
            return self::summarizeTableNameBatchPhp($tables, $configuredIndexes);
        }

        return [
            'tables' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'configured' => (int) $fields[2],
            'defaults' => (int) $fields[3],
            'unresolved' => (int) $fields[4],
            'distinct_logical' => (int) $fields[5],
        ];
    }

    /** @return array<string, int|string> */
    private static function fullTextSettings(): array
    {
        return [
            'min_prefix_len' => 0,
            'min_infix_len' => 2,
            'exact_words' => 1,
            'index_field_lengths' => 1,
        ];
    }

    /**
     * @param  array<string, int|string>  $settings
     * @return array<string, array{settings: array<string, int|string>, columns: array<string, array<string, mixed>>}>
     */
    private static function secondaryDefinitions(array $settings): array
    {
        $definitions = [];
        foreach (SecondarySearchIndex::cases() as $index) {
            $definitions[$index->value] = ['settings' => $settings, 'columns' => $index->manticoreColumns()];
        }

        return $definitions;
    }

    /**
     * First logical name (as a string) for every string table, matching `logicalNamePhp()`'s
     * first-match scan.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<string, string>
     */
    private static function w425FirstLogicalByTable(array $map): array
    {
        $byTable = [];
        foreach ($map as $name => $table) {
            if (\is_string($table) && ! isset($byTable[$table])) {
                $byTable[$table] = (string) $name;
            }
        }

        return $byTable;
    }

    /** Table and prefix bytes {@see self::tableResolvePlanBatch()} accepts without flagging. */
    private const string W59_TABLE_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    public const string W59_FFI_CDEF = 'int64_t nntmux_w595_index_resolve_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W59.5 parity test's QUALIFICATION). */
    public const bool W59_RUST_FFI_DEFAULT = true;

    /**
     * Wave 59.5 routing and FFI cache, kept in function-local statics (no static properties, so every W59 class
     * follows the same shape). Without $op it reports the routing; 'set' swaps the override for $val and returns the
     * previous one; 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library; 'registry' returns
     * the cached `[newline-joined logical names, logical => 1-based position]` lookup.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;
        static $registry = null;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w59_5', self::W59_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            case 'registry':
                if ($registry === null) {
                    $logicals = array_map('strval', array_keys(self::definitions()));
                    $positions = [];
                    foreach ($logicals as $position => $logical) {
                        $positions[$logical] ??= $position + 1;
                    }
                    $registry = [implode("\n", $logicals), $positions];
                }

                return $registry;
            default:
                return $enabled ?? self::W59_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW59RustFfi(): bool
    {
        return self::w59RustFfiState() === true;
    }

    /**
     * Runs $callback with tableResolvePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 59.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w59Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w59RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w595_index_resolve_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Logical names and their 1-based positions from {@see definitions()}, cached for the batch helpers.
     *
     * @return array{string, array<string, int>}
     */
    private static function w59Registry(): array
    {
        /** @var array{string, array<string, int>} $registry */
        $registry = self::w59RustFfiState('registry');

        return $registry;
    }

    /**
     * Batch table resolution over `name\tprefix` against the logical names of {@see definitions()}. Six words per
     * record: `[kind, index, base_len, physical_len, physical_hash, flags]`. A non-empty prefix the name starts with is
     * stripped; a remaining `_rt` suffix makes the name physical (kind 2), otherwise logical (kind 1). Index is the
     * 1-based definition position of the base name (0 unknown); a resolved name's physical table is
     * `prefix.base._rt`, with its length and FNV-1a 32 hash (both 0 when unresolved). An empty base is kind 0. Flags:
     * 1 prefix stripped, 2 unknown base, 4 empty base, 8 prefix over 32 bytes or outside `[a-z0-9_]`, 16 name outside
     * `[a-z0-9_]`, 32 not exactly two fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function tableResolvePlanBatch(array $records): array
    {
        return self::usesW59RustFfi() ? self::tableResolvePlanBatchNative($records) : self::tableResolvePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function tableResolvePlanBatchPhp(array $records): array
    {
        $positions = self::w59Registry()[1];
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 2 ? 0 : 32;
            $name = $fields[0];
            $prefix = $fields[1] ?? '';
            $prefixLength = \strlen($prefix);
            if ($prefixLength > 32 || strspn($prefix, self::W59_TABLE_BYTES) !== $prefixLength) {
                $flags |= 8;
            }
            if (strspn($name, self::W59_TABLE_BYTES) !== \strlen($name)) {
                $flags |= 16;
            }
            $stripped = $name;
            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $stripped = substr($name, $prefixLength);
                $flags |= 1;
            }
            $kind = 1;
            $base = $stripped;
            if (str_ends_with($stripped, '_rt')) {
                $kind = 2;
                $base = substr($stripped, 0, -3);
            }
            $index = 0;
            if ($base === '') {
                $flags |= 4;
                $kind = 0;
            } else {
                $index = $positions[$base] ?? 0;
                if ($index === 0) {
                    $flags |= 2;
                }
            }
            if ($index > 0) {
                $physical = $prefix.$base.'_rt';
                array_push($out, $kind, $index, \strlen($base), \strlen($physical), (int) hexdec(hash('fnv1a32', $physical)), $flags);
            } else {
                array_push($out, $kind, 0, \strlen($base), 0, 0, $flags);
            }
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function tableResolvePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        // The first record is the logical name header; it produces no words.
        return self::w59Words($count + 1, self::w59Registry()[0]."\0".implode("\0", $records), 6 * $count)
            ?? self::tableResolvePlanBatchPhp($records);
    }
}
