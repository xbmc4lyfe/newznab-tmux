<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Logical names for metadata indexes (Manticore *_rt / Elasticsearch).
 */
enum SecondarySearchIndex: string
{
    case Music = 'music';
    case Books = 'books';
    case Games = 'games';
    case Console = 'console';
    case Steam = 'steam';
    case Anime = 'anime';

    /**
     * Manticore RT column definitions (CreateManticoreIndexes / auto-create).
     *
     * @return array<string, array<string, mixed>>
     */
    public function manticoreColumns(): array
    {
        return match ($this) {
            self::Music => [
                'title' => ['type' => 'text'],
                'artist' => ['type' => 'text'],
                'year' => ['type' => 'string'],
                'genres_id' => ['type' => 'integer'],
                'cover' => ['type' => 'integer'],
            ],
            self::Books => [
                'title' => ['type' => 'text'],
                'author' => ['type' => 'text'],
                'publishdate' => ['type' => 'string'],
                'cover' => ['type' => 'integer'],
            ],
            self::Games => [
                'title' => ['type' => 'text'],
                'genres_id' => ['type' => 'integer'],
                'releasedate_ts' => ['type' => 'bigint'],
                'cover' => ['type' => 'integer'],
            ],
            self::Console => [
                'title' => ['type' => 'text'],
                'platform' => ['type' => 'string'],
                'genres_id' => ['type' => 'integer'],
                'releasedate_ts' => ['type' => 'bigint'],
                'cover' => ['type' => 'integer'],
            ],
            self::Steam => [
                'name' => ['type' => 'text'],
                'appid' => ['type' => 'integer'],
            ],
            self::Anime => [
                'title' => ['type' => 'text'],
                'anidbid' => ['type' => 'integer'],
                'anilist_id' => ['type' => 'integer'],
                'mal_id' => ['type' => 'integer'],
                'lang' => ['type' => 'string'],
                'title_type' => ['type' => 'string'],
                'media_type' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
        };
    }

    /**
     * Fields used for full-text MATCH (Manticore) / multi_match (ES).
     *
     * @return list<string>
     */
    public function fulltextFields(): array
    {
        return match ($this) {
            self::Music => ['artist', 'title'],
            self::Books => ['author', 'title'],
            self::Games => ['title'],
            self::Console => ['title', 'platform'],
            self::Steam => ['name'],
            self::Anime => ['title'],
        };
    }

    /**
     * Stable integer document id for composite anime title rows (PK is multi-column in MySQL).
     */
    public static function animeTitleDocumentId(int $anidbid, string $type, string $lang, string $title, bool $useRustFfi = false): int
    {
        return ($useRustFfi ? self::animeTitleDocumentIdNative($anidbid, $type, $lang, $title) : null)
            ?? self::animeTitleDocumentIdPhp($anidbid, $type, $lang, $title);
    }

    public static function animeTitleDocumentIdNative(int $anidbid, string $type, string $lang, string $title): ?int
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int64_t nntmux_secondary_index_anime_doc_id(const char *input, size_t len);') ?? false;
        if ($ffi === false) {
            return null;
        }
        $payload = $anidbid.'|'.$type.'|'.$lang.'|'.$title;
        try {
            $id = $ffi->nntmux_secondary_index_anime_doc_id($payload, strlen($payload));

            return is_int($id) && $id >= 0 ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function animeTitleDocumentIdPhp(int $anidbid, string $type, string $lang, string $title): int
    {
        $payload = $anidbid.'|'.$type.'|'.$lang.'|'.$title;

        return (int) sprintf('%u', crc32($payload));
    }

    // Rust is the default only while the paired W63.6 benchmark clears 1.5x on all four metrics.
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_INDEX_FFI_CDEF = <<<'C'
        int32_t nntmux_w636_secondary_index_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W63_INDEX_HEADER = 30;

    private const int W63_MAX_ITEMS = 1_048_576;

    /** Largest document id carried in a row (2^31 - 1). */
    private const int W63_DOC_CAP = 2_147_483_647;

    /** Exact token => `case | source << 3`. */
    private const array W63_INDEX_TOKENS = [
        'music' => 0, 'books' => 1, 'games' => 2, 'console' => 3, 'steam' => 4, 'anime' => 5,
        'Music' => 8, 'Books' => 9, 'Games' => 10, 'Console' => 11, 'Steam' => 12, 'Anime' => 13,
    ];

    /** Lowercased trimmed alias (including the `<value>_rt` table) => case. */
    private const array W63_INDEX_ALIASES = [
        'music' => 0, 'music_rt' => 0, 'audio' => 0, 'mp3' => 0,
        'books' => 1, 'books_rt' => 1, 'book' => 1, 'ebook' => 1, 'ebooks' => 1,
        'games' => 2, 'games_rt' => 2, 'game' => 2, 'pc_games' => 2,
        'console' => 3, 'console_rt' => 3, 'consoles' => 3,
        'steam' => 4, 'steam_rt' => 4, 'steam_apps' => 4,
        'anime' => 5, 'anime_rt' => 5, 'anidb' => 5, 'anime_titles' => 5,
    ];

    /** Manticore column type => field kind. */
    private const array W63_FIELD_KINDS = ['text' => 1, 'string' => 2, 'integer' => 3, 'bigint' => 4];

    /**
     * Plan secondary index lookups in order. Indexes resolve by backing value (source 0), case name (1), an
     * alias of `strtolower(trim($index))` including its `<value>_rt` table (2), else unknown case 6 (3). The
     * exact field name is routed through `manticoreColumns()` and `fulltextFields()`: kind 0 absent, 1 text,
     * 2 string, 3 integer, 4 bigint, 5 blank, with its full-text position (7 when none). Route 0 full-text
     * match, 1 attribute filter, 2 rejected (no such column), 3 no field requested, 4 unknown index. Doc state
     * 0 missing (<= 0), 1 valid, 2 overflow (> 2^31-1); the row carries the id clamped to 0..2^31-1.
     *
     * Layout: `[0]` count, `[1..7]` per case, `[8..11]` per source, `[12..17]` per field kind, `[18..22]` per
     * route, `[23..25]` per doc state, `[26]` mask of known cases, `[27]` first rejected or unknown index
     * (-1), `[28]` total columns of resolved indexes, `[29]` FNV-1a digest; then per record `case | source
     * << 3 | kind << 5 | route << 8 | fulltext position << 11 | doc state << 14 | min(index bytes, 255) << 16
     * | min(field bytes, 255) << 24 | doc << 32`.
     *
     * @param  array<array-key, int|string>  $indexes
     * @param  array<array-key, string>  $fields
     * @param  array<array-key, int>  $documentIds
     * @return list<int>
     */
    public static function w63SecondaryIndexPlanBatch(array $indexes, array $fields, array $documentIds): array
    {
        return (self::usesW63RustFfi() ? self::w63SecondaryIndexPlanBatchNative($indexes, $fields, $documentIds) : null)
            ?? self::w63SecondaryIndexPlanBatchPhp($indexes, $fields, $documentIds);
    }

    /**
     * @param  array<array-key, int|string>  $indexes
     * @param  array<array-key, string>  $fields
     * @param  array<array-key, int>  $documentIds
     * @return list<int>
     */
    public static function w63SecondaryIndexPlanBatchPhp(array $indexes, array $fields, array $documentIds): array
    {
        $indexes = array_values($indexes);
        $count = \count($indexes);
        $fields = self::w63Column($fields, $count, '');
        $documentIds = self::w63Column($documentIds, $count);
        $out = array_fill(0, self::W63_INDEX_HEADER, 0);
        $out[0] = $count;
        $out[27] = -1;
        $digest = 2_166_136_261;
        $cases = self::cases();
        $routes = $columns = [];
        $rows = [];
        foreach ($indexes as $at => $index) {
            $index = (string) $index;
            $field = (string) $fields[$at];
            $code = self::W63_INDEX_TOKENS[$index] ?? null;
            if ($code === null) {
                $case = self::W63_INDEX_ALIASES[strtolower(trim($index))] ?? 6;
                $source = $case === 6 ? 3 : 2;
            } else {
                $case = $code & 7;
                $source = $code >> 3;
            }
            if ($field === '') {
                $kind = 5;
                $position = 7;
            } elseif ($case === 6) {
                $kind = 0;
                $position = 7;
            } else {
                $routes[$case] ??= self::w63FieldRoutes($cases[$case]);
                $route = $routes[$case][$field] ?? 56;
                $kind = $route & 7;
                $position = $route >> 3;
            }
            $route = match (true) {
                $case === 6 => 4,
                $kind === 5 => 3,
                $kind === 0 => 2,
                $position !== 7 => 0,
                default => 1,
            };
            $doc = (int) $documentIds[$at];
            $state = $doc <= 0 ? 0 : ($doc <= self::W63_DOC_CAP ? 1 : 2);
            $out[1 + $case]++;
            $out[8 + $source]++;
            $out[12 + $kind]++;
            $out[18 + $route]++;
            $out[23 + $state]++;
            if ($case < 6) {
                $out[26] |= 1 << $case;
                $out[28] += $columns[$case] ??= \count($cases[$case]->manticoreColumns());
            }
            if (($route === 2 || $route === 4) && $out[27] < 0) {
                $out[27] = $at;
            }
            $row = $case | $source << 3 | $kind << 5 | $route << 8 | $position << 11 | $state << 14 | min(\strlen($index), 255) << 16
                | min(\strlen($field), 255) << 24 | max(0, min($doc, self::W63_DOC_CAP)) << 32;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[29] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $indexes
     * @param  array<array-key, string>  $fields
     * @param  array<array-key, int>  $documentIds
     * @return list<int>|null
     */
    public static function w63SecondaryIndexPlanBatchNative(array $indexes, array $fields, array $documentIds): ?array
    {
        $count = \count($indexes);
        if ($count > self::W63_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w63Strings([...array_values($indexes), ...self::w63Column($fields, $count, '')]);
        $ints = pack('q*', ...self::w63Column($documentIds, $count));
        $words = self::W63_INDEX_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w63Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w636_secondary_index_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * Column => `kind | fulltext position << 3` for one index.
     *
     * @return array<string, int>
     */
    private static function w63FieldRoutes(self $index): array
    {
        $positions = array_flip($index->fulltextFields());
        $routes = [];
        foreach ($index->manticoreColumns() as $column => $definition) {
            $routes[$column] = (self::W63_FIELD_KINDS[$definition['type']] ?? 0) | ($positions[$column] ?? 7) << 3;
        }

        return $routes;
    }

    /**
     * Route the Wave 63.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /**
     * Route the Wave 63.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 63.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $forced = self::w63RustFfiState();

        return \is_bool($forced) ? $forced : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 63.6 Rust batch path forced on or off, then restore the previous routing.
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
     * Wave 63.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w636', self::W63_INDEX_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 63.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w63Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w63RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w63Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w63Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
