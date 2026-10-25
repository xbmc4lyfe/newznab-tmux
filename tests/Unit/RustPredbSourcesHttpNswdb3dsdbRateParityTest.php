<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MovieBrowseService;
use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\Sources\BoundedHttpFeedSource;
use App\Services\Predb\Feeds\Sources\HttpFeedSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbSourcesHttpNswdb3dsdbRateParityTest extends TestCase
{
    /**
     * Per-class toggle names: parent and child classes keep distinct methods, constants and storage.
     *
     * @var array<class-string, array{use: string, uses: string, reset: string, default: string, cdef: string, ffi: string, method: string}>
     */
    private const CLASSES = [
        HttpFeedSource::class => ['use' => 'useHttpFeedRustFfi', 'uses' => 'usesHttpFeedRustFfi', 'reset' => 'resetHttpFeedRustFfi', 'default' => 'HTTP_FEED_RUST_FFI_DEFAULT', 'cdef' => 'HTTP_FEED_FFI_CDEF', 'ffi' => 'httpFeedFfi', 'method' => 'planHttpFeedRequest'],
        NswdbSource::class => ['use' => 'useNswdbRustFfi', 'uses' => 'usesNswdbRustFfi', 'reset' => 'resetNswdbRustFfi', 'default' => 'NSWDB_RUST_FFI_DEFAULT', 'cdef' => 'NSWDB_FFI_CDEF', 'ffi' => 'nswdbFfi', 'method' => 'planNswdbPage'],
        ThreeDsDbSource::class => ['use' => 'useThreeDsDbRustFfi', 'uses' => 'usesThreeDsDbRustFfi', 'reset' => 'resetThreeDsDbRustFfi', 'default' => 'THREE_DS_DB_RUST_FFI_DEFAULT', 'cdef' => 'THREE_DS_DB_FFI_CDEF', 'ffi' => 'threeDsDbFfi', 'method' => 'planThreeDsDbPage'],
        FeedRateLimitedException::class => ['use' => 'useRustFfi', 'uses' => 'usesRustFfi', 'reset' => 'resetRustFfi', 'default' => 'RUST_FFI_DEFAULT', 'cdef' => 'RATE_LIMIT_FFI_CDEF', 'ffi' => 'rateLimitFfi', 'method' => 'planRateLimitBackoff'],
        MovieBrowseService::class => ['use' => 'useMovieBrowseFilterRustFfi', 'uses' => 'usesMovieBrowseFilterRustFfi', 'reset' => 'resetMovieBrowseFilterRustFfi', 'default' => 'MOVIE_BROWSE_FILTER_RUST_FFI_DEFAULT', 'cdef' => 'MOVIE_BROWSE_FILTER_FFI_CDEF', 'ffi' => 'movieBrowseFilterFfi', 'method' => 'planMovieBrowseFilter'],
    ];

    /** Strings that exercise trimming, casing, slashes, UTF-8, separators and NUL bytes. */
    private const TEXTS = [
        '',
        ' ',
        '0',
        '00',
        "\0\x0B\t\r\n",
        "\x0C",
        'NNTmux-PreDB-Importer/1.0',
        ' NNTmux-PreDB-Importer/1.0 ',
        'Rate limit exceeded',
        'RATELIMIT',
        'Too Many Requests',
        'throttled, slow down',
        'rate  limit',
        'RÄTE LIMIT',
        'Alien',
        "O\\'Brien",
        'back\\\\slash',
        'nul\\0escape',
        'trailing\\',
        '\\',
        '1999',
        '2024',
        "2024\n",
        "2024\n\n",
        "\n2024",
        '1899',
        '19\\99',
        '20a4',
        '7',
        'tt0111161',
        'Ünïcödé  Ñame',
        'ポケモン',
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "\x1F",
        "nul\0byte",
    ];

    private const FIELDS = ['title', 'director', 'actors', 'genre', 'rating', 'year', 'imdb', 'Title', 'imdbid', 'plot', '', "year\0", "\x1F"];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 15, 99, 100, 101, 199, 200, 299, 399, 400, 429, 499, 500, 503, 599, 600, 899, 900, 901, 4999, 5000, 5001, PHP_INT_MAX - 1, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class => $names) {
            [$class, $names['reset']]();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::CLASSES as $class => $names) {
            $uses = [$class, $names['uses']];
            $use = [$class, $names['use']];
            $this->assertFalse((new ReflectionClassConstant($class, $names['default']))->getValue(), $class);
            $this->assertFalse($uses(), $class);
            $use(true);
            $this->assertTrue($uses(), $class);
            $use(false);
            $this->assertFalse($uses(), $class);
            $use(null);
            $this->assertFalse($uses(), $class);
            $use();
            $this->assertTrue($uses(), $class);
            [$class, $names['reset']]();
            $this->assertFalse($uses(), $class);
            $this->assertNull((new ReflectionProperty($class, $names['ffi']))->getValue(), $class);
        }
    }

    public function test_toggles_are_independent_of_parent_and_sibling_classes(): void
    {
        try {
            HttpFeedSource::useHttpFeedRustFfi(true);
            $this->assertFalse(BoundedHttpFeedSource::usesRustFfi());
            $this->assertFalse(SrrdbSource::usesRustFfi());
            $this->assertFalse(XrelSource::usesRustFfi());
            $this->assertFalse(NswdbSource::usesNswdbRustFfi());
            $this->assertFalse(ThreeDsDbSource::usesThreeDsDbRustFfi());
            // Subclasses inherit the parent's distinctly named toggle rather than shadowing it.
            $this->assertTrue(NswdbSource::usesHttpFeedRustFfi());

            BoundedHttpFeedSource::useRustFfi(true);
            NswdbSource::useNswdbRustFfi(true);
            $this->assertFalse(ThreeDsDbSource::usesThreeDsDbRustFfi());
            $this->assertFalse(FeedRateLimitedException::usesRustFfi());
            $this->assertFalse(MovieBrowseService::usesMovieBrowseFilterRustFfi());
            NswdbSource::resetNswdbRustFfi();
            $this->assertTrue(HttpFeedSource::usesHttpFeedRustFfi());
            $this->assertTrue(BoundedHttpFeedSource::usesRustFfi());
            HttpFeedSource::resetHttpFeedRustFfi();
            $this->assertTrue(BoundedHttpFeedSource::usesRustFfi());

            // The movie filter toggle is separate from the existing getMovieOrder() toggle.
            MovieBrowseService::setUseRustFfi(true);
            $this->assertFalse(MovieBrowseService::usesMovieBrowseFilterRustFfi());
        } finally {
            BoundedHttpFeedSource::resetRustFfi();
            MovieBrowseService::setUseRustFfi(false);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['timeout' => 15, 'connect_timeout' => 10, 'attempts' => 2, 'retry_sleep_ms' => 500, 'retryable' => true, 'rate_limited' => false, 'client_error' => false, 'user_agent' => 'NNTmux-PreDB-Importer/1.0'],
            $this->php(HttpFeedSource::class, 'planHttpFeedRequest', 15, 503, false, " NNTmux-PreDB-Importer/1.0\n"),
        );
        $this->assertSame(
            ['timeout' => 5, 'connect_timeout' => 5, 'attempts' => 2, 'retry_sleep_ms' => 500, 'retryable' => false, 'rate_limited' => true, 'client_error' => true, 'user_agent' => ''],
            $this->php(HttpFeedSource::class, 'planHttpFeedRequest', 5, 429, false, ''),
        );
        $this->assertTrue($this->php(HttpFeedSource::class, 'planHttpFeedRequest', 15, 200, true, 'ua')['retryable']);
        $this->assertFalse($this->php(HttpFeedSource::class, 'planHttpFeedRequest', 15, 429, true, 'ua')['rate_limited']);
        $this->assertFalse($this->php(HttpFeedSource::class, 'planHttpFeedRequest', 15, 600, false, 'ua')['retryable']);

        $this->assertSame(
            ['key' => 'nswdb', 'label' => 'nswdb.com', 'log_prefix' => 'NSWDB', 'valid_page' => true, 'page_count' => 3, 'past_end' => false, 'offset' => 200, 'length' => 50, 'entries' => 250],
            $this->php(NswdbSource::class, 'planNswdbPage', 250, 100, 3),
        );
        $this->assertSame(
            ['key' => '3dsdb', 'label' => '3dsdb.com', 'log_prefix' => '3DSDB', 'valid_page' => true, 'page_count' => 3, 'past_end' => true, 'offset' => 0, 'length' => 0, 'entries' => 250],
            $this->php(ThreeDsDbSource::class, 'planThreeDsDbPage', 250, 100, 4),
        );
        $this->assertSame(
            ['key' => '3dsdb', 'label' => '3dsdb.com', 'log_prefix' => '3DSDB', 'valid_page' => false, 'page_count' => 5000, 'past_end' => false, 'offset' => 0, 'length' => 0, 'entries' => 5000],
            $this->php(ThreeDsDbSource::class, 'planThreeDsDbPage', PHP_INT_MAX, PHP_INT_MIN, 0),
        );
        $nswdb = new NswdbSource('https://nswdb.example/export.xml');
        $threeDs = new ThreeDsDbSource('https://3dsdb.example/export.xml');
        $this->assertSame($nswdb->key(), NswdbSource::planNswdbPage(1, 1, 1)['key']);
        $this->assertSame($threeDs->key(), ThreeDsDbSource::planThreeDsDbPage(1, 1, 1)['key']);
        // The plan mirrors fetch() slicing over the parsed entries.
        foreach ([0, 1, 99, 100, 101, 250, 5000] as $count) {
            $entries = range(1, max(1, $count));
            $entries = $count === 0 ? [] : $entries;
            foreach ([1, 7, 100] as $size) {
                foreach ([1, 2, 3, 50, 5001] as $page) {
                    $plan = NswdbSource::planNswdbPage($count, $size, $page);
                    $expected = $page > (int) ceil(count($entries) / $size) ? [] : array_slice($entries, ($page - 1) * $size, $size);
                    $this->assertSame($expected, array_slice($entries, $plan['offset'], $plan['length']), "{$count}/{$size}/{$page}");
                    $this->assertSame($expected === [], $plan['past_end'] || $plan['length'] === 0);
                }
            }
        }

        $this->assertSame(
            ['throttled' => true, 'attempt' => 1, 'backoff_seconds' => 15, 'give_up' => false, 'reason' => 'Rate Limit exceeded'],
            $this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', "  Rate Limit exceeded\n", 1, 0),
        );
        $this->assertSame(
            ['throttled' => false, 'attempt' => 6, 'backoff_seconds' => 480, 'give_up' => true, 'reason' => ''],
            $this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', '', 6, 0),
        );
        $this->assertSame(900, $this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', 'x', PHP_INT_MAX, -1)['backoff_seconds']);
        $this->assertSame(30, $this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', 'x', PHP_INT_MIN, 30)['backoff_seconds']);
        $this->assertFalse($this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', 'RÄTE LIMIT', 1, 0)['throttled']);
        $this->assertSame(120, strlen($this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', str_repeat('x', 200), 1, 0)['reason']));
        $exception = new FeedRateLimitedException('Too many requests');
        $this->assertTrue(FeedRateLimitedException::planRateLimitBackoff($exception->getMessage(), 1, 0)['throttled']);

        $skip = ['applied' => false, 'operator' => '', 'column' => '', 'value' => ''];
        $this->assertSame(['applied' => true, 'operator' => 'LIKE', 'column' => 'm.title', 'value' => '%Alien%'], $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'title', 'Alien'));
        $this->assertSame(['applied' => true, 'operator' => 'LIKE', 'column' => 'm.rating', 'value' => '%7.%'], $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'rating', '7'));
        $this->assertSame(['applied' => true, 'operator' => '=', 'column' => 'm.imdbid', 'value' => "tt\x00123"], $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'imdb', 'tt\\0123'));
        $this->assertSame(['applied' => true, 'operator' => '=', 'column' => 'm.year', 'value' => "2024\n"], $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'year', "2024\n"));
        $this->assertSame($skip, $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'year', '1899'));
        $this->assertSame($skip, $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'genre', '0'));
        $this->assertSame($skip, $this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'Genre', 'x'));
    }

    public function test_movie_browse_filter_plan_matches_get_browse_by(): void
    {
        $service = (new \ReflectionClass(MovieBrowseService::class))->newInstanceWithoutConstructor();
        $getBrowseBy = new ReflectionMethod(MovieBrowseService::class, 'getBrowseBy');
        foreach (['title', 'director', 'actors', 'genre', 'rating', 'year', 'imdb'] as $field) {
            foreach (['Alien', "O\\'Brien", '7', '1999', '1899', '0', '', 'tt0111161', 'a\\\\b'] as $value) {
                $this->app->instance('request', Request::create('/movies', 'GET', [$field => $value]));
                $plan = MovieBrowseService::planMovieBrowseFilter($field, $value);
                $expected = $plan['applied'] ? ' AND '.$plan['column'].' '.($plan['operator'] === '=' ? '= ' : 'LIKE ').escapeString($plan['value']) : '';
                $this->assertSame(' '.$expected, $getBrowseBy->invoke($service), "{$field}={$value}");
            }
        }
    }

    public function test_edge_cases_match_php_through_native_and_dispatch(): void
    {
        $this->ffiOrSkip();

        foreach (self::INTS as $timeout) {
            foreach (self::INTS as $status) {
                $this->assertParity(HttpFeedSource::class, [$timeout, $status, $status % 2 === 0, 'ua']);
            }
        }
        foreach (self::TEXTS as $userAgent) {
            $this->assertParity(HttpFeedSource::class, [15, 429, false, $userAgent]);
            $this->assertParity(HttpFeedSource::class, [15, 0, true, $userAgent]);
        }

        foreach (self::INTS as $count) {
            foreach (self::INTS as $size) {
                foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 50, 4999, 5000, 5001, PHP_INT_MAX] as $page) {
                    $this->assertParity(NswdbSource::class, [$count, $size, $page]);
                    $this->assertParity(ThreeDsDbSource::class, [$count, $size, $page]);
                }
            }
        }

        foreach (self::TEXTS as $message) {
            foreach ([PHP_INT_MIN, 0, 1, 5, 6, 7, 8, PHP_INT_MAX] as $attempt) {
                $this->assertParity(FeedRateLimitedException::class, [$message, $attempt, 0]);
            }
            $this->assertParity(FeedRateLimitedException::class, [$message.str_repeat(' x', 100), 3, 0]);
        }
        foreach (self::INTS as $retryAfter) {
            $this->assertParity(FeedRateLimitedException::class, ['Rate limit', 2, $retryAfter]);
        }

        foreach (self::FIELDS as $field) {
            foreach (self::TEXTS as $value) {
                $this->assertParity(MovieBrowseService::class, [$field, $value]);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3840));
        $alphabet = "aAbBcCdDeEfFrRtTlLiImMzZ0129-_./:'\\ \t\n\r\0\x0B\x0C\x1F\x1E\xc3\xa9\xff";
        $string = static function (int $max = 40) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => self::INTS[$random->getInt(0, count(self::INTS) - 1)],
            1 => $random->getInt(-10, 700),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(0, 6000),
        };
        $phrases = ['rate limit', 'RateLimit', 'TOO MANY REQUESTS', 'Throttling', 'ok', 'error'];

        for ($i = 0; $i < 1200; $i++) {
            $userAgent = $random->getInt(0, 1) === 0 ? $string(48) : self::TEXTS[$random->getInt(0, count(self::TEXTS) - 1)];
            $this->assertParity(HttpFeedSource::class, [$int(), $int(), $random->getInt(0, 1) === 1, $userAgent]);

            $this->assertParity(NswdbSource::class, [$int(), $int(), $int()]);
            $this->assertParity(ThreeDsDbSource::class, [$int(), $int(), $int()]);

            $message = $string(30).($random->getInt(0, 1) === 0 ? $phrases[$random->getInt(0, count($phrases) - 1)] : '').$string(160);
            $this->assertParity(FeedRateLimitedException::class, [$message, $int(), $int()]);

            $field = self::FIELDS[$random->getInt(0, count(self::FIELDS) - 1)];
            $value = match ($random->getInt(0, 3)) {
                0 => (string) $random->getInt(1850, 2150).['', "\n", ' ', "\n\n"][$random->getInt(0, 3)],
                1 => self::TEXTS[$random->getInt(0, count(self::TEXTS) - 1)],
                default => $string(60),
            };
            $this->assertParity(MovieBrowseService::class, [$field, $value]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[512]');
        $small = $ffi->new('char[4]');

        // Exact wire encodings.
        $written = $this->callFfi($ffi, 'nntmux_w384_http_feed_request_plan', 15, 503, 0, " ua\x1Fx ", 7, $out, 512);
        $this->assertSame("15\x1F10\x1F2\x1F500\x1F1\x1F0\x1F0\x1Fua\x1Fx", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w384_catalogue_page_plan', 0, 250, 100, 3, $out, 512);
        $this->assertSame("nswdb\x1Fnswdb.com\x1FNSWDB\x1F1\x1F3\x1F0\x1F200\x1F50\x1F250", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w384_catalogue_page_plan', 1, 250, 100, 4, $out, 512);
        $this->assertSame("3dsdb\x1F3dsdb.com\x1F3DSDB\x1F1\x1F3\x1F1\x1F0\x1F0\x1F250", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w384_rate_limit_backoff_plan', 3, 0, 'Slow down', 9, $out, 512);
        $this->assertSame("0\x1F3\x1F60\x1F0\x1FSlow down", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w384_movie_browse_filter_plan', 'rating', 6, "7\x1F", 2, $out, 512);
        $this->assertSame("1\x1FLIKE\x1Fm.rating\x1F%7\x1F.%", FFI::string($out, $written));

        // A tiny out_cap reports -required without writing, and the exact size then succeeds.
        foreach ([
            ['nntmux_w384_http_feed_request_plan', [15, 200, 0, 'ua', 2]],
            ['nntmux_w384_catalogue_page_plan', [0, 10, 3, 2]],
            ['nntmux_w384_catalogue_page_plan', [1, 10, 3, 2]],
            ['nntmux_w384_rate_limit_backoff_plan', [1, 0, '', 0]],
            ['nntmux_w384_movie_browse_filter_plan', ['x', 1, 'y', 1]],
            ['nntmux_w384_movie_browse_filter_plan', ['title', 5, 'Alien', 5]],
        ] as [$symbol, $args]) {
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 3]);
            $this->assertLessThan(-1, $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $exact = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $exact, -$needed]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 512]), $symbol.' null out_buf');
        }

        // Invalid flags and sources decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_http_feed_request_plan', 15, 200, 2, 'ua', 2, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_catalogue_page_plan', 2, 10, 3, 2, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_catalogue_page_plan', 0xFFFFFFFF, 10, 3, 2, $out, 512));

        // Null input pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_http_feed_request_plan', 15, 200, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_rate_limit_backoff_plan', 1, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_movie_browse_filter_plan', null, 0, 'v', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w384_movie_browse_filter_plan', 'title', 5, null, 0, $out, 512));

        // Every dispatcher resolves its own native handle.
        foreach (self::CLASSES as $class => $names) {
            [$class, $names['use']](true);
        }
        HttpFeedSource::planHttpFeedRequest(15, 200, false, 'ua');
        NswdbSource::planNswdbPage(10, 3, 1);
        ThreeDsDbSource::planThreeDsDbPage(10, 3, 1);
        FeedRateLimitedException::planRateLimitBackoff('rate limit', 1, 0);
        MovieBrowseService::planMovieBrowseFilter('title', 'x');
        foreach (self::CLASSES as $class => $names) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, $names['ffi']))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::CLASSES as $class => $names) {
            (new ReflectionProperty($class, $names['ffi']))->setValue(null, false);
            [$class, $names['use']](true);
        }

        $this->assertSame($this->php(HttpFeedSource::class, 'planHttpFeedRequest', 12, 502, false, ' ua '), HttpFeedSource::planHttpFeedRequest(12, 502, false, ' ua '));
        $this->assertSame($this->php(NswdbSource::class, 'planNswdbPage', 250, 100, 3), NswdbSource::planNswdbPage(250, 100, 3));
        $this->assertSame($this->php(ThreeDsDbSource::class, 'planThreeDsDbPage', 250, 100, 3), ThreeDsDbSource::planThreeDsDbPage(250, 100, 3));
        $this->assertSame($this->php(FeedRateLimitedException::class, 'planRateLimitBackoff', 'Rate limit', 4, 0), FeedRateLimitedException::planRateLimitBackoff('Rate limit', 4, 0));
        $this->assertSame($this->php(MovieBrowseService::class, 'planMovieBrowseFilter', 'year', '2001'), MovieBrowseService::planMovieBrowseFilter('year', '2001'));

        $cdef = (new ReflectionClassConstant(HttpFeedSource::class, 'HTTP_FEED_FFI_CDEF'))->getValue();
        $this->assertIsString($cdef);
        $this->assertNull(RustPipelineLibrary::resolveDomain('metadata_w384', $cdef, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib'));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = default user-agent request plans, first/last catalogue pages, short throttling bodies and
        // typical movie title/year/genre filters; uncommon = padded user agents, extreme ints, 2 KiB HTML
        // throttling bodies, long slashed actor lists, newline-terminated years and unknown filter keys.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, rustc 1.99.1, Apple M5, 3 rounds):
        // - Round 1: common mean 0.35x / p99 0.42x, uncommon mean 0.44x / p99 0.45x
        // - Round 2: common mean 0.35x / p99 0.38x, uncommon mean 0.44x / p99 0.47x
        // - Round 3: common mean 0.36x / p99 0.39x, uncommon mean 0.44x / p99 0.45x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php --filter benchmark
        // PHP's sub-microsecond scalar work beats the FFI round trip plus wire packing on both workloads, so
        // every class stays below the 1.5x bar in .ai/rules/rust-performance.md and keeps its default false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3841));
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
            foreach (self::CLASSES as $class => $names) {
                [$class, $names['use']](null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustPredbSourcesHttpNswdb3dsdbRate speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::CLASSES as $class => $names) {
                $this->assertFalse([$class, $names['uses']](), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * Compares the PHP reference with the native variant and both dispatcher modes.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $names = self::CLASSES[$class];
        $method = $names['method'];
        $context = $class.'::'.$method.'('.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR).')';
        $expected = $this->php($class, $method, ...$args);
        $this->assertSame($expected, (new ReflectionMethod($class, $method.'Native'))->invoke(null, ...$args), $context.' native');
        $use = [$class, $names['use']];
        $use(false);
        $this->assertSame($expected, $class::$method(...$args), $context.' php dispatch');
        $use(true);
        $this->assertSame($expected, $class::$method(...$args), $context.' rust dispatch');
        $use(null);
    }

    /**
     * @param  class-string  $class
     */
    private function php(string $class, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, $method.'Php'))->invoke(null, ...$args);
    }

    /**
     * @return list<array{class-string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            [HttpFeedSource::class, [15, 200, false, 'NNTmux-PreDB-Importer/1.0']],
            [HttpFeedSource::class, [15, 503, false, 'NNTmux-PreDB-Importer/1.0']],
            [NswdbSource::class, [4200, 100, 1]],
            [NswdbSource::class, [4200, 100, 43]],
            [ThreeDsDbSource::class, [3100, 100, 2]],
            [FeedRateLimitedException::class, ['Rate limit exceeded, retry later', 1, 0]],
            [FeedRateLimitedException::class, ['Too many requests', 2, 30]],
            [MovieBrowseService::class, ['title', 'The Matrix']],
            [MovieBrowseService::class, ['year', '1999']],
            [MovieBrowseService::class, ['genre', 'Sci-Fi']],
        ];
    }

    /**
     * @return list<array{class-string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        return [
            [HttpFeedSource::class, [PHP_INT_MAX, 429, false, str_repeat(' ', 32).'Mozilla/5.0 (X11; Linux x86_64) NNTmux'.str_repeat("\t", 32)]],
            [HttpFeedSource::class, [PHP_INT_MIN, 0, true, "a\x1Fb"]],
            [NswdbSource::class, [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX]],
            [ThreeDsDbSource::class, [5000, 1, 4999]],
            [ThreeDsDbSource::class, [-1, PHP_INT_MAX, PHP_INT_MIN]],
            [FeedRateLimitedException::class, [str_repeat('<html><body>Service busy. ', 80).'Throttled.</body></html>', PHP_INT_MAX, -1]],
            [FeedRateLimitedException::class, [str_repeat("Ünïcödé \x1F ", 40), 9, PHP_INT_MAX]],
            [MovieBrowseService::class, ['actors', str_repeat("Tom O\\'Brien, ", 40)]],
            [MovieBrowseService::class, ['year', "2024\n"]],
            [MovieBrowseService::class, ['plot', 'ignored']],
        ];
    }

    /**
     * @param  list<array{class-string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::CLASSES as $class => $names) {
            [$class, $names['use']]($useRust);
        }
        $count = 0;
        foreach ($workload as [$class, $args]) {
            $method = self::CLASSES[$class]['method'];
            $count += count($class::$method(...$args));
        }

        return $count;
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

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdefs = [];
        foreach (self::CLASSES as $class => $names) {
            $cdef = (new ReflectionClassConstant($class, $names['cdef']))->getValue();
            $this->assertIsString($cdef);
            $cdefs[] = $cdef;
        }
        // NSWDB and 3DSDB declare the same export; declare it once.
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w384', implode("\n", array_values(array_unique($cdefs))));
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the w384 PreDB source, rate-limit and movie filter exports is not available.');
        }

        return $ffi;
    }
}
