<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\AdultController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\GamesController;
use App\Http\Controllers\MusicController;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_MEDIA_BROWSE_CONTROLLERS_BENCH=1`,
 * `NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w26-3/release/libnntmux_pipeline.dylib`, release profile,
 * 3 rounds x 61 paired samples x 200 iterations of one simulated browse page per controller,
 * warmup=8, seeded random order (mt_srand(263)), Apple Silicon Darwin, PHP 8.5.12; p99 is the
 * 61st-of-61 sample; speedup = PHP latency / Rust latency):
 *
 * - Adult (order-by map + display name): median 0.43x-0.45x, p99 0.36x-0.43x
 *   (PHP median 0.251-0.264 ms, Rust median 0.581-0.593 ms).
 * - Books (order-by map + display name + 25 overview excerpts): median 1.49x-1.52x, p99 1.34x-1.85x
 *   (PHP median 5.013-6.392 ms, Rust median 3.307-4.296 ms).
 * - Console (display name + 25 review excerpts): median 1.69x-1.76x, p99 1.46x-1.65x
 *   (PHP median 4.876-5.677 ms, Rust median 2.764-3.253 ms).
 * - Games (order-by map + display name + filter validation): median 0.17x-0.18x, p99 0.17x-0.18x
 *   (PHP median 0.169-0.184 ms, Rust median 0.982-1.045 ms).
 * - Music (order-by map + display name + stripslashes inputs): median 0.24x, p99 0.24x-0.29x
 *   (PHP median 0.214-0.256 ms, Rust median 0.892-1.073 ms).
 * - A second full run measured Adult 0.43x-0.48x, Books median 1.52x-1.55x with p99 1.34x-1.52x,
 *   Console median 1.74x-1.75x with p99 1.44x-1.71x, Games 0.16x-0.27x and Music 0.23x-0.29x.
 * - Overall: per-call FFI marshaling dominates the short string builders, and the excerpt-heavy
 *   Books/Console pages miss the 1.5x p99 threshold in at least one round per run (before the
 *   page's database and view costs, which dilute any gain further). PHP stays the production
 *   default (`$useRustFfi = false`) for all 5 controllers.
 */
class RustMediaBrowseControllersParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_media_browse_order_by_paths(
        uint8_t kind,
        const char *id_ptr,
        size_t id_len,
        const char *category_ptr,
        size_t category_len,
        const char *extra_ptr,
        size_t extra_len,
        const char *orderings_ptr,
        size_t orderings_len,
        size_t ordering_count,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_media_browse_category_display_name(
        uint8_t is_all,
        uint8_t has_title,
        const char *title_ptr,
        size_t title_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_media_browse_truncate_words(
        const char *text_ptr,
        size_t text_len,
        size_t max_words,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_media_browse_game_filters(
        const char *category_ptr,
        size_t category_len,
        const int64_t *category_ids,
        size_t category_count,
        int64_t default_category,
        const char *year_ptr,
        size_t year_len,
        int64_t min_year,
        int64_t max_year,
        const char *genre_ptr,
        size_t genre_len,
        const int64_t *genre_ids,
        size_t genre_count,
        int64_t *out_fields
    );
    ssize_t nntmux_media_browse_strip_inputs(
        const char *artist_ptr,
        size_t artist_len,
        const char *title_ptr,
        size_t title_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * @var list<class-string>
     */
    private const CLASSES = [
        AdultController::class,
        BooksController::class,
        ConsoleController::class,
        GamesController::class,
        MusicController::class,
    ];

    private const BROWSE_ORDERING = [
        'name_asc', 'name_desc', 'cat_asc', 'cat_desc', 'posted_asc', 'posted_desc',
        'size_asc', 'size_desc', 'files_asc', 'files_desc', 'stats_asc', 'stats_desc',
    ];

    private const MIN_YEAR = 1903;

    private const MAX_YEAR = 2027;

    /**
     * Category id => model stand-in, as built by `GamesController::show()`.
     */
    private const GAME_CATEGORIES = [4010 => 'x', 4020 => 'x', 4030 => 'x', 4050 => 'x', 4060 => 'x'];

    /**
     * Genre id => title, as built by the genre lookup.
     */
    private const GAME_GENRES = [1 => 'Action', 7 => 'Puzzle', 12 => 'Racing', 40 => 'RPG'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to the PHP implementation");

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), "{$class} null toggle restores the PHP default");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: int|string, 3: string}>
     */
    public static function orderByProvider(): array
    {
        return [
            'browse_all' => [self::BROWSE_ORDERING, '', 6000, ''],
            'zero_id_is_all' => [['title_asc', 'title_desc'], '0', '7020', '&title=&author='],
            'named_category' => [['posted_asc'], 'Ebook', 7020, '&title=a b&author=O\'Brien'],
            'utf8_and_query_chars' => [['size_desc', 'ünï'], 'Ünï & ?x=1', '-1', '&title=🙂&author=%20'],
            'string_category' => [['files_asc'], 'Packs', ' 6010 ', ''],
            'empty_ordering' => [[], 'Ebook', 7020, ''],
            'single_empty_ordering' => [[''], '', 0, ''],
            'duplicate_ordering' => [['a', 'b', 'a'], 'x', 1, ''],
            'nul_ordering_declines_to_php' => [["name\0asc", 'cat_asc'], 'x', 1, ''],
            'nul_id' => [['name_asc'], "a\0b", "1\0", "&title=\0"],
        ];
    }

    /**
     * @param  list<string>  $ordering
     */
    #[DataProvider('orderByProvider')]
    public function test_order_by_paths_parity(array $ordering, string $id, int|string $category, string $extra): void
    {
        $this->injectFfi();

        $idOrAll = $id ?: 'All';
        $expectedAdult = [];
        $expectedBooks = [];
        $expectedGames = [];
        $expectedMusic = [];
        foreach ($ordering as $type) {
            $expectedAdult['orderby'.$type] = '/XXX/'.$idOrAll.'?t='.$category.'&ob='.$type;
            $expectedBooks['orderby'.$type] = '/Books/'.$idOrAll.'?t='.$category.$extra.'&ob='.$type.'&offset=0';
            $expectedGames['orderby'.$type] = 'Games?ob='.$type;
            $expectedMusic['orderby'.$type] = 'music/'.$idOrAll.'?ob='.$type;
        }

        $this->assertSame($expectedAdult, AdultController::orderByPathsPhp($ordering, $id, $category));
        $this->assertSame($expectedBooks, BooksController::orderByPathsPhp($ordering, $id, $category, $extra));
        $this->assertSame($expectedGames, GamesController::orderByPathsPhp($ordering));
        $this->assertSame($expectedMusic, MusicController::orderByPathsPhp($ordering, $id));

        $this->assertSame($expectedAdult, AdultController::orderByPathsNative($ordering, $id, $category));
        $this->assertSame($expectedBooks, BooksController::orderByPathsNative($ordering, $id, $category, $extra));
        $this->assertSame($expectedGames, GamesController::orderByPathsNative($ordering));
        $this->assertSame($expectedMusic, MusicController::orderByPathsNative($ordering, $id));

        foreach ([false, true] as $useRust) {
            $this->setAllModes($useRust);
            $this->assertSame($expectedAdult, AdultController::orderByPaths($ordering, $id, $category));
            $this->assertSame($expectedBooks, BooksController::orderByPaths($ordering, $id, $category, $extra));
            $this->assertSame($expectedGames, GamesController::orderByPaths($ordering));
            $this->assertSame($expectedMusic, MusicController::orderByPaths($ordering, $id));
        }
    }

    /**
     * @return array<string, array{0: int|string, 1: string|null, 2: string}>
     */
    public static function categoryNameProvider(): array
    {
        return [
            'all_int' => [-1, 'Ignored', 'All'],
            'all_string' => ['-1', 'Ignored', 'All'],
            'all_padded_string' => [' -1', 'Ignored', 'All'],
            'missing_category' => [7020, null, 'All'],
            'found' => [7020, 'Ebook', 'Ebook'],
            'found_string_id' => ['7020', 'Ebook', 'Ebook'],
            'empty_title' => [7020, '', ''],
            'utf8_title' => [5000, 'Ünï 🙂', 'Ünï 🙂'],
            'nul_title' => [5000, "a\0b", "a\0b"],
            'non_numeric_category' => ['abc', 'Root', 'Root'],
        ];
    }

    #[DataProvider('categoryNameProvider')]
    public function test_category_display_name_parity(int|string $category, ?string $title, string $expected): void
    {
        $this->injectFfi();

        foreach ([AdultController::class, BooksController::class, ConsoleController::class, GamesController::class, MusicController::class] as $class) {
            $this->assertSame($expected, $class::categoryDisplayNamePhp($category, $title), $class);
            $this->assertSame($expected, $class::categoryDisplayNameNative($category, $title), $class);
            foreach ([false, true] as $useRust) {
                $class::setUseRustFfi($useRust);
                $this->assertSame($expected, $class::categoryDisplayName($category, $title), $class);
            }
        }
    }

    public function test_excerpt_words_parity_with_edge_cases_and_random_text(): void
    {
        $this->injectFfi();

        $cases = [
            ['', 50], ['0', 50], ['single', 1], ['a b c', 3], ['a b c d', 3], ['a  b', 2], [' a', 1],
            ['abc', 0], ['a b ', 2], ["tab\tsep\nnew line", 1], ['trailing ', 1], ['ünï 🙂 x', 2],
            [str_repeat('word ', 60), 50], [str_repeat('w ', 50).'w', 50], ["nul\0 byte x", 2],
            ['negative falls back', -1], ['negative falls back too', -2],
        ];
        mt_srand(2631);
        $alphabet = ['a', 'b', ' ', ' ', '  ', "\t", 'ü', '.'];
        for ($i = 0; $i < 400; $i++) {
            $text = '';
            $len = mt_rand(0, 120);
            for ($j = 0; $j < $len; $j++) {
                $text .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }
            $cases[] = [$text, mt_rand(0, 60)];
        }

        foreach ([BooksController::class, ConsoleController::class] as $class) {
            foreach ($cases as [$text, $maxWords]) {
                $php = $class::excerptWordsPhp($text, $maxWords);
                $this->assertSame($php, $class::excerptWordsNative($text, $maxWords), $class.' '.json_encode([$text, $maxWords]));
                foreach ([false, true] as $useRust) {
                    $class::setUseRustFfi($useRust);
                    $this->assertSame($php, $class::excerptWords($text, $maxWords));
                }
            }
        }

        $this->assertSame(str_repeat('word ', 49).'word...', BooksController::excerptWordsPhp(str_repeat('word ', 60), 50));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function gameFilterProvider(): array
    {
        return [
            'all_valid' => ['4050', '2020', '7'],
            'defaults' => ['', '', ''],
            'leading_zero_keys' => ['04050', '02020', '07'],
            'padded_keys' => [' 4050', ' 2020 ', '7 '],
            'negative_zero' => ['-0', '-0', '-0'],
            'float_keys' => ['4050.0', '2020.9', '7.0'],
            'exponent_year' => ['4060', '2.02e3', '1'],
            'exponent_out_of_range' => ['4060', '1e3', '12'],
            'year_bounds' => ['4010', '1903', '40'],
            'year_below' => ['4010', '1902', '41'],
            'year_upper' => ['4010', '2027', '40'],
            'year_above' => ['4010', '2028', '40'],
            'hex_year' => ['4010', '0x7E4', '40'],
            'signed_year' => ['4010', '+2020', '+7'],
            'overflow' => ['9223372036854775808', '99999999999999999999', '9223372036854775807'],
            'huge_exponent' => ['4010', '1e999', '1'],
            'nul_suffix' => ["4050\0", "2020\0", "7\0"],
            'whitespace_only' => [' ', "\t\n", ' '],
            'unknown_ids' => ['9999', 'abcd', '999'],
            'dot_forms' => ['4050.', '2020.', '.5e4'],
            'vertical_tab_and_formfeed' => ['4050', "\x0B2020\x0C", '7'],
        ];
    }

    #[DataProvider('gameFilterProvider')]
    public function test_game_filters_parity(string $categoryInput, string $yearInput, string $genreInput): void
    {
        $this->injectFfi();

        $this->assertGameFilterParity($categoryInput, $yearInput, $genreInput, self::GAME_CATEGORIES, self::GAME_GENRES);
    }

    public function test_game_filters_match_original_controller_logic(): void
    {
        $years = range(self::MIN_YEAR, self::MAX_YEAR);
        rsort($years);
        foreach (self::gameFilterProvider() as [$categoryInput, $yearInput, $genreInput]) {
            $category = 4050;
            if ($categoryInput !== '' && isset(self::GAME_CATEGORIES[$categoryInput])) {
                $category = (int) $categoryInput;
            }
            $yearValue = is_numeric($yearInput) ? (int) $yearInput : null;
            $year = $yearValue !== null && \in_array($yearValue, $years, true) ? $yearValue : '';
            $genre = isset(self::GAME_GENRES[$genreInput]) ? $genreInput : '';

            $this->assertSame(
                ['category' => $category, 'year' => $year, 'genre' => $genre],
                GamesController::gameFiltersPhp($categoryInput, self::GAME_CATEGORIES, 4050, $yearInput, self::MIN_YEAR, self::MAX_YEAR, $genreInput, self::GAME_GENRES)
            );
        }
    }

    public function test_game_filters_randomized_numeric_parity(): void
    {
        $this->injectFfi();

        mt_srand(2632);
        $alphabet = ['0', '1', '2', '4', '5', '9', '.', 'e', 'E', '+', '-', ' ', "\t", 'x'];
        for ($i = 0; $i < 3000; $i++) {
            $inputs = [];
            for ($k = 0; $k < 3; $k++) {
                $text = '';
                $len = mt_rand(0, 7);
                for ($j = 0; $j < $len; $j++) {
                    $text .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
                }
                $inputs[] = $text;
            }
            $this->assertGameFilterParity($inputs[0], $inputs[1], $inputs[2], [0 => 'x', 1 => 'x', 5 => 'x', 10 => 'x', 2020 => 'x'], [-5 => 'a', 0 => 'b', 24 => 'c']);
        }

        // Plausible year strings, including float/exponent forms around the bounds.
        for ($i = 0; $i < 1000; $i++) {
            $value = mt_rand(1890, 2040) + mt_rand(0, 99) / 100;
            foreach ([(string) $value, sprintf('%.3e', $value), ' '.$value, $value.' ', '-'.$value] as $yearInput) {
                $this->assertGameFilterParity('', $yearInput, '', self::GAME_CATEGORIES, self::GAME_GENRES);
            }
        }
    }

    public function test_game_filters_null_values_and_string_keys(): void
    {
        $this->injectFfi();

        // `isset()` ignores null values, so a genre without a title never validates.
        $genres = [1 => 'Action', 2 => null];
        $this->assertGameFilterParity('', '', '2', self::GAME_CATEGORIES, $genres);
        $this->assertSame('', GamesController::gameFiltersNative('', self::GAME_CATEGORIES, 4050, '', 1903, 2027, '2', $genres)['genre']);

        // String keys require PHP lookup semantics, so the native path declines to PHP.
        $categories = ['abc' => 'x', 4050 => 'x'];
        $this->assertGameFilterParity('abc', '', '', $categories, self::GAME_GENRES);
        $this->assertSame(0, GamesController::gameFiltersNative('abc', $categories, 4050, '', 1903, 2027, '', [])['category']);

        // An inverted year range declines to PHP.
        $this->assertSame(
            GamesController::gameFiltersPhp('', [], 4050, '2000', 2027, 1903, '', []),
            GamesController::gameFiltersNative('', [], 4050, '2000', 2027, 1903, '', [])
        );

        // Empty id maps.
        $this->assertGameFilterParity('4050', '2000', '1', [], []);
    }

    public function test_browse_inputs_parity_with_edge_cases_and_random_text(): void
    {
        $this->injectFfi();

        $cases = [
            ['', ''], ['O\\\'Brien', 'Title'], ['a\\\\b', 'x\\0y'], ['end\\', '\\'], ['\\n\\t', '\\"q\\"'],
            ["nul\0raw", 'ünï\\ 🙂'], ['plain', ''], ['', 'plain'],
        ];
        mt_srand(2633);
        $alphabet = ['\\', '\\', '0', "'", '"', 'a', ' ', "\0", 'ü'];
        for ($i = 0; $i < 500; $i++) {
            $pair = [];
            for ($k = 0; $k < 2; $k++) {
                $text = '';
                $len = mt_rand(0, 24);
                for ($j = 0; $j < $len; $j++) {
                    $text .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
                }
                $pair[] = $text;
            }
            $cases[] = $pair;
        }

        foreach ($cases as [$artist, $title]) {
            $expected = [
                'artist' => $artist !== '' ? stripslashes($artist) : '',
                'title' => $title !== '' ? stripslashes($title) : '',
            ];
            $this->assertSame($expected, MusicController::browseInputsPhp($artist, $title));
            $this->assertSame($expected, MusicController::browseInputsNative($artist, $title), json_encode([$artist, $title]) ?: '');
            foreach ([false, true] as $useRust) {
                MusicController::setUseRustFfi($useRust);
                $this->assertSame($expected, MusicController::browseInputs($artist, $title));
            }
        }
    }

    public function test_all_five_controllers_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
            $this->assertSame($class::categoryDisplayNamePhp(7020, 'Ebook'), $class::categoryDisplayName(7020, 'Ebook'));
        }

        $this->assertSame(AdultController::orderByPathsPhp(self::BROWSE_ORDERING, '', 6000), AdultController::orderByPaths(self::BROWSE_ORDERING, '', 6000));
        $this->assertSame(BooksController::orderByPathsPhp(['a'], 'x', 1, '&t='), BooksController::orderByPaths(['a'], 'x', 1, '&t='));
        $this->assertSame(BooksController::excerptWordsPhp('a b c', 2), BooksController::excerptWords('a b c', 2));
        $this->assertSame(ConsoleController::excerptWordsPhp('a b c', 2), ConsoleController::excerptWords('a b c', 2));
        $this->assertSame(GamesController::orderByPathsPhp(['a']), GamesController::orderByPaths(['a']));
        $this->assertSame(
            GamesController::gameFiltersPhp('4050', self::GAME_CATEGORIES, 4010, '2020', 1903, 2027, '7', self::GAME_GENRES),
            GamesController::gameFilters('4050', self::GAME_CATEGORIES, 4010, '2020', 1903, 2027, '7', self::GAME_GENRES)
        );
        $this->assertSame(MusicController::orderByPathsPhp(['a'], ''), MusicController::orderByPaths(['a'], ''));
        $this->assertSame(MusicController::browseInputsPhp('a\\b', 'c'), MusicController::browseInputs('a\\b', 'c'));
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_and_undersized_buffers(): void
    {
        $ffi = $this->requireFfi();

        $raw = $ffi->new('uint8_t[64]');
        $unalignedSize = $ffi->cast('size_t *', FFI::addr($raw[1]));
        $unalignedI64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $lens = $ffi->new('size_t[4]');
        $ids = $ffi->new('int64_t[2]');
        $ids[0] = 4050;
        $ids[1] = 4060;
        $fields = $ffi->new('int64_t[4]');
        $buf = $ffi->new('char[512]');
        $small = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $small[$i] = 'Z';
        }

        // Order-by paths: null input, oversized input, bad kind, count mismatch, null/unaligned lens,
        // null buffer, oversized capacity, then undersized capacity.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, null, 0, '', 0, '', 0, 'a', 1, 1, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, 'a', -1, 1, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 9, '', 0, '', 0, '', 0, 'a', 1, 1, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, "a\0b", 3, 3, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, 'a', 1, 1, null, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, 'a', 1, 1, $unalignedSize, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, 'a', 1, 1, $lens, null, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 0, '', 0, '', 0, '', 0, 'a', 1, 1, $lens, $buf, -1));
        $full = self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 3, '', 0, '', 0, '', 0, "a\0b", 3, 2, $lens, $buf, 512);
        $this->assertSame(\strlen('music/All?ob=a') * 2, $full);
        $lens[0] = 999;
        $this->assertSame(-$full, self::callFfi($ffi, 'nntmux_media_browse_order_by_paths', 3, '', 0, '', 0, '', 0, "a\0b", 3, 2, $lens, $small, 4));
        $this->assertSame(999, (int) $lens[0]);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Category display name.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_category_display_name', 0, 1, null, 0, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_category_display_name', 0, 1, 'x', 1, null, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_category_display_name', 0, 1, 'x', 1, $buf, -1));
        $this->assertSame(-5, self::callFfi($ffi, 'nntmux_media_browse_category_display_name', 0, 1, 'Ebook', 5, $small, 4));
        // A one-byte requirement reports -2 so it never collides with the decline code.
        $this->assertSame(-2, self::callFfi($ffi, 'nntmux_media_browse_category_display_name', 0, 1, 'E', 1, $small, 0));
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Word truncation.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_truncate_words', null, 0, 50, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_truncate_words', 'a', -1, 50, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_truncate_words', 'a', 1, 50, null, 512));
        $this->assertSame(-8, self::callFfi($ffi, 'nntmux_media_browse_truncate_words', 'a b c d', 7, 3, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Game filters write nothing on invalid arguments.
        for ($i = 0; $i < 4; $i++) {
            $fields[$i] = 7;
        }
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', null, 0, $ids, 2, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, $fields));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, null, 0, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, $fields));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, $unalignedI64, 1, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, $fields));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, $ids, -1, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, $fields));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, $ids, 2, 4050, '', 0, 2027, 1903, '', 0, $ids, 2, $fields));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, $ids, 2, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, null));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '', 0, $ids, 2, 4050, '', 0, 1903, 2027, '', 0, $ids, 2, $unalignedI64));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(7, (int) $fields[$i]);
        }
        $this->assertSame(0, self::callFfi($ffi, 'nntmux_media_browse_game_filters', '4060', 4, $ids, 2, 4050, '2020', 4, 1903, 2027, '4050', 4, $ids, 2, $fields));
        $this->assertSame([4060, 1, 2020, 1], [(int) $fields[0], (int) $fields[1], (int) $fields[2], (int) $fields[3]]);

        // Strip inputs.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_strip_inputs', null, 0, '', 0, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_strip_inputs', '', 0, '', 0, $unalignedSize, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_media_browse_strip_inputs', '', 0, '', 0, $lens, null, 512));
        $lens[0] = 999;
        $this->assertSame(-6, self::callFfi($ffi, 'nntmux_media_browse_strip_inputs', 'abc', 3, 'def', 3, $lens, $small, 4));
        $this->assertSame(999, (int) $lens[0]);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        for ($i = 0; $i < 64; $i++) {
            $this->assertSame(0, (int) $raw[$i]);
        }
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_MEDIA_BROWSE_CONTROLLERS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_MEDIA_BROWSE_CONTROLLERS_BENCH=1 to run the paired benchmark.');
        }

        $this->injectFfi();

        mt_srand(2634);
        $overviews = [];
        for ($i = 0; $i < 25; $i++) {
            $overviews[] = implode(' ', array_fill(0, mt_rand(10, 120), 'lorem'));
        }
        $bookOrdering = ['title_asc', 'title_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc', 'files_asc', 'files_desc', 'stats_asc', 'stats_desc'];

        // One simulated page render per iteration: each controller's helpers once, plus the
        // 25 per-result excerpt truncations that Books/Console run on a cover page.
        $workloads = [
            'adult' => static function (int $i): void {
                AdultController::orderByPaths(self::BROWSE_ORDERING, '', 6000 + ($i % 10));
                AdultController::categoryDisplayName(6000, 'XXX');
            },
            'books' => static function (int $i) use ($overviews, $bookOrdering): void {
                BooksController::orderByPaths($bookOrdering, 'Ebook', 7020, '&title=&author=');
                BooksController::categoryDisplayName(7020, 'Ebook');
                foreach ($overviews as $overview) {
                    BooksController::excerptWords($overview, 50);
                }
            },
            'console' => static function (int $i) use ($overviews): void {
                ConsoleController::categoryDisplayName(-1, null);
                foreach ($overviews as $overview) {
                    ConsoleController::excerptWords($overview, 50);
                }
            },
            'games' => static function (int $i) use ($bookOrdering): void {
                GamesController::orderByPaths($bookOrdering);
                GamesController::categoryDisplayName(4050, 'Games');
                GamesController::gameFilters((string) (4010 + 10 * ($i % 6)), self::GAME_CATEGORIES, 4050, (string) (1990 + $i % 40), self::MIN_YEAR, self::MAX_YEAR, (string) ($i % 50), self::GAME_GENRES);
            },
            'music' => static function (int $i) use ($bookOrdering): void {
                MusicController::orderByPaths($bookOrdering, 'MP3');
                MusicController::categoryDisplayName(3010, 'MP3');
                MusicController::browseInputs('O\\\'Brien', 'Greatest \\"Hits\\"');
            },
        ];

        $setAllModes = fn (bool $rust) => $this->setAllModes($rust);
        $measure = static function (callable $workload, bool $rust, int $iterations) use ($setAllModes): float {
            $setAllModes($rust);
            $t0 = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $workload($i);
            }

            return (hrtime(true) - $t0) / 1_000_000;
        };

        $rounds = 3;
        $samples = 61;
        $iterations = 200;
        $summary = [];
        mt_srand(263);
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < 8; $w++) {
                $measure($workload, false, 10);
                $measure($workload, true, 10);
            }

            for ($round = 1; $round <= $rounds; $round++) {
                $phpTimes = [];
                $rustTimes = [];
                for ($s = 0; $s < $samples; $s++) {
                    if (mt_rand(0, 1) === 1) {
                        $rustTimes[] = $measure($workload, true, $iterations);
                        $phpTimes[] = $measure($workload, false, $iterations);
                    } else {
                        $phpTimes[] = $measure($workload, false, $iterations);
                        $rustTimes[] = $measure($workload, true, $iterations);
                    }
                }

                sort($phpTimes);
                sort($rustTimes);
                $p99Index = (int) ceil(0.99 * $samples) - 1;
                $phpMedian = $phpTimes[intdiv($samples, 2)];
                $rustMedian = $rustTimes[intdiv($samples, 2)];
                $summary[] = sprintf(
                    '%s round %d: PHP median=%.3f ms p99=%.3f ms, Rust median=%.3f ms p99=%.3f ms, median speedup=%.2fx, p99 speedup=%.2fx',
                    $name,
                    $round,
                    $phpMedian,
                    $phpTimes[$p99Index],
                    $rustMedian,
                    $rustTimes[$p99Index],
                    $phpMedian / max(0.0001, $rustMedian),
                    $phpTimes[$p99Index] / max(0.0001, $rustTimes[$p99Index])
                );
            }
        }

        fwrite(STDERR, "\n[Wave 26.3 Benchmark]\n".implode("\n", $summary)."\n");
        $this->assertCount($rounds * \count($workloads), $summary);
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @param  array<array-key, mixed>  $categories
     * @param  array<array-key, mixed>  $genres
     */
    private function assertGameFilterParity(string $categoryInput, string $yearInput, string $genreInput, array $categories, array $genres): void
    {
        $php = GamesController::gameFiltersPhp($categoryInput, $categories, 4050, $yearInput, self::MIN_YEAR, self::MAX_YEAR, $genreInput, $genres);
        $message = json_encode([$categoryInput, $yearInput, $genreInput]) ?: '';
        $this->assertSame($php, GamesController::gameFiltersNative($categoryInput, $categories, 4050, $yearInput, self::MIN_YEAR, self::MAX_YEAR, $genreInput, $genres), $message);
        foreach ([false, true] as $useRust) {
            GamesController::setUseRustFfi($useRust);
            $this->assertSame($php, GamesController::gameFilters($categoryInput, $categories, 4050, $yearInput, self::MIN_YEAR, self::MAX_YEAR, $genreInput, $genres), $message);
        }
    }

    private function setAllModes(bool $rust): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi($rust);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function injectFfi(): void
    {
        $ffi = $this->requireFfi();
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting($ffi);
        }
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'categorize',
            self::DIRECT_CDEF,
            is_string($path) && $path !== '' ? $path : null
        );
        if ($ffi === null) {
            $this->markTestSkipped('Rust categorize FFI library is not built or available.');
        }

        return $ffi;
    }
}
