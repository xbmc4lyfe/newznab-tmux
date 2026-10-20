<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\NativePerArticleObfuscation;
use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\Binaries\PerArticleObfuscationRouter;
use App\Services\BlacklistService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PerArticleObfuscationCorpus;

/**
 * Parity between HeaderParser::markPerArticleObfuscated() and its Rust candidate. The corpus
 * test runs everywhere; the native tests need a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.
 */
final class PerArticleObfuscationNativeTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>
     */
    private static function php(array $headers, int $minArticles): array
    {
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function __construct() {}
        }, false);
        $parser->markPerArticleObfuscated($headers, $minArticles);
        $numbers = $parser->perArticleObfuscatedNumbers();
        sort($numbers);

        return $numbers;
    }

    /**
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private static function sorted(array $numbers): array
    {
        sort($numbers);

        return $numbers;
    }

    public function test_header_parser_size_floor_mirrors_the_native_one(): void
    {
        self::assertSame(NativePerArticleObfuscation::MIN_HEADERS, HeaderParser::NATIVE_MIN_HEADERS);
        self::assertSame(NativePerArticleObfuscation::MAX_SUBJECT_LENGTH, PerArticleObfuscationRouter::MAX_SUBJECT_LENGTH);
    }

    public function test_corpus_is_deterministic_and_exercises_marking(): void
    {
        self::assertSame(PerArticleObfuscationCorpus::stratum('mixed', 7, 2000), PerArticleObfuscationCorpus::stratum('mixed', 7, 2000));
        self::assertNotSame(PerArticleObfuscationCorpus::stratum('mixed', 7, 2000), PerArticleObfuscationCorpus::stratum('mixed', 8, 2000));
        self::assertGreaterThan(19000, count(self::php(PerArticleObfuscationCorpus::stratum('kenpsx', 7), 16)));
        self::assertGreaterThan(19000, count(self::php(PerArticleObfuscationCorpus::stratum('boneless', 7), 16)));
        self::assertSame([], self::php(PerArticleObfuscationCorpus::stratum('real-uploads', 7), 16));
    }

    public function test_header_parser_uses_the_native_port_and_skips_the_same_articles(): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        $headers = PerArticleObfuscationCorpus::stratum('mixed', 11, 2 * HeaderParser::NATIVE_MIN_HEADERS);
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function __construct() {}

            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        }, $native);

        $marked = $parser->markPerArticleObfuscated($headers, 16);
        $numbers = $parser->perArticleObfuscatedNumbers();
        sort($numbers);

        self::assertSame(self::php($headers, 16), $numbers);
        self::assertSame($marked, $parser->parse($headers, 'alt.test')['obfuscated']);
    }

    public function test_native_declines_subjects_longer_than_the_backtrack_safe_length(): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        // Token-poor ranges stay on PHP; the unsampled path still answers for them.
        foreach (['non-yenc', 'readable-yenc', 'bare-random', 'single-part-hashed', 'text-with-uploads'] as $name) {
            // Above MIN_HEADERS, so the sample (not the size floor) keeps these on PHP.
            $routed = PerArticleObfuscationCorpus::stratum($name, 5, 2 * NativePerArticleObfuscation::MIN_HEADERS);
            self::assertNull($native->mark($routed, 16));
            $marked = $native->markWithoutSampling($routed, 16);
            self::assertNotNull($marked);
            self::assertSame(self::php($routed, 16), self::sorted($marked));
        }
        // Alternating real and obfuscated articles at an even period are still sampled.
        $alternating = [];
        $obfuscated = PerArticleObfuscationCorpus::stratum('kenpsx', 6, 10000);
        $real = PerArticleObfuscationCorpus::stratum('readable-yenc', 6, 10000);
        for ($i = 0; $i < 10000; $i++) {
            $alternating[] = $real[$i];
            $alternating[] = $obfuscated[$i];
        }
        self::assertNotNull($native->mark($alternating, 16));
        self::assertNull($native->mark(PerArticleObfuscationCorpus::stratum('kenpsx', 5, NativePerArticleObfuscation::MIN_HEADERS - 1), 16));
        // The sampler stays linear on long zero runs inside a part count.
        $zeros = PerArticleObfuscationCorpus::stratum('non-yenc', 5, NativePerArticleObfuscation::MIN_HEADERS);
        foreach (array_keys($zeros) as $index) {
            $zeros[$index]['Subject'] = 'x (1/'.str_repeat('0', 32000).'x)';
        }
        $started = hrtime(true);
        self::assertNull($native->mark($zeros, 16));
        self::assertLessThan(1e9, hrtime(true) - $started);
        $headers = PerArticleObfuscationCorpus::stratum('kenpsx', 5, NativePerArticleObfuscation::MIN_HEADERS);
        self::assertNotSame([], $native->mark($headers, 16));
        $headers[] = ['Number' => 1, 'Subject' => str_repeat(' ', NativePerArticleObfuscation::MAX_SUBJECT_LENGTH).')'];
        self::assertNull($native->mark($headers, 16));
        self::assertNull($native->markWithoutSampling($headers, 16));
    }

    public function test_unusual_article_numbers_take_the_filtered_retry_and_match_php(): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        $base = PerArticleObfuscationCorpus::stratum('kenpsx', 4, 2000);
        $forms = [static fn (int $n): int => $n, static fn (int $n): string => ' '.$n, static fn (int $n): string => $n.' ',
            static fn (int $n): string => $n."\n", static fn (int $n): string => '+'.$n, static fn (int $n): float => $n + 0.5,
            static fn (int $n): string => '1e3', static fn (int $n): string => 'abc', static fn (int $n): ?int => null,
            static fn (int $n): bool => true, static fn (int $n): string => '0000'.$n];
        foreach ($forms as $index => $form) {
            $headers = $base;
            foreach ($headers as $row => $header) {
                if ($row % 7 === 0) {
                    $headers[$row]['Number'] = $form(5_000_000_000 + $row);
                }
            }
            $marked = $native->markWithoutSampling($headers, 16);
            self::assertNotNull($marked, 'form '.$index);
            self::assertSame(self::php($headers, 16), self::sorted($marked), 'form '.$index);
        }
    }

    public function test_counts_past_the_double_range_match_php_without_declining(): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        // PHP's (int) caps up to the largest double and gives 0 beyond it; only the rounding
        // boundary itself (17976931348623158...) declines.
        $boundary = '17976931348623158'.str_repeat('0', 292);
        foreach ([str_repeat('9', 308) => false, str_repeat('9', 309) => false, str_repeat('1', 310) => false, $boundary => true] as $total => $declines) {
            $headers = PerArticleObfuscationCorpus::stratum('kenpsx', 9, 2 * NativePerArticleObfuscation::MIN_HEADERS);
            for ($i = 0; $i < 40; $i++) {
                $headers[] = ['Number' => (string) (9_000_000_000 + $i),
                    'Subject' => '[40/62] "'.md5((string) $i).'" yEnc ('.($i + 2).'/'.$total.')'];
            }
            $headers[] = ['Number' => (string) $total, 'Subject' => '[40/62] "'.md5('n').'" yEnc (3/687)'];
            $marked = $native->markWithoutSampling($headers, 16);
            if ($declines) {
                self::assertNull($marked);
            } else {
                self::assertNotNull($marked, strlen((string) $total).' digits');
                self::assertSame(self::php($headers, 16), self::sorted($marked), strlen((string) $total).' digits');
            }
        }
    }

    public function test_the_default_parser_builds_the_wrapper_only_for_routed_ranges_and_stops_after_a_failed_load(): void
    {
        // No Laravel config here, so the default wrapper's library path cannot resolve and its
        // load fails: the PHP result must stand, and later ranges must neither route nor rebuild.
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function __construct() {}
        });
        $wrapperOf = static fn (HeaderParser $parser): mixed => (new \ReflectionProperty(HeaderParser::class, 'native'))->getValue($parser);
        $small = PerArticleObfuscationCorpus::stratum('kenpsx', 3, HeaderParser::NATIVE_MIN_HEADERS - 1);
        $parser->markPerArticleObfuscated($small, 16);
        self::assertNull($wrapperOf($parser));

        $headers = PerArticleObfuscationCorpus::stratum('kenpsx', 3, 2 * HeaderParser::NATIVE_MIN_HEADERS);
        $parser->markPerArticleObfuscated($headers, 16);
        $numbers = $parser->perArticleObfuscatedNumbers();
        sort($numbers);
        self::assertSame(self::php($headers, 16), $numbers);
        $wrapper = $wrapperOf($parser);
        self::assertInstanceOf(NativePerArticleObfuscation::class, $wrapper);
        self::assertTrue($wrapper->unavailable());

        $parser->markPerArticleObfuscated($headers, 16);
        self::assertSame(spl_object_id($wrapper), spl_object_id($wrapperOf($parser)));
        self::assertCount(count($numbers), $parser->perArticleObfuscatedNumbers());
    }

    public function test_the_gate_samples_strictly_alternating_ranges_in_every_phase(): void
    {
        // Obfuscated posts on even positions, plain text on odd ones: fixed probes on odd positions
        // would keep these ranges on PHP every time.
        $check = new \ReflectionMethod(PerArticleObfuscationGate::class, 'mayCarryPartCounts');
        $passes = 0;
        for ($seed = 1; $seed <= 20; $seed++) {
            $obfuscated = PerArticleObfuscationCorpus::stratum('boneless', $seed, 10000);
            $text = PerArticleObfuscationCorpus::stratum('non-yenc', $seed, 10000);
            $headers = [];
            for ($i = 0; $i < 10000; $i++) {
                $headers[] = ['Number' => (string) (7_000_000_000 + 20_000 * $seed + 2 * $i)] + $obfuscated[$i];
                $headers[] = ['Number' => (string) (7_000_000_000 + 20_000 * $seed + 2 * $i + 1)] + $text[$i];
            }
            $first = $check->invoke(null, $headers);
            // Seeded from the range, so repeating a range repeats its routing.
            self::assertSame($first, $check->invoke(null, $headers));
            $passes += $first ? 1 : 0;
        }
        self::assertGreaterThan(15, $passes);
    }

    /** @return iterable<string, array{string, int}> */
    public static function strata(): iterable
    {
        foreach (array_keys(PerArticleObfuscationCorpus::strata()) as $name) {
            foreach ([1, 2, 3] as $seed) {
                yield $name.' seed '.$seed => [$name, $seed];
            }
        }
    }

    #[DataProvider('strata')]
    public function test_native_marks_exactly_what_php_marks(string $name, int $seed): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        $size = $name === 'large-range' ? 50000 : null;
        $headers = PerArticleObfuscationCorpus::stratum($name, $seed, $size);
        foreach ([16, 8, 40] as $minArticles) {
            $marked = $native->markWithoutSampling($headers, $minArticles);
            // No corpus count reaches 309 digits and no subject exceeds 512 bytes, so nothing declines.
            self::assertNotNull($marked, $name.' at '.$minArticles);
            sort($marked);
            self::assertSame(self::php($headers, $minArticles), $marked, $name.' at '.$minArticles);
        }
    }
}
