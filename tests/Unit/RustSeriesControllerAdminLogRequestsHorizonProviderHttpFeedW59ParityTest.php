<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\SeriesController;
use App\Http\Requests\Admin\AdminLogFileRequest;
use App\Http\Requests\Admin\AdminLogViewerRequest;
use App\Providers\HorizonServiceProvider;
use App\Services\Predb\Feeds\Sources\HttpFeedSource;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant and the trailer word count. */
    private const TARGETS = [
        SeriesController::class => ['w59SeriesQueryBatch', 'nntmux_w593_series_query_batch', 'W59_SERIES_CONTROLLER_FFI_CDEF', 49],
        AdminLogFileRequest::class => ['w59LogFileCheckBatch', 'nntmux_w593_admin_log_file_check_batch', 'W59_ADMIN_LOG_FILE_REQUEST_FFI_CDEF', 18],
        AdminLogViewerRequest::class => ['w59LogViewerPlanBatch', 'nntmux_w593_admin_log_viewer_plan_batch', 'W59_ADMIN_LOG_VIEWER_REQUEST_FFI_CDEF', 26],
        HorizonServiceProvider::class => ['w59HorizonAccessBatch', 'nntmux_w593_horizon_access_batch', 'W59_HORIZON_SERVICE_PROVIDER_FFI_CDEF', 19],
        HttpFeedSource::class => ['w59HttpFeedResponseBatch', 'nntmux_w593_http_feed_response_batch', 'W59_HTTP_FEED_SOURCE_FFI_CDEF', 14],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        SeriesController::class => true,
        AdminLogFileRequest::class => true,
        AdminLogViewerRequest::class => true,
        HorizonServiceProvider::class => true,
        HttpFeedSource::class => true,
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r\n", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', '||||', '|||||', '||||||',
        ' | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g', '.', '..', '...', '/', '../', '/..', '/../', 'a/../b', 'a\\b', '.log',
        'LARAVEL.LOG', 'x.log.1', 'x.log.123', 'x.log.1234', 'x.LOG.9', '.gz', 'a.txt', 'laravel-2026-01-02.log', 'laravel-2026-1-02.log',
        'x-0000-00-00.LOG', '-2026-01-02.log', 'S01E02', 's1e1', 's1234e5', 'se1', 's01e', 'S001E0001', 'S1E12345', 'ss12e3', 'a s9e9 s1e2',
        '0', '00', '0000', '00000', '1', '9999', '10000', '999999', '1000000', '18446744073709551616', '-1', '+1', '1e3', '0x10', 'name_asc',
        'NAME_ASC', 'stats_desc', 'posted', 'debug', 'DEBUG', 'error,warning', ',,,', 'info, notice ,', 'laravel', 'Daily', 'bad chan', 'a_b-c',
        '"', '""', '"a b"', '"a', '*', '?', '(', '[x]', '{y}', '^$', '\\+', 'local', 'LOCAL', 'production', 'Admin', 'admin', 'ADMIN',
        'Admin,admin', 'a@b.c', '@b.c', 'a@', 'a@b', 'a@.b', 'a@b.', 'a@@b.c', 'a b@c.d', 'x@y.local', 'x@y.LAN', 'x@y.internal', '127.0.0.1',
        '10.1.2.3', '172.16.0.1', '172.32.0.1', '192.168.1.1', '169.254.0.1', '8.8.8.8', '256.1.1.1', '1.2.3', '1.2.3.4.5', '1..2.3', '0001.2.3.4',
        '::1', '::', ':::', '::1::', 'fe80::1', 'FE80::1', 'fc00::1', 'fd12::', '2001:db8::1', 'g::1', '1:2:3:4:5:6:7:8:9:a:b:c:d:e:f:0:1',
        '59', '60', '299', '300', '999999999', '1000000000', 'http://', 'https://', 'http://a', 'HTTP://A.B', 'ftp://x', '://x', 'http://.a',
        'http://a-', 'http://-a', 'http://a:0', 'http://a:65535', 'http://a:65536', 'http://a:', 'http://u:p@h', 'http://a@b@c', 'https://h:443',
        'http://h:80', 'https://h:80', 'http://h?q', 'http://h#f?x', 'http://h/p?x', 'http://[::1]/', '200', '304', '429', '500', '599', '600',
        '099', '2000', '20', 'Wed, 21 Oct 2015 07:28:00 GMT', 'wed, 21 oct 2015 07:28:00 gmt', 'Wed, 21 Oct 2015 07:28:00 GMT ', '86401',
        '9999999999', '99999999999', 'application/xml', 'TEXT/XML; charset=utf-8', 'application/vnd.api+json', 'text/html;charset=', 'text/plain;',
        "a; charset='latin1'", 'charset=iso-8859-1', 'x;charset="koi8-r"', 'gzip', 'X-GZIP', 'identity', 'br', 'deflate', 'compress', '1048576',
        '1048577', '999999999999999', '1000000000000000'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 1100), str_repeat('/', 300), str_repeat('../', 90),
            'logs/'.str_repeat('d/', 20).'x.log', str_repeat('"', 301), str_repeat('* ', 200), str_repeat('s1e1', 100), str_repeat('|', 300),
            'http://'.str_repeat('a', 253), 'http://'.str_repeat('a', 254), 'http://'.str_repeat('a', 300).'.com', str_repeat('9', 40),
            str_repeat('Admin,', 20), str_repeat(':', 50), str_repeat("\xc3\xa9", 200), str_repeat(',', 500)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w59_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW59RustFfi', 'withW59RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->isFinal() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W59_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            $state = new ReflectionMethod($class, 'w59RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w59RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW59RustFfi');
            $with = new ReflectionMethod($class, 'withW59RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W59 toggle signatures';
            }
            // Toggle state lives in function-local statics only, never in static properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w59')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
        }
        // Each class declares its own FFI declaration constant name, so none shadows a sibling's or a subclass's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w59_http_feed_subclasses_inherit_the_shared_toggle(): void
    {
        $subclasses = [];
        foreach (glob(\dirname(__DIR__, 2).'/app/Services/Predb/Feeds/Sources/*.php') ?: [] as $file) {
            $class = 'App\\Services\\Predb\\Feeds\\Sources\\'.basename($file, '.php');
            if ($class !== HttpFeedSource::class && is_subclass_of($class, HttpFeedSource::class)) {
                $subclasses[] = $class;
            }
        }
        $this->assertNotEmpty($subclasses);
        foreach ($subclasses as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW59RustFfi', 'withW59RustFfi', 'w59HttpFeedResponseBatch', 'w59RustFfiState'] as $method) {
                $this->assertSame(HttpFeedSource::class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $this->assertSame(HttpFeedSource::class, $reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT')?->getDeclaringClass()->getName(), $class);
        }
        $flipped = ! HttpFeedSource::usesW59RustFfi();
        HttpFeedSource::withW59RustFfi($flipped, function () use ($subclasses, $flipped): void {
            foreach ($subclasses as $class) {
                $this->assertSame($flipped, $class::usesW59RustFfi(), $class);
            }
        });
    }

    public function test_w59_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), $class);
            $this->assertTrue($class::withW59RustFfi(true, static fn (): bool => $class::usesW59RustFfi()));
            $this->assertFalse($class::withW59RustFfi(false, static fn (): bool => $class::usesW59RustFfi()));
            $this->assertTrue($class::withW59RustFfi(false, static fn (): bool => $class::withW59RustFfi(true, static fn (): bool => $class::usesW59RustFfi())));
            $this->assertFalse($class::withW59RustFfi(true, static fn (): bool => $class::withW59RustFfi(false, static fn (): bool => $class::usesW59RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), $class);
            try {
                $class::withW59RustFfi(! $class::usesW59RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), $class.' restores after an exception');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW59RustFfi();
            $class::withW59RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW59RustFfi(), $class.' vs '.$other);
                }
            });
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['Show S01E02|1|2|name_asc|3', 'laravel.log|100||200', 'a.log|"x y"|error|daily|2', 'local|1|Admin|a@b.c|::1|5',
                'https://h/x|200||text/xml|10|gzip', ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW59RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW59RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w59_existing_public_api_is_unchanged(): void
    {
        $series = (new ReflectionClass(SeriesController::class))->newInstanceWithoutConstructor();
        $this->assertSame('0-9', $series->classifyTitleBucketPhp('24'));
        $this->assertSame('B', $series->classifyTitleBucket('Breaking Bad'));
        $this->assertSame(['file' => 'x.log'], AdminLogFileRequest::normalizeInputPhp(' x.log '));
        $this->assertSame(['error', 'info'], AdminLogFileRequest::normalizeLevelsPhp(['error', 'info', 'error']));
        $this->assertSame(['file' => 'x', 'q' => 'y'], AdminLogViewerRequest::normalizeInputPhp(' x ', ' y '));
        $this->assertSame(['authenticated' => true, 'is_admin' => true, 'is_local' => false, 'gate_allowed' => true, 'allowed' => true],
            HorizonServiceProvider::summarizeHorizonAccessDecisionPhp(1, true, 'production'));
        $this->assertTrue(HttpFeedSource::planHttpFeedRequest(15, 429, false, 'ua')['rate_limited']);
    }

    public function test_w59_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('categorize_w593', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = ['laravel.log|100||200', 'local|1|Admin|a@b.c|::1|5'];
            $blob = implode("\0", $items);
            $words = $trailer + 2;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
        }
    }

    public function test_w59_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = ['Show S01E02|1|2|name_asc|3', 'logs/a-2026-01-02.log||50|50', 'a.log|"x y" z*|error,info|daily|2', 'local|1|Admin|a@b.c|::1|5',
                'https://h/x?a|429|30|text/xml|10|gzip', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w59Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, list<int>}>
     */
    public static function knownCases(): array
    {
        $fill = static fn (int $n, array $set): array => array_replace(array_fill(0, $n, 0), $set);

        return [
            'series: marker query, bare digits and invalid parameters' => [SeriesController::class,
                ['Breaking Bad S01E02|1|2|name_asc|3', '24|||', '|x|y|zz|0'],
                [2 | 1 << 5 | 1 << 6 | 1 << 8 | 3 << 10 | 1 << 14 | 1 << 16 | 2 << 30 | 95 << 44 | 19 << 51, 20 << 44 | 2 << 51,
                    27 | 2 << 6 | 2 << 8 | 11 << 10 | 2 << 14,
                    ...$fill(28, [0 => 1, 2 => 1, 27 => 1]), 1, 1, 1, ...$fill(12, [0 => 1, 3 => 1, 11 => 1]), 1, 1, 1, 3, 115, 1]],
            'log file: tail window, dated offset page and traversal' => [AdminLogFileRequest::class,
                ['laravel.log|100||200', 'logs/laravel-2026-01-02.log||50|50', '../etc/passwd'],
                [1 << 12 | 1 << 16 | 2 << 18 | 2 << 20 | 11 << 23, 1 << 7 | 1 << 8 | 1 << 14 | 1 << 16 | 1 << 20 | 27 << 23 | 50 << 33,
                    4 | 4 << 3 | 2 << 8 | 1 << 18 | 13 << 23, 2, 0, 0, 0, 1, 0, 2, 0, 0, 0, 1, 1, 1, 1, 0, 250, 100, 2]],
            'log viewer: simple search and rejected filter' => [AdminLogViewerRequest::class,
                ['laravel.log|"foo bar" baz*|error,WARNING|daily|2', '||debug,foo|bad chan|x'],
                [1 | 1 << 2 | 2 << 4 | 3 << 7 | 1 << 11 | 24 << 15 | 13 << 27 | 3 << 35 | 14 << 43 | 2 << 53,
                    4 << 4 | 9 << 7 | 2 << 11 | 1 << 15 | 1 << 23 | 1 << 53,
                    0, 0, 1, 0, 1, 1, 0, 0, 1, 1, 0, 0, 0, ...$fill(10, [3 => 1, 9 => 1]), 13, 13, 2]],
            'horizon: admin gate, local only, malformed wait and guest' => [HorizonServiceProvider::class,
                ['production|1|Admin,User|a@b.com|10.0.0.1|30', 'local||admin|x@y.local|::1|500', 'staging|007|Admin|bad|fe80::1|abc', 'prod|x|||1.2.3|'],
                [2 | 1 << 3 | 1 << 5 | 2 << 7 | 1 << 11 | 2 << 14 | 1 << 18 | 1 << 21 | 1 << 22 | 2 << 23 | 30 << 25 | 1 << 45,
                    1 | 1 << 6 | 1 << 7 | 1 << 11 | 1 << 13 | 1 << 14 | 3 << 18 | 1 << 22 | 3 << 23 | 500 << 25,
                    3 | 1 << 3 | 1 << 5 | 1 << 7 | 2 << 11 | 3 << 14 | 4 << 18 | 1 << 21 | 1 << 22 | 2 << 23 | 3 << 45, 5 | 2 << 3 | 7 << 14 | 1 << 45,
                    1, 0, 2, 1, 0, 1, 1, 1, 0, 0, 0, 1, 1, 1, 0, 1, 1, 500, 2]],
            'http feed: accepted, rate limited, unsupported scheme and retry' => [HttpFeedSource::class,
                ['https://Example.com/feed?x=1|200||application/rss+xml; charset="UTF-8"|1000|gzip', 'http://u@h:8080/|429|Wed, 21 Oct 2015 07:28:00 GMT|||',
                    'ftp://x|200', 'http://example.com|503|120'],
                [1 << 3 | 1 << 4 | 1 << 6 | 1 << 11 | 1 << 14 | 2 << 17 | 1 << 20 | 11 << 44, 1 << 5 | 3 << 6 | 2 << 9 | 1 << 23 | 60 << 27 | 1 << 44,
                    3 | 1 << 6 | 6 << 23, 4 << 6 | 1 << 9 | 1 << 23 | 120 << 27 | 11 << 44,
                    1, 2, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1000, 120, 2]],
            'series empty batch' => [SeriesController::class, [], array_fill(0, 49, 0)],
            'http feed empty batch' => [HttpFeedSource::class, [], array_fill(0, 14, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['Show S01E02|1|2|name_asc|3', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['local|1|Admin|a@b.c|::1|5', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position.
        $edges = self::edgeCases();
        for ($slot = 0; $slot < 6; $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $fields = ['laravel.log', '200', '30', 'error', 'daily', '2'];
                $fields[$slot] = $edge;
                $rows[] = implode('|', $fields);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Show S01E02|1|2', 7 => 'laravel.log|1', 'a' => '', 'c' => 'http://h|200'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([591, 592, 593] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(5593));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAeEsSzZ0123456789|||,,.:;/@-_ \"'*?()[]\\+=GMTlocalAdminhttp\0\t\n\x0b\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(5594)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W593_BENCH_ROUNDS
        // interleaved paired rounds per helper and workload (default 301, minimum 60), 5 iterations per round).
        // Common = one batch of 320 well-formed items of the class's format; uncommon = 320 items mixing edge
        // cases, malformed, long, NUL, quote and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~5.5-5.9), common mean / p99, uncommon mean / p99:
        // - SeriesController:       8.50x / 6.72x, 5.90x / 4.11x
        // - AdminLogFileRequest:    6.54x / 4.96x, 5.33x / 4.15x
        // - AdminLogViewerRequest:  7.33x / 6.99x, 4.96x / 3.88x
        // - HorizonServiceProvider: 7.37x / 6.24x, 7.29x / 5.43x
        // - HttpFeedSource:         5.58x / 2.57x, 5.38x / 4.33x
        // Every helper clears every gate in all three runs and defaults to Rust. The lowest single figure,
        // HttpFeedSource's common p99 at 2.57x, came from one run whose other two measured 5.94x and 5.18x.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W593_BENCH=1 php artisan test --compact tests/Unit/RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W593_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W593_BENCH=1 to run the Wave 59.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5936));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W593_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(5937)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(5938)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW59RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry($items));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW59RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][3] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW59RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW59RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL, quote and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                SeriesController::class => $this->seriesItem($random, $uncommon),
                AdminLogFileRequest::class => $this->logFileItem($random, $uncommon),
                AdminLogViewerRequest::class => $this->logViewerItem($random, $uncommon),
                HorizonServiceProvider::class => $this->horizonItem($random, $uncommon),
                default => $this->feedItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||', $row."'",
                    str_repeat($row, 4), str_replace('|', ' | ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function seriesItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->pick($random, ['Breaking Bad', 'The Office', '24', 'Doctor Who', 'House of the Dragon', 'Star Trek Discovery', 'Fargo',
            'The Walking Dead', '9-1-1', 'Mr. Robot', 'The Expanse', 'Severance']);
        $query = $random->getInt(0, 2) === 0 ? $title.' S'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'E'.str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT) : $title;
        $season = $random->getInt(0, 2) === 0 ? '' : (string) $random->getInt(1, 30);
        $episode = $random->getInt(0, 2) === 0 ? '' : (string) $random->getInt(1, 40);
        $sort = $this->pick($random, ['', 'posted_desc', 'posted_asc', 'name_asc', 'size_desc', 'files_asc', 'stats_desc']);
        $page = $random->getInt(0, 1) === 0 ? '' : (string) $random->getInt(1, 40);
        if ($noisy) {
            $query = $this->pick($random, [$query, strtolower($query), '', '#'.$query, $query.' s1234e5', str_repeat($query.' ', 8), "caf\xc3\xa9 ".$query]);
            $season = $this->pick($random, [$season, '0', '00000', 'x', '-1', '1.5']);
            $episode = $this->pick($random, [$episode, '9999', '10000', 'E01', '']);
            $sort = $this->pick($random, [$sort, 'NAME_ASC', 'rating', 'posted']);
            $page = $this->pick($random, [$page, '0', '1000000', 'abc', '-3']);
        }

        return implode('|', [$query, $season, $episode, $sort, $page]);
    }

    private function logFileItem(Randomizer $random, bool $noisy): string
    {
        $file = $this->pick($random, ['laravel.log', 'laravel-2026-'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'-'
            .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT).'.log', 'horizon.log', 'scheduler.log.'.$random->getInt(1, 9),
            'indexer/worker-'.$random->getInt(1, 16).'.log', 'archive/laravel.log.gz', 'debug.txt', 'tmux/monitor.log']);
        $before = $random->getInt(0, 2) === 0 ? (string) $random->getInt(0, 5_000_000) : '';
        $offset = $random->getInt(0, 1) === 0 ? (string) ($random->getInt(0, 200) * 100) : '';
        $limit = $this->pick($random, ['', '50', '100', '200', '500']);
        if ($noisy) {
            $file = $this->pick($random, [$file, '../'.$file, '/var/log/'.$file, 'logs\\'.$file, 'a/../'.$file, '.env', $file.' copy', strtoupper($file),
                str_repeat('d/', 140).$file, '']);
            $before = $this->pick($random, [$before, '-1', 'x', str_repeat('9', 19), '1e9']);
            $offset = $this->pick($random, [$offset, '0', 'abc', str_repeat('9', 18)]);
            $limit = $this->pick($random, [$limit, '25', '050', '1000', 'all']);
        }

        return implode('|', [$file, $before, $offset, $limit]);
    }

    private function logViewerItem(Randomizer $random, bool $noisy): string
    {
        $file = $this->pick($random, ['', 'laravel.log', 'horizon.log', 'laravel-2026-10-26.log', 'indexer.log']);
        $query = $this->pick($random, ['', '', 'error', 'SQLSTATE', 'connection refused', '"Deadlock found" retry', 'timeout*', 'release id=42',
            'NNTP 430', 'Failed to fetch nzb', 'queue:work --tries=3']);
        $levels = $this->pick($random, ['', 'error', 'error,critical', 'warning,error,critical,alert,emergency', 'debug', 'info,notice']);
        $channel = $this->pick($random, ['', 'laravel', 'daily', 'horizon', 'scheduler', 'indexer', 'stack']);
        $page = $random->getInt(0, 1) === 0 ? '' : (string) $random->getInt(1, 60);
        if ($noisy) {
            $query = $this->pick($random, [$query, '(foo|bar)+', '^\\[x\\]$', '"unbalanced', str_repeat('term ', 60), "caf\xc3\xa9", '* ? * ?']);
            $levels = $this->pick($random, [$levels, 'ERROR,Warning', 'fatal', ',,error,,', 'trace,debug']);
            $channel = $this->pick($random, [$channel, 'Custom_Chan-1', 'bad chan', 'x/y', 'LARAVEL']);
            $page = $this->pick($random, [$page, '0', 'next', '9999999']);
            $file = $this->pick($random, [$file, str_repeat('f', 300)]);
        }

        return implode('|', [$file, $query, $levels, $channel, $page]);
    }

    private function horizonItem(Randomizer $random, bool $noisy): string
    {
        $env = $this->pick($random, ['production', 'production', 'local', 'staging', 'testing']);
        $uid = $random->getInt(0, 4) === 0 ? '' : (string) $random->getInt(1, 50_000);
        $roles = $this->pick($random, ['User', 'Admin', 'Admin,User', 'Moderator,User', 'Disabled', '', 'User,Admin,Friend']);
        $email = $this->pick($random, ['', 'admin@example.com', 'user'.$random->getInt(1, 999).'@mail.example.org', 'ops@nntmux.local', 'dev@box.lan']);
        $ip = $this->pick($random, ['127.0.0.1', '10.0.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254), '192.168.1.'.$random->getInt(1, 254),
            $random->getInt(1, 223).'.'.$random->getInt(0, 255).'.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254), '::1', '2001:db8::'.dechex($random->getInt(1, 65535)), '']);
        $wait = $random->getInt(0, 3) === 0 ? '' : (string) $random->getInt(0, 900);
        if ($noisy) {
            $env = $this->pick($random, [$env, 'LOCAL', 'prod', '', ' local']);
            $uid = $this->pick($random, [$uid, '0', '-1', 'abc', str_repeat('9', 20)]);
            $roles = $this->pick($random, [$roles, 'admin', 'ADMIN,user', str_repeat('Role,', 20), ',,,']);
            $email = $this->pick($random, [$email, 'bad', '@x.y', 'a@@b.c', 'a b@c.d', 'x@y.']);
            $ip = $this->pick($random, [$ip, '256.1.1.1', '1.2.3', 'fe80::1', 'FD00::1', 'g::1', '1::2::3', 'localhost']);
            $wait = $this->pick($random, [$wait, '-5', '1e3', '9999999999', 'slow']);
        }

        return implode('|', [$env, $uid, $roles, $email, $ip, $wait]);
    }

    private function feedItem(Randomizer $random, bool $noisy): string
    {
        $url = $this->pick($random, ['https://predb.net/api/v1/?page='.$random->getInt(1, 50), 'https://api.srrdb.com/v1/search/r:'.$random->getInt(1, 999),
            'http://predb.ovh/api/v1/?q=x', 'https://www.xrel.to/api/release/latest.json', 'https://feeds.example.org:8443/rss', 'https://predb.club/rss']);
        $status = (string) $this->pick($random, [200, 200, 200, 304, 429, 500, 502, 503, 404, 403]);
        $retry = $this->pick($random, ['', '', (string) $random->getInt(1, 3600), 'Wed, 21 Oct 2015 07:28:00 GMT']);
        $type = $this->pick($random, ['application/json', 'application/rss+xml; charset=utf-8', 'text/xml', 'application/xml;charset=UTF-8', 'text/html',
            'application/vnd.api+json', '']);
        $length = $this->pick($random, ['', (string) $random->getInt(1, 900_000), (string) $random->getInt(1_048_577, 9_000_000), '0']);
        $encoding = $this->pick($random, ['', 'gzip', 'br', 'deflate', 'identity']);
        if ($noisy) {
            $url = $this->pick($random, [$url, 'ftp://x/y', 'predb.net/api', 'https://', 'https://-bad-.com/', 'http://h:99999/', strtoupper($url),
                'https://u:p@host.example/x', 'http://'.str_repeat('a', 260).'.com/']);
            $status = $this->pick($random, [$status, '0', '99', '600', '2000', 'OK', '301']);
            $retry = $this->pick($random, [$retry, 'soon', '-1', '99999999999', 'wed, 21 oct 2015 07:28:00 gmt']);
            $type = $this->pick($random, [$type, 'TEXT/HTML; charset=iso-8859-1', "text/plain; charset='latin1'", 'x;charset=', 'image/png']);
            $length = $this->pick($random, [$length, 'abc', '-1', str_repeat('9', 16)]);
            $encoding = $this->pick($random, [$encoding, 'GZIP', 'compress', 'x-gzip', 'zstd']);
        }

        return implode('|', [$url, $status, $retry, $type, $length, $encoding]);
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 59.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W59_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w593', HttpFeedSource::W59_HTTP_FEED_SOURCE_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
