<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Irc\PreAnnounceParser;
use App\Services\Predb\Stream\WebSocketClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RustWebSocketPreAnnounceParityTest extends TestCase
{
    public function test_frames_match_php_at_length_boundaries_and_all_mask_alignments(): void
    {
        $encode = new ReflectionMethod(WebSocketClient::class, 'encodeFrameNative');
        $decode = new ReflectionMethod(WebSocketClient::class, 'decodeFrameNative');
        $phpEncode = new ReflectionMethod(WebSocketClient::class, 'encodeFramePhp');
        $phpDecode = new ReflectionMethod(WebSocketClient::class, 'decodeFramePhp');
        $mask = "\x00\x81\xfe\x7f";
        foreach ([0, 1, 7, 8, 9, 15, 16, 125, 126, 127, 128, 4095, 4096, 65535, 65536, 262144, 4194304] as $length) {
            $payload = substr(str_repeat("\x00\xffabc123", intdiv($length, 8) + 1), 0, $length);
            foreach ([0, 1, 2, 8, 9, 10, 255] as $opcode) {
                $frame = $phpEncode->invoke(null, $opcode, $payload, $mask);
                self::assertSame($frame, $encode->invoke(null, $opcode, $payload, $mask));
                self::assertSame($frame, WebSocketClient::encodeFrame($opcode, $payload, $mask));
                self::assertSame($phpDecode->invoke(null, $frame.'tail'), $decode->invoke(null, $frame.'tail'));
                self::assertSame($phpDecode->invoke(null, $frame), WebSocketClient::decodeFrame($frame));
            }
        }
        // Literal RFC example independently checks both the header and mask order.
        self::assertSame(hex2bin('818537fa213d7f9f4d5158'), $encode->invoke(null, 1, 'Hello', hex2bin('37fa213d')));
        foreach (["\x89\x02hb", "\x01\x03abc", "\x80\x00", "\x82\x7e\x00\x03abc", "\x81\x7f".pack('J', 3).'abc'] as $frame) {
            self::assertSame($phpDecode->invoke(null, $frame), $decode->invoke(null, $frame));
        }
        foreach (['', 'x', 'abcde'] as $oddMask) {
            self::assertSame($phpEncode->invoke(null, 1, 'payload', $oddMask), WebSocketClient::encodeFrame(1, 'payload', $oddMask));
        }
    }

    public function test_coalesced_small_frames_do_not_initialize_ffi(): void
    {
        $state = new ReflectionProperty(WebSocketClient::class, 'native');
        $previous = $state->getValue();
        $state->setValue(null, null);
        try {
            $php = new ReflectionMethod(WebSocketClient::class, 'encodeFramePhp');
            foreach ([125, 128, 4088, 4095] as $length) {
                $frame = $php->invoke(null, 1, str_repeat('x', $length), 'abcd');
                $decoded = WebSocketClient::decodeFrame($frame.str_repeat("\x80\0", 4096));
                self::assertSame([true, 1, str_repeat('x', $length), strlen($frame)], $decoded);
                self::assertNull($state->getValue());
            }
            // Non-minimal 64-bit encoding of a tiny masked payload also stays in PHP.
            self::assertSame([true, 1, 'x', 15], WebSocketClient::decodeFrame("\x81\xff".pack('J', 1).'abcd'.chr(ord('x') ^ ord('a')).str_repeat("\x80\0", 4096)));
            self::assertNull($state->getValue());
        } finally {
            $state->setValue(null, $previous);
        }
    }

    public function test_incomplete_and_rejected_frames_preserve_status_and_errors(): void
    {
        $decode = new ReflectionMethod(WebSocketClient::class, 'decodeFrameNative');
        $php = new ReflectionMethod(WebSocketClient::class, 'decodeFramePhp');
        foreach (["\x81\x80abcd", "\x81\xfe\x00\x03abcdfoo", "\x81\xff".pack('J', 3).'abcdfoo'] as $frame) {
            for ($i = 0; $i < strlen($frame); $i++) {
                self::assertNull($decode->invoke(null, substr($frame, 0, $i)));
            }
        }
        foreach ([
            ["\x81\x7f\x80".str_repeat("\0", 7), 'WebSocket frame length has the reserved high bit set.'],
            ["\x81\xff".pack('J', 4194305), 'WebSocket frame of 4194305 bytes exceeds the limit.'],
            ["\x81\x7f".pack('J', PHP_INT_MAX), 'WebSocket frame of '.PHP_INT_MAX.' bytes exceeds the limit.'],
        ] as [$frame, $error]) {
            foreach ([static fn () => $decode->invoke(null, $frame), static fn () => $php->invoke(null, $frame), static fn () => WebSocketClient::decodeFrame($frame)] as $call) {
                try {
                    $call();
                    self::fail('Expected length rejection');
                } catch (RuntimeException $exception) {
                    self::assertSame($error, $exception->getMessage());
                }
            }
        }
    }

    /** @return list<string> */
    public static function announces(): array
    {
        return [
            'PRE: [TV-HD] Example.S01E01.1080p-GRP', 'INFO: Example-GRP [14F 0.5MB]',
            '(pre) (MP3-WEB) (Example-(LIVE)-GRP)', '(INFO) (Example-GRP) (0 Files) (38 MB)',
            'pre | TV-HD | Example-GRP', 'info | Example-GRP - 14 F & 67 MB',
            'p2p | #1 | BTN | Example-GRP, WIN!', 'p2p | #2 | BTN | Example-GRP, WIN!',
            '.::: PRE ::: TV-X264-HD ::: Example-GRP :::.', '[INFO] FOR Example-GRP with 5F 158.3MB added.',
            '[PRE]  [TV-HD]  Example-GRP', 'gibberish', '', 'PRE: [] Example-GRP',
            'PRE: [TV] nodash', 'PRE: [TV] '.str_repeat('a', 252).'-GRP',
            'PRE: [TV] Léon_天空-GRP', "PRE: [TV] Bad\xff-GRP", "PRE: [TV] Example-GRP\n",
            "\0PRE: [TV] Example-GRP\0", "PRE: [TV] Example-GRP\r", "PRE: [TV] Example\0-GRP",
            'PRE: [TV] First-GRP', '', '',
        ];
    }

    public function test_announces_and_batches_preserve_fields_null_slots_and_unicode_fallback(): void
    {
        $native = new ReflectionMethod(PreAnnounceParser::class, 'parseNative');
        $php = new ReflectionMethod(PreAnnounceParser::class, 'parsePhp');
        $batchNative = new ReflectionMethod(PreAnnounceParser::class, 'parseBatchNative');
        $at = CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC');
        $lines = self::announces();
        foreach (['NUKE', 'UNNUKE', 'MODNUKE', 'RENUKE', 'OLDNUKE'] as $type) {
            array_push($lines, "$type: Example-GRP [ bad.reason ] [NUKENET]", "($type) (Example-GRP) (reason)", "[$type] Example-GRP []");
        }
        foreach (PreAnnounceParser::FORMATS as $format) {
            $parser = new PreAnnounceParser($format, 'src');
            $expected = [];
            foreach ($lines as $line) {
                $entry = $php->invoke($parser, $line, $at);
                $expected[] = $entry;
                $candidate = $native->invoke($parser, $line, $at);
                if ($candidate !== false) {
                    self::assertEquals($entry, $candidate, $format.' '.bin2hex($line));
                }
                self::assertEquals($entry, $parser->parse($line, $at));
            }
            self::assertEquals($expected, $parser->parseBatch($lines, $at));
            self::assertSame([], $parser->parseBatch([], $at));
            self::assertSame([null], $parser->parseBatch([''], $at));
            // Exercise native batch transport independently of embedded NUL fallback.
            $plain = array_values(array_filter($lines, static fn (string $s): bool => ! str_contains($s, "\0")));
            self::assertEquals(array_map(static fn (string $s) => $php->invoke($parser, $s, $at), $plain), $batchNative->invoke($parser, $plain, $at));
            self::assertSame([null, null], $batchNative->invoke($parser, ['', ''], $at));
            self::assertSame([], $batchNative->invoke($parser, [], $at));
            self::assertNull($batchNative->invoke($parser, ["PRE: [TV] X\0-GRP"], $at));
        }
        $entry = $native->invoke(new PreAnnounceParser('opentrackers', 'ignored'), '[PRE] [TV] Example-GRP', $at);
        self::assertIsObject($entry);
        self::assertSame('opentrackers-relay', $entry->source);
        self::assertNull($entry->predate);
        self::assertEquals($at, $entry->listedAt);
    }

    public function test_mutated_announce_grammars_match_php(): void
    {
        $native = new ReflectionMethod(PreAnnounceParser::class, 'parseNative');
        $php = new ReflectionMethod(PreAnnounceParser::class, 'parsePhp');
        $batch = new ReflectionMethod(PreAnnounceParser::class, 'parseBatchNative');
        $at = CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC');
        mt_srand(20261025);
        $seeds = self::announces();
        $cases = [];
        $alphabet = "()[]| :,-_aAbB123\t\v\f\0\r\n";
        for ($i = 0; $i < 1200; $i++) {
            $line = $seeds[$i % count($seeds)];
            for ($j = 0; $j < 3; $j++) {
                $line = substr_replace($line, $alphabet[mt_rand(0, strlen($alphabet) - 1)], mt_rand(0, strlen($line)), mt_rand(0, 1));
            }
            $cases[] = $line;
        }
        $cases[] = '[NUKE] Example-GRP ['.str_repeat('reason.', 1024).']';
        foreach (PreAnnounceParser::FORMATS as $format) {
            $parser = new PreAnnounceParser($format, 'src');
            foreach ($cases as $line) {
                $expected = $php->invoke($parser, $line, $at);
                $candidate = $native->invoke($parser, $line, $at);
                if ($candidate !== false) {
                    self::assertEquals($expected, $candidate, $format.' '.bin2hex($line));
                }
                self::assertEquals($expected, $parser->parse($line, $at));
            }
            $plain = array_values(array_filter($cases, static fn (string $s): bool => ! str_contains($s, "\0")));
            self::assertEquals(array_map(static fn (string $s) => $php->invoke($parser, $s, $at), $plain), $batch->invoke($parser, $plain, $at));
        }
    }

    public function test_automatic_receipt_times_keep_relay_observation_semantics(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
        try {
            $php = new ReflectionMethod(PreAnnounceParser::class, 'parsePhp');
            $native = new ReflectionMethod(PreAnnounceParser::class, 'parseNative');
            foreach (PreAnnounceParser::FORMATS as $format) {
                $parser = new PreAnnounceParser($format, 'src');
                foreach (self::announces() as $line) {
                    $candidate = $native->invoke($parser, $line);
                    if ($candidate !== false) {
                        self::assertEquals($php->invoke($parser, $line), $candidate);
                    }
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ffi_disabled_retains_php_behavior(): void
    {
        $code = <<<'PHP'
            require getcwd().'/tests/bootstrap.php';
            $payload = str_repeat('hello', 14000);
            $frame = App\Services\Predb\Stream\WebSocketClient::encodeFrame(1, $payload, 'abcd');
            if (App\Services\Predb\Stream\WebSocketClient::decodeFrame($frame)[2] !== $payload) { exit(1); }
            $parser = new App\Services\Predb\Irc\PreAnnounceParser('opentrackers', 'src');
            $entries = $parser->parseBatch(['[PRE] [TV] Example-GRP', '', 'PRE: [TV] Léon-GRP']);
            if ($entries[0]->title !== 'Example-GRP' || $entries[1] !== null || $entries[2]->title !== 'Léon-GRP') { exit(2); }
            echo 'fallback-ok';
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Reproduce: NNTMUX_WS_BENCH=1 php artisan test --compact
     * tests/Unit/RustWebSocketPreAnnounceParityTest.php --filter=benchmark
     *
     * Protocol: 40 paired runs, seeded random order, 256 warmup calls, 128 timed
     * caller invocations per side per pair. Individual latency p99 uses 5120
     * samples per side. Raw paired mean/p99 observations are emitted as JSON with NNTMUX_WS_BENCH_RAW=1.
     * Correctness is checked before timing. No sockets or production resources.
     */
    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_WS_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired microbenchmark.');
        }
        mt_srand(20261024);
        $workloads = [];
        foreach ([128, 4096, 65536, 262144] as $length) {
            $prefix = '{"name":"Example.Release-GRP","pad":"';
            $payload = $prefix.str_repeat('a', $length - strlen($prefix) - 2).'"}';
            $mask = "\x81\0\xfe\x7f";
            $encodePhp = (new ReflectionMethod(WebSocketClient::class, 'encodeFramePhp'))->getClosure();
            $decodePhp = (new ReflectionMethod(WebSocketClient::class, 'decodeFramePhp'))->getClosure();
            $encodeNative = (new ReflectionMethod(WebSocketClient::class, 'encodeFrameNative'))->getClosure();
            $decodeNative = (new ReflectionMethod(WebSocketClient::class, 'decodeFrameNative'))->getClosure();
            $frame = $encodePhp(1, $payload, $mask);
            $server = "\x81".($length < 65536 ? "\x7e".pack('n', $length) : "\x7f".pack('J', $length)).$payload;
            $workloads['decodeUnmasked/'.$length] = [static fn () => $decodePhp($server), static fn () => WebSocketClient::decodeFrame($server)];
            $workloads['encode/'.$length] = [static fn () => $encodePhp(1, $payload, $mask), static fn () => WebSocketClient::encodeFrame(1, $payload, $mask)];
            $workloads['decode/'.$length] = [static fn () => $decodePhp($frame), static fn () => WebSocketClient::decodeFrame($frame)];
            $workloads['encodeNative/'.$length] = [static fn () => $encodePhp(1, $payload, $mask), static fn () => $encodeNative(1, $payload, $mask)];
            $workloads['decodeNative/'.$length] = [static fn () => $decodePhp($frame), static fn () => $decodeNative($frame)];
        }
        $small = (new ReflectionMethod(WebSocketClient::class, 'encodeFramePhp'))->getClosure()(1, str_repeat('x', 128), 'abcd').str_repeat("\x80\0", 4096);
        $php = (new ReflectionMethod(WebSocketClient::class, 'decodeFramePhp'))->getClosure();
        $workloads['decodeCoalesced/128'] = [static fn () => $php($small), static fn () => WebSocketClient::decodeFrame($small)];
        $lines = ['PRE: [TV] Example-GRP', '(PRE) (TV) (Example-GRP)', 'pre | TV | Example-GRP', '.::: PRE ::: TV ::: Example-GRP :::.', '[PRE] [TV] Example-GRP', '[PRE] [TV] Example-GRP'];
        $at = CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC');
        foreach (PreAnnounceParser::FORMATS as $i => $format) {
            $parser = new PreAnnounceParser($format, 'src');
            $php = (new ReflectionMethod($parser, 'parsePhp'))->getClosure($parser);
            $native = (new ReflectionMethod($parser, 'parseNative'))->getClosure($parser);
            $line = $lines[$i];
            $workloads['parseNative/'.$format] = [static fn () => $php($line, $at), static fn () => $native($line, $at)];
            $workloads['parseNow/'.$format] = [static fn () => $php($line, $at), static fn () => $parser->parse($line, $at)];
            $batch = array_fill(0, 64, $line);
            $nativeBatch = (new ReflectionMethod($parser, 'parseBatchNative'))->getClosure($parser);
            $workloads['batchNative/'.$format] = [static fn () => array_map(static fn (string $s) => $php($s, $at), $batch), static fn () => $nativeBatch($batch, $at)];
            $workloads['batch/'.$format] = [static fn () => array_map(static fn (string $s) => $php($s, $at), $batch), static fn () => $parser->parseBatch($batch, $at)];
        }
        $parser = new PreAnnounceParser('opentrackers', 'src');
        $php = (new ReflectionMethod($parser, 'parsePhp'))->getClosure($parser);
        foreach (['info' => 'INFO: Example-GRP [14F 67MB]', 'nuke' => '[NUKE] Example-GRP [bad.audio]', 'miss' => 'unmatched noise', 'corruptnet' => $lines[0], 'zenet' => $lines[1], 'predatabase' => $lines[2], 'long_reason' => '[NUKE] Example-GRP ['.str_repeat('reason.', 1024).']'] as $label => $line) {
            $workloads['relay/'.$label] = [static fn () => $php($line, $at), static fn () => $parser->parse($line, $at)];
        }
        foreach ([1, 4, 16, 64] as $count) {
            $batch = array_fill(0, $count, $lines[4]);
            $workloads['relayBatch/'.$count] = [static fn () => array_map(static fn (string $s) => $php($s, $at), $batch), static fn () => $parser->parseBatch($batch, $at)];
        }
        $percentile = static function (array $values): float {
            sort($values);

            return (float) $values[(int) ceil(count($values) * .99) - 1];
        };
        $report = ['protocol' => ['seed' => 20261024, 'pairs' => 40, 'calls_per_pair' => 128, 'warmup' => 256, 'php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'baseline_commit' => 'c8ba96324e0e64cf29d2c435183efb88ccf53dec', 'source_sha256' => array_map('hash_file', array_fill(0, 4, 'sha256'), ['app/Services/Predb/Stream/WebSocketClient.php', 'app/Services/Predb/Irc/PreAnnounceParser.php', 'rust/pipeline-ffi/src/irc.rs', 'tests/Unit/RustWebSocketPreAnnounceParityTest.php']), 'library_sha256' => hash_file('sha256', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'), 'composer_lock_sha256' => hash_file('sha256', 'composer.lock'), 'cargo_lock_sha256' => hash_file('sha256', 'rust/pipeline-ffi/Cargo.lock')], 'workloads' => []];
        foreach ($workloads as $name => [$baseline, $candidate]) {
            self::assertEquals($baseline(), $candidate(), $name);
            for ($i = 0; $i < 256; $i++) {
                $baseline();
                $candidate();
            }
            $samples = [[], []];
            $pairs = [];
            for ($run = 0; $run < 40; $run++) {
                $order = mt_rand(0, 1) ? [0, 1] : [1, 0];
                $pair = [];
                foreach ($order as $side) {
                    $call = $side === 0 ? $baseline : $candidate;
                    $latencies = [];
                    for ($i = 0; $i < 128; $i++) {
                        $start = hrtime(true);
                        $call();
                        $latencies[] = hrtime(true) - $start;
                    }
                    array_push($samples[$side], ...$latencies);
                    $pair[$side] = [array_sum($latencies) / count($latencies), $percentile($latencies)];
                }
                $pairs[] = [$pair[0], $pair[1]];
            }
            $mean = array_sum($samples[0]) / array_sum($samples[1]);
            $p99 = $percentile($samples[0]) / $percentile($samples[1]);
            $report['workloads'][$name] = ['mean_speedup' => $mean, 'p99_speedup' => $p99, 'pairs_mean_p99_ns' => $pairs];
            fwrite(STDOUT, sprintf("BENCH %s mean=%.3fx p99=%.3fx\n", $name, $mean, $p99));
            if (getenv('NNTMUX_WS_BENCH_RAW') === '1') {
                fwrite(STDOUT, 'BENCH_PAIR_RAW '.json_encode([$name => $report['workloads'][$name]], JSON_THROW_ON_ERROR)."\n");
            }
        }
        if (getenv('NNTMUX_WS_BENCH_RAW') === '1') {
            fwrite(STDOUT, 'BENCH_PROTOCOL '.json_encode($report['protocol'], JSON_THROW_ON_ERROR)."\n");
        }
    }
}
