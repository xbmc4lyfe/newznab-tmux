<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BooksProcessor;
use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 66.4 batch helpers: BooksProcessor lookup plan rows (title token flags, ISBN check digits,
 * year, provider fallback order, cover/metadata state, eligibility and batch limit),
 * CategorizationPipeline resolution rows (pipe result parsing, priority order and cutoff, stop
 * and lock guards, multi-pipe best result), CategorizationResult evaluation rows (success,
 * protection, override gate, parent ID, rule tag, confidence band and basis points),
 * CategorizationService routing rows (cache and in-batch hits, empty and hex names, pipeline,
 * group route and legacy fallbacks) and AbstractCategorizationPipe visit rows (stop/skip guard,
 * pipe threshold, passable update and Misc lock). Each export takes a field-major NUL-joined blob
 * (plus a 0x01 twin when a value holds a NUL) and packed int64 columns (IEEE doubles for
 * confidences); Rust writes fixed-width int64 rows and a batch trailer that PHP decodes with one
 * unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w66RustFfiState(); no class declares a new property.
 *
 * The paired benchmark (RUN_RUST_FFI_BENCHMARKS=1) times the PHP and native batch methods,
 * including validation, packing and decoding, over interleaved rounds of 320 records in random
 * order per round (fixed seed). Rounds per workload: W66_BENCH_ROUNDS (default 1000, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Decision: four runs at 0ff724f0b + this change (Apple Silicon, PHP 8.5.12 CLI, release build,
 * 1000 rounds per workload after 10 warmups, load average 5.0-10.2), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): w66BooksProcessorPlanBatch 3.99/3.48, 3.18/2.85;
 * w66CategorizationPipelinePlanBatch 6.37/5.93, 5.73/5.48; w66CategorizationResultPlanBatch
 * 3.19/2.67, 2.77/2.43; w66CategorizationServicePlanBatch 1.75/1.72, 1.75/1.85;
 * w66AbstractPipePlanBatch 2.51/2.21, 2.19/2.04. Rust wins where PHP splits and case-folds
 * tokens, parses and sorts pipe result lines, hashes labels and runs per-record comparisons;
 * service routing is closest to the gate because PHP's str_contains() and array-key cache are
 * native C. Every helper clears 1.5x and defaults to Rust.
 */
final class RustBooksProcessorCategorizationPipelineResultServicePipeW66ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'books' => [BooksProcessor::class, 'w66BooksProcessorPlanBatch', 3, 12],
        'pipeline' => [CategorizationPipeline::class, 'w66CategorizationPipelinePlanBatch', 5, 10],
        'result' => [CategorizationResult::class, 'w66CategorizationResultPlanBatch', 4, 11],
        'service' => [CategorizationService::class, 'w66CategorizationServicePlanBatch', 4, 13],
        'pipe' => [AbstractCategorizationPipe::class, 'w66AbstractPipePlanBatch', 4, 10],
    ];

    /** W66_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        BooksProcessor::class => true,
        CategorizationPipeline::class => true,
        CategorizationResult::class => true,
        CategorizationService::class => true,
        AbstractCategorizationPipe::class => true,
    ];

    private const int SEED = 6642026;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x0c", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', '"', '.', '..', '/', '\\', '-', '_', '|', ',', ':', '*', '(', ')', '[', ']', 'x', 'X', 'A', '0', '9', '978', 'pipe', 'hash_', 'no_match', '|10|', '2019'];

    private const array BOOK_TITLES = ['Author Name - Book Title (2019) EPUB', 'Some.Magazine.Issue.42.2023.PDF', 'Comic Series 001 (2020) (Digital) CBR', 'Author - Title Unabridged M4B',
        'Writer - Novel RETAIL EPUB MOBI', 'National.Geographic.Weekly.March.2024', 'Programming Guide 3rd Edition AZW3', 'Audiobooks - Famous Story MP3 1998',
        'Ünïcödé Bøøk 日本 - 2001 epub', 'J.R.R.Tolkien-The.Hobbit.1937.KF8', 'Some Title', 'Title ISBN 0306406152 mobi', 'Book 080442957X'];

    private const array PROVIDERS = ['', '', 'amazon', 'amazon,google', 'google, isbndb', 'isbndb,amazon,google,openlibrary', 'OpenLibrary , Amazon', 'foo,bar', 'amazon,amazon,google',
        ',,', ' ISBNDB ', 'google,openlibrary,isbndb'];

    private const array BOOK_CATEGORIES = [7020, 7020, 7020, 7010, 7030, 7040, 7060, 3030, 3030, 7999, 7000, 2040, 5040, 0];

    private const array PIPE_NAMES = ['misc', 'GroupName', 'xxx', 'tv', 'movie', 'book', 'music', 'pc', 'console', 'MiscSafetyNet', 'MiscPipe', 'TvPipe', ' moviepipe ', 'custom'];

    private const array MATCHED_BY = ['no_match', 'no_match', 'hash_md5', 'hash_sha1', 'obfuscated_subject', 'gibberish_name', 'group_only_low_signal', 'tv_episode', 'movie_title',
        'book_ebook', 'none', '', 'music_flac', 'pc_0day', 'xxx_web', 'hash', 'Hash_x'];

    private const array CATEGORIES = [10, 10, 20, 5040, 5030, 5070, 2040, 2030, 2060, 3010, 3040, 4010, 4050, 6010, 6040, 7020, 7010, 1030, 1000, 0, 8010];

    private const array PRIORITIES = [1, 5, 10, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    private const array RELEASES = ['Show.Name.S01E02.1080p.WEB.H264-GRP', 'Movie.Title.2024.2160p.UHD.BluRay.x265-TEAM', 'Artist-Album-2023-FLAC-GRP', 'Some.Game-CODEX',
        'Author - Book Title (2019) EPUB', 'Ünïcödé 日本 Release', 'xxx.site.24.01.01.scene.1080p', 'OF.Creator.Name.Clip', 'Windows.Software.v1.2.x64-TEAM', 'random words here'];

    private const array GROUPS = ['alt.binaries.teevee', 'alt.binaries.teevee', 'alt.binaries.moovee', 'Alt.Binaries.HDTV ', 'alt.binaries.sounds.mp3', 'alt.binaries.e-book',
        'alt.binaries.erotica', 'alt.binaries.games.xbox', 'alt.binaries.mac', 'alt.binaries.misc', 'alt.binaries.boneless', ''];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW66RustFfi();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w66RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W66_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w66', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW66RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    private static function fnv(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $value, true));

        return $hash[1];
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length <= 0 ? '' : $random->getBytes($length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 3, 4, -2, 9, 10, 11, 19, 20, 21, 255, 256, 999, 1000, 2999, 3030, 5999, 6000, 6999, 7000, 7999, 8000, 8999, 9000, 9499, 9500,
            9999, 10_000, 10_001, 0xFFFF, 1 << 20, 1_000_000, 1_000_001, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1,
            $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 10_000)]);
    }

    private static function extremeFloat(Randomizer $random): float
    {
        return self::pick($random, [NAN, INF, -INF, -0.0, 0.0, 0.95, 0.9499999999999999, 0.8, 0.7999999999999999, 0.6, 0.5999999999999999, 0.5, 1.0, 2.0, 2.5, -1.0,
            -1.5, 1e300, -1e300, 5e-324, -5e-324, 1e-9, $random->getFloat(-3.0, 3.0), $random->nextFloat()]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    private static function float(Randomizer $random, float $value, bool $uncommon): float
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeFloat($random) : $value;
    }

    private static function confidence(Randomizer $random): float
    {
        return self::pick($random, [0.0, 0.0, 0.3, 0.5, 0.55, 0.6, 0.7, 0.8, 0.85, 0.9, 0.95, 0.97, 1.0, $random->nextFloat()]);
    }

    /** A check-digit valid ISBN-13 or ISBN-10, sometimes with a wrong check digit. */
    private static function isbn(Randomizer $random): string
    {
        if ($random->getInt(0, 1) === 0) {
            $digits = self::pick($random, ['978', '979']).\sprintf('%09d', $random->getInt(0, 999_999_999));
            $sum = 0;
            for ($k = 0; $k < 12; $k++) {
                $sum += (int) $digits[$k] * ($k % 2 === 0 ? 1 : 3);
            }
            $check = (10 - $sum % 10) % 10;
        } else {
            $digits = \sprintf('%09d', $random->getInt(0, 999_999_999));
            $sum = 0;
            for ($k = 0; $k < 9; $k++) {
                $sum += (10 - $k) * (int) $digits[$k];
            }
            $check = (11 - $sum % 11) % 11;
            $check = $check === 10 ? self::pick($random, ['X', 'x']) : $check;
        }

        return $digits.($random->getInt(0, 4) === 0 ? ($random->getInt(1, 9) + (int) $check) % 10 : $check);
    }

    /** @return list<mixed> */
    private static function booksItem(Randomizer $random, bool $uncommon): array
    {
        $title = self::pick($random, self::BOOK_TITLES);
        if ($random->getInt(0, 3) === 0) {
            $title .= ' '.self::isbn($random);
        }

        return [
            self::spoil($random, $title, $uncommon),
            self::spoil($random, self::pick($random, self::PROVIDERS), $uncommon),
            self::int($random, self::pick($random, self::BOOK_CATEGORIES), $uncommon),
            self::int($random, self::pick($random, [0, 0, 0, 0, -2, -2, 123, 0]), $uncommon),
            self::int($random, $random->getInt(0, 1), $uncommon),
            self::int($random, self::pick($random, [1, 1, 1, 2, 2, 0]), $uncommon),
            self::int($random, $random->getInt(0, 4), $uncommon),
            self::int($random, self::pick($random, [0, 0, 100, 50, 5]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function pipelineItem(Randomizer $random, bool $uncommon): array
    {
        $lines = [];
        for ($k = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, $uncommon && $random->getInt(0, 4) === 0 ? 40 : 10); $k > 0; $k--) {
            $confidence = self::pick($random, [0, 3000, 5000, 5999, 6000, 7000, 8000, 9000, 9499, 9500, 9600, 10_000, $random->getInt(0, 10_000)]);
            $lines[] = match ($random->getInt(0, 14)) {
                0 => self::pick($random, ['bad line', 'tv|x|5040|8000|y', 'tv|10|5040|123456|y', 'tv|10|5040', ' ', 'tv|-1|5040|8000|y', 'tv|1234567|5040|8000|y']),
                1 => ' '.self::pick($random, self::PIPE_NAMES).'|10|5040|8000|a|b ',
                default => self::pick($random, self::PIPE_NAMES).'|'.self::pick($random, self::PRIORITIES).'|'.self::pick($random, self::CATEGORIES).'|'.$confidence
                    .'|'.self::pick($random, self::MATCHED_BY),
            };
        }

        return [
            self::spoil($random, self::pick($random, self::RELEASES), $uncommon),
            self::spoil($random, implode(self::pick($random, ["\n", "\n", "\r\n", "\n\n"]), $lines), $uncommon),
            self::int($random, self::pick($random, [0, 0, 0, 50, 10, 100]), $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 1 : 0, $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function resultItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, self::pick($random, self::MATCHED_BY), $uncommon),
            self::spoil($random, self::pick($random, self::MATCHED_BY), $uncommon),
            self::int($random, self::pick($random, self::CATEGORIES), $uncommon),
            self::int($random, self::pick($random, self::CATEGORIES), $uncommon),
            self::float($random, self::confidence($random), $uncommon),
            self::float($random, self::confidence($random), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function serviceItem(Randomizer $random, bool $uncommon): array
    {
        $name = match ($random->getInt(0, 5)) {
            0 => bin2hex($random->getBytes(self::pick($random, [16, 20]))),
            1, 2 => self::pick($random, self::RELEASES).'-'.bin2hex($random->getBytes(3)),
            default => self::pick($random, self::RELEASES),
        };

        return [
            self::spoil($random, $random->getInt(0, 9) === 0 ? strtoupper($name).' ' : $name, $uncommon),
            self::spoil($random, self::pick($random, self::GROUPS), $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? self::pick($random, self::CATEGORIES) : -1, $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? -1 : self::pick($random, self::CATEGORIES), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? -1 : self::pick($random, self::CATEGORIES), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function pipeItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, self::pick($random, self::PIPE_NAMES), $uncommon),
            self::spoil($random, self::pick($random, self::MATCHED_BY), $uncommon),
            self::spoil($random, self::pick($random, self::MATCHED_BY), $uncommon),
            self::int($random, self::pick($random, self::CATEGORIES), $uncommon),
            self::int($random, self::pick($random, self::CATEGORIES), $uncommon),
            self::int($random, self::pick($random, [0, 0, 0, 0, 1, 2, 4, 5, 6]), $uncommon),
            self::float($random, self::confidence($random), $uncommon),
            self::float($random, self::confidence($random), $uncommon),
            self::float($random, self::pick($random, [0.0, 0.0, 0.3, 0.5, 0.6]), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'books' => self::booksItem($random, $uncommon),
                'pipeline' => self::pipelineItem($random, $uncommon),
                'result' => self::resultItem($random, $uncommon),
                'service' => self::serviceItem($random, $uncommon),
                default => self::pipeItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $max = PHP_INT_MAX;
        $min = PHP_INT_MIN;
        $nul = "a\0b";

        return match ($key) {
            'books' => [
                ['Title 2019 EPUB 9780306406157', 'google,isbndb', 7020, 0, 1, 1, 0, 0],
                ['x', '', 3030, -2, 0, 2, 5, 1],
                ['Title 0306406152 080442957x 9780306406158', 'amazon,isbndb', 7020, 0, 0, 1, 0, 0],
                ['Title 1899 2100 20 199 19999', 'openlibrary,openlibrary,AMAZON', 7999, -2, 0, 1, 3, 0],
                ['Title', '', 7000, -2, 0, 1, 2, 0],
                ['Title', '', 7020, 5, 1, 1, 0, 0],
                ['   ', '', 7020, 0, 0, 1, 0, 0],
                ['Title', '', 2040, 0, 0, 1, 0, 0],
                ['Title', '', 7020, 0, 0, 0, 0, 0],
                ['Title', '', 7020, 0, 0, 1, 0, 1],
                ['Title', '', 7020, 0, 0, 1, 0, 1],
                ['EPUB-MOBI_CBR.ISSUE/RETAIL m4b', ' , ,foo', $max, $max, $max, $max, $max, $max],
                ['9790000000001 9790000000000', 'isbndb', 3030, $min, $min, $min, $min, $min],
                ['Ünïcödé Bøøk 日本 2001 éPUB epub', "google\0", 7020, 0, 1, 2, 0, 1_000_001],
                ['', '', 0, 0, 0, 0, 0, 0],
                [$nul, $nul, 7020, 0, 0, 1, 0, 0],
                ["Title\0EPUB", "isbndb\0,amazon", 7020, 0, 0, 1, 0, 0],
            ],
            'pipeline' => [
                ['Rel', "misc|1|10|9000|hash_md5\ntv|20|5040|8000|tv_episode\nbad\nmovie|30|2040|9600|x", 0, 0],
                ['', "tv|5|5040|7000|x\nmovie|3|2040|7000|y", 4, 0],
                ['a', "tv|10|5040|9500|x\nmovie|20|2040|9900|y", 0, 0],
                ['a', "misc|10|10|9000|hash_x\nmovie|20|2040|5000|y\ntv|30|5040|6000|z", 0, 0],
                ['a', "xxx|10|10|9000|obfuscated_x\nmovie|20|2040|5999|y\ntv|30|5040|6000|z", 0, 0],
                ['a', "tv|10|10|5000|x\nmovie|10|2040|5000|y\npc|10|4010|5000|z", 0, 0],
                ['a', "tv|10|5040|0|x\nmovie|10|10|5000|no_match\nmusic|5|3010|10000|ok", 0, 1],
                ['a', "tv|10|5040|99999|x\npc||4010|5000|z\n|10|10|5000|\nmovie|000010|0|00001|", 0, 0],
                ['a', "tv|10|5040|8000|a|b|c\n MISC |1|20|100|hash_\r\n", 10, 0],
                ['a', str_repeat("tv|10|5040|8000|x\n", 40), 0, 0],
                ['a', "tv|10|5040|8000|x\nmovie|20|2040|8500|y", $max, $max],
                ['a', "tv|10|5040|8000|x\nmovie|20|2040|8500|y", $min, $min],
                ['Ünïcödé 日本', "tv|10|5040|8000|日本\nmovie|20|2040|8500|é", 15, 0],
                ['', '', 0, 0],
                [$nul, $nul, 0, 0],
                ["Rel\0x", "tv|10|5040|8000|x\0y\nmisc|1|10|9000|hash_\0", 0, 0],
            ],
            'result' => [
                ['hash_md5', 'no_match', 10, 10, 0.5, 0.0],
                ['tv_x', 'obfuscated_y', 5040, 10, 0.5, 0.75],
                ['tv_x', 'obfuscated_y', 5040, 10, 0.6, 0.75],
                ['tv_x', 'gibberish_y', 20, 10, 0.1, 0.75],
                ['tv_x', 'none', 5040, 10, 0.8, 0.8],
                ['tv_x', 'none', 10, 10, 0.8, 0.8],
                ['tv_x', 'none', 5040, 5030, 0.8, 0.8],
                ['no_match', 'x', 10, 5040, 0.0, 0.0],
                ['group_only_low_signal', 'hash_', 10, 20, -0.0, 0.0],
                ['_lead', 'trail_', 8999, 9000, NAN, NAN],
                ['', '', -1, 0, INF, -INF],
                ['x', 'y', $max, $min, 1e300, -1e300],
                ['x', 'y', $min, $max, 5e-324, -5e-324],
                ['Ünïcödé_日本', 'é', 7020, 7020, 0.9499999999999999, 0.95],
                [$nul, $nul, 10, 10, 0.5, 0.5],
                ["hash_\0", "no_match\0", 10, 10, 0.97, 0.2],
            ],
            'service' => [
                ['Show.S01E02', 'alt.binaries.teevee', -1, 5040, 5040],
                ['show.s01e02 ', ' ALT.binaries.TEEVEE', -1, 2000, 10],
                ['abcdef0123456789abcdef0123456789', '', -1, -1, -1],
                ['ABCDEF0123456789ABCDEF0123456789ABCDEF01', 'alt.binaries.mp3', -1, 5040, -1],
                ['abcdef0123456789abcdef012345678g', 'alt.binaries.mp3', -1, 10, -1],
                ['x', 'alt.binaries.mp3', 9999, 10, -1],
                ['y', 'alt.binaries.boneless', -1, 10, 3010],
                ['z', 'alt.binaries.boneless', -1, 10, 9000],
                ['w', 'alt.binaries.xbox.erotica', 8999, 10, 10],
                ['', '', -1, 5040, 5040],
                ['', '', 0, 5040, 5040],
                ['v', 'alt.binaries.mac', $max, $max, $max],
                ['u', 'alt.binaries.e-book.comics', $min, $min, $min],
                ['Ünïcödé 日本', 'ALT.BINARIES.MÜSIC', -1, -1, -1],
                [$nul, $nul, -1, 5040, 5040],
                ["a\0", 'b', -1, 5040, 5040],
                ['a', "\0b", -1, 2040, 5040],
            ],
            default => [
                ['MiscPipe', 'hash_x', 'no_match', 10, 10, 0, 0.5, 0.0, 0.0],
                ['TvPipe', 'tv', 'none', 5040, 10, 1, 0.5, 0.0, 0.0],
                [' tvpipe ', 'tv', 'none', 5040, 10, 4, 0.5, 0.5, 0.6],
                ['tv', 'tv', 'none', 5040, 10, 0, 0.5, 0.95, 0.0],
                ['tv', 'tv', 'none', 5040, 10, 2, 0.5, 0.5, 0.0],
                ['tv', 'tv', 'hash_y', 5040, 10, 0, 0.59, 0.5, 0.0],
                ['tv', 'tv', 'hash_y', 5040, 10, 0, 0.6, 0.5, 0.0],
                ['tv', 'tv', 'none', 5040, 10, 0, 0.5, 0.5, 0.0],
                ['tv', 'tv', 'none', 5040, 5030, 0, 0.5, 0.5, 0.0],
                ['tv', 'no_match', 'none', 10, 10, 0, 0.5, 0.1, 0.0],
                ['misc', 'tv', 'none', 10, 10, 0, 0.0, 0.0, 0.0],
                ['pipe', 'x', 'y', 1, 2, 0, NAN, 0.1, 0.0],
                ['PIPE', 'x', 'y', 1, 2, 0, 0.5, NAN, NAN],
                ['MiscSafetyNetPipe', 'gibberish_a', 'none', 10, 10, $max, INF, -INF, -INF],
                ['xxx', 'a', 'b', $min, $max, $min, -0.0, 0.0, -0.0],
                ['Ünïcödé', '日本', 'é', 20, 20, 0, 1e300, -1e300, 5e-324],
                [$nul, $nul, $nul, 10, 10, 0, 0.5, 0.0, 0.0],
                ["misc\0", "hash_\0", "\0", 10, 10, 0, 0.7, 0.2, 0.0],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w66_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W66_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W66_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w664_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W66_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W66_TRAILER_WORDS'));
        foreach (['usesW66RustFfi', 'withW66RustFfi', 'enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w66RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW66RustFfi());
        self::assertFalse($class::withW66RustFfi(false, static fn (): bool => $class::usesW66RustFfi()));
        self::assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW66RustFfi(), 'withW66RustFfi restores the default');

        $class::enableW66RustFfi();
        self::assertTrue($class::usesW66RustFfi());
        $class::disableW66RustFfi();
        self::assertFalse($class::usesW66RustFfi());
        self::assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()));
        self::assertFalse($class::usesW66RustFfi(), 'withW66RustFfi restores an explicit override');
        $class::resetW66RustFfi();
        self::assertSame($constant->getValue(), $class::usesW66RustFfi());

        $nested = $class::withW66RustFfi(false, static fn (): array => [
            $class::usesW66RustFfi(),
            $class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()),
            $class::usesW66RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW66RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW66RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w66', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW66RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW66RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        BooksProcessor::withW66RustFfi(false, static function (): void {
            foreach ([CategorizationPipeline::class, CategorizationResult::class, CategorizationService::class, AbstractCategorizationPipe::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW66RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W66_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW66RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [BooksProcessor::usesRustFfi(), BooksProcessor::usesW414RustFfi(), BooksProcessor::usesW53RustFfi(),
            CategorizationPipeline::usesW413RustFfi(), CategorizationPipeline::usesW53RustFfi(), CategorizationResult::$useRustFfi, CategorizationResult::usesW413RustFfi(),
            CategorizationResult::usesW53RustFfi(), CategorizationService::$useRustFfi, CategorizationService::usesW403RustFfi(), CategorizationService::usesW53RustFfi(),
            AbstractCategorizationPipe::$useRustFfi, AbstractCategorizationPipe::usesW463RustFfi(), AbstractCategorizationPipe::usesW53RustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW66RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertTrue(BooksProcessor::dispatchPlanPhp(1, false)['dispatch']);
                    self::assertSame([CategorizationPipeline::W53_SIGNAL_GROUP_SUFFIX], CategorizationPipeline::releaseNameSignalsPhp(['a-GRP']));
                    self::assertFalse(CategorizationResult::noMatch()->isSuccessfulPhp());
                    self::assertSame(1, CategorizationService::summarizeCategoryDistributionPhp([5040])['tv']);
                    self::assertSame(1, AbstractCategorizationPipe::summarizeSkipGuardConfidenceBatchPhp([0.96])['stop']);
                });
            }
        }
        self::assertSame($before, $snapshot());
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
            [array_map(static fn (mixed $v): mixed => \is_string($v) ? null : $v, $first)],
            [array_map(static fn (mixed $v): mixed => \is_float($v) ? (int) $v : (\is_int($v) ? (float) $v : $v), $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W66', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_use_the_twin_frame(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $edges = self::edgeItems($key);
        $nul = array_values(array_filter($edges, static fn (array $item): bool => str_contains(implode('', array_filter($item, 'is_string')), "\0")));
        self::assertNotSame([], $nul);
        self::assertParity($class, $method, $words, $trailer, $nul, $key.' NUL-bearing values');
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': the 0x01 twin frames NUL-bearing values');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::HELPERS as $key => [$class, $method]) {
            $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 9, true);
            $php = $class::{$method.'Php'}($items);
            self::state($class, 'ffi', false);
            self::assertSame($php, $class::{$method.'Native'}($items), $key);
            self::assertSame(0, self::state($class, 'misses'), $key.': a missing library is not a declined call');
            $class::resetW66RustFfi();
        }
    }

    public function test_hand_checked_rows(): void
    {
        $books = BooksProcessor::w66BooksProcessorPlanBatchPhp([
            ['Title 2019 EPUB 9780306406157', 'google,isbndb', 7020, 0, 1, 1, 0, 0],
            ['x', '', 3030, -2, 0, 2, 5, 1],
        ]);
        self::assertSame([6 | 1 << 4 | 1 << 6 | 33 << 8 | 2 << 14 | (3 | 2 << 4) << 19 | 2019 << 43, self::fnv('title 2019 epub 9780306406157'), 0], \array_slice($books, 0, 3));
        self::assertSame([2 | 2 << 6 | 2 << 14 | 1 << 17 | (1 | 2 << 4) << 19 | 5 << 35, self::fnv('x'), -1], \array_slice($books, 3, 3));
        self::assertSame([2, 0, 1, 0, 0, 0, 1, 0, 1, 1, 1], \array_slice($books, 6, 11));

        $pipeline = CategorizationPipeline::w66CategorizationPipelinePlanBatchPhp([
            ['Rel', "misc|1|10|9000|hash_md5\ntv|20|5040|8000|tv_episode\nbad\nmovie|30|2040|9600|x", 0, 0],
            ['', "tv|5|5040|7000|x\nmovie|3|2040|7000|y", 4, 0],
        ]);
        self::assertSame([4 | 3 << 4 | 1 << 10 | 2 << 16 | 1 << 28 | 1 << 44 | 1 << 50 | 1 << 57, 10, 9000, self::fnv('hash_md5'), self::fnv('rel')], \array_slice($pipeline, 0, 5));
        self::assertSame([3 | 2 << 4 | 1 << 10 | 1 << 22 | 1 << 44 | 2 << 50 | 1 << 56, 2040, 7000, self::fnv('y'), 0], \array_slice($pipeline, 5, 5));
        self::assertSame([2, 0, 0, 1, 1, 0, 5, 1, 2], \array_slice($pipeline, 10, 9));

        $result = CategorizationResult::w66CategorizationResultPlanBatchPhp([
            ['hash_md5', 'no_match', 10, 10, 0.5, 0.0],
            ['tv_x', 'obfuscated_y', 5040, 10, 0.5, 0.75],
        ]);
        self::assertSame([1 | 2 | 8 | 16 | 3 << 8 | 1 << 12 | 2 << 16 | 2 << 24 | 8 << 28, 0, 5000, self::fnv('hash')], \array_slice($result, 0, 4));
        self::assertSame([1 | 4 | 16 | 7 << 8 | 4 << 12 | 2 << 16 | 2 << 20 | 1 << 24 | 4 << 28, 5000, 5000, self::fnv('tv')], \array_slice($result, 4, 4));
        self::assertSame([2, 2, 1, 1, 0, 1, 1, 0, 0, 0], \array_slice($result, 8, 10));

        $service = CategorizationService::w66CategorizationServicePlanBatchPhp([
            ['Show.S01E02', 'alt.binaries.teevee', -1, 5040, 5040],
            ['show.s01e02 ', ' ALT.binaries.TEEVEE', -1, 2000, 10],
            ['abcdef0123456789abcdef0123456789', '', -1, -1, -1],
        ]);
        self::assertSame([5 | 3 << 6 | 1 << 11 | 11 << 38, 5040, self::fnv('show.s01e02'), self::fnv('alt.binaries.teevee')], \array_slice($service, 0, 4));
        self::assertSame([2 | 2 << 4 | 3 << 6 | 2 << 11 | 1 << 17 | 11 << 38, 5040, self::fnv('show.s01e02'), self::fnv('alt.binaries.teevee')], \array_slice($service, 4, 4));
        self::assertSame([4 | 20 << 11 | 32 << 38, 20, self::fnv('abcdef0123456789abcdef0123456789'), 0], \array_slice($service, 8, 4));
        self::assertSame([3, 0, 1, 0, 1, 1, 0, 0, 0, 1, 1, 2], \array_slice($service, 12, 12));

        $pipe = AbstractCategorizationPipe::w66AbstractPipePlanBatchPhp([
            ['MiscPipe', 'hash_x', 'no_match', 10, 10, 0, 0.5, 0.0, 0.0],
            ['TvPipe', 'tv', 'none', 5040, 10, 1, 0.5, 0.0, 0.0],
            [' tvpipe ', 'tv', 'none', 5040, 10, 4, 0.5, 0.5, 0.6],
        ]);
        self::assertSame([4 | 1 << 4 | 11 << 8 | 2 << 16 | 8 << 28, 10, 5000, self::fnv('hash_x')], \array_slice($pipe, 0, 4));
        self::assertSame([1 | 4 << 4 | 1 << 8 | 2 << 16 | 6 << 28, 10, 0, self::fnv('none')], \array_slice($pipe, 4, 4));
        self::assertSame([3 | 4 << 4 | 17 << 8 | 2 << 16 | 2 << 20 | 2 << 24 | 8 << 28, 10, 5000, self::fnv('none')], \array_slice($pipe, 8, 4));
        self::assertSame([3, 1, 0, 1, 1, 0, 0, 1, 1], \array_slice($pipe, 12, 9));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('W66_BENCH_ROUNDS') ?: 1000));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            // A default-enabled helper below the gate fails; one below it must retain PHP.
            if ($class::usesW66RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW66.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
