<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GamesProcessor;
use App\Services\Gdpr\GdprNotificationService;
use App\Services\GenreService;
use App\Services\IGDB\Client;
use App\Services\IGDB\Exceptions\IgdbHttpException;
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

final class RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        GamesProcessor::class => ['w67GamesProcessorPlanBatch', 'nntmux_w673_games_processor_batch', 'W67_GAMES_PROCESSOR_FFI_CDEF', 'W67_GAMES_PROCESSOR_TRAILER', 22],
        GdprNotificationService::class => ['w67GdprNotificationPlanBatch', 'nntmux_w673_gdpr_notification_batch', 'W67_GDPR_NOTIFICATION_FFI_CDEF', 'W67_GDPR_NOTIFICATION_TRAILER', 33],
        GenreService::class => ['w67GenreServicePlanBatch', 'nntmux_w673_genre_service_batch', 'W67_GENRE_SERVICE_FFI_CDEF', 'W67_GENRE_SERVICE_TRAILER', 20],
        Client::class => ['w67IgdbClientPlanBatch', 'nntmux_w673_igdb_client_batch', 'W67_IGDB_CLIENT_FFI_CDEF', 'W67_IGDB_CLIENT_TRAILER', 21],
        IgdbHttpException::class => ['w67IgdbHttpExceptionPlanBatch', 'nntmux_w673_igdb_http_exception_batch', 'W67_IGDB_HTTP_EXCEPTION_FFI_CDEF', 'W67_IGDB_HTTP_EXCEPTION_TRAILER', 27],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        GamesProcessor::class => true,
        GdprNotificationService::class => true,
        GenreService::class => true,
        Client::class => true,
        IgdbHttpException::class => true,
    ];

    /** One well-formed NUL-free item per class, in the order of TARGETS. */
    private const SAMPLES = [
        '4050|Cyberpunk.2077-CODEX (2020)|steam|0|0|',
        'erasure|submitted|someone@example.com|pending|0|0',
        'Sci-Fi|4000|0|',
        '/games/|3600|0|0|0|fields name,cover.url; where id = 1942; limit 10;',
        '429|30|0|0|{"message":"Too Many Requests"}',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", " \t\n\t ", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', ' | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", "\x0C|\x0C|\x0C|\x0C|\x0C", 'a|b|c|d|e|f|g|h|i', '.', '-', '_',
        '/', '//', ';', ',', '@', '0', '00', '01', '1', '2', '3', '4', '5', '9', '10', '99', '100', '299', '300', '301', '400', '401', '403', '404', '408',
        '429', '499', '500', '503', '599', '600', '999', '1000', '1001', '3000', '3540', '3600', '4000', '4049', '4050', '4999', '5000', '86400', '99999',
        '299999', '300000', '600000', '600001', '999999', '1000000', '999999999', '1000000000', '9999999999', '99999999999', '-1', '+1', ' 12 ', '1e3',
        '0x10', '0.5', 'steam', 'STEAM', ' Igdb ', 'greenmangaming', 'GreenManGaming', 'gog', 'export', 'Erasure', 'rectification', 'restriction',
        'submitted', 'EXPORT_READY', 'erasure_completed', 'rejected', 'retention_expiring', 'retention_expired', 'pending', 'processing', 'completed',
        'cancelled', 'unknown', 'a@b.co', 'A@B.CO', '@b.co', 'a@b.', 'a@.co', 'a@b@c.d', 'a b@c.d', 'a@bco', ' a@b.co ', 'console', 'music', 'game', 'pc',
        'PC', 'sci-fi', 'SciFi', 'rpg', 'R&B', 'hip hop', 'hip_hop', 'Rock & Roll', 'Action/Adventure', 'Drama, Comedy', "J\xc3\xa9zz", '  science   fiction ',
        'games', '/games/', 'Games', 'multiquery', 'release_dates', 'games/count', 'bogus_endpoint', 'bad!endpoint', 'fields name;', 'FIELDS name;',
        'fields name', 'where id = 1;', 'fields *; limit 500;', 'fields *; limit 501;', 'fields *; limit 1234567;', 'fields *; limit ;', 'limit 10;',
        'search "x"; fields name;', 'query games "x" { fields name; };', 'GMT', 'Wed, 21 Oct 2015 07:28:00 GMT', 'Wed 21 Oct 2015 07:28:00 GMT', 'soon',
        '{"message":"Too Many Requests"}', '[{"title":"Syntax Error"}]', '<html>Internal Server Error</html>', 'authorization failure', 'invalid client',
        'not found', 'Service Unavailable', 'timeout', "\x0C body \x0C", ...self::SAMPLES, '4050|Game|igdb|1|10|', '4050|Game|greenmangaming|1|99999|',
        '4050|Game||0|0|12', '2000|Movie|||', 'x|y|z|||', '4000|Half-Life 1998 REPACK|steam|2|3600|', '4050|Old 19999 Game 2000|gog|0|0|',
        'export|export_ready|A@B.CO|completed|0|1', 'export|export_ready|a@b.co|pending|0|0', 'erasure|retention_expired||completed|0|0',
        'x|submitted|a@b.co|pending|0|0', 'erasure|erasure_completed|a@b.co|completed|99999|5', 'restriction|retention_expiring|a@b.co|completed|0|11',
        'export|rejected|bad|rejected|0|0', '  science   fiction |game||', 'Rock|3000|1|5', 'Pop|3000|1|', 'Jazz|music||9', 'Bad|x||', 'Rpg|console|0|0',
        'games|3600|3600|0|0|fields name;', 'games|3600|0|4|200|fields name;', 'games|3600|0|0|0|fields name; limit 600;', 'Games!|3600|0|0|0|fields name;',
        'multiquery|||||query games "x" { fields name; };', 'games|120|0|0|0|fields name;', 'games|x|0|0|0|fields name;', 'games|3600|0|9|1000|fields a;',
        '400||0|0|Too many requests, slow down', '500||2|0|<html>Internal Server Error</html>', '401||0|0|authorization failure', '503||5|0|',
        'x||0|0|', '503|Wed, 21 Oct 2015 07:28:00 GMT|1|0|', '503|soon|1|599999|', '200||0|0|ok', '0||0|0|', '|99999|0|0|'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a ', 300), str_repeat('.', 600), str_repeat('|', 300),
            str_repeat('9', 40), str_repeat('9', 6), str_repeat('9', 7), str_repeat('9', 9), str_repeat('9', 10), str_repeat("\xc3\xa9", 200),
            str_repeat("\n", 300), str_repeat('f', 5000), str_repeat(';', 100), str_repeat('fields x; ', 50), str_repeat('limit ', 40).'7;',
            str_repeat('_', 300), str_repeat(' - ', 100), str_repeat('@', 50).'.co', 'a@'.str_repeat('b', 300).'.co', str_repeat('x', 4096).'|4000|0|',
            '4050|'.str_repeat('Game ', 60).'|steam|0|0|', '503||1|0|'.str_repeat('x', 300), str_repeat("\xff", 300)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW67RustFfi();
        }
        parent::tearDown();
    }

    public function test_w67_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi', 'usesW67RustFfi', 'withW67RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W67_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W67_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
            }
            foreach (['w67RustFfiState', 'w67Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w67RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w67RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW67RustFfi');
            $with = new ReflectionMethod($class, 'withW67RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW67RustFfi/withW67RustFfi signatures';
            }
            foreach (['enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w67_adds_no_properties(): void
    {
        // Property sets as of Wave 66 (static and instance), so Wave 67 cannot add state outside w67RustFfiState().
        $expected = [
            GamesProcessor::class => ['echooutput', 'ffi', 'gamesService', 'useRustFfi', 'w414Ffi', 'w414UseRustFfi'],
            GdprNotificationService::class => ['ffi', 'useRustFfi'],
            GenreService::class => ['ffi', 'useRustFfi', 'useW50RustFfi', 'w434Ffi', 'w434UseRustFfi', 'w50Ffi', 'w50Out', 'w50OutCap'],
            Client::class => ['ffi', 'useRustFfi', 'w47Ffi', 'w47UseRustFfi'],
            IgdbHttpException::class => ['code', 'ffi', 'file', 'line', 'message', 'responseBody', 'statusCode', 'useRustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
            $this->assertSame([], preg_grep('/w67/i', $actual), $class);
        }
    }

    public function test_w67_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W67_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class);
            $this->assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()));
            $this->assertFalse($class::withW67RustFfi(false, static fn (): bool => $class::usesW67RustFfi()));
            $this->assertTrue($class::withW67RustFfi(false, static fn (): bool => $class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi())));
            $this->assertFalse($class::withW67RustFfi(true, static fn (): bool => $class::withW67RustFfi(false, static fn (): bool => $class::usesW67RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class);
            try {
                $class::withW67RustFfi(! $class::usesW67RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class.' restores after an exception');
            $class::disableW67RustFfi();
            $this->assertFalse($class::usesW67RustFfi(), $class);
            $this->assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()));
            $this->assertFalse($class::usesW67RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW67RustFfi();
            $this->assertTrue($class::usesW67RustFfi(), $class);
            $class::resetW67RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW67RustFfi();
            $class::withW67RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW67RustFfi(), $class.' vs '.$other);
                }
            });
            $class::disableW67RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? false : self::DEFAULTS[$other], $other::usesW67RustFfi(), $class.' disable vs '.$other);
            }
            $class::resetW67RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW67RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW67RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW67RustFfi();
            $this->assertSame($php, $class::withW67RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW67RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w67_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertFalse(GamesProcessor::usesRustFfi());
        $this->assertTrue(GamesProcessor::usesW414RustFfi());
        $this->assertTrue(GamesProcessor::usesW53RustFfi());
        $this->assertFalse(GdprNotificationService::usesRustFfi());
        $this->assertTrue(GdprNotificationService::usesW54RustFfi());
        $this->assertFalse(GenreService::usesRustFfi());
        $this->assertFalse(GenreService::usesW434RustFfi());
        $this->assertTrue(GenreService::usesW50RustFfi());
        $this->assertFalse(Client::usesRustFfi());
        $this->assertFalse(Client::usesW47RequestRustFfi());
        $this->assertTrue(Client::usesW54RustFfi());
        $this->assertFalse(IgdbHttpException::usesRustFfi());
        $this->assertTrue(IgdbHttpException::usesW53RustFfi());
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW67RustFfi($enabled, function (): void {
                    $this->assertTrue(GamesProcessor::dispatchPlanPhp(1, true)['dispatch']);
                    $this->assertSame(GamesProcessor::w53GameTitleFlagBatchPhp(['Game v1.2 REPACK-GRP', '']), GamesProcessor::w53GameTitleFlagBatch(['Game v1.2 REPACK-GRP', '']));
                    $this->assertTrue(GdprNotificationService::shouldSendAdminCopyPhp('erasure', 'submitted'));
                    $this->assertSame('a@b.co', GdprNotificationService::normalizeRecipientEmailPhp(' a@b.co '));
                    $this->assertSame('genres_'.md5('_0'), GenreService::buildGenresCacheKeyPhp());
                    $this->assertSame([1 => 'action'], GenreService::normalizeGenresMapPhp([['id' => 1, 'title' => 'Action']]));
                    $this->assertSame('igdb:query:'.md5('games|fields name;'), Client::buildQueryCacheKeyPhp('games', 'fields name;'));
                    $this->assertSame(3540, Client::computeTokenTtlPhp(3600));
                    $this->assertSame('rate_limited', IgdbHttpException::classifyHttpFailurePhp(429, 'x')['category']);
                    $exception = new IgdbHttpException('boom', 503, 'body');
                    $this->assertSame([503, 'body'], [$exception->getStatusCode(), $exception->getResponseBody()]);
                });
            }
        }
    }

    public function test_w67_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('services_w673', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = self::SAMPLES;
            $blob = implode("\0", $items);
            $words = $trailer + \count($items);
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob with a length and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // Output overlapping the length column declines.
            $shared = $ffi->new('int64_t[64]');
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 64), $class.' overlap');
        }
    }

    public function test_w67_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, "J\xc3\xa9zz|music||", "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w67Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", '4050|Ga\0me|steam|0|0|', "erasure|submitted|a\0@b.co|pending|0|0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  array<int, int>  $expected  Expected words by position.
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $words = $class::{self::TARGETS[$class][0].'Php'}($items);
        $this->assertSame($expected, array_intersect_key($words, $expected));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, array<int, int>}>
     */
    public static function knownCases(): array
    {
        return [
            'games: lookup, waiting, exhausted, matched, skipped and malformed' => [GamesProcessor::class,
                ['4050|Cyberpunk.2077-CODEX (2020)|steam|0|0|', '4050|Game|igdb|1|10|', '4050|Game|greenmangaming|1|99999|', '4050|Game||0|0|12', '2000|Movie|||', 'x|y|z|||'],
                [0 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 11 | 1 << 14 | 2 << 16 | 5 << 20 | 178 << 23 | 27 << 31,
                    1 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 2 << 11 | 3 << 14 | 4 << 20 | 4 << 31 | 590 << 39,
                    2 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 3 << 11 | 3 << 20 | 4 << 31, 3 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 14 | 1 << 20 | 4 << 31,
                    4 => 3 | 1 << 3 | 1 << 14 | 5 << 31, 5 => 4 | 2 << 3 | 4 << 11 | 6 << 20 | 1 << 31,
                    6 => 1, 7 => 1, 8 => 0, 9 => 1, 10 => 1, 11 => 1, 12 => 1, 13 => 0, 14 => 4, 15 => 0, 16 => 1, 17 => 1, 18 => 2, 19 => 3, 20 => 0, 21 => 1,
                    22 => 1, 23 => 1, 24 => 590, 25 => 2, 26 => 1, 27 => 4]],
            'gdpr: admin copy, waiting, mismatch, admin-only retention and unknown type' => [GdprNotificationService::class,
                ['erasure|submitted|a@b.co|pending|0|0', 'export|export_ready|A@B.CO|completed|0|1', 'export|export_ready|a@b.co|pending|0|0',
                    'erasure|retention_expired||completed|0|0', 'x|submitted|a@b.co|pending|0|0'],
                [0 => 2 | 1 << 3 | 1 << 6 | 1 << 9 | 1 << 11 | 1 << 13 | 2 << 15 | 1 << 17 | 1 << 18 | 5 << 19 | 6 << 54,
                    1 => 1 | 2 << 3 | 3 << 6 | 1 << 9 | 1 << 11 | 1 << 13 | 1 << 15 | 1 << 17 | 4 << 19 | 1 << 22 | 300 << 30 | 6 << 54,
                    2 => 1 | 2 << 3 | 1 << 6 | 1 << 9 | 1 << 11 | 1 << 13 | 1 << 15 | 1 << 18 | 1 << 19 | 6 << 54,
                    3 => 2 | 6 << 3 | 3 << 6 | 1 << 11 | 1 << 13 | 3 << 15 | 1 << 17 | 1 << 18 | 5 << 19,
                    4 => 5 | 1 << 3 | 1 << 6 | 1 << 9 | 1 << 11 | 1 << 13 | 1 << 15 | 1 << 17 | 1 << 18 | 6 << 19 | 6 << 54,
                    5 => 0, 6 => 1, 7 => 0, 8 => 0, 9 => 1, 10 => 2, 11 => 1, 12 => 0, 13 => 3, 14 => 1, 15 => 1, 16 => 0, 17 => 2, 18 => 2, 19 => 0, 20 => 0,
                    21 => 1, 22 => 0, 23 => 2, 24 => 2, 25 => 0, 26 => 0, 27 => 0, 28 => 1, 29 => 0, 30 => 1, 31 => 4, 32 => 0, 33 => 1, 34 => 2, 35 => 300,
                    36 => 1, 37 => 1]],
            'genre: alias insert, duplicate, disable, drop, update and invalid type' => [GenreService::class,
                ['Sci-Fi|4000|0|', '  science   fiction |game||', 'Rock|3000|1|5', 'Pop|3000|1|', 'Jazz|music||9', 'Bad|x||'],
                [0 => 3 | 1 << 3 | 1 << 5 | 1 << 9 | 1 << 12 | 5 << 13 | 15 << 16 | 2 << 24 | 6 << 52,
                    1 => 3 | 2 << 3 | 1 << 12 | 1 << 13 | 15 << 16 | 2 << 24 | 1 << 28 | 17 << 52,
                    2 => 2 | 1 << 3 | 2 << 5 | 1 << 7 | 1 << 12 | 2 << 13 | 4 << 16 | 1 << 24 | 4 << 52,
                    3 => 2 | 1 << 3 | 2 << 5 | 1 << 12 | 3 << 13 | 3 << 16 | 1 << 24 | 3 << 52,
                    4 => 2 | 2 << 3 | 1 << 7 | 1 << 12 | 4 << 13 | 4 << 16 | 1 << 24 | 4 << 52,
                    5 => 5 | 2 << 3 | 1 << 12 | 6 << 13 | 3 << 16 | 1 << 24 | 3 << 52,
                    6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 1, 13 => 0, 14 => 0, 15 => 3, 16 => 2, 17 => 0, 18 => 1,
                    19 => 1, 20 => 0, 21 => 0, 22 => 6, 23 => 4, 24 => 2, 25 => 1]],
            'igdb client: send, refresh, throttled' => [Client::class,
                ['/games/|3600|0|0|0|fields name; limit 10;', 'games|3600|3600|0|0|fields name;', 'games|3600|0|4|200|fields name;'],
                [0 => 11 | 1 << 6 | 5 << 9 | 1 << 16 | 1 << 18 | 1 << 20 | 1 << 22 | 5 << 27 | 10 << 40 | 2 << 50 | 22 << 56,
                    1 => 11 | 1 << 6 | 1 << 9 | 1 << 16 | 1 << 18 | 1 << 20 | 1 << 22 | 1 << 24 | 3 << 27 | 1 << 50 | 12 << 56,
                    2 => 11 | 1 << 6 | 1 << 9 | 1 << 16 | 1 << 18 | 1 << 20 | 1 << 22 | 1 << 26 | 4 << 27 | 800 << 30 | 1 << 50 | 12 << 56,
                    3 => 0, 4 => 0, 5 => 0, 6 => 1, 7 => 1, 8 => 1, 9 => 0, 10 => 3, 11 => 0, 12 => 0, 13 => 3, 14 => 0, 15 => 0, 16 => 0, 17 => 1, 18 => 0,
                    19 => 1, 20 => 800, 21 => 10620, 22 => 1, 23 => 1]],
            'igdb client: invalid endpoint and query states' => [Client::class,
                ['games|3600|0|0|0|fields name; limit 600;', 'Games!|3600|0|0|0|fields name;', 'games|3600|0|0|0|where id = 1;', 'games|3600|0|0|0|fields x',
                    'multiquery|||||query games "x" { fields name; };', 'games|x|0|0|0|fields name;'],
                [6 => 0, 7 => 1, 8 => 3, 9 => 0, 10 => 0, 11 => 1, 12 => 1, 13 => 5, 14 => 0, 15 => 0, 16 => 3, 17 => 1, 18 => 1, 19 => 1]],
            'igdb exception: header retry, reclassified backoff and fail fast' => [IgdbHttpException::class,
                ['429|30|0|0|{"message":"Too Many Requests"}', '400||0|0|Too many requests, slow down', '401||0|0|authorization failure'],
                [0 => 4 | 1 << 3 | 1 << 5 | 1 << 6 | 1 << 8 | 3 << 10 | 1 << 15 | 1 << 17 | 2 << 19 | 31 << 22 | 429 << 31 | 300 << 41,
                    1 => 4 | 1 << 3 | 1 << 5 | 3 << 8 | 3 << 10 | 1 << 14 | 1 << 15 | 1 << 17 | 3 << 19 | 28 << 22 | 400 << 31 | 10 << 41,
                    2 => 1 | 1 << 3 | 3 << 8 | 1 << 10 | 1 << 15 | 1 << 17 | 21 << 22 | 401 << 31,
                    3 => 1, 4 => 0, 5 => 1, 6 => 1, 7 => 0, 8 => 0, 9 => 1, 10 => 0, 11 => 0, 12 => 2, 13 => 0, 14 => 0, 15 => 0, 16 => 2, 17 => 1, 18 => 0,
                    19 => 0, 20 => 0, 21 => 1, 22 => 0, 23 => 2, 24 => 3, 25 => 1, 26 => 31000, 27 => 30000, 28 => 1, 29 => 3]],
            'igdb exception: exhausted, date header and malformed' => [IgdbHttpException::class,
                ['503||5|0|', '503|Wed, 21 Oct 2015 07:28:00 GMT|1|0|', '503|soon|1|599999|', 'x||0|0|'],
                [4 => 0, 5 => 2, 6 => 1, 7 => 0, 8 => 1]],
            'games empty batch' => [GamesProcessor::class, [], array_fill(0, 22, 0)],
            'gdpr empty batch' => [GdprNotificationService::class, [], array_fill(0, 33, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, [...self::SAMPLES, ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $label = bin2hex(substr($item, 0, 64));
            $this->assertParity($class, [$item, $item], 'edge item '.$label);
            $this->assertParity($class, [self::SAMPLES[0], $item], 'edge item vs work item '.$label);
            $this->assertParity($class, [$item], 'single edge item '.$label);
        }
        // Edge values in every pipe field of every class's sample.
        $edges = self::edgeCases();
        foreach (self::SAMPLES as $sample) {
            $fields = explode('|', $sample);
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot.' of '.$sample);
            }
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => self::SAMPLES[1], 7 => self::SAMPLES[3], 'a' => '', 'c' => self::SAMPLES[4]];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][4] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([671, 672, 673] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6733));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAgGmMtT0123456789||||;;  @.,_-/<[{\"\t\n\0\x0C\x01\x7f\xff\xc3\xa9limitfieldsGMTsteamigdbcodexrpgsci", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6734)), true), 0, 80));
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
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining, the FFI
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W673_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5 NTS, release dylib, Apple
        // Silicon, shared host at load average ~8.6-10.4), common mean / p99, uncommon mean / p99:
        // - GamesProcessor:          3.85x / 3.49x, 4.67x / 3.51x
        // - GdprNotificationService: 6.14x / 4.50x, 6.26x / 4.69x
        // - GenreService:            6.94x / 5.32x, 4.89x / 3.45x
        // - IGDB Client:             4.09x / 3.60x, 4.82x / 3.75x
        // - IgdbHttpException:       6.28x / 5.21x, 4.96x / 4.22x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 67.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6736));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W673_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6737)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6738)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW67RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW67RustFfi() : $class::disableW67RustFfi();
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
        fwrite(STDERR, "\nRustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW67RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][4] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW67RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW67RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL and non-ASCII bytes.
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
                GamesProcessor::class => $this->gameItem($random, $uncommon),
                GdprNotificationService::class => $this->gdprItem($random, $uncommon),
                GenreService::class => $this->genreItem($random, $uncommon),
                Client::class => $this->clientItem($random, $uncommon),
                default => $this->exceptionItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n", str_replace('-', '', $row),
                    str_replace(' ', '_', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function gameItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->pick($random, ['Cyberpunk 2077', 'The.Witcher.3.Wild.Hunt.GOTY', 'Half-Life Alyx', 'Elden Ring', 'Baldurs.Gate.3', "Caf\xc3\xa9 Tycoon", 'Stardew Valley'])
            .$this->pick($random, ['', '-CODEX', '-PLAZA', '-SKIDROW', ' [FitGirl Repack]', '.v1.2.3-GOG', '-EMPRESS', '-RAZOR1911'])
            .$this->pick($random, ['', ' ('.$random->getInt(1990, 2025).')', ' '.$random->getInt(1990, 2025)]);

        return implode('|', [$this->pick($random, [4050, 4050, 4050, 4010, 4020, 4000, 2000, '']), $title,
            $this->pick($random, ['', '', 'steam', 'Steam', 'igdb', 'greenmangaming', $noisy ? 'gamespot' : 'steam']), $this->pick($random, [0, 0, 0, 1, 1, 2, 3]),
            $random->getInt(0, 200_000), $this->pick($random, ['', '', '', 0, $random->getInt(1, 99_999)])]);
    }

    private function gdprItem(Randomizer $random, bool $noisy): string
    {
        $email = $this->pick($random, ['user'.$random->getInt(1, 9999).'@example.com', 'Someone.Else@Mail.EXAMPLE.org', 'first.last+tag@sub.domain.co.uk', '',
            $noisy ? 'not-an-email' : 'admin@example.net', $noisy ? 'a b@c.d' : 'x@y.io']);

        return implode('|', [$this->pick($random, ['export', 'erasure', 'rectification', 'restriction', 'Export', $noisy ? 'deletion' : 'erasure']),
            $this->pick($random, ['submitted', 'export_ready', 'erasure_completed', 'rejected', 'retention_expiring', 'retention_expired', $noisy ? 'bogus' : 'submitted']),
            $email, $this->pick($random, ['pending', 'processing', 'completed', 'rejected', 'cancelled', 'Completed']), $random->getInt(0, 200_000),
            $this->pick($random, [0, 0, 0, 1, 2, 3, 4, 5, 6])]);
    }

    private function genreItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['Action', 'Adventure', 'Role-Playing', 'RPG', 'Sci-Fi', 'Science Fiction', 'Hip Hop', 'R&B', 'Rock & Roll',
            'Action/Adventure', "Jazz \xc3\xa9", ' drama ', 'Comedy,Drama', 'Puzzle_Platformer', 'Strategy', 'Shooter', 'Simulation', 'Indie']),
            $this->pick($random, [1000, 3000, 4000, 'console', 'music', 'game', 'pc', '', $noisy ? 'movies' : '4000', 2000]),
            $this->pick($random, ['', '0', '0', '1', $noisy ? '2' : '0']), $this->pick($random, ['', '', 0, $random->getInt(1, 9999)])]);
    }

    private function clientItem(Randomizer $random, bool $noisy): string
    {
        $query = $this->pick($random, ['fields name,cover.url,first_release_date; where id = '.$random->getInt(1, 300_000).';',
            'search "'.$this->pick($random, ['Witcher', 'Halo', "Pok\xc3\xa9mon"]).'"; fields name; limit '.$random->getInt(1, 600).';',
            'fields *; where platforms = (6); sort rating desc; limit 50; offset '.$random->getInt(0, 500).';', 'query games "x" { fields name; limit 5; };',
            'fields name; exclude summary;', $noisy ? 'fields name' : 'fields id;', 'where id = 5;']);

        return implode('|', [$this->pick($random, ['games', '/games', 'covers', 'platforms', 'multiquery', 'search', 'genres', 'games/count', $noisy ? 'Games!' : 'games']),
            $this->pick($random, ['', 3600, 5_184_000, 120, $random->getInt(0, 9_999_999)]), $random->getInt(0, 6_000_000), $random->getInt(0, 6),
            $random->getInt(0, 1500), $query]);
    }

    private function exceptionItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, [0, 400, 401, 403, 404, 408, 429, 429, 500, 502, 503, 504, 200, '']),
            $this->pick($random, ['', '', $random->getInt(1, 120), 'Wed, 21 Oct 2015 07:28:00 GMT', $noisy ? 'soon' : '']), $random->getInt(0, 7),
            $random->getInt(0, 700_000), $this->pick($random, ['', '{"message":"Too Many Requests"}', '[{"title":"Syntax Error","status":400}]',
                '<html><body>502 Bad Gateway</body></html>', 'Service Unavailable', 'authorization failure', 'Request timeout after 30s', 'Not Found'])]);
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
     * Calls a Wave 67.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w67RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('services_w673', IgdbHttpException::W67_IGDB_HTTP_EXCEPTION_FFI_CDEF) === null) {
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
