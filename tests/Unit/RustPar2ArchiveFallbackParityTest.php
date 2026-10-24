<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\MediaSourceStage;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\Par2FileList;
use App\Services\Par2Processor;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use PHPUnit\Framework\TestCase;

class RustPar2ArchiveFallbackParityTest extends TestCase
{
    private const int FUZZ_CASES = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain(
            'wave13_par2_archive_fallback_test',
            Par2FileList::FFI_CDEF."\n"
            .Par2Processor::FFI_CDEF."\n"
            .ReleaseFilesArchiveFallback::FFI_CDEF."\n"
            .MediaSourceStage::FFI_CDEF."\n"
            .ArchivedMediaEntry::FFI_CDEF
        ) === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not built in this environment.');
        }
    }

    protected function tearDown(): void
    {
        Par2FileList::useRustFfi(true);
        Par2Processor::useRustFfi(true);
        ReleaseFilesArchiveFallback::useRustFfi(true);
        MediaSourceStage::useRustFfi(true);
        ArchivedMediaEntry::useRustFfi(true);

        parent::tearDown();
    }

    public function test_extract_files_fixed_cases(): void
    {
        $set = str_repeat("\x11", 16);
        $id1 = str_repeat("\x01", 16);
        $id2 = str_repeat("\x02", 16);
        $main = $this->packet(Par2Info::PACKET_MAIN, $set, pack('PV', 1024, 2).$id1.$id2);
        $desc1 = $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id1, 5000, "movie.mkv\0\0\0"));
        $desc2 = $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id2, 77, "0\0"));
        $ifsc = $this->packet(Par2Info::PACKET_FILEVER, $set, $id2.str_repeat("\x05", 60));
        $creator = $this->packet(Par2Info::PACKET_CREATOR, $set, "QuickPar 0.9\0\0\0");
        $recovery = $this->packet(Par2Info::PACKET_RECOVERY, $set, pack('V', 3).str_repeat("\x09", 28));
        $empty = $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id1, 1, " \t\0\0"));
        $long = $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id2, 1, str_repeat('n', 300)));
        $huge = $this->packet(Par2Info::PACKET_FILEDESC, $set, $id1.str_repeat("\xAB", 32).pack('VV', 7, 0xFFFFFFFF).'big.bin');

        $cases = [
            '', 'no marker here', Par2Info::PACKET_MARKER, 'junk'.$main.$desc1.$desc2,
            $ifsc.$desc2.$desc1.$main.$creator.$recovery, $main, $desc1, $empty, $long, $huge,
            $main.$desc1, $desc1.$desc1.$ifsc.$ifsc, substr($main.$desc1, 0, -3),
            $main.substr_replace($desc1, 'X', -1, 1), $desc1.str_repeat("\0", 70),
            $this->packet(Par2Info::PACKET_MAIN, $set, pack('PV', 1, 9).$id1, 64).$desc1,
            $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id1, 1, 'x'), 32).$desc2,
            $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id1, 1, 'x'), 40).$desc2,
            $this->packet(Par2Info::PACKET_FILEVER, $set, $id1.str_repeat("\x03", 50)).$desc1,
            $this->packet(Par2Info::PACKET_CREATOR, $set, 'c', 48).$desc1,
            $this->packet("PAR 2.0\0Unknown\0", $set, 'whatever').$desc1,
        ];
        foreach ($cases as $case) {
            $this->assertExtractFilesParity($case);
        }
        foreach ([1, 64, 100, 200, 400, 0, -5] as $maxReadBytes) {
            $this->assertExtractFilesParity('junk'.$main.$desc1.$desc2, $maxReadBytes);
        }
    }

    public function test_extract_files_fuzz_parity(): void
    {
        mt_srand(13);
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $this->assertExtractFilesParity($this->randomPar2(), mt_rand(0, 9) === 0 ? mt_rand(1, 4000) : Par2FileList::DEFAULT_MAX_READ_BYTES);
        }
    }

    public function test_par2_file_entry_hydration_parity(): void
    {
        mt_srand(14);
        for ($i = 0; $i < 500; $i++) {
            $data = $this->randomPar2();
            $supported = false;
            // Sizes past PHP_INT_MAX warn on the (int) cast in listFromPar2Info() either way.
            $php = @Par2FileEntry::listFromPar2DataPhp($data);
            $this->assertEquals($php, @Par2FileEntry::listFromPar2DataNative($data, $supported), 'listFromPar2Data: '.bin2hex($data));
            $this->assertTrue($supported);
            $this->assertEquals($php, @Par2FileEntry::listFromPar2Data($data));
        }
    }

    public function test_payload_budget_parity(): void
    {
        $cases = [
            [[], 10], [[['name' => 'a']], 10], [[['size' => 5], ['size' => 6]], 11], [[['size' => 5], ['size' => 6]], 10],
            [[['size' => -1]], 10], [[['size' => 5, 'size_decimal' => '5']], 5], [[['size' => 5, 'size_decimal' => '6']], 5],
            [[['size_decimal' => '9223372036854775807']], PHP_INT_MAX], [[['size_decimal' => '9223372036854775808']], PHP_INT_MAX],
            [[['size_decimal' => '18446744073709551615']], PHP_INT_MAX], [[['size_decimal' => '018']], 100],
            [[['size_decimal' => "1\n"]], 100], [[['size_decimal' => "1\n2"]], 100], [[['size_decimal' => '']], 100],
            [[['size' => 2.0]], 100], [[['size' => 2.5]], 2], [[['size' => INF]], 100], [[['size' => 1.0e30]], PHP_INT_MAX],
            [[['size' => '12']], 100], [[['size' => true]], 100], [[['size' => null, 'name' => 'x']], 100],
            [[['blocks' => 3]], 100], [[['size' => 1]], null], [[['size' => 1]], 0], [[['size' => 0]], -1],
        ];
        foreach ($cases as [$files, $max]) {
            $this->assertPayloadBudgetParity($files, $max);
        }

        mt_srand(15);
        $values = [0, 1, 7, 1000, PHP_INT_MAX, -3, '0', '00', '42', '9223372036854775807', '99999999999999999999', "5\n", 'x', 1.5, null, true];
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $files = [];
            for ($f = mt_rand(0, 6); $f > 0; $f--) {
                $file = [];
                if (mt_rand(0, 2) > 0) {
                    $file['size'] = $values[mt_rand(0, count($values) - 1)];
                }
                if (mt_rand(0, 3) === 0) {
                    $file['size_decimal'] = $values[mt_rand(0, count($values) - 1)];
                }
                if (mt_rand(0, 1) === 0) {
                    $file['name'] = 'f'.$f;
                }
                $files['k'.$f] = $file;
            }
            $this->assertPayloadBudgetParity($files, [null, 0, 1, 50, 5000, PHP_INT_MAX][mt_rand(0, 5)]);
        }
    }

    public function test_archive_image_name_and_suffix_parity(): void
    {
        $cases = [
            'cover.jpg', 'COVER.JPEG', 'a/b/c.Png', 'x.webp', "x.jpg\n", "x.jpg\n\n", 'x.jpgx', 'x.jpe', 'x.jpeg.nfo', '.jpg',
            'jpg', '', '/', 'a.b/', 'a.b/c', 'dir.d/F.TXT', 'x.', 'x..', 'a/.hidden', "caf\xc3\xa9.JPG", "x.J\xc3\x89G",
            "nul\0.png", 'a/b.c/', 'a//b.Mkv//', 'archive.part01.rar', "x.\xc3\x89XT",
        ];
        foreach ($cases as $case) {
            $this->assertArchiveNameParity($case);
        }
        foreach ($this->fuzzStrings(16, ['.jpg', '.JPEG', '.png', '.webp', 'jp', 'g', '.', '/', 'cover', "\n", "\xc3\xa9"]) as $case) {
            $this->assertArchiveNameParity($case);
        }
    }

    public function test_middle_volume_index_parity(): void
    {
        $cases = [
            ['a'], ['b', 'a'], ['r.part10.rar', 'r.part2.rar', 'r.part1.rar'], ['x.r00', 'x.r01', 'x.rar', 'x.R02'],
            ['A', 'a', 'B', 'b'], ['img12', 'img10', 'IMG2', 'img1'], ['x01', 'x1', 'x001', 'x 1', 'x1 '], ['', 'a', ''],
            ['007', '7', '07', '0', '00'], ['1.010', '1.01', '1.1', '1.001'], ["a\tb", 'a b', 'ab', 'a  b'],
            ['vol 9', 'vol 10', 'vol  8', 'vol09'], ["caf\xc3\xa9", "CAF\xc3\x89", 'cafe'],
        ];
        foreach ($cases as $titles) {
            $this->assertMiddleVolumeParity($titles);
        }
        $fragments = ['Movie', '.part', '01', '1', '10', '002', '.rar', '.r', '0', ' ', "\t", 'a', 'B', '-', '_', '9', "\xc3\xa9", "\xa0"];
        $strings = $this->fuzzStrings(17, $fragments);
        mt_srand(18);
        for ($i = 0, $n = count($strings); $i < $n;) {
            $size = mt_rand(1, 40);
            $this->assertMiddleVolumeParity(array_slice($strings, $i, $size));
            $i += $size;
        }

        $supported = true;
        MediaSourceStage::middleVolumeIndexNative(["a\0b", 'c'], $supported);
        $this->assertFalse($supported, 'NUL-holding titles must decline to PHP');
    }

    public function test_middle_volume_index_declines_outside_c_locale(): void
    {
        $restoreC = preg_match('/^\s$/', "\xa0") === 0;
        $original = setlocale(LC_CTYPE, '0');
        try {
            if (setlocale(LC_CTYPE, 'C.UTF-8', 'C.utf8') !== false) {
                $supported = true;
                $titles = ["caf\xc3\xa9.r01", "CAF\xc3\x89.r00"];
                $this->assertSame(
                    MediaSourceStage::middleVolumeIndexPhp($titles),
                    MediaSourceStage::middleVolumeIndexNative($titles, $supported),
                );
                $this->assertFalse($supported, 'non-ASCII titles decline under C.UTF-8');
                MediaSourceStage::middleVolumeIndexNative(['b.r01', 'a.r00'], $supported);
                $this->assertTrue($supported);
            }
            if (setlocale(LC_CTYPE, 'en_US.ISO8859-1', 'en_US.ISO-8859-1', 'de_DE.ISO8859-1') !== false) {
                $supported = true;
                $titles = ["\xe4.r01", "\xc4.r00", 'b'];
                $this->assertSame(
                    MediaSourceStage::middleVolumeIndexPhp($titles),
                    MediaSourceStage::middleVolumeIndexNative($titles, $supported),
                );
                $this->assertFalse($supported);
                ReleaseFilesArchiveFallback::isArchiveImageNameNative('x.jpg', $supported);
                $this->assertFalse($supported);
            }
        } finally {
            setlocale(LC_CTYPE, $original === false ? 'C' : $original);
            if ($restoreC) {
                setlocale(LC_CTYPE, 'C');
            }
        }
    }

    public function test_continuation_bytes_parity(): void
    {
        $cases = [
            [10, 90, 100, 999, 5000, 400], [10, 80, 100, 999, 5000, 400], [0, 100, 100, 149, 5000, 400], [0, 100, 100, 99, 50, 400],
            [0, 0, 0, 0, 0, 0], [0, 10, 10, 1000, 5, 400], [5, 10, 15, 14, 100, 400], [0, 1, 1, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
            [PHP_INT_MAX, 1, 0, 0, 0, 0], [0, 1, 1, 10, 100, PHP_INT_MIN], [-5, 6, 1, 100, 100, 50],
        ];
        foreach ($cases as $case) {
            $this->assertContinuationParity(...$case);
        }
        mt_srand(19);
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $rangeStart = mt_rand(0, 2000);
            $head = mt_rand(0, 3000);
            $downloaded = mt_rand(0, 1) === 0 ? $rangeStart + $head : mt_rand(0, 5000);
            $this->assertContinuationParity($rangeStart, $head, $downloaded, mt_rand(0, 9000), mt_rand(0, 9000), mt_rand(0, 9000));
        }
    }

    public function test_disabled_rust_ffi_falls_back_to_php(): void
    {
        Par2FileList::useRustFfi(false);
        Par2Processor::useRustFfi(false);
        ReleaseFilesArchiveFallback::useRustFfi(false);
        MediaSourceStage::useRustFfi(false);
        ArchivedMediaEntry::useRustFfi(false);

        $set = str_repeat("\x11", 16);
        $data = $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody(str_repeat("\x01", 16), 5, 'a.mkv'));
        $files = $budget = $image = $suffix = $middle = $continuation = true;
        $this->assertSame(Par2FileList::extractFilesPhp($data), Par2FileList::extractFilesNative($data, supported: $files));
        $this->assertTrue(Par2Processor::setFitsPayloadBudgetNative([['size' => 5]], 10, $budget));
        $this->assertTrue(ReleaseFilesArchiveFallback::isArchiveImageNameNative('x.jpg', $image));
        $this->assertSame('.jpg', ReleaseFilesArchiveFallback::extractedFileSuffixNative('x.JPG', $suffix));
        $this->assertSame(0, MediaSourceStage::middleVolumeIndexNative(['b', 'a'], $middle));
        $this->assertSame(10, $this->entry(0, 10, 10, 100, 20)->continuationBytesNative(400, $continuation));
        $this->assertSame([false, false, false, false, false, false], [$files, $budget, $image, $suffix, $middle, $continuation]);
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('WAVE13_PAR2_ARCHIVE_FALLBACK_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set WAVE13_PAR2_ARCHIVE_FALLBACK_BENCHMARK=1 to run paired randomized benchmarks.');
        }

        mt_srand(20);
        $commonPar2 = [$this->indexPar2(4, 40), $this->indexPar2(8, 120), $this->indexPar2(12, 300), $this->indexPar2(2, 10)];
        $uncommonPar2 = [$this->indexPar2(60, 900), $this->indexPar2(200, 20), $this->indexPar2(30, 6000), 'junk'.$this->indexPar2(1, 1)];
        $budgetCommon = [];
        $budgetUncommon = [];
        for ($i = 0; $i < 4; $i++) {
            $budgetCommon[] = array_map(static fn (int $n): array => ['name' => "f{$n}.rar", 'size' => mt_rand(1, 50_000_000)], range(1, 12));
            $budgetUncommon[] = array_map(static fn (int $n): array => ['name' => "f{$n}.rar", 'size_decimal' => (string) mt_rand(1, 50_000_000)], range(1, 150));
        }
        $titlesCommon = [];
        $titlesUncommon = [];
        for ($i = 0; $i < 4; $i++) {
            $titlesCommon[] = array_map(static fn (int $n): string => sprintf('Movie.2026.1080p.BluRay.x264-GRP.part%02d.rar', $n), $this->shuffled(range(1, 8 + $i * 4)));
            $titlesUncommon[] = array_map(static fn (int $n): string => sprintf('[%d/%d] - "Show.Name.S01.2160p.WEB-DL.DDP5.1-GRP.r%03d" yEnc', $n, 300, $n), $this->shuffled(range(1, 80 + $i * 40)));
        }
        $entries = [$this->entry(0, 4096, 4096, 50_000_000, 50_000_000), $this->entry(10, 90, 100, 999, 5000), $this->entry(0, 100, 5000, 9000, 9000), $this->entry(7, 3, 10, 20, 30)];

        $workloads = [
            'extractFiles' => [$commonPar2, $uncommonPar2,
                static fn (string $d): ?array => Par2FileList::extractFilesPhp($d),
                static fn (string $d): ?array => Par2FileList::extractFilesNative($d), 1000],
            'listFromPar2Data' => [$commonPar2, $uncommonPar2,
                static fn (string $d): array => Par2FileEntry::listFromPar2DataPhp($d),
                static fn (string $d): array => Par2FileEntry::listFromPar2DataNative($d), 1000],
            'setFitsPayloadBudget' => [$budgetCommon, $budgetUncommon,
                static fn (array $f): bool => Par2Processor::setFitsPayloadBudgetPhp($f, 400_000_000),
                static fn (array $f): bool => Par2Processor::setFitsPayloadBudgetNative($f, 400_000_000), 2000],
            'isArchiveImageName' => [['Cover.jpg', 'movie.part01.rar', 'Sample/screen01.png', 'release.nfo'],
                [str_repeat('Long.Directory.Name/', 10).'folder.webp', "x.JPEG\n", 'a.jpgx', str_repeat('n', 250).'.png'],
                static fn (string $s): bool => ReleaseFilesArchiveFallback::isArchiveImageNamePhp($s),
                static fn (string $s): bool => ReleaseFilesArchiveFallback::isArchiveImageNameNative($s), 2000],
            'extractedFileSuffix' => [['Cover.jpg', 'release.NFO', 'Sample/screen01.png', 'file_id.diz'],
                [str_repeat('Long.Directory.Name/', 10).'folder.WEBP', 'noext', 'a.b/', str_repeat('n', 250).'.JPEG'],
                static fn (string $s): string => ReleaseFilesArchiveFallback::extractedFileSuffixPhp($s),
                static fn (string $s): string => ReleaseFilesArchiveFallback::extractedFileSuffixNative($s), 2000],
            'middleVolumeIndex' => [$titlesCommon, $titlesUncommon,
                static fn (array $t): ?int => MediaSourceStage::middleVolumeIndexPhp($t),
                static fn (array $t): ?int => MediaSourceStage::middleVolumeIndexNative($t), 2000],
            'continuationBytes' => [array_slice($entries, 0, 2), array_slice($entries, 2),
                static fn (ArchivedMediaEntry $e): int => $e->continuationBytesPhp(8_000_000),
                static fn (ArchivedMediaEntry $e): int => $e->continuationBytesNative(8_000_000), 2000],
        ];

        $results = [];
        foreach ($workloads as $name => [$common, $uncommon, $php, $rust, $pairs]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                foreach ($inputs as $input) {
                    $this->assertEquals($php($input), $rust($input), "{$name}.{$kind} parity");
                }
                $results["{$name}.{$kind}"] = $this->runPairedBenchmark($pairs, $inputs, $php, $rust);
            }
        }

        foreach ($results as $label => $stats) {
            fwrite(STDERR, sprintf(
                "%-32s mean=%.2fx p99=%.2fx php_mean=%.0fns rust_mean=%.0fns php_p99=%.0fns rust_p99=%.0fns n=%d\n",
                $label,
                $stats['mean_speedup'],
                $stats['p99_speedup'],
                $stats['php_mean_ns'],
                $stats['rust_mean_ns'],
                $stats['php_p99_ns'],
                $stats['rust_p99_ns'],
                $stats['samples'],
            ));
        }
        $this->assertNotEmpty($results);
    }

    private function assertExtractFilesParity(string $data, int $maxReadBytes = Par2FileList::DEFAULT_MAX_READ_BYTES): void
    {
        $supported = false;
        $php = Par2FileList::extractFilesPhp($data, $maxReadBytes);
        $native = Par2FileList::extractFilesNative($data, $maxReadBytes, $supported);
        $this->assertSame($php, $native, 'extractFiles('.$maxReadBytes.'): '.bin2hex(substr($data, 0, 2000)));
        $this->assertTrue($supported);
        $this->assertSame($php, Par2FileList::extractFiles($data, $maxReadBytes));
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $files
     */
    private function assertPayloadBudgetParity(array $files, ?int $max): void
    {
        $supported = false;
        $php = Par2Processor::setFitsPayloadBudgetPhp($files, $max);
        $this->assertSame($php, Par2Processor::setFitsPayloadBudgetNative($files, $max, $supported), 'setFitsPayloadBudget: '.var_export([$files, $max], true));
        $this->assertSame($php, Par2Processor::setFitsPayloadBudget($files, $max));
        $hasFloat = array_filter($files, static fn (array $f): bool => is_float($f['size'] ?? null)) !== [];
        if (! $hasFloat) {
            $this->assertTrue($supported, 'setFitsPayloadBudget declined: '.var_export([$files, $max], true));
        }
    }

    private function assertArchiveNameParity(string $name): void
    {
        $imageSupported = $suffixSupported = false;
        $image = ReleaseFilesArchiveFallback::isArchiveImageNamePhp($name);
        $suffix = ReleaseFilesArchiveFallback::extractedFileSuffixPhp($name);
        $this->assertSame($image, ReleaseFilesArchiveFallback::isArchiveImageNameNative($name, $imageSupported), 'isArchiveImageName: '.bin2hex($name));
        $this->assertSame($suffix, ReleaseFilesArchiveFallback::extractedFileSuffixNative($name, $suffixSupported), 'extractedFileSuffix: '.bin2hex($name));
        $this->assertTrue($imageSupported && $suffixSupported);
        $this->assertSame($image, ReleaseFilesArchiveFallback::isArchiveImageName($name));
        $this->assertSame($suffix, ReleaseFilesArchiveFallback::extractedFileSuffix($name));
    }

    /**
     * @param  list<string>  $titles
     */
    private function assertMiddleVolumeParity(array $titles): void
    {
        $supported = false;
        $php = MediaSourceStage::middleVolumeIndexPhp($titles);
        $this->assertSame($php, MediaSourceStage::middleVolumeIndexNative($titles, $supported), 'middleVolumeIndex: '.json_encode(array_map('bin2hex', $titles)));
        $this->assertSame($php, MediaSourceStage::middleVolumeIndex($titles));
        $locale = setlocale(LC_CTYPE, '0');
        $ascii = preg_match('/[\x80-\xff]/', implode('', $titles)) !== 1;
        if (! str_contains(implode('', $titles), "\0") && ($locale === 'C' || $locale === 'POSIX' || $ascii)) {
            $this->assertTrue($supported, 'middleVolumeIndex declined: '.json_encode(array_map('bin2hex', $titles)));
        }
    }

    private function assertContinuationParity(int $rangeStart, int $headLength, int $downloaded, int $inVolumeEnd, int $realSize, int $target): void
    {
        $entry = $this->entry($rangeStart, $headLength, $downloaded, $inVolumeEnd, $realSize);
        $supported = false;
        $php = $entry->continuationBytesPhp($target);
        $this->assertSame($php, $entry->continuationBytesNative($target, $supported));
        $this->assertSame($php, $entry->continuationBytes($target));
    }

    private function entry(int $rangeStart, int $headLength, int $downloaded, int $inVolumeEnd, int $realSize): ArchivedMediaEntry
    {
        return new ArchivedMediaEntry('movie.mkv', $realSize, 0, str_repeat('h', $headLength), $rangeStart, $inVolumeEnd, $downloaded, 3, false, null);
    }

    private function packet(string $type, string $set, string $body, ?int $declaredLength = null): string
    {
        $hashed = $set.$type.$body;
        $length = $declaredLength ?? 64 + strlen($body);
        $covered = substr($hashed, 0, max(0, $length - 32));

        return Par2Info::PACKET_MARKER.pack('VV', $length & 0xFFFFFFFF, $length >> 32).md5($covered, true).$hashed;
    }

    private function descBody(string $fileId, int $size, string $name): string
    {
        return $fileId.md5($name, true).md5($name.'16k', true).pack('P', $size).$name;
    }

    /**
     * A typical PAR2 index: main, a descriptor and IFSC packet per file and a creator.
     */
    private function indexPar2(int $files, int $blocksPerFile): string
    {
        $set = $this->bytes(16);
        $ids = [];
        for ($i = 0; $i < $files; $i++) {
            $ids[] = $this->bytes(16);
        }
        $out = $this->packet(Par2Info::PACKET_MAIN, $set, pack('PV', 768000, $files).implode('', $ids));
        foreach ($ids as $i => $id) {
            $name = sprintf('Some.Release.Name.2026.1080p.WEB-DL.DDP5.1.H.264-GRP.part%03d.rar', $i + 1);
            $out .= $this->packet(Par2Info::PACKET_FILEDESC, $set, $this->descBody($id, mt_rand(1, 400_000_000), str_pad($name, (int) ceil(strlen($name) / 4) * 4, "\0")));
            $out .= $this->packet(Par2Info::PACKET_FILEVER, $set, $id.$this->bytes(20 * $blocksPerFile));
        }

        return $out.$this->packet(Par2Info::PACKET_CREATOR, $set, "Created by par2cmdline version 0.8.1\0\0\0\0");
    }

    private function randomPar2(): string
    {
        $sets = [str_repeat("\x11", 16), str_repeat("\x22", 16)];
        $ids = [str_repeat("\x01", 16), str_repeat("\x02", 16), str_repeat("\x03", 16), '0123456789abcdef'];
        $names = ['movie.mkv', "a.nfo\0\0\0", '0', '', " \t", str_repeat('x', 270), "name \x0b\0", "caf\xc3\xa9.rar", "\0\0\0\0"];
        $out = mt_rand(0, 3) === 0 ? $this->bytes(mt_rand(1, 12)) : '';
        for ($p = mt_rand(0, 7); $p > 0; $p--) {
            $set = $sets[mt_rand(0, 1)];
            $id = $ids[mt_rand(0, 3)];
            $kind = mt_rand(0, 9);
            [$type, $body] = match (true) {
                $kind <= 1 => [Par2Info::PACKET_MAIN, pack('PV', 1024, mt_rand(0, 3)).implode('', array_slice($ids, 0, mt_rand(0, 4)))],
                $kind <= 4 => [Par2Info::PACKET_FILEDESC, $id.$this->bytes(32).(mt_rand(0, 5) === 0 ? pack('VV', mt_rand(0, 9), mt_rand(0x80000000, 0xFFFFFFFF)) : pack('P', mt_rand(0, 9_000_000)))
                    .$names[mt_rand(0, count($names) - 1)]],
                $kind <= 6 => [Par2Info::PACKET_FILEVER, $id.str_repeat("\x07", 20 * mt_rand(0, 4) + (mt_rand(0, 4) === 0 ? mt_rand(1, 19) : 0))],
                $kind === 7 => [Par2Info::PACKET_RECOVERY, pack('V', 1).$this->bytes(mt_rand(0, 8))],
                $kind === 8 => [Par2Info::PACKET_CREATOR, 'creator'.str_repeat("\0", mt_rand(0, 3))],
                default => ["PAR 2.0\0Unknown\0", $this->bytes(mt_rand(0, 8))],
            };
            $declared = match (mt_rand(0, 14)) {
                0 => mt_rand(0, 70),
                1 => 64 + strlen($body) + mt_rand(1, 40),
                2 => 32,
                default => null,
            };
            $packet = $this->packet($type, $set, $body, $declared);
            if (mt_rand(0, 20) === 0) {
                $packet[mt_rand(16, strlen($packet) - 1)] = chr(mt_rand(0, 255));
            }
            if (mt_rand(0, 25) === 0) {
                $packet = substr_replace($packet, pack('V', mt_rand(0x80000000, 0xFFFFFFFF)), 12, 4);
            }
            $out .= $packet;
            if (mt_rand(0, 8) === 0) {
                $out .= $this->bytes(mt_rand(1, 6));
            }
        }
        if ($out !== '' && mt_rand(0, 6) === 0) {
            $out = substr($out, 0, mt_rand(0, strlen($out)));
        }

        return $out;
    }

    /** Seeded bytes, so fuzz failures reproduce. */
    private function bytes(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= chr(mt_rand(0, 255));
        }

        return $out;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function shuffled(array $values): array
    {
        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    /**
     * @param  list<string>  $fragments
     * @return list<string>
     */
    private function fuzzStrings(int $seed, array $fragments): array
    {
        mt_srand($seed);
        $alphabet = "aZ09._-/ \n\t\x00\x0b\x7f\xc3\xa9\xff";
        $out = [];
        for ($i = 0; $i < self::FUZZ_CASES; $i++) {
            $s = '';
            for ($p = mt_rand(0, 8); $p > 0; $p--) {
                $s .= mt_rand(0, 2) === 0
                    ? $alphabet[mt_rand(0, strlen($alphabet) - 1)]
                    : $fragments[mt_rand(0, count($fragments) - 1)];
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Paired single-call samples over rotating inputs, with per-pair randomized order.
     *
     * @param  list<mixed>  $inputs
     * @return array{mean_speedup: float, p99_speedup: float, php_mean_ns: float, rust_mean_ns: float, php_p99_ns: float, rust_p99_ns: float, samples: int}
     */
    private function runPairedBenchmark(int $pairs, array $inputs, callable $phpFn, callable $rustFn): array
    {
        foreach ($inputs as $input) {
            for ($w = 0; $w < 20; $w++) {
                $phpFn($input);
                $rustFn($input);
            }
        }

        mt_srand(0x5EED);
        $phpTimes = [];
        $rustTimes = [];
        $count = count($inputs);
        for ($i = 0; $i < $pairs; $i++) {
            $input = $inputs[$i % $count];
            if (mt_rand(0, 1) === 1) {
                $t0 = hrtime(true);
                $rustFn($input);
                $rustTimes[] = hrtime(true) - $t0;
                $t1 = hrtime(true);
                $phpFn($input);
                $phpTimes[] = hrtime(true) - $t1;
            } else {
                $t0 = hrtime(true);
                $phpFn($input);
                $phpTimes[] = hrtime(true) - $t0;
                $t1 = hrtime(true);
                $rustFn($input);
                $rustTimes[] = hrtime(true) - $t1;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $phpMean = array_sum($phpTimes) / count($phpTimes);
        $rustMean = max(1.0, array_sum($rustTimes) / count($rustTimes));
        $p99Index = min(count($phpTimes) - 1, (int) floor(count($phpTimes) * 0.99));
        $phpP99 = (float) $phpTimes[$p99Index];
        $rustP99 = max(1.0, (float) $rustTimes[$p99Index]);

        return [
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $phpP99 / $rustP99,
            'php_mean_ns' => $phpMean,
            'rust_mean_ns' => $rustMean,
            'php_p99_ns' => $phpP99,
            'rust_p99_ns' => $rustP99,
            'samples' => $pairs,
        ];
    }
}
