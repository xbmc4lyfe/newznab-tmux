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
}
