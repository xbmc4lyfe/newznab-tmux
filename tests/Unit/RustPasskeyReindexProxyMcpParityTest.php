<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Passkeys\ConfigureCeremonyStepManagerFactoryAction;
use App\Http\Middleware\TrustProxies;
use App\Jobs\PurgeDeletedAccounts;
use App\Jobs\ReindexReleaseJob;
use App\Mcp\Tools\Concerns\HasVersionedStructuredOutput;
use App\Mcp\Tools\PlanVerificationTool;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5.12 on Apple Silicon Darwin. Measured run:
 *
 * - Common (all five helpers, typical inputs): PHP mean 0.96 us, Rust mean 7.12 us -> 0.13x; p99 0.15x.
 * - Uncommon (localhost RP, 256 proxies, 64 fields, unknown keys): mean 0.07x; p99 0.09x
 *   (PHP 9.34 us / Rust 106.26 us).
 *
 * Packet marshaling, FFI buffer allocation and decoding dominate these sub-microsecond helpers, so
 * Rust misses the 1.5x gate on both the common mean and the uncommon p99 and every helper keeps PHP
 * as the default (`usesRustFfi() === false`). Each run prints its ratios to STDERR.
 */
class RustPasskeyReindexProxyMcpParityTest extends TestCase
{
    private const CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    private const STATIC_CLASSES = [
        ConfigureCeremonyStepManagerFactoryAction::class,
        TrustProxies::class,
        PurgeDeletedAccounts::class,
        ReindexReleaseJob::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::STATIC_CLASSES as $class) {
            $class::setUseRustFfi(null);
            (new ReflectionClass($class))->setStaticPropertyValue('ffi', null);
        }
        $envelope = self::envelopeClass();
        $envelope::setUseRustFfi(null);
        (new ReflectionClass($envelope))->setStaticPropertyValue('envelopeMetaFfi', null);
        PlanVerificationTool::setEnvelopeMetaUseRustFfi(null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach ([...self::STATIC_CLASSES, self::envelopeClass()] as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }

        // Tools that declare their own FFI toggle keep it; the envelope toggle is separate.
        $this->assertFalse(PlanVerificationTool::envelopeMetaUsesRustFfi());
        PlanVerificationTool::setEnvelopeMetaUseRustFfi(true);
        $this->assertTrue(PlanVerificationTool::envelopeMetaUsesRustFfi());
        $this->assertFalse(PlanVerificationTool::usesRustFfi());
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: list<string>}>
     */
    public static function originProvider(): array
    {
        return [
            'distinct_host' => ['https://app.test', 'nn.test', ['https://app.test', 'https://nn.test']],
            'same_origin' => ['https://nn.test', 'nn.test', ['https://nn.test']],
            'localhost_port' => ['http://localhost:8000', 'localhost', ['http://localhost:8000', 'https://localhost', 'http://localhost']],
            'localhost_http' => ['http://localhost', 'localhost', ['http://localhost', 'https://localhost']],
            'localhost_https' => ['https://localhost', 'localhost', ['https://localhost', 'http://localhost']],
            'localhost_upper' => ['http://LOCALHOST', 'LOCALHOST', ['http://LOCALHOST', 'https://LOCALHOST']],
            'empty_rp' => ['', '', ['', 'https://']],
            'null_app' => [null, 'nn.test', ['https://nn.test']],
            'null_rp' => ['https://nn.test', null, ['https://nn.test']],
            'both_null' => [null, null, []],
            'nul_bytes' => ["https://a\0b", "a\0b", ["https://a\0b"]],
            'utf8' => ['https://bücher.test', 'bücher.test', ['https://bücher.test']],
            'invalid_utf8' => ["\xff", "\xfe", ["\xff", "https://\xfe"]],
            'long' => [str_repeat('h', 70000), 'x', [str_repeat('h', 70000), 'https://x']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('originProvider')]
    public function test_allowed_rp_origins_parity(?string $appUrl, ?string $rpId, array $expected): void
    {
        $this->requireFfi();
        $class = ConfigureCeremonyStepManagerFactoryAction::class;

        $this->assertSame($expected, $class::normalizeAllowedRpOriginsPhp($appUrl, $rpId));
        $this->assertSame($expected, $class::normalizeAllowedRpOriginsNative($appUrl, $rpId));
        foreach ([false, true] as $rust) {
            $class::setUseRustFfi($rust);
            $this->assertSame($expected, $class::normalizeAllowedRpOrigins($appUrl, $rpId));
        }
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>|string|null}>
     */
    public static function proxyProvider(): array
    {
        return [
            'null' => [null],
            'empty_array' => [[]],
            'empty_string' => [''],
            'wildcard' => ['*'],
            'csv_string' => ['10.0.0.1, 10.0.0.2'],
            'remote_addr' => [['REMOTE_ADDR']],
            'cidrs' => [['173.245.48.0/20', '2400:cb00::/32', '10.0.0.0/8']],
            'duplicates_kept' => [['10.0.0.1', '10.0.0.1', '']],
            'nul_and_utf8' => [["10.0.0.1\0", 'ü', "\xff"]],
            'assoc' => [['a' => '10.0.0.1']],
            'sparse' => [[1 => '10.0.0.1']],
            'non_string' => [[1, '10.0.0.1', null]],
            'nested' => [[['10.0.0.1']]],
            'large' => [array_map(static fn (int $i): string => "10.0.{$i}.0/24", range(0, 2000))],
        ];
    }

    /**
     * @param  array<array-key, mixed>|string|null  $proxies
     */
    #[DataProvider('proxyProvider')]
    public function test_trusted_proxy_list_parity(array|string|null $proxies): void
    {
        $this->requireFfi();
        $expected = $proxies !== [] ? $proxies : null;

        $this->assertSame($expected, TrustProxies::normalizeTrustedProxyListPhp($proxies));
        $this->assertSame($expected, TrustProxies::normalizeTrustedProxyListNative($proxies));
        foreach ([false, true] as $rust) {
            TrustProxies::setUseRustFfi($rust);
            $this->assertSame($expected, TrustProxies::normalizeTrustedProxyList($proxies));
        }
    }

    public function test_purge_batch_summary_parity(): void
    {
        $this->requireFfi();

        $this->assertSame(
            ['purged' => 0, 'failed' => 0, 'total' => 0, 'status' => 'noop', 'message' => 'Purged 0 deleted account(s).'],
            PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(0, 0),
        );
        $this->assertSame(
            ['purged' => 3, 'failed' => 2, 'total' => 5, 'status' => 'partial', 'message' => 'Purged 3 deleted account(s); 2 failed.'],
            PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(3, 2),
        );
        $this->assertSame('complete', PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(4, 0)['status']);
        $this->assertSame('failed', PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(-4, 1)['status']);
        $this->assertSame(PHP_INT_MAX, PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(PHP_INT_MAX, PHP_INT_MAX)['total']);

        $values = [0, 1, 2, 17, 1000, -1, -1000, PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MIN];
        foreach ($values as $purged) {
            foreach ($values as $failed) {
                $expected = PurgeDeletedAccounts::buildPurgeBatchSummaryPhp($purged, $failed);
                $this->assertSame($expected, PurgeDeletedAccounts::buildPurgeBatchSummaryNative($purged, $failed), "{$purged}/{$failed}");
                foreach ([false, true] as $rust) {
                    PurgeDeletedAccounts::setUseRustFfi($rust);
                    $this->assertSame($expected, PurgeDeletedAccounts::buildPurgeBatchSummary($purged, $failed));
                }
            }
        }
    }

    public function test_reindex_payload_parity(): void
    {
        $this->requireFfi();

        $this->assertSame(
            [
                'release_id' => 42,
                'fields' => ['searchname', 'name'],
                'attempt' => 1,
                'max_tries' => 3,
                'remaining_tries' => 2,
                'backoff_seconds' => 10,
                'should_retry' => true,
            ],
            ReindexReleaseJob::normalizeReindexPayloadPhp(42, [' searchname ', 'name', 'searchname', '', 7]),
        );
        $this->assertSame(
            [10, 20, 40, 80, 160, 300, 300],
            array_map(static fn (int $a): int => ReindexReleaseJob::normalizeReindexPayloadPhp(1, [], $a, 99)['backoff_seconds'], [1, 2, 3, 4, 5, 6, 50]),
        );

        $fieldSets = [
            [],
            ['searchname'],
            [' a ', "\ta\n", 'a', "\0\x0B", ' ', 'b'],
            ['1', 1, '01', 1.0, true, null, ['x'], ' 1'],
            ['k' => 'v', 3 => 'w', 'v'],
            ["nul\0inside", 'ü ', "\xff", "\u{00A0}x\u{00A0}"],
            array_map(static fn (int $i): string => 'field_'.($i % 37), range(0, 500)),
        ];
        $numbers = [0, 1, 2, 3, 6, 7, 100, -1, PHP_INT_MAX, PHP_INT_MIN];
        foreach ($fieldSets as $fields) {
            foreach ($numbers as $attempts) {
                foreach ([1, 3, 0, -5, PHP_INT_MAX] as $maxTries) {
                    foreach ([42, 0, -1, PHP_INT_MAX] as $releaseId) {
                        $expected = ReindexReleaseJob::normalizeReindexPayloadPhp($releaseId, $fields, $attempts, $maxTries);
                        $this->assertSame($expected, ReindexReleaseJob::normalizeReindexPayloadNative($releaseId, $fields, $attempts, $maxTries));
                    }
                }
            }
            foreach ([false, true] as $rust) {
                ReindexReleaseJob::setUseRustFfi($rust);
                $this->assertSame(
                    ReindexReleaseJob::normalizeReindexPayloadPhp(9, $fields),
                    ReindexReleaseJob::normalizeReindexPayload(9, $fields),
                );
            }
        }
    }

    public function test_envelope_meta_parity(): void
    {
        $this->requireFfi();
        $class = self::envelopeClass();

        $this->assertSame(
            ['schema_version' => 1, 'complete' => true, 'missing_required' => [], 'unknown_keys' => []],
            $class::normalizeEnvelopeMetaPhp('1', ['schema_version', 'summary', 'warnings', 'truncated']),
        );
        $this->assertSame(
            ['schema_version' => 7, 'complete' => false, 'missing_required' => ['schema_version', 'warnings', 'truncated'], 'unknown_keys' => ['extra', '0']],
            $class::normalizeEnvelopeMetaPhp('007', ['summary', 'extra', 'evidence', 'extra', 0, null, 1.5]),
        );

        $versions = [
            '1', '0', '2', '-5', '-0', '007', '999999999999999999', '-999999999999999999',
            '', ' 1', '1 ', '+1', '1e3', '1.9', '0x1A', 'abc', '12abc', "1\0", '-',
            '9223372036854775807', '9223372036854775808', '99999999999999999999999', '1e100', '-1e100', 'INF', 'NAN', '١',
        ];
        $keySets = [
            [],
            ['schema_version', 'summary', 'warnings', 'truncated'],
            ['schema_version', 'summary', 'evidence', 'results', 'warnings', 'truncated'],
            ['summary', 'summary', 'Summary', 'warnings '],
            [0, 1, '0', 'x', 'x', true, null, 2.5, ['nested']],
            ["schema_version\0", 'ü', "\xff", ''],
            array_map(static fn (int $i): string => 'key_'.($i % 50), range(0, 400)),
        ];
        foreach ($versions as $version) {
            foreach ($keySets as $keys) {
                $expected = $class::normalizeEnvelopeMetaPhp($version, $keys);
                $this->assertSame((int) $version, $expected['schema_version']);
                $this->assertSame($expected, $class::normalizeEnvelopeMetaNative($version, $keys), var_export($version, true));
                foreach ([false, true] as $rust) {
                    $class::setUseRustFfi($rust);
                    $this->assertSame($expected, $class::normalizeEnvelopeMeta($version, $keys));
                }
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        foreach (self::STATIC_CLASSES as $class) {
            (new ReflectionClass($class))->setStaticPropertyValue('ffi', false);
        }
        $envelope = self::envelopeClass();
        (new ReflectionClass($envelope))->setStaticPropertyValue('envelopeMetaFfi', false);

        $this->assertSame(['https://a', 'https://b'], ConfigureCeremonyStepManagerFactoryAction::normalizeAllowedRpOriginsNative('https://a', 'b'));
        $this->assertNull(TrustProxies::normalizeTrustedProxyListNative([]));
        $this->assertSame(['x'], TrustProxies::normalizeTrustedProxyListNative(['x']));
        $this->assertSame(PurgeDeletedAccounts::buildPurgeBatchSummaryPhp(2, 1), PurgeDeletedAccounts::buildPurgeBatchSummaryNative(2, 1));
        $this->assertSame(ReindexReleaseJob::normalizeReindexPayloadPhp(5, [' a'], 2), ReindexReleaseJob::normalizeReindexPayloadNative(5, [' a'], 2));
        $this->assertSame($envelope::normalizeEnvelopeMetaPhp('3', ['x']), $envelope::normalizeEnvelopeMetaNative('3', ['x']));
    }

    public function test_direct_c_abi_declines_malformed_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['3', '0']);
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w32_passkey_reindex_proxy_mcp($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound

        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(3, $valid, strlen($valid), $buf, 256);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(-$written, $call(3, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(3, $valid, strlen($valid), null, 0));
        $this->assertSame(-1, $call(3, null, 4, $buf, 256));
        $this->assertSame(-1, $call(3, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(0, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(99, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(3, '', 0, $small, 8));
        $this->assertSame(-1, $call(3, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(3, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(3, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $callParts(3, ['+1', '0']));
        $this->assertSame(-1, $callParts(5, ['1e3']));
        $this->assertSame(-1, $callParts(1, ['0', 'x', '0', '']));
        $this->assertSame(-1, $callParts(2, ['q']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();
        $envelope = self::envelopeClass();
        $classes = [...self::STATIC_CLASSES, $envelope];

        $common = static function () use ($envelope): void {
            ConfigureCeremonyStepManagerFactoryAction::normalizeAllowedRpOrigins('https://nn.test', 'nn.test');
            TrustProxies::normalizeTrustedProxyList(['173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32']);
            PurgeDeletedAccounts::buildPurgeBatchSummary(12, 0);
            ReindexReleaseJob::normalizeReindexPayload(123456, [], 1);
            $envelope::normalizeEnvelopeMeta('1', ['schema_version', 'summary', 'warnings', 'truncated']);
        };
        $longFields = array_map(static fn (int $i): string => " field_{$i} ", range(0, 63));
        $longProxies = array_map(static fn (int $i): string => "10.{$i}.0.0/16", range(0, 255));
        $uncommon = static function () use ($envelope, $longFields, $longProxies): void {
            ConfigureCeremonyStepManagerFactoryAction::normalizeAllowedRpOrigins('http://localhost:8000', 'localhost');
            TrustProxies::normalizeTrustedProxyList($longProxies);
            PurgeDeletedAccounts::buildPurgeBatchSummary(PHP_INT_MAX, 3);
            ReindexReleaseJob::normalizeReindexPayload(PHP_INT_MAX, $longFields, 9, 3);
            $envelope::normalizeEnvelopeMeta('007', ['summary', 'extra', 'evidence', 'extra', 'other']);
        };

        $setAll = static function (bool $rust) use ($classes): void {
            foreach ($classes as $class) {
                $class::setUseRustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $name => $workload) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < 20; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 20_000;
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach ($classes as $class) {
            $class::setUseRustFfi(null);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 32.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['uncommon']['mean'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach ($classes as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
    }

    /**
     * @return class-string
     */
    private static function envelopeClass(): string
    {
        static $class = null;

        return $class ??= (new class
        {
            use HasVersionedStructuredOutput;
        })::class;
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search', self::CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
