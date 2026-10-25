<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Security\IndexerProxyDetector;
use App\Services\Security\ProxyRequestContext;
use Closure;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustIrcNetworksStreamListenerCloudflareProxyW47ParityTest extends TestCase
{
    /** Class => toggle stem of its `useW47*RustFfi()` / `usesW47*RustFfi()` / `resetW47*RustFfi()` methods. */
    private const STEMS = [
        IrcNetworks::class => 'Endpoint',
        PredbStreamListener::class => 'Frame',
        CloudflareIpRangeService::class => 'CidrMatch',
        IndexerProxyDetector::class => 'Score',
        ProxyRequestContext::class => 'Xff',
    ];

    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        IrcNetworks::class => 'routeEndpoints',
        PredbStreamListener::class => 'classifyFrames',
        CloudflareIpRangeService::class => 'matchAddresses',
        IndexerProxyDetector::class => 'scoreRequests',
        ProxyRequestContext::class => 'resolveForwardedChains',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 47, 600 pairs
     * per workload after 30 warmups, randomized call order, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS (shared host, load ~6). Timings include dispatch, NUL joining,
     * the FFI call and unpack('P*'). A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        IrcNetworks::class => [4.777, 2.816, 4.262, 3.275],
        PredbStreamListener::class => [2.863, 1.681, 5.349, 3.889],
        CloudflareIpRangeService::class => [12.438, 5.740, 13.367, 9.356],
        IndexerProxyDetector::class => [4.782, 1.834, 2.681, 2.308],
        ProxyRequestContext::class => [13.291, 6.765, 7.029, 5.958],
    ];

    private const PAIRS = 600;

    private const WARMUPS = 30;

    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    protected function tearDown(): void
    {
        foreach (array_keys(self::STEMS) as $class) {
            $this->reset($class);
        }
        parent::tearDown();
    }

    public function test_wave_methods_exist(): void
    {
        foreach (self::STEMS as $class => $stem) {
            $reflection = new ReflectionClass($class);
            $entry = self::ENTRIES[$class];
            foreach (["useW47{$stem}RustFfi", "usesW47{$stem}RustFfi", "resetW47{$stem}RustFfi", $entry, $entry.'Php', $entry.'Native', 'w47Ffi'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasConstant('W47_FFI_CDEF'), $class);
            self::assertStringContainsString('nntmux_w47_', (string) $reflection->getConstant('W47_FFI_CDEF'));
            self::assertTrue($reflection->hasConstant('W47_'.strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $stem)).'_RUST_FFI_DEFAULT'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        $defaults = $this->defaults();
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertSame(min($speedups) >= 1.5, $defaults[$class], $class);
        }
    }

    public function test_default_routing_toggles_and_reset(): void
    {
        $defaults = $this->defaults();
        foreach (array_keys(self::STEMS) as $class) {
            $this->reset($class);
            self::assertSame($defaults[$class], $this->uses($class), $class);
            $this->toggle($class, ! $defaults[$class]);
            self::assertSame(! $defaults[$class], $this->uses($class), $class);
            $this->toggle($class, null);
            self::assertSame($defaults[$class], $this->uses($class), $class);
            $this->toggle($class, true);
            $this->reset($class);
            self::assertSame($defaults[$class], $this->uses($class), $class);
        }
        // The Wave 47 toggles are independent of the earlier useRustFfi switches.
        self::assertFalse(IrcNetworks::$useRustFfi);
        self::assertTrue(PredbStreamListener::usesRustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $endpoints = ['irc.synirc.net:6697/#pre', '', '[::1]'];
        foreach ([true, false] as $native) {
            IrcNetworks::useW47EndpointRustFfi($native);
            self::assertSame(IrcNetworks::routeEndpointsPhp($endpoints), IrcNetworks::routeEndpoints($endpoints));
            PredbStreamListener::useW47FrameRustFfi($native);
            self::assertSame(PredbStreamListener::classifyFramesPhp(['event: hb']), PredbStreamListener::classifyFrames(['event: hb']));
            CloudflareIpRangeService::useW47CidrMatchRustFfi($native);
            self::assertSame([20, 0], CloudflareIpRangeService::matchAddresses(['173.245.48.9'], ['173.245.48.0/20']));
            IndexerProxyDetector::useW47ScoreRustFfi($native);
            self::assertSame([4 << 8], IndexerProxyDetector::scoreRequests(['8.8.8.8'], ['SABnzbd'], [''], ''));
            ProxyRequestContext::useW47XffRustFfi($native);
            self::assertSame([0, 2 | 1 << 8 | 1 << 18], ProxyRequestContext::resolveForwardedChains(['10.0.0.1'], ['1.1.1.1, 10.2.2.2'], ['10.0.0.0/8']));
        }
    }

    public function test_irc_endpoint_parity(): void
    {
        $this->requireBindings();
        $crc = static fn (string $value): int => crc32($value);
        $expected = [
            1 | 1 << 2 | 6667 << 8, $crc('irc.synirc.net:6667'),
            1 | 1 << 1 | 1 << 2 | 7000 << 8 | 2 << 24 | 1 << 32, $crc('irc.example.org:7000'),
            -1, 0,
            1 | 3 << 2 | 6697 << 8, $crc('2001:db8::1:6697'),
            1 | 1 << 1 | 2 << 2 | 6697 << 8 | 1 << 24, $crc('10.0.0.2:6697'),
            -2, 0, -3, 0, -4, 0, -3, 0, -2, 0, -3, 0,
        ];
        $specs = ['irc.synirc.net', ' ircs://Irc.Example.org:+7000/#a,#b,bad, ', '', '[2001:DB8::1]:6697', 'IRCS://10.0.0.2/#pre', '1.2.3', 'host:0', '[zz]', '[::1]x', '-bad.example', 'host:+'];
        self::assertSame($expected, IrcNetworks::routeEndpointsPhp($specs));
        $this->assertEndpointParity($specs);
        $this->assertEndpointParity([]);
        $this->assertEndpointParity(['']);
        $this->assertEndpointParity(["a\0b"]);
        $this->assertEndpointParity([str_repeat('a', 63).'.net', str_repeat('a', 64).'.net', str_repeat('a.', 127).'a', 'host:65535', 'host:65536', 'h:/'.str_repeat('#c,', 300), "h/#a b,#\x07,&ok"]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertEndpointParity($this->endpointBatch(new Randomizer(new Mt19937(4710 + $i)), $uncommon));
            }
        }
    }

    public function test_stream_frame_parity(): void
    {
        $this->requireBindings();
        $sse = ": hi\r\nevent: update\r\ndata: {\"action\":\r\ndata: \"update\"}\nid: 7\rretry: 1500\nfoo\n\n";
        $frames = ['{"action" : "insert","row":{}}', '  {"message":{"release":"x"}}', '{"hb":1}', $sse, 'event: hb', '', 'data:plain', 'retry: 12x', "\n{\"action\":\"delete\"}"];
        $expected = [
            2 | 1 << 8 | 1 << 27, -1,
            4 | 1 << 8 | 1 << 27, -1,
            1 | 1 << 8 | 1 << 27, -1,
            3 | 2 << 8 | 1 << 16 | 1 << 24 | 1 << 25 | 1 << 26 | 1 << 32, 1500,
            1 | 1 << 26, -1,
            0, -1,
            5 | 1 << 8, -1,
            0, -1,
            5 | 1 << 8 | 1 << 27, -1,
        ];
        self::assertSame($expected, PredbStreamListener::classifyFramesPhp($frames));
        $this->assertFrameParity($frames);
        $this->assertFrameParity([]);
        $this->assertFrameParity(['', "\r\n\r\n", ':', 'data', 'data:', 'retry: 1234567890123456789', 'retry: 123456789012345678', "id\nid: x", "a\0b"]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertFrameParity($this->frameBatch(new Randomizer(new Mt19937(4720 + $i)), $uncommon));
            }
        }
    }

    public function test_cidr_match_parity(): void
    {
        $this->requireBindings();
        $ranges = ['173.245.48.0/20', 'bad', '2400:cb00::/32', '10.1.2.3/8', '1.2.3.4/33', '::/0', '0.0.0.0/0'];
        $addresses = ['173.245.50.1', '2400:cb00:1::5', '10.9.9.9', '8.8.8.8', 'nope', '::ffff:173.245.50.1', '01.2.3.4'];
        $expected = [20, -1, 32 | 1 << 9, 8 | 1 << 8, -1, 1 << 9, 0, 0, 2, 3, 6, -2, 5, -2];
        self::assertSame($expected, CloudflareIpRangeService::matchAddressesPhp($addresses, $ranges));
        $this->assertCidrParity($addresses, $ranges);
        $this->assertCidrParity([], []);
        $this->assertCidrParity([''], []);
        $this->assertCidrParity([], ['']);
        $this->assertCidrParity(['1.2.3.4'], ['1.2.3.4/032', '1.2.3.4/', '/8', '1.2.3.4/1234', '1.2.3.4/3x', '1::/128', '1::/129', '1.2.3.4/8/9']);
        $this->assertCidrParity(["a\0b"], self::CLOUDFLARE);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                [$batch, $table] = $this->cidrBatch(new Randomizer(new Mt19937(4730 + $i)), $uncommon);
                $this->assertCidrParity($batch, $table);
            }
        }
    }

    public function test_proxy_score_parity(): void
    {
        $this->requireBindings();
        $patterns = " Hydra.Local:5076 ,\r\n\nprowlarr,,";
        $ips = ['192.168.1.5', '8.8.8.8', 'bad'];
        $agents = ['NZBHydra2/5.0', 'SABnzbd/4.0', ''];
        $referers = ['http://hydra.local:5076/x', '', 'https://user@[fe80::1]:80/'];
        self::assertSame([85 | (1 | 2 | 16 | 64) << 8, 4 << 8, 25 | (8 | 32 | 64) << 8], IndexerProxyDetector::scoreRequestsPhp($ips, $agents, $referers, $patterns));
        $this->assertScoreParity($ips, $agents, $referers, $patterns);
        $this->assertScoreParity([], [], [], $patterns);
        $this->assertScoreParity([''], [''], [''], '');
        $this->assertScoreParity(['1.1.1.1', '2.2.2.2'], ['Sonarr'], ['x'], 'x');
        $this->assertScoreParity(['1.1.1.1'], ["Prowlarr\0"], [''], 'x');
        $this->assertScoreParity(['127.0.0.1', '100.64.0.1', '172.31.0.1', '172.32.0.1', '::1', 'fc00::1', 'fe80::1', 'fec0::1'], array_fill(0, 8, 'Radarr'), ['ftp://localhost', 'HTTP://LOCALHOST', 'http://box.LAN#x', 'http://a@b@10.0.0.1:9/', 'http://[::1', 'https://[fd00::1]/', 'http://x.local?y', 'https://192.168.0.1'], "\t");
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                [$a, $b, $c, $d] = $this->scoreBatch(new Randomizer(new Mt19937(4740 + $i)), $uncommon);
                $this->assertScoreParity($a, $b, $c, $d);
            }
        }
    }

    public function test_forwarded_chain_parity(): void
    {
        $this->requireBindings();
        $trusted = ['10.0.0.0/8', '203.0.113.7', 'junk', '::1', 'REMOTE_ADDR', '1.2.3.4/'];
        $remotes = ['10.0.0.1', '10.0.0.1', '8.8.8.8', '::1', '::1', '10.0.0.1', 'bad'];
        $chains = ['198.51.100.2, 203.0.113.7:443', '1.1.1.1,10.2.2.2 , [2001:db8::1]:80', '1.1.1.1', " \t", '1.1.1.1,,x', '10.1.1.1,10.2.2.2', ''];
        $expected = [
            0, 2 | 1 << 8 | 1 << 16 | 1 << 18,
            2, 3 | 1 << 16 | 1 << 17 | 1 << 18,
            -1, 1 | 1 << 18,
            -3, 1 << 18,
            -2, 3 | 1 << 18 | 2 << 24,
            0, 2 | 2 << 8 | 1 << 18,
            -1, 0,
        ];
        self::assertSame($expected, ProxyRequestContext::resolveForwardedChainsPhp($remotes, $chains, $trusted));
        $this->assertChainParity($remotes, $chains, $trusted);
        $this->assertChainParity([], [], $trusted);
        $this->assertChainParity(['10.0.0.1'], [''], []);
        $this->assertChainParity(['10.0.0.1', '10.0.0.2'], ['1.1.1.1'], $trusted);
        $this->assertChainParity(['10.0.0.1'], ["1.1.1.1\0"], $trusted);
        $this->assertChainParity(array_fill(0, 6, '10.0.0.1'), ['[::1', '[]', '[::1]:', '[::1]:123456', '1.1.1.1:', '1:2', '::ffff:1.2.3.4'], $trusted);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                [$a, $b, $c] = $this->chainBatch(new Randomizer(new Mt19937(4750 + $i)), $uncommon);
                $this->assertChainParity($a, $b, $c);
            }
        }
    }

    /**
     * Seeded fuzz over IP-like noise, which exercises the filter_var()/inet_pton() mirror.
     */
    public function test_randomized_malformed_input_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(4799));
        for ($i = 0; $i < 300; $i++) {
            $noise = [];
            for ($j = $random->getInt(0, 16); $j > 0; $j--) {
                $noise[] = $this->ipish($random);
            }
            $this->assertEndpointParity(array_map(fn (string $ip): string => $random->getInt(0, 1) === 0 ? '['.$ip.']:'.$random->getInt(0, 70000) : $ip, $noise));
            $this->assertCidrParity($noise, array_map(fn (string $ip): string => $ip.'/'.$random->getInt(0, 130), array_reverse($noise)));
            $this->assertScoreParity($noise, array_fill(0, \count($noise), 'Jackett'), array_map(fn (string $ip): string => 'http://'.$ip.'/', $noise), '');
            $this->assertChainParity(array_fill(0, \count($noise), '10.0.0.1'), array_map(fn (string $ip): string => $ip.', '.$ip, $noise), ['10.0.0.0/8', ...\array_slice($noise, 0, 3)]);
        }
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on fresh copies of one randomly chosen fixture in random order. Common
     * batches hold 64 typical items, Uncommon batches 128 long, IPv6-heavy or malformed ones.
     * Nearest-rank p99. Every pair must agree; raw pairs are written outside the checkout.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(47));
        $summary = $records = [];
        foreach (array_keys(self::STEMS) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $this->toggle($class, $native);
                        $args = $fixtures[$variant];
                        $call = $this->entry($class);
                        $start = hrtime(true);
                        $results[(int) $native] = $call(...$args);
                        $elapsed[(int) $native] = hrtime(true) - $start;
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
            $this->reset($class);
        }
        $artifact = sys_get_temp_dir().'/w471-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'seed' => 47, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW47.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertCount(5, $summary);
    }

    /** @return array<class-string, bool> */
    private function defaults(): array
    {
        return [
            IrcNetworks::class => IrcNetworks::W47_ENDPOINT_RUST_FFI_DEFAULT,
            PredbStreamListener::class => PredbStreamListener::W47_FRAME_RUST_FFI_DEFAULT,
            CloudflareIpRangeService::class => CloudflareIpRangeService::W47_CIDR_MATCH_RUST_FFI_DEFAULT,
            IndexerProxyDetector::class => IndexerProxyDetector::W47_SCORE_RUST_FFI_DEFAULT,
            ProxyRequestContext::class => ProxyRequestContext::W47_XFF_RUST_FFI_DEFAULT,
        ];
    }

    /** @param  key-of<self::STEMS>  $class */
    private function toggle(string $class, ?bool $enabled): void
    {
        match ($class) {
            IrcNetworks::class => IrcNetworks::useW47EndpointRustFfi($enabled),
            PredbStreamListener::class => PredbStreamListener::useW47FrameRustFfi($enabled),
            CloudflareIpRangeService::class => CloudflareIpRangeService::useW47CidrMatchRustFfi($enabled),
            IndexerProxyDetector::class => IndexerProxyDetector::useW47ScoreRustFfi($enabled),
            ProxyRequestContext::class => ProxyRequestContext::useW47XffRustFfi($enabled),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function uses(string $class): bool
    {
        return match ($class) {
            IrcNetworks::class => IrcNetworks::usesW47EndpointRustFfi(),
            PredbStreamListener::class => PredbStreamListener::usesW47FrameRustFfi(),
            CloudflareIpRangeService::class => CloudflareIpRangeService::usesW47CidrMatchRustFfi(),
            IndexerProxyDetector::class => IndexerProxyDetector::usesW47ScoreRustFfi(),
            ProxyRequestContext::class => ProxyRequestContext::usesW47XffRustFfi(),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function reset(string $class): void
    {
        match ($class) {
            IrcNetworks::class => IrcNetworks::resetW47EndpointRustFfi(),
            PredbStreamListener::class => PredbStreamListener::resetW47FrameRustFfi(),
            CloudflareIpRangeService::class => CloudflareIpRangeService::resetW47CidrMatchRustFfi(),
            IndexerProxyDetector::class => IndexerProxyDetector::resetW47ScoreRustFfi(),
            ProxyRequestContext::class => ProxyRequestContext::resetW47XffRustFfi(),
        };
    }

    /** @param  key-of<self::STEMS>  $class */
    private function entry(string $class): Closure
    {
        return match ($class) {
            IrcNetworks::class => IrcNetworks::routeEndpoints(...),
            PredbStreamListener::class => PredbStreamListener::classifyFrames(...),
            CloudflareIpRangeService::class => CloudflareIpRangeService::matchAddresses(...),
            IndexerProxyDetector::class => IndexerProxyDetector::scoreRequests(...),
            ProxyRequestContext::class => ProxyRequestContext::resolveForwardedChains(...),
        };
    }

    /**
     * @param  key-of<self::STEMS>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            IrcNetworks::class => [$this->endpointBatch($random, $uncommon)],
            PredbStreamListener::class => [$this->frameBatch($random, $uncommon)],
            CloudflareIpRangeService::class => $this->cidrBatch($random, $uncommon),
            IndexerProxyDetector::class => $this->scoreBatch($random, $uncommon),
            ProxyRequestContext::class => $this->chainBatch($random, $uncommon),
        };
    }

    /**
     * Each Wave 47 export must resolve and accept a one-item batch, so parity runs Rust.
     */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            IrcNetworks::class => ['nntmux_w47_irc_endpoint_route_batch', ['a', 1, 1], 16],
            PredbStreamListener::class => ['nntmux_w47_predb_stream_frame_batch', ['a', 1, 1], 16],
            CloudflareIpRangeService::class => ['nntmux_w47_cloudflare_cidr_match_batch', ['a', 1, 1, 'b', 1, 1], 16],
            IndexerProxyDetector::class => ['nntmux_w47_proxy_signal_score_batch', ['a', 1, 'b', 1, 'c', 1, 1, '', 0], 8],
            ProxyRequestContext::class => ['nntmux_w47_proxy_forwarded_chain_batch', ['a', 1, 'b', 1, 1, '', 0, 0], 16],
        ];
        foreach ($calls as $class => [$symbol, $args, $bytes]) {
            $this->reset($class);
            $ffi = (new ReflectionMethod($class, 'w47Ffi'))->invoke(null);
            self::assertInstanceOf(FFI::class, $ffi, $class.' must load the cleaning_w47 binding');
            $out = $ffi->new("char[{$bytes}]");
            self::assertSame($bytes, $this->invoke($ffi, $symbol, [...$args, $out, $bytes]), $symbol);
        }
    }

    /**
     * Calls an export declared by a class's W47_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<string>  $specs */
    private function assertEndpointParity(array $specs): void
    {
        $php = IrcNetworks::routeEndpointsPhp($specs);
        self::assertCount(2 * \count($specs), $php);
        self::assertSame($php, IrcNetworks::routeEndpointsNative($specs), json_encode($specs, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /** @param  list<string>  $frames */
    private function assertFrameParity(array $frames): void
    {
        $php = PredbStreamListener::classifyFramesPhp($frames);
        self::assertCount(2 * \count($frames), $php);
        self::assertSame($php, PredbStreamListener::classifyFramesNative($frames), json_encode($frames, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $ranges
     */
    private function assertCidrParity(array $addresses, array $ranges): void
    {
        $php = CloudflareIpRangeService::matchAddressesPhp($addresses, $ranges);
        self::assertCount(\count($addresses) + \count($ranges), $php);
        self::assertSame($php, CloudflareIpRangeService::matchAddressesNative($addresses, $ranges), json_encode([$addresses, $ranges]) ?: '');
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $agents
     * @param  list<string>  $referers
     */
    private function assertScoreParity(array $ips, array $agents, array $referers, string $patterns): void
    {
        $php = IndexerProxyDetector::scoreRequestsPhp($ips, $agents, $referers, $patterns);
        self::assertCount(\count($ips), $php);
        self::assertSame($php, IndexerProxyDetector::scoreRequestsNative($ips, $agents, $referers, $patterns), json_encode([$ips, $agents, $referers, $patterns]) ?: '');
    }

    /**
     * @param  list<string>  $remotes
     * @param  list<string>  $chains
     * @param  list<string>  $trusted
     */
    private function assertChainParity(array $remotes, array $chains, array $trusted): void
    {
        $php = ProxyRequestContext::resolveForwardedChainsPhp($remotes, $chains, $trusted);
        self::assertCount(2 * \count($remotes), $php);
        self::assertSame($php, ProxyRequestContext::resolveForwardedChainsNative($remotes, $chains, $trusted), json_encode([$remotes, $chains, $trusted]) ?: '');
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, string $alphabet, int $max): string
    {
        $length = $random->getInt(0, $max);

        return $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
    }

    private function ipish(Randomizer $random): string
    {
        return match ($random->getInt(0, 4)) {
            0 => implode('.', [$random->getInt(0, 300), $random->getInt(0, 300), $random->getInt(0, 300), $random->getInt(0, 300)]),
            1 => $this->pick($random, ['10.', '192.168.', '172.16.', '100.64.', '127.0.', '169.254.', '01.', '0.']).$random->getInt(0, 255).'.'.$random->getInt(0, 99),
            2, 3 => $this->noise($random, '0123456789abcdefABCDEF:.', 22),
            default => $this->pick($random, ['::1', '::', 'fe80::1', 'fc00::5', '2400:cb00::1', '::ffff:1.2.3.4', '1::2:3:4:5:6:7', '1:2:3:4::5:6:7:8', '1:2:3:4:5:6:1.2.3.4', '1:2:3:4:5:6:7:1.2.3.4', ':1::', '::1.2.3.4', '1:2.3.4.5', '', '1.2.3.4 ', '12345::', '1:::2']),
        };
    }

    private function v6(Randomizer $random): string
    {
        return $this->pick($random, ['2400:cb00', '2606:4700', '2a06:98c0', '2001:db8', 'fe80', 'fd12']).':'.dechex($random->getInt(0, 65535)).'::'.dechex($random->getInt(1, 65535));
    }

    /** @return list<string> */
    private function endpointBatch(Randomizer $random, bool $uncommon): array
    {
        $batch = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $batch[] = $uncommon
                ? $this->pick($random, ['ircs://', 'irc://', '', ' ']).$this->pick($random, ['['.$this->v6($random).']', str_repeat('sub'.$random->getInt(0, 9).'.', $random->getInt(3, 20)).'example.org', $random->getInt(0, 300).'.'.$random->getInt(0, 255).'.0.1', $this->noise($random, 'abc019-._[]:', 24)])
                    .$this->pick($random, ['', ':'.$random->getInt(0, 70000), ':+'.$random->getInt(1, 9999), ':x'])
                    .'/'.implode(',', array_map(fn (int $n): string => $this->pick($random, ['#pre', '&local', '#'.str_repeat('x', $n), 'bad', '', '#a b']), range(1, $random->getInt(1, 24))))
                : $this->pick($random, ['irc.synirc.net', 'irc.corrupt-net.org', 'irc.abjects.net', 'irc.zenet.org', 'ircs://irc.predb.org', 'irc.libera.chat'])
                    .$this->pick($random, ['', ':6667', ':+6697', ':7000'])
                    .$this->pick($random, ['', '/#pre', '/#pre,#spam', '/#pre-announce,&local']);
        }

        return $batch;
    }

    /** @return list<string> */
    private function frameBatch(Randomizer $random, bool $uncommon): array
    {
        $batch = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $name = 'Some.Release.Name.'.$random->getInt(1000, 9999).'.1080p.WEB.H264-GRP';
            if ($uncommon) {
                $eol = $this->pick($random, ["\n", "\r\n", "\r"]);
                $lines = [': keepalive '.$random->getInt(0, 999), 'event: '.$this->pick($random, ['update', 'insert', 'ping', 'message']), 'id: '.$random->getInt(1, 1 << 30), 'retry: '.$random->getInt(0, 99999)];
                foreach (str_split('{"action" : "'.$this->pick($random, ['insert', 'update', 'nuke']).'", "row": {"name":"'.$name.'","team":"GRP","cat":"TV","size":'.$random->getInt(1, 99999).',"files":'.$random->getInt(1, 99).',"preAt":'.$random->getInt(1600000000, 1800000000).'}}', $random->getInt(12, 40)) as $chunk) {
                    $lines[] = 'data: '.$chunk;
                }
                $lines[] = $this->pick($random, ['x-extra: 1', 'data', ':', '']);
                $batch[] = implode($eol, $lines).$eol.$eol;
            } else {
                $batch[] = match ($random->getInt(0, 5)) {
                    0 => '{"hb":'.$random->getInt(1600000000, 1800000000).'}',
                    1 => '{"message":{"pretime":'.$random->getInt(1600000000, 1800000000).',"release":"'.$name.'","section":"TV","group":"GRP"}}',
                    default => '{"action":"'.$this->pick($random, ['insert', 'update']).'","row":{"name":"'.$name.'","team":"GRP","cat":"TV-HD","size":'.$random->getInt(1, 99999).',"files":'.$random->getInt(1, 99).',"preAt":'.$random->getInt(1600000000, 1800000000).',"nuke":null}}',
                };
            }
        }

        return $batch;
    }

    /** @return array{list<string>, list<string>} */
    private function cidrBatch(Randomizer $random, bool $uncommon): array
    {
        $addresses = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $addresses[] = $uncommon
                ? match ($random->getInt(0, 3)) {
                    0 => $this->v6($random),
                    1 => $random->getInt(1, 223).'.'.$random->getInt(0, 255).'.'.$random->getInt(0, 255).'.'.$random->getInt(0, 255),
                    2 => '::ffff:'.$random->getInt(1, 223).'.'.$random->getInt(0, 255).'.0.1',
                    default => $this->ipish($random),
                }
            : $this->pick($random, ['173.245.', '104.16.', '172.64.', '162.158.', '198.41.']).$random->getInt(0, 255).'.'.$random->getInt(0, 255);
        }
        $ranges = self::CLOUDFLARE;
        if ($uncommon) {
            array_push($ranges, '10.0.0.0/8', '192.168.0.0/16', 'fd00::/8', '2001:db8::1/32', 'bad/24', '1.2.3.4/33');
        }

        return [$addresses, $ranges];
    }

    /** @return array{list<string>, list<string>, list<string>, string} */
    private function scoreBatch(Randomizer $random, bool $uncommon): array
    {
        $ips = $agents = $referers = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            if ($uncommon) {
                $ips[] = $random->getInt(0, 1) === 0 ? $this->v6($random) : $this->ipish($random);
                $agents[] = 'Mozilla/5.0 (X11; Linux x86_64; rv:'.$random->getInt(60, 140).'.0) Gecko/20100101 '.$this->pick($random, ['Firefox/128.0', 'Prowlarr/1.21.2.4649 (ubuntu 24.04)', 'NZBHydra2/7.4.0', 'SABnzbd/4.3.3', '']).str_repeat(' compat', $random->getInt(0, 12));
                $referers[] = $this->pick($random, ['https://user:pass@hydra.home.lan:5076/api?apikey=x&t=search', 'http://[fd00::'.dechex($random->getInt(1, 999)).']:9696/1/api', 'https://www.example.com/'.str_repeat('path/', $random->getInt(1, 30)), 'HTTP://LOCALHOST:8080/', '']);
            } else {
                $ips[] = $this->pick($random, ['203.0.113.', '198.51.100.', '192.168.1.', '10.0.0.']).$random->getInt(1, 254);
                $agents[] = $this->pick($random, ['NZBHydra2/7.4.0', 'Prowlarr/1.21.2.4649 (ubuntu 24.04)', 'SABnzbd/4.3.3', 'NZBGet/24.3', 'Sonarr/4.0.9.2244 (ubuntu 22.04)', 'Mozilla/5.0']);
                $referers[] = $this->pick($random, ['', '', 'http://hydra.local:5076/', 'https://example.com/']);
            }
        }

        return [$ips, $agents, $referers, "hydra\nprowlarr:9696, jackett"];
    }

    /** @return array{list<string>, list<string>, list<string>} */
    private function chainBatch(Randomizer $random, bool $uncommon): array
    {
        $remotes = $chains = [];
        for ($i = $uncommon ? 128 : 64; $i > 0; $i--) {
            $remotes[] = $this->pick($random, ['173.245.48.'.$random->getInt(0, 255), '2400:cb00::'.dechex($random->getInt(1, 65535)), '10.0.0.'.$random->getInt(1, 9), '8.8.8.8']);
            if ($uncommon) {
                $hops = [];
                for ($h = $random->getInt(3, 9); $h > 0; $h--) {
                    $hops[] = $this->pick($random, ['['.$this->v6($random).']:'.$random->getInt(1, 65535), '162.158.'.$random->getInt(0, 255).'.'.$random->getInt(0, 255).':443', $this->v6($random), '198.51.100.'.$random->getInt(0, 255), $this->ipish($random)]);
                }
                $chains[] = implode($this->pick($random, [',', ', ', " ,\t"]), $hops);
            } else {
                $chains[] = $this->pick($random, ['203.0.113.'.$random->getInt(1, 254), '203.0.113.'.$random->getInt(1, 254).', 162.158.1.'.$random->getInt(1, 254), '']);
            }
        }

        return [$remotes, $chains, [...self::CLOUDFLARE, '10.0.0.0/8', '127.0.0.1', '::1']];
    }
}
