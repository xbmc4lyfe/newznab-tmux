<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\V1\ApiV1Presenter;
use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use App\Services\Api\V1\ReleaseRows;
use App\Services\Api\V1\XmlResponseContext;
use App\Services\Api\V2\ApiV2Presenter;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use stdClass;
use Throwable;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W465_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, release dylib. Timings include PHP-side filtering and framing, the FFI call and output
 * decoding plus any per-item PHP fallback; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every common and uncommon mean and p99 ratio reaches 1.5x. The
 * default run only checks that Rust-default helpers are not slower than PHP, so slower CI hosts do not
 * flake; `NNTMUX_W465_BENCH=1` enforces the full 1.5x gate for every Rust default.
 */
class RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        ApiV1Presenter::class,
        ReleaseItemPayloadBuilder::class,
        ReleaseRows::class,
        XmlResponseContext::class,
        ApiV2Presenter::class,
    ];

    /**
     * Benchmark-qualified defaults. Three strict 1000-sample runs (Apple Silicon, PHP 8.5 CLI, release
     * dylib, loaded host) gave min common mean/p99 and uncommon mean/p99 speedups of: ApiV1Presenter
     * 1.84/1.52/1.94/1.92, ReleaseItemPayloadBuilder 6.23/4.72/4.78/3.71, ReleaseRows 2.39/1.78/3.63/2.83,
     * XmlResponseContext 1.25/1.20/1.45/1.36 (computing the slot tags in PHP is most of the work) and
     * ApiV2Presenter 18.62/14.00/12.47/6.86. XmlResponseContext keeps PHP.
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ApiV1Presenter::class => true,
        ReleaseItemPayloadBuilder::class => true,
        ReleaseRows::class => true,
        XmlResponseContext::class => false,
        ApiV2Presenter::class => true,
    ];

    private const RANDOM_ROUNDS = 1500;

    private const ALPHABET = ['a', 'Z', '0', '9', '.', '-', '_', ' ', '/', '"', '\\', '<', '>', '&', "'", "\t", "\n", "\r", "\x08", "\x0c", "\x01", "\x1f", "\x7f", "\0", "\xc3\xa9", "\xe2\x82\xac", "\xe2\x80\xa8", "\xe2\x80\xa9", "\xe2\x80\xa7", "\xf0\x9f\x8e\xac", "\xff", "\xc0\xaf", "\xed\xa0\x80", "\xe2\x80"];

    private const TOTAL_TOKENS = [' ', "\t", "\n", "\r", "\x0b", "\x0c", '+', '-', '0', '1', '5', '9', '42', '.', 'e', 'E', 'x', 'a', "\0", '922337203685477580', '7', '8', '_'];

    private const OPTION_VALUES = [null, [], ['t' => 'search'], 'x', '', 0, 1.5, false, true];

    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW465RustFfi();
        }
        (new ReflectionClass(ApiV1Presenter::class))->setStaticPropertyValue('w465Ffi', null);
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW465RustFfi', 'usesW465RustFfi', 'resetW465RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), "{$class}::{$method}");
            }
            $default = (new ReflectionClassConstant($class, 'W465_RUST_FFI_DEFAULT'))->getValue();
            $this->assertSame(self::DEFAULTS[$class], $default, "{$class} default must match the benchmark evidence");
            $this->assertSame($default, $class::usesW465RustFfi(), "{$class} must start at its default");
            $class::useW465RustFfi(true);
            $this->assertTrue($class::usesW465RustFfi());
            $class::useW465RustFfi(null);
            $this->assertSame($default, $class::usesW465RustFfi(), "{$class}: null restores the default");
            $class::useW465RustFfi(false);
            $this->assertFalse($class::usesW465RustFfi());
            $class::useW465RustFfi();
            $this->assertTrue($class::usesW465RustFfi());
            $class::resetW465RustFfi();
            $this->assertSame($default, $class::usesW465RustFfi(), "{$class}: reset restores the default");
        }

        // Toggles are independent of each other and of the earlier useRustFfi switches.
        foreach (self::CLASSES as $class) {
            $class::useW465RustFfi(true);
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW465RustFfi(), "{$class} leaked into {$other}");
                }
            }
            $class::resetW465RustFfi();
        }
        $legacy = ReleaseItemPayloadBuilder::usesRustFfi();
        ReleaseItemPayloadBuilder::useW465RustFfi(! $legacy);
        $this->assertSame($legacy, ReleaseItemPayloadBuilder::usesRustFfi());
        $this->assertFalse(ApiV1Presenter::$useRustFfi);
    }

    public function test_json_string_batch_examples(): void
    {
        $values = ['Show.Name.S01E01.1080p', 'a/b "q" \\', "tab\there\x01", 'Ünïcödé €', "line\u{2028}sep", "\xff", '', 'Show.Name.S01E01.1080p', 7, null, ['x']];
        $summary = $this->both(ApiV1Presenter::class, static fn () => ApiV1Presenter::summarizeJsonStringBatch($values));
        $this->assertSame(['values' => 11, 'invalid' => 3, 'invalid_utf8' => 1, 'escaped' => 3, 'encoded_bytes' => 113, 'max_encoded_bytes' => 24, 'distinct_strings' => 6], $summary);

        // Surrogates and overlong forms are rejected like json_encode(); a NUL declines to PHP.
        $this->assertSame(2, $this->both(ApiV1Presenter::class, static fn () => ApiV1Presenter::summarizeJsonStringBatch(["\xed\xa0\x80", "\xc0\xaf"]))['invalid_utf8']);
        $this->assertSame(10, $this->both(ApiV1Presenter::class, static fn () => ApiV1Presenter::summarizeJsonStringBatch(["a\0b"]))['encoded_bytes']);
        $this->assertSame(['values' => 0, 'invalid' => 0, 'invalid_utf8' => 0, 'escaped' => 0, 'encoded_bytes' => 0, 'max_encoded_bytes' => 0, 'distinct_strings' => 0], $this->both(ApiV1Presenter::class, static fn () => ApiV1Presenter::summarizeJsonStringBatch([])));
    }

    public function test_rss_timestamp_batch_examples(): void
    {
        $timestamps = [0, 1761393600, -1, 951782400, -30610224000, -30610224001, 253402300799, 253402300800, -62135596800, PHP_INT_MAX, PHP_INT_MIN];
        $dates = $this->both(ReleaseItemPayloadBuilder::class, static fn () => ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatch($timestamps));
        $this->assertSame(array_map(static fn (int $timestamp): string => date(DATE_RSS, $timestamp), $timestamps), $dates);
        $this->assertSame('Sat, 25 Oct 2025 12:00:00 +0000', $dates[1]);
        $this->assertSame('Wed, 01 Jan 1000 00:00:00 +0000', $dates[4]);
        $this->assertSame([], $this->both(ReleaseItemPayloadBuilder::class, static fn () => ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatch([])));
        $this->assertSame(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcPhp(1761393600), $dates[1]);
    }

    public function test_total_rows_batch_examples(): void
    {
        $values = ['120', ' 120 ', '-3', 'x', '9z', 5, null, '0'];
        $summary = $this->both(ReleaseRows::class, static fn () => ReleaseRows::summarizeTotalRowsBatch($values));
        $this->assertSame(['values' => 8, 'invalid' => 2, 'numeric' => 4, 'zero' => 2, 'negative' => 1, 'max_total' => 120, 'min_total' => -3, 'distinct_totals' => 4], $summary);
        $this->assertSame(120, ReleaseRows::totalRowsFromPhp(' 120 ', 0));

        // Float forms, overflow and NULs decline to PHP.
        foreach ([['1.5', '1e3'], ['9223372036854775808'], ["12\0"], ['-.5', '.5']] as $declined) {
            $this->assertSame(ReleaseRows::summarizeTotalRowsBatchPhp($declined), ReleaseRows::summarizeTotalRowsBatchNative($declined));
        }
        $this->assertSame(['values' => 2, 'invalid' => 2, 'numeric' => 0, 'zero' => 0, 'negative' => 0, 'max_total' => 0, 'min_total' => 0, 'distinct_totals' => 0], $this->both(ReleaseRows::class, static fn () => ReleaseRows::summarizeTotalRowsBatch([1, false])));
    }

    public function test_legacy_options_batch_examples(): void
    {
        $sets = [
            ['Parameters' => ['t' => 'search'], 'Data' => [], 'Server' => ['server' => []], 'Offset' => 0, 'Type' => 'search'],
            [],
            ['Parameters' => 'bad', 'Server' => null, 'Data' => null],
            'not array',
            ['Type' => new stdClass, 'Extra' => 1],
        ];
        $summary = $this->both(XmlResponseContext::class, static fn () => XmlResponseContext::summarizeLegacyOptionsBatch($sets));
        $this->assertSame(['sets' => 5, 'invalid' => 1, 'provided' => 9, 'nulls' => 2, 'arrays' => 3, 'others' => 4, 'coerced' => 2, 'complete' => 1, 'distinct_shapes' => 4], $summary);

        // Coerced slots are exactly the ones fromLegacyOptionsPhp() resets to [].
        $context = XmlResponseContext::fromLegacyOptionsPhp($sets[2]);
        $this->assertSame([], $context->parameters);
        $this->assertSame([], $context->server);
        $this->assertSame(['sets' => 0, 'invalid' => 0, 'provided' => 0, 'nulls' => 0, 'arrays' => 0, 'others' => 0, 'coerced' => 0, 'complete' => 0, 'distinct_shapes' => 0], $this->both(XmlResponseContext::class, static fn () => XmlResponseContext::summarizeLegacyOptionsBatch([])));
    }

    public function test_rss_date_string_batch_examples(): void
    {
        $dates = ['2026-10-25 12:34:56', '2024-02-29', '2023-02-29 00:00:00', '2026-10-25T12:34:56', '2026-10-25 12:34:56.5', '@0', 'Sun, 25 Oct 2026 12:34:56 +0000', '1000-01-01 00:00:00', '9999-12-31 23:59:59', '0999-12-31 00:00:00', '2026-10-25 24:00:00'];
        $formatted = $this->both(ApiV2Presenter::class, static fn () => ApiV2Presenter::formatRssDateBatch($dates));
        $this->assertSame('Sun, 25 Oct 2026 12:34:56 +0000', $formatted[0]);
        $this->assertSame('Thu, 29 Feb 2024 00:00:00 +0000', $formatted[1]);
        $this->assertSame('Wed, 01 Mar 2023 00:00:00 +0000', $formatted[2]);
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 +0000', $formatted[5]);
        $this->assertSame('Mon, 26 Oct 2026 00:00:00 +0000', $formatted[10]);
        $this->assertSame(ApiV2Presenter::formatRssDatesPhp('2026-10-25 12:34:56'), [$formatted[0]]);
        $this->assertSame([], $this->both(ApiV2Presenter::class, static fn () => ApiV2Presenter::formatRssDateBatch([])));
        // A NUL inside a string declines the joined framing; Carbon serves the batch.
        $this->assertSame(self::outcome(static fn () => ApiV2Presenter::formatRssDateBatchPhp(["2026-10-25\0"])), self::outcome(static fn () => ApiV2Presenter::formatRssDateBatchNative(["2026-10-25\0"])));

        // Carbon exceptions propagate identically.
        $bad = ['2026-10-25 12:00:00', 'not a date at all'];
        $php = self::outcome(static fn () => ApiV2Presenter::formatRssDateBatchPhp($bad));
        $this->assertStringStartsWith('!', $php);
        $this->assertSame($php, self::outcome(static fn () => ApiV2Presenter::formatRssDateBatchNative($bad)));
    }

    public function test_non_utc_time_zone_uses_php(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $timestamps = [0, 1761393600, 1774746000];
        $this->assertSame(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchPhp($timestamps), ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchNative($timestamps));
        $this->assertStringEndsWith('+0100', ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchNative($timestamps)[0]);
        $dates = ['2026-10-25 12:34:56', '2026-07-01'];
        $this->assertSame(ApiV2Presenter::formatRssDateBatchPhp($dates), ApiV2Presenter::formatRssDateBatchNative($dates));
        $this->assertStringEndsWith('+0200', ApiV2Presenter::formatRssDateBatchNative($dates)[1]);
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame([0, 1, 5, 5, 1], ApiV1Presenter::evaluateW465Counters(1, ApiV1Presenter::w465Joined(['a"']), 5));
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 +0000', ApiV1Presenter::evaluateW465Native(2, pack('q', 0), 31));
        $this->assertSame(str_repeat("\0", 31), ApiV1Presenter::evaluateW465Native(2, pack('q', PHP_INT_MAX), 31));
        $this->assertSame([1, 0, 1, -7, -7, 1], ApiV1Presenter::evaluateW465Counters(3, ApiV1Presenter::w465Joined(['-7']), 6));
        $this->assertSame([5, 0, 0, 5, 2, 1, 1], ApiV1Presenter::evaluateW465Counters(4, pack('S', 1023), 7));
        $this->assertSame('Sun, 25 Oct 2026 00:00:00 +0000', ApiV1Presenter::evaluateW465Native(5, ApiV1Presenter::w465Joined(['2026-10-25']), 31));
        $this->assertSame('', ApiV1Presenter::evaluateW465Native(2, '', 0));
        $this->assertSame([0, 0, 0, 0, 0], ApiV1Presenter::evaluateW465Counters(1, ApiV1Presenter::w465Joined([]), 5));

        $this->assertNull(ApiV1Presenter::evaluateW465Native(0, '', 0));
        $this->assertNull(ApiV1Presenter::evaluateW465Native(6, '', 0));
        $this->assertNull(ApiV1Presenter::evaluateW465Native(2, pack('q', 0), 30));
        $this->assertNull(ApiV1Presenter::evaluateW465Native(2, "\0", 0));
        $this->assertNull(ApiV1Presenter::evaluateW465Native(2, '', -1));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(1, ApiV1Presenter::w465Joined(['a']), 4));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(1, ApiV1Presenter::w465Joined(['a']), 0));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(1, ApiV1Presenter::w465Joined(["a\0b"]), 5));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(3, ApiV1Presenter::w465Joined(['1e3']), 6));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(4, pack('S', 1024), 7));
        $this->assertNull(ApiV1Presenter::evaluateW465Counters(4, "\0", 7));

        // The output buffer grows for large date batches.
        $timestamps = range(0, 86400 * 5000, 86400);
        $this->assertSame(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchPhp($timestamps), ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchNative($timestamps));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(ApiV1Presenter::class))->setStaticPropertyValue('w465Ffi', false);
        $this->assertNull(ApiV1Presenter::evaluateW465Native(2, '', 0));

        $this->assertSame(ApiV1Presenter::summarizeJsonStringBatchPhp(['a', 1]), ApiV1Presenter::summarizeJsonStringBatchNative(['a', 1]));
        $this->assertSame(ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchPhp([0, 86400]), ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatchNative([0, 86400]));
        $this->assertSame(ReleaseRows::summarizeTotalRowsBatchPhp(['3', null]), ReleaseRows::summarizeTotalRowsBatchNative(['3', null]));
        $sets = [['Data' => 1], 'x'];
        $this->assertSame(XmlResponseContext::summarizeLegacyOptionsBatchPhp($sets), XmlResponseContext::summarizeLegacyOptionsBatchNative($sets));
        $this->assertSame(ApiV2Presenter::formatRssDateBatchPhp(['2026-10-25']), ApiV2Presenter::formatRssDateBatchNative(['2026-10-25']));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('char[8]');
        $big = $ffi->new('char[64]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w465_api_v1_v2_presenter_payload_rows_xml_context($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = ApiV1Presenter::w465Joined(['a', 'b']);
        $this->assertSame(40, $call(1, $packet, $big, 64));
        $this->assertSame(-40, $call(1, $packet, $small, 8));
        $this->assertSame("\0", FFI::string($small, 1));
        $this->assertSame(-40, $call(1, $packet, null, 0));
        $this->assertSame(-1, $call(1, $packet, null, 8));
        $this->assertSame(-62, $call(2, pack('q*', 0, 1), $big, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 64));
        }
        $this->assertSame(-1, $call(1, '', $big, 64));
        $this->assertSame(-1, $call(1, "\x01\x00\x00", $big, 64));
        $this->assertSame(-1, $call(1, pack('L', 0).'x', $big, 64));
        $this->assertSame(-1, $call(1, pack('L', 3).'a', $big, 64));
        $this->assertSame(-1, $call(2, str_repeat("\0", 9), $big, 64));
        $this->assertSame(-1, $call(4, "\0", $big, 64));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4655);
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $odd = static fn (): mixed => $pick([7, -3, 0, 1.5, true, false, null, ['x'], new stdClass]);

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $values = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $values[] = mt_rand(0, 10) === 0 ? $odd() : self::randomBytes(16, mt_rand(0, 6) === 0);
            }
            $this->assertParity(ApiV1Presenter::class, static fn () => ApiV1Presenter::summarizeJsonStringBatch($values), $values);

            $timestamps = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $timestamps[] = match (mt_rand(0, 5)) {
                    0 => $pick([PHP_INT_MAX, PHP_INT_MIN, -30610224000, -30610224001, 253402300799, 253402300800, 0, -1, 951782400, 4107542399]),
                    1 => mt_rand(-40000000000, 260000000000),
                    2 => mt_rand(-PHP_INT_MAX, PHP_INT_MAX),
                    default => mt_rand(0, 2000000000),
                };
            }
            $this->assertParity(ReleaseItemPayloadBuilder::class, static fn () => ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatch($timestamps), $timestamps);

            $totals = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                if (mt_rand(0, 10) === 0) {
                    $totals[] = $odd();

                    continue;
                }
                $total = '';
                for ($j = mt_rand(0, 5); $j > 0; $j--) {
                    $total .= $pick(self::TOTAL_TOKENS);
                }
                $totals[] = mt_rand(0, 3) === 0 ? (string) mt_rand(-1000000, 100000000) : $total;
            }
            $this->assertParity(ReleaseRows::class, static fn () => ReleaseRows::summarizeTotalRowsBatch($totals), $totals);

            $sets = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                if (mt_rand(0, 10) === 0) {
                    $sets[] = $pick(['set', 1, null, new stdClass]);

                    continue;
                }
                $set = [];
                foreach (['Parameters', 'Data', 'Server', 'Offset', 'Type', 'Extra', 'parameters'] as $key) {
                    if (mt_rand(0, 2) !== 0) {
                        $set[$key] = mt_rand(0, 9) === 0 ? new stdClass : $pick(self::OPTION_VALUES);
                    }
                }
                $sets[] = $set;
            }
            $this->assertParity(XmlResponseContext::class, static fn () => XmlResponseContext::summarizeLegacyOptionsBatch($sets), $sets);

            $dates = [];
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                $dates[] = self::randomDate($pick);
            }
            $this->assertParity(ApiV2Presenter::class, static fn () => self::outcome(static fn () => ApiV2Presenter::formatRssDateBatch($dates)), $dates);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();
        mt_srand(4651);
        $strict = getenv('NNTMUX_W465_BENCH') === '1';
        $samples = $strict ? 1000 : 300;

        $commonTitles = [];
        $commonStamps = [];
        $commonTotals = [];
        $commonSets = [];
        $commonDates = [];
        for ($i = 0; $i < 50; $i++) {
            $commonTitles[] = $i % 5 === 0 ? 'TV > HD' : 'Show.Name.S01E'.sprintf('%02d', $i % 24).'.1080p.WEB.h264-GRP';
            $commonStamps[] = 1761393600 - $i * 3607;
            $commonTotals[] = (string) (12000 + $i % 3);
            $commonSets[] = ['Parameters' => ['t' => 'search', 'extended' => '1'], 'Data' => [['guid' => 'g'.$i]], 'Server' => ['server' => ['url' => 'https://example.test']], 'Offset' => $i * 100, 'Type' => 'search'];
            $commonDates[] = gmdate('Y-m-d H:i:s', 1761393600 - $i * 3607);
        }

        $bigTitles = [];
        $bigStamps = [];
        $bigTotals = [];
        $bigSets = [];
        $bigDates = [];
        for ($i = 0; $i < 3000; $i++) {
            $bigTitles[] = match ($i % 10) {
                0 => 'Film "Ünïcödé" / Director\'s Cut '.str_repeat('Long Title ', 1 + $i % 8),
                1 => "Line\u{2028}Break\tTab ".$i,
                2 => "Invalid \xff bytes ".$i,
                3 => $i,
                default => 'Some.Movie.'.(1990 + $i % 30).'.2160p.UHD.BluRay.x265-GRP'.str_repeat('.PROPER', $i % 3),
            };
            $bigStamps[] = $i % 100 === 0 ? -40000000000 - $i : mt_rand(0, 2000000000);
            $bigTotals[] = match ($i % 8) {
                0 => ' '.mt_rand(0, 999999).' ',
                1 => '-'.mt_rand(1, 99),
                2 => mt_rand(0, 9).'abc',
                3 => null,
                4 => '',
                default => (string) mt_rand(0, 99999999),
            };
            $bigSets[] = match ($i % 6) {
                0 => [],
                1 => ['Parameters' => 'bad', 'Data' => null, 'Type' => 'caps'],
                2 => 'invalid',
                3 => ['Server' => new stdClass, 'Offset' => null, 'Extra' => 1],
                default => ['Parameters' => ['t' => 'tv'], 'Data' => [], 'Server' => [], 'Offset' => $i, 'Type' => 'tv'],
            };
            $bigDates[] = match (true) {
                $i % 100 === 0 => gmdate('Y-m-d\TH:i:s', mt_rand(0, 2000000000)),
                $i % 5 === 0 => gmdate('Y-m-d', mt_rand(0, 2000000000)),
                default => gmdate('Y-m-d H:i:s', mt_rand(0, 2000000000)),
            };
        }

        $workloads = [
            ApiV1Presenter::class => [
                static fn () => ApiV1Presenter::summarizeJsonStringBatch($commonTitles),
                static fn () => ApiV1Presenter::summarizeJsonStringBatch($bigTitles),
            ],
            ReleaseItemPayloadBuilder::class => [
                static fn () => ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatch($commonStamps),
                static fn () => ReleaseItemPayloadBuilder::rfc2822FromUnixUtcBatch($bigStamps),
            ],
            ReleaseRows::class => [
                static fn () => ReleaseRows::summarizeTotalRowsBatch($commonTotals),
                static fn () => ReleaseRows::summarizeTotalRowsBatch($bigTotals),
            ],
            XmlResponseContext::class => [
                static fn () => XmlResponseContext::summarizeLegacyOptionsBatch($commonSets),
                static fn () => XmlResponseContext::summarizeLegacyOptionsBatch($bigSets),
            ],
            ApiV2Presenter::class => [
                static fn () => ApiV2Presenter::formatRssDateBatch($commonDates),
                static fn () => ApiV2Presenter::formatRssDateBatch($bigDates),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $class::useW465RustFfi(false);
            $expected = [$common(), $uncommon()];
            $class::useW465RustFfi(true);
            $this->assertSame($expected, [$common(), $uncommon()], "{$class} benchmark workloads must agree");

            $results = [];
            foreach (['common' => [$common, 20, $samples], 'uncommon' => [$uncommon, 1, $class === ApiV2Presenter::class ? intdiv($samples, 3) : $samples]] as $name => [$workload, $calls, $count]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW465RustFfi($rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $count; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW465RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::resetW465RustFfi();

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 46.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $this->assertSame(self::DEFAULTS[$class], $class::usesW465RustFfi());
            if (! self::DEFAULTS[$class]) {
                continue;
            }
            $ratios = [$results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']];
            if ($strict) {
                $this->assertGreaterThanOrEqual(1.5, min($ratios), "{$class} defaults to Rust without clearing every 1.5x mean/p99 gate");
            } else {
                $this->assertGreaterThanOrEqual(1.0, min($results['common']['mean'], $results['uncommon']['mean']), "{$class} defaults to Rust but Rust is slower than PHP");
            }
        }
        fwrite(STDERR, "\n");
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useW465RustFfi(false);
        $php = $plan();
        $class::useW465RustFfi(true);
        $rust = $plan();
        $class::resetW465RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW465RustFfi(false);
        $php = $plan();
        $class::useW465RustFfi(true);
        $rust = $plan();
        $class::resetW465RustFfi();
        $this->assertSame($php, $rust, bin2hex(serialize(self::describe($args))));
    }

    /**
     * The result, or `!Class: message` when $plan throws.
     */
    private static function outcome(callable $plan): mixed
    {
        try {
            return $plan();
        } catch (Throwable $e) {
            return '!'.$e::class.': '.$e->getMessage();
        }
    }

    private static function describe(mixed $value): mixed
    {
        if (\is_object($value)) {
            return $value::class;
        }

        return \is_array($value) ? array_map(self::describe(...), $value) : $value;
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $byte = self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    private static function randomDate(callable $pick): string
    {
        $two = static fn (int $max): string => sprintf('%02d', mt_rand(0, $max));
        $date = $pick(['0999', '1000', '1969', '1970', '2000', '2023', '2024', '2100', '9999', (string) mt_rand(1000, 9999)]).'-'.$two(13).'-'.$two(32);
        if (mt_rand(0, 3) !== 0) {
            $date .= $pick([' ', ' ', ' ', 'T']).$two(24).':'.$two(60).':'.$two(60);
        }

        return match (mt_rand(0, 15)) {
            0 => ' '.$date,
            1 => $date.$pick(['Z', '.5', ' UTC', '+02:00']),
            2 => $pick(['@0', '@86400', 'garbage', '2026-10-25T12:00:00Z', '2026-10-25']),
            default => $date,
        };
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w465', ApiV1Presenter::W465_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
