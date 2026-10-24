<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RedisMonitorService;
use App\Services\SystemMetricsService;
use App\Services\Tmux\Tmux;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Paired randomized single-call benchmark evidence (40 paired runs x 128 iterations, hrtime(true)):
 *
 * | Method / Workload                             | Rust mean (p99)        | PHP mean (p99)          | Speedup (mean / p99) | Production Path |
 * | --------------------------------------------- | ---------------------- | ----------------------- | -------------------- | --------------- |
 * | Tmux::getUSPConnections (common 24 lines)     | 2556.1 ns (3092.1 ns)  | 13245.2 ns (15862.0 ns) | 5.18x / 5.13x        | Rust FFI        |
 * | Tmux::getUSPConnections (uncommon 200 lines)  | 15967.6 ns (21205.4 ns)| 114184.9 ns (134186.8ns)| 7.15x / 6.33x        | Rust FFI        |
 * | Tmux::socketLineHasPeerPort (common)          | 182.1 ns (267.9 ns)    | 193.0 ns (254.9 ns)     | 1.06x / 0.95x        | PHP (<1.5x)     |
 * | Tmux::socketLineHasPeerPort (uncommon)        | 178.2 ns (235.4 ns)    | 228.1 ns (508.8 ns)     | 1.28x / 2.16x        | PHP (<1.5x)     |
 * | Tmux::socketLineMatchesAnyAddress (common 4)  | 306.9 ns (384.7 ns)    | 235.5 ns (531.5 ns)     | 0.77x / 1.38x        | PHP (<1.5x)     |
 * | Tmux::socketLineMatchesAnyAddress (uncommon33)| 655.6 ns (1253.7 ns)   | 2567.5 ns (3005.9 ns)   | 3.92x / 2.40x        | PHP (common<1.5)|
 * | Tmux::extractPeerEndpoint (common)            | 248.9 ns (267.6 ns)    | 142.2 ns (147.5 ns)     | 0.57x / 0.55x        | PHP (<1.5x)     |
 * | Tmux::extractPeerEndpoint (uncommon)          | 301.7 ns (559.9 ns)    | 186.2 ns (317.7 ns)     | 0.62x / 0.57x        | PHP (<1.5x)     |
 * | Tmux::decodeSize (common)                     | 209.5 ns (217.4 ns)    | 134.3 ns (139.7 ns)     | 0.64x / 0.64x        | PHP (<1.5x)     |
 * | Tmux::decodeSize (uncommon)                   | 214.3 ns (246.4 ns)    | 186.5 ns (192.7 ns)     | 0.87x / 0.78x        | PHP (<1.5x)     |
 * | SystemMetricsService::parseCpuCount (common8) | 162.9 ns (177.4 ns)    | 272.1 ns (316.7 ns)     | 1.67x / 1.79x        | Rust FFI        |
 * | SystemMetricsService::parseCpuCount (uncom64) | 521.8 ns (672.2 ns)    | 1495.1 ns (1933.2 ns)   | 2.87x / 2.88x        | Rust FFI        |
 * | SystemMetricsService::parseMeminfo (common)   | 189.9 ns (219.1 ns)    | 282.9 ns (315.4 ns)     | 1.49x / 1.44x        | PHP (<1.5x)     |
 * | SystemMetricsService::parseMeminfo (uncommon) | 207.6 ns (244.1 ns)    | 286.8 ns (332.7 ns)     | 1.38x / 1.36x        | PHP (<1.5x)     |
 * | SystemMetricsService::parseCpuInfo (common8)  | 423.0 ns (582.7 ns)    | 856.1 ns (930.3 ns)     | 2.02x / 1.60x        | Rust FFI        |
 * | SystemMetricsService::parseCpuInfo (uncom64)  | 785.4 ns (1277.6 ns)   | 4777.8 ns (5875.9 ns)   | 6.08x / 4.60x        | Rust FFI        |
 * | RedisMonitorService::parseRedisInfo (common)  | 1241.7 ns (1877.0 ns)  | 1875.9 ns (3175.4 ns)   | 1.51x / 1.69x        | Rust FFI        |
 * | RedisMonitorService::parseRedisInfo (uncom16) | 1890.0 ns (2610.0 ns)  | 3035.3 ns (5198.9 ns)   | 1.61x / 1.99x        | Rust FFI        |
 * | RedisMonitorService::formatBytes (common)     | 221.8 ns (352.9 ns)    | 130.3 ns (277.3 ns)     | 0.59x / 0.79x        | PHP (<1.5x)     |
 * | RedisMonitorService::formatBytes (uncommon)   | 210.6 ns (374.3 ns)    | 126.0 ns (327.1 ns)     | 0.60x / 0.87x        | PHP (<1.5x)     |
 */
final class RustTmuxSocketMetricsParserParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_tmux_extract_peer_endpoint(const char *line, size_t line_len, char *out_buf, size_t out_cap);
        int32_t nntmux_tmux_socket_line_has_peer_port(const char *line, size_t line_len, const char *port, size_t port_len);
        int32_t nntmux_tmux_socket_line_matches_any_address_nul(const char *line, size_t line_len, const char *addresses, size_t addresses_len, size_t addr_count, const char *port, size_t port_len);
        int64_t nntmux_tmux_count_usp_connections(uint8_t is_alternate, const char *port, size_t port_len, const char *other_port, size_t other_port_len, const char *ips, size_t ips_len, size_t ips_count, const char *other_ips, size_t other_ips_len, size_t other_ips_count, const char *snapshot, size_t snapshot_len);
        ssize_t nntmux_tmux_decode_size(double bytes, char *out_buf, size_t out_cap);
        int32_t nntmux_metrics_parse_cpu_count(const char *cpuinfo, size_t cpuinfo_len);
        int64_t nntmux_metrics_parse_meminfo(const char *meminfo, size_t meminfo_len);
        ssize_t nntmux_metrics_parse_cpu_info(const char *cpuinfo, size_t cpuinfo_len, char *out_buf, size_t out_cap);
        int64_t nntmux_redis_sum_db_keys_nul(const char *input, size_t input_len, size_t count);
        double nntmux_redis_hit_rate(int64_t hits, int64_t misses);
        ssize_t nntmux_redis_format_bytes(int64_t bytes, char *out_buf, size_t out_cap);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        Tmux::setUseRustFfi(true);
        SystemMetricsService::setUseRustFfi(true);
        RedisMonitorService::setUseRustFfi(true);

        $ffi = RustPipelineLibrary::resolveDomain('irc', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
    }

    protected function tearDown(): void
    {
        Tmux::setUseRustFfi(true);
        SystemMetricsService::setUseRustFfi(true);
        RedisMonitorService::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_c_abi_resize_and_error_contracts(): void
    {
        $tiny = $this->ffi->new('char[2]');
        $line = 'ESTAB 0 0 10.0.0.2:41001 185.90.196.65:563';

        // @phpstan-ignore method.notFound
        $neededPeer = $this->ffi->nntmux_tmux_extract_peer_endpoint($line, strlen($line), $tiny, 2);
        self::assertSame(-strlen('185.90.196.65:563'), $neededPeer);

        // Fewer than 5 columns returns -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_extract_peer_endpoint('ESTAB 0 0 10.0.0.2:41001', 24, $tiny, 2));

        // Whitespace in port declines with -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_socket_line_has_peer_port('ESTAB 0 0', 9, '56 3', 4));

        // Mismatched NUL address count returns -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_socket_line_matches_any_address_nul($line, strlen($line), "1.2.3.4\05.6.7.8", 15, 1, '563', 3));

        // Invalid is_alternate (> 1) returns -1
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_count_usp_connections(2, '563', 3, '563', 3, '', 0, 0, '', 0, 0, $line, strlen($line)));

        // decodeSize resize contract
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('1.5 KB'), $this->ffi->nntmux_tmux_decode_size(1536.0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_decode_size(NAN, $tiny, 2));

        // parse_cpu_info resize contract and embedded NUL decline
        $cpuinfo = "processor\t: 0\ncpu cores\t: 4\nmodel name\t: AMD EPYC 7763\n";
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("4\x001\x00AMD EPYC 7763"), $this->ffi->nntmux_metrics_parse_cpu_info($cpuinfo, strlen($cpuinfo), $tiny, 2));
        $badCpuinfo = "processor\t: 0\nmodel name\t: AMD\0EPYC\n";
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_metrics_parse_cpu_info($badCpuinfo, strlen($badCpuinfo), $tiny, 2));

        // parse_meminfo missing fields returns 0; MemTotal: 0 declines with -1
        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_metrics_parse_meminfo("MemTotal: 1024 kB\n", 18));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_metrics_parse_meminfo("MemTotal: 0 kB\nMemAvailable: 0 kB\n", 34));

        // Redis sum_db_keys count mismatch and overflow
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_redis_sum_db_keys_nul("keys=10\0keys=20", 15, 1));
        $hugeKeys = 'keys='.str_repeat('9', 25);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_redis_sum_db_keys_nul($hugeKeys, strlen($hugeKeys), 1));

        // Redis format_bytes resize contract
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('1.50 MB'), $this->ffi->nntmux_redis_format_bytes(1572864, $tiny, 2));
    }

    public function test_tmux_usp_connections_and_socket_helpers_parity(): void
    {
        /** @var Tmux $tmux */
        $tmux = (new ReflectionClass(Tmux::class))->newInstanceWithoutConstructor();
        $hasPeerPortMethod = new ReflectionMethod(Tmux::class, 'socketLineHasPeerPort');
        $hasPeerPortPhpMethod = new ReflectionMethod(Tmux::class, 'socketLineHasPeerPortPhp');
        $matchesAddrMethod = new ReflectionMethod(Tmux::class, 'socketLineMatchesAnyAddress');
        $matchesAddrPhpMethod = new ReflectionMethod(Tmux::class, 'socketLineMatchesAnyAddressPhp');
        $extractPeerMethod = new ReflectionMethod(Tmux::class, 'extractPeerEndpoint');
        $extractPeerPhpMethod = new ReflectionMethod(Tmux::class, 'extractPeerEndpointPhp');

        $lines = [
            '',
            '   ',
            'ESTAB 0 0 10.0.0.2:41001',
            'ESTAB 0 0 10.0.0.2:41001 185.90.196.65:563',
            'CLOSE-WAIT 0 0 10.0.0.2:41002 185.90.196.97:119',
            'ESTAB 0 0 10.0.0.2:41003 198.51.100.40:https',
            'ESTAB 0 0 10.0.0.2:41004 198.51.100.40:443',
            'ESTAB 0 0 [2001:db8::2]:41005 [2001:db8::10]:563 users:(("php",pid=1,fd=3))',
            'ESTAB 185.90.196.65:563',
            'ESTAB 0 0 10.0.0.2:41006 185.90.196.65',
            "a b c d\x0c",
            "\x0ca b c d e",
        ];

        foreach ($lines as $idx => $line) {
            $expectedPeer = $extractPeerPhpMethod->invoke($tmux, $line);
            $actualPeer = $extractPeerMethod->invoke($tmux, $line);
            $supported = false;
            $nativePeer = Tmux::extractPeerEndpointNative($line, $supported);

            self::assertSame($expectedPeer, $actualPeer, "extractPeerEndpoint mismatch at #{$idx}");
            self::assertTrue($supported, "extractPeerEndpointNative should support #{$idx}");
            self::assertSame($expectedPeer, $nativePeer, "extractPeerEndpointNative mismatch at #{$idx}");

            foreach (['', '119', '443', '563', 'https', '56 3'] as $port) {
                $expectedHasPort = $hasPeerPortPhpMethod->invoke($tmux, $line, $port);
                Tmux::setUseRustFfi(true);
                $actualHasPort = $hasPeerPortMethod->invoke($tmux, $line, $port);
                $nativeHasPort = Tmux::socketLineHasPeerPortNative($line, $port);

                self::assertSame($expectedHasPort, $actualHasPort, "socketLineHasPeerPort mismatch at #{$idx} port=[{$port}]");
                if ($nativeHasPort !== null) {
                    self::assertSame($expectedHasPort, $nativeHasPort, "socketLineHasPeerPortNative mismatch at #{$idx} port=[{$port}]");
                }
            }
        }

        $manyIps = [];
        for ($i = 0; $i < 40; $i++) {
            $manyIps[] = '198.51.100.'.$i;
        }
        $addressSets = [
            [],
            ['185.90.196.65'],
            ['185.90.196.65', '198.51.100.40', '2001:db8::10'],
            $manyIps,
            ["185.90.196.65\0bad", '198.51.100.40'],
        ];

        foreach ($lines as $idx => $line) {
            foreach ($addressSets as $setIdx => $addrs) {
                foreach (['', '563', '443'] as $port) {
                    $expectedMatch = $matchesAddrPhpMethod->invoke($tmux, $line, $addrs, $port);
                    Tmux::setUseRustFfi(true);
                    $actualMatch = $matchesAddrMethod->invoke($tmux, $line, $addrs, $port);
                    $nativeMatch = Tmux::socketLineMatchesAnyAddressNative($line, $addrs, $port);

                    self::assertSame($expectedMatch, $actualMatch, "socketLineMatchesAnyAddress mismatch line #{$idx} set #{$setIdx}");
                    if ($nativeMatch !== null) {
                        self::assertSame($expectedMatch, $nativeMatch, "socketLineMatchesAnyAddressNative mismatch line #{$idx} set #{$setIdx}");
                    }
                }
            }
        }

        $snapshots = [
            '',
            "   \r\n  \n",
            implode("\n", [
                'ESTAB 0 0 10.0.0.2:41001 185.90.196.65:563',
                'ESTAB 0 0 10.0.0.2:41002 185.90.196.97:563',
                'ESTAB 0 0 10.0.0.2:41003 85.12.62.250:563',
                'TIME-WAIT 0 0 10.0.0.2:41004 85.12.62.251:563',
                'ESTAB 0 0 10.0.0.2:41005 198.51.100.40:563',
                'ESTAB 0 0 10.0.0.2:41006 198.51.100.41:563',
                'CLOSE-WAIT 0 0 10.0.0.2:41007 198.51.100.41:563',
            ]),
            implode("\r\n", [
                'ESTAB 0 0 10.0.0.2:41001 185.90.196.65:119',
                "CLOSE-WAIT 0 0 10.0.0.2:41002 185.90.196.65:119\x85ESTAB 0 0 10.0.0.2:41003 198.51.100.40:443",
                'ESTAB 0 0 10.0.0.2:41004 198.51.100.40:https',
                'ESTAB 0 0 [2001:db8::2]:41005 [2001:db8::10]:563',
            ]),
        ];

        $connectionConfigs = [
            [
                'ip' => '185.90.196.65',
                'ips' => ['185.90.196.65', '185.90.196.97'],
                'port' => 563,
                'ip_a' => '198.51.100.40',
                'ips_a' => ['198.51.100.40', '198.51.100.41'],
                'port_a' => 563,
            ],
            [
                'ip' => '185.90.196.65',
                'port' => 119,
                'ip_a' => '198.51.100.40',
                'port_a' => 443,
            ],
            [
                'ip' => '185.90.196.65',
                'port' => 9999, // No port match -> triggers Pass 2 IP fallback
                'ip_a' => '2001:db8::10',
                'ips_a' => ['2001:db8::10'],
                'port_a' => '',
            ],
            [
                'ip' => '185.90.196.65',
                'ips' => $manyIps,
                'port' => 563,
                'ip_a' => false,
                'ips_a' => [],
                'port_a' => 563,
            ],
        ];

        foreach ($snapshots as $sIdx => $snapshot) {
            foreach ($connectionConfigs as $cIdx => $conns) {
                foreach (['primary', 'alternate'] as $which) {
                    Tmux::setUseRustFfi(false);
                    $expected = $tmux->getUSPConnections($which, $conns, $snapshot);

                    Tmux::setUseRustFfi(true);
                    $actual = $tmux->getUSPConnections($which, $conns, $snapshot);

                    self::assertSame($expected, $actual, "getUSPConnections mismatch snap #{$sIdx} cfg #{$cIdx} which={$which}");
                }
            }
        }
    }

    public function test_tmux_decode_size_parity(): void
    {
        /** @var Tmux $tmux */
        $tmux = (new ReflectionClass(Tmux::class))->newInstanceWithoutConstructor();

        $values = [
            0,
            0.0,
            -0.0,
            1,
            -1,
            512,
            1023,
            1024,
            -1024,
            1536,
            1048576,
            1572864,
            1073741824,
            1099511627776,
            1125899906842624,
            1152921504606846976,
            -9876543210123,
            1234567.89,
            -98765.4321,
        ];

        mt_srand(20261024);
        for ($i = 0; $i < 500; $i++) {
            $values[] = (mt_rand(0, 1) === 1 ? 1 : -1) * mt_rand(0, PHP_INT_MAX);
        }

        foreach ($values as $idx => $val) {
            $expected = $tmux->decodeSize($val);
            $native = Tmux::decodeSizeNative($val);
            self::assertSame($expected, $native, "decodeSizeNative mismatch at #{$idx} val={$val}");
        }
    }

    public function test_system_metrics_cpu_and_meminfo_parsers_parity(): void
    {
        $service = new SystemMetricsService;

        $cpuSamples = [
            '',
            "vendor_id\t: GenuineIntel\n",
            "processor\t: 0\nmodel name\t: Intel(R) Xeon(R) CPU\ncpu cores\t: 4\n",
            "processor\t: 0\nmodel name\t: 0\ncpu cores\t: 0\nphysical id\t: 0\nprocessor\t: 1\nphysical id\t: 0\nprocessor\t: 2\nphysical id\t: 1\nprocessor\t: 3\nphysical id\t: 1\n",
            "model name :   \nprocessor : 0\n",
            "not_a_processor\t: 0\nprocessor\t: 0\n",
        ];

        // Exercise SIMD 16-byte chunk alignments across 0..32 prefix padding lengths
        for ($pad = 0; $pad <= 32; $pad++) {
            $prefix = str_repeat(' ', $pad)."\n";
            $cpuSamples[] = $prefix."processor\t: 0\ncpu cores\t: 2\nmodel name\t: Apple Virtual CPU\nprocessor\t: 1\n";
            $cpuSamples[] = $prefix."processor\t: 0\nphysical id\t: 0\nmodel name\t: Multi Socket CPU\nprocessor\t: 1\nphysical id\t: 1\n";
        }

        // >32 unique physical IDs to exercise SeenCidrs overflow path
        $manySockets = '';
        for ($i = 0; $i < 40; $i++) {
            $manySockets .= "processor\t: {$i}\nphysical id\t: {$i}\nmodel name\t: Many Socket Xeon\n";
        }
        $cpuSamples[] = $manySockets;

        foreach ($cpuSamples as $idx => $cpuinfo) {
            SystemMetricsService::setUseRustFfi(false);
            $expectedCount = $service->parseCpuCount($cpuinfo);
            $expectedInfo = $service->parseCpuInfo($cpuinfo);

            SystemMetricsService::setUseRustFfi(true);
            $actualCount = $service->parseCpuCount($cpuinfo);
            $nativeCount = SystemMetricsService::parseCpuCountNative($cpuinfo);
            $actualInfo = $service->parseCpuInfo($cpuinfo);
            $nativeInfo = SystemMetricsService::parseCpuInfoNative($cpuinfo);

            self::assertSame($expectedCount, $actualCount, "parseCpuCount mismatch at #{$idx}");
            self::assertSame($expectedCount, $nativeCount, "parseCpuCountNative mismatch at #{$idx}");
            self::assertSame($expectedInfo, $actualInfo, "parseCpuInfo mismatch at #{$idx}");
            self::assertSame($expectedInfo, $nativeInfo, "parseCpuInfoNative mismatch at #{$idx}");
        }

        $memSamples = [
            '',
            "MemTotal:       16384000 kB\n",
            "MemAvailable:    8192000 kB\n",
            "MemTotal:       65536000 kB\nMemFree:        12000000 kB\nMemAvailable:   45678900 kB\n",
            "MemTotal: invalid\nMemTotal:   32768000 kB\nMemAvailable: bad\nMemAvailable: 12345678 kB\n",
            "MemTotal:       1000000 kB\nMemAvailable:   1000000 kB\n",
            "MemTotal:       1000000 kB\nMemAvailable:   2000000 kB\n", // Available > Total falls back cleanly
        ];

        mt_srand(20261024);
        for ($i = 0; $i < 200; $i++) {
            $totalKb = mt_rand(1024, 536870912);
            $availKb = mt_rand(0, $totalKb);
            $memSamples[] = "MemTotal: {$totalKb} kB\nMemAvailable: {$availKb} kB\n";
        }

        foreach ($memSamples as $idx => $meminfo) {
            SystemMetricsService::setUseRustFfi(false);
            $expectedMem = $service->parseMeminfo($meminfo);

            SystemMetricsService::setUseRustFfi(true);
            $actualMem = $service->parseMeminfo($meminfo);
            $nativeMem = SystemMetricsService::parseMeminfoNative($meminfo);

            self::assertSame($expectedMem, $actualMem, "parseMeminfo mismatch at #{$idx}");
            if ($nativeMem !== null) {
                self::assertSame($expectedMem, $nativeMem, "parseMeminfoNative mismatch at #{$idx}");
            }
        }
    }

    public function test_redis_monitor_service_parse_info_and_format_bytes_parity(): void
    {
        /** @var RedisMonitorService $redis */
        $redis = (new ReflectionClass(RedisMonitorService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(RedisMonitorService::class, 'host'))->setValue($redis, '127.0.0.1');
        (new ReflectionProperty(RedisMonitorService::class, 'port'))->setValue($redis, 6379);
        $parseMethod = new ReflectionMethod(RedisMonitorService::class, 'parseRedisInfo');
        $formatBytesMethod = new ReflectionMethod(RedisMonitorService::class, 'formatBytes');

        $infoPayloads = [
            [],
            [
                'redis_version' => '7.2.4',
                'uptime_in_days' => '14',
                'uptime_in_seconds' => '1209600',
                'role' => 'master',
                'keyspace_hits' => 0,
                'keyspace_misses' => 0,
                'db0' => 'keys=125430,expires=12000,avg_ttl=3600000',
                'db1' => 'keys=8420,expires=8420,avg_ttl=1800000',
                'db2' => 'keys=abc,keys=310,expires=0',
            ],
            [
                'Server' => ['redis_version' => '7.2.4', 'uptime_in_days' => 3, 'uptime_in_seconds' => 259200],
                'Stats' => ['keyspace_hits' => 40000000, 'keyspace_misses' => 5000000],
                'Keyspace' => [
                    'db0' => ['keys' => 500, 'expires' => 10],
                    'db1' => 'keys=1500,expires=20,avg_ttl=100',
                    'db15' => 'expires=0,keys=99,avg_ttl=0',
                ],
            ],
            [
                'keyspace_hits' => 12345,
                'keyspace_misses' => 6789,
                'db0' => "keys=100\0keys=999", // Embedded NUL falls back to PHP regex -> 100
                'db1' => 'keys=200,expires=0',
            ],
        ];

        foreach ($infoPayloads as $idx => $payload) {
            RedisMonitorService::setUseRustFfi(false);
            $expected = $parseMethod->invoke($redis, $payload);

            RedisMonitorService::setUseRustFfi(true);
            $actual = $parseMethod->invoke($redis, $payload);

            self::assertSame($expected, $actual, "parseRedisInfo mismatch at #{$idx}");
            $hits = (int) ($expected['keyspace_hits']);
            $misses = (int) ($expected['keyspace_misses']);
            if ($hits + $misses === 0) {
                self::assertSame(0, $actual['hit_rate']);
                self::assertSame(0, RedisMonitorService::hitRateNative(0, 0));
            } else {
                self::assertSame($expected['hit_rate'], RedisMonitorService::hitRateNative($hits, $misses));
            }
        }

        $byteCases = [
            -1024,
            -1,
            0,
            1,
            128,
            512,
            640,
            1023,
            1024,
            1024 + 128,
            1024 + 640,
            1048575,
            1048576,
            1572864,
            1073741823,
            1073741824,
            19876543210,
        ];

        mt_srand(20261024);
        for ($i = 0; $i < 500; $i++) {
            $byteCases[] = mt_rand(0, 100_000_000_000);
        }

        foreach ($byteCases as $idx => $b) {
            $expectedStr = $formatBytesMethod->invoke($redis, $b);
            $nativeStr = RedisMonitorService::formatBytesNative($b);
            self::assertSame($expectedStr, $nativeStr, "formatBytesNative mismatch at #{$idx} bytes={$b}");
        }
    }
}
