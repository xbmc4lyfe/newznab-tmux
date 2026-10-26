<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Providers\BaseVideoProvider;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\TvReleaseContext;
use App\Services\TvProcessing\TvShowAdder;
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

final class RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        BaseVideoProvider::class => ['w63BaseVideoProviderPlanBatch', 'nntmux_w633_base_video_provider_batch', 'W63_BASE_VIDEO_PROVIDER_FFI_CDEF', 'W63_BASE_VIDEO_PROVIDER_TRAILER', 20],
        LocalDbProvider::class => ['w63LocalDbProviderPlanBatch', 'nntmux_w633_local_db_provider_batch', 'W63_LOCAL_DB_PROVIDER_FFI_CDEF', 'W63_LOCAL_DB_PROVIDER_TRAILER', 17],
        TraktProvider::class => ['w63TraktProviderPlanBatch', 'nntmux_w633_trakt_provider_batch', 'W63_TRAKT_PROVIDER_FFI_CDEF', 'W63_TRAKT_PROVIDER_TRAILER', 12],
        TvReleaseContext::class => ['w63TvReleaseContextPlanBatch', 'nntmux_w633_tv_release_context_batch', 'W63_TV_RELEASE_CONTEXT_FFI_CDEF', 'W63_TV_RELEASE_CONTEXT_TRAILER', 16],
        TvShowAdder::class => ['w63TvShowAdderPlanBatch', 'nntmux_w633_tv_show_adder_batch', 'W63_TV_SHOW_ADDER_FFI_CDEF', 'W63_TV_SHOW_ADDER_TRAILER', 16],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        BaseVideoProvider::class => true,
        LocalDbProvider::class => true,
        TraktProvider::class => true,
        TvReleaseContext::class => true,
        TvShowAdder::class => true,
    ];

    /** One well-formed item per class, in the order of TARGETS. */
    private const SAMPLES = [
        'The Office (2005)|2005|US|The Office||2005|us|tvdb|81189',
        'The Office (2005)||US|0|1|2|12|Office|||us',
        'The Office (2005)||The Office|2005|1391|73244|tt0386676||/images/poster.jpg|https://x/f.jpg|America/New_York|2|5',
        '123|Show.Name.S01E02.720p-GRP|5|5030|0|0|0123456789abcdef0123456789abcdef01234567|0|2024-01-02',
        'IMDB|tt0944947|0||Game of Thrones|GoT,got, Game of Thrones ,A Song|2011-04-17|us',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', '||||||||', '|||||||||', '||||||||||', '|||||||||||', '||||||||||||', '|||||||||||||',
        ' | | | | | | | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h|i|j|k|l|m|n', ',', ',,', ' , ', 'a,A, a ,b',
        '.', '..', '-', '--', '_', '(', ')', '()', '(2019)', ' (2019)', 'Show (2019)', 'Show(2019)', 'Show (1899)', 'Show (2100)', 'Show (20199)',
        'Show (19x9)', 'Show 2019', 'Show.2019', 'Show(2019', 'Show (2019', 'Show 2019)', 'Show_2019', 'The', 'the', 'The The', 'THE OFFICE',
        'the.office.us', 'Office', 'Law and Order', 'Law & Order', 'LAW AND ORDER', 'Theater', 'Theatre', 'er', 'ER', ' and ', 'and', 'a', 'ab', 'AB',
        'us', 'US', 'uk', 'xx', 'U1', 'USA', 'gb', '0', '00', '000', '01', '1', '2', '3', '9', '10', '99', '100', '999', '1000', '9999', '10000',
        '-1', '+1', '+', '-', '+0', '-0', ' 12 ', '1e3', '0x10', '0.5', '123456789012345678', '1234567890123456789', '4294967295', '9999999999',
        '10000000000', '1899', '1900', '1999', '2000', '2019', '2099', '2100', '20190', '19', '20', '2024-03-09', '2024.03.09', '2024/03/09',
        '2024-03/09', '2024-13-01', '2024-00-10', '2024-01-32', '2024-01-00', '1899-12-31', '2100-01-01', '24-03-09', '2024-3-9', 'tt0944947',
        'TT0944947', 'tt944947', 'Tt12345', 't12345', 't1234', 'tt12345678901', 'tt0000000', 'tt', 't', 'tt1234567x', 'xtt123456', 'tt123456',
        '81189', '0081189', 'imdb', 'IMDB', ' imdb ', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage', 'TVDB', 'anidb', 'http://a/b.jpg', 'https://x',
        'httpx', '/img/a.jpg', '//img/a.jpg', '///', 'img.example/a.png', 'UTC', 'utc', 'America/New_York', 'Europe/London', 'America//York',
        '/America', 'America/', 'Etc/GMT+5', 'Bad Zone/x', 'S01E02', 's01e02', 'S1E2', 'S01E02E03', 'S01E', 'S01', 'S1234', 'S1234E01', 'S01 E02',
        '1x02', '01x002', '123x02', '1x2', 'x02', '2024 03 09', '2024.13.09', '2024 03 32', '1999 1 1', 'Show.Name.S01E02.720p.HDTV.x264-GRP',
        'Daily.Show.2024.03.09.720p-GRP', 'Show.Name.S02.Complete-GRP', 'Show.Name.3x07.HDTV', 'Show_Name__S01E01', '0123456789abcdef0123456789abcdef01234567',
        '0123456789ABCDEF0123456789ABCDEF01234567', '0123456789abcdef0123456789abcdef0123456', 'g123456789abcdef0123456789abcdef01234567',
        ...self::SAMPLES, 'Law and Order|||Law & Order|||||', 'imdb|0944947|||x|||', 'tvdb|0|||Show|||'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 512), str_repeat('a ', 300), str_repeat('.', 600),
            str_repeat('|', 300), str_repeat(',', 300), str_repeat('9', 40), str_repeat('x', 63), str_repeat('x', 64), str_repeat('x', 65),
            str_repeat('a/', 40).'b', str_repeat('A', 179).'B', str_repeat('A', 181).','.str_repeat('a', 182), str_repeat("\xc3\xa9", 200),
            str_repeat('S01E01.', 80), str_repeat('the ', 40), str_repeat('and ', 40), str_repeat('er', 200), ' '.str_repeat('Show ', 101).'(2019)',
            str_repeat('(2019)', 50), str_repeat('tt0944947|', 20), str_repeat('5', 1100), str_repeat('s1e', 400).'1'];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW63RustFfi();
        }
        parent::tearDown();
    }

    public function test_w63_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', 'usesW63RustFfi', 'withW63RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W63_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W63_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
            }
            foreach (['w63RustFfiState', 'w63Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w63RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w63RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW63RustFfi');
            $with = new ReflectionMethod($class, 'withW63RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW63RustFfi/withW63RustFfi signatures';
            }
            foreach (['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w63_adds_no_properties(): void
    {
        // Property sets as of Wave 62 (static, instance and inherited), so Wave 63 cannot add state outside w63RustFfiState().
        $expected = [
            BaseVideoProvider::class => ['echooutput', 'native', 'nativeResolved', 'sites', 'useRustFfi', 'useW49RustFfi', 'w49Ffi', 'w49Out', 'w49OutCap'],
            LocalDbProvider::class => ['echooutput', 'imgSavePath', 'localDbFfi', 'posterUrl', 'siteColumns', 'useLocalDbRustFfi', 'useW49RustFfi', 'w49Ffi',
                'w49Out', 'w49OutCap'],
            TraktProvider::class => ['client', 'echooutput', 'fanartUrl', 'imgSavePath', 'localizedTZ', 'posterUrl', 'siteColumns', 'useRustFfi', 'useW49RustFfi',
                'w49Ffi', 'w49Out', 'w49OutCap'],
            TvReleaseContext::class => ['categoriesId', 'ffi', 'groupsId', 'guid', 'leftGuid', 'releaseId', 'searchName', 'tvEpisodesId', 'useRustFfi', 'videosId',
                'w47RustFfiHandle', 'w47RustFfiOverride'],
            TvShowAdder::class => ['ffi', 'useRustFfi', 'useW49RustFfi', 'w49Ffi', 'w49Out', 'w49OutCap'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
        }
        foreach (['releaseId', 'searchName', 'groupsId', 'categoriesId', 'videosId', 'tvEpisodesId', 'guid', 'leftGuid'] as $property) {
            $this->assertTrue((new ReflectionClass(TvReleaseContext::class))->getProperty($property)->isReadOnly(), $property);
        }
    }

    public function test_w63_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W63_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class);
            $this->assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()));
            $this->assertFalse($class::withW63RustFfi(false, static fn (): bool => $class::usesW63RustFfi()));
            $this->assertTrue($class::withW63RustFfi(false, static fn (): bool => $class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi())));
            $this->assertFalse($class::withW63RustFfi(true, static fn (): bool => $class::withW63RustFfi(false, static fn (): bool => $class::usesW63RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class);
            try {
                $class::withW63RustFfi(! $class::usesW63RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class.' restores after an exception');
            $class::disableW63RustFfi();
            $this->assertFalse($class::usesW63RustFfi(), $class);
            $this->assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()));
            $this->assertFalse($class::usesW63RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW63RustFfi();
            $this->assertTrue($class::usesW63RustFfi(), $class);
            $class::resetW63RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes, including BaseVideoProvider and its LocalDb/Trakt subclasses.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW63RustFfi();
            $class::withW63RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW63RustFfi(), $class.' vs '.$other);
                }
            });
            $class::disableW63RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? false : self::DEFAULTS[$other], $other::usesW63RustFfi(), $class.' disable vs '.$other);
            }
            $class::resetW63RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW63RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW63RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW63RustFfi();
            $this->assertSame($php, $class::withW63RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW63RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w63_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertTrue(BaseVideoProvider::usesW49RustFfi());
        $this->assertTrue(LocalDbProvider::usesW49RustFfi());
        $this->assertFalse(LocalDbProvider::usesRustFfi());
        $this->assertTrue(TraktProvider::usesW49RustFfi());
        $this->assertFalse(TvReleaseContext::usesRustFfi());
        $this->assertSame(TvReleaseContext::W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT, TvReleaseContext::usesW47ReleaseContextRustFfi());
        $this->assertFalse(TvShowAdder::usesRustFfi());
        $this->assertTrue(TvShowAdder::usesW49RustFfi());
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW63RustFfi($enabled, function (): void {
                    $this->assertCount(4 + 3, BaseVideoProvider::w49TitlePlanBatchPhp(['Show (2019)']));
                    $this->assertSame(['name' => 'show', 'year' => null, 'country' => 'US', 'has_name' => true],
                        LocalDbProvider::normalizeShowLookupPhp(' show ', 0, ' us '));
                    $this->assertSame(['site_id' => 0, 'season' => 1, 'episode' => 2, 'video_id' => 5, 'valid' => true],
                        LocalDbProvider::normalizeEpisodeLookupPhp(-3, 1, 2, 5));
                    $this->assertCount(3, LocalDbProvider::showLookupKeyBatchPhp(['The Office (2005)'], [0], ['us']));
                    $this->assertSame(TraktProvider::traktIdsBatchPhp([], []), TraktProvider::traktIdsBatch([], []));
                    $context = TvReleaseContext::fromDataPhp(['id' => 7, 'searchname' => 'Show.S01E02', 'groups_id' => 3, 'categories_id' => 5030]);
                    $this->assertSame([7, 'Show.S01E02', 3, 5030, 0, 0, null], [$context->releaseId, $context->searchName, $context->groupsId,
                        $context->categoriesId, $context->videosId, $context->tvEpisodesId, $context->guid]);
                    $this->assertCount(TvReleaseContext::W47_FIELDS_PER_CONTEXT + 2, TvReleaseContext::w47EligibilityBatchPhp([$context]));
                    $this->assertSame('0944947', (new TvShowAdder)->normalizeIdPhp('imdb', ' tt0944947 '));
                    $this->assertSame('tvmaze', (new TvShowAdder)->videoColumnForSourcePhp('tvmaze'));
                    $this->assertSame(TvShowAdder::normalizeRequestBatchPhp(['tvdb'], ['81189']), TvShowAdder::normalizeRequestBatch(['tvdb'], ['81189']));
                });
            }
        }
    }

    public function test_w63_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('tv_w633', $declaration);
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

    public function test_w63_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, 'Daily.Show.2024.03.09.720p-GRP', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w63Invoke');
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
            'base video: exact title with site route, normalized conjunction' => [BaseVideoProvider::class,
                [self::SAMPLES[0], 'Law and Order|||Law & Order|||||', '|||||||imdb|tt0944947', '||||||||', 'Show|||Other Show|||||'],
                [0 => 1 | 1 << 2 | 1 << 5 | 1 << 6 | 1 << 9 | 1 << 11 | 4 << 13 | 1 << 16 | 1 << 18 | 2 << 20 | 115 << 22 | 3 << 30 | 10 << 38 | 2 << 46,
                    1 => 1 | 1 << 3 | 1 << 4 | 1 << 5 | 3 << 6 | 2 << 18 | 1 << 20 | 90 << 22 | 3 << 30 | 13 << 38 | 2 << 46,
                    2 => 1 << 13 | 1 << 16 | 1 << 18, 3 => 0, 4 => 1 | 5 << 6 | 2 << 18 | 60 << 22 | 1 << 30 | 4 << 38 | 1 << 46,
                    5 => 2, 6 => 1, 8 => 1, 9 => 0, 10 => 1, 12 => 3, 13 => 1, 14 => 1, 15 => 1, 16 => 2, 17 => 2, 18 => 0, 19 => 3, 20 => 1, 21 => 115,
                    22 => 265, 23 => 2, 24 => 1]],
            'local db: single episode lookup with exact key' => [LocalDbProvider::class, [self::SAMPLES[1], '|||||||||', 'Show 2019|||||||||'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 11 | 1 << 13 | 3 << 14 | 1 << 17 | 1 << 19 | 1 << 22 | 2 << 24 | 110 << 26
                    | 100 << 35 | 1 << 42 | 105 << 50, 1 => 0, 2 => 1 | 1 << 2 | 1 << 14 | 1 << 42 | 119 << 50,
                    3 => 1, 4 => 1, 6 => 1, 7 => 0, 8 => 2, 9 => 0, 10 => 1, 11 => 2, 13 => 0, 14 => 1, 15 => 110, 16 => 2, 17 => 1, 18 => 1, 19 => 1]],
            'trakt: summary by id with episode query and resolved images' => [TraktProvider::class, [self::SAMPLES[2], 'Show||||||tt1234567|||||1|1', ''],
                [0 => 1 | 1 << 2 | 1 << 3 | 1 << 5 | 1 << 7 | 2 << 11 | 1 << 13 | 1 << 15 | 1 << 17 | 1 << 19 | 2 << 21 | 1 << 23 | 1 << 25 | 120 << 27
                    | 25 << 35 | 15 << 43 | 2 << 51, 1 => 1 | 1 << 7 | 1 << 17 | 1 << 19 | 1 << 21 | 2 << 23, 2 => 0,
                    3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 0, 11 => 1, 12 => 120, 13 => 1, 14 => 1]],
            'release context: show lookup, not TV, processed and daily' => [TvReleaseContext::class,
                [self::SAMPLES[3], '5|Movie.2019.1080p|1|2040|||||', '6|Show.S01E02|1|5030|9|4|||', '7|Daily.Show.2024.03.09|0|5040|||||', '0|x|||||||'],
                [0 => 1 | 1 << 2 | 2 << 4 | 2 << 6 | 1 << 8 | 1 << 9 | 1 << 12 | 1 << 14 | 1 << 16 | 4 << 18 | 90 << 23 | 25 << 30 | 1 << 40 | 2 << 50,
                    1 => 1 | 1 << 2 | 1 << 18 | 16 << 30, 2 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 9 | 2 << 18 | 30 << 23 | 11 << 30 | 1 << 40 | 2 << 50,
                    3 => 1 | 2 << 2 | 1 << 8 | 3 << 9 | 4 << 18 | 85 << 23 | 21 << 30, 4 => 2 | 1 << 21 | 1 << 30,
                    5 => 1, 6 => 1, 7 => 1, 8 => 0, 9 => 2, 10 => 0, 11 => 2, 12 => 2, 13 => 0, 14 => 1, 15 => 0, 16 => 2, 17 => 205, 18 => 5,
                    19 => 2, 20 => 3]],
            'show adder: insert with aliases, repeat, unsupported and invalid id' => [TvShowAdder::class,
                [self::SAMPLES[4], 'imdb|0944947|||x|||', 'anidb|1|||x|||', 'tvdb|0|||Show|||', 'tvdb|81189|1|42|Show|||', 'tvmaze|5|7||Show|||'],
                [0 => 5 | 1 << 3 | 1 << 5 | 1 << 10 | 1 << 12 | 1 << 14 | 5 << 15 | 2 << 18 | 7 << 26 | 15 << 32 | 2 << 40,
                    1 => 5 | 1 << 3 | 1 << 10 | 3 << 15 | 7 << 26 | 1 << 32, 2 => 6 | 1 << 3 | 1 << 10 | 1 << 26 | 1 << 32,
                    3 => 1 | 2 << 3 | 1 << 10 | 1 << 15 | 1 << 26 | 4 << 32, 4 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 10 | 2 << 15 | 5 << 26 | 4 << 32,
                    5 => 2 | 1 << 3 | 2 << 5 | 1 << 10 | 1 << 15 | 1 << 26 | 4 << 32,
                    6 => 1, 7 => 2, 8 => 1, 9 => 1, 10 => 0, 11 => 1, 12 => 0, 13 => 2, 14 => 1, 15 => 0, 16 => 0, 17 => 2, 18 => 1, 19 => 2, 20 => 2, 21 => 1]],
            'base video empty batch' => [BaseVideoProvider::class, [], array_fill(0, 20, 0)],
            'show adder empty batch' => [TvShowAdder::class, [], array_fill(0, 16, 0)],
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
        // Edge values in every field position of every class's sample.
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
        foreach ([631, 632, 633] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6333));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("sSeExXtTaAnNdDrRhHpP0123456789|||||,,..--_ ()+/:\0\t\n\r\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6334)), true), 0, 80));
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W633_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, release dylib, Apple
        // Silicon, shared host at load average ~6.1-8.8), common mean / p99, uncommon mean / p99:
        // - BaseVideoProvider: 8.03x / 5.15x, 5.62x / 4.57x
        // - LocalDbProvider:   7.80x / 6.18x, 4.92x / 4.25x
        // - TraktProvider:     6.38x / 5.95x, 5.63x / 4.84x
        // - TvReleaseContext:  5.42x / 5.27x, 9.74x / 8.78x
        // - TvShowAdder:       8.76x / 6.66x, 5.94x / 4.46x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 63.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6336));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W633_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6337)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6338)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW63RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW63RustFfi() : $class::disableW63RustFfi();
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
        fwrite(STDERR, "\nRustTvBaseVideoLocalDbTraktProviderContextAdderW63 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW63RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW63RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW63RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
                BaseVideoProvider::class => $this->baseItem($random, $uncommon),
                LocalDbProvider::class => $this->localItem($random, $uncommon),
                TraktProvider::class => $this->traktItem($random, $uncommon),
                TvReleaseContext::class => $this->contextItem($random, $uncommon),
                default => $this->adderItem($random, $uncommon),
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
            'House of the Dragon', 'Gilmore Girls', 'Law and Order SVU', 'The Late Show', 'Andor', 'Slow Horses', 'Bluey', 'MasterChef',
            "Grey's Anatomy", 'Mr. Robot', 'Theater of Blood', 'Stranger Things']);
    }

    private function year(Randomizer $random): string
    {
        return (string) $random->getInt(1960, 2026);
    }

    private function baseItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $year = $this->year($random);
        $titleField = $this->pick($random, [$title, $title, $title.' ('.$year.')']);
        $candidate = $this->pick($random, [$title, $title, str_replace(' and ', ' & ', $title), str_replace('The ', '', $title), $this->showTitle($random), '']);
        $alias = $this->pick($random, ['', '', $title.' (US)', strtoupper($title), $this->showTitle($random)]);
        $candidateYear = $this->pick($random, ['', $year, (string) ((int) $year + 1), $this->year($random)]);
        $column = $this->pick($random, ['', '', 'tvdb', 'tvmaze', 'tmdb', 'trakt', 'imdb', 'tvrage']);
        $site = $column === 'imdb' ? 'tt'.str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT) : ($column === '' ? '' : (string) $random->getInt(1, 450000));
        $fields = [$titleField, $this->pick($random, ['', $year]), $this->pick($random, ['', 'US', 'UK']), $candidate, $alias, $candidateYear,
            $this->pick($random, ['', 'US', 'us', 'GB']), $column, $site];
        if ($noisy) {
            $fields[0] = $this->pick($random, [$fields[0], '', ' ', "Bad\x01Name", str_repeat('n', 260), '(2019)', 'Show (1899)']);
            $fields[7] = $this->pick($random, [$fields[7], 'IMDB', 'anidb', ' tvdb ']);
            $fields[8] = $this->pick($random, [$fields[8], '0', '-1', 'tt12', '12345678901', 'x']);
            $fields[1] = $this->pick($random, [$fields[1], '1899', 'year', '20190']);
        }

        return implode('|', $fields);
    }

    private function localItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $year = $this->year($random);
        $name = $this->pick($random, [$title, $title, $title.' '.$year, str_replace(' ', '.', $title).'.'.$year, $title.' ('.$year.')']);
        $video = $this->pick($random, [(string) $random->getInt(1, 90000), (string) $random->getInt(1, 90000), '0', '']);
        $fields = [$name, $this->pick($random, ['', '', $year]), $this->pick($random, ['', 'US', 'uk']), (string) $random->getInt(0, 450000),
            (string) $random->getInt(0, 30), (string) $random->getInt(0, 24), $video,
            $this->pick($random, [$title, $title, $this->showTitle($random), str_replace('The ', '', $title), '']),
            $this->pick($random, ['', '', $title.' (US)', $this->showTitle($random)]),
            $this->pick($random, [$year, $year, (string) ((int) $year + 1), $this->year($random), '']), $this->pick($random, ['', 'US', 'uk'])];
        if ($noisy) {
            $fields[0] = $this->pick($random, [$fields[0], '', 'The', '...', str_repeat('word ', 60), "Bad\x7f"]);
            $fields[4] = $this->pick($random, [$fields[4], '-1', 'x', '+3']);
            $fields[5] = $this->pick($random, [$fields[5], '-2', '1e3', ' 7 ']);
            $fields[6] = $this->pick($random, [$fields[6], '-5', '+7', str_repeat('9', 19)]);
        }

        return implode('|', $fields);
    }

    private function traktItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random);
        $year = $this->year($random);
        $fields = [$this->pick($random, [$title, $title, $title.' ('.$year.')']), $this->pick($random, ['', $year]),
            $this->pick($random, [$title, $title, $this->showTitle($random), $title.' (US)', '']), $this->pick($random, [$year, (string) ((int) $year - 1), $this->year($random), '']),
            $this->pick($random, ['', '', (string) $random->getInt(1, 200000)]), $this->pick($random, ['', (string) $random->getInt(70000, 450000)]),
            $this->pick($random, ['', 'tt'.str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT)]), $this->pick($random, ['', (string) $random->getInt(1, 250000)]),
            $this->pick($random, ['', 'https://walter.trakt.tv/images/shows/000/001/390/posters/medium/'.$random->getInt(1, 99999).'.jpg', 'walter-r2.trakt.tv/images/p.jpg']),
            $this->pick($random, ['', 'https://walter.trakt.tv/images/shows/fanart/'.$random->getInt(1, 99999).'.jpg', '/walter.trakt.tv/f.jpg']),
            $this->pick($random, ['', 'America/New_York', 'Europe/London', 'Asia/Tokyo', 'UTC']), $this->pick($random, ['', (string) $random->getInt(0, 20)]),
            $this->pick($random, ['', (string) $random->getInt(1, 24)])];
        if ($noisy) {
            $fields[0] = $this->pick($random, [$fields[0], '', "Bad\x7f", '***', str_repeat('t', 300)]);
            $fields[6] = $this->pick($random, [$fields[6], 'tt123', 'TT0944947', 'xtt1234567', '944947']);
            $fields[8] = $this->pick($random, [$fields[8], '///', 'httpx', ' http://a ']);
            $fields[10] = $this->pick($random, [$fields[10], 'America//York', 'Bad Zone', '/UTC', str_repeat('a/', 40)]);
            $fields[11] = $this->pick($random, [$fields[11], '1000', 's1']);
            $fields[12] = $this->pick($random, [$fields[12], '0', '99999']);
        }

        return implode('|', $fields);
    }

    private function searchName(Randomizer $random): string
    {
        $show = str_replace(' ', '.', $this->showTitle($random));
        $s = str_pad((string) $random->getInt(1, 30), 2, '0', STR_PAD_LEFT);
        $e = str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT);
        $tail = $this->pick($random, ['.720p.HDTV.x264-GRP', '.1080p.WEB-DL.DDP5.1.H.264-NTb', '.2160p.AMZN.WEBRip-GRP', '-GRP', '.PROPER.480p-LOL']);

        return $this->pick($random, ["{$show}.S{$s}E{$e}{$tail}", "{$show}.S{$s}E{$e}{$tail}", "{$show}.{$random->getInt(1990, 2026)}.S{$s}E{$e}{$tail}",
            "{$show}.S{$s}.Complete{$tail}", "{$show}.".((int) $s)."x{$e}{$tail}", "{$show}_S{$s}E{$e}_{$tail}",
            "{$show}.{$random->getInt(2000, 2026)}.".str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'.'
                .str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT).$tail, "{$show}{$tail}"]);
    }

    private function contextItem(Randomizer $random, bool $noisy): string
    {
        $guid = sha1((string) $random->getInt(0, PHP_INT_MAX));
        $video = $this->pick($random, ['0', '0', (string) $random->getInt(1, 90000)]);
        $fields = [(string) $random->getInt(1, 9000000), $this->searchName($random), (string) $random->getInt(1, 900),
            (string) $this->pick($random, [5030, 5040, 5045, 5050, 5070, 5080, 5000, 2040, 7020]), $video,
            $video === '0' ? '0' : $this->pick($random, ['0', (string) $random->getInt(1, 900000)]), $this->pick($random, [$guid, $guid, '']),
            $this->pick($random, [$guid[0], $guid[0], '']), $this->pick($random, ['', '', $random->getInt(1990, 2026).'-'
                .str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT)])];
        if ($noisy) {
            $fields[0] = $this->pick($random, [$fields[0], '0', '-1', 'x', '']);
            $fields[1] = $this->pick($random, [$fields[1], '', 'abc', "Show\x80S01E01", str_repeat('a.', 600), 's1234e01', '1x2']);
            $fields[3] = $this->pick($random, [$fields[3], '5999', '6000', '4999', '-5030']);
            $fields[6] = $this->pick($random, [$fields[6], strtoupper($guid), substr($guid, 1), 'g'.substr($guid, 1)]);
            $fields[7] = $this->pick($random, [$fields[7], strtoupper($guid[0]), 'zz']);
            $fields[8] = $this->pick($random, [$fields[8], '2024-13-01', '2024/01/01', '1899-01-01']);
        }

        return implode('|', $fields);
    }

    private function adderItem(Randomizer $random, bool $noisy): string
    {
        $source = $this->pick($random, TvShowAdder::SUPPORTED_SOURCES);
        $id = $source === 'imdb' ? $this->pick($random, ['tt', '', 't']).str_pad((string) $random->getInt(1, 9999999), 7, '0', STR_PAD_LEFT) : (string) $random->getInt(1, 450000);
        $title = $this->showTitle($random);
        $aliases = $this->pick($random, ['', '', $title.' (US)', strtoupper($title).','.$title, $title.' US, '.$this->showTitle($random).',,', $this->showTitle($random)]);
        $fields = [$this->pick($random, [$source, $source, strtoupper($source), ' '.$source.' ']), $id, $this->pick($random, ['', '0', '0', '1', '2']),
            $this->pick($random, ['', '', '0', (string) $random->getInt(1, 90000)]), $title, $aliases,
            $this->pick($random, ['', $random->getInt(1960, 2026).'-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9)]), $this->pick($random, ['', 'US', 'gb'])];
        if ($noisy) {
            $fields[0] = $this->pick($random, [$fields[0], '', 'anidb', 'IMDB ']);
            $fields[1] = $this->pick($random, [$fields[1], '0', '000', '-5', 'tt12', 'abc', ' ']);
            $fields[2] = $this->pick($random, [$fields[2], '3', 'x', '-1']);
            $fields[4] = $this->pick($random, [$fields[4], '', "Bad\x01", str_repeat('t', 300)]);
            $fields[5] = $this->pick($random, [$fields[5], str_repeat('a,', 200), str_repeat('x', 400), ' , , ']);
        }

        return implode('|', $fields);
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
     * Calls a Wave 63.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w63RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('tv_w633', TvShowAdder::W63_TV_SHOW_ADDER_FFI_CDEF) === null) {
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
