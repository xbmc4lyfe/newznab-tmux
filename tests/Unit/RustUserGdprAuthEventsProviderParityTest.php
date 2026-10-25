<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\GdprPurgeExpiredExports;
use App\Events\UserAccessedApi;
use App\Events\UserLoggedIn;
use App\Models\User;
use App\Observers\UserActivityObserver;
use App\Observers\UserApiCacheObserver;
use App\Observers\UserServiceObserver;
use App\Providers\UserServiceProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustUserGdprAuthEventsProviderParityTest extends TestCase
{
    /** @var list<class-string> */
    private const TOGGLED = [
        GdprPurgeExpiredExports::class,
        User::class,
        UserAccessedApi::class,
        UserLoggedIn::class,
        UserServiceProvider::class,
    ];

    private const INTS = [PHP_INT_MIN, -4294967296, -100, -1, 0, 1, 2, 7, 30, 99, 100, 101, 1000, 4294967296, PHP_INT_MAX];

    /** Addresses covering IPv4/IPv6 edge syntax, padding, case and malformed forms. */
    private const IPS = [
        null, '', ' ', '127.0.0.1', " 10.0.0.1\n", '0.0.0.0', '255.255.255.255', '256.1.1.1', '01.2.3.4', '1.2.3', '1.2.3.4.5',
        '1..2.3', '+1.2.3.4', '1.2.3.4 ', "1.2.3.4\0", '::', '::1', '1::', 'FE80::1', '2001:DB8:0:0:8:800:200C:417A',
        '1:2:3:4:5:6:7:8', '1:2:3:4:5:6:7:8:9', '1:2:3:4:5:6:7::', '1::2::3', ':::', '::::', '1:::2', '::ffff:192.168.0.1',
        '::ffff:256.1.1.1', '1.2.3.4::', '1:2:3:4:5:6:1.2.3.4', '1:2:3:4:5:6:7:1.2.3.4', '12345::', 'g::1', 'fe80::1%eth0',
        '0000:0000:0000:0000:0000:0000:0000:0001', '1:2:3:4:5:6:7:8:9:10:11:12:13:14:15:16:17:18', "\xff.1.1.1", '日本',
    ];

    private const ENDPOINTS = [
        null, '', ' ', '/', '//', 'api', '/api/v1/search', ' //api/v2/search/ ?t=search&q=x', '/api?', '#frag', '?only',
        "/rss\t?x#y", '/a//b', "\0/api\0", '/api/ v1 /', 'ünïcode/päth', "/sep\x1F\x1E", "\xff\xfe", '/api#a?b',
    ];

    private const AGENTS = [
        null, '', "\t\x7F ", 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0', " Mozilla/5.0\t\t(X11)\x00 ", 'curl/8.4.0',
        'x curl/8.4.0', 'Wget/1.21', 'python-requests/2.31', 'HTTPie/3.2', 'Go-http-client/1.1', 'Googlebot/2.1',
        'Mozilla/5.0 (compatible; bingbot/2.0)', 'SpiderMan', 'WebCrawler', 'SABnzbd/4.0', 'NZBGet/21', 'ünïcode agent',
        "bad \xff utf8", "sep\x1F\x1Emarker",
    ];

    protected function tearDown(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles_independently(): void
    {
        foreach (self::TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            foreach (array_diff(self::TOGGLED, [$class]) as $other) {
                $this->assertFalse($other::usesRustFfi(), $other.' must not follow '.$class);
            }
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['retention_days' => 30, 'candidates' => 10, 'deleted' => 4, 'failed' => 6, 'skipped' => 0, 'status' => 'partial', 'has_failures' => true],
            GdprPurgeExpiredExports::summarizePurgePlanPhp(30, 10, 4, 9),
        );
        $this->assertSame('idle', GdprPurgeExpiredExports::summarizePurgePlanPhp(-1, -5, 3, 3)['status']);
        $this->assertSame('complete', GdprPurgeExpiredExports::summarizePurgePlanPhp(7, 3, 3, 0)['status']);
        $this->assertSame(
            ['retention_days' => 7, 'candidates' => 10, 'deleted' => 0, 'failed' => 2, 'skipped' => 8, 'status' => 'failed', 'has_failures' => true],
            GdprPurgeExpiredExports::summarizePurgePlanPhp(7, 10, -4, 2),
        );

        $this->assertSame(
            ['current_requests' => 3, 'max_requests' => 10, 'allowed' => true, 'unlimited' => false, 'remaining' => 7, 'reason' => 'within_quota'],
            User::summarizeApiQuotaDecisionPhp(3, 10, false, true),
        );
        $this->assertSame('quota_exhausted', User::summarizeApiQuotaDecisionPhp(10, 10, false, true)['reason']);
        $this->assertSame(['allowed' => true, 'remaining' => null], array_intersect_key(User::summarizeApiQuotaDecisionPhp(99, 1, true, true), ['allowed' => 0, 'remaining' => 0]));
        $this->assertSame('unverified', User::summarizeApiQuotaDecisionPhp(0, 10, true, false)['reason']);

        $this->assertSame(
            ['user_id' => 0, 'ip' => '::ffff:1.2.3.4', 'ip_version' => 6, 'endpoint' => '/api/v2/search', 'has_query' => true],
            UserAccessedApi::normalizeAccessAuditPayloadPhp(-5, ' ::FFFF:1.2.3.4 ', ' //api/v2/search/ ?t=1'),
        );
        $this->assertSame(['user_id' => 9, 'ip' => null, 'ip_version' => 0, 'endpoint' => '/', 'has_query' => false], UserAccessedApi::normalizeAccessAuditPayloadPhp(9, '01.2.3.4', null));
        $this->assertSame(255, strlen(UserAccessedApi::normalizeAccessAuditPayloadPhp(1, null, str_repeat('a', 400))['endpoint']));

        $this->assertSame(
            ['user_id' => 4, 'ip' => '10.0.0.1', 'ip_version' => 4, 'user_agent' => 'Mozilla/5.0 (X11)', 'client_family' => 'browser', 'method' => 'remember'],
            UserLoggedIn::normalizeLoginAuditPayloadPhp(4, '10.0.0.1', " Mozilla/5.0\t\t(X11)\x00 ", true),
        );
        $this->assertSame('cli', UserLoggedIn::normalizeLoginAuditPayloadPhp(1, null, 'curl/8')['client_family']);
        $this->assertSame('other', UserLoggedIn::normalizeLoginAuditPayloadPhp(1, null, 'x curl/8')['client_family']);
        $this->assertSame('bot', UserLoggedIn::normalizeLoginAuditPayloadPhp(1, null, 'Googlebot Mozilla/5.0')['client_family']);
        $this->assertSame(['user_agent' => null, 'client_family' => 'unknown', 'method' => 'password'], array_slice(UserLoggedIn::normalizeLoginAuditPayloadPhp(1, null, "\t\x7F "), 3));
        $this->assertSame(511, strlen((string) UserLoggedIn::normalizeLoginAuditPayloadPhp(1, null, str_repeat('a', 511).'  b')['user_agent']));

        $this->assertSame(
            ['enabled' => true, 'observers' => ['App\\Observers\\UserObserver', "_Ok\xc3\xa9"], 'invalid' => 4, 'duplicates' => 1],
            UserServiceProvider::normalizeObserverRegistrationPlanPhp([' \\App\\Observers\\UserObserver ', 'app\\observers\\userobserver', 42, '1Bad', 'App\\', "_Ok\xc3\xa9", '']),
        );
        $this->assertSame(['enabled' => false, 'observers' => [], 'invalid' => 1, 'duplicates' => 0], UserServiceProvider::normalizeObserverRegistrationPlanPhp(['A', null], false));
    }

    public function test_boot_plan_registers_the_three_user_observers(): void
    {
        $observers = [UserServiceObserver::class, UserActivityObserver::class, UserApiCacheObserver::class];
        foreach ([false, true] as $useRust) {
            UserServiceProvider::setUseRustFfi($useRust);
            $this->assertSame(
                ['enabled' => true, 'observers' => $observers, 'invalid' => 0, 'duplicates' => 0],
                UserServiceProvider::normalizeObserverRegistrationPlan($observers),
            );
        }
    }

    public function test_native_and_dispatch_match_php_on_edge_grids(): void
    {
        foreach (self::INTS as $a) {
            foreach (self::INTS as $b) {
                $this->assertPurgeParity($a, $b, $b, $a);
                $this->assertPurgeParity(30, $a, $b, $this->negate($b));
                foreach ([[false, false], [false, true], [true, false], [true, true]] as [$admin, $verified]) {
                    $this->assertQuotaParity($a, $b, $admin, $verified);
                }
            }
        }
        foreach (self::IPS as $ip) {
            foreach (self::ENDPOINTS as $endpoint) {
                $this->assertAccessParity(7, $ip, $endpoint);
            }
            foreach (self::AGENTS as $agent) {
                $this->assertLoginParity(7, $ip, $agent, $agent === null);
            }
        }
        $this->assertAccessParity(PHP_INT_MIN, str_repeat('1', 46), str_repeat("/\0x", 5000));
        $this->assertLoginParity(PHP_INT_MAX, null, str_repeat("ab \t", 3000), true);
        $this->assertPlanParity([], true);
        $this->assertPlanParity([1, 2.5, null, true, [], new \stdClass], true);
        $this->assertPlanParity(['b' => 'App\\B', 'a' => '\\\\App\\B', 'App\\b\\', ' \\App\\C ', "App\\\x80"], false);
        $this->assertPlanParity(array_map(static fn (int $i): string => 'App\\Observers\\O'.($i % 50), range(1, 300)), true);
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3610));
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $alphabet = ['a', 'F', 'g', '0', '1', '9', '.', ':', '/', '\\', '?', '#', '_', ' ', "\t", "\n", "\0", "\x0B", "\x7F", 'é', '日', "\xff", 'bot', 'curl/', 'Mozilla/'];
        $text = static function (int $max) use ($random, $alphabet): ?string {
            if ($random->getInt(0, 9) === 0) {
                return null;
            }
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 2)) {
            0 => $random->getInt(-5, 20),
            1 => $random->getInt(-100_000, 100_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };

        for ($i = 0; $i < 2000; $i++) {
            $this->assertPurgeParity($int(), $int(), $int(), $int());
            $this->assertQuotaParity($int(), $int(), $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1);
            $ip = $random->getInt(0, 1) === 1 ? $pick(self::IPS) : $text(20);
            $this->assertAccessParity($int(), $ip, $text(40));
            $this->assertLoginParity($int(), $ip, $random->getInt(0, 1) === 1 ? $pick(self::AGENTS) : $text(60), $random->getInt(0, 1) === 1);
            $names = [];
            for ($n = $random->getInt(0, 6); $n > 0; $n--) {
                $names[] = $random->getInt(0, 5) === 0 ? $random->getInt(0, 9) : $pick(['App\\A', 'app\\a', '\\App\\B', 'C', '_d', '9e', 'F\\', ''])."{$text(4)}";
            }
            $this->assertPlanParity($names, $random->getInt(0, 3) !== 0);
        }
    }

    public function test_native_export_validates_frames_pointers_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $input = pack('q4', 30, 10, 4, 9);

        $small = $ffi->new('char[8]');
        FFI::memset($small, ord('Z'), 8);
        $this->assertSame(-56, $this->callFfi($ffi, 1, $input, 32, $small, 8));
        $this->assertSame(-56, $this->callFfi($ffi, 1, $input, 32, $small, 55));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));

        $out = $ffi->new('char[256]');
        $this->assertSame(56, $this->callFfi($ffi, 1, $input, 32, $out, 256));
        $this->assertSame(pack('q7', 30, 10, 4, 6, 0, 2, 1), FFI::string($out, 56));
        $this->assertSame(32, $this->callFfi($ffi, 5, pack('q2', 1, 0), 16, $out, 256));
        $this->assertSame(pack('q4', 1, 0, 0, 0), FFI::string($out, 32));

        FFI::memset($out, ord('Z'), 256);
        // Unknown operations, truncated/oversized frames, invalid flags and lengths decline with -1.
        foreach ([[0, $input], [6, $input], [255, $input], [1, substr($input, 0, 24)], [1, $input.'x'], [2, pack('q4', 1, 2, 2, 1)],
            [3, pack('q2', 1, -2)], [3, pack('q2', 1, PHP_INT_MAX)], [4, pack('q3', 1, 0, -1)], [5, pack('q2', 1, PHP_INT_MAX)],
            [5, pack('q2', 1, -1)], [5, pack('q3', 1, 2, -1)], [1, ''],
        ] as [$operation, $frame]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $frame, strlen($frame), $out, 256), "op {$operation} ".bin2hex($frame));
        }
        // Null pointers and oversized lengths/capacities decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 1, null, 32, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 1, $input, 32, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 1, $input, 16_777_217, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 1, $input, 32, $out, 16_777_217));
        $this->assertSame(str_repeat('Z', 256), FFI::string($out, 256));

        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(true);
        }
        GdprPurgeExpiredExports::summarizePurgePlan(1, 1, 1, 0);
        User::summarizeApiQuotaDecision(1, 2, false, true);
        UserAccessedApi::normalizeAccessAuditPayload(1, '::1', '/api');
        UserLoggedIn::normalizeLoginAuditPayload(1, '::1', 'curl/8');
        UserServiceProvider::normalizeObserverRegistrationPlan(['App\\A']);
        foreach (self::TOGGLED as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $saved = [];
        foreach (self::TOGGLED as $class) {
            $property = new ReflectionProperty($class, 'ffi');
            $saved[$class] = $property->getValue();
            $class::setUseRustFfi(true);
            $property->setValue(null, false);
        }

        try {
            $this->assertNull(GdprPurgeExpiredExports::summarizePurgePlanNative(30, 10, 4, 9));
            $this->assertNull(User::summarizeApiQuotaDecisionNative(3, 10, false, true));
            $this->assertNull(UserAccessedApi::normalizeAccessAuditPayloadNative(1, '::1', '/api'));
            $this->assertNull(UserLoggedIn::normalizeLoginAuditPayloadNative(1, '::1', 'curl/8', true));
            $this->assertNull(UserServiceProvider::normalizeObserverRegistrationPlanNative(['App\\A']));
            $this->assertSame(GdprPurgeExpiredExports::summarizePurgePlanPhp(30, 10, 4, 9), GdprPurgeExpiredExports::summarizePurgePlan(30, 10, 4, 9));
            $this->assertSame(User::summarizeApiQuotaDecisionPhp(3, 10, false, true), User::summarizeApiQuotaDecision(3, 10, false, true));
            $this->assertSame(UserAccessedApi::normalizeAccessAuditPayloadPhp(1, '::1', '/api?x'), UserAccessedApi::normalizeAccessAuditPayload(1, '::1', '/api?x'));
            $this->assertSame(UserLoggedIn::normalizeLoginAuditPayloadPhp(1, '::1', 'curl/8', true), UserLoggedIn::normalizeLoginAuditPayload(1, '::1', 'curl/8', true));
            $this->assertSame(UserServiceProvider::normalizeObserverRegistrationPlanPhp(['App\\A', 'app\\a']), UserServiceProvider::normalizeObserverRegistrationPlan(['App\\A', 'app\\a']));
        } finally {
            foreach ($saved as $class => $value) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $value);
            }
        }
    }

    public function test_frame_decoder_rejects_malformed_output(): void
    {
        $this->assertSame([5, 'ab', null], UserAccessedApi::unpackFrame(pack('q', 5).UserAccessedApi::packString('ab').UserAccessedApi::packString(null), 1, 2));
        $this->assertNull(UserAccessedApi::unpackFrame(pack('q', 5), 1, 1));
        $this->assertNull(UserAccessedApi::unpackFrame(pack('q2', 5, 3).'ab', 1, 1));
        $this->assertNull(UserAccessedApi::unpackFrame(pack('q2', 5, -2), 1, 1));
        $this->assertNull(UserAccessedApi::unpackFrame(pack('q2', 5, -1).'x', 1, 1));
        $this->assertNull(UserAccessedApi::unpackFrame(pack('q2', 5, PHP_INT_MAX), 1, 1));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including frame packing, the FFI call and decoding (release dylib, 5 warmups,
        // 301 samples x 40 iterations). Common = in-range counters, IPv4/short IPv6, short API
        // paths, browser/CLI agents and the three production observers; uncommon = extreme
        // counters, malformed/long IPs, padded/long endpoints and agents, and 40 mixed names.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.47x / p99 0.44x, uncommon mean 0.54x / p99 0.54x
        // - Round 2: common mean 0.46x / p99 0.58x, uncommon mean 0.53x / p99 0.57x
        // - Round 3: common mean 0.47x / p99 0.52x, uncommon mean 0.54x / p99 0.67x
        // - Round 4: common mean 0.47x / p99 0.48x, uncommon mean 0.53x / p99 0.57x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustUserGdprAuthEventsProviderParityTest.php --filter benchmark
        // The FFI round trip and frame packing cost more than these short PHP computations, so every
        // helper stays below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3611));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::TOGGLED as $class) {
                $class::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustUserGdprAuthEventsProvider speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::TOGGLED as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertPurgeParity(int $retention, int $candidates, int $deleted, int $failed): void
    {
        $expected = GdprPurgeExpiredExports::summarizePurgePlanPhp($retention, $candidates, $deleted, $failed);
        $context = json_encode([$retention, $candidates, $deleted, $failed]) ?: '';
        $this->assertSame($expected, GdprPurgeExpiredExports::summarizePurgePlanNative($retention, $candidates, $deleted, $failed), $context);
        foreach ([true, false] as $useRust) {
            GdprPurgeExpiredExports::setUseRustFfi($useRust);
            $this->assertSame($expected, GdprPurgeExpiredExports::summarizePurgePlan($retention, $candidates, $deleted, $failed), $context);
        }
        GdprPurgeExpiredExports::setUseRustFfi(null);
    }

    private function assertQuotaParity(int $current, int $max, bool $admin, bool $verified): void
    {
        $expected = User::summarizeApiQuotaDecisionPhp($current, $max, $admin, $verified);
        $context = json_encode([$current, $max, $admin, $verified]) ?: '';
        $this->assertSame($expected, User::summarizeApiQuotaDecisionNative($current, $max, $admin, $verified), $context);
        foreach ([true, false] as $useRust) {
            User::setUseRustFfi($useRust);
            $this->assertSame($expected, User::summarizeApiQuotaDecision($current, $max, $admin, $verified), $context);
        }
        User::setUseRustFfi(null);
    }

    private function assertAccessParity(int $userId, ?string $ip, ?string $endpoint): void
    {
        $expected = UserAccessedApi::normalizeAccessAuditPayloadPhp($userId, $ip, $endpoint);
        $context = bin2hex(($ip ?? '<null>').'|'.substr($endpoint ?? '<null>', 0, 64));
        $this->assertSame($expected, UserAccessedApi::normalizeAccessAuditPayloadNative($userId, $ip, $endpoint), $context);
        foreach ([true, false] as $useRust) {
            UserAccessedApi::setUseRustFfi($useRust);
            $this->assertSame($expected, UserAccessedApi::normalizeAccessAuditPayload($userId, $ip, $endpoint), $context);
        }
        UserAccessedApi::setUseRustFfi(null);
    }

    private function assertLoginParity(int $userId, ?string $ip, ?string $agent, bool $viaRemember): void
    {
        $expected = UserLoggedIn::normalizeLoginAuditPayloadPhp($userId, $ip, $agent, $viaRemember);
        $context = bin2hex(($ip ?? '<null>').'|'.substr($agent ?? '<null>', 0, 64));
        $this->assertSame($expected, UserLoggedIn::normalizeLoginAuditPayloadNative($userId, $ip, $agent, $viaRemember), $context);
        foreach ([true, false] as $useRust) {
            UserLoggedIn::setUseRustFfi($useRust);
            $this->assertSame($expected, UserLoggedIn::normalizeLoginAuditPayload($userId, $ip, $agent, $viaRemember), $context);
        }
        UserLoggedIn::setUseRustFfi(null);
    }

    /**
     * @param  array<mixed>  $names
     */
    private function assertPlanParity(array $names, bool $enabled): void
    {
        $expected = UserServiceProvider::normalizeObserverRegistrationPlanPhp($names, $enabled);
        $context = substr(var_export($names, true), 0, 256);
        $this->assertSame($expected, UserServiceProvider::normalizeObserverRegistrationPlanNative($names, $enabled), $context);
        foreach ([true, false] as $useRust) {
            UserServiceProvider::setUseRustFfi($useRust);
            $this->assertSame($expected, UserServiceProvider::normalizeObserverRegistrationPlan($names, $enabled), $context);
        }
        UserServiceProvider::setUseRustFfi(null);
    }

    /**
     * @return array{counts: list<array{int, int, int, int}>, ips: list<string|null>, endpoints: list<string|null>, agents: list<string|null>, names: list<string>}
     */
    private function commonWorkload(): array
    {
        return [
            'counts' => [[30, 12, 12, 0], [30, 0, 0, 0], [7, 5, 3, 2], [30, 100, 99, 1]],
            'ips' => ['192.168.1.20', '10.0.0.5', '2001:db8::1', '127.0.0.1'],
            'endpoints' => ['/api/v1/search', '/api/v1/details', '/rss?t=5000', '/api/v2/capabilities'],
            'agents' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15', 'curl/8.4.0', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0', 'python-requests/2.31'],
            'names' => [UserServiceObserver::class, UserActivityObserver::class, UserApiCacheObserver::class],
        ];
    }

    /**
     * @return array{counts: list<array{int, int, int, int}>, ips: list<string|null>, endpoints: list<string|null>, agents: list<string|null>, names: list<string>}
     */
    private function uncommonWorkload(): array
    {
        return [
            'counts' => [[PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX], [-1, -1, -1, -1], [PHP_INT_MAX, 4294967296, 4294967297, 1], [0, 1, 0, 0]],
            'ips' => [null, ' FE80:0000:0000:0000:0202:B3FF:FE1E:8329 ', '::ffff:256.1.1.1', '01.02.03.04'],
            'endpoints' => [null, str_repeat('/segment', 60).'?q='.str_repeat('x', 200), "  //api//v1/ \t#frag", 'ünïcode/päth/'],
            'agents' => [null, str_repeat("Mozilla/5.0 \t (compatible) ", 30), "\x00\x01Googlebot/2.1\x7F", 'Ünïcode Agent/1.0'],
            'names' => array_map(static fn (int $i): string => ($i % 3 === 0 ? ' \\' : '').'App\\Observers\\Observer'.($i % 25), range(1, 40)),
        ];
    }

    /**
     * @param  array{counts: list<array{int, int, int, int}>, ips: list<string|null>, endpoints: list<string|null>, agents: list<string|null>, names: list<string>}  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi($useRust);
        }
        $sink = 0;
        foreach ($workload['counts'] as $i => [$retention, $candidates, $deleted, $failed]) {
            $sink += GdprPurgeExpiredExports::summarizePurgePlan($retention, $candidates, $deleted, $failed)['skipped'] & 0xFF;
            $sink += (int) User::summarizeApiQuotaDecision($deleted, $candidates, $i === 3, $i !== 1)['allowed'];
            $sink += strlen(UserAccessedApi::normalizeAccessAuditPayload($i, $workload['ips'][$i], $workload['endpoints'][$i])['endpoint']);
            $sink += strlen(UserLoggedIn::normalizeLoginAuditPayload($i, $workload['ips'][$i], $workload['agents'][$i], $i % 2 === 0)['client_family']);
        }
        $sink += count(UserServiceProvider::normalizeObserverRegistrationPlan($workload['names'])['observers']);

        return $sink;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    /** Integer negation that maps PHP_INT_MIN to PHP_INT_MAX instead of promoting it to float. */
    private function negate(int $value): int
    {
        return $value === PHP_INT_MIN ? PHP_INT_MAX : -$value;
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w361_user_gdpr_auth(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', UserServiceProvider::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 36.1 user/GDPR/auth export is not available.');
        }

        return $ffi;
    }
}
