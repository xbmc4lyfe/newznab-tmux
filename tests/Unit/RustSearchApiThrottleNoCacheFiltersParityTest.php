<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\NoCacheForAuthenticatedUsers;
use App\Http\Middleware\ThrottleApiRequestsByToken;
use App\Services\Api\ApiUserResolver;
use App\Services\Search\Support\SearchFailureTracker;
use App\Support\DateRangeFilter;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

/**
 * Wave 19 parity and paired benchmark suite for `SearchFailureTracker`,
 * `ApiUserResolver`, `DateRangeFilter`, `ThrottleApiRequestsByToken` and
 * `NoCacheForAuthenticatedUsers` (`rust/pipeline-ffi/src/search.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_SEARCH_API_THROTTLE_NOCACHE_BENCH=1 php artisan test --compact tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon) through the public PHP adapters, so
 * FFI marshaling and allocation are included; nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x on all 4 metrics (common mean,
 * common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS, PHP 8.5.12 CLI (FFI), rustc 1.99.1 release (lto, cgu=1),
 * base commit db5ebbf22. Paired speedup (`PHP latency / Rust FFI latency`, mean/p99, 3 reps):
 * - `failureRecord`:       common 0.18x/0.29x, 0.18x/0.29x, 0.18x/0.29x | uncommon 0.42x/0.50x, 0.42x/0.45x, 0.41x/0.40x
 * - `failureSince`:        common 0.36x/0.40x, 0.35x/0.44x, 0.40x/0.33x | uncommon 0.32x/0.29x, 0.30x/0.25x, 0.31x/0.40x
 * - `failureThreshold`:    common 0.34x/0.40x, 0.35x/0.40x, 0.32x/0.40x | uncommon 0.29x/0.50x, 0.27x/0.29x, 0.28x/0.34x
 * - `apiUserCacheKey`:     common 0.46x/0.50x, 0.45x/0.44x, 0.43x/0.44x | uncommon 0.76x/0.75x, 0.75x/0.75x, 0.73x/0.59x
 * - `apiCredentials`:      common 0.21x/0.25x, 0.22x/0.22x, 0.21x/0.25x | uncommon 0.22x/0.37x, 0.22x/0.30x, 0.23x/0.30x
 * - `datePlan`:            common 0.15x/0.22x, 0.15x/0.25x, 0.15x/0.20x | uncommon 0.21x/0.29x, 0.21x/0.25x, 0.20x/0.29x
 * - `dateParse`:           common 0.35x/0.36x, 0.35x/0.38x, 0.35x/0.18x | uncommon 0.37x/0.31x, 0.37x/0.39x, 0.37x/0.40x
 * - `throttleKey`:         common 0.19x/0.28x, 0.19x/0.29x, 0.19x/0.29x | uncommon 0.24x/0.29x, 0.25x/0.29x, 0.26x/0.25x
 * - `throttleMax`:         common 0.32x/0.25x, 0.29x/0.34x, 0.30x/0.25x | uncommon 0.30x/0.50x, 0.30x/0.25x, 0.29x/0.25x
 * - `throttleRemaining`:   common 0.31x/0.40x, 0.32x/0.50x, 0.30x/0.40x | uncommon 0.32x/0.25x, 0.30x/0.25x, 0.32x/0.25x
 * - `throttleRetryAfter`:  common 0.31x/0.34x, 0.30x/0.50x, 0.32x/0.25x | uncommon 0.32x/0.25x, 0.31x/0.34x, 0.33x/0.34x
 * - `throttleErrorHeader`: common 0.25x/0.25x, 0.24x/0.25x, 0.24x/0.25x | uncommon 0.32x/0.27x, 0.29x/0.27x, 0.30x/0.30x
 * - `noCachePrevent`:      common 0.39x/0.50x, 0.39x/0.40x, 0.40x/0.50x | uncommon 0.39x/0.34x, 0.40x/0.40x, 0.40x/0.40x
 * - `noCacheVary`:         common 0.43x/0.44x, 0.41x/0.50x, 0.41x/0.50x | uncommon 0.93x/1.00x, 0.97x/1.00x, 0.94x/1.00x
 *
 * No method qualified. Each helper is an integer comparison, a short concatenation,
 * `md5()`/`trim()`/`explode()` (already C in PHP) or a strict `match`, so the FFI call
 * and marshaling cost more than the PHP work; Carbon object construction dominates
 * `parseDate()` on both paths. `$useRustFfi = false` stays the default in all five
 * classes; both `*Native` and `*Php` paths remain covered by the parity tests below.
 */
final class RustSearchApiThrottleNoCacheFiltersParityTest extends TestCase
{
    private const string FULL_CDEF = SearchFailureTracker::FFI_CDEF."\n"
        .ApiUserResolver::FFI_CDEF."\n"
        .DateRangeFilter::FFI_CDEF."\n"
        .ThrottleApiRequestsByToken::FFI_CDEF."\n"
        .NoCacheForAuthenticatedUsers::FFI_CDEF;

    /** @var list<class-string> */
    private const array CLASSES = [
        SearchFailureTracker::class,
        ApiUserResolver::class,
        DateRangeFilter::class,
        ThrottleApiRequestsByToken::class,
        NoCacheForAuthenticatedUsers::class,
    ];

    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        date_default_timezone_set($this->timezone);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Build rust/pipeline-ffi in release mode to load the search domain.');

        return $ffi;
    }

    private static function tracker(int $failures): SearchFailureTracker
    {
        $tracker = new SearchFailureTracker;
        (new ReflectionProperty(SearchFailureTracker::class, 'failures'))->setValue($tracker, $failures);

        return $tracker;
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

    public function test_search_failure_tracker_matches_php(): void
    {
        self::ffi();
        foreach ([0, 1, 2, 41, PHP_INT_MAX - 1] as $failures) {
            $php = self::tracker($failures);
            $native = self::tracker($failures);
            $php->recordPhp();
            $native->recordNative();
            self::assertSame($php->count(), $native->count(), (string) $failures);

            foreach ([0, 1, 2, $failures, $failures + 1, -1, 3, PHP_INT_MIN, PHP_INT_MAX] as $probe) {
                self::assertSame($php->failedSincePhp($probe), $native->failedSinceNative($probe), "since {$failures}/{$probe}");
                self::assertSame($php->reachedThresholdPhp($probe), $native->reachedThresholdNative($probe), "threshold {$failures}/{$probe}");
            }
        }

        // An increment past PHP_INT_MAX fails the same way on both paths.
        $php = self::outcome(static fn () => self::tracker(PHP_INT_MAX)->recordPhp());
        $native = self::outcome(static fn () => self::tracker(PHP_INT_MAX)->recordNative());
        self::assertNotNull($php[0]);
        self::assertSame($php, $native);

        // Window semantics as ApiReleaseRowCache uses them.
        SearchFailureTracker::setUseRustFfi(true);
        $tracker = new SearchFailureTracker;
        $before = $tracker->count();
        self::assertFalse($tracker->failedSince($before));
        $tracker->record();
        $tracker->record();
        self::assertSame(2, $tracker->count());
        self::assertTrue($tracker->failedSince($before));
        self::assertTrue($tracker->reachedThreshold(2));
        self::assertFalse($tracker->reachedThreshold(3));
        self::assertFalse($tracker->reachedThreshold(0));
    }

    public function test_api_user_cache_key_and_credentials_match_php(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(20261024));
        $tokens = ['', 'abc', '0', " \t", "\0bin\xff", str_repeat('k', 4096)];
        for ($i = 0; $i < 200; $i++) {
            $tokens[] = $random->getBytes($random->getInt(0, 96));
        }
        foreach ($tokens as $token) {
            self::assertSame(ApiUserResolver::cacheKeyPhp($token), ApiUserResolver::cacheKeyNative($token));
        }

        $values = [null, '', ' ', " \t\n\r\0\x0B", ' tok ', 'tok', '0', "\x0Bx", 0, 1, 1.5, false, true, [], ['x']];
        foreach ($values as $apiToken) {
            foreach ($values as $apikey) {
                $label = var_export([$apiToken, $apikey], true);
                self::assertSame(
                    ApiUserResolver::resolveCredentialsPhp($apiToken, $apikey),
                    ApiUserResolver::resolveCredentialsNative($apiToken, $apikey),
                    $label,
                );

                // The real caller's original inline logic on a JSON request body.
                $request = Request::create('/api/v1/api', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(array_filter(
                    ['api_token' => $apiToken, 'apikey' => $apikey],
                    static fn (mixed $v): bool => $v !== null,
                ), JSON_THROW_ON_ERROR));
                $token = $request->input('api_token') ?? $request->input('apikey');
                $expected = ! \is_string($token) || $token === ''
                    ? null
                    : ['token' => $token, 'version' => $request->filled('api_token') ? ApiUserResolver::VERSION_2 : ApiUserResolver::VERSION_1];
                self::assertSame($expected, ApiUserResolver::resolveCredentialsPhp($request->input('api_token'), $request->input('apikey')), $label);
                self::assertSame($expected, ApiUserResolver::resolveCredentialsNative($request->input('api_token'), $request->input('apikey')), $label);
            }
        }
    }

    public function test_throttle_helpers_match_php(): void
    {
        self::ffi();
        foreach ([0, 1, 42, -7, PHP_INT_MAX, PHP_INT_MIN] as $userId) {
            self::assertSame(ThrottleApiRequestsByToken::rateLimitKeyPhp($userId), ThrottleApiRequestsByToken::rateLimitKeyNative($userId));
        }
        foreach ([null, 0, -5, 1, 60, 250, PHP_INT_MAX, PHP_INT_MIN, '100', '', '0', 'abc', '12abc', 1.9, 0.0, true, false] as $rateLimit) {
            self::assertSame(
                ThrottleApiRequestsByToken::maxAttemptsPhp($rateLimit),
                ThrottleApiRequestsByToken::maxAttemptsNative($rateLimit),
                var_export($rateLimit, true),
            );
        }
        foreach ([0, 1, 59, 60, 61, 1_000_000, -3, PHP_INT_MAX, PHP_INT_MIN, '3', '03', ' 3', '-2', '3.5', 3.5] as $attempts) {
            foreach ([1, 60, PHP_INT_MAX, PHP_INT_MIN] as $max) {
                self::assertSame(
                    self::outcome(static fn () => ThrottleApiRequestsByToken::remainingAttemptsPhp($max, $attempts)),
                    self::outcome(static fn () => ThrottleApiRequestsByToken::remainingAttemptsNative($max, $attempts)),
                    var_export([$max, $attempts], true),
                );
            }
        }
        foreach ([0, 1, 37, -9, PHP_INT_MAX, PHP_INT_MIN, 0.5, 12.0] as $availableIn) {
            self::assertSame(ThrottleApiRequestsByToken::retryAfterPhp($availableIn), ThrottleApiRequestsByToken::retryAfterNative($availableIn));
        }
        foreach ([[500, 'Request limit reached'], [0, ''], [-1, "bin\0\xff"], [PHP_INT_MIN, str_repeat('m', 9000)]] as [$code, $message]) {
            self::assertSame(ThrottleApiRequestsByToken::errorHeaderPhp($code, $message), ThrottleApiRequestsByToken::errorHeaderNative($code, $message));
        }
    }

    public function test_no_cache_helpers_match_php(): void
    {
        self::ffi();
        $names = [...NoCacheForAuthenticatedUsers::AUTH_FLOW_ROUTES, null, '', 'home', 'Login', 'login ', 'login.*', '2fa', 'password.request.x'];
        foreach ([true, false] as $authenticated) {
            foreach ($names as $name) {
                self::assertSame(
                    NoCacheForAuthenticatedUsers::shouldPreventCachingForPhp($authenticated, $name),
                    NoCacheForAuthenticatedUsers::shouldPreventCachingForNative($authenticated, $name),
                    var_export([$authenticated, $name], true),
                );
            }
        }

        $random = new Randomizer(new Mt19937(20261024));
        $vary = ['', ',', '0', '0, 00', 'Cookie', 'cookie', ' Accept-Encoding , Origin ', "Cookie\x0B,\0Authorization\0", 'Authorization,Authorization', str_repeat('X-Long, ', 600)];
        $alphabet = ['Cookie', 'Authorization', 'Accept', '0', '', ' ', "\t", ',', ', ', 'Origin', "\0"];
        for ($i = 0; $i < 300; $i++) {
            $value = '';
            for ($j = $random->getInt(0, 8); $j > 0; $j--) {
                $value .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
            }
            $vary[] = $value;
        }
        foreach ($vary as $existing) {
            foreach ([true, false] as $bearer) {
                self::assertSame(
                    NoCacheForAuthenticatedUsers::mergeVaryPhp($existing, $bearer),
                    NoCacheForAuthenticatedUsers::mergeVaryNative($existing, $bearer),
                    var_export([$existing, $bearer], true),
                );
            }
        }
    }

    public function test_no_cache_middleware_headers_match_with_rust_enabled(): void
    {
        self::ffi();
        $handle = static function (string $routeName, ?string $bearer, string $vary): array {
            $request = Request::create('/'.$routeName, 'GET', [], [], [], $bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer] : []);
            $route = new Route(['GET'], $routeName, static fn (): Response => new Response('OK'));
            $route->name($routeName);
            $request->setRouteResolver(static fn (): Route => $route);
            $response = (new NoCacheForAuthenticatedUsers)->handle($request, static function () use ($vary): Response {
                $response = new Response('OK');
                if ($vary !== '') {
                    $response->headers->set('Vary', $vary);
                }

                return $response;
            });

            return $response->headers->all();
        };

        Auth::shouldReceive('check')->andReturn(false);
        foreach (['register', '2fa.post', 'home'] as $routeName) {
            foreach ([null, 'tok', '0'] as $bearer) {
                foreach (['', 'Accept-Encoding', 'Cookie, 0'] as $vary) {
                    NoCacheForAuthenticatedUsers::setUseRustFfi(false);
                    $php = $handle($routeName, $bearer, $vary);
                    NoCacheForAuthenticatedUsers::setUseRustFfi(true);
                    $native = $handle($routeName, $bearer, $vary);
                    unset($php['date'], $native['date']);
                    self::assertSame($php, $native, "{$routeName}/{$bearer}/{$vary}");
                }
            }
        }
    }

    public function test_date_range_plan_and_parse_match_php(): void
    {
        self::ffi();
        $periods = ['7days', '30days', '90days', 'year', 'all', 'ALL', '7 days', '', '0', null, 7, true, ['all']];
        foreach ([true, false] as $hasStart) {
            foreach ([true, false] as $hasEnd) {
                foreach ([true, false] as $hasPeriod) {
                    foreach ($periods as $period) {
                        foreach ([30, 0, -4, 365] as $defaultDays) {
                            self::assertSame(
                                DateRangeFilter::resolvePlanPhp($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays),
                                DateRangeFilter::resolvePlanNative($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays),
                                var_export([$hasStart, $hasEnd, $hasPeriod, $period, $defaultDays], true),
                            );
                        }
                    }
                }
            }
        }

        $dates = [
            '2026-10-24', '2024-02-29', '2000-02-29', '1000-01-01', '9999-12-31', '2026-10-24 13:45:09',
            '2026-03-08 02:30:00', '2026-11-01 01:30:00', '2026-10-18 00:00:00', '2026-10-18',
            '2026-02-29', '1900-02-29', '2026-13-01', '2026-01-05 24:00:00', '2026-01-05T10:00:00',
            '0999-01-01', '2026-1-05', 'yesterday', '-1 week', '2026-10-24 13:45:09+02:00', '@0', '',
            null, 1_700_000_000, ['x'],
        ];
        foreach (['UTC', 'America/New_York', 'America/Sao_Paulo', 'Asia/Kolkata'] as $timezone) {
            date_default_timezone_set($timezone);
            Carbon::setTestNow(Carbon::create(2026, 10, 24, 12, 0, 0));
            foreach ($dates as $value) {
                $format = static fn (?Carbon $c): ?string => $c?->format('Y-m-d H:i:s.u e U');
                self::assertSame(
                    self::outcome(static fn () => $format(DateRangeFilter::parseDatePhp($value))),
                    self::outcome(static fn () => $format(DateRangeFilter::parseDateNative($value))),
                    $timezone.' '.var_export($value, true),
                );
            }
        }
    }

    public function test_date_range_from_request_matches_with_rust_enabled(): void
    {
        self::ffi();
        Carbon::setTestNow(Carbon::create(2026, 10, 24, 12, 0, 0));
        $queries = [
            [],
            ['period' => '7days'],
            ['period' => 'year'],
            ['period' => 'all'],
            ['period' => 'bogus'],
            ['period' => ['all']],
            ['start_date' => '2026-01-01', 'end_date' => '2026-02-01 10:00:00'],
            ['start_date' => '2026-01-01', 'period' => '90days'],
            ['start_date' => 'last monday', 'end_date' => 'today', 'period' => 'all'],
        ];
        $format = static fn (array $range): array => array_map(static fn (?Carbon $c): ?string => $c?->format('Y-m-d H:i:s.u e'), $range);
        foreach ($queries as $query) {
            foreach ([30, 14] as $defaultDays) {
                DateRangeFilter::setUseRustFfi(false);
                $php = $format(DateRangeFilter::fromRequest(Request::create('/admin', 'GET', $query), $defaultDays));
                DateRangeFilter::setUseRustFfi(true);
                $native = $format(DateRangeFilter::fromRequest(Request::create('/admin', 'GET', $query), $defaultDays));
                self::assertSame($php, $native, var_export($query, true));
            }
        }
        DateRangeFilter::setUseRustFfi(false);
        self::assertSame(['2026-10-17 12:00:00', '2026-10-24 12:00:00'], array_map(
            static fn (?Carbon $c): ?string => $c?->format('Y-m-d H:i:s'),
            DateRangeFilter::fromRequest(Request::create('/admin', 'GET', ['period' => '7days'])),
        ));
        self::assertNull(DateRangeFilter::fromRequest(Request::create('/admin', 'GET', ['period' => 'all']))[0]);
    }

    public function test_dispatch_toggles_and_missing_library_fall_back_to_php(): void
    {
        $check = function (): void {
            $tracker = new SearchFailureTracker;
            $tracker->record();
            self::assertSame(1, $tracker->count());
            self::assertTrue($tracker->failedSince(0));
            self::assertTrue($tracker->reachedThreshold(1));
            self::assertSame('api_user:'.md5('tok'), ApiUserResolver::cacheKey('tok'));
            self::assertSame(['token' => 'tok', 'version' => 2], ApiUserResolver::resolveCredentials('tok', 'key'));
            self::assertSame(['token' => 'key', 'version' => 1], ApiUserResolver::resolveCredentials(null, 'key'));
            self::assertNull(ApiUserResolver::resolveCredentials('', 'key'));
            self::assertSame(['mode' => DateRangeFilter::MODE_YEAR, 'days' => 0], DateRangeFilter::resolvePlan(false, false, true, 'year', 30));
            self::assertSame('2026-01-02 03:04:05', DateRangeFilter::parseDate('2026-01-02 03:04:05')->format('Y-m-d H:i:s'));
            self::assertSame('api-rate-limit:user:9', ThrottleApiRequestsByToken::rateLimitKey(9));
            self::assertSame(60, ThrottleApiRequestsByToken::maxAttempts(null));
            self::assertSame(57, ThrottleApiRequestsByToken::remainingAttempts(60, '3'));
            self::assertSame(1, ThrottleApiRequestsByToken::retryAfter(0));
            self::assertSame('API ERROR [500] x', ThrottleApiRequestsByToken::errorHeader(500, 'x'));
            self::assertTrue(NoCacheForAuthenticatedUsers::shouldPreventCachingFor(false, 'logout'));
            self::assertFalse(NoCacheForAuthenticatedUsers::shouldPreventCachingFor(false, null));
            self::assertSame('Accept, Cookie, Authorization', NoCacheForAuthenticatedUsers::mergeVary('Accept', true));
        };

        foreach (self::CLASSES as $class) {
            self::assertFalse($class::$useRustFfi, $class);
            $class::setUseRustFfi(true);
        }
        $check();

        // A missing library resolves to false; every Native path then returns the PHP result.
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
            self::assertNull($class::searchFfi());
        }
        $check();
    }

    public function test_direct_c_abi_error_buffer_and_alignment_contracts(): void
    {
        $ffi = self::ffi();
        $raw = $ffi->new('char[64]');
        $misaligned64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $misaligned32 = $ffi->cast('int32_t *', FFI::addr($raw[1]));
        $i64 = $ffi->new('int64_t[6]');
        $i32 = $ffi->new('int32_t[1]');
        $small = $ffi->new('char[4]');

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_failure_next(1, $misaligned64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_failure_next(1, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_failure_next(-1, $i64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_failure_next(PHP_INT_MAX, $i64));

        // @phpstan-ignore method.notFound
        self::assertSame(-44, $ffi->nntmux_api_user_cache_key('t', 1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_user_cache_key(null, 1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_token_classify('t', 1, 1, '', 0, 0, $misaligned32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_token_classify('t', 1, 3, '', 0, 0, $i32));
        // @phpstan-ignore method.notFound
        self::assertSame(2, $ffi->nntmux_api_token_classify('t', 1, 1, '', 0, 0, $i32));

        // @phpstan-ignore method.notFound
        self::assertSame(-(\strlen('api-rate-limit:user:42') + 3), $ffi->nntmux_throttle_rate_limit_key(42, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_throttle_rate_limit_key(42, null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_throttle_max_attempts(5, 2, 60));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_throttle_remaining(PHP_INT_MIN, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_throttle_error_header(1, null, 3, $small, 4));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nocache_should_prevent(2, '', 0, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nocache_merge_vary('', 0, 2, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-(\strlen('Cookie') + 3), $ffi->nntmux_nocache_merge_vary('', 0, 0, $small, 4));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_date_range_plan(0, 0, 0, '', 0, 0, 30, $misaligned64, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-5, $ffi->nntmux_date_range_plan(0, 0, 0, '', 0, 0, 30, $i64, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_date_range_plan(2, 0, 0, '', 0, 0, 30, $i64, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_date_range_parse_ymd('2026-01-01', 10, $misaligned64, 6));
        // @phpstan-ignore method.notFound
        self::assertSame(-9, $ffi->nntmux_date_range_parse_ymd('2026-01-01', 10, $i64, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_date_range_parse_ymd('2026-01-01', 10, $i64, 6));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_date_range_parse_ymd(null, 10, $i64, 6));
    }

    /**
     * Run with:
     * NNTMUX_SEARCH_API_THROTTLE_NOCACHE_BENCH=1 php artisan test --compact tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_SEARCH_API_THROTTLE_NOCACHE_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_SEARCH_API_THROTTLE_NOCACHE_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $trackerPhp = self::tracker(0);
        $trackerNative = self::tracker(0);
        $tokenCommon = '0123456789abcdef0123456789abcdef';
        $tokenUncommon = str_repeat('tok-', 64);
        $varyUncommon = 'Accept-Encoding, Origin, 0, , Accept-Language, Cookie';

        $workloads = [
            'failureRecord/common' => [
                static fn () => $trackerPhp->recordPhp(),
                static fn () => $trackerNative->recordNative(),
            ],
            'failureRecord/uncommon' => [
                static fn () => self::tracker(PHP_INT_MAX - 2)->recordPhp(),
                static fn () => self::tracker(PHP_INT_MAX - 2)->recordNative(),
            ],
            'failureSince/common' => [
                static fn () => $trackerPhp->failedSincePhp($trackerPhp->count()),
                static fn () => $trackerNative->failedSinceNative($trackerNative->count()),
            ],
            'failureSince/uncommon' => [
                static fn () => $trackerPhp->failedSincePhp(-1),
                static fn () => $trackerNative->failedSinceNative(-1),
            ],
            'failureThreshold/common' => [
                static fn () => $trackerPhp->reachedThresholdPhp(3),
                static fn () => $trackerNative->reachedThresholdNative(3),
            ],
            'failureThreshold/uncommon' => [
                static fn () => $trackerPhp->reachedThresholdPhp(0),
                static fn () => $trackerNative->reachedThresholdNative(0),
            ],
            'apiUserCacheKey/common' => [
                static fn () => ApiUserResolver::cacheKeyPhp($tokenCommon),
                static fn () => ApiUserResolver::cacheKeyNative($tokenCommon),
            ],
            'apiUserCacheKey/uncommon' => [
                static fn () => ApiUserResolver::cacheKeyPhp($tokenUncommon),
                static fn () => ApiUserResolver::cacheKeyNative($tokenUncommon),
            ],
            'apiCredentials/common' => [
                static fn () => ApiUserResolver::resolveCredentialsPhp(null, $tokenCommon),
                static fn () => ApiUserResolver::resolveCredentialsNative(null, $tokenCommon),
            ],
            'apiCredentials/uncommon' => [
                static fn () => ApiUserResolver::resolveCredentialsPhp(" \t", ['x']),
                static fn () => ApiUserResolver::resolveCredentialsNative(" \t", ['x']),
            ],
            'datePlan/common' => [
                static fn () => DateRangeFilter::resolvePlanPhp(false, false, true, '30days', 30),
                static fn () => DateRangeFilter::resolvePlanNative(false, false, true, '30days', 30),
            ],
            'datePlan/uncommon' => [
                static fn () => DateRangeFilter::resolvePlanPhp(true, false, true, ['all'], 14),
                static fn () => DateRangeFilter::resolvePlanNative(true, false, true, ['all'], 14),
            ],
            'dateParse/common' => [
                static fn () => DateRangeFilter::parseDatePhp('2026-10-01'),
                static fn () => DateRangeFilter::parseDateNative('2026-10-01'),
            ],
            'dateParse/uncommon' => [
                static fn () => DateRangeFilter::parseDatePhp('2024-02-29 23:59:59'),
                static fn () => DateRangeFilter::parseDateNative('2024-02-29 23:59:59'),
            ],
            'throttleKey/common' => [
                static fn () => ThrottleApiRequestsByToken::rateLimitKeyPhp(1234),
                static fn () => ThrottleApiRequestsByToken::rateLimitKeyNative(1234),
            ],
            'throttleKey/uncommon' => [
                static fn () => ThrottleApiRequestsByToken::rateLimitKeyPhp(PHP_INT_MIN),
                static fn () => ThrottleApiRequestsByToken::rateLimitKeyNative(PHP_INT_MIN),
            ],
            'throttleMax/common' => [
                static fn () => ThrottleApiRequestsByToken::maxAttemptsPhp(250),
                static fn () => ThrottleApiRequestsByToken::maxAttemptsNative(250),
            ],
            'throttleMax/uncommon' => [
                static fn () => ThrottleApiRequestsByToken::maxAttemptsPhp(null),
                static fn () => ThrottleApiRequestsByToken::maxAttemptsNative(null),
            ],
            'throttleRemaining/common' => [
                static fn () => ThrottleApiRequestsByToken::remainingAttemptsPhp(250, '17'),
                static fn () => ThrottleApiRequestsByToken::remainingAttemptsNative(250, '17'),
            ],
            'throttleRemaining/uncommon' => [
                static fn () => ThrottleApiRequestsByToken::remainingAttemptsPhp(60, 9_999),
                static fn () => ThrottleApiRequestsByToken::remainingAttemptsNative(60, 9_999),
            ],
            'throttleRetryAfter/common' => [
                static fn () => ThrottleApiRequestsByToken::retryAfterPhp(42),
                static fn () => ThrottleApiRequestsByToken::retryAfterNative(42),
            ],
            'throttleRetryAfter/uncommon' => [
                static fn () => ThrottleApiRequestsByToken::retryAfterPhp(0),
                static fn () => ThrottleApiRequestsByToken::retryAfterNative(0),
            ],
            'throttleErrorHeader/common' => [
                static fn () => ThrottleApiRequestsByToken::errorHeaderPhp(500, 'Request limit reached'),
                static fn () => ThrottleApiRequestsByToken::errorHeaderNative(500, 'Request limit reached'),
            ],
            'throttleErrorHeader/uncommon' => [
                static fn () => ThrottleApiRequestsByToken::errorHeaderPhp(PHP_INT_MIN, str_repeat('m', 512)),
                static fn () => ThrottleApiRequestsByToken::errorHeaderNative(PHP_INT_MIN, str_repeat('m', 512)),
            ],
            'noCachePrevent/common' => [
                static fn () => NoCacheForAuthenticatedUsers::shouldPreventCachingForPhp(false, 'browse'),
                static fn () => NoCacheForAuthenticatedUsers::shouldPreventCachingForNative(false, 'browse'),
            ],
            'noCachePrevent/uncommon' => [
                static fn () => NoCacheForAuthenticatedUsers::shouldPreventCachingForPhp(false, '2faVerify'),
                static fn () => NoCacheForAuthenticatedUsers::shouldPreventCachingForNative(false, '2faVerify'),
            ],
            'noCacheVary/common' => [
                static fn () => NoCacheForAuthenticatedUsers::mergeVaryPhp('', false),
                static fn () => NoCacheForAuthenticatedUsers::mergeVaryNative('', false),
            ],
            'noCacheVary/uncommon' => [
                static fn () => NoCacheForAuthenticatedUsers::mergeVaryPhp($varyUncommon, true),
                static fn () => NoCacheForAuthenticatedUsers::mergeVaryNative($varyUncommon, true),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertEquals($phpCall(), $rustCall(), $name);
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
            fwrite(STDOUT, sprintf("%-34s %s\n", $name, implode('  ', $reps)));
        }
    }
}
