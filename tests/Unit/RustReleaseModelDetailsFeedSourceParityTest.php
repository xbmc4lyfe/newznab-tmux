<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\BasePageController;
use App\Http\Controllers\DetailsController;
use App\Models\Release;
use App\Services\Predb\Feeds\Sources\BoundedHttpFeedSource;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustReleaseModelDetailsFeedSourceParityTest extends TestCase
{
    /** @var array<class-string, array{cdef: string, ffi: string}> */
    private const CLASSES = [
        Release::class => ['cdef' => 'RELEASE_GUID_FFI_CDEF', 'ffi' => 'releaseFfi'],
        DetailsController::class => ['cdef' => 'DETAILS_PLAN_FFI_CDEF', 'ffi' => 'detailsFfi'],
        BoundedHttpFeedSource::class => ['cdef' => 'BOUNDED_FEED_FFI_CDEF', 'ffi' => 'boundedFeedFfi'],
        SrrdbSource::class => ['cdef' => 'SRRDB_PAGE_FFI_CDEF', 'ffi' => 'srrdbFfi'],
        XrelSource::class => ['cdef' => 'XREL_PAGE_FFI_CDEF', 'ffi' => 'xrelFfi'],
    ];

    private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private const SHA = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    /** Strings that exercise trimming, casing, UTF-8, separators and NUL bytes. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\t\r\n",
        "\x0C",
        'gzip',
        ' GZip ',
        'IDENTITY',
        'identity, gzip',
        'br',
        'deflate',
        'https://api.srrdb.com/v1/search/order:date-desc',
        'https://api.srrdb.com/v1/search/order:date-desc///',
        '///',
        'tt0111161',
        '0111161',
        'tt0000000',
        '-5',
        'Some.Release.Name-GRP',
        'Ünïcödé  Ñame',
        'ポケモン',
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "\x1F",
        "\x1E",
        "nul\0byte",
    ];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 5, 6, 44, 45, 46, 99, 100, 101, 8191, 8192, 8193, 1048576, 204963823041217240, 204963823041217241, 204963823041217242, PHP_INT_MAX - 1, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (array_keys(self::CLASSES) as $class) {
            $this->assertFalse((new ReflectionClassConstant($class, 'RUST_FFI_DEFAULT'))->getValue(), $class);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi();
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertNull((new ReflectionProperty($class, self::CLASSES[$class]['ffi']))->getValue(), $class);
        }
    }

    public function test_toggles_are_independent_of_parent_and_sibling_classes(): void
    {
        $baseDefault = BasePageController::usesRustFfi();
        DetailsController::useRustFfi(true);
        $this->assertSame($baseDefault, BasePageController::usesRustFfi());
        BasePageController::setUseRustFfi(! $baseDefault);
        try {
            DetailsController::resetRustFfi();
            $this->assertFalse(DetailsController::usesRustFfi());
        } finally {
            BasePageController::setUseRustFfi(null);
        }

        // Bounded subclasses share the parent's toggle; srrDB and xREL keep their own.
        BoundedHttpFeedSource::useRustFfi(true);
        $this->assertTrue(Nima4kSource::usesRustFfi());
        $this->assertFalse(SrrdbSource::usesRustFfi());
        $this->assertFalse(XrelSource::usesRustFfi());
        SrrdbSource::useRustFfi(true);
        $this->assertFalse(XrelSource::usesRustFfi());
        $this->assertFalse(Release::usesRustFfi());
        Nima4kSource::resetRustFfi();
        $this->assertFalse(BoundedHttpFeedSource::usesRustFfi());
        $this->assertTrue(SrrdbSource::usesRustFfi());
    }

    public function test_php_reference_semantics(): void
    {
        $upper = strtoupper(self::UUID);
        $this->assertSame(
            ['guids' => [self::UUID, self::SHA], 'valid' => 2, 'invalid' => 3, 'duplicates' => 2],
            $this->php(Release::class, 'normalizeGuidBatch', [" {$upper}", self::UUID, "\t".self::UUID."\n", 'not-a-guid', '', self::SHA."\x0B", self::UUID."\x0C"]),
        );
        $this->assertSame(['guids' => [], 'valid' => 0, 'invalid' => 0, 'duplicates' => 0], $this->php(Release::class, 'normalizeGuidBatch', []));

        $this->assertSame(
            ['lookups' => ['video', 'movie', 'music', 'anidb'], 'lookup_count' => 4, 'meta_description' => 'View NZB for Some.Release'],
            $this->php(DetailsController::class, 'planDetailsLookups', 1, 'tt0111161', 0, 3, -1, 0, 9, 'Some.Release'),
        );
        $this->assertSame(
            ['lookups' => [], 'lookup_count' => 0, 'meta_description' => 'View NZB for '],
            $this->php(DetailsController::class, 'planDetailsLookups', 0, 'tt0000000', 0, 0, 0, 0, 0, ''),
        );
        foreach ([null, '', 0, '0', 'tt0000000', 'abc', -5, 'tt1', '00001', PHP_INT_MIN] as $imdb) {
            $plan = $this->php(DetailsController::class, 'planDetailsLookups', 0, $imdb, 0, 0, 0, 0, 0, 'x');
            $this->assertSame(imdb_id_is_valid($imdb), $plan['lookups'] === ['movie'], var_export($imdb, true));
        }

        $this->assertSame(
            ['encoding' => 'gzip', 'supported' => true, 'gzip' => true, 'limits_valid' => true, 'next_read' => 8192, 'remaining_budget' => 1048576, 'over_limit' => false],
            $this->php(BoundedHttpFeedSource::class, 'planBoundedBodyRead', " GZip \n", 1048576, 0),
        );
        $this->assertSame(
            ['encoding' => 'br', 'supported' => false, 'gzip' => false, 'limits_valid' => true, 'next_read' => 4, 'remaining_budget' => 3, 'over_limit' => false],
            $this->php(BoundedHttpFeedSource::class, 'planBoundedBodyRead', 'br', 10, 7),
        );
        $this->assertSame(
            ['encoding' => '', 'supported' => true, 'gzip' => false, 'limits_valid' => true, 'next_read' => 0, 'remaining_budget' => 0, 'over_limit' => true],
            $this->php(BoundedHttpFeedSource::class, 'planBoundedBodyRead', '', 10, 11),
        );
        $this->assertFalse($this->php(BoundedHttpFeedSource::class, 'planBoundedBodyRead', 'gzip', PHP_INT_MAX, 0)['limits_valid']);

        $this->assertSame(intdiv(PHP_INT_MAX, SrrdbSource::PAGE_SIZE) + 1, (new ReflectionClassConstant(SrrdbSource::class, 'SRRDB_MAX_PLANNED_PAGE'))->getValue());
        $this->assertSame(
            ['url' => 'https://x/skip:90', 'page' => 3, 'skip' => 90, 'clamped' => false],
            $this->php(SrrdbSource::class, 'planSrrdbPage', 'https://x//', 3),
        );
        $this->assertSame(['url' => 'https://x', 'page' => 1, 'skip' => 0, 'clamped' => true], $this->php(SrrdbSource::class, 'planSrrdbPage', 'https://x', -4));
        foreach (['https://api.srrdb.com/v1/search/order:date-desc', 'https://api.srrdb.com/v1/search/order:date-desc/'] as $endpoint) {
            foreach ([-3, 0, 1, 2, 3, 1000] as $page) {
                $this->assertSame(SrrdbSource::pageUrl($endpoint, max(1, $page)), SrrdbSource::planSrrdbPage($endpoint, $page)['url']);
            }
        }

        $this->assertSame(
            ['per_page' => 100, 'page' => 3, 'past_end' => true, 'json_fields' => 2, 'key' => 'xrel', 'source' => 'xrel'],
            $this->php(XrelSource::class, 'planXrelPage', 100, 3, 2, false),
        );
        $this->assertSame(
            ['per_page' => 5, 'page' => 1, 'past_end' => false, 'json_fields' => 3, 'key' => 'xrel_p2p', 'source' => 'xrel-p2p'],
            $this->php(XrelSource::class, 'planXrelPage', 1, -7, 0, true),
        );
        $this->assertSame('xrel_p2p', (new XrelSource('https://x', p2p: true))->key());
        $this->assertSame('xrel', (new XrelSource('https://x'))->key());
    }

    public function test_edge_cases_match_php_through_native_and_dispatch(): void
    {
        $this->ffiOrSkip();

        $guids = [self::UUID, strtoupper(self::UUID), ' '.self::SHA.' ', self::SHA."\x0C", '0f8fad5bd-9cb-469f-a165-70867728950e', ...self::TEXTS];
        $this->assertGuidParity([]);
        $this->assertGuidParity(['']);
        $this->assertGuidParity($guids);
        foreach ($guids as $guid) {
            $this->assertGuidParity([$guid, $guid]);
        }

        foreach ([null, 0, -5, PHP_INT_MIN, str_repeat('9', 308), str_repeat('9', 309), ...self::TEXTS] as $imdb) {
            foreach (self::TEXTS as $name) {
                $this->assertDetailsParity([1, 0, 1, 0, 1, 0], $imdb, $name);
            }
        }
        foreach (self::INTS as $id) {
            $this->assertDetailsParity([$id, $id, $id, $id, $id, $id], 'tt1', 'n');
        }

        foreach (self::TEXTS as $encoding) {
            foreach (self::INTS as $maximum) {
                foreach ([PHP_INT_MIN, -1, 0, 1, 7, 8192, 1048576, PHP_INT_MAX] as $received) {
                    $this->assertBoundedParity($encoding, $maximum, $received);
                }
            }
        }

        foreach (self::TEXTS as $endpoint) {
            foreach (self::INTS as $page) {
                $this->assertSrrdbParity($endpoint, $page);
            }
        }

        foreach (self::INTS as $pageSize) {
            foreach (self::INTS as $page) {
                foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, PHP_INT_MAX] as $total) {
                    $this->assertXrelParity($pageSize, $page, $total, $page % 2 === 0);
                }
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3740));
        $alphabet = "aAbBcCdDeEfFzZ09-_./: \t\n\r\0\x0B\x0C\x1F\x1E\xc3\xa9\xff";
        $string = static function (int $max = 40) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => self::INTS[$random->getInt(0, count(self::INTS) - 1)],
            1 => $random->getInt(-10, 200),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(0, 2_000_000),
        };
        $guid = static function () use ($random, $string): string {
            $hex = bin2hex($random->getBytes(20));
            $value = match ($random->getInt(0, 5)) {
                0 => substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12),
                1 => $hex,
                2 => self::UUID,
                3 => self::SHA,
                4 => substr($hex, 0, $random->getInt(0, 40)),
                default => $string(42),
            };
            if ($random->getInt(0, 2) === 0) {
                $value = strtoupper($value);
            }
            if ($random->getInt(0, 3) === 0) {
                $value = [' ', "\t", "\n", "\0", "\x0B", "\x0C"][$random->getInt(0, 5)].$value.[' ', "\r", ''][$random->getInt(0, 2)];
            }

            return $value;
        };

        for ($i = 0; $i < 1200; $i++) {
            $batch = [];
            for ($j = 0, $n = $random->getInt(0, 12); $j < $n; $j++) {
                $batch[] = $guid();
            }
            $this->assertGuidParity($batch);

            $imdb = match ($random->getInt(0, 3)) {
                0 => null,
                1 => $int(),
                2 => 'tt'.str_pad((string) $random->getInt(0, 9999999), 7, '0', STR_PAD_LEFT),
                default => $string(12),
            };
            $this->assertDetailsParity([$int(), $int(), $int(), $int(), $int(), $int()], $imdb, $string(80));

            $encoding = $random->getInt(0, 1) === 0 ? $string(16) : [' gzip', 'GZIP ', 'identity', '', 'br', "\tIdentity\n"][$random->getInt(0, 5)];
            $this->assertBoundedParity($encoding, $int(), $int());

            $endpoint = $random->getInt(0, 1) === 0 ? $string(60) : 'https://api.srrdb.com/v1/search/'.str_repeat('/', $random->getInt(0, 3));
            $this->assertSrrdbParity($endpoint, $int());

            $this->assertXrelParity($int(), $int(), $int(), $random->getInt(0, 1) === 1);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[512]');
        $small = $ffi->new('char[4]');
        $guids = self::UUID."\x1F".self::SHA;

        // Exact wire encodings.
        $written = $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', 2, $guids, strlen($guids), $out, 512);
        $this->assertSame("2\x1F0\x1F0\x1F".self::UUID."\x1F".self::SHA, FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w374_details_lookup_plan', 1, 0, 3, -1, 0, 9, 1, 'tt0111161', 9, "a\x1Fb", 3, $out, 512);
        $this->assertSame("4\x1F75\x1FView NZB for a\x1Fb", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w374_bounded_body_read_plan', 10, 7, ' GZip ', 6, $out, 512);
        $this->assertSame("1\x1F1\x1F1\x1F4\x1F3\x1F0\x1Fgzip", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w374_srrdb_page_plan', 3, 'e//', 3, $out, 512);
        $this->assertSame("3\x1F90\x1F0\x1Fe/skip:90", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w374_xrel_page_plan', 1, -7, 0, 1, $out, 512);
        $this->assertSame("5\x1F1\x1F0\x1F3\x1Fxrel_p2p\x1Fxrel-p2p", FFI::string($out, $written));

        // A tiny out_cap reports -required without writing, and the exact size then succeeds.
        foreach ([
            ['nntmux_w374_release_guid_batch', [2, $guids, strlen($guids)]],
            ['nntmux_w374_details_lookup_plan', [1, 0, 0, 0, 0, 0, 0, '', 0, 'name', 4]],
            ['nntmux_w374_bounded_body_read_plan', [10, 0, 'gzip', 4]],
            ['nntmux_w374_srrdb_page_plan', [2, 'endpoint', 8]],
            ['nntmux_w374_xrel_page_plan', [100, 1, 0, 0]],
        ] as [$symbol, $args]) {
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 4]);
            $this->assertLessThan(-1, $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $exact = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $exact, -$needed]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 512]), $symbol.' null out_buf');
        }

        // Invalid counts, flags and presence-mismatched input decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', 3, $guids, strlen($guids), $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', 1, $guids, strlen($guids), $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', 0, 'x', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', (1 << 20) + 1, '', 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_details_lookup_plan', 0, 0, 0, 0, 0, 0, 2, '', 0, '', 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_details_lookup_plan', 0, 0, 0, 0, 0, 0, 0, 'tt1', 3, '', 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_xrel_page_plan', 100, 1, 0, 2, $out, 512));

        // Null input pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_release_guid_batch', 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_details_lookup_plan', 0, 0, 0, 0, 0, 0, 1, null, 0, '', 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_details_lookup_plan', 0, 0, 0, 0, 0, 0, 0, '', 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_bounded_body_read_plan', 10, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w374_srrdb_page_plan', 1, null, 0, $out, 512));

        // Inputs the export declines (separator inside a GUID) still produce the PHP result.
        Release::useRustFfi(true);
        $batch = ["a\x1Fb", self::UUID];
        $this->assertSame($this->php(Release::class, 'normalizeGuidBatch', $batch), Release::normalizeGuidBatch($batch));

        foreach (array_keys(self::CLASSES) as $class) {
            $class::useRustFfi(true);
        }
        Release::normalizeGuidBatch([self::UUID]);
        DetailsController::planDetailsLookups(1, null, 0, 0, 0, 0, 0, 'x');
        BoundedHttpFeedSource::planBoundedBodyRead('gzip', 10, 0);
        SrrdbSource::planSrrdbPage('e', 2);
        XrelSource::planXrelPage(10, 1, 0, false);
        foreach (self::CLASSES as $class => $names) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, $names['ffi']))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::CLASSES as $class => $names) {
            (new ReflectionProperty($class, $names['ffi']))->setValue(null, false);
            $class::useRustFfi(true);
        }

        $batch = [self::UUID, strtoupper(self::UUID), 'x'];
        $this->assertSame($this->php(Release::class, 'normalizeGuidBatch', $batch), Release::normalizeGuidBatch($batch));
        $this->assertSame(
            $this->php(DetailsController::class, 'planDetailsLookups', 1, 'tt1', 0, 0, 0, 0, 2, 'n'),
            DetailsController::planDetailsLookups(1, 'tt1', 0, 0, 0, 0, 2, 'n'),
        );
        $this->assertSame($this->php(BoundedHttpFeedSource::class, 'planBoundedBodyRead', ' GZIP', 5, 9), BoundedHttpFeedSource::planBoundedBodyRead(' GZIP', 5, 9));
        $this->assertSame($this->php(SrrdbSource::class, 'planSrrdbPage', 'e/', 4), SrrdbSource::planSrrdbPage('e/', 4));
        $this->assertSame($this->php(XrelSource::class, 'planXrelPage', 500, 4, 3, true), XrelSource::planXrelPage(500, 4, 3, true));

        $cdef = (new ReflectionClassConstant(Release::class, 'RELEASE_GUID_FFI_CDEF'))->getValue();
        $this->assertIsString($cdef);
        $this->assertNull(RustPipelineLibrary::resolveDomain('metadata_w374', $cdef, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib'));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = short GUID batches, typical details rows, gzip/identity reads and first srrDB/xREL pages;
        // uncommon = 400-GUID batches, long search names, padded encodings and out-of-range pages.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, rustc 1.99.1, Apple M5, 3 rounds):
        // - Round 1: common mean 0.33x / p99 0.39x, uncommon mean 0.73x / p99 0.70x
        // - Round 2: common mean 0.32x / p99 0.39x, uncommon mean 0.72x / p99 0.74x
        // - Round 3: common mean 0.33x / p99 0.36x, uncommon mean 0.73x / p99 0.72x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustReleaseModelDetailsFeedSourceParityTest.php --filter benchmark
        // PHP's sub-microsecond work (and preg_match per GUID) beats the FFI round trip plus wire packing on
        // both workloads, so every class stays below the 1.5x bar in .ai/rules/rust-performance.md and
        // keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3741));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (array_keys(self::CLASSES) as $class) {
                $class::useRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustReleaseModelDetailsFeedSource speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (array_keys(self::CLASSES) as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * @param  list<string>  $guids
     */
    private function assertGuidParity(array $guids): void
    {
        $this->assertParity(Release::class, 'normalizeGuidBatch', [$guids]);
    }

    /**
     * @param  array{int, int, int, int, int, int}  $ids
     */
    private function assertDetailsParity(array $ids, int|string|null $imdb, string $name): void
    {
        [$videos, $games, $music, $book, $console, $anidb] = $ids;
        $this->assertParity(DetailsController::class, 'planDetailsLookups', [$videos, $imdb, $games, $music, $book, $console, $anidb, $name]);
    }

    private function assertBoundedParity(string $encoding, int $maximum, int $received): void
    {
        $this->assertParity(BoundedHttpFeedSource::class, 'planBoundedBodyRead', [$encoding, $maximum, $received]);
    }

    private function assertSrrdbParity(string $endpoint, int $page): void
    {
        $this->assertParity(SrrdbSource::class, 'planSrrdbPage', [$endpoint, $page]);
    }

    private function assertXrelParity(int $pageSize, int $page, int $total, bool $p2p): void
    {
        $this->assertParity(XrelSource::class, 'planXrelPage', [$pageSize, $page, $total, $p2p]);
    }

    /**
     * Compares the PHP reference with the native variant and both dispatcher modes.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, string $method, array $args): void
    {
        $context = $class.'::'.$method.'('.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR).')';
        $expected = $this->php($class, $method, ...$args);
        $this->assertSame($expected, (new ReflectionMethod($class, $method.'Native'))->invoke(null, ...$args), $context.' native');
        $class::useRustFfi(false);
        $this->assertSame($expected, $class::$method(...$args), $context.' php dispatch');
        $class::useRustFfi(true);
        $this->assertSame($expected, $class::$method(...$args), $context.' rust dispatch');
        $class::useRustFfi(null);
    }

    /**
     * @param  class-string  $class
     */
    private function php(string $class, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, $method.'Php'))->invoke(null, ...$args);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['guid', [[self::UUID, '7c9e6679-7425-40de-944b-e07fc1f90ae7', self::UUID]]],
            ['guid', [[self::SHA]]],
            ['details', [1234, 'tt0111161', 0, 0, 0, 0, 0, 'The.Shawshank.Redemption.1994.1080p.BluRay.x264-GRP']],
            ['details', [0, null, 0, 5521, 0, 0, 0, 'Artist-Album-WEB-2024-GRP']],
            ['bounded', ['gzip', 1048576, 0]],
            ['bounded', ['', 1048576, 16384]],
            ['srrdb', ['https://api.srrdb.com/v1/search/order:date-desc', 1]],
            ['srrdb', ['https://api.srrdb.com/v1/search/order:date-desc', 3]],
            ['xrel', [100, 1, 50, false]],
            ['xrel', [100, 2, 50, true]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $batch = [];
        for ($i = 0; $i < 400; $i++) {
            $hex = hash('sha1', (string) $i);
            $batch[] = match ($i % 4) {
                0 => ' '.strtoupper(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12)),
                1 => $hex,
                2 => 'not-a-guid-'.$i,
                default => self::UUID,
            };
        }

        return [
            ['guid', [$batch]],
            ['guid', [["sep\x1Finside", self::UUID]]],
            ['details', [PHP_INT_MAX, PHP_INT_MIN, -1, PHP_INT_MAX, 1, 1, 1, str_repeat('Ünïcödé.Release.Name-', 40)]],
            ['details', [0, 'tt0000000', 0, 0, 0, 0, 0, "sep\x1Fname"]],
            ['bounded', [str_repeat(' ', 64).'IDENTITY'.str_repeat("\t", 64), PHP_INT_MAX - 1, PHP_INT_MIN]],
            ['bounded', ['x-unknown-encoding', PHP_INT_MAX, 5]],
            ['srrdb', [str_repeat('https://mirror.example/', 8).str_repeat('/', 32), PHP_INT_MAX]],
            ['srrdb', ['', PHP_INT_MIN]],
            ['xrel', [PHP_INT_MAX, PHP_INT_MAX, -1, true]],
            ['xrel', [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, false]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (array_keys(self::CLASSES) as $class) {
            $class::useRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'guid' => Release::normalizeGuidBatch(...$args),
                'details' => DetailsController::planDetailsLookups(...$args),
                'bounded' => BoundedHttpFeedSource::planBoundedBodyRead(...$args),
                'srrdb' => SrrdbSource::planSrrdbPage(...$args),
                default => XrelSource::planXrelPage(...$args),
            };
            $count += count($result);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdefs = [];
        foreach (self::CLASSES as $class => $names) {
            $cdef = (new ReflectionClassConstant($class, $names['cdef']))->getValue();
            $this->assertIsString($cdef);
            $cdefs[] = $cdef;
        }
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w374', implode("\n", $cdefs));
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the release/details/feed source helper exports is not available.');
        }

        return $ffi;
    }
}
