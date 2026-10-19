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
        $headers = PerArticleObfuscationCorpus::stratum('kenpsx', 5, 200);
        self::assertNotSame([], $native->mark($headers, 16));
        $headers[] = ['Number' => 1, 'Subject' => str_repeat(' ', NativePerArticleObfuscation::MAX_SUBJECT_LENGTH).')'];
        self::assertNull($native->mark($headers, 16));
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
            $marked = $native->mark($headers, $minArticles);
            if ($marked === null) {
                // A decline keeps the PHP result; only the saturating-integer case may decline.
                self::assertSame('adversarial', $name);

                continue;
            }
            sort($marked);
            self::assertSame(self::php($headers, $minArticles), $marked, $name.' at '.$minArticles);
        }
    }
}
