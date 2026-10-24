<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BookService;
use App\Services\IsbnDbService;
use App\Support\BookMatchScorer;
use App\Support\Data\BookParseResult;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Reproduce the paired single-call experiment with benchmark('/tmp/wave10-books.json').
 * Baseline: 1daf6378120fd3ae14af916bff0fc0d2088d41c6. Production adoption
 * requires >=1.5x mean AND p99 on common AND uncommon inputs for each method.
 * Unsupported Unicode, locale, encoding, framing and Carbon date forms use PHP.
 * PHP 8.5.12, rustc 1.99.1 release, Darwin arm64 Apple M5, 10 CPUs/32 GiB;
 * OPcache CLI off; host process without imposed limits. 1,024 warmups,
 * 20,480 paired single calls per method/workload, five randomized blocks.
 * Completed run 1 (PHP latency / native latency), common then uncommon mean/p99:
 * hasStructuralConflict          0.65/0.65   0.45/0.53
 * structuralMarkers              0.49/0.55   0.42/0.24
 * meaningfulTitleTokenCount      2.20/3.32   3.94/2.91
 * workKey                        0.93/0.92   2.83/1.96
 * pickBestCandidateForParse       1.43/1.38   1.24/1.30
 * candidatePassesIdentityRules    1.14/1.46   1.56/1.70
 * candidateCompleteness          0.25/0.27   0.25/0.26
 * candidateYearTie               0.53/0.50   0.52/0.67
 * normalizeBookResult            0.77/0.61   0.64/0.64
 * Only meaningfulTitleTokenCount qualifies; the rest retain PHP by default.
 * Raw samples/order/fixtures/source hashes: /tmp/wave10-books-run1.json.
 * The initial repetitive-title screening run was terminated incomplete and
 * the first revised recorder failed on Spatie object serialization; neither
 * failure supplied adoption evidence. Their reports remain under /tmp.
 */
final class RustBookCandidateRankingParityTest extends TestCase
{
    public static function service(): BookService
    {
        return (new ReflectionClass(BookService::class))->newInstanceWithoutConstructor();
    }

    public static function isbnDb(): IsbnDbService
    {
        return (new ReflectionClass(IsbnDbService::class))->newInstanceWithoutConstructor();
    }

    private static function ffi(): FFI
    {
        $ffi = BookMatchScorer::rankingFfi();
        self::assertNotNull($ffi, 'Build and copy the release pipeline library before running parity tests.');

        return $ffi;
    }

    /** @return list<string> */
    private static function titles(): array
    {
        return ['', '0', ' ', 'The Art of War', 'The Art of War: A New Translation',
            'Domain-Driven Design', 'Dune Book 2', 'Dune vol. #002', 'Dune volume3',
            '3rd Edition', '3 ED.x', '1234th edition', '2 ed..', '2 edition_',
            'Book 1234', 'Book #000', 'BOOK 2 series 3', '2nd Edition 3rd Edition',
            "Book\v2", "2\ved.", "Book\f#\t3\n2nd edition", 'xBook 2', 'Book_2',
            'Book 2é', 'ÉDITION', 'Cien años de soledad', 'İ I Σ ς', '東京の本',
            "Bad\xffUTF8", "A\0Book 2", str_repeat('The Long Book Title ', 300).'Book 23'];
    }

    public function test_scorer_helpers_match_php_and_toggles(): void
    {
        self::ffi();
        $php = new BookMatchScorer;
        $php->useRustFfi = false;
        $rust = new BookMatchScorer;
        $rust->useRustFfi = true;
        $random = new Randomizer(new Mt19937(1001));
        $titles = self::titles();
        $chunks = ['Book', 'Volume', 'vol.', 'series', 'ed.', 'edition', '2nd', '3', '1234', '#007', 'x', 'the', 'A', '0', '_', '.', "\v", "\n", "\t", "\xff", 'é'];
        for ($i = 0; $i < 1500; $i++) {
            $parts = [];
            for ($n = $random->getInt(1, 15); $n > 0; $n--) {
                $parts[] = $chunks[$random->getInt(0, count($chunks) - 1)];
            }
            $titles[] = implode(' ', $parts);
        }
        foreach ($titles as $i => $title) {
            $candidateTitle = $titles[($i + 7) % count($titles)];
            $parsed = new BookParseResult(rawName: $title, title: $candidateTitle);
            $candidate = ['title' => $title, 'author' => ['Martin, Robert C.', 'Tzu Sun', '2 10 01 1e2', 'García Márquez', ''][($i % 5)]];
            self::assertSame($php->structuralMarkers($title), $rust->structuralMarkers($title), 'markers '.bin2hex($title));
            self::assertSame($php->meaningfulTitleTokenCount($title), $rust->meaningfulTitleTokenCount($title), 'tokens '.bin2hex($title));
            self::assertSame($php->hasStructuralConflict($parsed, $candidateTitle), $rust->hasStructuralConflict($parsed, $candidateTitle));
            self::assertSame($php->workKey($candidate), $rust->workKey($candidate));
        }
        $parsed = new BookParseResult(rawName: 'Book', title: '2');
        self::assertTrue($rust->hasStructuralConflict($parsed, 'Book 3'), 'Markers may span rawName and title.');
    }

    /** @return array<string, mixed> */
    public static function candidate(string $title = 'Domain-Driven Design', string $author = 'Eric Evans', string $id = 'provider:1'): array
    {
        return ['title' => $title, 'author' => $author, 'asin' => $id, 'isbn' => null, 'ean' => null,
            'publisher' => 'Addison-Wesley', 'publishdate' => '2003-08-30', 'pages' => '560',
            'overview' => 'An approach to software design.', 'genre' => 'Computers', 'coverurl' => 'https://example.test/cover.jpg', '_source' => 'fixture'];
    }

    /** @return list<array{BookParseResult, array<int, array<string, mixed>>}> */
    public static function rankingCases(): array
    {
        $parsed = new BookParseResult(rawName: 'Eric Evans - Domain-Driven Design', title: 'Domain-Driven Design', author: 'Eric Evans', year: 2003);
        $good = self::candidate();
        $edition = array_replace($good, ['asin' => 'provider:2', 'publishdate' => '1999-01-01', 'overview' => '']);
        $isbn = new BookParseResult(rawName: 'Clean Code', title: 'Clean Code', isbn: '9780132350884');

        return [
            [$parsed, []], [$parsed, [$good]], [$parsed, [self::candidate('Other Unrelated Work', 'Other Person'), $good]],
            [$parsed, [$edition, $good, self::candidate('Refactoring', 'Martin Fowler', 'provider:3')]],
            [$parsed, [$good, array_replace($good, ['asin' => 'duplicate', 'author' => 'Evans, Eric', 'pages' => 0, 'publisher' => false])]],
            [$parsed, [self::candidate('Domain-Driven Design Handbook'), $good]],
            [$isbn, [self::candidate('Unrelated', 'Unknown') + ['extra' => false], array_replace($good, ['isbn' => '0132350882'])]],
            [$isbn, [array_replace($good, ['isbn' => '9780132350884', 'ean' => '0321125215'])]],
            [new BookParseResult(rawName: 'The Art of War', title: 'The Art of War'), [self::candidate('The Art of War', 'Sun Tzu')]],
            [new BookParseResult(rawName: 'Introduction to Modern Software Design', title: 'Introduction to Modern Software Design'), [self::candidate('Introduction to Modern Software Design')]],
            [new BookParseResult(rawName: 'Dune Book 2', title: 'Dune Book 2', author: 'Frank Herbert'), [self::candidate('Dune Book 3', 'Frank Herbert'), self::candidate('Dune Book 2', 'Frank Herbert', 'dune2')]],
            [$parsed, [9 => $good, 25 => $edition]],
            [$parsed, [['asin' => 'empty:1'], ['asin' => 'empty:2'], []]],
            [new BookParseResult(rawName: 'Cien años de soledad', title: 'Cien años de soledad', author: 'García Márquez'), [self::candidate('Cien años de soledad', 'García Márquez')]],
            [new BookParseResult(rawName: "Book\v2", title: 'Dune', author: 'Frank Herbert'), [self::candidate('Dune Book 3', 'Frank Herbert')]],
            [new BookParseResult(rawName: 'Book', title: '2', author: 'Author'), [self::candidate('Book 3', 'Author')]],
        ];
    }

    public function test_batch_ranking_matches_the_existing_caller_and_retains_candidates(): void
    {
        self::ffi();
        $service = self::service();
        $scorer = new BookMatchScorer;
        foreach (self::rankingCases() as $i => [$parsed, $candidates]) {
            $expected = $service->pickBestCandidateForParsePhp($candidates, $parsed, $scorer);
            self::assertSame($expected, $service->pickBestCandidateForParseNative($candidates, $parsed, $scorer), "case $i");
            foreach ([false, true, null] as $toggle) {
                $service->useRustFfi = $toggle;
                self::assertSame($expected, (new ReflectionMethod(BookService::class, 'pickBestCandidateForParse'))->invoke($service, $candidates, $parsed, $scorer));
            }
        }
        $random = new Randomizer(new Mt19937(1002));
        for ($i = 0; $i < 800; $i++) {
            $parsed = new BookParseResult(rawName: 'Design Book 2', title: ['Design Book 2', 'The Art of Computer Programming', 'Domain-Driven Design'][$i % 3], author: $i % 4 ? 'Eric Evans' : null, year: 2003, isbn: $i % 7 ? null : '9780132350884');
            $candidates = [];
            for ($n = $random->getInt(0, 18); $n > 0; $n--) {
                $candidates[] = self::candidate(['Design Book 2', 'Design Book 3', 'The Art of Computer Programming', 'Domain-Driven Design', 'Refactoring'][$random->getInt(0, 4)], ['Evans Eric', 'Eric Evans', 'Martin Fowler', 'E Evans', ''][$random->getInt(0, 4)], (string) $n)
                    + ['isbn13' => 'provider-only'];
                $last = count($candidates) - 1;
                $candidates[$last]['publishdate'] = ['2003-01-01', '2000', '', null][$random->getInt(0, 3)];
                $candidates[$last]['isbn'] = [null, '9780132350884', '0132350882', 'bad', '9780321125217'][$random->getInt(0, 4)];
            }
            self::assertSame($service->pickBestCandidateForParsePhp($candidates, $parsed, $scorer), $service->pickBestCandidateForParseNative($candidates, $parsed, $scorer), "random batch $i");
        }
        [$parsed] = self::rankingCases()[1];
        self::assertSame(self::candidate(), $service->pickBestCandidateForParseNative([self::candidate()], $parsed, $scorer));
    }

    public function test_stable_work_order_and_uninspected_provider_fields(): void
    {
        self::ffi();
        $service = self::service();
        $scorer = new BookMatchScorer;
        $parsed = new BookParseResult(rawName: 'Clean Code', title: 'Clean Code', isbn: '9780132350884', year: 2003);
        $older = array_replace(self::candidate('Alpha', 'Author A'), ['isbn' => '9780132350884', 'publishdate' => '2000-01-01']);
        $other = array_replace(self::candidate('Beta', 'Author B'), ['isbn' => '9780132350884']);
        $newer = array_replace($older, ['asin' => 'newer', 'publishdate' => '2003-08-30']);
        self::assertSame($newer, $service->pickBestCandidateForParseNative([$older, $other, $newer], $parsed, $scorer));
        $candidate = self::candidate();
        unset($candidate['asin']);
        $candidate['opaque_provider_field'] = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('Ranking must not serialize this field.');
            }
        };
        $parsed = new BookParseResult(rawName: 'Domain-Driven Design', title: 'Domain-Driven Design', author: 'Eric Evans');
        self::assertSame($candidate, $service->pickBestCandidateForParsePhp([$candidate], $parsed, $scorer));
        self::assertSame($candidate, $service->pickBestCandidateForParseNative([$candidate], $parsed, $scorer));
        self::assertSame($candidate, $service->pickBestCandidateForParseNative([$candidate], $parsed, new class extends BookMatchScorer {}));
    }

    public function test_identity_thresholds_floating_edges_completeness_and_years(): void
    {
        self::ffi();
        $service = self::service();
        $scorer = new BookMatchScorer;
        $parsed = new BookParseResult(rawName: 'The Art of Software Design', title: 'The Art of Software Design', author: 'Eric Evans', year: 2003);
        $candidate = self::candidate($parsed->title);
        foreach ([null, '', 'Eric Evans', 'E Evans', 'Other Author'] as $author) {
            $parsed->author = $author;
            foreach ([0.0, 0.7799999999999999, 0.78, 0.8199999999999999, 0.82, 0.92, 1.0, INF, -INF, NAN] as $score) {
                foreach ([null, $score - 0.08, $score - 0.12, $score, INF, NAN] as $runner) {
                    self::assertSame($service->candidatePassesIdentityRulesPhp($candidate, $score, $runner, $parsed, $scorer), $service->candidatePassesIdentityRulesNative($candidate, $score, $runner, $parsed, $scorer));
                }
            }
        }
        foreach ([null, '', '0', 0, 0.0, false, true, 'false', [], ['x'], INF, NAN] as $value) {
            $fields = array_fill_keys(['isbn', 'ean', 'publisher', 'publishdate', 'pages', 'overview', 'genre', 'coverurl'], $value);
            self::assertSame($service->candidateCompletenessPhp($fields), $service->candidateCompletenessNative($fields));
        }
        foreach ([null, PHP_INT_MIN, PHP_INT_MAX, 0, 1900, 2003, 2099] as $year) {
            $parsed->year = $year;
            foreach (['', '0', null, false, '2003-08-30', '_2003', 'x2003', '2003x', '20030', '1900 / 2003', 'é2003é', "\xff2003", '2100', '9999'] as $date) {
                $candidate['publishdate'] = $date;
                self::assertSame($service->candidateYearTiePhp($candidate, $parsed), $service->candidateYearTieNative($candidate, $parsed));
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public static function isbnBooks(): array
    {
        $book = ['title' => 'Domain-Driven Design', 'authors' => ['Eric Evans'], 'subjects' => ['Software', 'Design'], 'isbn13' => '9780321125217', 'isbn10' => '0321125215', 'publisher' => 'Addison-Wesley', 'date_published' => '2003-08-30', 'pages' => 560, 'image' => 'https://example.test/image.jpg', 'synopsis' => ' <p>Practical design</p> '];
        $cases = [$book, [], array_replace($book, ['authors' => [null, false, 0, '0', '', ' ', 'García Márquez', true, 3.25], 'subjects' => [0, '0', 'Books', 'Books'], 'isbn13' => 'bad', 'isbn' => '0132350882', 'isbn10' => '9780132350884', 'image' => null, 'image_original' => 'cover'])];
        foreach (['2020', '2000-02-29', '1900-02-29', '2020-02-31', '2020-1-2', '0000-01-01', '2020-04', 'September 1, 2020', 'next Thursday', 'now', '2020-01-01T23:59:00-05:00', '', ' ', 'nonsense', null, 2020, false, "2020-01-01\0", "bad\xff"] as $date) {
            $cases[] = array_replace($book, ['date_published' => $date]);
        }
        $cases[] = array_replace($book, ['authors' => ["Author\0Name", "Bad\xff"], 'subjects' => 'not an array']);
        $cases[] = array_replace($book, ['pages' => '560.75', 'isbn13' => null, 'isbn10' => null, 'title' => "Bad\xff"]);

        return $cases;
    }

    public function test_isbndb_normalization_preserves_carbon_and_php_value_semantics(): void
    {
        self::ffi();
        $service = self::isbnDb();
        $previous = Carbon::getTestNow();
        Carbon::setTestNow('2026-10-24 12:34:56');
        try {
            foreach (self::isbnBooks() as $i => $book) {
                $expected = $service->normalizeBookResultPhp($book);
                self::assertSame($expected, $service->normalizeBookResultNative($book), "ISBNdb case $i");
                foreach ([false, true, null] as $toggle) {
                    $service->useRustFfi = $toggle;
                    self::assertSame($expected, (new ReflectionMethod(IsbnDbService::class, 'normalizeBookResult'))->invoke($service, $book));
                }
            }
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    public function test_direct_abi_executes_and_declines_unsupported_frames(): void
    {
        $ffi = self::ffi();
        $invoke = static fn (string $name, mixed ...$args): mixed => $ffi->$name(...$args);
        $cap = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $cap);
        self::assertNotNull($buffer);
        foreach (['Book 2 3rd edition', "Book\v#007 02\ved.x", 'Book 1234', '_Book 2', 'Book 2_'] as $text) {
            $packed = $invoke('nntmux_book_rank_helper', 0, $text, strlen($text), 0);
            $markers = (new BookMatchScorer)->structuralMarkersPhp($text);
            self::assertSame(($markers['volume'] ?? -1) + 1 + ((($markers['edition'] ?? -1) + 1) << 10), $packed);
        }
        self::assertSame(2, $invoke('nntmux_book_rank_helper', 1, 'The Art of War', 14, 0));
        $input = serialize(['isbn' => null, 'ean' => '', 'pages' => 0, 'publisher' => false]);
        self::assertSame(2, $invoke('nntmux_book_rank_helper', 2, $input, strlen($input), 0));
        $input = implode("\0", ['The Art of Software Design', 'Eric Evans', 'The Art of Software Design', 'Evans Eric'])."\0";
        self::assertSame(1, $invoke('nntmux_book_identity_nul', $input, strlen($input), 0, 0.97, 0.0));
        $input = "The Art of War\0Sun Tzu\0";
        $needed = $invoke('nntmux_book_work_key_nul', $input, strlen($input), $buffer, 1);
        self::assertLessThan(-1, $needed);
        $written = $invoke('nntmux_book_work_key_nul', $input, strlen($input), $buffer, $cap);
        self::assertSame(-$needed, $written);
        self::assertSame('the art of war|sun tzu', FFI::string($buffer, $written));
        // Header: raw/title/author/isbn/has-isbn/year/count. Each record:
        // title/author/isbn/ean/fallback-key/completeness-bits/publishdate.
        $fields = ['Domain-Driven Design', 'Domain-Driven Design', 'Eric Evans', '', '0', '2003', '1',
            'Domain-Driven Design', 'Eric Evans', '', '', '1provider:1', '00111111', '2003-08-30'];
        $input = implode("\0", $fields)."\0";
        self::assertSame(0, $invoke('nntmux_book_rank_candidates_nul', $input, strlen($input)));
        foreach (['', "bad\0", substr($input, 0, -1), $input."extra\0", "é\0", "\xff\0"] as $bad) {
            self::assertSame(-1, $invoke('nntmux_book_rank_candidates_nul', $bad, strlen($bad)));
        }
        $input = implode("\0", ['1', '2020-02-29', '2', '2', 'Author', '0', 'Books', '0'])."\0";
        $written = $invoke('nntmux_isbndb_fields_nul', $input, strlen($input), $buffer, $cap);
        self::assertGreaterThan(0, $written);
        self::assertSame(['1', '2020-02-29', 'Author', 'Books', ''], explode("\0", FFI::string($buffer, $written)));
    }

    public function test_missing_library_locale_and_encoding_fallbacks(): void
    {
        self::ffi();
        $property = new ReflectionProperty(BookMatchScorer::class, 'rankingFfi');
        $previous = $property->getValue();
        $locale = setlocale(LC_CTYPE, '0');
        $encoding = mb_internal_encoding();
        try {
            $property->setValue(null, false);
            $this->assertFallbackParity();
            $property->setValue(null, $previous);
            mb_internal_encoding('ISO-8859-1');
            self::assertFalse(BookMatchScorer::rankingEnvironmentSupported());
            $this->assertFallbackParity();
            mb_internal_encoding($encoding);
            foreach (['en_US.ISO8859-1', 'en_US.ISO8859-15', 'tr_TR.ISO8859-9', 'en_US.UTF-8'] as $candidateLocale) {
                if (setlocale(LC_CTYPE, $candidateLocale) !== false) {
                    self::assertFalse(BookMatchScorer::rankingEnvironmentSupported());
                    $this->assertFallbackParity();
                    break;
                }
            }
        } finally {
            $property->setValue(null, $previous);
            mb_internal_encoding($encoding);
            // Reset PCRE character tables to ASCII before restoring the prior locale.
            setlocale(LC_CTYPE, 'C');
            if ($locale !== false) {
                setlocale(LC_CTYPE, $locale);
            }
        }
    }

    private function assertFallbackParity(): void
    {
        $service = self::service();
        $scorer = new BookMatchScorer;
        foreach (self::rankingCases() as [$parsed, $candidates]) {
            self::assertSame($service->pickBestCandidateForParsePhp($candidates, $parsed, $scorer), $service->pickBestCandidateForParseNative($candidates, $parsed, $scorer));
        }
        foreach (self::titles() as $title) {
            self::assertSame($scorer->structuralMarkersPhp($title), $scorer->structuralMarkersNative($title));
            self::assertSame($scorer->meaningfulTitleTokenCountPhp($title), $scorer->meaningfulTitleTokenCountNative($title));
            $candidate = ['title' => $title, 'author' => 'Eric Evans'];
            self::assertSame($scorer->workKeyPhp($candidate), $scorer->workKeyNative($candidate));
        }
        $isbn = self::isbnDb();
        self::assertSame($isbn->normalizeBookResultPhp(self::isbnBooks()[0]), $isbn->normalizeBookResultNative(self::isbnBooks()[0]));
    }

    /**
     * php -r 'require "tests/bootstrap.php"; Tests\Unit\RustBookCandidateRankingParityTest::benchmark("/tmp/wave10-books.json");'
     * Single calls include packing, dispatch, allocation, FFI and PHP fallback.
     * Keep this executor serial; do not run builds/analysis concurrently.
     */
    public static function benchmark(string $outputPath): void
    {
        $coldStart = hrtime(true);
        self::ffi();
        $resolutionNs = hrtime(true) - $coldStart;
        $service = self::service();
        $service->useRustFfi = false;
        $scorer = new BookMatchScorer;
        $scorer->useRustFfi = false;
        $isbn = self::isbnDb();
        $dispatchPhp = new BookMatchScorer;
        $dispatchPhp->useRustFfi = false;
        $dispatchRust = new BookMatchScorer;
        $random = new Randomizer(new Mt19937(10010));
        $workloads = [
            'common' => ['Domain-Driven Design', 'The Art of War', 'Dune Book 2', 'Effective Java 3rd Edition', 'Clean Code: A Handbook', 'Introduction to Software Architecture'],
            'uncommon' => [str_repeat('An Extended Introduction to Software Design ', 12).'Vol. #023 12th Edition',
                str_repeat('A Comprehensive Practical Programming Guide ', 8).'Second Part',
                str_repeat('Deep Software Architecture Systems Patterns ', 16).'2nd Edition'],
            'fallback' => ['Cien años de soledad', 'İ I Σ ς', '東京の本', "Binary\0Book", "Bad\xffText"],
        ];
        $report = [
            'protocol' => 'seed 10010; 1024 warmups; 5 blocks of 4096 randomized paired single calls; nearest-rank p99; no memoization; output equality before timing',
            'baseline_commit' => '1daf6378120fd3ae14af916bff0fc0d2088d41c6',
            'php' => PHP_VERSION, 'os' => php_uname(), 'ffi' => ini_get('ffi.enable'),
            'locale' => setlocale(LC_CTYPE, '0'), 'mb_encoding' => mb_internal_encoding(),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'rust_profile' => 'release; opt-level=3; LTO; codegen-units=1',
            'rustc' => trim((string) shell_exec('rustc --version')),
            'hardware' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string hw.logicalcpu hw.memsize')),
            'resource_limits' => 'host process; no imposed CPU or memory limit',
            'library_resolution_ns' => $resolutionNs,
            'hashes' => [], 'results' => [],
        ];
        foreach (['app/Services/BookService.php', 'app/Support/BookMatchScorer.php', 'app/Services/IsbnDbService.php',
            'rust/pipeline-ffi/src/metadata.rs', 'tests/Unit/RustBookCandidateRankingParityTest.php',
            'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'vendor/composer/installed.json',
            'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $file) {
            $report['hashes'][$file] = hash_file('sha256', $file);
        }
        foreach ($workloads as $workload => $titles) {
            $parses = [];
            $rankParses = [];
            $candidates = [];
            $batches = [];
            $books = [];
            foreach ($titles as $i => $title) {
                $author = $workload === 'uncommon' ? 'Eric Evans, Robert C. Martin, Martin Fowler, Joshua Bloch' : 'Eric Evans';
                $parses[] = new BookParseResult(rawName: $title, title: $title, author: $i % 3 ? $author : null, year: 2003);
                $candidates[] = self::candidate($title, $author, 'fixture:'.$i);
                $batch = [];
                $count = $workload === 'uncommon' ? 80 : [1, 5, 10, 20, 10, 5][$i % 6];
                $rankTitle = $workload === 'uncommon' ? [
                    'Designing Data-Intensive Applications: The Big Ideas Behind Reliable, Scalable, and Maintainable Systems',
                    'Domain-Driven Design: Tackling Complexity in the Heart of Software',
                    'Software Architecture: The Hard Parts - Modern Trade-Off Analyses for Distributed Architectures',
                ][$i % 3] : $title;
                $rankParse = new BookParseResult(rawName: $rankTitle, title: $rankTitle, author: $i % 3 ? $author : null, year: 2003);
                $rankParses[] = $rankParse;
                for ($j = 0; $j < $count; $j++) {
                    $batch[] = self::candidate($j % 4 === 0 ? $rankTitle : ['Patterns of Enterprise Application Architecture', 'Refactoring: Improving the Design of Existing Code', 'Clean Architecture: A Guide to Software Structure and Design', 'The Pragmatic Programmer: Your Journey to Mastery'][($i + $j) % 4], $j % 3 ? $author : 'Martin Fowler', 'fixture:'.$j);
                    $batch[$j]['publishdate'] = $j % 2 ? '2000-01-01' : '2003-08-30';
                }
                $batch[] = self::candidate($rankTitle, $author, 'best:'.$i);
                $batches[] = $batch;
                $books[] = ['title' => $title, 'authors' => $workload === 'uncommon' ? array_fill(0, 12, $author) : [$author],
                    'subjects' => $workload === 'uncommon' ? array_fill(0, 25, 'Software Engineering') : ['Software', 'Design'],
                    'isbn13' => '9780321125217', 'isbn10' => '0321125215', 'date_published' => ['2003-08-30', '2003', 'August 30, 2003'][$i % 3],
                    'publisher' => 'Publisher', 'pages' => '560', 'synopsis' => '<p>A practical guide.</p>'];
            }
            $pairs = [
                'hasStructuralConflict' => [fn (int $i): bool => $scorer->hasStructuralConflictPhp($parses[$i], $titles[($i + 1) % count($titles)]), fn (int $i): bool => $scorer->hasStructuralConflictNative($parses[$i], $titles[($i + 1) % count($titles)])],
                'structuralMarkers' => [fn (int $i): array => $scorer->structuralMarkersPhp($titles[$i]), fn (int $i): array => $scorer->structuralMarkersNative($titles[$i])],
                'meaningfulTitleTokenCount' => [fn (int $i): int => $scorer->meaningfulTitleTokenCountPhp($titles[$i]), fn (int $i): int => $scorer->meaningfulTitleTokenCountNative($titles[$i])],
                'meaningfulTitleTokenCountDispatch' => [fn (int $i): int => $dispatchPhp->meaningfulTitleTokenCount($titles[$i]), fn (int $i): int => $dispatchRust->meaningfulTitleTokenCount($titles[$i])],
                'workKey' => [fn (int $i): string => $scorer->workKeyPhp($candidates[$i]), fn (int $i): string => $scorer->workKeyNative($candidates[$i])],
                'pickBestCandidateForParse' => [fn (int $i): ?array => $service->pickBestCandidateForParsePhp($batches[$i], $rankParses[$i], $scorer), fn (int $i): ?array => $service->pickBestCandidateForParseNative($batches[$i], $rankParses[$i], $scorer)],
                'candidatePassesIdentityRules' => [fn (int $i): bool => $service->candidatePassesIdentityRulesPhp($candidates[$i], 0.97, $i % 2 ? 0.81 : null, $parses[$i], $scorer), fn (int $i): bool => $service->candidatePassesIdentityRulesNative($candidates[$i], 0.97, $i % 2 ? 0.81 : null, $parses[$i], $scorer)],
                'candidateCompleteness' => [fn (int $i): int => $service->candidateCompletenessPhp($candidates[$i]), fn (int $i): int => $service->candidateCompletenessNative($candidates[$i])],
                'candidateYearTie' => [fn (int $i): int => $service->candidateYearTiePhp($candidates[$i], $parses[$i]), fn (int $i): int => $service->candidateYearTieNative($candidates[$i], $parses[$i])],
                'normalizeBookResult' => [fn (int $i): array => $isbn->normalizeBookResultPhp($books[$i]), fn (int $i): array => $isbn->normalizeBookResultNative($books[$i])],
            ];
            foreach ($pairs as $method => $calls) {
                foreach ($titles as $i => $title) {
                    self::assertSame($calls[0]($i), $calls[1]($i), $method.' '.$workload.' '.$i);
                }
                $coldStart = hrtime(true);
                $calls[1](0);
                $firstCall = hrtime(true) - $coldStart;
                for ($warm = 0; $warm < 1024; $warm++) {
                    $calls[0]($warm % count($titles));
                    $calls[1]($warm % count($titles));
                }
                $samples = [[], []];
                $orders = [];
                $indices = [];
                for ($sample = 0; $sample < 20480; $sample++) {
                    $i = $random->getInt(0, count($titles) - 1);
                    $first = $random->getInt(0, 1);
                    $indices[] = $i;
                    $orders[] = $first;
                    foreach ([$first, 1 - $first] as $side) {
                        $start = hrtime(true);
                        $result = $calls[$side]($i);
                        $samples[$side][] = hrtime(true) - $start;
                        unset($result);
                    }
                }
                $stats = static function (array $ns): array {
                    sort($ns);

                    return ['mean_ns' => array_sum($ns) / count($ns), 'p99_ns' => $ns[(int) ceil(count($ns) * 0.99) - 1]];
                };
                $php = $stats($samples[0]);
                $rust = $stats($samples[1]);
                $blocks = [];
                for ($block = 0; $block < 5; $block++) {
                    $p = $stats(array_slice($samples[0], $block * 4096, 4096));
                    $r = $stats(array_slice($samples[1], $block * 4096, 4096));
                    $blocks[] = ['mean_speedup' => $p['mean_ns'] / $r['mean_ns'], 'p99_speedup' => $p['p99_ns'] / $r['p99_ns']];
                }
                $key = $method.'/'.$workload;
                $report['results'][$key] = ['php' => $php, 'rust' => $rust,
                    'mean_speedup' => $php['mean_ns'] / $rust['mean_ns'], 'p99_speedup' => $php['p99_ns'] / $rust['p99_ns'],
                    'blocks' => $blocks, 'samples' => $samples, 'orders' => $orders, 'input_indices' => $indices,
                    'first_warm_call_ns' => $firstCall, 'titles' => $titles,
                    'parses' => array_map(static fn (BookParseResult $p): array => get_object_vars($p), $parses),
                    'candidates' => $candidates, 'batches' => $batches, 'books' => $books,
                    'raw_inputs_base64' => base64_encode(serialize([$titles, array_map(get_object_vars(...), $parses), array_map(get_object_vars(...), $rankParses), $candidates, $batches, $books]))];
                file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                echo $key.' '.json_encode([$php, $rust, $php['mean_ns'] / $rust['mean_ns'], $php['p99_ns'] / $rust['p99_ns']]).PHP_EOL;
            }
        }
    }
}
