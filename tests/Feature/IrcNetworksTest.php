<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\IrcScraperCommand;
use App\Services\Predb\Irc\IrcNetworks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IrcNetworksTest extends TestCase
{
    #[Test]
    public function only_enabled_networks_are_listed(): void
    {
        config([
            'irc_settings.networks.synirc.enabled' => true,
            'irc_settings.networks.corruptnet.enabled' => true,
            'irc_settings.networks.zenet.enabled' => false,
        ]);

        $this->assertSame(['synirc', 'corruptnet'], IrcNetworks::enabled());
    }

    #[Test]
    public function the_synirc_network_keeps_using_the_top_level_settings(): void
    {
        config([
            'irc_settings.scrape_irc_server' => 'irc.example.net',
            'irc_settings.scrape_irc_port' => 7000,
            'irc_settings.scrape_irc_tls' => true,
            'irc_settings.scrape_irc_channels' => serialize(['#PreNNTmux' => null, '#private' => 'pw']),
        ]);

        $network = IrcNetworks::resolve('synirc');

        $this->assertSame('nntmux', $network['format']);
        $this->assertSame('irc.example.net', $network['server']);
        $this->assertSame(7000, $network['port']);
        $this->assertTrue($network['tls']);
        $this->assertNull($network['tls_verify_peer_name']);
        $this->assertSame(['#PreNNTmux' => null, '#private' => 'pw'], $network['channels']);
    }

    #[Test]
    public function public_pre_networks_resolve_from_their_profiles(): void
    {
        $corrupt = IrcNetworks::resolve('corruptnet');
        $zenet = IrcNetworks::resolve('zenet');

        $this->assertSame(['corruptnet', 'corrupt-net', 'irc.corrupt-net.org', 6697, true], [$corrupt['format'], $corrupt['source'], $corrupt['server'], $corrupt['port'], $corrupt['tls']]);
        $this->assertSame(['#pre' => null, '#Pre.Spam' => null], $corrupt['channels']);
        $this->assertNull($corrupt['tls_verify_peer_name']);
        $this->assertFalse($zenet['tls_verify_peer_name']);
    }

    #[Test]
    public function networks_join_their_info_channels_and_reuse_known_formats(): void
    {
        $this->assertSame(['#pre' => null, '#pre.spam' => null, '#p2ptrace' => null], IrcNetworks::resolve('predatabase')['channels']);
        $this->assertSame(['#pre' => null, '#Pre.Spam' => null], IrcNetworks::resolve('corruptnet')['channels']);
        $this->assertSame('corruptnet', IrcNetworks::resolve('efnet')['format']);
        // EFnet's certificates are self-signed, so it defaults to plain IRC.
        $this->assertSame([6667, false], [IrcNetworks::resolve('efnet')['port'], IrcNetworks::resolve('efnet')['tls']]);
        $this->assertSame('zenet', IrcNetworks::resolve('abjects')['format']);
        $this->assertSame('irc', IrcNetworks::resolve('ngp')['type']);
    }

    #[Test]
    public function websocket_streams_resolve_with_their_url_and_format(): void
    {
        $club = IrcNetworks::resolve('predbclub_ws');
        $net = IrcNetworks::resolve('predbnet_ws');

        $this->assertSame(['websocket', 'predb_club', 'wss://predb.club/api/v1/ws'], [$club['type'], $club['format'], $club['url']]);
        $this->assertSame(['websocket', 'predb_net', 'wss://api.predb.net/ws'], [$net['type'], $net['format'], $net['url']]);
    }

    #[Test]
    public function websocket_streams_start_without_an_irc_username(): void
    {
        config([
            'irc_settings.scrape_irc_username' => '',
            // A closed local port: the stream starts, then fails to connect instead of failing on the nickname.
            'irc_settings.networks.predbclub_ws.url' => 'ws://127.0.0.1:9/ws',
        ]);

        $this->artisan('irc:scrape', ['--network' => 'predbclub_ws'])
            ->expectsOutputToContain('WebSocket connect to 127.0.0.1:9 failed')
            ->doesntExpectOutputToContain('You must put a username')
            ->assertFailed();

        $this->artisan('irc:scrape', ['--network' => 'zenet'])
            ->expectsOutputToContain('You must put a username')
            ->assertFailed();
    }

    #[Test]
    public function restarts_back_off_exponentially_up_to_an_hour(): void
    {
        $this->assertSame(
            [30, 60, 120, 240, 3600, 3600],
            array_map(IrcScraperCommand::restartDelay(...), [1, 2, 3, 4, 8, 40])
        );
    }

    #[Test]
    public function unknown_networks_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IrcNetworks::resolve('undernet');
    }
}
