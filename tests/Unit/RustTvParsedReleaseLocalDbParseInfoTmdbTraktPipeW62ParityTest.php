<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
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

final class RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant and the trailer word count. */
    private const TARGETS = [
        ParsedTvRelease::class => ['w62ReleaseSummaryBatch', 'nntmux_w623_parsed_tv_release_batch', 'W62_PARSED_TV_RELEASE_FFI_CDEF', 20],
        LocalDbPipe::class => ['w62LocalPlanBatch', 'nntmux_w623_local_db_pipe_batch', 'W62_LOCAL_DB_PIPE_FFI_CDEF', 12],
        ParseInfoPipe::class => ['w62ParsePlanBatch', 'nntmux_w623_parse_info_pipe_batch', 'W62_PARSE_INFO_PIPE_FFI_CDEF', 14],
        TmdbPipe::class => ['w62TmdbPlanBatch', 'nntmux_w623_tmdb_pipe_batch', 'W62_TMDB_PIPE_FFI_CDEF', 17],
        TraktPipe::class => ['w62TraktPlanBatch', 'nntmux_w623_trakt_pipe_batch', 'W62_TRAKT_PIPE_FFI_CDEF', 12],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        ParsedTvRelease::class => true,
        LocalDbPipe::class => true,
        ParseInfoPipe::class => true,
        TmdbPipe::class => true,
        TraktPipe::class => true,
    ];

    /** Trailer constants each class declares for its own batch. */
    private const TRAILER_CONSTANTS = [
        ParsedTvRelease::class => 'W62_PARSED_TV_RELEASE_TRAILER',
        LocalDbPipe::class => 'W62_LOCAL_DB_PIPE_TRAILER',
        ParseInfoPipe::class => 'W62_PARSE_INFO_PIPE_TRAILER',
        TmdbPipe::class => 'W62_TMDB_PIPE_TRAILER',
        TraktPipe::class => 'W62_TRAKT_PIPE_TRAILER',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\r\n", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', '||||',
        '|||||', '||||||', '|||||||', '||||||||', '|||||||||', ' | | | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h|i|j',
        '.', '..', '-', '--', '_', '(', ')', '()', '(2019)', ' (2019)', 'Show (2019)', 'Show (1899)', 'Show (2100)', 'Show(2019)', 'Show (20199)',
        'Show (19x9)', 'The', 'the', 'The The', 'THE OFFICE', 'the.office.us', 'Office', 'a', 'ab', 'AB', 'us', 'US', 'uk', 'xx', 'U1', 'USA', 'gb',
        '0', '00', '01', '1', '2', '9', '10', '99', '100', '999', '1000', '9999', '10000', '-1', '+1', '+', '-', '+0', '-0', '1e3', '0x10', '0.5',
        '123456789012345678', '1234567890123456789', '4294967295', '4294967296', '9999999999', '10000000000', 'all', 'ALL', 'All', 'al', 'alll',
        '1899', '1900', '1999', '2000', '2019', '2099', '2100', '20190', '19', '20', '2024-03-09', '2024.03.09', '2024/03/09', '2024-03/09',
        '2024-13-01', '2024-00-10', '2024-01-32', '2024-01-00', '1899-12-31', '2100-01-01', '24-03-09', '2024-3-9', '2024-03-09 ', 'tt0944947',
        'TT0944947', 'tt944947', 'tt12345678901', 'tt0000000', 'tt', 'tt1234567x', '81189', '0081189', 'the-office', 'The-Office', 'the--office',
        '-the-office', 'the-office-', 'the_office', 'the office', 'true', 'TRUE', 'yes', 'Yes', 'on', 'ON', 'false', 'no', 'off', 'maybe', 'down',
        'S01E02', 's01e02', 'S1E2', 'S01E02E03', 'S01E02-E03', 'S01E02E', 'S01', 'S1234', 'S01 E02', '1x02', '01x002', '123x02', '1x2', 'x02',
        'Part 1', 'Part.2', 'Part0', 'part12', 'pt3', 'Pt 4', 'Part 123', 'Ep 5', 'Episode 12', 'ep0', 'EP01', 'Episode', '2024 03 09',
        '2024.13.09', '2024 03 32', '1999 1 1', 'Show.Name.S01E02.720p.HDTV.x264-GRP', 'Show.Name.2019.S01E02-GRP', 'Daily.Show.2024.03.09.720p-GRP',
        'Show.Name.S02.Complete-GRP', 'Show.Name.3x07.HDTV', 'Doc.Part.2.of.3-GRP', 'Anime.Ep.12.1080p', 'Show.Name.S01E01E02E03',
        'Show|Show (2019)|1|2|2024-01-02|us|2019', 'The Office|2005|US|Office|2005|us|12|7|0', 'Show|81189|tt0944947|1396|2008|US|',
        'The Office (US)||||tt0386676|2|5|', 'x|the-office|||||', 'Show||1|all|||'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 512), str_repeat('a', 513), str_repeat('a ', 300),
            str_repeat('.', 600), str_repeat('|', 300), str_repeat('9', 40), str_repeat('x', 100), str_repeat('x', 101), str_repeat('a-', 50).'b',
            str_repeat("\xc3\xa9", 200), str_repeat('S01E01.', 80), str_repeat('the ', 40), str_repeat('e01', 30), 's01'.str_repeat('e01', 30),
            str_repeat('2019 ', 60), ' '.str_repeat('Show ', 101).'(2019)', str_repeat('(2019)', 50), str_repeat('tt0944947|', 20)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w62_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW62RustFfi', 'withW62RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W62_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W62_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            if ($reflection->getConstant(self::TRAILER_CONSTANTS[$class]) !== $trailer) {
                $missing[] = $class.'::'.self::TRAILER_CONSTANTS[$class];
            }
            foreach (['w62RustFfiState', 'w62Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w62RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w62RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW62RustFfi');
            $with = new ReflectionMethod($class, 'withW62RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW62RustFfi/withW62RustFfi signatures';
            }
        }
        // Each class declares its own FFI declaration constant name and export.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $symbols = array_column(array_values(self::TARGETS), 1);
        $this->assertSame($symbols, array_values(array_unique($symbols)));
        $this->assertSame([], $missing);
    }

    public function test_w62_adds_no_properties(): void
    {
        // Property sets as of Wave 61 (static, instance and inherited), so Wave 62 cannot add state outside w62RustFfiState().
        $expected = [
            ParsedTvRelease::class => ['airdate', 'cleanName', 'country', 'episode', 'name', 'season', 'seasonPack', 'tvFfi', 'useRustFfi',
                'w47RustFfiHandle', 'w47RustFfiOverride', 'year'],
            LocalDbPipe::class => ['echoOutput', 'ffi', 'localDb', 'priority', 'useRustFfi', 'w48RustFfiHandle', 'w48RustFfiOverride'],
            ParseInfoPipe::class => ['echoOutput', 'ffi', 'parser', 'priority', 'useRustFfi', 'w48RustFfiHandle', 'w48RustFfiOverride'],
            TmdbPipe::class => ['echoOutput', 'ffi', 'priority', 'useRustFfi', 'w48RustFfiHandle', 'w48RustFfiOverride'],
            TraktPipe::class => ['echoOutput', 'ffi', 'priority', 'useRustFfi', 'useW49RustFfi', 'w49Ffi', 'w49Out', 'w49OutCap'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
        }
        // ParsedTvRelease keeps its readonly promoted value properties.
        foreach (['name', 'cleanName', 'country', 'season', 'episode', 'seasonPack', 'airdate', 'year'] as $property) {
            $this->assertTrue((new ReflectionClass(ParsedTvRelease::class))->getProperty($property)->isReadOnly(), $property);
        }
    }

    public function test_w62_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), $class);
            $this->assertTrue($class::withW62RustFfi(true, static fn (): bool => $class::usesW62RustFfi()));
            $this->assertFalse($class::withW62RustFfi(false, static fn (): bool => $class::usesW62RustFfi()));
            $this->assertTrue($class::withW62RustFfi(false, static fn (): bool => $class::withW62RustFfi(true, static fn (): bool => $class::usesW62RustFfi())));
            $this->assertFalse($class::withW62RustFfi(true, static fn (): bool => $class::withW62RustFfi(false, static fn (): bool => $class::usesW62RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), $class);
            try {
                $class::withW62RustFfi(! $class::usesW62RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), $class.' restores after an exception');
        }
        // Toggles are independent between classes, including the sibling pipes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW62RustFfi();
            $class::withW62RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW62RustFfi(), $class.' vs '.$other);
                }
            });
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['Show|Show (2019)|1|2|2024-01-02|us|2019', 'The Office|2005|US|Office|2005|us|12|7|0', 'Show.Name.S01E02-E03.720p',
                'Show|81189|tt0944947|1396|2008|US|', 'The Office (US)||||tt0386676|2|5|', ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW62RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW62RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW62RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w62_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertNull(ParsedTvRelease::$useRustFfi);
        $this->assertSame(ParsedTvRelease::W47_PARSED_RELEASE_RUST_FFI_DEFAULT, ParsedTvRelease::usesW47ParsedReleaseRustFfi());
        $this->assertFalse(LocalDbPipe::usesRustFfi());
        $this->assertSame(LocalDbPipe::W48_LOCAL_DB_RUST_FFI_DEFAULT, LocalDbPipe::usesW48LocalDbRustFfi());
        $this->assertFalse(ParseInfoPipe::usesRustFfi());
        $this->assertSame(ParseInfoPipe::W48_PARSE_INFO_RUST_FFI_DEFAULT, ParseInfoPipe::usesW48ParseInfoRustFfi());
        $this->assertFalse(TmdbPipe::usesRustFfi());
        $this->assertSame(TmdbPipe::W48_TMDB_RUST_FFI_DEFAULT, TmdbPipe::usesW48TmdbRustFfi());
        $this->assertFalse(TraktPipe::usesRustFfi());
        $this->assertTrue(TraktPipe::usesW49RustFfi());
        $this->assertSame(LocalDbPipe::W56_RUST_FFI_DEFAULT, LocalDbPipe::usesW56RustFfi());
        // Existing helpers behave as before, whatever the Wave 62 toggles.
        foreach ([true, false] as $enabled) {
            ParsedTvRelease::withW62RustFfi($enabled, function (): void {
                $parsed = ParsedTvRelease::fromParseInfoPhp(['name' => 'Show', 'cleanname' => 'Show (2019)', 'season' => '1', 'episode' => '2', 'country' => 'US']);
                $this->assertNotNull($parsed);
                $this->assertSame('Show', $parsed->titleWithoutYearPhp());
                $this->assertSame('US', $parsed->countryCodePhp());
                $this->assertTrue($parsed->hasEpisodeNumbers());
                $this->assertNull(ParsedTvRelease::fromParseInfoPhp(false));
                $this->assertCount(ParsedTvRelease::W47_FIELDS_PER_NAME, ParsedTvRelease::w47NormalizeBatchPhp(['Show.S01E02']));
                $pack = ParsedTvRelease::fromParseInfoPhp(['name' => 'Show', 'season' => 2, 'episode' => 'all']);
                $this->assertNotNull($pack);
                $this->assertTrue($pack->seasonPack);
                $this->assertFalse(LocalDbPipe::shouldLookupEpisodePhp($pack));
                $this->assertTrue(LocalDbPipe::shouldLookupEpisodePhp($parsed));
            });
            LocalDbPipe::withW62RustFfi($enabled, function (): void {
                $this->assertTrue(LocalDbPipe::hasValidSiteIdPhp(' 12 '));
                $this->assertFalse(LocalDbPipe::hasValidSiteIdPhp('-3'));
                $this->assertSame([0, 1, 2, 3], [LocalDbPipe::classifyOutcomePhp(0, false, 0), LocalDbPipe::classifyOutcomePhp(5, true, 0),
                    LocalDbPipe::classifyOutcomePhp(5, false, 9), LocalDbPipe::classifyOutcomePhp(5, false, 0)]);
                $this->assertSame('Local DB', (new LocalDbPipe)->getName());
                $this->assertCount(5 + 5, LocalDbPipe::w48CandidateBatchPhp([['title' => 'Show', 'candidate_title' => 'Show']]));
            });
            ParseInfoPipe::withW62RustFfi($enabled, function (): void {
                $this->assertTrue(ParseInfoPipe::isValidParseInfoPhp(['name' => 'x', 'season' => 1, 'episode' => 2]));
                $this->assertFalse(ParseInfoPipe::isValidParseInfoPhp(false));
                $this->assertSame('  ✗ Parse failed: abc', ParseInfoPipe::formatParseFailedMessagePhp('abcdef', 3));
                $this->assertSame('ParseInfo', (new ParseInfoPipe)->getName());
                $this->assertCount(8 + 5, ParseInfoPipe::w48TokenBatchPhp(['Show.S01E02']));
            });
            TmdbPipe::withW62RustFfi($enabled, function (): void {
                $this->assertSame('TMDB', (new TmdbPipe)->getName());
                $this->assertTrue(TmdbPipe::evaluateAvailabilityPhp(null));
                $this->assertFalse(TmdbPipe::evaluateAvailabilityPhp('no key'));
                $this->assertSame(TmdbPipe::resolvePipeSpecPhp(), TmdbPipe::resolvePipeSpecPhp());
            });
            TraktPipe::withW62RustFfi($enabled, function (): void {
                $this->assertSame('Trakt', (new TraktPipe)->getName());
                $this->assertTrue(TraktPipe::evaluateAvailabilityPhp(null));
                $this->assertFalse(TraktPipe::evaluateAvailabilityPhp('no key'));
                $this->assertSame(TraktPipe::w49IdsBatchPhp([]), TraktPipe::w49IdsBatch([]));
            });
        }
    }

    public function test_w62_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('tv_w623', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = ['Show|Show (2019)|1|2|2024-01-02|us|2019', 'Show.Name.S01E02.720p'];
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

    public function test_w62_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = ['Show|Show (2019)|1|2|2024-01-02|us|2019', 'The Office|2005|US|Office|2005|us|12|7|0', 'Daily.Show.2024.03.09.720p-GRP',
                'Show|81189|tt0944947|1396|2008|US|', 'x|the-office|||||', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w62Invoke');
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
            'parsed release: numbered episode with year suffix, season pack from the name' => [ParsedTvRelease::class,
                ['Show|Show (2019)|1|2|2024-01-02|us|2019', 'Show||1|all|||', 'Daily|Daily|||2024.03.09|zz|', '|x|1|2|||'],
                [0 => 1 | 1 << 3 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 12 | 2 << 14 | 1 << 17 | 2 << 27 | 119 << 41 | 1 << 49 | 4 << 54,
                    1 => 1 | 1 << 2 | 1 << 4 | 3 << 6 | 1 << 14 | 1 << 17 | 4 << 54, 2 => 1 | 1 << 8 | 2 << 10 | 3 << 14 | 5 << 54,
                    3 => 1 << 4 | 1 << 6 | 1 << 17 | 2 << 27 | 1 << 54,
                    4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 0, 9 => 0, 10 => 1, 11 => 3, 17 => 2, 18 => 4 + 4 + 5 + 1, 19 => 1, 20 => 2,
                    21 => 20240102, 22 => 20240309, 23 => 1]],
            'local db: exact match and partial season pack' => [LocalDbPipe::class,
                ['The Office|2005|US|Office|2005|us|12|7|0', 'a b|||a c|||0||yes', 'Show|2010|US|Show|2012|UK|+5||'],
                [0 => 100 | 1 << 7 | 1 << 9 | 1 << 11 | 1 << 13 | 2 << 16 | 2 << 18 | 130 << 20 | 1 << 29 | 1 << 37 | 1 << 45,
                    1 => 33 | 2 << 11 | 1 << 15 | 33 << 20 | 2 << 29 | 2 << 37,
                    2 => 100 | 3 << 7 | 2 << 9 | 1 << 11 | 3 << 16 | 40 << 20 | 1 << 29 | 1 << 37 | 1 << 45,
                    3 => 1, 4 => 0, 5 => 1, 6 => 1, 7 => 2, 8 => 0, 9 => 1, 10 => 1, 11 => 130, 12 => 2, 13 => 203, 14 => 2]],
            'parse info: multi-episode, daily, part, gate' => [ParseInfoPipe::class,
                ['Show.Name.S01E02-E03.720p', 'Show 2024 03 09 x', 'Doc Part 2', "\x01", 'Show.3x07', 'Show.S02.Complete', 'Anime Ep 12', 'Nothing here'],
                [0 => 2 | 1 << 3 | 1 << 5 | 2 << 15 | 3 << 29 | 2 << 60, 1 => 4 | 1 << 3 | 124 << 43 | 3 << 51 | 9 << 55 | 1 << 60,
                    2 => 5 | 1 << 3 | 2 << 15 | 2 << 29 | 1 << 60, 3 => 3 << 3, 4 => 1 | 1 << 3 | 3 << 5 | 7 << 15 | 7 << 29 | 1 << 60,
                    5 => 3 | 1 << 3 | 2 << 5 | 1 << 60, 6 => 6 | 1 << 3 | 12 << 15 | 12 << 29 | 1 << 60, 7 => 1 << 3 | 2 << 60,
                    8 => 2, 9 => 1, 10 => 1, 11 => 1, 12 => 1, 13 => 1, 14 => 1, 15 => 0, 16 => 7, 17 => 0, 18 => 1, 19 => 9, 20 => 1, 21 => 3]],
            'tmdb: direct id, title search and unavailable' => [TmdbPipe::class,
                ['Show|81189|tt0944947|1396|2008|US|', 'Show|||||', 'x||||||down', 'Other|81189|||||', 'show|||||', 'Show||||2008||'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 2 << 9 | 1 << 12 | 1 << 14 | 4 << 16 | 1396 << 24, 1 => 1 | 5 << 9 | 4 << 16,
                    2 => 1 | 1 << 8 | 1 << 16, 3 => 1 | 1 << 2 | 3 << 9 | 5 << 16 | 81189 << 24, 6 => 1, 7 => 0, 8 => 1, 9 => 1, 10 => 0, 11 => 3,
                    12 => 4, 13 => 2, 21 => 4, 22 => 2]],
            'trakt: imdb lookup with episode query and slug' => [TraktPipe::class,
                ['The Office (US)||||tt0386676|2|5|', 'x|the-office|||||', 'The Office||||||', '|||||1||', 'y|THE-OFFICE|5|||1|0|'],
                [0 => 1 | 1 << 8 | 1 << 10 | 1 << 12 | 4 << 15 | 3 << 18 | 13 << 20 | 2 << 28 | 5 << 38, 1 => 1 | 1 << 2 | 3 << 15 | 1 << 18 | 10 << 20,
                    2 => 1 | 6 << 15 | 1 << 18 | 10 << 20, 3 => 1 << 10 | 1 << 15 | 1 << 28,
                    4 => 1 | 1 << 2 | 1 << 4 | 1 << 10 | 1 << 12 | 2 << 15 | 2 << 18 | 10 << 20 | 1 << 28,
                    5 => 0, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 0, 11 => 1, 12 => 1, 13 => 2, 14 => 1, 15 => 1, 16 => 1]],
            'parsed release empty batch' => [ParsedTvRelease::class, [], array_fill(0, 20, 0)],
            'trakt empty batch' => [TraktPipe::class, [], array_fill(0, 12, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['Show|Show (2019)|1|2|2024-01-02|us|2019', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['The Office|2005|US|Office|2005|us|12|7|0', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position.
        $edges = self::edgeCases();
        for ($slot = 0; $slot < 9; $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $fields = ['The Office', '2005', 'US', 'Office (2005)', '2', '5', '12', '7', '0'];
                $fields[$slot] = $edge;
                $rows[] = implode('|', $fields);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Show|x', 7 => 'Show.S01E02', 'a' => '', 'c' => 'Show|81189'];
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
        foreach ([621, 622, 623] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6233));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("sSeExXpPaArRtTlLhH0123456789|||..--_ ()+\0\t\n\r\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6234)), true), 0, 80));
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
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W623_BENCH_ROUNDS
        // interleaved paired rounds per helper and workload (default 301, minimum 60), 5 iterations per round).
        // Common = one batch of 320 well-formed items of the class's format; uncommon = 320 items mixing edge
        // cases, malformed, long, NUL, CR/LF, case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~6.3-7.4), common mean / p99, uncommon mean / p99:
        // - ParsedTvRelease: 8.47x / 7.27x, 7.63x / 5.65x
        // - LocalDbPipe:     7.25x / 5.70x, 4.76x / 3.79x
        // - ParseInfoPipe:   12.85x / 9.25x, 9.42x / 6.61x
        // - TmdbPipe:        8.11x / 6.11x, 4.92x / 4.07x
        // - TraktPipe:       9.91x / 6.33x, 7.64x / 5.08x
        // Every helper clears every gate in all three runs and defaults to Rust. Each helper defaults to Rust only
        // when it clears 1.5x on both workloads' mean and p99, per .ai/rules/rust-performance.md; the assertion
        // below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W623_BENCH=1 php artisan test --compact tests/Unit/RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W623_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W623_BENCH=1 to run the Wave 62.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6236));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W623_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6237)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6238)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW62RustFfi($useRust, static fn (): array => $class::$entry($items)));
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
        fwrite(STDERR, "\nRustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW62RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW62RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW62RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
                ParsedTvRelease::class => $this->parsedItem($random, $uncommon),
                LocalDbPipe::class => $this->localItem($random, $uncommon),
                ParseInfoPipe::class => $this->searchName($random, $uncommon),
                TmdbPipe::class => $this->tmdbItem($random, $uncommon),
                default => $this->traktItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n"]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function showTitle(Randomizer $random): string
    {
        return $this->pick($random, ['The Office', 'Breaking Bad', 'Game of Thrones', 'The Expanse', 'Severance', 'Doctor Who', 'Top Gear',
            'The Daily Show', 'Last Week Tonight', 'Planet Earth', 'Shogun', 'The Bear', 'Only Murders in the Building', 'Ted Lasso',
            'House of the Dragon', 'Gilmore Girls', 'Law and Order SVU', 'The Late Show', 'Andor', 'Slow Horses', 'Bluey', 'MasterChef']);
    }

    private function parsedItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->showTitle($random);
        $year = (string) $random->getInt(1960, 2026);
        $clean = $this->pick($random, [$name, $name.' ('.$year.')', '', $name.' US']);
        $season = $this->pick($random, [(string) $random->getInt(1, 30), (string) $random->getInt(1, 9), '']);
        $episode = $this->pick($random, [(string) $random->getInt(1, 24), str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT), 'all', '']);
        $airdate = $this->pick($random, ['', '', $random->getInt(1990, 2026).'-'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'-'
            .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT)]);
        $country = $this->pick($random, ['', '', 'US', 'UK', 'ca', 'AU', 'nz', 'DE']);
        $yearField = $this->pick($random, ['', $year]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', ' ', "Bad\x01Name", str_repeat('n', 260)]);
            $season = $this->pick($random, [$season, '1000', 'x', '-1', 'S01']);
            $episode = $this->pick($random, [$episode, 'ALL', '10000', 'e01', '0']);
            $airdate = $this->pick($random, [$airdate, '2024-13-01', '2024/01/01', '2024-01/01', '1899-01-01', 'today']);
            $country = $this->pick($random, [$country, 'USA', 'zz', 'u1', 'Gb']);
            $yearField = $this->pick($random, [$yearField, '1899', '2100', 'year', '19999']);
        }

        return implode('|', [$name, $clean, $season, $episode, $airdate, $country, $yearField]);
    }

    private function localItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $candidate = $this->pick($random, [$title, $title, $this->showTitle($random), str_replace('The ', '', $title), $title.' (US)', strtoupper($title)]);
        $year = (string) $random->getInt(1990, 2026);
        $candidateYear = $this->pick($random, [$year, $year, (string) ((int) $year + 1), (string) $random->getInt(1990, 2026), '']);
        $country = $this->pick($random, ['', 'US', 'UK']);
        $candidateCountry = $this->pick($random, [$country, 'US', 'uk', '']);
        $video = $this->pick($random, [(string) $random->getInt(1, 90000), (string) $random->getInt(1, 90000), '0', '']);
        $episode = $this->pick($random, [(string) $random->getInt(1, 900000), '0', '']);
        $pack = $this->pick($random, ['0', '0', '1', '', 'false']);
        if ($noisy) {
            $title = $this->pick($random, [$title, '', 'The', '...', str_repeat('word ', 60)]);
            $year = $this->pick($random, [$year, '1899', 'x', '20190']);
            $video = $this->pick($random, [$video, '-5', '+7', ' 12 ', '1e3', str_repeat('9', 19)]);
            $episode = $this->pick($random, [$episode, '-1', 'abc', '+0']);
            $pack = $this->pick($random, [$pack, 'YES', 'On', 'maybe']);
        }

        return implode('|', [$title, $year, $country, $candidate, $candidateYear, $candidateCountry, $video, $episode, $pack]);
    }

    private function searchName(Randomizer $random, bool $noisy): string
    {
        $show = str_replace(' ', '.', $this->showTitle($random));
        $s = str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT);
        $e = str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT);
        $tail = $this->pick($random, ['.720p.HDTV.x264-GRP', '.1080p.WEB-DL.DDP5.1.H.264-NTb', '.2160p.AMZN.WEBRip-GRP', '-GRP', '.PROPER.480p-LOL']);
        $name = $this->pick($random, [
            "{$show}.S{$s}E{$e}{$tail}", "{$show}.S{$s}E{$e}{$tail}", "{$show}.S{$s}E{$e}E".str_pad((string) ((int) $e + 1), 2, '0', STR_PAD_LEFT).$tail,
            "{$show}.{$random->getInt(1990, 2026)}.S{$s}E{$e}{$tail}", "{$show}.S{$s}.Complete{$tail}", "{$show}.".((int) $s)."x{$e}{$tail}",
            "{$show}.{$random->getInt(2000, 2026)}.".str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'.'
                .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT).$tail,
            "{$show}.Part.{$random->getInt(1, 6)}{$tail}", "{$show}.Ep.{$random->getInt(1, 400)}{$tail}", "{$show}{$tail}",
        ]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', str_repeat($name, 12), "{$show}\x01S01E01", "{$show}.S1234E01", "{$show}.Part.0", "{$show}.2024.13.01",
                "{$show}.S01E02E03E04", "{$show}.S01.E05", strtoupper($name), "{$show}.Episode.12"]);
        }

        return $name;
    }

    private function tmdbItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $tvdb = $this->pick($random, ['', '', (string) $random->getInt(70000, 450000)]);
        $imdb = $this->pick($random, ['', '', 'tt'.str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT)]);
        $tmdb = $this->pick($random, ['', '', '', (string) $random->getInt(1, 250000)]);
        $year = $this->pick($random, ['', (string) $random->getInt(1960, 2026)]);
        $country = $this->pick($random, ['', 'US', 'GB']);
        $reason = $this->pick($random, ['', '', '', '', 'missing api key']);
        if ($noisy) {
            $title = $this->pick($random, [$title, '', '!!!', "Bad\x01", str_repeat('t', 300)]);
            $tvdb = $this->pick($random, [$tvdb, '0', '-1', '12345678901', 'x']);
            $imdb = $this->pick($random, [$imdb, 'tt123', 'TT0944947', 'tt0000000', '944947']);
            $tmdb = $this->pick($random, [$tmdb, '00', '9999999999', 'abc']);
            $year = $this->pick($random, [$year, '1899', '20x0']);
            $country = $this->pick($random, [$country, 'USA', '1A']);
        }

        return implode('|', [$title, $tvdb, $imdb, $tmdb, $year, $country, $reason]);
    }

    private function traktItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $slug = $this->pick($random, ['', '', strtolower(str_replace(' ', '-', $title))]);
        $trakt = $this->pick($random, ['', '', '', (string) $random->getInt(1, 200000)]);
        $tvdb = $this->pick($random, ['', (string) $random->getInt(70000, 450000)]);
        $imdb = $this->pick($random, ['', 'tt'.str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT)]);
        $season = $this->pick($random, ['', (string) $random->getInt(1, 20)]);
        $episode = $this->pick($random, ['', (string) $random->getInt(1, 24)]);
        $reason = $this->pick($random, ['', '', '', '', 'no client id']);
        if ($noisy) {
            $slug = $this->pick($random, [$slug, '-bad', 'bad-', 'a--b', 'Has Space', str_repeat('s', 101), 'UPPER-CASE']);
            $trakt = $this->pick($random, [$trakt, '0', 'x', '12345678901']);
            $season = $this->pick($random, [$season, '1000', 's1']);
            $episode = $this->pick($random, [$episode, '0', '99999']);
            $title = $this->pick($random, [$title, '', "Bad\x7f", '***']);
        }

        return implode('|', [$title, $slug, $trakt, $tvdb, $imdb, $season, $episode, $reason]);
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
     * Calls a Wave 62.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w62RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W62_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('tv_w623', TraktPipe::W62_TRAKT_PIPE_FFI_CDEF) === null) {
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
