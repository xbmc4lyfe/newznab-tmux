<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Parity suite for `nntmux_container_splice` (rust/pipeline-ffi/src/additional.rs) against
 * `ContainerSplicer::splice()` and the original concatenating implementation.
 *
 * Paired randomized benchmark (opt-in, see test_paired_randomized_benchmark): Apple M5, macOS arm64,
 * PHP 8.5.12 NTS, rustc 1.99.1 `--release` (LTO), 8 MiB head / 3 MiB mid over 8 inputs per workload,
 * 3 untimed warmups each, N=1000 randomized-order triples, two runs (run 1 / run 2). Timings cover
 * the whole splice: FFI call, offset readback and the joined copy. Mean / p99 in microseconds.
 *
 * | Workload | Legacy concat | splice() PHP | spliceNative() Rust | Rust vs PHP | PHP vs legacy |
 * | --- | --- | --- | --- | --- | --- |
 * | Matroska, cluster anywhere in mid (common) | 279.5 / 507.7 | 178.8 / 303.9 | 138.7 / 277.1 | 1.29x / 1.10x (run 2: 1.29x / 1.19x) | 1.56x / 1.67x |
 * | Transport stream packets (common) | 157.4 / 251.7 | 157.0 / 267.9 | 156.6 / 272.9 | 1.00x / 0.98x (1.00x / 1.02x) | 1.00x / 0.94x |
 * | Program stream, pack within 4 KiB (common) | 163.3 / 298.8 | 164.0 / 310.4 | 164.3 / 315.3 | 1.00x / 0.98x (1.01x / 1.02x) | 1.00x / 0.96x |
 * | AVI, chunk id within 16 KiB (common) | 355.4 / 577.8 | 169.5 / 292.8 | 169.0 / 283.3 | 1.00x / 1.03x (0.98x / 0.88x) | 2.10x / 1.97x |
 * | Matroska, no cluster in mid (uncommon) | 194.1 / 282.0 | 194.6 / 306.0 | 111.0 / 195.3 | 1.75x / 1.57x (1.47x / 1.40x) | 1.00x / 0.92x |
 * | AVI, no chunk id in mid (uncommon) | 103.4 / 176.3 | 102.9 / 178.7 | 110.5 / 174.5 | 0.93x / 1.02x (0.92x / 0.97x) | 1.00x / 0.99x |
 *
 * The joined multi-megabyte copy dominates every successful splice, transport stream offsets are
 * already Rust-scanned, and Zend's strpos / PCRE-JIT scans are native, so Rust misses the 1.5x
 * common-path threshold: splice() stays on PHP and spliceNative() remains a tested candidate.
 * The retained native fix replaces `substr().substr()` (two tail copies) with one
 * `substr_replace()` copy: 1.56x / 1.67x on Matroska and 2.10x / 1.97x on AVI over the original.
 *
 * ArchivedMediaSlicer was screened too: its CPU helpers (sample-name PCRE 83 ns mean / 167 ns
 * p99, range explode 87 / 167 ns, basename comparison 110 / 209 ns) cost no more than a bare
 * string FFI round trip (72 / 166 ns), so no Rust candidate can reach 1.5x and it is unchanged.
 */
final class RustMediaSlicerSplicerParityTest extends TestCase
{
    private const string CDEF = 'int32_t nntmux_container_splice(uint8_t container_code, const char *head, size_t head_len, const char *tail, size_t tail_len, size_t *out_head_end, size_t *out_tail_start);';

    private const string CLUSTER = "\x1F\x43\xB6\x75";

    private const string PACK = "\x00\x00\x01\xBA";

    private const int SEED = 20261024;

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('media_splicer_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust container splice FFI export is unavailable.');
        }

        return $ffi;
    }

    /**
     * ContainerSplicer::splice() as it was before the single-copy join.
     */
    private static function legacySplice(string $head, string $mid, VideoContainer $container): ?string
    {
        switch ($container) {
            case VideoContainer::Matroska:
                $headEnd = strpos($head, self::CLUSTER);
                $midStart = strpos($mid, self::CLUSTER);

                return $headEnd === false || $midStart === false ? null : substr($head, 0, $headEnd).substr($mid, $midStart);
            case VideoContainer::MpegTransport:
                $offset = VideoContainer::transportStreamOffsetPhp($mid);

                return $offset === null ? null : substr($mid, $offset);
            case VideoContainer::MpegProgram:
                $offset = strpos($mid, self::PACK);

                return $offset === false ? null : substr($mid, $offset);
            case VideoContainer::Avi:
                $movi = strpos($head, 'movi');
                if ($movi === false || $movi < 8 || substr($head, $movi - 8, 4) !== 'LIST'
                    || preg_match('/\d\d(?:dc|db|wb)/', $mid, $chunk, PREG_OFFSET_CAPTURE) !== 1
                ) {
                    return null;
                }

                return substr($head, 0, $movi + 4).substr($mid, $chunk[0][1]);
            default:
                return null;
        }
    }

    private function assertParity(string $head, string $mid, VideoContainer $container, string $label = ''): ?string
    {
        $expected = self::legacySplice($head, $mid, $container);
        $splicer = new ContainerSplicer;
        self::assertSame($expected, $splicer->splice($head, $mid, $container), $label.' splice()');
        $supported = false;
        self::assertSame($expected, $splicer->spliceNative($head, $mid, $container, $supported), $label.' spliceNative()');
        self::assertTrue($supported, $label.' native path used');

        return $expected;
    }

    private static function vint(int $length): string
    {
        return $length < 0x7F ? chr(0x80 | $length) : pack('n', 0x4000 | $length);
    }

    private static function ebml(string $id, string $payload): string
    {
        return $id.self::vint(strlen($payload)).$payload;
    }

    private static function matroskaHead(int $clusters): string
    {
        $head = self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x86", "\x01").self::ebml("\x42\x82", 'matroska'))
            ."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .self::ebml("\x11\x4D\x9B\x74", str_repeat("\x4D\xBB\x8B", 4))
            .self::ebml("\x15\x49\xA9\x66", self::ebml("\x2A\xD7\xB1", "\x0F\x42\x40"))
            .self::ebml("\x16\x54\xAE\x6B", self::ebml("\xAE", self::ebml("\xD7", "\x01").self::ebml("\x86", 'V_MPEG4/ISO/AVC')));
        for ($i = 0; $i < $clusters; $i++) {
            $head .= self::ebml(self::CLUSTER, self::ebml("\xE7", chr($i)).self::ebml("\xA3", "\x81\x00\x00\x80".str_repeat(chr(0x20 + $i), 300)));
        }

        return $head;
    }

    private static function tsPackets(int $count, int $stride = 188): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $packet = "\x47\x01\x00".chr(0x10 | ($i & 0x0F)).str_repeat(chr($i & 0xFF), 184);
            $out .= $stride === 192 ? pack('N', $i * 3003).$packet : $packet;
        }

        return $out;
    }

    private static function aviHead(): string
    {
        $hdrl = 'avih'.pack('V', 56).str_repeat("\0", 56);

        return 'RIFF'.pack('V', 100000).'AVI LIST'.pack('V', strlen($hdrl) + 4).'hdrl'.$hdrl.'LIST'.pack('V', 90000).'movi'
            .'00dc'.pack('V', 6).'FRAME0'.'01wb'.pack('V', 4).'AUD0';
    }

    /**
     * @return array<string, array{string, string, VideoContainer, bool}>
     */
    public static function fixtures(): array
    {
        $mkvHead = self::matroskaHead(2);
        $mkvMid = 'tail-of-a-block'.substr(self::matroskaHead(3), 120);
        $ts = self::tsPackets(8);
        $m2ts = self::tsPackets(8, 192);
        $pes = "\x00\x00\x01\xE0\x00\x08\x80\x80\x05\x21\x00\x01\x00\x01";
        $pack = self::PACK."\x44\x00\x04\x00\x04\x01\x01\x89\xC3\xF8";
        $avi = self::aviHead();

        return [
            'matroska headers then mid cluster' => [$mkvHead, $mkvMid, VideoContainer::Matroska, true],
            'matroska cluster at both starts' => [self::CLUSTER.'head', self::CLUSTER.'mid', VideoContainer::Matroska, true],
            'matroska first of several clusters' => ['H'.self::CLUSTER.'a'.self::CLUSTER, 'mm'.self::CLUSTER.'b'.self::CLUSTER, VideoContainer::Matroska, true],
            'matroska no head cluster' => [substr($mkvHead, 0, (int) strpos($mkvHead, self::CLUSTER)), $mkvMid, VideoContainer::Matroska, false],
            'matroska no mid cluster' => [$mkvHead, 'no cluster here', VideoContainer::Matroska, false],
            'matroska truncated cluster id' => [$mkvHead, "abc\x1F\x43\xB6", VideoContainer::Matroska, false],
            'matroska empty mid' => [$mkvHead, '', VideoContainer::Matroska, false],
            'matroska empty head' => ['', $mkvMid, VideoContainer::Matroska, false],
            'transport stream aligned' => ['', $ts, VideoContainer::MpegTransport, true],
            'transport stream junk prefix' => ['ignored', 'junk!'.$ts, VideoContainer::MpegTransport, true],
            'transport stream sync byte in junk' => ['', "\x47\x47junk".$ts, VideoContainer::MpegTransport, true],
            'transport stream 192-byte packets' => ['', 'xy'.$m2ts, VideoContainer::MpegTransport, true],
            'transport stream offset past one stride' => ['', str_repeat('j', 190).$ts, VideoContainer::MpegTransport, false],
            'transport stream too short' => ['', self::tsPackets(4), VideoContainer::MpegTransport, false],
            'transport stream sync lost' => ['', substr_replace($ts, 'X', 188 * 2, 1), VideoContainer::MpegTransport, false],
            'program stream pack header' => ['', 'tail'.$pack.$pes, VideoContainer::MpegProgram, true],
            'program stream leading zero bytes' => ['', "\x00\x00\x00\x00\x01\xBA".$pes, VideoContainer::MpegProgram, true],
            'program stream sequence header only' => ['', "\x00\x00\x01\xB3".$pes, VideoContainer::MpegProgram, false],
            'program stream truncated pack' => ['', $pes."\x00\x00\x01", VideoContainer::MpegProgram, false],
            'avi movi then video chunk' => [$avi, 'partial-frame'.'00dc'.pack('V', 2).'F1', VideoContainer::Avi, true],
            'avi audio and db chunks' => [$avi, 'xx01wb..02db', VideoContainer::Avi, true],
            'avi rejects non chunk ids' => [$avi, '0adc 01wc 1dc wb ab00', VideoContainer::Avi, false],
            'avi non-ascii digits' => [$avi, "\xB2\xB3dc\xD9\xA0\xD9\xA1wb", VideoContainer::Avi, false],
            'avi movi too early' => ['moviLIST00dc', '00dc', VideoContainer::Avi, false],
            'avi first movi not a list' => ['RIFF....JUNK....movi....LIST....movi', '00dc', VideoContainer::Avi, false],
            'avi no movi' => [substr($avi, 0, 40), '00dc', VideoContainer::Avi, false],
            'avi no chunk in mid' => [$avi, str_repeat("\xFF", 64), VideoContainer::Avi, false],
            'mp4 never splices' => ["\0\0\0\x20ftypisom", 'moof mdat', VideoContainer::Mp4, false],
            'asf never splices' => ["\x30\x26\xB2\x75\x8E\x66\xCF\x11", self::CLUSTER, VideoContainer::Asf, false],
            'ogg never splices' => ['OggS', 'OggS', VideoContainer::Ogg, false],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_fixture_parity(string $head, string $mid, VideoContainer $container, bool $spliced): void
    {
        $this->ffi();
        $result = $this->assertParity($head, $mid, $container);
        self::assertSame($spliced, $result !== null);
    }

    public function test_spliced_matroska_keeps_headers_and_resumes_at_cluster(): void
    {
        $this->ffi();
        $head = self::matroskaHead(2);
        $mid = 'tail-of-a-block'.self::CLUSTER.'MIDDLE';
        $headers = substr($head, 0, (int) strpos($head, self::CLUSTER));

        self::assertSame($headers.self::CLUSTER.'MIDDLE', (new ContainerSplicer)->spliceNative($head, $mid, VideoContainer::Matroska));
    }

    public function test_c_abi_offsets_and_error_contract(): void
    {
        $ffi = $this->ffi();
        $offsets = $ffi->new('size_t[2]');
        $headEnd = FFI::addr($offsets[0]);
        $midStart = FFI::addr($offsets[1]);
        // @phpstan-ignore method.notFound
        $call = static fn (int $code, string $head, string $mid, mixed $headOut, mixed $midOut): int => $ffi->nntmux_container_splice($code, $head, strlen($head), $mid, strlen($mid), $headOut, $midOut);

        self::assertSame(1, $call(1, 'ab'.self::CLUSTER, 'xyz'.self::CLUSTER, $headEnd, $midStart));
        self::assertSame([2, 3], [$offsets[0], $offsets[1]]);
        $avi = self::aviHead();
        self::assertSame(1, $call(2, $avi, '..01wb', $headEnd, $midStart));
        self::assertSame([(int) strpos($avi, 'movi') + 4, 2], [$offsets[0], $offsets[1]]);
        self::assertSame(1, $call(4, 'ignored', 'junk'.self::tsPackets(6), $headEnd, $midStart));
        self::assertSame([0, 4], [$offsets[0], $offsets[1]]);

        $offsets[0] = 77;
        $offsets[1] = 88;
        foreach ([0, 5, 6, 7, 255] as $code) {
            self::assertSame(0, $call($code, self::CLUSTER, self::CLUSTER, $headEnd, $midStart), 'code '.$code);
        }
        self::assertSame(0, $call(1, '', '', $headEnd, $midStart));
        self::assertSame([77, 88], [$offsets[0], $offsets[1]], 'offsets untouched without a splice');
        self::assertSame(-1, $call(1, self::CLUSTER, self::CLUSTER, null, $midStart));
        self::assertSame(-1, $call(1, self::CLUSTER, self::CLUSTER, $headEnd, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_container_splice(1, null, 4, self::CLUSTER, 4, $headEnd, $midStart));
    }

    public function test_falls_back_to_php_when_library_is_unavailable(): void
    {
        $resolved = new ReflectionProperty(ContainerSplicer::class, 'ffiResolved');
        $handle = new ReflectionProperty(ContainerSplicer::class, 'ffi');
        $previous = [$resolved->getValue(), $handle->getValue()];
        $resolved->setValue(null, true);
        $handle->setValue(null, null);
        try {
            $head = self::matroskaHead(1);
            $mid = 'x'.self::CLUSTER.'y';
            $supported = true;
            self::assertSame(self::legacySplice($head, $mid, VideoContainer::Matroska), (new ContainerSplicer)->spliceNative($head, $mid, VideoContainer::Matroska, $supported));
            self::assertFalse($supported);
        } finally {
            $resolved->setValue(null, $previous[0]);
            $handle->setValue(null, $previous[1]);
        }
    }

    public function test_randomized_parity_with_marker_heavy_alphabets(): void
    {
        $this->ffi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $alphabets = [
            VideoContainer::Matroska->value => ["\x1F", "\x43", "\xB6", "\x75", "\x1A", 'x', "\x00"],
            VideoContainer::MpegProgram->value => ["\x00", "\x01", "\xBA", "\xB3", "\xE0", 'p'],
            VideoContainer::MpegTransport->value => ["\x47", "\x00", "\x11", 't'],
            VideoContainer::Avi->value => ['0', '1', '9', 'd', 'w', 'c', 'b', 'a', "\xB2", 'L', 'I', 'S', 'T', 'm', 'o', 'v', 'i'],
        ];
        $markers = [
            VideoContainer::Matroska->value => [self::CLUSTER],
            VideoContainer::MpegProgram->value => [self::PACK],
            VideoContainer::MpegTransport->value => [self::tsPackets(5), self::tsPackets(5, 192)],
            VideoContainer::Avi->value => ['LIST....movi', '00dc', '01wb', '99db'],
        ];
        $hits = 0;
        foreach ([VideoContainer::Matroska, VideoContainer::MpegProgram, VideoContainer::MpegTransport, VideoContainer::Avi] as $container) {
            $alphabet = $alphabets[$container->value];
            for ($case = 0; $case < 400; $case++) {
                $parts = [];
                foreach ([0, 1] as $side) {
                    $length = $random->getInt(0, $case % 10 === 0 ? 4000 : 160);
                    $bytes = '';
                    for ($i = 0; $i < $length; $i++) {
                        $bytes .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
                    }
                    if ($random->getInt(0, 2) > 0) {
                        $choices = $markers[$container->value];
                        $marker = $choices[$random->getInt(0, count($choices) - 1)];
                        $bytes = substr_replace($bytes, $marker, $random->getInt(0, strlen($bytes)), 0);
                    }
                    $parts[$side] = $bytes;
                }
                if ($this->assertParity($parts[0], $parts[1], $container, $container->value.' #'.$case) !== null) {
                    $hits++;
                }
            }
        }
        self::assertGreaterThan(400, $hits, 'random inputs exercise successful splices');
    }

    /**
     * Opt-in reproducible screen: WAVE6_SPLICER_BENCHMARK=1 php artisan test --compact
     * tests/Unit/RustMediaSlicerSplicerParityTest.php --filter=benchmark
     * (WAVE6_SPLICER_BENCHMARK_PAIRS=1000 for tail stability). Each timing covers the caller's
     * whole splice: FFI call, offset readback and the joined copy; input generation is outside.
     * The adoption decision is asserted: Rust stays a candidate unless every workload clears 1.5x.
     */
    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('WAVE6_SPLICER_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $this->ffi();
        ini_set('memory_limit', '2G');
        $random = new Randomizer(new Mt19937(self::SEED));
        $pairs = max(40, min(5000, (int) (getenv('WAVE6_SPLICER_BENCHMARK_PAIRS') ?: 200)));
        $headBytes = 8 << 20;
        $midBytes = 3 << 20;
        $noise = static fn (int $length, string $avoid): string => str_replace($avoid, 'zzzz', $random->getBytes($length));
        $aviNoise = static fn (int $length): string => (string) preg_replace('/\d\d(?:dc|db|wb)/', 'zzzz', $random->getBytes($length));
        $workloads = [
            'matroska_common' => [VideoContainer::Matroska, static fn (): array => [
                substr_replace($noise($headBytes, self::CLUSTER), self::CLUSTER, $random->getInt(2048, 65536), 4),
                substr_replace($noise($midBytes, self::CLUSTER), self::CLUSTER, $random->getInt(0, $midBytes - 8), 4),
            ]],
            'transport_common' => [VideoContainer::MpegTransport, static fn (): array => [
                $noise(65536, "\x47"), $noise($random->getInt(0, 187), "\x47").self::tsPackets(intdiv($midBytes, 188)),
            ]],
            'program_common' => [VideoContainer::MpegProgram, static fn (): array => [
                '', substr_replace($noise($midBytes, self::PACK), self::PACK, $random->getInt(0, 4096), 4),
            ]],
            'avi_common' => [VideoContainer::Avi, static fn (): array => [
                self::aviHead().$random->getBytes($headBytes),
                substr_replace($aviNoise($midBytes), '01wb', $random->getInt(0, 16384), 4),
            ]],
            'matroska_no_mid_cluster_uncommon' => [VideoContainer::Matroska, static fn (): array => [
                substr_replace($noise($headBytes, self::CLUSTER), self::CLUSTER, 4096, 4), $noise($midBytes, self::CLUSTER),
            ]],
            'avi_no_chunk_uncommon' => [VideoContainer::Avi, static fn (): array => [
                self::aviHead().$random->getBytes($headBytes), $aviNoise($midBytes),
            ]],
        ];
        $splicer = new ContainerSplicer;
        $qualifies = true;
        foreach ($workloads as $workload => [$container, $generate]) {
            $inputs = [];
            for ($i = 0; $i < 8; $i++) {
                $inputs[] = $generate();
            }
            // Three untimed warmups per implementation and input; also the parity check.
            foreach ($inputs as [$head, $mid]) {
                $expected = self::legacySplice($head, $mid, $container);
                for ($i = 0; $i < 3; $i++) {
                    self::assertSame($expected, $splicer->splice($head, $mid, $container));
                    self::assertSame($expected, $splicer->spliceNative($head, $mid, $container));
                }
            }
            $samples = [];
            for ($pair = 0; $pair < $pairs; $pair++) {
                [$head, $mid] = $inputs[$pair % count($inputs)];
                $order = $random->getInt(0, 1) === 0 ? ['legacy', 'php', 'rust'] : ['rust', 'php', 'legacy'];
                $sample = ['pair' => $pair, 'order' => $order];
                foreach ($order as $implementation) {
                    $start = hrtime(true);
                    $result = match ($implementation) {
                        'legacy' => self::legacySplice($head, $mid, $container),
                        'php' => $splicer->splice($head, $mid, $container),
                        'rust' => $splicer->spliceNative($head, $mid, $container),
                    };
                    $sample[$implementation] = (hrtime(true) - $start) / 1e3;
                    unset($result);
                }
                $samples[] = $sample;
            }
            $stats = [];
            $p99Index = (int) ceil($pairs * 0.99) - 1;
            foreach (['legacy', 'php', 'rust'] as $implementation) {
                $times = array_column($samples, $implementation);
                sort($times);
                $stats[$implementation] = ['mean_us' => array_sum($times) / $pairs, 'p99_us' => $times[$p99Index]];
            }
            $meanSpeedup = $stats['php']['mean_us'] / $stats['rust']['mean_us'];
            $p99Speedup = $stats['php']['p99_us'] / $stats['rust']['p99_us'];
            $qualifies = $qualifies && $meanSpeedup >= 1.5 && $p99Speedup >= 1.5;
            fwrite(STDOUT, json_encode([
                'workload' => $workload, 'container' => $container->value, 'pairs' => $pairs, 'warmup_each' => 3,
                'seed' => self::SEED, 'php_version' => PHP_VERSION, 'host' => php_uname(), 'jit' => ini_get('opcache.jit'),
                'source_sha256' => [
                    'splicer_php' => hash_file('sha256', __DIR__.'/../../app/Services/AdditionalProcessing/Media/ContainerSplicer.php'),
                    'additional_rs' => hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/src/additional.rs'),
                    'harness' => hash_file('sha256', __FILE__),
                ],
                'stats' => $stats,
                'rust_vs_php' => ['mean' => $meanSpeedup, 'p99' => $p99Speedup],
                'php_vs_legacy' => [
                    'mean' => $stats['legacy']['mean_us'] / $stats['php']['mean_us'],
                    'p99' => $stats['legacy']['p99_us'] / $stats['php']['p99_us'],
                ],
                'raw_samples_us' => getenv('WAVE6_SPLICER_BENCHMARK_RAW') === '1' ? $samples : null,
            ], JSON_THROW_ON_ERROR)."\n");
        }
        self::assertFalse($qualifies, 'Rust cleared 1.5x on every workload: route ContainerSplicer::splice() through spliceNative().');
    }
}
