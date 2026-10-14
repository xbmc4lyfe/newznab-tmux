<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\YencArticles;

final class NNTPBoundedArticleTest extends TestCase
{
    public function test_bounded_article_read_refreshes_ownership_across_socket_fragments(): void
    {
        $data = str_repeat('x', 20000)."\r\n";
        $client = $this->client($data.".\r\n");
        $heartbeats = 0;
        $result = $client->getMessageBounded('alt.test', 'test@example', strlen($data), false, static function () use (&$heartbeats): bool {
            $heartbeats++;

            return true;
        });
        self::assertSame($data, $result->body);
        self::assertGreaterThanOrEqual(3, $heartbeats);
    }

    public function test_bounded_article_ownership_loss_discards_prefix_and_never_tries_alternate(): void
    {
        $client = $this->client("prefix\r\nsecond line\r\n.\r\n");
        $client->alternate = $this->client("alternate\r\n.\r\n");
        $result = $client->getMessageBounded('alt.test', 'test@example', 100, true, static fn (): bool => false);

        self::assertNull($result->body);
        self::assertFalse($client->isConnected());
        self::assertSame([], $client->alternate->alternateConnections);
    }

    public function test_bounded_alternate_article_read_keeps_the_ownership_heartbeat(): void
    {
        $client = $this->client('');
        $client->response = 430;
        $client->alternate = $this->client("alternate\r\n.\r\n");
        $heartbeats = 0;
        $result = $client->getMessageBounded('alt.test', 'test@example', 100, true, static function () use (&$heartbeats): bool {
            $heartbeats++;

            return true;
        });

        self::assertSame("alternate\r\n", $result->body);
        self::assertGreaterThanOrEqual(2, $heartbeats);
    }

    public function test_returns_complete_plain_par2_bytes_and_preserves_next_response(): void
    {
        $data = "PAR2\0PKT".str_repeat('x', 8183)."..continued\r\n.leading dot\r\n";
        $client = $this->client(preg_replace('/^\./m', '..', $data).".\r\nNEXT\r\n");

        $result = $client->getMessageBounded('alt.test', 'test@example', strlen($data));

        self::assertSame($data, $result->body);
        self::assertNull($result->part);
        self::assertNull($result->error);
        self::assertSame("NEXT\r\n", $client->nextLine());
        self::assertSame(['BODY <test@example>'], $client->commands);
    }

    public function test_decodes_multipart_article_whose_full_file_size_exceeds_ceiling(): void
    {
        $data = YencArticles::bytes();
        $client = $this->client($this->wire(YencArticles::part($data, 'large.par2', 5000000000, 2, 12345, 100)));

        $result = $client->getMessageBounded('alt.test', 'test@example', 256);

        self::assertSame($data, $result->body);
        self::assertSame(5000000000, $result->part?->size);
        self::assertSame('large.par2', $result->part?->name);
        self::assertSame(2, $result->part?->part);
        self::assertSame(12344, $result->part?->offset());
        self::assertSame(256, $result->part?->length());
    }

    public function test_decodes_single_part_yenc_with_dot_stuffing(): void
    {
        $data = str_repeat("\x04", 260).YencArticles::bytes();
        $client = $this->client($this->wire(YencArticles::article($data)));

        $result = $client->getMessageBounded('alt.test', 'test@example', strlen($data));

        self::assertSame($data, $result->body);
        self::assertSame(strlen($data), $result->part?->size);
    }

    #[DataProvider('literalYencNames')]
    public function test_bounded_transport_preserves_literal_yenc_name_bytes(string $name): void
    {
        $data = YencArticles::bytes();
        $client = $this->client($this->wire(YencArticles::part($data, $name, strlen($data), 1, 1)));

        $result = $client->getMessageBounded('alt.test', 'test@example', strlen($data));

        self::assertSame($data, $result->body);
        self::assertSame($name, $result->part?->name);
        self::assertNull($result->error);
    }

    /** @return array<string,array{string}> */
    public static function literalYencNames(): array
    {
        return ['trailing space' => ['index.par2 '], 'trailing tab' => ["index.par2\t"], 'leading whitespace' => [" \tindex.par2"], 'non UTF8 byte' => ["index-\xff.par2 "]];
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function invalidBodies(): iterable
    {
        yield 'plain decoded ceiling' => ["12345\r\n.\r\n", 6, true];
        yield 'dishonest yenc size' => ["=ybegin line=128 size=1 name=x\r\nklmnop\r\n=yend size=1\r\n.\r\n", 5, true];
        yield 'invalid crc' => ["=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3 crc32=deadbeef\r\n.\r\n", 10, false];
        yield 'missing yend' => ["=ybegin line=128 size=3 name=x\r\nklm\r\n.\r\n", 100, false];
        yield 'multiple frames' => [YencArticles::article('ABC')."\r\n".YencArticles::article('DEF')."\r\n.\r\n", 100, false];
        yield 'missing terminator' => ["PAR2\0PKT\r\n", 100, false];
        yield 'multipart bad part' => ["=ybegin part=2 line=128 size=100 name=x\r\n=ypart begin=1 end=3\r\nklm\r\n=yend size=3 part=1 pcrc32=a3830348\r\n.\r\n", 100, false];
        yield 'multipart missing checksum' => ["=ybegin part=1 line=128 size=100 name=x\r\n=ypart begin=1 end=3\r\nklm\r\n=yend size=3 part=1\r\n.\r\n", 100, false];
    }

    #[DataProvider('invalidBodies')]
    public function test_returns_no_prefix_for_invalid_or_oversized_articles(string $wire, int $ceiling, bool $oversized): void
    {
        $client = $this->client($wire);

        $result = $client->getMessageBounded('alt.test', 'test@example', $ceiling);

        self::assertNull($result->body);
        self::assertNull($result->part);
        self::assertNotNull($result->error);
        self::assertSame($oversized, $result->oversized);
    }

    public function test_aborts_unterminated_wire_at_cap_plus_one_and_discards_connection(): void
    {
        $client = $this->client(str_repeat('x', 1000000));

        $result = $client->getMessageBounded('alt.test', 'test@example', 10);

        self::assertNull($result->body);
        self::assertTrue($result->oversized);
        self::assertSame(65587, $client->discardedAt);
        self::assertFalse($client->isConnected());
    }

    public function test_alternate_returns_only_its_complete_article_after_primary_truncation(): void
    {
        $client = $this->client("primary prefix\r\n");
        $client->alternate = $this->client($this->wire(YencArticles::article('ABC')));

        $result = $client->getMessageBounded('alt.test', 'test@example', 100, true);

        self::assertSame('ABC', $result->body);
        self::assertFalse($client->isConnected());
        self::assertSame([true], $client->alternate->alternateConnections);
    }

    public function test_alternate_connection_failure_does_not_return_primary_prefix(): void
    {
        $client = $this->client("primary prefix\r\n");
        $client->alternate = $this->client('');
        $client->alternate->connectionFails = true;

        $result = $client->getMessageBounded('alt.test', 'test@example', 100, true);

        self::assertNull($result->body);
        self::assertNotNull($result->error);
        self::assertSame([], $client->alternate->commands);
    }

    public function test_alternate_body_failure_does_not_return_either_prefix(): void
    {
        $client = $this->client("primary prefix\r\n");
        $client->alternate = $this->client("alternate prefix\r\n");
        $client->setCurrentAlternate();

        $result = $client->getMessageBounded('alt.test', 'test@example', 100, true);

        self::assertNull($result->body);
        self::assertNotNull($result->error);
        self::assertSame([false], $client->alternate->alternateConnections);
    }

    public function test_does_not_retry_oversized_article_on_alternate(): void
    {
        $client = $this->client("too large\r\n.\r\n");
        $client->alternate = $this->client("ok\r\n.\r\n");

        $result = $client->getMessageBounded('alt.test', 'test@example', 5, true);

        self::assertTrue($result->oversized);
        self::assertSame([], $client->alternate->alternateConnections);
    }

    public function test_body_status_failure_is_explicit(): void
    {
        $client = $this->client('');
        $client->response = 430;

        $result = $client->getMessageBounded('alt.test', 'test@example', 100);

        self::assertNull($result->body);
        self::assertNotNull($result->error);
    }

    #[DataProvider('missingThenFoundResponses')]
    public function test_complete_missing_statuses_reuse_the_socket_and_preserve_the_next_body(string $missing, int $count, bool $yenc): void
    {
        $data = "PAR2\0PKT".YencArticles::bytes()."\r\n";
        $body = $yenc ? $this->wire(YencArticles::article($data)) : $this->wire(rtrim($data, "\r\n"));
        $client = $this->socketClient(str_repeat($missing, $count)."222 0 <found@example> body follows\r\n".$body);

        for ($index = 0; $index < $count; $index++) {
            $result = $client->getMessageBounded('alt.test', 'missing'.$index.'@example', strlen($data));
            self::assertNull($result->body);
            self::assertNotNull($result->error);
            self::assertTrue($client->isConnected());
            self::assertSame('alt.test', $client->currentGroup());
        }

        $result = $client->getMessageBounded('alt.test', 'found@example', strlen($data));

        self::assertSame($data, $result->body);
        self::assertNull($result->error);
        self::assertSame([], $client->connections);
        $commands = array_map(static fn (int $index): string => 'BODY <missing'.$index.'@example>', range(0, $count - 1));
        $commands[] = 'BODY <found@example>';
        self::assertSame($commands, $client->sentCommands());
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function missingThenFoundResponses(): iterable
    {
        yield 'plain PAR2' => ["430 No article with that message-id\r\n", 1, false];
        yield 'yenc PAR2 after repeated misses' => ["430 No article with that message-id\r\n", 3, true];
        yield 'no optional comment' => ["430\r\n", 1, true];
        yield 'maximum status line' => ['430 '.str_repeat('x', 506)."\r\n", 1, true];
    }

    #[DataProvider('unsafeBodyStatuses')]
    public function test_unsafe_statuses_discard_the_socket_without_returning_a_body(string $status): void
    {
        $client = $this->socketClient($status);

        $result = $client->getMessageBounded('alt.test', 'test@example', 100);

        self::assertNull($result->body);
        self::assertNotNull($result->error);
        self::assertFalse($client->isConnected());
        self::assertSame($status === '' ? [] : ['BODY <test@example>'], $client->sentCommands());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBodyStatuses(): iterable
    {
        yield 'disconnecting' => ["400 Service unavailable\r\n"];
        yield 'number-only failure' => ["423 No article with that number\r\n"];
        yield 'unexpected response' => ["500 Unknown command\r\n"];
        yield 'missing CRLF' => ['430 No article'];
        yield 'LF only' => ["430 No article\n"];
        yield 'leading whitespace' => [" 430 No article\r\n"];
        yield 'garbage after code' => ["430garbage\r\n"];
        yield 'tab after code' => ["430\tNo article\r\n"];
        yield 'embedded CR' => ["430 No\rarticle\r\n"];
        yield 'embedded NUL' => ["430 No\0article\r\n"];
        yield 'overlong complete status' => ['430 '.str_repeat('x', 507)."\r\n"];
        yield 'vendor read truncation' => ['430 '.str_repeat('x', 5000)."\r\n"];
        yield 'malformed body success' => ["222garbage\r\nnot a body\r\n.\r\n"];
        yield 'no status' => [''];
    }

    public function test_missing_primary_can_fall_back_without_losing_its_next_article(): void
    {
        $primary = "PAR2\0PKTprimary\r\n";
        $alternate = "PAR2\0PKTalternate";
        $client = $this->socketClient("430 No article\r\n222 0 <next@example> body follows\r\n".$primary.".\r\n");
        $client->alternate = $this->socketClient("222 0 <missing@example> body follows\r\n".$this->wire(YencArticles::article($alternate)));

        $result = $client->getMessageBounded('alt.test', 'missing@example', 100, true);

        self::assertSame($alternate, $result->body);
        self::assertTrue($client->isConnected());
        self::assertSame([true], $client->alternate->connections);
        self::assertFalse($client->alternate->isConnected());
        self::assertSame($primary, $client->getMessageBounded('alt.test', 'next@example', 100)->body);
        self::assertSame([], $client->connections);
        self::assertSame(['BODY <missing@example>', 'BODY <next@example>'], $client->sentCommands());
        self::assertSame(['BODY <missing@example>'], $client->alternate->sentCommands());
    }

    public function test_rejects_nonpositive_ceiling_before_network_access(): void
    {
        $client = $this->client('');
        $this->expectException(\InvalidArgumentException::class);

        $client->getMessageBounded('alt.test', 'test@example', 0);
    }

    public function test_aborts_yenc_wire_flood_despite_small_declared_sizes(): void
    {
        $client = $this->client("=ybegin line=128 size=1 name=x\r\n".str_repeat('k', 1000000));

        $result = $client->getMessageBounded('alt.test', 'test@example', 10);

        self::assertNull($result->body);
        self::assertTrue($result->oversized);
        self::assertSame(65587, $client->discardedAt);
        self::assertFalse($client->isConnected());
    }

    public function test_rejects_command_injection_before_sending_body(): void
    {
        $client = $this->client('');

        $result = $client->getMessageBounded('alt.test', "test@example>\r\nQUIT", 100);

        self::assertNull($result->body);
        self::assertNotNull($result->error);
        self::assertSame([], $client->commands);
    }

    public function test_preserves_escape_state_across_yenc_line_endings(): void
    {
        $article = "=ybegin line=128 size=1 name=x\r\n=\r\n}\r\n=yend size=1 crc32=56bcae53\r\n.\r\n";
        $client = $this->client($article);

        $result = $client->getMessageBounded('alt.test', 'test@example', 1);

        self::assertSame("\x13", $result->body);
    }

    private function client(string $wire): BoundedArticleHarness
    {
        return new BoundedArticleHarness($wire, $this->decoder());
    }

    private function socketClient(string $wire): BoundedArticleSocketHarness
    {
        return new BoundedArticleSocketHarness($wire, $this->decoder());
    }

    private function decoder(): YencService
    {
        $library = getenv('YENC_NATIVE_LIBRARY');

        return $library ? new YencService(new NativePayloadDecoder($library)) : new YencService;
    }

    private function wire(string $article): string
    {
        return preg_replace('/^\./m', '..', $article)."\r\n.\r\n";
    }
}

final class BoundedArticleHarness extends NNTPService
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<bool> */
    public array $alternateConnections = [];

    public ?self $alternate = null;

    public bool $connectionFails = false;

    public int $response = 222;

    public ?int $discardedAt = null;

    public function __construct(string $wire, YencService $decoder)
    {
        $this->_socket = fopen('php://memory', 'w+');
        fwrite($this->_socket, $wire);
        rewind($this->_socket);
        $this->_yencService = $decoder;
        $this->_currentServer = $this->_configServer = 'primary';
        $this->_configAlternateServer = 'alternate';
    }

    public function __destruct()
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
    }

    public function nextLine(): string|false
    {
        return fgets($this->_socket);
    }

    public function setCurrentAlternate(): void
    {
        $this->_currentServer = 'alternate';
    }

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        return [];
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        $this->commands[] = $cmd;

        return $this->response;
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->alternateConnections[] = $alternate;

        return $this->connectionFails ? $this->throwError('Connection failed') : true;
    }

    protected function createBoundedArticleClient(): NNTPService
    {
        return $this->alternate ?? throw new \LogicException('Unexpected alternate connection');
    }

    protected function discardBoundedArticleConnection(): void
    {
        if (is_resource($this->_socket)) {
            $this->discardedAt = ftell($this->_socket);
        }
        parent::discardBoundedArticleConnection();
    }
}

/** Exercises the production command writer, status parser, connection check and group cache. */
final class BoundedArticleSocketHarness extends NNTPService
{
    /** @var resource */
    private mixed $peer;

    /** @var list<bool> */
    public array $connections = [];

    public ?self $alternate = null;

    public function __construct(string $wire, YencService $decoder)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Could not create fixture socket pair.');
        }
        [$this->_socket, $this->peer] = $pair;
        if ($wire !== '') {
            fwrite($this->peer, $wire);
        }
        stream_socket_shutdown($this->peer, STREAM_SHUT_WR);
        stream_set_blocking($this->peer, false);
        stream_set_timeout($this->_socket, 1);
        $this->_yencService = $decoder;
        $this->_currentServer = $this->_configServer = 'primary';
        $this->_configAlternateServer = 'alternate';
        $this->_currentGroup = 'alt.test';
        $this->_selectedGroupSummary = [];
    }

    public function __destruct()
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        fclose($this->peer);
    }

    public function currentGroup(): string
    {
        return $this->_currentGroup;
    }

    /** @return list<string> */
    public function sentCommands(): array
    {
        $commands = stream_get_contents($this->peer);

        return $commands === false || $commands === '' ? [] : explode("\r\n", rtrim($commands, "\r\n"));
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections[] = $alternate;

        return $this->isConnected() ? true : $this->throwError('Unexpected reconnect.');
    }

    protected function createBoundedArticleClient(): NNTPService
    {
        return $this->alternate ?? throw new \LogicException('Unexpected alternate connection');
    }
}
