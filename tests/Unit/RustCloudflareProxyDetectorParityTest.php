<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Services\Security\IndexerProxyDetector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Parity and boundary suite for Rust FFI helpers in `rust/pipeline-ffi/src/irc.rs`:
 * - `CloudflareIpRangeService`: `parseRangesFromBody`, `normalizeRanges`, `isValidCidr`
 * - `IndexerProxyDetector`: `refererLooksLikeIndexer`, `matchesAnyPattern`, `key`
 *
 * Paired randomized benchmark summary (macOS Darwin arm64, `cargo build --release`, 40 paired runs x 128 calls):
 * - `CloudflareIpRangeService::parseRangesFromBody` (common 22 ranges): PHP 5293.0 ns mean / 20708.0 ns p99 vs Rust 1240.6 ns mean / 8666.0 ns p99 -> 4.27x mean, 2.39x p99 (adopted in production).
 * - `CloudflareIpRangeService::parseRangesFromBody` (uncommon p99 256 ranges): PHP 55818.0 ns mean / 126792.0 ns p99 vs Rust 18827.9 ns mean / 48208.0 ns p99 -> 2.96x mean, 2.63x p99 (adopted in production).
 * - `CloudflareIpRangeService::normalizeRanges` (common 22 ranges): PHP 5052.7 ns mean / 23333.0 ns p99 vs Rust 1414.4 ns mean / 8791.0 ns p99 -> 3.57x mean, 2.65x p99 (adopted for N >= 4).
 * - `CloudflareIpRangeService::normalizeRanges` (uncommon p99 256 ranges): PHP 55443.9 ns mean / 121917.0 ns p99 vs Rust 21092.9 ns mean / 55666.0 ns p99 -> 2.63x mean, 2.19x p99 (adopted for N >= 4).
 * - `CloudflareIpRangeService::isValidCidr` (standalone single CIDR): PHP 131.0-168.1 ns mean vs Rust 95.6-146.0 ns mean -> 1.15x-1.37x mean, 1.20x-1.25x p99 (retained native PHP for standalone calls; `isValidCidrNative` tested).
 * - `IndexerProxyDetector::refererLooksLikeIndexer` (common 2 patterns): PHP 395.3 ns mean / 833.0 ns p99 vs Rust 225.1 ns mean / 375.0 ns p99 -> 1.76x mean, 2.22x p99 (adopted in production).
 * - `IndexerProxyDetector::refererLooksLikeIndexer` (uncommon p99 10 patterns miss): PHP 1287.5 ns mean / 9791.0 ns p99 vs Rust 645.9 ns mean / 2625.0 ns p99 -> 1.99x mean, 3.73x p99 (adopted in production).
 * - `IndexerProxyDetector::matchesAnyPattern` (pre-split array): PHP 350.3 ns mean / 708.0 ns p99 vs Rust 547.8 ns mean / 1000.0 ns p99 -> 0.64x mean, 0.71x p99 due to array implode overhead (retained native PHP in production; `matchesAnyPatternNative` tested).
 * - `IndexerProxyDetector::key` (3 parts with IPv6 MD5): PHP 312.3 ns mean / 416.0 ns p99 vs Rust 283.1 ns mean / 334.0 ns p99 -> 1.10x mean, 1.25x p99 (retained native PHP in production; `keyNative` tested).
 */
final class RustCloudflareProxyDetectorParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int32_t nntmux_cloudflare_is_valid_cidr(const char *cidr, size_t cidr_len);
        ssize_t nntmux_cloudflare_parse_cidr_body(const char *body, size_t body_len, uint8_t allow_proxy_tokens, char *out_buf, size_t out_cap);
        ssize_t nntmux_cloudflare_normalize_cidrs_nul(const char *input, size_t input_len, size_t count, uint8_t allow_proxy_tokens, char *out_buf, size_t out_cap);
        int32_t nntmux_proxy_detector_match_referer(const char *haystack, size_t haystack_len, const char *patterns, size_t patterns_len, size_t count, uint8_t mode);
        ssize_t nntmux_proxy_detector_cache_key_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        CloudflareIpRangeService::setUseRustFfi(true);
        IndexerProxyDetector::setUseRustFfi(true);
        $ffi = RustPipelineLibrary::resolveDomain('cloudflare_proxy_parity_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
    }

    protected function tearDown(): void
    {
        CloudflareIpRangeService::setUseRustFfi(true);
        IndexerProxyDetector::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_c_abi_resize_and_error_contracts(): void
    {
        $tiny = $this->ffi->new('char[2]');
        $body = "173.245.48.0/20\n2400:cb00::/32\n";

        // @phpstan-ignore method.notFound
        $neededBody = $this->ffi->nntmux_cloudflare_parse_cidr_body($body, strlen($body), 0, $tiny, 2);
        self::assertSame(-strlen("173.245.48.0/20\n2400:cb00::/32"), $neededBody);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_cloudflare_parse_cidr_body("invalid-cidr\n", 13, 0, $tiny, 2));

        $nul = "173.245.48.0/20\x002400:cb00::/32";
        // @phpstan-ignore method.notFound
        $neededNul = $this->ffi->nntmux_cloudflare_normalize_cidrs_nul($nul, strlen($nul), 2, 0, $tiny, 2);
        self::assertSame(-strlen("173.245.48.0/20\n2400:cb00::/32"), $neededNul);

        // Mismatched segment count returns -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_cloudflare_normalize_cidrs_nul($nul, strlen($nul), 1, 0, $tiny, 2));

        // Huge prefix (>18 non-zero digits) declines with -1
        $hugePrefixCidr = '192.0.2.1/'.str_repeat('9', 25);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_cloudflare_is_valid_cidr($hugePrefixCidr, strlen($hugePrefixCidr)));

        // Referer matcher invalid mode or mismatched count returns -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_proxy_detector_match_referer('http://hydra', 12, 'hydra', 5, 0, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_proxy_detector_match_referer('http://hydra', 12, "hydra\0prowlarr", 14, 1, 1));

        // Cache key resize and count mismatch contracts
        $keyParts = "count\0tok\0searches";
        // @phpstan-ignore method.notFound
        $neededKey = $this->ffi->nntmux_proxy_detector_cache_key_nul($keyParts, strlen($keyParts), 3, $tiny, 2);
        self::assertSame(-strlen('proxy_detect:count:tok:searches'), $neededKey);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_proxy_detector_cache_key_nul($keyParts, strlen($keyParts), 2, $tiny, 2));
    }

    public function test_cloudflare_is_valid_cidr_parity_across_ipv4_ipv6_and_fuzz(): void
    {
        $service = new CloudflareIpRangeService(new Filesystem);
        $isValidMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'isValidCidr');
        $isValidPhpMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'isValidCidrPhp');

        $cases = [
            // Valid IPv4 CIDRs
            '0.0.0.0/0',
            '127.0.0.1/8',
            '192.0.2.1/08',
            '192.0.2.1/00000000000000000000000032',
            '173.245.48.0/20',
            '255.255.255.255/32',
            // Invalid IPv4 CIDRs
            '',
            '173.245.48.0',
            '173.245.48.0/',
            '/24',
            '173.245.48.0/33',
            '173.245.48.0/-1',
            '173.245.48.0/+24',
            '173.245.48.0/24a',
            '173.245.48.0/24/1',
            '01.2.3.4/24',
            '1.02.3.4/24',
            '1.2.3.00/24',
            '256.0.0.1/24',
            '1.2.3/24',
            '1.2.3.4.5/24',
            '1.2.3.4./24',
            '.1.2.3.4/24',
            '1..2.3.4/24',
            ' 173.245.48.0/20',
            '173.245.48.0/20 ',
            // Valid IPv6 CIDRs
            '::/0',
            '::/128',
            '::1/128',
            '1::/64',
            '1::1/64',
            '2400:cb00::/32',
            '2a06:98c0::/29',
            '2001:0db8:0000:0000:0000:0000:0000:0001/128',
            'FFFF:AbCd:0:00:000:0000::1/064',
            '::ffff:192.0.2.1/96',
            '::ffff:0:192.0.2.1/96',
            '::192.0.2.1/128',
            '1:2:3:4:5:6:192.0.2.1/128',
            '1:2:3:4:5::192.0.2.1/64',
            '::1:2:3:4:5:192.0.2.1/64',
            '1::2:3:4:5:192.0.2.1/64',
            '::1:2:3:4:5:6:7/128',
            '1:2:3:4:5:6:7::/128',
            // Invalid IPv6 CIDRs
            '::/129',
            ':::1/64',
            ':1/64',
            '1:/64',
            '2001:db8::1::2/64',
            '1:2:3:4:5:6:7/64',
            '1:2:3:4:5:6:7:8:9/64',
            '1:2:3:4:5:6:7::8/64',
            '::1:2:3:4:5:6:7:8/64',
            '1:2:3:4:5:6:7:8::/64',
            '1:2:3:4:5:6::192.0.2.1/64',
            '::1:2:3:4:5:6:192.0.2.1/64',
            '1:2:3:4:5:6:7:192.0.2.1/64',
            '1:2:3:4:5:192.0.2.1/64',
            ':192.0.2.1/64',
            '192.0.2.1::/64',
            '::01.02.03.04/96',
            '00001:2:3:4:5:6:7:8/64',
            'fe80::1%eth0/64',
            '2001:db8::g/64',
        ];

        mt_srand(20261024);
        $alphabet = "0123456789abcdefABCDEF:.%/ \0\n";
        for ($i = 0; $i < 1000; $i++) {
            $seed = $cases[$i % count($cases)];
            $mutated = $seed;
            for ($j = 0; $j < 2; $j++) {
                $mutated = substr_replace(
                    $mutated,
                    $alphabet[mt_rand(0, strlen($alphabet) - 1)],
                    mt_rand(0, strlen($mutated)),
                    mt_rand(0, 1),
                );
            }
            $cases[] = $mutated;
        }

        foreach ($cases as $idx => $cidr) {
            $expected = $isValidPhpMethod->invoke($service, $cidr);
            $actual = $isValidMethod->invoke($service, $cidr);
            $native = CloudflareIpRangeService::isValidCidrNative($cidr);

            self::assertSame($expected, $actual, "isValidCidr mismatch at #{$idx} [{$cidr}]");
            if ($native !== null) {
                self::assertSame($expected, $native, "isValidCidrNative mismatch at #{$idx} [{$cidr}]");
            }
        }
    }

    public function test_cloudflare_parse_ranges_from_body_and_normalize_ranges_parity_and_exceptions(): void
    {
        $service = new CloudflareIpRangeService(new Filesystem);
        $parseMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'parseRangesFromBody');
        $normalizeMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'normalizeRanges');

        $largeRanges = [];
        for ($i = 0; $i < 45; $i++) {
            $largeRanges[] = sprintf('10.%d.%d.0/24', intdiv($i, 16), ($i % 16) * 4);
            $largeRanges[] = sprintf('2001:db8:%x::/48', $i + 1);
        }
        // Include duplicates across the 32-item stack/overflow boundary
        $largeRangesWithDups = array_merge($largeRanges, array_slice($largeRanges, 0, 20), array_slice($largeRanges, 50, 20));

        $bodies = [
            '',
            "   \r\n  # comment only \n\t\r",
            "173.245.48.0/20\n104.16.0.0/13\n2400:cb00::/32\n",
            " # Cloudflare IPv4\r\n \t\x0b173.245.48.0/20\0 \r104.16.0.0/13\r\n173.245.48.0/20\n# IPv6\n2400:cb00::/32\n2a06:98c0::/29\n2400:cb00::/32",
            implode("\r\n", $largeRangesWithDups),
        ];

        foreach ($bodies as $idx => $body) {
            CloudflareIpRangeService::setUseRustFfi(false);
            $expected = $parseMethod->invoke($service, $body);

            CloudflareIpRangeService::setUseRustFfi(true);
            $actual = $parseMethod->invoke($service, $body);
            $native = CloudflareIpRangeService::parseRangesFromBodyNative($body);

            self::assertSame($expected, $actual, "parseRangesFromBody mismatch at #{$idx}");
            self::assertSame($expected, $native, "parseRangesFromBodyNative mismatch at #{$idx}");
        }

        $rangeArrays = [
            [[], false],
            [['173.245.48.0/20'], false],
            [['173.245.48.0/20', '2400:cb00::/32', '173.245.48.0/20'], false],
            [[null, 123, false, [], ' 173.245.48.0/20 ', '#skip', '', '104.16.0.0/13', '2400:cb00::/32', '2a06:98c0::/29'], false],
            [[' private_ranges ', 'REMOTE_ADDR', 'PRIVATE_SUBNETS', '10.0.0.0/8', 'REMOTE_ADDR'], true],
            [$largeRangesWithDups, false],
        ];

        foreach ($rangeArrays as $idx => [$ranges, $allowTokens]) {
            CloudflareIpRangeService::setUseRustFfi(false);
            $expected = $normalizeMethod->invoke($service, $ranges, $allowTokens);

            CloudflareIpRangeService::setUseRustFfi(true);
            $actual = $normalizeMethod->invoke($service, $ranges, $allowTokens);
            $native = CloudflareIpRangeService::normalizeRangesNative($ranges, $allowTokens);

            self::assertSame($expected, $actual, "normalizeRanges mismatch at #{$idx}");
            self::assertSame($expected, $native, "normalizeRangesNative mismatch at #{$idx}");
        }

        // Embedded NUL inside a range declines in normalizeRangesNative but succeeds via PHP fallback in normalizeRanges
        $withNul = ["\x00173.245.48.0/20\x00", '104.16.0.0/13', '2400:cb00::/32', '2a06:98c0::/29'];
        self::assertNull(CloudflareIpRangeService::normalizeRangesNative($withNul));
        self::assertSame(
            ['173.245.48.0/20', '104.16.0.0/13', '2400:cb00::/32', '2a06:98c0::/29'],
            $normalizeMethod->invoke($service, $withNul),
        );

        // Form-feed (\x0c) is NOT stripped by PHP trim(), so it must be rejected identically
        $invalidArrays = [
            [['173.245.48.0/20', 'invalid-entry', '2400:cb00::/32', '2a06:98c0::/29'], false],
            [['REMOTE_ADDR', '173.245.48.0/20', '2400:cb00::/32', '2a06:98c0::/29'], false],
            [["\x0c173.245.48.0/20", '104.16.0.0/13', '2400:cb00::/32', '2a06:98c0::/29'], false],
        ];

        foreach ($invalidArrays as $idx => [$badRanges, $allowTokens]) {
            self::assertNull(CloudflareIpRangeService::normalizeRangesNative($badRanges, $allowTokens));

            CloudflareIpRangeService::setUseRustFfi(false);
            $phpError = null;
            try {
                $normalizeMethod->invoke($service, $badRanges, $allowTokens);
            } catch (RuntimeException $e) {
                $phpError = $e->getMessage();
            }

            CloudflareIpRangeService::setUseRustFfi(true);
            $rustError = null;
            try {
                $normalizeMethod->invoke($service, $badRanges, $allowTokens);
            } catch (RuntimeException $e) {
                $rustError = $e->getMessage();
            }

            self::assertNotNull($phpError, "Expected RuntimeException at invalid case #{$idx}");
            self::assertSame($phpError, $rustError, "Exception message mismatch at invalid case #{$idx}");
        }
    }

    public function test_indexer_proxy_detector_referer_matching_and_cache_key_parity(): void
    {
        $detector = new IndexerProxyDetector(new Repository(new ArrayStore));
        $matchesAnyMethod = new ReflectionMethod(IndexerProxyDetector::class, 'matchesAnyPattern');
        $keyMethod = new ReflectionMethod(IndexerProxyDetector::class, 'key');

        $referers = [
            null,
            '',
            'http://prowlarr.local:9696/1/api',
            'https://HYDRA.internal:5076/getnzb?id=123',
            'https://indexer.example.com/details/0',
            "https://custom-\xc3\x96-proxy.local/api",
        ];

        $specs = [
            '',
            'hydra,prowlarr',
            " \t hydra \r\n , 0 , \x0b prowlarr ",
            "nzbhydra2, custom-\xc3\x96-proxy",
            "\x0chydra,prowlarr",
        ];

        foreach ($specs as $spec) {
            $rawSplit = preg_split('/[\r\n,]+/', $spec) ?: [];
            $phpPatterns = array_values(array_filter(array_map(
                static fn (mixed $p): string => is_string($p) ? trim($p) : '',
                $rawSplit,
            )));

            foreach ($referers as $referer) {
                $expected = ($referer !== null && $referer !== '')
                    ? $matchesAnyMethod->invoke($detector, $referer, $phpPatterns)
                    : false;
                $actual = IndexerProxyDetector::refererLooksLikeIndexerNative($referer, $spec);
                self::assertSame($expected, $actual, "refererLooksLikeIndexerNative mismatch for referer=[{$referer}] spec=[{$spec}]");

                if ($referer !== null) {
                    $expectedArrayMatch = $matchesAnyMethod->invoke($detector, $referer, $phpPatterns);
                    $actualArrayMatch = IndexerProxyDetector::matchesAnyPatternNative($referer, $phpPatterns);
                    self::assertSame($expectedArrayMatch, $actualArrayMatch);
                }
            }
        }

        // In matchesAnyPattern (mode 1), literal '0' is preserved and matched
        self::assertSame(
            $matchesAnyMethod->invoke($detector, 'https://example.com/0', ['0']),
            IndexerProxyDetector::matchesAnyPatternNative('https://example.com/0', ['0']),
        );
        self::assertNull(IndexerProxyDetector::matchesAnyPatternNative('https://example.com', ["bad\0pattern", 'hydra']));

        // Cache key parity across ASCII, colons, >40 byte tokens, and IPv6 addresses
        $keyPartsCases = [
            [],
            ['ua', 'short_token'],
            ['ua', str_repeat('a', 40)],
            ['ua', str_repeat('a', 41)],
            ['count', '0123456789abcdef0123456789abcdef0123456789', 'searches'],
            ['ip', '10.0.0.1', 'indexer_ua'],
            ['ip', '2001:db8:85a3::8a2e:370:7334', 'indexer_ua'],
            ['part:with:colon', 'normal', str_repeat('z', 64)],
        ];

        foreach ($keyPartsCases as $idx => $parts) {
            $expectedKey = $keyMethod->invoke($detector, ...$parts);
            $nativeKey = IndexerProxyDetector::keyNative(...$parts);
            self::assertSame($expectedKey, $nativeKey, "keyNative mismatch at #{$idx}");
        }

        self::assertNull(IndexerProxyDetector::keyNative('ua', "embedded\0nul"));
    }

    /**
     * Reproduce: NNTMUX_CF_PROXY_BENCH=1 php artisan test --compact
     * tests/Unit/RustCloudflareProxyDetectorParityTest.php --filter=benchmark
     */
    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_CF_PROXY_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired microbenchmark.');
        }

        $service = new CloudflareIpRangeService(new Filesystem);
        $parseMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'parseRangesFromBody');
        $normalizeMethod = new ReflectionMethod(CloudflareIpRangeService::class, 'normalizeRanges');
        $detector = new IndexerProxyDetector(new Repository(new ArrayStore));
        $matchesAnyMethod = new ReflectionMethod(IndexerProxyDetector::class, 'matchesAnyPattern');

        $cfRanges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];
        $bodyCommon = implode("\n", $cfRanges)."\n";
        $referer = 'http://prowlarr.local:9696/1/api?t=get&id=9876543210abcdef';
        $spec = 'hydra, prowlarr';

        $workloads = [
            'parseRangesFromBody/common22' => [
                static function () use ($service, $parseMethod, $bodyCommon): array {
                    CloudflareIpRangeService::setUseRustFfi(false);

                    return $parseMethod->invoke($service, $bodyCommon);
                },
                static function () use ($service, $parseMethod, $bodyCommon): array {
                    CloudflareIpRangeService::setUseRustFfi(true);

                    return $parseMethod->invoke($service, $bodyCommon);
                },
            ],
            'normalizeRanges/common22' => [
                static function () use ($service, $normalizeMethod, $cfRanges): array {
                    CloudflareIpRangeService::setUseRustFfi(false);

                    return $normalizeMethod->invoke($service, $cfRanges, false);
                },
                static function () use ($service, $normalizeMethod, $cfRanges): array {
                    CloudflareIpRangeService::setUseRustFfi(true);

                    return $normalizeMethod->invoke($service, $cfRanges, false);
                },
            ],
            'refererLooksLikeIndexer/common2' => [
                static function () use ($detector, $matchesAnyMethod, $referer, $spec): bool {
                    $patterns = preg_split('/[\r\n,]+/', $spec) ?: [];
                    $patterns = array_values(array_filter(array_map(
                        static fn (mixed $p): string => is_string($p) ? trim($p) : '',
                        $patterns,
                    )));

                    return $matchesAnyMethod->invoke($detector, $referer, $patterns);
                },
                static fn (): ?bool => IndexerProxyDetector::refererLooksLikeIndexerNative($referer, $spec),
            ],
        ];

        $pct99 = static function (array $vals): float {
            sort($vals);

            return (float) $vals[(int) ceil(count($vals) * 0.99) - 1];
        };

        mt_srand(20261024);
        foreach ($workloads as $name => [$baseline, $candidate]) {
            self::assertSame($baseline(), $candidate(), $name);
            for ($i = 0; $i < 256; $i++) {
                $baseline();
                $candidate();
            }
            $samples = [[], []];
            for ($run = 0; $run < 40; $run++) {
                $order = mt_rand(0, 1) ? [0, 1] : [1, 0];
                foreach ($order as $side) {
                    $call = $side === 0 ? $baseline : $candidate;
                    for ($i = 0; $i < 128; $i++) {
                        $t0 = hrtime(true);
                        $call();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
            }
            $mean = (array_sum($samples[0]) / count($samples[0])) / (array_sum($samples[1]) / count($samples[1]));
            $p99 = $pct99($samples[0]) / $pct99($samples[1]);
            fwrite(STDOUT, sprintf("BENCH %s mean=%.2fx p99=%.2fx\n", $name, $mean, $p99));
        }
    }
}
