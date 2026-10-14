<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\IRCClient;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Predb\Stream\WebSocketClient;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class IrcDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
    }

    public function test_irc_finishes_a_message_before_closing_the_connection_on_drain(): void
    {
        $client = new class extends IRCClient
        {
            public int $processed = 0;

            public bool $closed = false;

            public function __construct()
            {
                $this->_lastPing = time();
            }

            protected function _readSocket(): void
            {
                $this->_buffer = ':user!host PRIVMSG #pre :example';
            }

            protected function processChannelMessages(): void
            {
                $this->processed++;
                ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
                if ($this->processed > 1) {
                    throw new \RuntimeException('Drain launched another message.');
                }
            }

            public function quit(?string $message = null): bool
            {
                $this->closed = true;

                return false;
            }
        };
        $client->readIncoming();
        $this->assertSame(1, $client->processed);
        $this->assertTrue($client->closed);
    }

    public function test_websocket_drain_closes_the_connection_after_importing_the_current_message(): void
    {
        $client = Mockery::mock(WebSocketClient::class);
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('receive')->andReturnUsing(static function (): string {
            if ((int) DB::table('processing_runtime_states')->value('stop_requested') === 1) {
                throw new \RuntimeException('Drain received another message.');
            }

            return '{"message":{"pretime":1791630534,"release":"Example.Release-GRP","section":"TV","group":"GRP"}}';
        });
        $client->shouldReceive('close')->once();
        $importer = Mockery::mock(PredbFeedImporter::class);
        $importer->shouldReceive('import')->andReturnUsing(static function (array $entries): array {
            DB::statement('CREATE TABLE imported_messages (title TEXT)');
            DB::table('imported_messages')->insert(['title' => $entries[0]->title]);
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);

            return ['inserted' => 1, 'updated' => 0, 'skipped' => 0];
        });
        (new PredbStreamListener($client, 'predb_net', $importer, silent: true))->run();
        $this->assertSame('Example.Release-GRP', DB::table('imported_messages')->value('title'));
    }
}
