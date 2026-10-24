<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\AllowRemoteVerificationPublication;
use App\Http\Middleware\DecorateHttpQueryResponses;
use App\Http\Middleware\DegradeWhenRedisUnreachable;
use App\Http\Middleware\EnforceSessionToken;
use App\Http\Middleware\ForceJsonOnAPI;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * Wave 20 parity and paired benchmark suite for `DegradeWhenRedisUnreachable`,
 * `DecorateHttpQueryResponses`, `EnforceSessionToken`, `ForceJsonOnAPI` and
 * `AllowRemoteVerificationPublication` (`rust/pipeline-ffi/src/search.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_REDIS_QUERY_SESSION_JSON_BENCH=1 php artisan test --compact tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon) through the public PHP adapters, so
 * FFI marshaling and allocation are included; nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x on all 4 metrics (common mean,
 * common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS, PHP 8.5.12 CLI (FFI), rustc 1.99.1 release (lto, cgu=1),
 * base commit b007d8199. Paired speedup (`PHP latency / Rust FFI latency`, mean/p99, 3 reps):
 * - `failoverChain`: common 0.19x/0.18x, 0.18x/0.17x, 0.18x/0.18x | uncommon 0.26x/0.27x, 0.27x/0.31x, 0.27x/0.33x
 * - `shouldProbe`:   common 0.25x/0.25x, 0.26x/0.25x, 0.26x/0.25x | uncommon 0.28x/0.40x, 0.28x/0.40x, 0.28x/0.25x
 * - `streamRemote`:  common 0.32x/0.33x, 0.32x/0.38x, 0.32x/0.38x | uncommon 0.40x/0.38x, 0.41x/0.38x, 0.42x/0.50x
 * - `tcpTarget`:     common 0.44x/0.45x, 0.44x/0.42x, 0.43x/0.46x | uncommon 0.52x/0.57x, 0.53x/0.57x, 0.53x/0.54x
 * - `queryFlags`:    common 0.17x/0.20x, 0.17x/0.25x, 0.17x/0.20x | uncommon 0.59x/0.55x, 0.59x/0.64x, 0.59x/0.67x
 * - `sessionToken`:  common 0.55x/0.50x, 0.55x/0.67x, 0.57x/0.50x | uncommon 0.56x/0.50x, 0.56x/0.67x, 0.57x/0.50x
 * - `forceJson`:     common 0.92x/0.86x, 0.95x/1.00x, 0.97x/1.00x | uncommon 0.85x/0.89x, 0.85x/0.90x, 0.84x/0.89x
 * - `networks`:      common 0.51x/0.50x, 0.49x/0.50x, 0.52x/0.50x | uncommon 0.85x/0.85x, 0.89x/0.92x, 0.89x/0.92x
 *
 * No method qualified. Each helper is a few string comparisons, a short concatenation
 * or `explode()`/`trim()`/`hash_equals()` (already C in PHP), so the FFI call, packed
 * `size_t[]` allocation and output copy cost more than the PHP work. `$useRustFfi =
 * false` stays the default in all five classes; both `*Native` and `*Php` paths remain
 * covered by the parity tests below.
 */
final class RustRedisDegradeQuerySessionJsonMiddlewareParityTest extends TestCase
{
    private const string FULL_CDEF = DegradeWhenRedisUnreachable::FFI_CDEF."\n"
        .DecorateHttpQueryResponses::FFI_CDEF."\n"
        .EnforceSessionToken::FFI_CDEF."\n"
        .ForceJsonOnAPI::FFI_CDEF."\n"
        .AllowRemoteVerificationPublication::FFI_CDEF;

    /** @var list<class-string> */
    private const array CLASSES = [
        DegradeWhenRedisUnreachable::class,
        DecorateHttpQueryResponses::class,
        EnforceSessionToken::class,
        ForceJsonOnAPI::class,
        AllowRemoteVerificationPublication::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Build rust/pipeline-ffi in release mode to load the search domain.');

        return $ffi;
    }

    /**
     * @return array{0: class-string<Throwable>|null, 1: mixed}
     */
    private static function outcome(callable $call): array
    {
        try {
            return [null, $call()];
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }
    }

    /**
     * Runs `$call` once per adapter mode and asserts identical outcomes.
     */
    private static function assertModesMatch(string $class, callable $call, string $label): void
    {
        $class::setUseRustFfi(false);
        $php = self::outcome($call);
        $class::setUseRustFfi(true);
        $native = self::outcome($call);
        $class::setUseRustFfi(false);
        self::assertSame($php, $native, $label);
    }

    public function test_native_paths_are_disabled_by_default(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::$useRustFfi, $class);
        }
    }

    public function test_failover_chain_matches_php(): void
    {
        self::ffi();
        $stores = [
            null,
            'redis',
            [],
            ['driver' => 'redis'],
            ['driver' => 'failover'],
            ['driver' => 'failover', 'stores' => null],
            ['driver' => 'failover', 'stores' => []],
            ['driver' => 'failover', 'stores' => ['redis', 'file']],
            ['driver' => 'failover', 'stores' => ['database', 'ReDiS']],
            ['driver' => 'failover', 'stores' => ['file', ' redis', 'redis ']],
            ['driver' => 'failover', 'stores' => [1, 2.5, true, false, null, 'REDIS']],
            ['driver' => 'failover', 'stores' => ['x' => 'file', 'y' => 'redis']],
            ['driver' => 'failover', 'stores' => [['redis'], 'redis']],
            ['driver' => 'Failover', 'stores' => ['redis']],
            ['driver' => 'failover ', 'stores' => ['redis']],
            ['driver' => null, 'stores' => ['redis']],
            ['driver' => 1, 'stores' => ['redis']],
            ['stores' => ['redis']],
            ['driver' => 'failover', 'stores' => "re\0dis"],
            ['driver' => 'failover', 'stores' => ["re\0dis", 'rédis', 'redis']],
        ];
        foreach ($stores as $i => $store) {
            self::assertSame(
                self::outcome(static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForPhp($store)),
                self::outcome(static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForNative($store)),
                "store #{$i}",
            );
        }
    }

    public function test_should_probe_matches_php(): void
    {
        self::ffi();
        $defaults = ['', 'redis', 'failover_redis_file', 'file', 'REDIS', 'redis ', 'failover', 'array'];
        $sessions = [null, 'redis', 'file', 'Redis', '', 0, true, ['redis']];
        foreach ($defaults as $default) {
            foreach ([false, true] as $chain) {
                foreach ($sessions as $session) {
                    self::assertSame(
                        DegradeWhenRedisUnreachable::shouldProbeRedisForPhp($default, $chain, $session),
                        DegradeWhenRedisUnreachable::shouldProbeRedisForNative($default, $chain, $session),
                        json_encode([$default, $chain, $session]) ?: '',
                    );
                }
            }
        }
    }

    public function test_stream_remote_matches_php(): void
    {
        self::ffi();
        $hosts = ['redis', '127.0.0.1', '::1', '[::1]', 'fe80::1%en0', '', 'a:b', '[x', str_repeat('h', 300), "nul\0host", 'hôst'];
        foreach (['tcp', 'tls', 'ssl', 'rediss', 'redis', 'TLS', '', 'unix'] as $scheme) {
            foreach ($hosts as $host) {
                foreach ([6379, 0, -1, 65536, PHP_INT_MAX, PHP_INT_MIN] as $port) {
                    self::assertSame(
                        DegradeWhenRedisUnreachable::streamRemotePhp($scheme, $host, $port),
                        DegradeWhenRedisUnreachable::streamRemoteNative($scheme, $host, $port),
                        "{$scheme}|{$host}|{$port}",
                    );
                }
            }
        }
    }

    public function test_redis_tcp_target_matches_php(): void
    {
        self::ffi();
        $configs = [
            null,
            'redis://localhost',
            [],
            ['host' => 'redis', 'port' => 6379],
            ['host' => 'redis', 'port' => '6380'],
            ['host' => 'redis', 'port' => '6380abc'],
            ['host' => 'redis', 'port' => 'abc'],
            ['host' => 'redis', 'port' => 6379.9],
            ['host' => 'redis', 'port' => null],
            ['host' => 'redis', 'port' => false],
            ['host' => null, 'port' => 7000],
            ['host' => ''],
            ['host' => false],
            ['host' => 0],
            ['host' => '/var/run/redis.sock'],
            ['host' => 'fe80::1', 'port' => 6379],
            ['host' => '[::1]'],
            ['host' => ['redis']],
            ['host' => 'redis', 'port' => ['6379']],
            ['url' => ''],
            ['url' => '0', 'host' => 'fallback'],
            ['url' => null, 'host' => 'fallback'],
            ['url' => 'redis://cache:6380'],
            ['url' => 'REDISS://user:secret@cache.example.com:6390/2'],
            ['url' => 'tls://cache'],
            ['url' => 'Ssl://cache:1'],
            ['url' => 'tcp://[::1]:6379'],
            ['url' => '//cache:6379'],
            ['url' => 'cache:6379'],
            ['url' => 'redis:///tmp/redis.sock'],
            ['url' => 'unix:///tmp/redis.sock'],
            ['url' => 'http://:80'],
            ['url' => 'redis://host:99999'],
            ['url' => true],
            ['url' => 1, 'host' => 'fallback'],
            ['url' => ['redis://x']],
        ];
        foreach ($configs as $i => $cfg) {
            self::assertSame(
                self::outcome(static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForPhp($cfg)),
                self::outcome(static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForNative($cfg)),
                "config #{$i}",
            );
        }
    }

    public function test_degrade_middleware_instance_methods_match_php(): void
    {
        self::ffi();
        $middleware = new DegradeWhenRedisUnreachable;
        $probe = new ReflectionMethod($middleware, 'shouldProbeRedis');
        $target = new ReflectionMethod($middleware, 'redisTcpTarget');
        $scenarios = [
            ['redis', 'file', ['host' => 'redis', 'port' => 6379]],
            ['failover_redis_file', 'file', ['url' => 'rediss://cache:6380']],
            ['custom_failover', 'file', ['host' => '/tmp/redis.sock']],
            ['file', 'redis', ['host' => 'fe80::1']],
            ['file', 'file', null],
        ];
        foreach ($scenarios as $i => [$cache, $session, $redis]) {
            config([
                'cache.default' => $cache,
                'cache.stores.custom_failover' => ['driver' => 'failover', 'stores' => ['database', 'redis']],
                'session.driver' => $session,
                'database.redis.default' => $redis,
            ]);
            self::assertModesMatch(DegradeWhenRedisUnreachable::class, static fn () => [$probe->invoke($middleware), $target->invoke($middleware)], "scenario #{$i}");
        }
    }

    public function test_query_header_flags_match_php(): void
    {
        self::ffi();
        $methods = ['QUERY', 'query', 'Query', 'GET', 'POST', '', 'QUERY '];
        $paths = ['api/v2/search', 'api/', 'api', 'API/x', '/', 'browse', 'api/*', "api/\n", "api/\xff", 'xapi/v1', 'api/v1/'.str_repeat('a', 400)];
        $routes = [null, [], ['GET', 'HEAD'], ['GET', 'HEAD', 'QUERY'], ['query'], ['QUERY '], [1, 'QUERY'], ['x' => 'QUERY']];
        foreach ($methods as $method) {
            foreach ($paths as $path) {
                foreach ($routes as $route) {
                    self::assertSame(
                        DecorateHttpQueryResponses::headerFlagsPhp($method, $path, $route),
                        DecorateHttpQueryResponses::headerFlagsNative($method, $path, $route),
                        json_encode([$method, bin2hex($path), $route]) ?: '',
                    );
                }
            }
        }
    }

    public function test_query_middleware_headers_match_php(): void
    {
        self::ffi();
        $cases = [
            ['QUERY', '/api/v2/search', ['GET', 'HEAD', 'QUERY']],
            ['QUERY', '/api/v2/search', null],
            ['GET', '/api/v2/search', ['GET', 'HEAD', 'QUERY']],
            ['GET', '/browse', ['GET', 'HEAD']],
            ['QUERY', '/api%2Fv2', null],
            ['QUERY', '/browse', ['QUERY']],
        ];
        foreach ($cases as $i => [$method, $uri, $routeMethods]) {
            self::assertModesMatch(DecorateHttpQueryResponses::class, static function () use ($method, $uri, $routeMethods): array {
                $request = Request::create($uri, $method);
                if ($routeMethods !== null) {
                    $route = new Route($routeMethods, ltrim($uri, '/'), static fn () => null);
                    $request->setRouteResolver(static fn () => $route);
                }
                $response = (new DecorateHttpQueryResponses)->handle($request, static fn () => new Response('ok'));

                return [$response->headers->get('Cache-Control'), $response->headers->get('Accept-Query')];
            }, "case #{$i}");
        }
    }

    public function test_session_token_action_matches_php(): void
    {
        self::ffi();
        $tokens = ['', '0', 'abc', 'abd', 'abcd', str_repeat('f', 64), str_repeat('f', 63).'e', "nul\0", "nul\0x", 'tökén'];
        foreach ($tokens as $user) {
            foreach ($tokens as $session) {
                self::assertSame(
                    EnforceSessionToken::sessionTokenActionPhp($user, $session),
                    EnforceSessionToken::sessionTokenActionNative($user, $session),
                    bin2hex($user).'|'.bin2hex($session),
                );
            }
        }
        self::assertSame(EnforceSessionToken::ACTION_ALLOW, EnforceSessionToken::sessionTokenActionPhp('', 'x'));
        self::assertSame(EnforceSessionToken::ACTION_SEED, EnforceSessionToken::sessionTokenActionPhp('x', ''));
        self::assertSame(EnforceSessionToken::ACTION_REJECT, EnforceSessionToken::sessionTokenActionPhp('x', 'y'));
    }

    public function test_session_token_middleware_matches_php(): void
    {
        self::ffi();
        $cases = [['', 'old'], ['tok', ''], ['tok', 'tok'], ['tok', 'other']];
        foreach ($cases as $i => [$userToken, $sessionToken]) {
            self::assertModesMatch(EnforceSessionToken::class, static function () use ($userToken, $sessionToken): array {
                $session = new Store('test', new ArraySessionHandler(10));
                $session->start();
                if ($sessionToken !== '') {
                    $session->put('session_token_web', $sessionToken);
                }
                $request = Request::create('/api/v1/api', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
                $request->setLaravelSession($session);
                $request->setUserResolver(static fn () => (object) ['session_token' => $userToken]);
                try {
                    $status = (new EnforceSessionToken)->handle($request, static fn () => new Response('ok'))->getStatusCode();
                } catch (HttpException $e) {
                    $status = $e->getStatusCode();
                }

                return [$status, $session->get('session_token_web')];
            }, "case #{$i}");
        }
    }

    public function test_force_json_accept_matches_php(): void
    {
        self::ffi();
        $paths = ['api/v1/api', 'api/v2/search', 'api', 'api/', 'API/x', 'browse', "api/\xc3", 'api/*'];
        $accepts = [
            null,
            '',
            '*/*',
            'text/html',
            'application/json',
            'application/vnd.api+json',
            'application/JSON',
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'json',
            '/jso',
            "x\0/json",
            str_repeat('text/plain,', 100),
        ];
        foreach ($paths as $path) {
            foreach ($accepts as $accept) {
                self::assertSame(
                    ForceJsonOnAPI::forcedAcceptHeaderPhp($path, $accept),
                    ForceJsonOnAPI::forcedAcceptHeaderNative($path, $accept),
                    bin2hex($path).'|'.var_export($accept, true),
                );
            }
        }
    }

    public function test_force_json_middleware_matches_php(): void
    {
        self::ffi();
        $cases = [['/api/v1/api', 'text/html'], ['/api/v1/api', null], ['/api/v2/x', 'application/json'], ['/browse', 'text/html'], ['/api%2Fv1', '*/*']];
        foreach ($cases as $i => [$uri, $accept]) {
            self::assertModesMatch(ForceJsonOnAPI::class, static function () use ($uri, $accept): array {
                $request = Request::create($uri, 'GET');
                $request->headers->remove('accept');
                if ($accept !== null) {
                    $request->headers->set('accept', $accept);
                }
                (new ForceJsonOnAPI)->handle($request, static fn () => new Response('ok'));

                return [$request->header('accept'), $request->expectsJson()];
            }, "case #{$i}");
        }
    }

    public function test_allowed_networks_match_php(): void
    {
        self::ffi();
        $inputs = [
            '',
            ',',
            ' , ,0, ',
            '10.0.0.0/8',
            '10.0.0.0/8, 192.168.0.0/16 ,\t::1'."\n",
            "\x0b172.16.0.0/12\r\n,fd00::/8",
            '00,0.0,0',
            "a\0b,c",
            " \0 ,x",
            str_repeat('10.0.0.1, ', 200),
            'ünï, côdé',
        ];
        foreach ($inputs as $input) {
            self::assertSame(
                AllowRemoteVerificationPublication::allowedNetworksPhp($input),
                AllowRemoteVerificationPublication::allowedNetworksNative($input),
                bin2hex($input),
            );
        }
    }

    public function test_publication_middleware_matches_php(): void
    {
        self::ffi();
        $cases = [
            [true, '10.0.0.0/8', '10.1.2.3'],
            [true, '10.0.0.0/8', '8.8.8.8'],
            [true, ' , ', '10.1.2.3'],
            [true, '192.168.0.0/16, 10.0.0.0/8', '10.9.9.9'],
            [false, '10.0.0.0/8', '10.1.2.3'],
        ];
        foreach ($cases as $i => [$enabled, $networks, $ip]) {
            config([
                'release_verification.publish_endpoint' => $enabled,
                'release_verification.publish_allowed_networks' => $networks,
            ]);
            self::assertModesMatch(AllowRemoteVerificationPublication::class, static function () use ($ip): int {
                $request = Request::create('/verification/publish', 'POST', server: ['REMOTE_ADDR' => $ip]);
                try {
                    return (new AllowRemoteVerificationPublication)->handle($request, static fn () => new Response('ok'))->getStatusCode();
                } catch (HttpException $e) {
                    return $e->getStatusCode();
                }
            }, "case #{$i}");
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(20261024));
        $alphabet = "aApPiI/:,[]0. \t\nqQuUeErRyYjJsSoOnN+\xff";
        $draw = static function (int $max) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);

            return $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
        };
        for ($i = 0; $i < 2_000; $i++) {
            $a = $draw(24);
            $b = $draw(24);
            self::assertSame(DegradeWhenRedisUnreachable::streamRemotePhp($a, $b, $i - 1000), DegradeWhenRedisUnreachable::streamRemoteNative($a, $b, $i - 1000));
            self::assertSame(DegradeWhenRedisUnreachable::redisTcpTargetForPhp(['host' => $a, 'port' => $b]), DegradeWhenRedisUnreachable::redisTcpTargetForNative(['host' => $a, 'port' => $b]));
            self::assertSame(DecorateHttpQueryResponses::headerFlagsPhp($a, 'api/'.$b, [$a, $b]), DecorateHttpQueryResponses::headerFlagsNative($a, 'api/'.$b, [$a, $b]));
            self::assertSame(EnforceSessionToken::sessionTokenActionPhp($a, $b), EnforceSessionToken::sessionTokenActionNative($a, $b));
            self::assertSame(ForceJsonOnAPI::forcedAcceptHeaderPhp('api/'.$a, $b), ForceJsonOnAPI::forcedAcceptHeaderNative('api/'.$a, $b));
            self::assertSame(AllowRemoteVerificationPublication::allowedNetworksPhp($a.$b), AllowRemoteVerificationPublication::allowedNetworksNative($a.$b));
        }
    }

    /**
     * NNTMUX_REDIS_QUERY_SESSION_JSON_BENCH=1 php artisan test --compact tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_REDIS_QUERY_SESSION_JSON_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_REDIS_QUERY_SESSION_JSON_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $chainCommon = ['driver' => 'failover', 'stores' => ['redis', 'file']];
        $chainUncommon = ['driver' => 'failover', 'stores' => ['database', 'array', 'file', 'memcached', 'REDIS']];
        $cfgCommon = ['url' => null, 'host' => 'redis', 'port' => '6379'];
        $cfgUncommon = ['url' => 'rediss://user:secret@cache.example.com:6380/0'];
        $routeCommon = ['GET', 'HEAD'];
        $routeUncommon = ['GET', 'HEAD', 'POST', 'QUERY'];
        $token = str_repeat('0123456789abcdef', 4);
        $otherToken = str_repeat('0123456789abcdef', 3).'fedcba9876543210';
        $acceptUncommon = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
        $networksCommon = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
        $networksUncommon = ' 10.0.0.0/8 , , 172.16.0.0/12,0,192.168.0.0/16 , fd00::/8 ,fe80::/10, 127.0.0.1 , ';

        $workloads = [
            'failoverChain/common' => [
                static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForPhp($chainCommon),
                static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForNative($chainCommon),
            ],
            'failoverChain/uncommon' => [
                static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForPhp($chainUncommon),
                static fn () => DegradeWhenRedisUnreachable::failoverChainContainsRedisForNative($chainUncommon),
            ],
            'shouldProbe/common' => [
                static fn () => DegradeWhenRedisUnreachable::shouldProbeRedisForPhp('failover_redis_file', true, 'redis'),
                static fn () => DegradeWhenRedisUnreachable::shouldProbeRedisForNative('failover_redis_file', true, 'redis'),
            ],
            'shouldProbe/uncommon' => [
                static fn () => DegradeWhenRedisUnreachable::shouldProbeRedisForPhp('file', false, 'database'),
                static fn () => DegradeWhenRedisUnreachable::shouldProbeRedisForNative('file', false, 'database'),
            ],
            'streamRemote/common' => [
                static fn () => DegradeWhenRedisUnreachable::streamRemotePhp('tcp', 'redis', 6379),
                static fn () => DegradeWhenRedisUnreachable::streamRemoteNative('tcp', 'redis', 6379),
            ],
            'streamRemote/uncommon' => [
                static fn () => DegradeWhenRedisUnreachable::streamRemotePhp('tcp', 'fe80::1:2:3:4', 6380),
                static fn () => DegradeWhenRedisUnreachable::streamRemoteNative('tcp', 'fe80::1:2:3:4', 6380),
            ],
            'tcpTarget/common' => [
                static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForPhp($cfgCommon),
                static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForNative($cfgCommon),
            ],
            'tcpTarget/uncommon' => [
                static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForPhp($cfgUncommon),
                static fn () => DegradeWhenRedisUnreachable::redisTcpTargetForNative($cfgUncommon),
            ],
            'queryFlags/common' => [
                static fn () => DecorateHttpQueryResponses::headerFlagsPhp('GET', 'browse/all', $routeCommon),
                static fn () => DecorateHttpQueryResponses::headerFlagsNative('GET', 'browse/all', $routeCommon),
            ],
            'queryFlags/uncommon' => [
                static fn () => DecorateHttpQueryResponses::headerFlagsPhp('QUERY', 'api/v2/search', $routeUncommon),
                static fn () => DecorateHttpQueryResponses::headerFlagsNative('QUERY', 'api/v2/search', $routeUncommon),
            ],
            'sessionToken/common' => [
                static fn () => EnforceSessionToken::sessionTokenActionPhp($token, $token),
                static fn () => EnforceSessionToken::sessionTokenActionNative($token, $token),
            ],
            'sessionToken/uncommon' => [
                static fn () => EnforceSessionToken::sessionTokenActionPhp($token, $otherToken),
                static fn () => EnforceSessionToken::sessionTokenActionNative($token, $otherToken),
            ],
            'forceJson/common' => [
                static fn () => ForceJsonOnAPI::forcedAcceptHeaderPhp('api/v1/api', 'application/json'),
                static fn () => ForceJsonOnAPI::forcedAcceptHeaderNative('api/v1/api', 'application/json'),
            ],
            'forceJson/uncommon' => [
                static fn () => ForceJsonOnAPI::forcedAcceptHeaderPhp('api/v1/api', $acceptUncommon),
                static fn () => ForceJsonOnAPI::forcedAcceptHeaderNative('api/v1/api', $acceptUncommon),
            ],
            'networks/common' => [
                static fn () => AllowRemoteVerificationPublication::allowedNetworksPhp($networksCommon),
                static fn () => AllowRemoteVerificationPublication::allowedNetworksNative($networksCommon),
            ],
            'networks/uncommon' => [
                static fn () => AllowRemoteVerificationPublication::allowedNetworksPhp($networksUncommon),
                static fn () => AllowRemoteVerificationPublication::allowedNetworksNative($networksUncommon),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $percentile99($samples[0]) / $percentile99($samples[1]));
            }
            fwrite(STDOUT, sprintf("%-28s %s\n", $name, implode('  ', $reps)));
        }
    }
}
