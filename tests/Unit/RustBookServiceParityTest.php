<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BookService;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 7.2 adoption evidence (baseline 2069af11e, PHP 8.5.12, rustc 1.99.1 release,
 * Darwin arm64 Apple M5 / 10 CPUs / 32 GiB, OPcache CLI off, FFI enabled).
 * Serial run: 1,024 warmups, five blocks of 4,096 randomized paired calls per workload.
 * Ratios are PHP latency / Rust latency, including PHP dispatch, FFI and result allocation:
 *
 * Method                             common mean/p99    uncommon mean/p99
 * parseReleaseName                   0.88x / 0.92x      0.66x / 0.74x
 * extractIsbn                        0.70x / 1.28x      0.67x / 0.70x
 * looksLikeObfuscatedBookSubject      0.50x / 0.60x      0.50x / 0.50x
 * determineReadableBookSearchName     0.77x / 0.87x      0.75x / 0.78x
 *
 * All candidates fail the >=1.5x mean AND p99 gates; production retains PHP.
 * Five-block mean ranges: parser common 0.86-0.90x, uncommon 0.65-0.68x;
 * all other common/uncommon block means <=0.80x. Fallback means span 0.42-0.99x.
 * First native parse including library/regex initialization: 3.41 ms (separate from warm calls).
 * Raw samples/order/indices/hashes: /tmp/wave7-book-benchmark-3.json; reproduce via benchmark().
 * Run 1 independently reached the same rejection; run 2 is excluded for PHPStan interference.
 */
final class RustBookServiceParityTest extends TestCase
{
    public static function service(): BookService
    {
        $service = (new ReflectionClass(BookService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BookService::class, 'obfuscatedSubjectExtractor'))->setValue($service, new ObfuscatedSubjectExtractor);

        return $service;
    }

    /** @return list<string> */
    public static function subjects(): array
    {
        return [
            '', ' ', '12345', 'a b c d', 'Atomic Habits EPUB',
            'Eric.Evans - Domain.Driven.Design 978-0321125217 RETAIL EPUB',
            'Clean Code by Robert C. Martin 2008 EPUB',
            'The Pragmatic Programmer 1999 EPUB', 'Frank Herbert - Dune (Book 2) EPUB',
            'N:/NZB [2/8] - "Harry Styles Songbook - 1st Edition 2026.part1.rar"',
            'N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"',
            "N_NZB_[1_6]_-_Woman's_Day_New_Zealand_-_Issue_45_April_27_2026.par2",
            'MCN.April.22.2026.HYBRID.MAGAZINE.eBook-21A1', 'MCN - April 22, 2026',
            'MCN.jAnUaRy.00.1999 MAGAZINE', 'MCN February 3, 2026',
            'Foxit PDF Editor Pro 14.0.4.33508 Multilingual x64',
            'Breaking.Bad.S05E16.720p.BluRay.x264', 'Dark.Staffel.02.German.WEB-DL',
            'El verano en que me enamore Temporada 01 PAR2',
            'A Book 9780132350885 9780132350884 EPUB',
            'A Book 0132350883 0132350882 EPUB', 'A Book 0-8044-2957-x PDF',
            'A Book 9780132350885 0132350882 EPUB',
            'ISBN_9780132350884', '97801323508840', 'x9780132350884',
            'N:/NZBfoo', 'N:/NZB_', 'N.:_-NZB [', 're posted by author - title',
            'reposted byline', 'Posted by A - Book Title', "\tN_NZB_[1_2]_-_some_book.rar",
            'lowercase book by some author epub', "'some book title.rar'", 'Book [one] text [two]',
            'As Req Robert Martin - Clean Code EPUB INTERNAL', 'Example - Issue 45 EPUB',
            'Reader May', 'Reader Maybe', 'Novel The Novel EPUB', 'Novel - Novel 2026 EPUB',
            'Café - Über Bücher EPUB', '東京の本', "Bad\xffUTF8", "A\0Book EPUB", "Book\nTitle EPUB",
            str_repeat('Long.Book.', 300).'EPUB',
        ];
    }

    public function test_release_parser_and_readable_names_match_php(): void
    {
        $this->requireFfi();
        $service = self::service();
        $ffi = $this->requireFfi();
        $out = $ffi->new('char[16384]');
        $readable = new ReflectionMethod(BookService::class, 'determineReadableBookSearchNamePhp');
        foreach (self::subjects() as $subject) {
            foreach (['ebook', 'audiobook', 'other'] as $type) {
                if (strlen($subject) <= 1024 && preg_match('/^[ -~]*$/D', $subject) === 1) {
                    // @phpstan-ignore method.notFound
                    self::assertGreaterThan(0, $ffi->nntmux_book_parse_release_name($subject, strlen($subject), $type, strlen($type), $out, 16384));
                }
                $expected = $service->parseReleaseNamePhp($subject, $type);
                self::assertEquals($expected, $service->parseReleaseNameNative($subject, $type), bin2hex($subject));
                self::assertEquals($expected, $service->parseReleaseName($subject, $type));
                self::assertSame($readable->invoke($service, $subject, $expected), $service->determineReadableBookSearchNameNative($subject, $expected));
            }
        }
    }

    public function test_isbn_first_match_precedence_and_obfuscated_prefix_parity(): void
    {
        $this->requireFfi();
        $service = self::service();
        $obfuscated = new ReflectionMethod(BookService::class, 'looksLikeObfuscatedBookSubjectPhp');
        mt_srand(72026);
        $subjects = self::subjects();
        for ($i = 0; $i < 1500; $i++) {
            $subjects[] = ['N:/NZB', 'N_NZB [', 'posted by', 'reposted by', 'ordinary'][mt_rand(0, 4)]
                .[' ', '_', '\\', "\t", ':', '-', '['][mt_rand(0, 6)]
                .['9780132350884', '0-8044-2957-X', '0132350883', '9780132350885 0132350882', 'by'][mt_rand(0, 4)];
        }
        foreach ($subjects as $subject) {
            self::assertSame($service->extractIsbnPhp($subject), $service->extractIsbnNative($subject), bin2hex($subject));
            self::assertSame($obfuscated->invoke($service, $subject), $service->looksLikeObfuscatedBookSubjectNative($subject), bin2hex($subject));
        }
    }

    public function test_randomized_release_parity(): void
    {
        $this->requireFfi();
        $service = self::service();
        mt_srand(20260702);
        for ($i = 0; $i < 1800; $i++) {
            $name = ['John Smith - ', '', 'N:/NZB [1/2] - ', 'posted by someone - '][mt_rand(0, 3)]
                .['The Great Book', 'The_Great_Book', 'the.great.book', 'Issue 23', 'MCN May 03', 'ArtofUsenet', 'Single', 'Reader January'][mt_rand(0, 7)]
                .['', ' by Jane Doe', ' - 2nd Edition', ' [Retail]'][mt_rand(0, 3)]
                .[' 1999', ' 2026', '', ' 978-0-13-235088-4', ' 0-8044-2957-X'][mt_rand(0, 4)]
                .[' EPUB', ' PDF', '.MOBI', ' S01E02 1080p WEB-DL', '.part01.rar', ' MP3', ' v1.2.3', ' Repackfix'][mt_rand(0, 7)];
            $type = $i % 2 ? 'ebook' : 'audiobook';
            self::assertEquals($service->parseReleaseNamePhp($name, $type), $service->parseReleaseNameNative($name, $type), $name);
        }
    }

    public function test_ascii_cleanup_tokens_and_punctuation_match_php(): void
    {
        $this->requireFfi();
        $service = self::service();
        $tokens = [
            'Novel', 'TIA', 'HQ', '(EPUB)', 'PDF-English', 'compleet', 'DAGSTiDNiNGEN',
            'DiRFiX', '+ extra', 'eBooks', 'eBookers', 'html', 'NEW_', 'Proper', 'Repackfix',
            'English', 'Swedish', 'R4 ', 'Repost', 'TruePDF', 'V413HAV', 'TIA!!!',
            'As Req ', 'conversion ', 'eq ', 'Das neue Abenteuer 12', 'Fixed version',
            'Full ', 'Per Req As Found', 'REQ ', 'revised ', 'version ', 'COMPLETE',
            'INTERNAL', 'RELOADED', 'docx', 'ENG', 'Req', 'v 3', '[one] text [two]', '()',
            'S001E002', 'Season 2', 'Episode 2', 'WEB-DL', 'H.264', 'AAC5.1', 'DDP5.1',
            'MCN', 'Issue 23', 'Part 1', 'January', 'May', 'Maybe', '2026', '19 12 2020',
            'ArtofUsenet', 'ebook collection', 'Mega Book pack', 'Book Dump', 'Sexual',
            '978-0-13-235088-4', 'ISBN_0132350882', '1984', 'PDF', 'a', 'by', 'Author',
            '"', "'", '-', '.', '_', '[]', '320kbps', 'patch', 'sample', 'nulled',
        ];
        mt_srand(20261024);
        for ($trial = 0; $trial < 4000; $trial++) {
            $parts = [];
            for ($j = 0, $count = mt_rand(1, 7); $j < $count; $j++) {
                $parts[] = $tokens[mt_rand(0, count($tokens) - 1)];
            }
            $subject = implode([' ', '.', '_', ' - '][mt_rand(0, 3)], $parts);
            $type = $trial % 2 ? 'ebook' : 'audiobook';
            $parsed = $service->parseReleaseNamePhp($subject, $type);
            self::assertEquals($parsed, $service->parseReleaseNameNative($subject, $type), $subject);
            self::assertSame($service->determineReadableBookSearchNamePhp($subject, $parsed), $service->determineReadableBookSearchNameNative($subject, $parsed), $subject);
        }
    }

    public function test_abi_declines_unicode_and_ambiguous_input_and_reports_capacity(): void
    {
        $ffi = $this->requireFfi();
        $out = $ffi->new('char[4096]');
        foreach (['Café', "\xff", "Book\0Name", str_repeat('A', 4097)] as $name) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_book_parse_release_name($name, strlen($name), 'ebook', 5, $out, 4096));
        }
        foreach (['Café', "\xff"] as $name) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_book_extract_isbn($name, strlen($name), $out, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_book_looks_like_obfuscated($name, strlen($name)));
        }
        $name = 'Ordinary Book Title';
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_book_readable_search_name('Café', 5, $name, strlen($name), 0, $out, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen($name), $ffi->nntmux_book_readable_search_name($name, strlen($name), $name, strlen($name), 0, $out, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_book_readable_search_name($name, strlen($name), $name, strlen($name), 1, $out, 4096));
        $name = 'N:/NZB [1/2] - "An Ordinary Book.rar"';
        // Obfuscated extraction deliberately precedes the junk check in PHP.
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_book_readable_search_name($name, strlen($name), 'ignored', 7, 1, $out, 4096);
        self::assertSame('An Ordinary Book', FFI::string($out, $written));
        $name = 'Eric Evans - Domain Driven Design EPUB';
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_book_parse_release_name($name, strlen($name), 'ebook', 5, $out, 1);
        self::assertLessThan(-1, $needed);
        // @phpstan-ignore method.notFound
        self::assertSame(-$needed, $ffi->nntmux_book_parse_release_name($name, strlen($name), 'ebook', 5, $out, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame(-13, $ffi->nntmux_book_extract_isbn('9780132350884', 13, $out, 1));
    }

    public function test_missing_ffi_preserves_php_results(): void
    {
        $property = new ReflectionProperty(BookService::class, 'bookFfi');
        $previous = $property->getValue();
        $property->setValue(null, false);
        try {
            $service = self::service();
            foreach (self::subjects() as $subject) {
                self::assertEquals($service->parseReleaseNamePhp($subject), $service->parseReleaseNameNative($subject));
                self::assertEquals($service->parseReleaseNamePhp($subject), $service->parseReleaseName($subject));
                self::assertSame($service->extractIsbnPhp($subject), $service->extractIsbnNative($subject));
                self::assertSame($service->looksLikeObfuscatedBookSubjectPhp($subject), $service->looksLikeObfuscatedBookSubjectNative($subject));
                $parsed = $service->parseReleaseNamePhp($subject);
                self::assertSame($service->determineReadableBookSearchNamePhp($subject, $parsed), $service->determineReadableBookSearchNameNative($subject, $parsed));
            }
        } finally {
            $property->setValue(null, $previous);
        }
    }

    /**
     * Reproduce with: php -r 'require "tests/bootstrap.php";
     * Tests\Unit\RustBookServiceParityTest::benchmark("/tmp/book-benchmark.json");'
     * Each workload uses 20,480 randomized paired individual calls after 1,024 warmups.
     * Raw nanosecond samples, order, input indices, source hashes and runtime settings are saved.
     */
    public static function benchmark(string $outputPath): void
    {
        $service = self::service();
        $workloads = [
            'common' => [
                'Eric.Evans - Domain.Driven.Design 978-0321125217 RETAIL EPUB',
                'Clean Code by Robert C. Martin 2008 EPUB',
                'Frank Herbert - Dune (Book 2) EPUB',
                'The Pragmatic Programmer 1999 EPUB',
                'Atomic Habits EPUB',
                'MCN.April.22.2026.HYBRID.MAGAZINE.eBook-21A1',
                'N:/NZB [2/8] - "Harry Styles Songbook - 1st Edition 2026.part1.rar"',
                'N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"',
            ],
            'uncommon' => [
                str_repeat('A Complex Book Title ', 30).'978-0-13-235088-4 EPUB',
                str_repeat('A Complex Book Title ', 30).'0132350883 0132350882 PDF',
                'N_NZB_[1_6]_-_'.str_repeat('A_Long_Book_Title_', 35).'.part001.rar',
                're posted by '.str_repeat('a', 100).' - "Book Name EPUB"',
                str_repeat('0-1-', 180).'0-8044-2957-X',
                str_repeat('Reader January ', 45).'Issue 123 2026 EPUB',
            ],
            'fallback' => ['Café - Über Bücher EPUB', '東京の本 EPUB', str_repeat('Long.Book.', 300).'EPUB'],
        ];
        $report = [
            'protocol' => '72026; 1024 warmups; 5 blocks x 4096 paired calls; random order per pair; mean and nearest-rank p99; timings include PHP wrapper, FFI and result construction; no result memoization',
            'php' => PHP_VERSION, 'os' => php_uname(), 'ffi' => ini_get('ffi.enable'),
            'baseline_commit' => '2069af11e', 'build_profile' => 'cargo release (opt-level=3, LTO, codegen-units=1)',
            'hardware' => 'Apple M5, 10 logical CPUs, 32 GiB RAM; host process, no imposed resource limits',
            'rustc' => '1.99.1 (58cf8f9c3 2026-10-13)',
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'hashes' => [], 'results' => [],
        ];
        foreach (['app/Services/BookService.php', 'rust/pipeline-ffi/src/categorize.rs', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $file) {
            $report['hashes'][$file] = hash_file('sha256', $file);
        }
        // First-use resolution and regex compilation are reported separately from steady-state
        // samples because BookService processes multiple releases within one long-lived worker.
        $start = hrtime(true);
        $service->parseReleaseNameNative($workloads['common'][0]);
        $report['cold_native_parse_ns'] = hrtime(true) - $start;
        mt_srand(72026);
        foreach ($workloads as $workload => $names) {
            $parsed = array_map($service->parseReleaseNamePhp(...), $names);
            $pairs = [
                'parseReleaseName' => [fn (int $i) => $service->parseReleaseNamePhp($names[$i]), fn (int $i) => $service->parseReleaseNameNative($names[$i])],
                'extractIsbn' => [fn (int $i) => $service->extractIsbnPhp($names[$i]), fn (int $i) => $service->extractIsbnNative($names[$i])],
                'looksLikeObfuscatedBookSubject' => [fn (int $i) => $service->looksLikeObfuscatedBookSubjectPhp($names[$i]), fn (int $i) => $service->looksLikeObfuscatedBookSubjectNative($names[$i])],
                'determineReadableBookSearchName' => [fn (int $i) => $service->determineReadableBookSearchNamePhp($names[$i], $parsed[$i]), fn (int $i) => $service->determineReadableBookSearchNameNative($names[$i], $parsed[$i])],
            ];
            foreach ($pairs as $method => $calls) {
                foreach ($names as $i => $name) {
                    self::assertEquals($calls[0]($i), $calls[1]($i), $method.': '.$name);
                }
                for ($warm = 0; $warm < 1024; $warm++) {
                    $calls[0]($warm % count($names));
                    $calls[1]($warm % count($names));
                }
                $samples = [[], []];
                $orders = [];
                $indices = [];
                for ($sample = 0; $sample < 20480; $sample++) {
                    $i = mt_rand(0, count($names) - 1);
                    $first = mt_rand(0, 1);
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
                $report['results'][$key] = [
                    'php' => $php, 'rust' => $rust,
                    'mean_speedup' => $php['mean_ns'] / $rust['mean_ns'], 'p99_speedup' => $php['p99_ns'] / $rust['p99_ns'],
                    'blocks' => $blocks, 'samples' => $samples, 'orders' => $orders, 'input_indices' => $indices, 'inputs' => $names,
                ];
                echo $key.' '.json_encode([$php, $rust, $php['mean_ns'] / $rust['mean_ns'], $php['p99_ns'] / $rust['p99_ns']]).PHP_EOL;
            }
        }
        file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR));
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('book_service_parity', BookService::BOOK_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library before running book parity tests.');

        return $ffi;
    }
}
