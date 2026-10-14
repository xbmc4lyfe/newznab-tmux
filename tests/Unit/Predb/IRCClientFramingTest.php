<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\IRCClient;
use App\Services\Predb\Irc\PreAnnounceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IRCClientFramingTest extends TestCase
{
    public static function frames(): iterable
    {
        foreach ([511, 512, 513, 3002, 8702, 8703, 8704] as $bytes) {
            yield 'untagged '.$bytes => [str_repeat('x', $bytes - 2), "\r\n", $bytes <= 8703];
        }
        foreach ([8702, 8703, 8704] as $bytes) {
            yield 'tagged '.$bytes => ['@k='.str_repeat('x', 8187).' '.str_repeat('b', $bytes - 8193), "\r\n", $bytes <= 8703];
        }
        foreach ([8700, 8701, 8702] as $bytes) {
            foreach (["\r\n", "\n"] as $ending) {
                yield 'utf8 '.$bytes.' '.bin2hex($ending) => [str_repeat('é', intdiv($bytes, 2)).str_repeat('x', $bytes % 2), $ending, $bytes <= 8701];
            }
        }
    }

    #[DataProvider('frames')]
    public function test_total_ceiling_is_byte_based(string $payload, string $ending, bool $accepted): void
    {
        $client = $this->memory($payload.$ending);
        self::assertSame($accepted ? $payload : '', $client->read());
    }

    public function test_coalesced_frames_and_payload_whitespace_are_preserved(): void
    {
        $payload = str_repeat('x', 1022)."\x0btail \t ";
        $client = $this->memory($payload."\r\nnext\r\n");
        self::assertSame($payload, $client->read());
        self::assertSame('next', $client->read());
    }

    public function test_partial_eof_discards_frame_and_requests_fake_reconnect(): void
    {
        $client = $this->memory('unfinished');
        self::assertSame('', $client->read());
        self::assertSame([0, false], $client->state());
        self::assertSame(1, $client->reconnects);
    }

    public function test_partial_frame_waits_for_data_and_split_crlf_at_ceiling_completes(): void
    {
        $client = $this->paused();
        FramingPausedStream::$data = str_repeat('x', 8701)."\r";
        self::assertSame('', $client->read());
        self::assertSame([8702, false], $client->state());
        FramingPausedStream::$data = "\n";
        self::assertSame(str_repeat('x', 8701), $client->read());
    }

    public function test_overflow_across_calls_stays_bounded_and_does_not_promote_tail(): void
    {
        $client = $this->paused();
        FramingPausedStream::$data = str_repeat('x', 8702);
        self::assertSame('', $client->read());
        self::assertSame([8702, false], $client->state());
        FramingPausedStream::$data = 'x';
        self::assertSame('', $client->read());
        self::assertSame([0, true], $client->state());
        for ($i = 0; $i < 100; $i++) {
            FramingPausedStream::$data = str_repeat('x', 10000);
            self::assertSame('', $client->read());
            self::assertSame([0, true], $client->state());
        }
        FramingPausedStream::$data = "tail\r\nnext\r\n";
        self::assertSame('', $client->read());
        self::assertSame('next', $client->read());
    }

    public function test_close_and_eof_clear_partial_and_discard_states(): void
    {
        foreach ([7, 8703] as $bytes) {
            $client = $this->paused();
            FramingPausedStream::$data = str_repeat('x', $bytes);
            $client->read();
            $client->reset();
            self::assertSame([0, false], $client->state());
            $client->attach(fopen('framingpaused://fixture', 'r'));
            FramingPausedStream::$data = "fresh\r\n";
            self::assertSame('fresh', $client->read());
            FramingPausedStream::$data = str_repeat('x', $bytes);
            $client->read();
            FramingPausedStream::$ended = true;
            self::assertSame('', $client->read());
            self::assertSame([0, false], $client->state());
            self::assertSame(1, $client->reconnects);
        }
    }

    public function test_read_incoming_delivers_full_synthetic_title_and_never_partial_prefix(): void
    {
        $title = 'Synthetic.'.str_repeat('Episode.', 20).'2026-GRP';
        $line = ':fixture!u@h PRIVMSG #fixture :PRE: [TV-X264] '.str_repeat(' ', 1200).$title;
        $client = $this->memory($line."\r\n");
        try {
            $client->stopAtEof = true;
            $client->readIncoming();
            self::fail('The fixture should stop after one channel message.');
        } catch (FramingFixtureFinished) {
            self::assertSame([$title], $client->titles);
        }
        $client = $this->memory($line);
        try {
            $client->stopAtEof = true;
            $client->readIncoming();
            self::fail('The fixture should stop at EOF.');
        } catch (FramingFixtureFinished) {
            self::assertSame([], $client->titles);
        }
    }

    public function test_read_incoming_waits_for_the_rest_of_an_announce(): void
    {
        $title = 'Synthetic.Series.S01E01.2026.1080p.WEB-GRP';
        $client = $this->paused();
        FramingPausedStream::$data = ':fixture!u@h PRIVMSG #fixture :PRE: [TV-X264] Synthetic.Series.';
        $client->continuation = "S01E01.2026.1080p.WEB-GRP\r\n";
        try {
            $client->readIncoming();
            self::fail('The fixture should stop after the completed announce.');
        } catch (FramingFixtureFinished) {
            self::assertSame([$title], $client->titles);
            self::assertTrue($client->waitedWithoutPublishing);
        }
    }

    public function test_real_socket_timeout_retains_partial_until_complete_frames_are_parsed_once(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Local Unix socket pairs are unavailable on Windows.');
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets, 'Unix socket pairs must be supported on CI Linux.');
        [$reader, $writer] = $sockets;
        $client = new FramingClientFixture;
        $client->attach($reader);
        $client->expectedMessages = 2;
        $header = ':fixture!u@h PRIVMSG #fixture :PRE: [TV-X264] ';
        $titlePrefix = 'Synthetic.Series.';
        // Fill one fgets chunk so only the next read waits for the real timeout.
        $prefix = $header.str_repeat(' ', 1023 - strlen($header.$titlePrefix)).$titlePrefix;
        $tail = 'S01E01.2026.1080p.WEB-GRP';
        $second = ':fixture!u@h PRIVMSG #fixture :PRE: [TV-X264] Synthetic.Second.S01E02.2026.1080p.WEB-GRP';

        try {
            self::assertSame(strlen($prefix), fwrite($writer, $prefix));
            $started = hrtime(true);
            $published = $client->read();
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            $metadata = stream_get_meta_data($reader);

            self::assertTrue($metadata['timed_out'], 'Exercise the real five-second stream timeout.');
            self::assertGreaterThanOrEqual(4.5, $elapsed);
            self::assertLessThan(9, $elapsed, 'The reader must return after its bounded timeout.');
            self::assertSame('', $published, 'An incomplete announce must never be published.');
            self::assertSame([strlen($prefix), false], $client->state());
            self::assertSame([], $client->titles);
            self::assertSame([], $client->frames);
            self::assertSame(0, $client->reconnects);

            $remaining = $tail."\r\n".$second."\n";
            self::assertSame(strlen($remaining), fwrite($writer, $remaining));
            try {
                $client->readIncoming();
                self::fail('The fixture must stop after two complete announces.');
            } catch (FramingFixtureFinished) {
                self::assertSame([$prefix.$tail, $second], $client->frames);
                self::assertSame([
                    'Synthetic.Series.'.$tail,
                    'Synthetic.Second.S01E02.2026.1080p.WEB-GRP',
                ], $client->titles);
                self::assertSame([0, false], $client->state());
                self::assertSame(0, $client->reconnects);
            }
        } finally {
            $client->reset();
            if (is_resource($writer)) {
                fclose($writer);
            }
        }
    }

    private function memory(string $wire): FramingClientFixture
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $wire);
        rewind($stream);
        $client = new FramingClientFixture;
        $client->attach($stream);

        return $client;
    }

    private function paused(): FramingClientFixture
    {
        if (! in_array('framingpaused', stream_get_wrappers(), true)) {
            stream_wrapper_register('framingpaused', FramingPausedStream::class);
        }
        FramingPausedStream::$data = '';
        FramingPausedStream::$ended = false;
        $client = new FramingClientFixture;
        $client->attach(fopen('framingpaused://fixture', 'r'));

        return $client;
    }
}

final class FramingFixtureFinished extends RuntimeException {}

/** Actual reader methods with an in-memory stream; reconnect never opens a network socket. */
final class FramingClientFixture extends IRCClient
{
    public int $reconnects = 0;

    /** @var list<string> */
    public array $titles = [];

    /** @var list<string> */
    public array $frames = [];

    public int $expectedMessages = 1;

    public bool $stopAtEof = false;

    public ?string $continuation = null;

    public bool $waitedWithoutPublishing = false;

    public function __construct()
    {
        $this->_debug = false;
        $this->_lastPing = time();
    }

    public function __destruct()
    {
        $this->_closeStream();
    }

    public function attach($stream): void
    {
        $this->_socket = $stream;
    }

    public function read(): string
    {
        ob_start();
        try {
            $this->_readSocket();
        } finally {
            ob_end_clean();
        }

        return (string) $this->_buffer;
    }

    public function state(): array
    {
        return [strlen($this->_partialReadBuffer ?? ''), $this->_discardReadFrame ?? false];
    }

    public function reset(): void
    {
        $this->_closeStream();
    }

    protected function _reconnect(): void
    {
        $this->reconnects++;
        $this->_closeStream();
    }

    protected function _readSocket(): void
    {
        parent::_readSocket();
        if ($this->_buffer !== '') {
            $this->frames[] = $this->_buffer;
        }
        if ($this->continuation !== null && $this->_buffer === '') {
            $this->waitedWithoutPublishing = $this->titles === [];
            FramingPausedStream::$data = $this->continuation;
            $this->continuation = null;
        }
        if ($this->reconnects > 0 && $this->_buffer === '') {
            if ($this->stopAtEof) {
                throw new FramingFixtureFinished;
            }
        }
    }

    protected function processChannelMessages(): void
    {
        $entry = (new PreAnnounceParser('corruptnet', 'fixture'))->parse($this->_channelData['message']);
        if ($entry !== null) {
            $this->titles[] = $entry->title;
        }
        if ($this->expectedMessages === 1 || count($this->titles) >= $this->expectedMessages) {
            throw new FramingFixtureFinished;
        }
    }
}

final class FramingPausedStream
{
    public $context;

    public static string $data = '';

    public static bool $ended = false;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read($count): string
    {
        $chunk = substr(self::$data, 0, $count);
        self::$data = substr(self::$data, strlen($chunk));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return self::$ended && self::$data === '';
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return true;
    }
}
