<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\SearchUnavailableException;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\VerifyFailedReleaseRequest;
use App\Observers\ReleaseObserver;
use App\Observers\UserApiCacheObserver;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 21 Slice 21.4:
 * SearchUnavailableException, ReleaseObserver, UserApiCacheObserver, VerifyFailedReleaseRequest,
 * and Google2FAMiddleware.
 *
 * Benchmarks run only with NNTMUX_SEARCH_UNAVAILABLE_OBSERVERS_2FA_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_SEARCH_UNAVAILABLE_OBSERVERS_2FA_BENCH=1 php artisan test --compact tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * | Method                                            | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |---------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | SearchUnavailableException::renderDriver          | 0.35x / 0.25x       | 0.37x / 0.37x         | No      | PHP (false) |
 * | SearchUnavailableException::retryAfterHeaderValue | 0.21x / 0.21x       | 0.25x / 0.26x         | No      | PHP (false) |
 * | ReleaseObserver::hasIndexedFieldChange            | 0.98x / 0.95x       | 0.27x / 0.22x         | No      | PHP (false) |
 * | UserApiCacheObserver::hasCacheRelevantChange      | 0.41x / 0.27x       | 0.34x / 0.34x         | No      | PHP (false) |
 * | UserApiCacheObserver::tokenCacheKeys              | 0.58x / 0.58x       | 0.86x / 0.85x         | No      | PHP (false) |
 * | VerifyFailedReleaseRequest::requestAction         | 0.32x / 0.32x       | 0.34x / 0.33x         | No      | PHP (false) |
 * | Google2FAMiddleware::intendedUrlAction            | 2.09x / 1.92x       | 1.28x / 1.25x         | No      | PHP (false) |
 */
class RustSearchUnavailableObserversFailedRelease2faParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        SearchUnavailableException::class,
        ReleaseObserver::class,
        UserApiCacheObserver::class,
        VerifyFailedReleaseRequest::class,
        Google2FAMiddleware::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('search', SearchUnavailableException::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    /**
     * @param  class-string  $class
     */
    private static function toggle(string $class, ?bool $use): void
    {
        match ($use) {
            true => $class::enableRustFfi(),
            false => $class::disableRustFfi(),
            null => $class::resetRustFfi(),
        };
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. SearchUnavailableException
    // ---------------------------------------------------------------------

    public function test_search_unavailable_render_driver_and_retry_after_parity(): void
    {
        $versions = ['v2', 'v1', 'V2', 'v2 ', ' v2', '', 'v3', 'v2.0', "v2\0", 'v', 'api', 'é'];
        foreach ($versions as $version) {
            $expected = SearchUnavailableException::renderDriverPhp($version);
            $this->assertSame($version === 'v2' ? 1 : 0, $expected);
            $this->assertSame($expected, SearchUnavailableException::renderDriverNative($version), var_export($version, true));
            foreach ([true, false, null] as $use) {
                self::toggle(SearchUnavailableException::class, $use);
                $this->assertSame($expected, SearchUnavailableException::renderDriver($version));
            }
        }

        foreach ([0, 1, 30, -1, -30, 86400, PHP_INT_MAX, PHP_INT_MIN] as $seconds) {
            $expected = SearchUnavailableException::retryAfterHeaderValuePhp($seconds);
            $this->assertSame((string) $seconds, $expected);
            $this->assertSame($expected, SearchUnavailableException::retryAfterHeaderValueNative($seconds));
            foreach ([true, false, null] as $use) {
                self::toggle(SearchUnavailableException::class, $use);
                $this->assertSame($expected, SearchUnavailableException::retryAfterHeaderValue($seconds));
            }
        }
    }

    public function test_search_unavailable_render_matches_across_toggles(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $responses = [];
            foreach ([false, true] as $use) {
                self::toggle(SearchUnavailableException::class, $use);
                $response = (new SearchUnavailableException($version))->render();
                $this->assertSame(503, $response->getStatusCode());
                $this->assertSame('30', $response->headers->get('Retry-After'));
                $responses[] = [$response->headers->get('Content-Type'), $response->getContent()];
            }
            $this->assertSame($responses[0], $responses[1]);
        }
    }

    // ---------------------------------------------------------------------
    // 2. ReleaseObserver / 3. UserApiCacheObserver dirty-attribute inspection
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<array-key, mixed>}>
     */
    public static function attributeSetProvider(): array
    {
        return [
            'empty' => [[]],
            'timestamps only' => [['updated_at' => '2026-10-24 12:00:00']],
            'guid change' => [['guid' => 'abc']],
            'grabs among unrelated' => [['updated_at' => 1, 'password' => 'x', 'grabs' => 5]],
            'adddate last indexed field' => [['adddate' => null]],
            'api token change' => [['api_token' => 'new']],
            'deleted_at null value still counts' => [['deleted_at' => null]],
            'roles and remember token' => [['remember_token' => 'x', 'roles_id' => 2]],
            'case and whitespace mismatches' => [['Name' => 1, 'guid ' => 1, ' api_token' => 1, 'API_TOKEN' => 1]],
            'nul suffixed keys' => [["name\0" => 1, "verified\0" => 1]],
            'integer keys' => [[0 => 'a', 1 => 'b', 42 => 'c']],
            'empty string key and indexed' => [['' => 1, 'searchname' => 'x']],
            'utf8 keys' => [['nämé' => 1, 'verifiéd' => 1]],
            'email verified at only' => [['email_verified_at' => '2026-01-01']],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    #[DataProvider('attributeSetProvider')]
    public function test_observer_dirty_attribute_parity(array $attributes): void
    {
        $expectedRelease = false;
        foreach (ReleaseObserver::INDEXED_FIELDS as $field) {
            $expectedRelease = $expectedRelease || array_key_exists($field, $attributes);
        }
        $this->assertSame($expectedRelease, ReleaseObserver::hasIndexedFieldChangePhp($attributes));
        $this->assertSame($expectedRelease, ReleaseObserver::hasIndexedFieldChangeNative($attributes));

        $expectedUser = false;
        foreach (UserApiCacheObserver::CACHE_RELEVANT_FIELDS as $field) {
            $expectedUser = $expectedUser || array_key_exists($field, $attributes);
        }
        $this->assertSame($expectedUser, UserApiCacheObserver::hasCacheRelevantChangePhp($attributes));
        $this->assertSame($expectedUser, UserApiCacheObserver::hasCacheRelevantChangeNative($attributes));

        foreach ([true, false, null] as $use) {
            self::toggle(ReleaseObserver::class, $use);
            self::toggle(UserApiCacheObserver::class, $use);
            $this->assertSame($expectedRelease, ReleaseObserver::hasIndexedFieldChange($attributes));
            $this->assertSame($expectedUser, UserApiCacheObserver::hasCacheRelevantChange($attributes));
        }
    }

    public function test_observer_dirty_attribute_parity_for_every_field(): void
    {
        foreach ([...ReleaseObserver::INDEXED_FIELDS, ...UserApiCacheObserver::CACHE_RELEVANT_FIELDS] as $field) {
            $attributes = ['updated_at' => 1, $field => 'x'];
            $this->assertSame(
                ReleaseObserver::hasIndexedFieldChangePhp($attributes),
                ReleaseObserver::hasIndexedFieldChangeNative($attributes),
                $field,
            );
            $this->assertSame(
                UserApiCacheObserver::hasCacheRelevantChangePhp($attributes),
                UserApiCacheObserver::hasCacheRelevantChangeNative($attributes),
                $field,
            );
        }
    }

    public function test_user_api_token_cache_keys_parity(): void
    {
        $tokens = [null, '', 0, 123, true, ['x'], 'abc', ' ', "\0", str_repeat('k', 64), 'tökén', str_repeat('long-token-', 500)];
        foreach ($tokens as $token) {
            foreach ([0, 1, 42, 987654321, -7, PHP_INT_MAX] as $userId) {
                $expected = UserApiCacheObserver::tokenCacheKeysPhp($token, $userId);
                if (\is_string($token) && $token !== '') {
                    $this->assertSame(
                        ['api_user:'.md5($token), 'api_user_stats:'.$userId, 'user_category_exclusions_'.$userId],
                        $expected,
                    );
                } else {
                    $this->assertSame([], $expected);
                }
                $this->assertSame($expected, UserApiCacheObserver::tokenCacheKeysNative($token, $userId));
                foreach ([true, false, null] as $use) {
                    self::toggle(UserApiCacheObserver::class, $use);
                    $this->assertSame($expected, UserApiCacheObserver::tokenCacheKeys($token, $userId));
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. VerifyFailedReleaseRequest / 5. Google2FAMiddleware
    // ---------------------------------------------------------------------

    public function test_failed_release_request_action_parity(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'post', 'POST ', '', "POST\0", 'QUERY'];
        foreach ([true, false] as $hasApiToken) {
            foreach ($methods as $method) {
                $expected = VerifyFailedReleaseRequest::requestActionPhp($hasApiToken, $method);
                $this->assertSame(
                    $hasApiToken ? 0 : ($method === 'POST' ? 1 : 2),
                    $expected,
                );
                $this->assertSame($expected, VerifyFailedReleaseRequest::requestActionNative($hasApiToken, $method));
                foreach ([true, false, null] as $use) {
                    self::toggle(VerifyFailedReleaseRequest::class, $use);
                    $this->assertSame($expected, VerifyFailedReleaseRequest::requestAction($hasApiToken, $method));
                }
            }
        }
    }

    public function test_google2fa_intended_url_action_parity(): void
    {
        $routeNames = [
            null,
            '',
            'home',
            'passkeys',
            'passkeys.',
            'passkeys.index',
            'passkeys.*',
            'passkeys.store.extra',
            "passkeys.\nline",
            "passkeys.\0",
            'passkeys.é',
            "passkeys.\xff",
            "passkeys.\xc3",
            'Passkeys.index',
            'xpasskeys.index',
            'password.confirm',
            'password.confirm ',
            "password.confirm\n",
            'password.confirmed',
            'password.Confirm',
            'profileedit',
            '2fa.verify',
        ];
        foreach ($routeNames as $routeName) {
            foreach (['GET', 'POST', 'HEAD', 'get', ''] as $method) {
                $expected = Google2FAMiddleware::intendedUrlActionPhp($routeName, $method);
                $isSecurity = $routeName !== null && (Str::is('passkeys.*', $routeName) || Str::is('password.confirm', $routeName));
                $this->assertSame($isSecurity ? 1 : ($method === 'GET' ? 2 : 0), $expected);
                $this->assertSame(
                    $expected,
                    Google2FAMiddleware::intendedUrlActionNative($routeName, $method),
                    var_export($routeName, true).' '.$method,
                );
                foreach ([true, false, null] as $use) {
                    self::toggle(Google2FAMiddleware::class, $use);
                    $this->assertSame($expected, Google2FAMiddleware::intendedUrlAction($routeName, $method));
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $exFfi = RustPipelineLibrary::resolveDomain('search', SearchUnavailableException::FFI_CDEF);
        $this->assertNotNull($exFfi);
        $small = $exFfi->new('char[1]');
        $small[0] = 'Z';
        $this->assertSame(-3, self::ffiCall($exFfi, 'nntmux_search_unavailable_retry_after', 30, $small, 1));
        $this->assertSame('Z', $small[0]);
        $this->assertSame(-1, self::ffiCall($exFfi, 'nntmux_search_unavailable_retry_after', 30, null, 8));
        $this->assertSame(-1, self::ffiCall($exFfi, 'nntmux_search_unavailable_render_driver', null, 2));

        $releaseFfi = RustPipelineLibrary::resolveDomain('search', ReleaseObserver::FFI_CDEF);
        $this->assertNotNull($releaseFfi);
        $raw = $releaseFfi->new('uint8_t[32]');
        $unalignedLens = $releaseFfi->cast('size_t*', FFI::addr($raw[1]));
        $lens = $releaseFfi->new('size_t[1]');
        $lens[0] = 3;
        $this->assertSame(-1, self::ffiCall($releaseFfi, 'nntmux_release_observer_has_indexed_change', 'name', 4, $unalignedLens, 1));
        $this->assertSame(-1, self::ffiCall($releaseFfi, 'nntmux_release_observer_has_indexed_change', 'name', 4, null, 1));
        $this->assertSame(-1, self::ffiCall($releaseFfi, 'nntmux_release_observer_has_indexed_change', 'name', 4, $lens, 1));
        $lens[0] = 4;
        $this->assertSame(1, self::ffiCall($releaseFfi, 'nntmux_release_observer_has_indexed_change', 'name', 4, $lens, 1));

        $userFfi = RustPipelineLibrary::resolveDomain('search', UserApiCacheObserver::FFI_CDEF);
        $this->assertNotNull($userFfi);
        $userLens = $userFfi->new('size_t[1]');
        $userLens[0] = 9;
        $this->assertSame(1, self::ffiCall($userFfi, 'nntmux_user_api_cache_relevant_change', 'api_token', 9, $userLens, 1));
        $this->assertSame(-1, self::ffiCall($userFfi, 'nntmux_user_api_cache_relevant_change', 'api_token', 9, $userLens, PHP_INT_MAX));
        $keyBuf = $userFfi->new('char[16]');
        $this->assertSame(-88, self::ffiCall($userFfi, 'nntmux_user_api_token_cache_keys', 'abc', 3, 42, $keyBuf, 16));
        $this->assertSame(0, self::ffiCall($userFfi, 'nntmux_user_api_token_cache_keys', '', 0, 42, $keyBuf, 16));
        $this->assertSame(-1, self::ffiCall($userFfi, 'nntmux_user_api_token_cache_keys', null, 3, 42, $keyBuf, 16));

        $failedFfi = RustPipelineLibrary::resolveDomain('search', VerifyFailedReleaseRequest::FFI_CDEF);
        $this->assertNotNull($failedFfi);
        $this->assertSame(-1, self::ffiCall($failedFfi, 'nntmux_failed_release_request_action', 2, 'POST', 4));
        $this->assertSame(-1, self::ffiCall($failedFfi, 'nntmux_failed_release_request_action', 0, null, 4));

        $twoFaFfi = RustPipelineLibrary::resolveDomain('search', Google2FAMiddleware::FFI_CDEF);
        $this->assertNotNull($twoFaFfi);
        $this->assertSame(-1, self::ffiCall($twoFaFfi, 'nntmux_google2fa_intended_action', 5, 'home', 4, 'GET', 3));
        $this->assertSame(-1, self::ffiCall($twoFaFfi, 'nntmux_google2fa_intended_action', 1, null, 4, 'GET', 3));
        $this->assertSame(-1, self::ffiCall($twoFaFfi, 'nntmux_google2fa_intended_action', 1, 'home', 4, null, 3));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame(SearchUnavailableException::RENDER_V2_JSON, SearchUnavailableException::renderDriverNative('v2'));
        $this->assertSame('30', SearchUnavailableException::retryAfterHeaderValueNative(30));
        $this->assertTrue(ReleaseObserver::hasIndexedFieldChangeNative(['grabs' => 1]));
        $this->assertFalse(UserApiCacheObserver::hasCacheRelevantChangeNative(['password' => 'x']));
        $this->assertSame(
            UserApiCacheObserver::tokenCacheKeysPhp('abc', 7),
            UserApiCacheObserver::tokenCacheKeysNative('abc', 7),
        );
        $this->assertSame(
            VerifyFailedReleaseRequest::ACTION_REJECT_METHOD,
            VerifyFailedReleaseRequest::requestActionNative(false, 'GET'),
        );
        $this->assertSame(
            Google2FAMiddleware::INTENDED_PROFILE_SECURITY,
            Google2FAMiddleware::intendedUrlActionNative('passkeys.index', 'GET'),
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SEARCH_UNAVAILABLE_OBSERVERS_2FA_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SEARCH_UNAVAILABLE_OBSERVERS_2FA_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $releaseDirtyCommon = ['nfostatus' => 1, 'updated_at' => '2026-10-24 12:00:00'];
        $releaseDirtyUncommon = [];
        foreach (range(1, 40) as $i) {
            $releaseDirtyUncommon["unindexed_column_{$i}"] = $i;
        }
        $userChangesCommon = ['updated_at' => '2026-10-24 12:00:00', 'lastlogin' => '2026-10-24 12:00:00'];
        $userChangesUncommon = ['remember_token' => 'x', 'updated_at' => 1, 'email_verified_at' => '2026-10-24 12:00:00'];
        $tokenCommon = str_repeat('a1b2c3d4', 5);
        $tokenUncommon = str_repeat('long-api-token-', 40);

        $workloads = [
            'SearchUnavailableException::renderDriver' => [
                SearchUnavailableException::class,
                static fn () => SearchUnavailableException::renderDriver('v2'),
                static fn () => SearchUnavailableException::renderDriver('legacy-api-version-string'),
            ],
            'SearchUnavailableException::retryAfterHeaderValue' => [
                SearchUnavailableException::class,
                static fn () => SearchUnavailableException::retryAfterHeaderValue(30),
                static fn () => SearchUnavailableException::retryAfterHeaderValue(PHP_INT_MIN),
            ],
            'ReleaseObserver::hasIndexedFieldChange' => [
                ReleaseObserver::class,
                static fn () => ReleaseObserver::hasIndexedFieldChange($releaseDirtyCommon),
                static fn () => ReleaseObserver::hasIndexedFieldChange($releaseDirtyUncommon),
            ],
            'UserApiCacheObserver::hasCacheRelevantChange' => [
                UserApiCacheObserver::class,
                static fn () => UserApiCacheObserver::hasCacheRelevantChange($userChangesCommon),
                static fn () => UserApiCacheObserver::hasCacheRelevantChange($userChangesUncommon),
            ],
            'UserApiCacheObserver::tokenCacheKeys' => [
                UserApiCacheObserver::class,
                static fn () => UserApiCacheObserver::tokenCacheKeys($tokenCommon, 42),
                static fn () => UserApiCacheObserver::tokenCacheKeys($tokenUncommon, PHP_INT_MAX),
            ],
            'VerifyFailedReleaseRequest::requestAction' => [
                VerifyFailedReleaseRequest::class,
                static fn () => VerifyFailedReleaseRequest::requestAction(true, 'GET'),
                static fn () => VerifyFailedReleaseRequest::requestAction(false, 'DELETE'),
            ],
            'Google2FAMiddleware::intendedUrlAction' => [
                Google2FAMiddleware::class,
                static fn () => Google2FAMiddleware::intendedUrlAction('browse.index', 'GET'),
                static fn () => Google2FAMiddleware::intendedUrlAction('passkeys.'.str_repeat('é', 40), 'POST'),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2104 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-49s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $use) {
            self::toggle($class, $use);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                self::toggle($class, $use);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $use][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
