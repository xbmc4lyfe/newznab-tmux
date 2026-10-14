<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\IRCClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class IrcConnectionDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
    }

    public function test_drain_prevents_an_initial_connection_attempt(): void
    {
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        $client = $this->client();

        $this->assertFalse($client->connect('irc.example'));
        $this->assertSame(0, $client->attempts);
    }

    public function test_drain_during_a_failed_connection_prevents_further_attempts(): void
    {
        $client = $this->client();
        $client->stopOnConnect = true;

        $this->assertFalse($client->connect('irc.example'));
        $this->assertSame(1, $client->attempts);
    }

    public function test_drain_prevents_login_commands(): void
    {
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        $client = $this->client();
        $client->connected = true;

        $this->assertFalse($client->login('nick', 'user', 'real'));
        $this->assertSame([], $client->commands);
    }

    public function test_login_stops_waiting_for_registration_when_draining(): void
    {
        $client = $this->client();
        $client->connected = true;
        $client->stopOnRead = true;

        $this->assertFalse($client->login('nick', 'user', 'real'));
        $this->assertSame(1, $client->reads);
    }

    public function test_successful_login_still_returns_true(): void
    {
        $client = $this->client();
        $client->connected = true;

        $this->assertTrue($client->login('nick', 'user', 'real'));
        $this->assertSame(['NICK nick', 'USER user 0 * :real'], $client->commands);
    }

    public function test_connection_retries_still_work_without_drain(): void
    {
        $client = $this->client();
        $client->connectOnAttempt = 2;

        $this->assertTrue($client->connect('irc.example'));
        $this->assertSame(2, $client->attempts);
    }

    public function test_drain_interrupts_the_retry_delay(): void
    {
        $client = $this->client();
        $client->setReConnectDelay(5);
        $requested = false;
        DB::listen(static function (QueryExecuted $query) use ($client, &$requested): void {
            if (! $requested && $client->attempts === 1 && str_contains($query->sql, 'select * from "processing_runtime_states"')) {
                $requested = true;
                ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
            }
        });
        $started = hrtime(true);

        $this->assertFalse($client->connect('irc.example'));
        $this->assertTrue($requested);
        $this->assertSame(1, $client->attempts);
        $this->assertLessThan(1.0, (hrtime(true) - $started) / 1_000_000_000);
    }

    public function test_drain_prevents_reconnection_without_a_fatal_exit(): void
    {
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        $client = $this->client();

        $client->reconnect();

        $this->assertSame(0, $client->attempts);
    }

    public function test_drain_during_reconnection_returns_without_a_fatal_exit(): void
    {
        $client = $this->client();
        $client->stopOnConnect = true;

        $client->reconnect();

        $this->assertSame(1, $client->attempts);
        $this->assertSame([], $client->commands);
    }

    public function test_drain_during_reconnection_login_does_not_join_channels(): void
    {
        $client = $this->client();
        $client->connectOnAttempt = 1;
        $client->stopOnRead = true;

        $client->reconnect();

        $this->assertSame(1, $client->reads);
        $this->assertSame(['NICK nick', 'USER user 0 * :real'], $client->commands);
    }

    public function test_drain_prevents_address_fallback_after_a_failed_socket_attempt(): void
    {
        $client = new class extends IRCClient
        {
            public int $attempts = 0;

            public int $resolutions = 0;

            protected function _openSocket(string $socketString, ?string $peerName, ?int &$errorNumber, ?string &$errorString, ?float $timeout = null)
            {
                $this->attempts++;
                $errorNumber = 1;
                $errorString = 'Unavailable';
                ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);

                return false;
            }

            protected function _resolveAddresses(string $host): array
            {
                $this->resolutions++;

                return ['192.0.2.1'];
            }
        };
        ob_start();
        try {
            $connected = $client->connect('irc.example');
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($connected);
        $this->assertSame(1, $client->attempts);
        $this->assertSame(0, $client->resolutions);
    }

    public static function unfinishedFrames(): iterable
    {
        yield 'partial frame' => [1, false];
        yield 'oversized discarded frame' => [3, true];
    }

    #[DataProvider('unfinishedFrames')]
    public function test_drain_stops_continuous_unfinished_frame_reads(int $stopOnRead, bool $discarded): void
    {
        $client = $this->streamingClient($stopOnRead);

        $client->readIncoming();

        $this->assertSame([], $client->messages);
        $this->assertSame($stopOnRead, IrcDrainContinuousStream::$reads);
        $this->assertSame($discarded, $client->discardingAtQuit);
        $this->assertSame([false, '', false], $client->state());
    }

    public function test_drain_finishes_a_frame_completed_by_the_current_read(): void
    {
        $client = $this->streamingClient(1, true);

        $client->readIncoming();

        $this->assertSame(['example'], $client->messages);
        $this->assertSame(1, IrcDrainContinuousStream::$reads);
        $this->assertSame([false, '', false], $client->state());
    }

    private function streamingClient(int $stopOnRead, bool $complete = false): IrcDrainReadClient
    {
        if (! in_array('ircdraincontinuous', stream_get_wrappers(), true)) {
            stream_wrapper_register('ircdraincontinuous', IrcDrainContinuousStream::class);
        }
        IrcDrainContinuousStream::$reads = 0;
        IrcDrainContinuousStream::$stopOnRead = $stopOnRead;
        IrcDrainContinuousStream::$complete = $complete;

        return new IrcDrainReadClient(fopen('ircdraincontinuous://fixture', 'r+'));
    }

    private function client(): IrcConnectionDrainClient
    {
        $client = new IrcConnectionDrainClient;
        $client->setReConnectDelay(0);

        return $client;
    }
}

class IrcConnectionDrainClient extends IRCClient
{
    public int $attempts = 0;

    public int $reads = 0;

    public bool $connected = false;

    public bool $stopOnConnect = false;

    public bool $stopOnRead = false;

    public ?int $connectOnAttempt = null;

    /** @var list<string> */
    public array $commands = [];

    public function __destruct() {}

    public function reconnect(): void
    {
        $this->_remote_host = 'irc.example';
        $this->_nickName = 'nick';
        $this->_userName = 'user';
        $this->_realName = 'real';
        $this->_channels = ['#pre' => null];
        $this->_reconnect();
    }

    protected function _initiateStream(): void
    {
        $this->attempts++;
        $this->connected = $this->attempts === $this->connectOnAttempt;
        if ($this->stopOnConnect) {
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        }
    }

    protected function _connected(): bool
    {
        return $this->connected;
    }

    protected function _readSocket(): void
    {
        $this->reads++;
        if ($this->stopOnRead) {
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
            $this->_buffer = $this->reads === 1 ? '' : ':server 001 nick :Welcome';
        } else {
            $this->_buffer = ':server 001 nick :Welcome';
        }
    }

    protected function _writeSocket(string $command): bool
    {
        $this->commands[] = $command;

        return true;
    }

    protected function _closeStream(): void
    {
        $this->connected = false;
    }
}

class IrcDrainReadClient extends IRCClient
{
    /** @var list<string> */
    public array $messages = [];

    public bool $discardingAtQuit = false;

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->_socket = $stream;
        $this->_lastPing = time();
        $this->_debug = false;
    }

    public function __destruct()
    {
        $this->_closeStream();
    }

    /** @return array{bool, string, bool} */
    public function state(): array
    {
        return [is_resource($this->_socket), $this->_partialReadBuffer, $this->_discardReadFrame];
    }

    public function quit(?string $message = null): bool
    {
        $this->discardingAtQuit = $this->_discardReadFrame;

        return parent::quit($message);
    }

    protected function processChannelMessages(): void
    {
        $this->messages[] = $this->_channelData['message'];
    }

    protected function _writeSocket(string $command): bool
    {
        return true;
    }
}

class IrcDrainContinuousStream
{
    public $context;

    public static int $reads = 0;

    public static int $stopOnRead = 1;

    public static bool $complete = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        self::$reads++;
        if (self::$reads > self::$stopOnRead) {
            throw new RuntimeException('IRC continued reading an unfinished frame after drain.');
        }
        if (self::$reads === self::$stopOnRead) {
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        }

        return self::$complete ? ":user!host PRIVMSG #pre :example\r\n" : str_repeat('x', $count);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
