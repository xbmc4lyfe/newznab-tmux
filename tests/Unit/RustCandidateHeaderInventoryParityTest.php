<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\NativeSpotCheckPlanner;
use App\Services\Releases\Verification\SpotCheckPlanner;
use App\Support\RustPipelineLibrary;
use FFI;
use Tests\TestCase;
use Throwable;

/**
 * Paired caller-level benchmark summary (macOS arm64 Apple M5, PHP 8.5.12 CLI without opcache,
 * release LTO dylib from base ea3de407c; 1000 randomized-order pairs per workload after 20 warmups,
 * each sample averaging min(200, 20000 / articles) calls; manifests carry 45-byte message IDs and
 * per-file subjects; timings include serialize()/implode() marshaling and the FFI boundary):
 *
 * - `CandidateHeaderInventory::assessment` (adopted from 32 articles; PHP walks the types and stops
 *   where PHP stops, Rust validates/dedups message IDs fed in 16..1024-ID chunks):
 *   common 8x30: 45.3 us -> 21.0 us mean (2.16x), 56.5 -> 29.1 us p99 (1.94x);
 *   common 20x50: 232.9 -> 106.1 us mean (2.19x), 449.8 -> 220.6 us p99 (2.04x);
 *   common 50x100: 992.7 -> 435.6 us mean (2.28x), 1305.2 -> 652.1 us p99 (2.00x);
 *   uncommon waiting 20x50: 2.24x mean, 1.85x p99; rejection at 40 spread positions: 1.75x mean, 1.63x p99;
 *   uncommon 150x400 (60k articles): 12.30 -> 5.05 ms mean (2.44x), 14.50 -> 6.34 ms p99 (2.29x);
 *   threshold 32/64/128 articles: 1.88x/2.02x/2.10x mean, 1.65x/2.00x/1.88x p99.
 *   Below 32 articles Rust lost (8: 1.28x, 16: 1.40x mean even for the packed path), so PHP runs
 *   there (dispatch adds ~50 ns, 1x3: 727 -> 779 ns). Edge: a rejection inside the first ID chunk
 *   (duplicate at segment 2 of 1000) costs 0.7 -> 2.7 us (0.25x) because PHP exits immediately.
 * - `assessmentNative` over `serialize($files)` (exported, not adopted: slower than the above):
 *   1.58x-1.71x mean on common/threshold workloads, but 1.33x mean / 1.19x p99 at 60k articles.
 * - `CandidateHeaderInventory::completeness` (retained native PHP; `completenessNative` exposed):
 *   PHP counts per file, so serializing every segment loses: 0.01x mean (20x50), 0.00x (150x400).
 * - `CandidateHeaderInventory::bytes` (retained native PHP; `bytesNative` exposed):
 *   0.17x mean / 0.21x p99 (20x50), 0.22x mean / 0.36x p99 (150x400).
 * - `SpotCheckPlanner::select` (adopted for every size after the Linux NativeSpotCheckPlanner path):
 *   common 8x30 k=1: 199.0 -> 40.0 us mean (4.98x), p99 4.73x; 20x50 k=1: 5.45x mean, 5.66x p99;
 *   50x100 k=1: 5.49 -> 0.91 ms mean (6.03x), p99 5.39x; uncommon 20x50 k=16 inferred: 5.52x mean,
 *   5.23x p99; 150x400 k=16: 74.8 -> 11.5 ms mean (6.49x), p99 6.59x; 1x3 k=1: 2.03x mean, 1.93x p99.
 *
 * Machine load averaged 4-6 during runs; repeated 60k-article assessment runs ranged 1.95x-2.44x p99.
 */
class RustCandidateHeaderInventoryParityTest extends TestCase
{
    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_candidate_header_assess_packed(const char *data, size_t length, int32_t conflict, int64_t expected_files, uint32_t mode, int64_t *out);
        int32_t nntmux_candidate_header_assess_json(const char *data, size_t length, uint32_t mode, int64_t *out);
        void nntmux_candidate_header_ids_begin(size_t capacity);
        int64_t nntmux_candidate_header_ids_feed(const char *data, size_t length, int64_t count);
        int64_t nntmux_candidate_header_message_ids(const char *data, size_t length, int64_t count);
        ssize_t nntmux_spot_check_plan_packed(const char *data, size_t length, const char *identity, size_t identity_length, int64_t maximum_articles, int64_t maximum_header_bytes, int64_t article_limit, char *out_buf, size_t out_cap);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();

        $ffi = RustPipelineLibrary::resolveDomain('candidate_header_inventory_test', self::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
        CandidateHeaderInventory::setUseRustFfi(true);
        SpotCheckPlanner::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        CandidateHeaderInventory::setUseRustFfi(true);
        SpotCheckPlanner::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_assessment_matches_php_for_contract_manifests(): void
    {
        $inventory = new CandidateHeaderInventory;
        $base = $this->manifest(3, 40);
        $cases = ['ready' => $base];
        $cases['waiting_files'] = array_replace($base, ['expected_files' => 5]);
        $cases['waiting_articles'] = $base;
        $cases['waiting_articles']['files'][1]['totalparts'] = 41;
        $cases['conflict'] = array_replace($base, ['conflict' => true]);
        $cases['no_files'] = array_replace($base, ['files' => []]);
        $cases['duplicate_key'] = $base;
        $cases['duplicate_key']['files'][2]['key'] = $base['files'][0]['key'];
        $cases['excess_parts'] = $base;
        $cases['excess_parts']['files'][1]['totalparts'] = 39;
        $cases['duplicate_number'] = $base;
        $cases['duplicate_number']['files'][1]['segments'][7]['number'] = 3;
        $cases['number_above_total'] = $base;
        $cases['number_above_total']['files'][1]['segments'][7]['number'] = 41;
        $cases['zero_bytes'] = $base;
        $cases['zero_bytes']['files'][2]['segments'][39]['bytes'] = 0;
        $cases['duplicate_id_across_files'] = $base;
        $cases['duplicate_id_across_files']['files'][2]['segments'][5]['messageid'] = $base['files'][0]['segments'][9]['messageid'];
        $cases['duplicate_after_trim'] = $base;
        $cases['duplicate_after_trim']['files'][2]['segments'][5]['messageid'] = trim($base['files'][0]['segments'][9]['messageid'], '<>');
        $cases['space_in_id'] = $base;
        $cases['space_in_id']['files'][1]['segments'][20]['messageid'] = '<a b@example>';
        $cases['nul_in_id'] = $base;
        $cases['nul_in_id']['files'][1]['segments'][20]['messageid'] = "<a\0b@example>";
        $cases['only_brackets'] = $base;
        $cases['only_brackets']['files'][0]['segments'][0]['messageid'] = '<<>>';
        $cases['id_defect_before_file_defect'] = $base;
        $cases['id_defect_before_file_defect']['files'][0]['segments'][3]['messageid'] = "bad\x7fid";
        $cases['id_defect_before_file_defect']['files'][2]['key'] = '';

        foreach ($cases as $name => $manifest) {
            CandidateHeaderInventory::setUseRustFfi(false);
            $expected = $inventory->assessment($manifest);
            CandidateHeaderInventory::setUseRustFfi(true);
            $this->assertSame($expected, $inventory->assessment($manifest), $name);
            $native = CandidateHeaderInventory::assessmentNative($manifest);
            $this->assertSame($expected, $native, $name);
            $json = CandidateHeaderInventory::assessmentJsonNative(json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->assertSame($expected, $json, $name);
        }
        $this->assertSame('Invalid or duplicate article reference in candidate headers.', $inventory->rejection($cases['id_defect_before_file_defect']));
    }

    public function test_assessment_matches_php_for_defects_around_id_chunk_boundaries(): void
    {
        $inventory = new CandidateHeaderInventory;
        $base = $this->manifest(6, 50);
        foreach ([0, 1, 14, 15, 16, 17, 47, 48, 49, 50, 111, 112, 113, 239, 240, 241, 299] as $position) {
            $file = intdiv($position, 50);
            $segment = $position % 50;
            $variants = [];
            $variants['duplicate'] = $base;
            $variants['duplicate']['files'][$file]['segments'][$segment]['messageid'] = $base['files'][0]['segments'][0]['messageid'];
            $variants['invalid'] = $base;
            $variants['invalid']['files'][$file]['segments'][$segment]['messageid'] = 'bad id';
            $variants['invalid_then_file_defect'] = $variants['invalid'];
            $variants['invalid_then_file_defect']['files'][5]['totalparts'] = 0;
            $variants['file_defect_after'] = $base;
            $variants['file_defect_after']['files'][min(5, $file + 1)]['key'] = '';
            foreach ($variants as $name => $manifest) {
                CandidateHeaderInventory::setUseRustFfi(false);
                $expected = $inventory->assessment($manifest);
                CandidateHeaderInventory::setUseRustFfi(true);
                $this->assertSame($expected, $inventory->assessment($manifest), "{$name} at {$position}");
                $this->assertSame($expected, CandidateHeaderInventory::assessmentNative($manifest), "{$name} at {$position}");
            }
        }
    }

    public function test_seeded_fuzz_matches_php_for_assessment_completeness_and_bytes(): void
    {
        $inventory = new CandidateHeaderInventory;
        $nativeResults = ['assessment' => 0, 'json' => 0, 'completeness' => 0, 'bytes' => 0];
        mt_srand(84_2026);
        for ($round = 0; $round < 1500; $round++) {
            $manifest = $this->randomManifest();

            CandidateHeaderInventory::setUseRustFfi(false);
            $expected = $inventory->assessment($manifest);
            CandidateHeaderInventory::setUseRustFfi(true);
            $this->assertSame($expected, $inventory->assessment($manifest), "production assessment round {$round}");

            $native = CandidateHeaderInventory::assessmentNative($manifest);
            if ($native !== null) {
                $nativeResults['assessment']++;
                $this->assertSame($expected, $native, "packed assessment round {$round}");
            }

            $json = json_encode($manifest);
            if ($json !== false) {
                $decoded = json_decode($json, true);
                $jsonNative = CandidateHeaderInventory::assessmentJsonNative($json);
                if ($jsonNative !== null) {
                    $nativeResults['json']++;
                    $this->assertSame($inventory->assessment($decoded), $jsonNative, "json assessment round {$round}");
                }
            }

            $completeness = $this->attempt(fn () => $inventory->completeness($manifest));
            $nativeCompleteness = CandidateHeaderInventory::completenessNative($manifest);
            if ($nativeCompleteness !== null) {
                $nativeResults['completeness']++;
                $this->assertSame($completeness, $nativeCompleteness, "completeness round {$round}");
            }

            $bytes = $this->attempt(fn () => $inventory->bytes($manifest));
            $nativeBytes = CandidateHeaderInventory::bytesNative($manifest);
            if ($nativeBytes !== null) {
                $nativeResults['bytes']++;
                $this->assertSame($bytes, $nativeBytes, "bytes round {$round}");
            }
        }
        foreach ($nativeResults as $kind => $count) {
            $this->assertGreaterThan(300, $count, "Rust {$kind} must decide most fuzz rounds");
        }
    }

    public function test_message_id_validation_matches_php_pattern_and_framing(): void
    {
        $this->assertTrue(CandidateHeaderInventory::messageIdsValidNative([]));
        $this->assertTrue(CandidateHeaderInventory::messageIdsValidNative(['<a@b>', 'c@d', '<<e@f>>', "\xff@utf"]));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(['<a@b>', 'a@b']));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(['a@b', '']));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(['<>']));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(['a<b@c']));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(["a\tb"]));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(["a\x7fb"]));
        $this->assertFalse(CandidateHeaderInventory::messageIdsValidNative(["a\0b", 'a']));
        $this->assertNull(CandidateHeaderInventory::messageIdsValidNative(["a\0b"]));

        $this->ffi->nntmux_candidate_header_ids_begin(2); // @phpstan-ignore method.notFound
        $this->assertSame(2, $this->ffi->nntmux_candidate_header_ids_feed("x@y\0<z@w>", 9, 2)); // @phpstan-ignore method.notFound
        $this->assertSame(0, $this->ffi->nntmux_candidate_header_ids_feed('z@w', 3, 1)); // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_candidate_header_ids_feed(null, 0, -1)); // @phpstan-ignore method.notFound
        $this->assertSame(0, $this->ffi->nntmux_candidate_header_message_ids(null, 0, 0)); // @phpstan-ignore method.notFound
        $this->ffi->nntmux_candidate_header_ids_begin(0); // @phpstan-ignore method.notFound
    }

    public function test_packed_abi_declines_invalid_arguments_and_unsupported_shapes(): void
    {
        $out = $this->ffi->new('int64_t[8]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_candidate_header_assess_packed(null, 0, 0, 0, 1, $out));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_candidate_header_assess_packed('a:0:{}', 6, 0, 0, 1, null));
        $object = serialize([new \stdClass]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_candidate_header_assess_packed($object, strlen($object), 0, 0, 7, $out));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_candidate_header_assess_json('{"files":[]', 11, 1, $out));

        $manifest = $this->manifest(1, 3);
        $manifest['files'][0]['segments'][1]['meta'] = new \stdClass;
        $this->assertNull(CandidateHeaderInventory::assessmentNative($manifest));
        $manifest['files'][0]['segments'][1]['meta'] = static fn (): int => 1;
        $this->assertNull(CandidateHeaderInventory::assessmentNative($manifest));
        $this->assertSame((new CandidateHeaderInventory)->assessment($this->manifest(1, 3)), CandidateHeaderInventory::assessmentNative($this->manifest(1, 3)));
    }

    public function test_spot_check_plan_matches_php_for_contract_and_seeded_fuzz_manifests(): void
    {
        $limits = [[1, 1_048_576, 1_048_576], [4, 1000, 1000], [16, 350, null], [16, 1000, 100], [17, 2_000_000, null],
            [3, 16_777_216, 1_048_576], [16, 200_000, null], [0, 1000, null], [8, 0, null], [8, 1000, 0], [64, PHP_INT_MAX, null]];
        $native = 0;
        mt_srand(8_4_8_4);
        $manifests = [$this->manifest(3, 40), $this->manifest(1, 1100)];
        for ($round = 0; $round < 700; $round++) {
            $manifests[] = $this->randomManifest();
        }
        foreach ($manifests as $index => $manifest) {
            foreach ($index < 2 ? $limits : [$limits[mt_rand(0, count($limits) - 1)]] as [$articles, $bytes, $articleBytes]) {
                $expected = SpotCheckPlanner::selectPhp($manifest, $articles, $bytes, $articleBytes);
                $packed = SpotCheckPlanner::selectPackedNative($manifest, $articles, $bytes, $articleBytes);
                if ($packed !== null) {
                    $native++;
                    $this->assertSame($expected, $packed, "manifest {$index} limits {$articles}/{$bytes}");
                }
                $planner = new SpotCheckPlanner;
                $this->assertSame($expected, $planner->select($manifest, $articles, $bytes, $articleBytes), "manifest {$index} production");
            }
        }
        $this->assertGreaterThan(300, $native);
    }

    public function test_spot_check_plan_keeps_php_semantics_for_unsupported_shapes(): void
    {
        $manifest = $this->manifest(2, 5);
        $manifest['identity'] = 7;
        $this->assertNull(SpotCheckPlanner::selectPackedNative($manifest, 4, 10_000_000));
        $this->assertSame(SpotCheckPlanner::selectPhp($manifest, 4, 10_000_000), (new SpotCheckPlanner)->select($manifest, 4, 10_000_000));

        $manifest = $this->manifest(2, 5);
        $manifest['files'][1]['segments'][2]['bytes'] = '700000';
        $this->assertNull(SpotCheckPlanner::selectPackedNative($manifest, 4, 10_000_000));
        $manifest['files'][1]['segments'][2]['bytes'] = 700_000;
        $manifest['files'][0]['totalparts'] = '5';
        $this->assertNull(SpotCheckPlanner::selectPackedNative($manifest, 4, 10_000_000));
        $manifest['files'][0]['totalparts'] = 5;
        $manifest['files'][0]['metadata'] = new \stdClass;
        $this->assertNull(SpotCheckPlanner::selectPackedNative($manifest, 4, 10_000_000));

        // Duplicate keys keep the last file under the first position; numeric keys stay strings.
        $manifest = $this->manifest(3, 6);
        $manifest['files'][2]['key'] = $manifest['files'][0]['key'];
        $manifest['files'][1]['key'] = '123';
        foreach ([1, 3, 16] as $articles) {
            $this->assertSame(SpotCheckPlanner::selectPhp($manifest, $articles, 10_000_000), SpotCheckPlanner::selectPackedNative($manifest, $articles, 10_000_000));
        }
        $this->assertNull(SpotCheckPlanner::selectPackedNative(['identity' => new \stdClass, 'files' => []], 1, 100));
        $this->assertSame([], (new SpotCheckPlanner)->select(['identity' => new \stdClass, 'files' => []], 1, 100));

        // PHP_INT_MAX byte addition overflow declines in selectPackedNative and falls back to PHP float coercion.
        $overflow = [
            'identity' => 'overflow',
            'files' => [[
                'key' => 'file',
                'totalparts' => 4,
                'totalparts_inferred' => true,
                'segments' => [
                    ['number' => 4, 'messageid' => 'first', 'bytes' => PHP_INT_MAX],
                    ['number' => 1, 'messageid' => 'second', 'bytes' => 2],
                ],
            ]],
        ];
        $this->assertNull(SpotCheckPlanner::selectPackedNative($overflow, 2, PHP_INT_MAX));
        $this->assertSame(
            SpotCheckPlanner::selectPhp($overflow, 2, PHP_INT_MAX),
            (new SpotCheckPlanner)->select($overflow, 2, PHP_INT_MAX),
        );
    }

    public function test_spot_check_abi_reports_required_capacity_and_declines_invalid_input(): void
    {
        $files = serialize($this->manifest(4, 10)['files']);
        $buffer = $this->ffi->new('char[64]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-256, $this->ffi->nntmux_spot_check_plan_packed($files, strlen($files), 'id', 2, 4, PHP_INT_MAX, PHP_INT_MAX, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(64, $this->ffi->nntmux_spot_check_plan_packed($files, strlen($files), 'id', 2, 1, PHP_INT_MAX, PHP_INT_MAX, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $this->ffi->nntmux_spot_check_plan_packed($files, strlen($files), 'id', 2, 0, 100, 100, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_spot_check_plan_packed(null, 0, 'id', 2, 1, 100, 100, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $this->ffi->nntmux_spot_check_plan_packed('a:1:{', 5, 'id', 2, 1, 100, 100, $buffer, 64));

        $many = $this->manifest(40, 30);
        $this->assertSame(SpotCheckPlanner::selectPhp($many, 5000, PHP_INT_MAX), SpotCheckPlanner::selectPackedNative($many, 5000, PHP_INT_MAX));
    }

    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_CANDIDATE_INVENTORY_BENCH') !== '1') {
            $this->expectNotToPerformAssertions();

            return;
        }

        $inventory = new CandidateHeaderInventory;
        $manifest = $this->manifest(20, 50);
        $measure = static function (callable $call): float {
            $started = hrtime(true);
            for ($i = 0; $i < 50; $i++) {
                $call();
            }

            return (hrtime(true) - $started) / 50;
        };
        CandidateHeaderInventory::setUseRustFfi(false);
        $phpAssessment = $measure(fn () => $inventory->assessment($manifest));
        CandidateHeaderInventory::setUseRustFfi(true);
        $rustAssessment = $measure(fn () => $inventory->assessment($manifest));
        $phpPlan = $measure(fn () => SpotCheckPlanner::selectPhp($manifest, 1, 1_048_576, 1_048_576));
        $rustPlan = $measure(fn () => SpotCheckPlanner::selectPackedNative($manifest, 1, 1_048_576, 1_048_576));

        $this->assertLessThan($phpAssessment, $rustAssessment);
        $this->assertLessThan($phpPlan, $rustPlan);
    }

    /** @return mixed the result, or the Throwable PHP raised */
    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $error) {
            return $error;
        }
    }

    /** @return array<string, mixed> */
    private function manifest(int $files, int $segments): array
    {
        $inventory = [];
        for ($file = 0; $file < $files; $file++) {
            $parts = [];
            for ($number = 1; $number <= $segments; $number++) {
                $parts[] = ['number' => $number, 'messageid' => sprintf('<part%dof%d.%s@news.example>', $number, $file, substr(md5("{$file}.{$number}"), 0, 10)),
                    'bytes' => 700_000 + ($number * 37 % 1000)];
            }
            $inventory[] = ['key' => "release.part{$file}.rar", 'subject' => "release [{$file}] (1/{$segments})",
                'totalparts' => $segments, 'totalparts_inferred' => $file % 2 === 1, 'segments' => $parts];
        }

        return ['identity' => sha1("identity-{$files}-{$segments}"), 'expected_files' => $files, 'conflict' => false, 'files' => $inventory];
    }

    /** @return array<string, mixed> */
    private function randomManifest(): array
    {
        $pick = static function (array $weighted): mixed {
            $roll = mt_rand(1, array_sum(array_column($weighted, 0)));
            foreach ($weighted as [$weight, $value]) {
                $roll -= $weight;
                if ($roll <= 0) {
                    return $value instanceof \Closure ? $value() : $value;
                }
            }

            return null;
        };
        $seenIds = ['<seed@example>'];
        $files = [];
        $fileCount = $pick([[4, 0], [30, 1], [30, 3], [20, 6], [16, 12]]);
        $large = mt_rand(1, 5) === 1;
        for ($f = 0; $f < $fileCount; $f++) {
            if (mt_rand(1, 40) === 1) {
                $files[] = $pick([[1, 'file'], [1, 5], [1, null]]);

                continue;
            }
            $segmentCount = $large ? mt_rand(20, 60) : mt_rand(0, 8);
            $segments = [];
            for ($s = 1; $s <= $segmentCount; $s++) {
                if (mt_rand(1, 60) === 1) {
                    $segments[] = $pick([[1, 'segment'], [1, 7], [1, null]]);

                    continue;
                }
                $id = $pick([[150, fn () => sprintf('<%08x.%d@example>', mt_rand(), $s)], [4, fn () => $seenIds[array_rand($seenIds)]],
                    [3, fn () => trim($seenIds[array_rand($seenIds)], '<>')], [2, 'a b@example'], [2, ''], [2, '<>'], [2, "a\x01b@x"],
                    [1, "a\0b@x"], [2, '<<wrapped@example>>'], [1, "\xff\xfe@x"], [1, 'a<b@x'], [2, 5], [1, null]]);
                if (is_string($id)) {
                    $seenIds[] = $id;
                }
                $segment = [
                    'number' => $pick([[160, $s], [4, max(1, $s - 1)], [3, 0], [2, (string) $s], [2, (float) $s], [3, $s + 50], [2, null]]),
                    'messageid' => $id,
                    'bytes' => $pick([[160, fn () => mt_rand(1, 1_200_000)], [3, 0], [3, -5], [3, '100'], [2, 1.5], [2, null], [1, true], [1, []]]),
                ];
                if (mt_rand(1, 10) === 1) {
                    $segment['extra'] = ['nested' => [1, 'two' => [3.5, null]]];
                }
                if (mt_rand(1, 40) === 1) {
                    unset($segment[array_rand($segment)]);
                }
                $segments[] = $segment;
            }
            $file = [
                'key' => $pick([[160, "file-{$f}.bin"], [5, 'file-0.bin'], [3, ''], [2, 5], [2, null], [3, '123']]),
                'subject' => 'subject '.$f,
                'totalparts' => $pick([[120, $segmentCount], [30, fn () => $segmentCount + mt_rand(1, 3)], [6, max(0, $segmentCount - 1)],
                    [4, null], [3, (string) $segmentCount], [3, (float) $segmentCount], [2, true], [3, 0], [2, []], [2, [1]]]),
                'totalparts_inferred' => $pick([[3, false], [2, true], [1, 'yes'], [1, null]]),
                'segments' => $pick([[180, $segments], [3, null], [3, 'segments'], [3, fn () => array_combine(array_map(static fn (int $i): string => "s{$i}", array_keys($segments)), $segments) ?: []], [3, []]]),
            ];
            if (mt_rand(1, 20) === 1) {
                unset($file[array_rand($file)]);
            }
            $files[] = $file;
        }
        $manifest = [
            'identity' => $pick([[16, fn () => sha1((string) mt_rand())], [1, ''], [1, null], [1, 7]]),
            'expected_files' => $pick([[14, count($files)], [2, count($files) + 2], [2, null], [1, (string) count($files)], [1, -3]]),
            'conflict' => $pick([[40, false], [1, true], [1, 'yes']]),
            'files' => $pick([[60, $files], [1, null], [1, 'files']]),
        ];
        if (mt_rand(1, 15) === 1) {
            unset($manifest[array_rand($manifest)]);
        }

        return $manifest;
    }
}
