<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Parity and FFI boundary tests for `CompactFingerprintCollector` Rust helpers
 * (`nntmux_compact_fp_*` in `rust/pipeline-ffi/src/metadata.rs`).
 *
 * Paired randomized benchmark results (`N=40` interleaved pairs, warmup outside timed region,
 * release dylib `opt-level=3, lto=true, codegen-units=1` on Apple Silicon macOS):
 *
 * | Helper / Workload | Baseline Mean / p99 (ms) | Rust FFI Mean / p99 (ms) | Mean / p99 Speedup | Production Path |
 * | --- | --- | --- | --- | --- |
 * | `analyzeNzbForCompact` vs Prev (`analyzeManifest`+PHP, common 42x10 segs, x20) | 6.190 / 7.522 | 2.257 / 3.066 | 2.74x / 2.45x | Adopted Rust FFI (`>= 1.5x`) |
 * | `analyzeNzbForCompact` vs Pure PHP (DOM+PHP, common 42x10 segs, x20) | 18.246 / 22.248 | 2.257 / 3.066 | 8.09x / 7.26x | Adopted Rust FFI (`>= 1.5x`) |
 * | `analyzeNzbForCompact` vs Prev (`analyzeManifest`+PHP, p99 252x20 segs, x5) | 15.580 / 25.748 | 5.375 / 9.828 | 2.90x / 2.62x | Adopted Rust FFI (`>= 1.5x`) |
 * | `analyzeNzbForCompact` vs Pure PHP (DOM+PHP, p99 252x20 segs, x5) | 46.398 / 86.610 | 5.375 / 9.828 | 8.63x / 8.81x | Adopted Rust FFI (`>= 1.5x`) |
 * | `analyzeNzbForCompact` vs Prev (`analyzeManifest`+PHP, heavy p99 252x50 segs, x3) | 20.732 / 26.876 | 6.376 / 7.293 | 3.25x / 3.69x | Adopted Rust FFI (`>= 1.5x`) |
 * | `analyzeNzbForCompact` vs Pure PHP (DOM+PHP, heavy p99 252x50 segs, x3) | 57.949 / 70.320 | 6.376 / 7.293 | 9.09x / 9.64x | Adopted Rust FFI (`>= 1.5x`) |
 * | `selectSamples` over pre-parsed PHP array (common 42 files) | 0.813 / 1.136 | 1.701 / 2.221 | 0.48x / 0.51x | Subsumed by `analyzeNzbForCompact` |
 * | `summarizeFiles` over pre-parsed PHP array (common 42 files) | 0.690 / 1.120 | 2.324 / 3.139 | 0.30x / 0.36x | Subsumed by `analyzeNzbForCompact` |
 * | `yencIntegrity` over pre-parsed PHP array (common 12 articles) | 0.650 / 1.321 | 0.493 / 1.200 | 1.32x / 1.10x | Retained PHP (`< 1.5x`) |
 * | `verifiedPartPrefixMd5` (common 20 KiB x12) | 13.959 / 15.408 | 13.955 / 15.999 | 1.00x / 0.96x | Retained PHP (`< 1.5x`) |
 * | `subjectFilename+bounded` (common 42 files, batch) | 0.244 / 0.362 | 0.152 / 0.255 | 1.61x / 1.42x | Subsumed by `analyzeNzbForCompact` |
 */
final class RustCompactFingerprintCollectorParityTest extends TestCase
{
    private const int ITERATIONS = 300;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_compact_fp_subject_filename_bounded(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_verified_part_prefix_md5(const char *decoded, size_t decoded_len, int64_t file_size, int64_t begin, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_select_samples(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_summarize_files(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_yenc_integrity(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_analyze_nzb_xml(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
        C;

    private ?FFI $ffi = null;

    protected function setUp(): void
    {
        parent::setUp();
        CompactFingerprintCollector::setUseRustFfi(true);
        $this->ffi = RustPipelineLibrary::resolveDomain('compact_fingerprint', self::FFI_CDEF);
        if ($this->ffi === null) {
            self::markTestSkipped('libnntmux_pipeline is not built.');
        }
    }

    protected function tearDown(): void
    {
        CompactFingerprintCollector::setUseRustFfi(true);
        NzbFingerprintAnalyzer::setUseRustFfi(true);
        parent::tearDown();
    }

    #[Test]
    public function analyze_nzb_for_compact_matches_php_across_randomized_nzb_xml_manifests(): void
    {
        mt_srand(5100);
        $nzbStub = (new ReflectionClass(NzbService::class))->newInstanceWithoutConstructor();
        $collector = new CompactFingerprintCollector($nzbStub, new NzbFingerprintAnalyzer(new YencService), new Par2MetadataParser);

        $invalidObserved = 0;
        $overflowObserved = 0;
        $nullPostingObserved = 0;
        $cappedObserved = 0;

        for ($i = 0; $i < 120; $i++) {
            $fileCount = $i % 20 === 0 ? mt_rand(205, 225) : mt_rand(1, 25);
            $xml = self::randomNzbXml($fileCount, $i);

            NzbFingerprintAnalyzer::setUseRustFfi(false);
            CompactFingerprintCollector::setUseRustFfi(false);
            $expectedPurePhp = $collector->analyzeNzbForCompact($xml);

            NzbFingerprintAnalyzer::setUseRustFfi(true);
            CompactFingerprintCollector::setUseRustFfi(false);
            $expectedHybrid = $collector->analyzeNzbForCompact($xml);

            CompactFingerprintCollector::setUseRustFfi(true);
            $actualNative = CompactFingerprintCollector::analyzeNzbForCompactNative($xml);
            $actualMethod = $collector->analyzeNzbForCompact($xml);

            self::assertNotNull($actualNative, "native returned null on iteration {$i}");
            self::assertSame($expectedPurePhp, $expectedHybrid, "hybrid mismatch on iteration {$i}");
            self::assertSame($expectedPurePhp, $actualNative, "native mismatch on iteration {$i}");
            self::assertSame($expectedPurePhp, $actualMethod, "method mismatch on iteration {$i}");

            if ($expectedPurePhp['manifest_invalid_files'] > 0) {
                $invalidObserved++;
            }
            if ($expectedPurePhp['nzb']['total_bytes'] === null) {
                $overflowObserved++;
            }
            if ($expectedPurePhp['posting'] === null) {
                $nullPostingObserved++;
            }
            if ($expectedPurePhp['file_count'] > 200) {
                $cappedObserved++;
            }
        }

        self::assertGreaterThan(0, $invalidObserved);
        self::assertGreaterThan(0, $overflowObserved);
        self::assertGreaterThan(0, $nullPostingObserved);
        self::assertGreaterThan(0, $cappedObserved);
    }

    #[Test]
    public function analyze_nzb_for_compact_defers_invalid_xml_to_php_exceptions(): void
    {
        $nzbStub = (new ReflectionClass(NzbService::class))->newInstanceWithoutConstructor();
        $collector = new CompactFingerprintCollector($nzbStub, new NzbFingerprintAnalyzer(new YencService), new Par2MetadataParser);

        $invalidInputs = [
            'empty string' => '',
            'malformed xml' => '<nzb><file></nzb>',
            'empty nzb without files' => '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"></nzb>',
            'entity declaration' => '<?xml version="1.0"?><!DOCTYPE nzb [<!ENTITY xxe "bad">]><nzb><file subject="a"><segments><segment bytes="1" number="1">m@x</segment></segments></file></nzb>',
            'unsupported doctype' => '<?xml version="1.0"?><!DOCTYPE html><nzb><file subject="a"><segments><segment bytes="1" number="1">m@x</segment></segments></file></nzb>',
        ];

        foreach ($invalidInputs as $label => $xml) {
            CompactFingerprintCollector::setUseRustFfi(true);
            self::assertNull(CompactFingerprintCollector::analyzeNzbForCompactNative($xml), $label);

            CompactFingerprintCollector::setUseRustFfi(false);
            $expectedMessage = null;
            try {
                $collector->analyzeNzbForCompact($xml);
            } catch (RuntimeException $e) {
                $expectedMessage = $e->getMessage();
            }
            self::assertNotNull($expectedMessage, "expected RuntimeException on {$label}");

            CompactFingerprintCollector::setUseRustFfi(true);
            $actualMessage = null;
            try {
                $collector->analyzeNzbForCompact($xml);
            } catch (RuntimeException $e) {
                $actualMessage = $e->getMessage();
            }
            self::assertSame($expectedMessage, $actualMessage, "exception message mismatch on {$label}");
        }
    }

    #[Test]
    public function select_samples_matches_php_across_randomized_manifests(): void
    {
        mt_srand(5101);
        $omittedObserved = 0;
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $files = self::randomFiles(mt_rand(0, 35), true);
            $phpOmitted = null;
            $rustOmitted = null;
            $expected = CompactFingerprintCollector::selectSamples($files, $phpOmitted);
            $actual = CompactFingerprintCollector::selectSamplesNative($files, $rustOmitted);

            self::assertNotNull($actual, "iteration {$i}");
            self::assertSame($expected, $actual, "samples mismatch on iteration {$i}");
            self::assertSame($phpOmitted, $rustOmitted, "omitted mismatch on iteration {$i}");
            $omittedObserved += (int) $phpOmitted;
        }
        self::assertGreaterThan(0, $omittedObserved);
    }

    #[Test]
    public function summarize_files_matches_php_across_randomized_and_capped_manifests(): void
    {
        mt_srand(5102);
        $refSummary = new ReflectionMethod(CompactFingerprintCollector::class, 'nzbSummary');
        $refGroups = new ReflectionMethod(CompactFingerprintCollector::class, 'nzbGroups');
        $refFileRows = new ReflectionMethod(CompactFingerprintCollector::class, 'fileRows');
        $refPosting = new ReflectionMethod(CompactFingerprintCollector::class, 'posting');
        $refInvalid = new ReflectionMethod(CompactFingerprintCollector::class, 'manifestInvalidFileCount');

        $overflowObserved = 0;
        $nullPostingObserved = 0;
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $fileCount = $i % 25 === 0 ? mt_rand(205, 230) : mt_rand(1, 30);
            $files = self::randomFiles($fileCount, true);
            $manifest = [
                'file_count' => count($files),
                'files' => $files,
                'advertised_byte_totals_valid' => mt_rand(0, 9) > 0,
            ];
            $firstPrefixMd5 = [];
            foreach ($files as $file) {
                if (mt_rand(0, 2) === 0) {
                    $firstPrefixMd5[(int) $file['index']] = bin2hex(random_bytes(16));
                }
            }

            $expected = [
                'nzb' => $refSummary->invoke(null, $manifest),
                'files' => $refFileRows->invoke(null, $files, $firstPrefixMd5),
                'posting' => $refPosting->invoke(null, $files),
                'invalid_file_count' => $refInvalid->invoke(null, $manifest),
                'group_count' => count($refGroups->invoke(null, $files)),
            ];
            $actual = CompactFingerprintCollector::summarizeFilesNative($manifest, $firstPrefixMd5);

            self::assertNotNull($actual, "iteration {$i}");
            self::assertSame($expected, $actual, "summary mismatch on iteration {$i}");
            if ($expected['nzb']['total_bytes'] === null) {
                $overflowObserved++;
            }
            if ($expected['posting'] === null) {
                $nullPostingObserved++;
            }
        }
        self::assertGreaterThan(0, $overflowObserved);
        self::assertGreaterThan(0, $nullPostingObserved);
    }

    #[Test]
    public function yenc_integrity_and_summary_match_php_across_all_framing_states(): void
    {
        mt_srand(5103);
        $refErrors = new ReflectionMethod(CompactFingerprintCollector::class, 'yencIntegrityErrors');
        $refSummary = new ReflectionMethod(CompactFingerprintCollector::class, 'yencSummary');
        $refPartWithout = new ReflectionMethod(CompactFingerprintCollector::class, 'partWithoutTrailerPart');
        $refContradict = new ReflectionMethod(CompactFingerprintCollector::class, 'declaredCrcsContradict');
        $refEmptySingle = new ReflectionMethod(CompactFingerprintCollector::class, 'emptySinglePart');
        $refSizesAgree = new ReflectionMethod(CompactFingerprintCollector::class, 'sizesAgree');

        $seenErrors = [];
        $trailerlessVerifiedCount = 0;
        $emptySingleCount = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $yenc = self::randomYenc($i);
            $expectedErrors = $refErrors->invoke(null, $yenc);
            $expectedSummary = $refSummary->invoke(null, $yenc, $expectedErrors === []);
            $expectedPartWithout = is_array($yenc) ? $refPartWithout->invoke(null, $yenc) : false;
            $expectedContradict = is_array($yenc) ? $refContradict->invoke(null, $yenc) : false;
            $expectedEmptySingle = is_array($yenc) ? $refEmptySingle->invoke(null, $yenc) : false;
            $expectedSizesAgree = is_array($yenc) ? $refSizesAgree->invoke(null, $yenc) : null;

            $actual = CompactFingerprintCollector::yencIntegrityNative($yenc);
            self::assertNotNull($actual, "iteration {$i}");
            self::assertSame([
                'errors' => $expectedErrors,
                'summary' => $expectedSummary,
                'part_without_trailer_part' => $expectedPartWithout,
                'declared_crcs_contradict' => $expectedContradict,
                'empty_single_part' => $expectedEmptySingle,
                'sizes_agree' => $expectedSizesAgree,
            ], $actual, "yenc mismatch on iteration {$i}");

            foreach ($expectedErrors as $err) {
                $seenErrors[$err] = true;
            }
            if ($expectedPartWithout && $expectedErrors === []) {
                $trailerlessVerifiedCount++;
            }
            if ($expectedEmptySingle && $expectedErrors === []) {
                $emptySingleCount++;
            }
        }

        foreach (['YencCrcMismatch', 'YencSizeMismatch', 'YencFramingInvalid', 'YencUnverified'] as $errorType) {
            self::assertArrayHasKey($errorType, $seenErrors);
        }
        self::assertGreaterThan(0, $trailerlessVerifiedCount);
        self::assertGreaterThan(0, $emptySingleCount);
    }

    #[Test]
    public function verified_part_prefix_md5_matches_php(): void
    {
        $refPrefixMd5 = new ReflectionMethod(CompactFingerprintCollector::class, 'verifiedPartPrefixMd5');
        $payload20k = random_bytes(20_000);
        $payload500 = random_bytes(500);

        $cases = [
            'full 16k prefix' => [['file_size' => 40_000, 'begin' => 1], $payload20k],
            'small file under 16k' => [['file_size' => 500, 'begin' => 1], $payload500],
            'short decoded bytes' => [['file_size' => 40_000, 'begin' => 1], $payload500],
            'begin not 1' => [['file_size' => 500, 'begin' => 2], $payload20k],
            'zero file size' => [['file_size' => 0, 'begin' => 1], $payload20k],
            'negative file size' => [['file_size' => -5, 'begin' => 1], $payload20k],
            'missing begin' => [['file_size' => 500], $payload500],
            'non-string decoded' => [['file_size' => 500, 'begin' => 1], null],
        ];

        foreach ($cases as $name => [$yenc, $decoded]) {
            $supported = false;
            $expected = $refPrefixMd5->invoke(null, $yenc, $decoded);
            $actual = CompactFingerprintCollector::verifiedPartPrefixMd5Native($yenc, $decoded, $supported);

            self::assertTrue($supported, $name);
            self::assertSame($expected, $actual, $name);
        }
    }

    #[Test]
    public function subject_filename_and_bounded_match_php_including_multibyte_boundaries(): void
    {
        $refSubject = new ReflectionMethod(CompactFingerprintCollector::class, 'subjectFilename');
        $refBounded = new ReflectionMethod(CompactFingerprintCollector::class, 'bounded');

        $subjects = [
            '[01/42] - "Movie.Release.2026.part01.rar" yEnc (1/15)',
            'unquoted subject without quotes yEnc (1/1)',
            '"" "space-between-empty-quotes.par2" yEnc',
            '"""triple-leading-quotes.rar" yEnc',
            '"'.str_repeat('a', 150).'.rar" yEnc (1/1)',
            '"'.str_repeat('名', 60).'.rar" yEnc (1/1)',
            '"'.str_repeat('x', 118).'名.rar" yEnc (1/1)',
            '"'.str_repeat('x', 119).'名.rar" yEnc (1/1)',
            '"line1'."\n".'line2.nfo" yEnc',
            '',
        ];

        $expectedBatch = [];
        foreach ($subjects as $idx => $subject) {
            $expectedFn = $refSubject->invoke(null, $subject);
            $expectedBounded = $refBounded->invoke(null, $subject);
            $expectedCombined = $refBounded->invoke(null, $expectedFn);
            $expectedBatch[] = $expectedCombined;

            $supported = false;
            self::assertSame($expectedFn, CompactFingerprintCollector::subjectFilenameNative($subject), "subjectFilename #{$idx}");
            self::assertSame($expectedBounded, CompactFingerprintCollector::boundedNative($subject, $supported), "bounded #{$idx}");
            self::assertTrue($supported);
            self::assertSame($expectedCombined, CompactFingerprintCollector::subjectFilenameBoundedNative($subject), "combined #{$idx}");
        }

        self::assertSame($expectedBatch, CompactFingerprintCollector::subjectFilenameBoundedBatchNative($subjects));

        foreach ([12345, -99, null, true, ['array']] as $scalarOrOther) {
            $supported = false;
            $actual = CompactFingerprintCollector::boundedNative($scalarOrOther, $supported);
            self::assertTrue($supported);
            self::assertSame($refBounded->invoke(null, $scalarOrOther), $actual);
        }
    }

    #[Test]
    public function ffi_exports_handle_small_buffers_and_defer_unsupported_inputs(): void
    {
        self::assertNotNull($this->ffi);
        $small = $this->ffi->new('char[8]');

        $filesPayload = serialize(self::randomFiles(6, false));
        // @phpstan-ignore method.notFound
        $needed = $this->ffi->nntmux_compact_fp_select_samples($filesPayload, strlen($filesPayload), $small, 8);
        self::assertLessThan(-8, $needed);

        // @phpstan-ignore method.notFound
        $deferred = $this->ffi->nntmux_compact_fp_select_samples('O:8:"stdClass":0:{}', 19, $small, 8);
        self::assertSame(-1, $deferred);

        $invalidUtf8 = "\xff\xfe".str_repeat('a', 30);
        // @phpstan-ignore method.notFound
        $utf8Deferred = $this->ffi->nntmux_compact_fp_subject_filename_bounded($invalidUtf8, strlen($invalidUtf8), 1, $small, 8);
        self::assertSame(-1, $utf8Deferred);

        $supported = false;
        self::assertNull(CompactFingerprintCollector::boundedNative($invalidUtf8, $supported));
        self::assertFalse($supported);

        $payload = str_repeat('x', 1024);
        // @phpstan-ignore method.notFound
        $md5Small = $this->ffi->nntmux_compact_fp_verified_part_prefix_md5($payload, strlen($payload), 1024, 1, $small, 8);
        self::assertSame(-32, $md5Small);

        $validXml = self::randomNzbXml(4, 1);
        // @phpstan-ignore method.notFound
        $xmlSmall = $this->ffi->nntmux_compact_fp_analyze_nzb_xml($validXml, strlen($validXml), $small, 8);
        self::assertLessThan(-8, $xmlSmall);

        // @phpstan-ignore method.notFound
        $xmlInvalid = $this->ffi->nntmux_compact_fp_analyze_nzb_xml('<nzb></nzb>', 11, $small, 8);
        self::assertSame(-1, $xmlInvalid);
    }

    private static function randomNzbXml(int $fileCount, int $iteration): string
    {
        $doctype = $iteration % 3 === 0
            ? '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'
            : '';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$doctype.'<!-- header comment --><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
            .'<head><meta type="title">Release &amp; Title '.$iteration.'</meta></head>';

        $posters = ['poster1@example.com', 'poster2@example.com', '名'.str_repeat('p', 130).'@example.com', 'poster&amp;3@example.com', ''];
        $exts = ['par2', 'rar', 'nfo', 'mkv'];
        $allZeroDate = $iteration % 9 === 0;

        for ($i = 0; $i < $fileCount; $i++) {
            $ext = $i < 2 ? 'par2' : $exts[mt_rand(0, count($exts) - 1)];
            $segCount = mt_rand(1, 5);
            $subjBase = match ($i % 5) {
                0 => '&quot;'.str_repeat('名', 45).".part{$i}.{$ext}&quot; yEnc (1/{$segCount})",
                1 => "unquoted.file.{$i}.{$ext} yEnc (1/{$segCount})",
                2 => '&quot;Release &amp; Bonus &#x3042; '."part{$i}.{$ext}&quot; yEnc (1/{$segCount})",
                default => "&quot;Movie.2026.part{$i}.{$ext}&quot; yEnc (1/{$segCount})",
            };
            $poster = $posters[mt_rand(0, count($posters) - 1)];
            $date = $allZeroDate || mt_rand(0, 5) === 0 ? 0 : 1_790_812_800 + mt_rand(0, 7_200);

            $xml .= '<file poster="'.$poster.'" date="'.$date.'" subject="'.$subjBase.'"><groups>';
            $groupCount = $iteration % 11 === 0 && $i === 0 ? 24 : mt_rand(1, 3);
            for ($g = 0; $g < $groupCount; $g++) {
                $gName = $g === 0 && mt_rand(0, 6) === 0
                    ? 'alt.binaries.'.str_repeat('名', 45).".{$g}"
                    : "alt.binaries.group{$g}";
                $xml .= "<group>{$gName}</group>";
            }
            $xml .= '</groups><segments>';

            $reverseOrder = mt_rand(0, 4) === 0;
            $segIndices = range(1, $segCount);
            if ($reverseOrder) {
                $segIndices = array_reverse($segIndices);
            }
            foreach ($segIndices as $s) {
                $segNum = $iteration % 7 === 0 && $i === 0 && $s === $segCount && $segCount > 1 ? 1 : $s;
                $segBytes = $iteration % 13 === 0 && $i === 0 ? PHP_INT_MAX : mt_rand(1_000, 500_000);
                $msgId = $iteration % 8 === 0 && $i === 0 && $s > 1
                    ? "dup-f{$i}@fixture"
                    : "f{$i}-s{$s}-&#65;@fixture";
                $xml .= '<segment bytes="'.$segBytes.'" number="'.$segNum.'">'.$msgId.'</segment>';
            }
            $xml .= '</segments></file>';
        }

        return $xml.'</nzb>';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function randomFiles(int $count, bool $noisy): array
    {
        $files = [];
        $posters = ['poster1@example.com', 'poster2@example.com', '名'.str_repeat('p', 130).'@example.com', ''];
        $kinds = ['par2', 'rar', 'other'];

        for ($i = 0; $i < $count; $i++) {
            $kind = $i < 2 ? 'par2' : $kinds[mt_rand(0, count($kinds) - 1)];
            $segCount = $noisy && mt_rand(0, 12) === 0 ? 0 : mt_rand(1, 7);
            $segments = [];
            for ($s = 1; $s <= $segCount; $s++) {
                $msgId = $noisy && mt_rand(0, 15) === 0
                    ? ($s > 1 ? "f{$i}-s1@fixture" : '')
                    : "f{$i}-s{$s}@fixture";
                $segments[] = [
                    'number' => $s,
                    'bytes' => 50_000,
                    'messageid' => $msgId,
                ];
            }

            $groupCount = $noisy && mt_rand(0, 10) === 0 ? 25 : mt_rand(1, 3);
            $groups = [];
            for ($g = 0; $g < $groupCount; $g++) {
                $groups[] = $g === 0 && $noisy && mt_rand(0, 6) === 0
                    ? 'alt.binaries.'.str_repeat('名', 50).".{$g}"
                    : "alt.binaries.group{$g}";
            }

            $bytes = match ($noisy ? mt_rand(0, 15) : 1) {
                0 => null,
                2 => PHP_INT_MAX,
                default => mt_rand(1_000, 50_000_000),
            };
            $subjectName = $noisy && mt_rand(0, 5) === 0
                ? str_repeat('名', 45).".part{$i}.rar"
                : "Release.2026.part{$i}.{$kind}";

            $files[] = [
                'index' => $i,
                'kind' => $kind,
                'format_hint' => $kind === 'other' ? null : $kind,
                'subject' => "\"{$subjectName}\" yEnc (1/{$segCount})",
                'poster' => $posters[mt_rand(0, count($posters) - 1)],
                'date' => $noisy && mt_rand(0, 4) === 0 ? 0 : 1_790_812_800 + mt_rand(0, 3_600),
                'groups' => $groups,
                'segment_count' => $segCount,
                'invalid_segment_record_count' => $noisy && mt_rand(0, 12) === 0 ? 1 : 0,
                'invalid_segment_metadata_count' => $noisy && mt_rand(0, 12) === 0 ? 1 : 0,
                'duplicate_message_id_count' => $noisy && mt_rand(0, 12) === 0 ? 1 : 0,
                'duplicate_segment_number_count' => $noisy && mt_rand(0, 12) === 0 ? 1 : 0,
                'segment_number_sequence_valid' => ! ($noisy && mt_rand(0, 10) === 0),
                'advertised_bytes' => $bytes,
                'advertised_bytes_overflow' => $noisy && mt_rand(0, 12) === 0,
                'segments' => $segments,
            ];
        }

        return $files;
    }

    private static function randomYenc(int $iteration): mixed
    {
        if ($iteration % 20 === 0) {
            return null;
        }
        if ($iteration % 29 === 0) {
            return 'not-an-array';
        }

        $mode = $iteration % 7;
        if ($mode === 0) {
            // Valid empty single-part
            $header = ['line' => '128', 'size' => '0', 'name' => 'empty.txt'];
            if (mt_rand(0, 3) === 0) {
                $header['line'] = mt_rand(0, 1) === 1 ? '0' : 'abc';
            }

            return [
                'name' => 'empty.txt',
                'file_size' => 0,
                'part' => null,
                'total_parts' => null,
                'begin' => null,
                'end' => null,
                'multipart' => false,
                'multipart_structure_status' => 'single-part',
                'multipart_pcrc32_status' => null,
                'declared_crc32_matches' => true,
                'all_declared_crc32_fields_are_hex' => true,
                'decoded_segment_size' => 0,
                'decoded_size_matches_yenc' => null,
                'decoded_size_matches_ypart_range' => null,
                'decoded_size_matches_yend_size' => true,
                'ypart_range_valid' => null,
                'header_numeric_fields_valid' => false,
                'header_numeric_fields_representable' => true,
                'trailer_numeric_fields_valid' => true,
                'trailer_numeric_fields_representable' => true,
                'trailer_part_matches_header' => null,
                'trailer_total_matches_header' => null,
                'payload_escape_state_valid' => true,
                'integrity_checks_ambiguous' => false,
                'conflicting_control_fields' => [],
                'observed_decoded_crc32' => '00000000',
                'header_fields' => $header,
                'trailer_fields' => ['size' => '0', 'crc32' => '00000000'],
            ];
        }

        if ($mode === 1 || $mode === 2) {
            // Trailerless multipart (padded 8-hex or unpadded 5-hex pcrc32)
            $unpadded = $mode === 2;
            $sizeMatch = mt_rand(0, 4) > 0;
            $crcMatch = mt_rand(0, 4) > 0;

            return [
                'name' => str_repeat('名', 45).'.bin',
                'file_size' => 32,
                'part' => 1,
                'total_parts' => 2,
                'begin' => 1,
                'end' => 16,
                'multipart' => true,
                'multipart_structure_status' => $unpadded ? 'invalid' : 'incomplete',
                'multipart_pcrc32_status' => $unpadded ? 'malformed' : 'present-hex',
                'declared_crc32_matches' => null,
                'all_declared_crc32_fields_are_hex' => true,
                'decoded_segment_size' => 16,
                'decoded_size_matches_yenc' => null,
                'decoded_size_matches_ypart_range' => true,
                'decoded_size_matches_yend_size' => $sizeMatch,
                'ypart_range_valid' => true,
                'header_numeric_fields_valid' => true,
                'header_numeric_fields_representable' => true,
                'trailer_numeric_fields_valid' => true,
                'trailer_numeric_fields_representable' => true,
                'trailer_part_matches_header' => null,
                'trailer_total_matches_header' => null,
                'payload_escape_state_valid' => true,
                'integrity_checks_ambiguous' => false,
                'conflicting_control_fields' => [],
                'observed_decoded_crc32' => '000da322',
                'header_fields' => ['line' => '128', 'size' => '32', 'part' => '1', 'total' => '2', 'name' => 'segment.bin'],
                'trailer_fields' => [
                    'size' => $sizeMatch ? '16' : '17',
                    'pcrc32' => $crcMatch ? ($unpadded ? 'da322' : '000da322') : ($unpadded ? 'da323' : '000da323'),
                ],
            ];
        }

        $multipart = mt_rand(0, 1) === 1;
        $crcMatches = [true, true, false, null][mt_rand(0, 3)];
        $escapeValid = mt_rand(0, 6) > 0;
        $yendMatches = [true, true, false, null][mt_rand(0, 3)];
        $contradictSecondary = ! $multipart && mt_rand(0, 4) === 0;

        return [
            'name' => 'segment.bin',
            'file_size' => 80,
            'part' => $multipart ? 1 : null,
            'total_parts' => $multipart ? 5 : null,
            'begin' => $multipart ? 1 : null,
            'end' => $multipart ? 16 : null,
            'multipart' => $multipart,
            'multipart_structure_status' => $multipart ? 'complete' : 'single-part',
            'multipart_pcrc32_status' => $multipart ? 'present-hex' : null,
            'declared_crc32_matches' => $crcMatches,
            'all_declared_crc32_fields_are_hex' => mt_rand(0, 8) > 0,
            'decoded_segment_size' => 16,
            'decoded_size_matches_yenc' => $multipart ? null : $yendMatches,
            'decoded_size_matches_ypart_range' => $multipart ? $yendMatches : null,
            'decoded_size_matches_yend_size' => $yendMatches,
            'ypart_range_valid' => $multipart ? true : null,
            'header_numeric_fields_valid' => true,
            'header_numeric_fields_representable' => true,
            'trailer_numeric_fields_valid' => true,
            'trailer_numeric_fields_representable' => true,
            'trailer_part_matches_header' => $multipart ? true : null,
            'trailer_total_matches_header' => $multipart ? true : null,
            'payload_escape_state_valid' => $escapeValid,
            'integrity_checks_ambiguous' => mt_rand(0, 10) === 0,
            'conflicting_control_fields' => mt_rand(0, 10) === 0 ? ['size'] : [],
            'observed_decoded_crc32' => '00aabbcc',
            'header_fields' => ['line' => '128', 'size' => '80', 'part' => '1', 'total' => '5'],
            'trailer_fields' => $contradictSecondary
                ? ['size' => '16', 'crc32' => '00aabbcc', 'pcrc32' => '00000000']
                : ['size' => '16', 'crc32' => 'aabbcc', 'pcrc32' => '00AABBCC'],
        ];
    }
}
