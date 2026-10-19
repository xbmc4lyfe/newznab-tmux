<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\NativePerArticleObfuscation;
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
        $headers = PerArticleObfuscationCorpus::stratum('mixed', 11, 4000);
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

    public function test_counts_php_reads_as_an_infinite_double_keep_the_php_result(): void
    {
        $native = new NativePerArticleObfuscation((string) getenv('NNTMUX_TEST_NATIVE_LIBRARY'));
        if (getenv('NNTMUX_TEST_NATIVE_LIBRARY') === false || ! $native->available()) {
            self::markTestSkipped('Needs a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        // PHP's (int) caps a 308-digit total at PHP_INT_MAX but turns 309 digits into 0.
        foreach ([308 => false, 309 => true] as $digits => $declines) {
            $headers = PerArticleObfuscationCorpus::stratum('kenpsx', 9, 2 * NativePerArticleObfuscation::MIN_HEADERS);
            for ($i = 0; $i < 40; $i++) {
                $headers[] = ['Number' => (string) (9_000_000_000 + $i),
                    'Subject' => '[40/62] "'.md5((string) $i).'" yEnc ('.($i + 2).'/'.str_repeat('9', $digits).')'];
            }
            $headers[] = ['Number' => str_repeat('9', $digits), 'Subject' => '[40/62] "'.md5('n').'" yEnc (3/687)'];
            $marked = $native->mark($headers, 16);
            if ($declines) {
                self::assertNull($marked);
            } else {
                self::assertNotNull($marked);
                self::assertSame(self::php($headers, 16), self::sorted($marked));
            }
        }
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
