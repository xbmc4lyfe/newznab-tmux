<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\RustVerificationFramer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RustVerificationFramerTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_persistent_worker_keeps_connection_positions_and_binary_bodies(): void
    {
        $binary = $this->fixture(<<<'PHP'
if ($init !== ['connections' => [['ids' => ['a@b', 'c@d'], 'mode' => 'body'], ['ids' => ['e@f'], 'mode' => 'stat']], 'maximum_bytes' => 1024, 'buffer_bytes' => 4096]) { exit(2); }
fwrite(STDOUT, "\0");
if (feed() !== [1, 'stat']) { exit(3); }
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'present', 'length' => 0]]]);
if (feed() !== [0, 'first']) { exit(4); }
reply(['done' => false, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 4]]], "a\0\r\n");
if (feed() !== [0, 'last']) { exit(5); }
reply(['done' => true, 'error' => null, 'results' => [['index' => 1, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [
            ['ids' => ['a@b', 'c@d'], 'mode' => 'body'], ['ids' => ['e@f'], 'mode' => 'stat'],
        ], 1024, 4096, static function (): void {});

        self::assertSame(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'present', 'body' => '']]], $worker->feed(1, 'stat'));
        self::assertSame(['done' => false, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'body' => "a\0\r\n"]]], $worker->feed(0, 'first'));
        self::assertSame(['done' => true, 'error' => null, 'results' => [['index' => 1, 'status' => 'missing', 'body' => '']]], $worker->feed(0, 'last'));
    }

    public function test_terminal_connection_error_preserves_completed_bodies_and_other_connections(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => true, 'error' => 'damaged frame', 'results' => [['index' => 0, 'status' => 'body', 'length' => 2]]], 'ok');
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [
            ['ids' => ['a@b', 'c@d'], 'mode' => 'body'], ['ids' => ['e@f'], 'mode' => 'body'],
        ], 1024, 4096, static function (): void {});
        self::assertSame(['done' => true, 'error' => 'damaged frame', 'results' => [['index' => 0, 'status' => 'body', 'body' => 'ok']]], $worker->feed(0, 'x'));
        self::assertSame('missing', $worker->feed(1, 'y')['results'][0]['status']);
    }

    public function test_fragmented_large_feed_and_body_do_not_deadlock_on_pipe_capacity(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
if (feed() !== [0, str_repeat('x', 65536)]) { exit(2); }
$metadata = json_encode(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 100000]]]);
$wire = pack('N', strlen($metadata)).$metadata.str_repeat('z', 100000);
while ($wire !== '') { $n = fwrite(STDOUT, substr($wire, 0, 257)); $wire = substr($wire, $n); }
PHP);
        $worker = $this->worker($binary, maximumBytes: 100000, bufferBytes: 100000);
        self::assertSame(str_repeat('z', 100000), $worker->feed(0, str_repeat('x', 65536))['results'][0]['body']);
    }

    public function test_heartbeat_runs_during_silent_ipc_waits(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
usleep(450000);
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $recorder = new class
        {
            /** @var list<int> */
            public array $times = [];

            public function pulse(): void
            {
                $this->times[] = hrtime(true);
            }

            /** @return list<int> */
            public function values(): array
            {
                return $this->times;
            }
        };
        $worker = $this->worker($binary, heartbeat: $recorder->pulse(...));
        $recorder->times = [];
        self::assertTrue($worker->feed(0, 'x')['done']);
        $times = $recorder->values();
        self::assertGreaterThanOrEqual(3, count($times));
        foreach (array_slice($times, 1) as $index => $time) {
            self::assertLessThan(1_000_000_000, $time - $times[$index]);
        }
    }

    #[DataProvider('malformedReplies')]
    public function test_invalid_worker_output_is_a_sticky_failure(string $wire): void
    {
        $binary = $this->fixture('fwrite(STDOUT, "\\0"); feed(); fwrite(STDOUT, base64_decode('.var_export(base64_encode($wire), true).'));');
        $worker = $this->worker($binary);
        $this->assertFeedFails($worker);
        $this->assertFeedFails($worker);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedReplies(): iterable
    {
        yield 'oversized metadata' => [pack('N', 65537)];
        yield 'empty metadata' => [pack('N', 0)];
        yield 'truncated metadata' => [pack('N', 10).'{}'];
        yield 'invalid JSON' => [pack('N', 1).'{'];
        foreach ([
            'wrong done type' => ['done' => 1, 'error' => null, 'results' => []],
            'missing error' => ['done' => false, 'results' => []],
            'wrong error type' => ['done' => false, 'error' => 1, 'results' => []],
            'wrong index' => ['done' => true, 'error' => null, 'results' => [['index' => 1, 'status' => 'body', 'length' => 0]]],
            'negative body' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => -1]]],
            'oversized body' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 1025]]],
            'body exceeds absolute ceiling' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 16777217]]],
            'missing with body' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 1]]],
            'STAT status in BODY mode' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'present', 'length' => 0]]],
            'unknown status' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'complete', 'length' => 0]]],
            'duplicate result' => ['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0], ['index' => 0, 'status' => 'missing', 'length' => 0]]],
            'premature done' => ['done' => true, 'error' => null, 'results' => []],
        ] as $name => $metadata) {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
            yield $name => [pack('N', strlen($json)).$json];
        }
        $json = '{"done":true,"error":null,"results":[{"index":0,"status":"body","length":4}]}';
        yield 'truncated body' => [pack('N', strlen($json)).$json.'ab'];
    }

    public function test_completed_positions_cannot_be_replayed_in_a_later_reply(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => false, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [['ids' => ['a@b', 'c@d'], 'mode' => 'body']], 1024, 4096, static function (): void {});
        self::assertFalse($worker->feed(0, 'x')['done']);
        $this->assertFeedFails($worker);
    }

    public function test_cancellation_during_a_wait_closes_the_worker_without_retry(): void
    {
        $binary = $this->fixture('fwrite(STDOUT, "\\0"); feed(); sleep(20);');
        $state = new class
        {
            public bool $cancel = false;
        };
        $worker = $this->worker($binary, heartbeat: static function () use ($state): void {
            if ($state->cancel) {
                throw new RuntimeException('Lease lost fixture.');
            }
        });
        $state->cancel = true;
        $this->assertFeedFails($worker);
        $this->assertFeedFails($worker);
    }

    public function test_destructor_bounds_cleanup_of_a_noncooperative_owned_child(): void
    {
        $pidPath = $this->path();
        $binary = $this->fixture('file_put_contents('.var_export($pidPath, true).', getmypid()); fwrite(STDOUT, "\\0"); while (true) { usleep(100000); }');
        $worker = $this->worker($binary);
        $pid = (int) file_get_contents($pidPath);
        $started = hrtime(true);
        unset($worker);
        self::assertLessThan(1_000_000_000, hrtime(true) - $started);
        if (function_exists('posix_kill')) {
            self::assertFalse(@posix_kill($pid, 0));
        }
    }

    public function test_invalid_feed_is_refused_before_the_child_receives_it(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
if (feed() !== [0, 'valid']) { exit(2); }
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = $this->worker($binary);
        foreach ([[-1, 'x'], [1, 'x'], [0, str_repeat('x', 65537)]] as [$index, $chunk]) {
            try {
                $worker->feed($index, $chunk);
            } catch (RuntimeException) {
                continue;
            }
            self::fail('Invalid feed reached the worker.');
        }
        self::assertTrue($worker->feed(0, 'valid')['done']);
    }

    public function test_init_metadata_is_bounded_before_starting_the_child(): void
    {
        $started = $this->path();
        $binary = $this->fixture('file_put_contents('.var_export($started, true).', "started"); fwrite(STDOUT, "\\0");');
        try {
            new RustVerificationFramer($binary, [['ids' => [str_repeat('x', 131072)], 'mode' => 'body']], 1024, 4096, static function (): void {});
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($started);

            return;
        }
        self::fail('Oversized init was accepted.');
    }

    public function test_init_metadata_between_sixty_four_and_one_hundred_twenty_eight_kib_is_supported(): void
    {
        $binary = $this->fixture(<<<'PHP'
if (strlen($init['connections'][0]['ids'][0]) !== 100000) { exit(2); }
fwrite(STDOUT, "\0");
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [['ids' => [str_repeat('x', 100000)], 'mode' => 'body']], 1024, 4096, static function (): void {});
        self::assertTrue($worker->feed(0, 'x')['done']);
    }

    public function test_child_eof_after_a_complete_reply_is_a_sticky_failure_on_the_next_feed(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => false, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [['ids' => ['a@b', 'c@d'], 'mode' => 'body']], 1024, 4096, static function (): void {});
        self::assertSame(['index' => 0, 'status' => 'body', 'body' => ''], $worker->feed(0, 'x')['results'][0]);
        $this->assertFeedFails($worker);
        $this->assertFeedFails($worker);
    }

    public function test_normal_eof_shuts_down_the_child_before_sending_a_signal(): void
    {
        $exited = $this->path();
        $binary = $this->fixture('fwrite(STDOUT, "\\0"); while (! feof(STDIN)) { fread(STDIN, 1024); } file_put_contents('.var_export($exited, true).', "eof");');
        $worker = $this->worker($binary);
        unset($worker);
        self::assertSame('eof', file_get_contents($exited));
    }

    public function test_missing_binary_and_failed_handshake_are_detectable_before_network_requests(): void
    {
        foreach ([$this->path(), $this->fixture('fwrite(STDOUT, "x");')] as $binary) {
            try {
                $this->worker($binary);
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());

                continue;
            }
            self::fail('Unavailable worker was accepted.');
        }
    }

    public function test_reply_positions_must_advance_in_request_order(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => false, 'error' => null, 'results' => [['index' => 1, 'status' => 'missing', 'length' => 0]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [['ids' => ['a@b', 'c@d'], 'mode' => 'body']], 1024, 4096, static function (): void {});
        $this->assertFeedFails($worker);
    }

    public function test_total_body_budget_covers_all_feeds_and_connections(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 3]]], 'abc');
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 2]]], 'de');
PHP);
        $worker = new RustVerificationFramer($binary, [
            ['ids' => ['a@b'], 'mode' => 'body'], ['ids' => ['c@d'], 'mode' => 'body'],
        ], 4, 4, static function (): void {});
        self::assertSame('abc', $worker->feed(0, 'x')['results'][0]['body']);
        $this->expectException(RuntimeException::class);
        $worker->feed(1, 'y');
    }

    public function test_same_reply_body_lengths_cannot_exceed_the_global_buffer(): void
    {
        $binary = $this->fixture(<<<'PHP'
fwrite(STDOUT, "\0");
feed();
reply(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'body', 'length' => 3], ['index' => 1, 'status' => 'body', 'length' => 2]]]);
PHP);
        $worker = new RustVerificationFramer($binary, [['ids' => ['a@b', 'c@d'], 'mode' => 'body']], 4, 4, static function (): void {});
        $this->assertFeedFails($worker);
    }

    public function test_silent_worker_times_out_even_while_heartbeats_succeed(): void
    {
        $binary = $this->fixture('fwrite(STDOUT, "\\0"); feed(); sleep(60);');
        $worker = $this->worker($binary);
        $started = hrtime(true);
        $this->assertFeedFails($worker);
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThanOrEqual(29, $elapsed);
        self::assertLessThan(32, $elapsed);
        $this->assertFeedFails($worker);
    }

    /** @param array<mixed> $connections */
    #[DataProvider('invalidInitializations')]
    public function test_invalid_initialization_is_rejected_before_starting_the_child(array $connections, int $maximumBytes, int $bufferBytes): void
    {
        $started = $this->path();
        $binary = $this->fixture('file_put_contents('.var_export($started, true).', "started"); fwrite(STDOUT, "\\0");');
        try {
            new RustVerificationFramer($binary, $connections, $maximumBytes, $bufferBytes, static function (): void {});
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($started);

            return;
        }
        self::fail('Invalid initialization was accepted.');
    }

    /** @return iterable<string, array{array<mixed>, int, int}> */
    public static function invalidInitializations(): iterable
    {
        $valid = ['ids' => ['a@b'], 'mode' => 'body'];
        yield 'too many connections' => [array_fill(0, 9, $valid), 1024, 4096];
        yield 'too many IDs' => [[['ids' => array_fill(0, 17, 'a@b'), 'mode' => 'body']], 1024, 4096];
        yield 'buffer above absolute ceiling' => [[$valid], 1024, 67108865];
        yield 'article above absolute ceiling' => [[$valid], 16777217, 67108864];
        yield 'buffer below article maximum' => [[$valid], 1024, 1023];
        yield 'invalid connection shape' => [[[]], 1024, 4096];
        yield 'invalid ID type' => [[['ids' => [1], 'mode' => 'body']], 1024, 4096];
        foreach (['a b', "a\r\nb", "a\0b", '<a@b>', "a\u{0085}b", "a\u{2003}b", "\xff"] as $index => $id) {
            yield 'invalid ID '.$index => [[['ids' => [$id], 'mode' => 'body']], 1024, 4096];
        }
    }

    public function test_real_rust_executable_frames_interleaved_body_and_stat_responses(): void
    {
        $binary = getenv('NNTMUX_VERIFICATION_FRAMER') ?: getenv('NNTMUX_VERIFICATION_TEST_BINARY') ?: dirname(__DIR__, 2).'/docker/app/nzb-yenc-ffi/target/release/nntmux-verification-framer';
        if (! is_executable($binary)) {
            self::markTestSkipped('Set NNTMUX_VERIFICATION_FRAMER to the compiled Rust framer.');
        }
        $worker = new RustVerificationFramer($binary, [
            ['ids' => ['a@b', 'c@d'], 'mode' => 'body'], ['ids' => ['e@f'], 'mode' => 'stat'],
        ], 1024, 4096, static function (): void {});
        self::assertSame(['done' => false, 'error' => null, 'results' => []], $worker->feed(0, "222 0 <a@b>\r\n..x\r"));
        self::assertSame(['done' => true, 'error' => null, 'results' => [['index' => 0, 'status' => 'present', 'body' => '']]], $worker->feed(1, "223 0 <e@f>\r\n"));
        self::assertSame(['done' => true, 'error' => null, 'results' => [
            ['index' => 0, 'status' => 'body', 'body' => ".x\r\n"], ['index' => 1, 'status' => 'missing', 'body' => ''],
        ]], $worker->feed(0, "\n.\r\n430 missing\r\n"));
    }

    private function assertFeedFails(RustVerificationFramer $worker): void
    {
        try {
            $worker->feed(0, 'x');
        } catch (RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());

            return;
        }
        self::fail('Expected a detectable, non-retried framer failure.');
    }

    private function worker(string $binary, int $maximumBytes = 1024, int $bufferBytes = 4096, ?callable $heartbeat = null): RustVerificationFramer
    {
        return new RustVerificationFramer($binary, [['ids' => ['a@b'], 'mode' => 'body']], $maximumBytes, $bufferBytes, $heartbeat ?? static function (): void {});
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'verification framer ;');
        self::assertNotFalse($path);
        unlink($path);
        $this->paths[] = $path;

        return $path;
    }

    private function fixture(string $body): string
    {
        $path = $this->path();
        $source = '#!'.PHP_BINARY."\n<?php\n".<<<'PHP'
function exact(int $length): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread(STDIN, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') { exit(10); }
        $bytes .= $chunk;
    }
    return $bytes;
}
function feed(): array { return [ord(exact(1)), exact(unpack('N', exact(4))[1])]; }
function reply(array $metadata, string $body = ''): void {
    $json = json_encode($metadata, JSON_THROW_ON_ERROR);
    $wire = pack('N', strlen($json)).$json.$body;
    while ($wire !== '') { $n = fwrite(STDOUT, $wire); $wire = substr($wire, $n); }
}
$init = json_decode(exact(unpack('N', exact(4))[1]), true, flags: JSON_THROW_ON_ERROR);
PHP;
        file_put_contents($path, $source."\n".$body);
        chmod($path, 0700);

        return $path;
    }
}
