<?php

declare(strict_types=1);

namespace Tests\Feature;

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
        $this->assertSame(['#pre' => null], $corrupt['channels']);
        $this->assertNull($corrupt['tls_verify_peer_name']);
        $this->assertFalse($zenet['tls_verify_peer_name']);
    }

    #[Test]
    public function unknown_networks_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IrcNetworks::resolve('efnet');
    }
}
