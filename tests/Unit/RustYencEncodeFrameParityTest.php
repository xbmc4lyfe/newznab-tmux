<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RustYencEncodeFrameParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_yenc_encode(const char *, size_t, const char *, size_t, size_t, uint8_t, char *, size_t);
        int32_t nntmux_yenc_parse_frame(const char *, size_t, size_t *, size_t *, int64_t *, char *, size_t *);
        int32_t nntmux_yenc_frame_spans(const char *, size_t, size_t *);
        ssize_t nntmux_yenc_frame_record(const char *, size_t, uint8_t, char *, size_t);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('yenc_encode_frame_parity', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Build the release pipeline library to exercise native yEnc parity.');
        }
        $this->ffi = $ffi;
    }

    private static function phpEncoder(YencService $service): Closure
    {
        return (new ReflectionMethod($service, 'encodePhp'))->getClosure($service);
    }

    private static function phpParser(): Closure
    {
        return (new ReflectionMethod(ArticleFrame::class, 'parsePhp'))->getClosure();
    }

    public function test_encoding_matches_php_for_every_byte_wrap_crc_and_filename(): void
    {
        $service = new YencService;
        $php = self::phpEncoder($service);
        $random = new Randomizer(new Mt19937(6201));
        $all = implode('', array_map(chr(...), range(0, 255)));
        $inputs = ['', "\0", $all, str_repeat($all, 5), str_repeat(chr(4), 260),
            str_repeat(chr(214).chr(223).chr(224).chr(227).chr(246).chr(4).chr(19), 80)];
        for ($i = 0; $i < 30; $i++) {
            $inputs[] = $random->getBytes($random->getInt(1, 8192));
        }
        foreach ($inputs as $data) {
            foreach ([1, 2, 3, 127, 128, 253, 254, 300, PHP_INT_MAX] as $line) {
                foreach ([false, true] as $crc) {
                    $filename = "\0\t name=odd file \x80.bin\v\0 ";
                    $expected = $php($data, $filename, $line, $crc);
                    self::assertSame($expected, $service->encode($data, $filename, $line, $crc));
                    $capacity = strlen($expected) + 1;
                    $buffer = $this->ffi->new("char[{$capacity}]");
                    // @phpstan-ignore method.notFound
                    $written = $this->ffi->nntmux_yenc_encode($data, strlen($data), $filename, strlen($filename), $line, (int) $crc, $buffer, $capacity);
                    self::assertSame(strlen($expected), $written);
                    self::assertSame($expected, FFI::string($buffer, $written));
                }
            }
        }
        self::assertSame("=ybegin line=1 size=2 name=x\r\n=n\r\n=n\r\n=yend size=2", $service->encode(chr(4).chr(4), 'x', 1, false));
    }

    public function test_validation_and_native_capacity_contracts(): void
    {
        $service = new YencService;
        $php = self::phpEncoder($service);
        foreach ([-100, -1, 0, 1] as $line) {
            foreach (["bad\rname", "bad\nname", 'good'] as $filename) {
                if ($line > 0 && $filename === 'good') {
                    continue;
                }
                $messages = [];
                foreach ([$php, $service->encode(...)] as $encoder) {
                    try {
                        $encoder(str_repeat('A', 300), $filename, $line);
                        self::fail('Invalid encoding arguments must throw.');
                    } catch (RuntimeException $e) {
                        $messages[] = $e->getMessage();
                    }
                }
                self::assertSame($messages[0], $messages[1]);
            }
        }
        $buffer = $this->ffi->new('char[1]');
        $expected = $php(str_repeat('A', 300), 'a');
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen($expected), $this->ffi->nntmux_yenc_encode(str_repeat('A', 300), 300, 'a', 1, 128, 1, $buffer, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_yenc_encode(null, 1, 'a', 1, 128, 1, $buffer, 1));
    }

    public function test_frame_maps_and_source_offsets_match_php_for_malformed_and_multipart_frames(): void
    {
        $php = self::phpParser();
        $service = new YencService;
        $random = new Randomizer(new Mt19937(6202));
        $header = '=ybegin line=128 size=1000 name=a file.bin';
        $frames = ['', '=ybegin', $header, $header."\n=yend", $header."\r\n\r\n=yend size=0", "bad=ybegin line=1 size=1 name=x\n=yend size=0",
            $header."\n=ypart begin=1", $header."\n=ypart\tbegin=1 end=4\nABCD\n=yend size=4 pcrc32=AbC crc32=123",
            "=YBEGIN\tLINE=1 SIZE=4 NAME= a size=999\r\nABCD\r\n=YEND\tSIZE=4 CRC32=AbCd\r\nignored",
            "=ybegin line=1 size=1\nignored\n".$header."\npayload\n=yend size=8",
            $header."\nold\n=ybegin line=2 size=3 name=new\nnew\n=yend size=3",
            $header."\npayload\n=ybegin invalid\n=yend size=8",
            $header."\nnot=yend size=3\n=yendless\n=yend\r",
            "=ybegin line=1 size=1 size=3 name=\nabc\n=yend size=3 size=4 CRC32=0 crc32=deadbeef",
            $header."\n=ypart \n=yend ", $header."\n=yend\n",
            '=ybegin line=128 size=0 name='.str_repeat("binary\0\xff", 1000)."\n=yend size=0",
            '=ybegin line=128 size=0 '.str_repeat('key=a KEY=b ', 1000)."name=x\n=yend size=0",
        ];
        for ($i = 0; $i < 100; $i++) {
            $body = $service->encode($random->getBytes($random->getInt(1, 4096)), 'random file.bin');
            if ($i % 3 === 0) {
                $body = preg_replace('/\r\n/', "\r\n=ypart begin=1 end=9\r\n", $body, 1);
            }
            if ($i % 5 === 0) {
                $body = str_replace('=ybegin', '=YBEGIN', $body);
            }
            $frames[] = "Subject: example\r\n\r\n".$body;
        }
        $spans = $this->ffi->new('size_t[6]');
        $start = $this->ffi->new('size_t[1]');
        $length = $this->ffi->new('size_t[1]');
        $size = $this->ffi->new('int64_t[1]');
        $crc = $this->ffi->new('char[8]');
        $crcLength = $this->ffi->new('size_t[1]');
        $record = $this->ffi->new('char[131072]');
        foreach ($frames as $frame) {
            // Exercise routing above the threshold too, without changing line anchors.
            foreach ([$frame, str_repeat('preamble ', 40)."\n".$frame] as $input) {
                foreach ([true, false] as $trailer) {
                    $expected = $php($input, $trailer);
                    self::assertEquals($expected, ArticleFrame::parse($input, $trailer), bin2hex(substr($input, 0, 100)));
                    // @phpstan-ignore method.notFound
                    $written = $this->ffi->nntmux_yenc_frame_record($input, strlen($input), (int) $trailer, $record, 131072);
                    if ($expected === null) {
                        self::assertSame(0, $written);
                    } else {
                        self::assertGreaterThan(0, $written);
                        self::assertLessThanOrEqual(131072, $written);
                        self::assertSame(serialize($expected), serialize(unserialize(FFI::string($record, $written), ['allowed_classes' => [ArticleFrame::class]])));
                    }
                }
                $expected = $php($input);
                // @phpstan-ignore method.notFound
                $status = $this->ffi->nntmux_yenc_frame_spans($input, strlen($input), $spans);
                self::assertSame($expected === null ? 0 : 1, $status);
                // @phpstan-ignore method.notFound
                self::assertSame($status, $this->ffi->nntmux_yenc_parse_frame($input, strlen($input), $start, $length, $size, $crc, $crcLength));
                if ($expected !== null) {
                    self::assertSame($expected->payloadOffset, $start[0]);
                    self::assertSame($expected->payloadLength, $length[0]);
                    self::assertSame(substr($input, $expected->payloadOffset, $expected->payloadLength), substr($input, $start[0], $length[0]));
                }
            }
        }
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_yenc_parse_frame(null, 1, $start, $length, $size, $crc, $crcLength));
    }

    public function test_advisory_metadata_projection(): void
    {
        $start = $this->ffi->new('size_t[1]');
        $length = $this->ffi->new('size_t[1]');
        $size = $this->ffi->new('int64_t[1]');
        $crc = $this->ffi->new('char[8]');
        $crcLength = $this->ffi->new('size_t[1]');
        foreach ([
            ['', 'size=4 crc32=AbC pcrc32=def', 4, '00000abc'],
            ["=ypart begin=9 end=12\n", 'size=4 pcrc32=AbC crc32=def', 4, '00000abc'],
            ["=ypart begin=9 end=12\n", 'size=bad crc32=AbC', 4, ''],
            ['', 'size=bad crc32=INVALID', 1000, ''],
            ['', 'size=9223372036854775808 crc32=0', 1000, '00000000'],
        ] as [$part, $trailer, $expectedSize, $expectedCrc]) {
            $input = "=ybegin line=128 size=1000 name=a\n".$part."payload\n=yend ".$trailer;
            // @phpstan-ignore method.notFound
            self::assertSame(1, $this->ffi->nntmux_yenc_parse_frame($input, strlen($input), $start, $length, $size, $crc, $crcLength));
            self::assertSame($expectedSize, $size[0]);
            self::assertSame($expectedCrc, $crcLength[0] === 0 ? '' : FFI::string($crc, $crcLength[0]));
        }
    }

    public function test_canonical_field_fast_paths_preserve_full_grammar(): void
    {
        $full = (new ReflectionMethod(ArticleFrame::class, 'fields'))->getClosure();
        $fast = (new ReflectionMethod(ArticleFrame::class, 'nativeFields'))->getClosure();
        $values = ['', '1', '01', "1\tpart=2", "1\0", "1\r", '1 size=2', 'a file name'];
        foreach ($values as $line) {
            foreach ($values as $value) {
                foreach ([" line={$line} size={$value} name=a file\tpart=1\r\r", " size={$value} crc32={$line}\r", " size={$value}", " size={$value} pcrc32={$line}", " begin={$line} end={$value}", " part={$line} total={$value} line={$line} size={$value} name=a file"] as $control) {
                    foreach ([true, false] as $withName) {
                        self::assertSame($full($control, $withName), $fast($control, $withName));
                    }
                }
            }
        }
    }

    public function test_ffi_disabled_fallback_remains_usable(): void
    {
        $root = dirname(__DIR__, 2);
        $script = 'require $argv[1]."/tests/bootstrap.php"; $s = new App\\Services\\YencService; $data = str_repeat("binary\\0", 80); $body = $s->encode($data, "fallback.bin"); $f = App\\Services\\Yenc\\ArticleFrame::parse($body); if ($f === null || $s->decode($body) !== $data) { exit(1); } echo "fallback-ok";';
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=false', '-r', $script, $root], $root);
        $process->mustRun();
        self::assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Real service calls, 16 warmups and 1,000 randomized pairs per path/size.
     * Reproduce with the release library and --filter=test_paired_service_benchmarks.
     * Raw nanoseconds, fixture/source/library hashes and runtime details survive in the system temp directory.
     */
    public function test_paired_service_benchmarks_meet_mean_and_p99_adoption_gates(): void
    {
        $service = new YencService;
        $phpEncode = self::phpEncoder($service);
        $phpParse = self::phpParser();
        $random = new Randomizer(new Mt19937(6203));
        $root = dirname(__DIR__, 2);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $artifact = tempnam(sys_get_temp_dir(), 'wave6-yenc-pairs-');
        self::assertNotFalse($artifact);
        $log = fopen($artifact, 'wb');
        self::assertNotFalse($log);
        fwrite($log, json_encode(['type' => 'protocol', 'seed' => 6203, 'pairs' => 1000, 'warmups' => 16,
            'php' => PHP_VERSION, 'uname' => php_uname(),
            'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim((new Process(['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.ncpu']))->mustRun()->getOutput()) : php_uname('m'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'library_sha256' => hash_file('sha256', $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix),
            'base_commit' => trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()),
            'rustc' => trim((new Process(['rustc', '--version']))->mustRun()->getOutput()),
            'profile' => 'cargo build --release; opt-level=3; lto=true; codegen-units=1',
            'composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
            'vendor_manifest_sha256' => hash_file('sha256', $root.'/vendor/composer/installed.json'),
            'sources' => array_combine(['nfo.rs', 'YencService.php', 'ArticleFrame.php'], array_map(hash_file(...), array_fill(0, 3, 'sha256'), [$root.'/rust/pipeline-ffi/src/nfo.rs', $root.'/app/Services/YencService.php', $root.'/app/Services/Yenc/ArticleFrame.php'])),
        ], JSON_THROW_ON_ERROR)."\n");
        $summaries = [];
        try {
            $fixtures = [];
            foreach ([4096, 16384, 65536, 262144, 524288] as $bytes) {
                $data = $random->getBytes($bytes);
                $fixtures[] = ['ordinary', $data, $phpEncode($data, 'benchmark.bin'), ['encode', 'parse', 'parse_payload_only']];
            }
            $heavy = substr(str_repeat(chr(214).chr(223).chr(224).chr(227).chr(246).chr(4).chr(19), 74900), 0, 524288);
            $fixtures[] = ['escape_dense', $heavy, $phpEncode($heavy, 'benchmark.bin'), ['encode', 'parse', 'parse_payload_only']];
            $multipart = str_replace('=ybegin ', '=ybegin part=1 total=2 ', $fixtures[0][2]);
            $headerEnd = strpos($multipart, "\n");
            self::assertNotFalse($headerEnd);
            $multipart = substr_replace($multipart, "=ypart begin=1 end=4096\r\n", $headerEnd + 1, 0);
            $multipart = str_replace(' crc32=', ' pcrc32=', $multipart);
            $fixtures[] = ['multipart', $fixtures[0][1], $multipart, ['parse', 'parse_payload_only']];
            $nested = substr_replace($fixtures[4][2], "\r\n=ybegin invalid\r\n", 65536, 0);
            $fixtures[] = ['nested_invalid', $fixtures[4][1], $nested, ['parse']];
            $fixtures[] = ['unframed', $fixtures[4][1], str_repeat("plain body\r\n", 43691), ['parse']];
            foreach ($fixtures as [$workload, $data, $body, $paths]) {
                $bytes = strlen($data);
                self::assertSame($phpEncode($data, 'benchmark.bin'), $service->encode($data, 'benchmark.bin'));
                self::assertEquals($phpParse($body), ArticleFrame::parse($body));
                foreach ($paths as $path) {
                    $baseline = $path === 'encode' ? fn () => $phpEncode($data, 'benchmark.bin') : fn () => $phpParse($body, $path !== 'parse_payload_only');
                    $native = $path === 'encode' ? fn () => $service->encode($data, 'benchmark.bin') : fn () => ArticleFrame::parse($body, $path !== 'parse_payload_only');
                    for ($i = 0; $i < 16; $i++) {
                        $baseline();
                        $native();
                    }
                    $samples = ['php' => [], 'rust' => []];
                    for ($i = 0; $i < 1000; $i++) {
                        $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                        foreach ($order as $side) {
                            $call = $side === 'php' ? $baseline : $native;
                            $start = hrtime(true);
                            $result = $call();
                            $elapsed = hrtime(true) - $start;
                            $samples[$side][] = $elapsed;
                            unset($result);
                        }
                        fwrite($log, json_encode(['type' => 'pair', 'path' => $path, 'workload' => $workload, 'bytes' => $bytes, 'pair' => $i, 'order' => $order, 'php_ns' => $samples['php'][$i], 'rust_ns' => $samples['rust'][$i]], JSON_THROW_ON_ERROR)."\n");
                    }
                    $means = [];
                    $p99 = [];
                    $p99Bounds = [];
                    foreach ($samples as $side => $values) {
                        $means[$side] = array_sum($values) / count($values);
                        sort($values);
                        $p99[$side] = $values[989];
                        // Approximate 95% rank interval around the population p99.
                        $p99Bounds[$side] = [$values[982], $values[995]];
                    }
                    $summary = ['type' => 'summary', 'path' => $path, 'workload' => $workload, 'bytes' => $bytes, 'fixture_sha256' => hash('sha256', $data), 'body_sha256' => hash('sha256', $body), 'means_ns' => $means, 'p99_ns' => $p99, 'p99_rank_interval_ns' => $p99Bounds, 'mean_speedup' => $means['php'] / $means['rust'], 'p99_speedup' => $p99['php'] / $p99['rust']];
                    $summaries[] = $summary;
                    fwrite($log, json_encode($summary, JSON_THROW_ON_ERROR)."\n");
                }
            }
            $failedGates = [];
            foreach ($summaries as $row) {
                if ($row['mean_speedup'] < 1.5 || $row['p99_speedup'] < 1.5) {
                    $failedGates[] = $row['workload'].' '.$row['path'].' '.$row['bytes'];
                }
            }
            fwrite($log, json_encode(['type' => 'verdict', 'accepted' => $failedGates === [], 'failed_gates' => $failedGates], JSON_THROW_ON_ERROR)."\n");
        } catch (\Throwable $error) {
            fwrite($log, json_encode(['type' => 'failure', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n");
            throw $error;
        } finally {
            fclose($log);
            fwrite(STDERR, "\nyEnc paired benchmark evidence: {$artifact}\n");
        }
        foreach ($summaries as $summary) {
            $label = $summary['path'].' '.$summary['workload'].' '.$summary['bytes'].' bytes; evidence '.$artifact;
            self::assertGreaterThanOrEqual(1.5, $summary['mean_speedup'], 'mean '.$label);
            self::assertGreaterThanOrEqual(1.5, $summary['p99_speedup'], 'p99 '.$label);
        }
    }
}
