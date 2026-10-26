<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\Pipes\XxxPipe;
use App\Services\CollectionCleanupService;
use App\Services\CollectionsCleaningService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\DomainConfigurationRepository;
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

final class RustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        XxxPipe::class => ['w67XxxPipePlanBatch', 'nntmux_w671_xxx_pipe_plan_batch', 'W67_XXX_PIPE_FFI_CDEF', 28, 'xxx_pipe_w671'],
        CollectionCleanupService::class => ['w67CollectionCleanupPlanBatch', 'nntmux_w671_collection_cleanup_plan_batch', 'W67_COLLECTION_CLEANUP_FFI_CDEF', 21,
            'collection_cleanup_w671'],
        CollectionsCleaningService::class => ['w67CollectionsCleaningPlanBatch', 'nntmux_w671_collections_cleaning_plan_batch', 'W67_COLLECTIONS_CLEANING_FFI_CDEF', 34,
            'collections_cleaning_w671'],
        ConfigurationProvider::class => ['w67ConfigurationProviderPlanBatch', 'nntmux_w671_configuration_provider_plan_batch', 'W67_CONFIGURATION_PROVIDER_FFI_CDEF', 36,
            'configuration_provider_w671'],
        DomainConfigurationRepository::class => ['w67DomainConfigRepoPlanBatch', 'nntmux_w671_domain_config_repo_plan_batch', 'W67_DOMAIN_CONFIG_REPO_FFI_CDEF', 31,
            'domain_config_repo_w671'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        XxxPipe::class => true,
        CollectionCleanupService::class => true,
        CollectionsCleaningService::class => true,
        ConfigurationProvider::class => true,
        DomainConfigurationRepository::class => true,
    ];

    /** A realistic `|`-separated item per class. */
    private const TEMPLATES = [
        XxxPipe::class => ['Brazzers.21.01.01.Some.Name.XXX.1080p.MP4-GRP', 'alt.binaries.erotica', 'Poster <p@x.com>'],
        CollectionCleanupService::class => ['1761400000', '1761480000', '72', '40', '3200', '3', '1000'],
        CollectionsCleaningService::class => ['[01/20] - "Some.Show.S01E02.part01.rar" yEnc (01/50)', 'alt.binaries.teevee'],
        ConfigurationProvider::class => ['ingestion', 'max_messages', 'int', '20000', '1000'],
        DomainConfigurationRepository::class => ['site', 'site-name', 'NNTmux', 'NNTmux Indexer', '300'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed values. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\x0C", "\r", "\r\n", "\x7f", "\x01", "\xff", "caf\xc3\xa9", "\xe2\x80\x8b", '|', '||',
        '|||', '||||', '|||||', '||||||', '|||||||', '||||||||', ' | | ', "\t|\t|\t", "\0|\0|\0", 'a|b|c|d|e|f|g|h|i|j|k|l', '.', '..', '...', '-', '_', '+', ':', '/',
        '(', ')', '()', '(1/2)', '(01/20)', ' (1/2)', '(0/0)', '(1234567890/2)', '(12345678901/2)', '(1/)', '(/2)', '(a/2)', 'x (1/2) yEnc', 'x yEnc (1/2)',
        'yEnc', 'YENC', ' - yEnc', '--_ yenc', 'yen', '"', '""', '"a"', '"a.rar"', '"a.PAR2"', '"a.part01.rar"', '"a.vol01+02.par2"', '"a.7z"', '"noext"', '"a."',
        '10 MB', '1 kb', '5 GB', '5GB', 'x 9 Mb y', '0', '00', '1', '2', '3', '5', '9', '10', '15', '16', '72', '99', '100', '300', '511', '512', '1000', '3600',
        '8760', '8761', '9999', '10000', '10001', '86400', '86401', '2147483647', '2147483648', '4294967295', '999999999999999', '1000000000000000',
        '999999999999999999', '1000000000000000000', '-1', '-0', '+0', '+5', '--1', '+-1', '1-', ' 1 ', '1 2', '1.5', '1.', '.5', '.', '-.5', '-7.25', '1e3',
        '0x10', '1234567890123456.5', '1.1234567890123456', 'true', 'TRUE', 'false', 'False', 'yes', 'no', 'on', 'OFF', 'maybe', 'int', 'INTEGER', 'bool',
        'boolean', 'float', 'double', 'string', 'weird', 'site', 'SITE', 'registration', 'ingestion', 'post-processing', 'Post_Processing', 'post processing',
        'postprocessing', 'metadata', 'tmux', 'bogus', 'app.name', 'App.Name', 'app..name', 'app.', '.app', 'a_1.b_2', '9bad', 'site-name', 'Site Name',
        'max.items', '{"a":1}', '[1,2]', '{', '[', '}', '{]', "line\nbreak", 'XXX', 'xxx', 'Brazzers', 'TokyoHot', 'MILF', 'dvdr', 'DVDR.', 'dvdrip', 'dvd5',
        'dvd9', 'x264', 'h.264', 'HEVC', '2160p', '4k', 'UHD', 'imageset', '.pack.', ' pack ', 'wmv', 'xvid', 'clip', 'siterip', 's01e02', 'S1E2', 'Season',
        'hdtv', 'alt.binaries.erotica', 'alt.binaries.sounds.mp3', 'a.b.music', 'alt.binaries.teevee', 'anon@y.com', 'Oz@Lot.Com', '1761400000',
        '1761480000', '2000000000', "a\tb\nc\x0Bd\x0Ce", '  spaced   subject  (3/9) ', 'x|y|z', '1|2|3', 'x|1', '1|x'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 15), str_repeat('9', 15), str_repeat('9', 16), str_repeat('9', 18), str_repeat('9', 19), str_repeat('a', 255),
            str_repeat('a', 511), str_repeat('a', 512), str_repeat('a', 2048), str_repeat('x', 5000), str_repeat('x', 70000), str_repeat('.', 300), str_repeat('|', 300),
            str_repeat(' ', 100), str_repeat("\t ", 60), str_repeat('(1/2)', 30), str_repeat('yEnc', 20), str_repeat('"', 9), str_repeat('a.', 70).'b',
            str_repeat("\xc3\xa9", 200), str_repeat("\x01", 80), str_repeat('XXX', 40), str_repeat('1', 10), str_repeat('1', 11),
            'subject ('.str_repeat('9', 10).'/'.str_repeat('9', 10).')', str_repeat('9', 15).'.'.str_repeat('9', 15)];
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
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW67RustFfi', 'enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi', 'withW67RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W67_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W67_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
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
            // Wave 67 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if (str_contains(strtolower($property->getName()), 'w67')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \constant($class.'::'.str_replace('_FFI_CDEF', '_TRAILER', $cdef)), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        $repository = new ReflectionClass(DomainConfigurationRepository::class);
        $this->assertTrue($repository->isReadOnly() && $repository->isFinal(), 'DomainConfigurationRepository stays a final readonly class');
        $this->assertTrue((new ReflectionClass(ConfigurationProvider::class))->isFinal(), 'ConfigurationProvider stays final');
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w67_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W67_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class);
            $class::enableW67RustFfi();
            $this->assertTrue($class::usesW67RustFfi(), $class);
            $this->assertFalse($class::withW67RustFfi(false, static fn (): bool => $class::usesW67RustFfi()));
            $this->assertTrue($class::usesW67RustFfi(), $class.' restores the enabled override');
            $class::disableW67RustFfi();
            $this->assertFalse($class::usesW67RustFfi(), $class);
            $this->assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()));
            $this->assertFalse($class::usesW67RustFfi(), $class.' restores the disabled override');
            $class::resetW67RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class);
            $this->assertTrue($class::withW67RustFfi(false, static fn (): bool => $class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi())));
            $this->assertFalse($class::withW67RustFfi(true, static fn (): bool => $class::withW67RustFfi(false, static fn (): bool => $class::usesW67RustFfi())));
            try {
                $class::withW67RustFfi(! $class::usesW67RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class.' restores after an exception');
            // Flipping one class's toggle leaves the others at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW67RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW67RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW67RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)), "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW67RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW67RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w67_existing_public_api_is_unchanged(): void
    {
        $earlier = static fn (): array => [
            XxxPipe::usesRustFfi(), XxxPipe::usesW433RustFfi(), XxxPipe::usesW54RustFfi(),
            CollectionCleanupService::usesRustFfi(), CollectionCleanupService::usesW401RustFfi(), CollectionCleanupService::usesW54RustFfi(),
            CollectionsCleaningService::usesRustFfi(), CollectionsCleaningService::usesW431RustFfi(), CollectionsCleaningService::usesW54RustFfi(),
            ConfigurationProvider::usesRustFfi(), ConfigurationProvider::usesW57RustFfi(),
            DomainConfigurationRepository::usesRustFfi(), DomainConfigurationRepository::usesW57RustFfi(),
        ];
        $before = $earlier();
        $this->assertSame([XxxPipe::W433_RUST_FFI_DEFAULT, CollectionCleanupService::W401_RUST_FFI_DEFAULT, CollectionsCleaningService::W431_RUST_FFI_DEFAULT],
            [$before[1], $before[4], $before[7]]);
        foreach (array_keys(self::TARGETS) as $class) {
            foreach ([true, false] as $enabled) {
                $class::withW67RustFfi($enabled, function () use ($earlier, $before, $class): void {
                    $this->assertSame($before, $earlier(), $class.' leaves earlier-wave toggles alone');
                });
            }
            $class::resetW67RustFfi();
            $this->assertSame($before, $earlier(), $class.' reset leaves earlier-wave toggles alone');
        }
        $this->assertSame('XXX', XxxPipe::pipeNamePhp());
        $this->assertSame(10, XxxPipe::pipePriorityPhp());
        $this->assertSame(1000 - 2 * 3600, CollectionCleanupService::cutoffTimestampPhp(1000, 2));
        $this->assertSame(15, CollectionCleanupService::batchEndPhp(10, 5, 20));
        $this->assertSame(7, CollectionCleanupService::batchBoundPhp(3, 7, 9));
        $this->assertSame(6, CollectionCleanupService::cleanupTotalPhp([1, 2, 3]));
        $this->assertSame(-10, CollectionsCleaningService::REGEX_GENERIC_MATCH);
        $this->assertSame([], CollectionsCleaningService::subjectFeatureMasksPhp([]));
        $this->assertSame([], ConfigurationProvider::valueCodesBatchPhp([]));
        $this->assertSame(2, ConfigurationProvider::W57_VALUE_WORDS);
        $this->assertSame([['tmux_configuration_id' => 1, 'rule' => 'a']], DomainConfigurationRepository::cleanupRuleRowsPhp(['a', 'a']));
        $this->assertSame([], DomainConfigurationRepository::settingDiffBatchPhp([], []));
    }

    public function test_w67_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer, $domain]) {
            $ffi = RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef));
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = [implode('|', self::TEMPLATES[$class]), 'x|1|1|3'];
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
            // Output buffers overlapping the input or the length column decline.
            $shared = $ffi->new('int64_t[128]');
            FFI::memcpy($shared, str_repeat('a', 64), 64);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $ffi->cast('char *', FFI::addr($shared[0])), 64, null, 1, $shared, 128), $class);
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 128), $class);
            // A misaligned output pointer declines.
            $bytes = $ffi->new('char[1100]');
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $ffi->cast('int64_t *', FFI::addr($bytes[1])), 128), $class);
        }
    }

    public function test_w67_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)),
                'Post_Processing|Max-Items|5|6|99999999', '"x.part01.rar" yEnc (1/2)|alt.binaries.sounds.mp3', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w67Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
            // A batch larger than the cached buffer grows it.
            $large = array_fill(0, 3000, implode('|', self::TEMPLATES[$class]));
            $this->assertSame($class::{$entry.'Php'}($large), $invoke->invoke(null, $large, $trailer + 3000), $class.' grows its buffers');
            // reset drops the cached handle and buffers; the next call resolves them again.
            $class::resetW67RustFfi();
            $this->assertSame($class::{$entry.'Php'}($items), $invoke->invoke(null, $items, $trailer + \count($items)), $class.' after reset');
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $xxx = XxxPipe::w67XxxPipePlanBatchPhp(['Brazzers.21.01.01.Some.Name.XXX.1080p.MP4|alt.binaries.erotica|poster', 'Show.S01E02.HDTV.x264|alt.binaries.teevee|p',
            'Linux.Distro.ISO|a.b|p', 'Some.Clip.MILF|a.b|anon@y.com', '', 'Some.XXX.DVDR-GRP|alt.binaries.erotica|p', 'some.xxx.dvdr-grp|a.b|p',
            'Big.XXX.Show.S01E01.2160p|a.b', 'Random.Movie.2019|a.b']);
        $this->assertSame([4, 7, 1 | 2 | 16, 85, 6040], [$bits($xxx[0], 0, 3), $bits($xxx[0], 3, 4), $bits($xxx[0], 7, 10), $bits($xxx[0], 17, 8), $bits($xxx[0], 25, 13)]);
        $this->assertSame([1, 1, 3, 30, 6041, 0], [$bits($xxx[1], 0, 3), $bits($xxx[2], 0, 3), $bits($xxx[3], 0, 3), $bits($xxx[3], 17, 8), $bits($xxx[3], 25, 13),
            $bits($xxx[4], 0, 3)]);
        $this->assertSame([4, 6010, 128], [$bits($xxx[5], 3, 4), $bits($xxx[5], 25, 13), $bits($xxx[6], 7, 10) & 128]);
        $this->assertSame([4, 1, 6045, 1 | 32], [$bits($xxx[7], 0, 3), $bits($xxx[7], 3, 4), $bits($xxx[7], 25, 13), $bits($xxx[7], 7, 10)]);
        $this->assertSame([2, 0, 0, 17], [$bits($xxx[8], 0, 3), $bits($xxx[8], 25, 13), $bits($xxx[8], 17, 8), $bits($xxx[8], 38, 16)]);
        $this->assertSame([7, 3], [$xxx[9 + 26], $xxx[9 + 27]]);

        $cleanup = CollectionCleanupService::w67CollectionCleanupPlanBatchPhp(['1000000|1036000|10|5|50|3|1000', '1000000|1036000|10|0|0|3|1000',
            '980000|1036000|10|5|50|3|1000', '900000|1036000|10|5|50|5|1000', '900000|1036000|10|5|50|5|10', 'x', '|1036000', '', '2000000|1036000|99999|5|0|5|99999',
            '900000|1036000|10|0|0|5|']);
        $this->assertSame([3, 10, 10, 3, 5, 6, 1], [$bits($cleanup[0], 0, 3), $bits($cleanup[0], 11, 20), $bits($cleanup[0], 31, 14), $bits($cleanup[1], 0, 3),
            $bits($cleanup[2], 0, 3), $bits($cleanup[3], 0, 3), $bits($cleanup[3], 45, 16)]);
        $this->assertSame([6, 32, 1, 2, 0, 3, 1 | 4 | 16 | 32, 4, 1 | 2], [$bits($cleanup[4], 0, 3), $bits($cleanup[4], 3, 8), $bits($cleanup[5], 0, 3),
            $bits($cleanup[6], 0, 3), $bits($cleanup[7], 0, 3), $bits($cleanup[8], 0, 3), $bits($cleanup[8], 3, 8), $bits($cleanup[9], 0, 3),
            $bits($cleanup[9], 45, 16) | $bits($cleanup[9], 3, 2)]);
        $this->assertSame([56 + 56 + 1, 2 + 1, 0, 1036000 - 36000], [$cleanup[10 + 15], $cleanup[10 + 17], $cleanup[10 + 19], $cleanup[10 + 20]]);

        $cleaning = CollectionsCleaningService::w67CollectionsCleaningPlanBatchPhp(['[01/20] - "Some.File.part01.rar" yEnc (01/50)|alt.binaries.teevee',
            'Artist - Album (1/9) yEnc|alt.binaries.sounds.mp3', '(1/2) yEnc|a.b', 'plain subject 10 MB|a.b', '', '"x.par2" yEnc|a.b', '"x.vol01+02.par2"|a.b']);
        $this->assertSame([3, 1 | 2 | 16 | 32, 1, 1, 50], [$bits($cleaning[0], 0, 3), $bits($cleaning[0], 3, 10), $bits($cleaning[0], 13, 4), $bits($cleaning[0], 17, 12),
            $bits($cleaning[0], 29, 12)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', "generic:[01/20] - \"some.file.part01.rar\"\0alt.binaries.teevee")) & 0xFFFFF, $bits($cleaning[0], 41, 20));
        $this->assertSame([2, 1 | 2 | 64, 1, 4, 128, 0, 1 | 4 | 16, 2], [$bits($cleaning[1], 0, 3), $bits($cleaning[1], 3, 10), $bits($cleaning[2], 0, 3),
            $bits($cleaning[3], 0, 3), $bits($cleaning[3], 3, 10), $bits($cleaning[4], 0, 3), $bits($cleaning[5], 3, 10), $bits($cleaning[5], 13, 4)]);
        $this->assertSame([3, 4 | 8 | 16, 2], [$bits($cleaning[6], 0, 3), $bits($cleaning[6], 3, 10), $bits($cleaning[6], 13, 4)]);
        $this->assertSame([5, 50, 3], [$cleaning[7 + 32], $cleaning[7 + 31], $cleaning[7 + 33]]);

        $provider = ConfigurationProvider::w67ConfigurationProviderPlanBatchPhp(['site|app.name|string|NNTmux|', 'ingestion|max_messages|int| -20000 |',
            'ingestion|max_messages|int|abc|500', 'tmux|enabled|bool|nope|maybe', 'bogus|a|int|1|', 'site|Bad Key|int|1|', 'site|a|weird|1|', '|ratio||1.5|', '',
            'Metadata|flag|BOOLEAN|on|', 'site|title|string||']);
        $this->assertSame([4, 1, 4, 6], [$bits($provider[0], 0, 3), $bits($provider[0], 3, 3), $bits($provider[0], 9, 3), $bits($provider[0], 51, 11)]);
        $this->assertSame([4, 1 | 2 | 4, 20000, 5, 4 | 8 | 16 | 128, 500], [$bits($provider[1], 0, 3), $bits($provider[1], 12, 8), $bits($provider[1], 20, 31),
            $bits($provider[2], 0, 3), $bits($provider[2], 12, 8), $bits($provider[2], 20, 31)]);
        $this->assertSame([6, 1, 2, 3, 4, 3, 1, 0], [$bits($provider[3], 0, 3), $bits($provider[4], 0, 3), $bits($provider[5], 0, 3), $bits($provider[6], 0, 3),
            $bits($provider[7], 0, 3), $bits($provider[7], 9, 3), $bits($provider[7], 20, 31), $bits($provider[8], 0, 3)]);
        $this->assertSame([4, 5, 2, 2, 1], [$bits($provider[9], 0, 3), $bits($provider[9], 3, 3), $bits($provider[9], 6, 3), $bits($provider[9], 9, 3),
            $bits($provider[9], 20, 31)]);
        $this->assertSame([5, 4, 4 | 8], [$bits($provider[10], 0, 3), $bits($provider[10], 9, 3), $bits($provider[10], 12, 8)]);
        $this->assertSame([20000 + 500 + 1 + 1, 8], [$provider[11 + 34], $provider[11 + 35]]);

        $repo = DomainConfigurationRepository::w67DomainConfigRepoPlanBatchPhp(['site|Site-Name|old|new|300', 'Post_Processing|max.items|5|5|', 'tmux|colors|stored||0',
            'metadata|key|||99999999', 'nope|key|a|b|1', 'site|9bad|a|b|1', 'site|key|a|b|x', '', 'site|site_name|a|{"x":1}|1', "postprocessing|x|a\nb||"]);
        $this->assertSame([4, 1, 2, 1, 300], [$bits($repo[0], 0, 3), $bits($repo[0], 3, 3), $bits($repo[0], 6, 2), $bits($repo[0], 8, 9), $bits($repo[0], 17, 17)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', 'configuration:site.site_name')) & 0xFFFFF, $bits($repo[0], 34, 20));
        $this->assertSame([5, 4, 1 | 2, 6, 8, 7, 86400, 1, 2, 3, 0], [$bits($repo[1], 0, 3), $bits($repo[1], 3, 3), $bits($repo[1], 8, 9), $bits($repo[2], 0, 3),
            $bits($repo[2], 8, 9), $bits($repo[3], 0, 3), $bits($repo[3], 17, 17), $bits($repo[4], 0, 3), $bits($repo[5], 0, 3), $bits($repo[6], 0, 3),
            $bits($repo[7], 0, 3)]);
        $this->assertSame([32 | 256, 7, 6, 16 | 128, 3], [$bits($repo[8], 8, 9), $bits($repo[8], 54, 9), $bits($repo[9], 0, 3), $bits($repo[9], 8, 9), $bits($repo[9], 54, 9)]);
        $this->assertSame([5, 86400], [$repo[10 + 30], $repo[10 + 29]]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(190, \count($edges));
        $template = implode('|', self::TEMPLATES[$class]);
        $this->assertParity($class, [$template, ...$edges], 'edge batch');
        foreach ($edges as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$template, $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position of every class template, so each helper sees the others' formats too.
        foreach (self::TEMPLATES as $fields) {
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot);
            }
        }
        // Edges glued to realistic items with every separator.
        foreach ([' ', '.', '-', '_', ':', '|', '/', '(', ')', '"', ',', "\t", ''] as $glue) {
            $this->assertParity($class, array_map(static fn (string $e): string => $template.$glue.$e, $edges), 'glued '.bin2hex($glue));
            $this->assertParity($class, array_map(static fn (string $e): string => $e.$glue.$template, $edges), 'prefixed '.bin2hex($glue));
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => $template, 7 => 'x|1', 'a' => '', 'c' => "caf\xc3\xa9"];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items, 400 token-soup items and 300 random slices per class.
        foreach ([671, 672, 673] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6711));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbByYeEnNcCxXsStTdDvVrRiI()/0123456789|||||..--_+ :\"{}[]\0\t\r\n\x0B\x0C\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        $tokens = array_values(array_filter(self::EDGE, static fn (string $s): bool => $s !== ''));
        $tokenSoup = [];
        for ($i = 0; $i < 400; $i++) {
            $parts = [];
            for ($j = 0, $n = $random->getInt(1, 11); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, ['|', '|', '|', ' ', '.', '/', '(', '']), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6712)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
        // Repeated rows across few keys exercise the duplicate and distinct-key sets.
        $headers = [];
        for ($i = 0; $i < 400; $i++) {
            $headers[] = $this->pick($random, ['site', 'tmux', 'Post_Processing', 'xxx.'.$random->getInt(1, 3)]).'|'.$this->pick($random, ['key_a', 'Key-B', 'file (1/'.$random->getInt(1, 3).')'])
                .'|'.$this->pick($random, ['', '1', 'int', '"f.rar" yEnc']).'|'.$this->pick($random, ['', '5', '5 ', 'x']).'|'.$this->pick($random, ['', '300', '0', '1']);
        }
        $this->assertParity($class, $headers, 'clustered headers');
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W671_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // BENCHMARK_RESULTS
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 67.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6716));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W671_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6717)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6718)), true),
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
        fwrite(STDERR, "\nRustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67 speedup: {$summary}\n");
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
        $this->assertCount(self::TARGETS[$class][3] + \count($items), $php, $class.' '.$message);
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
            $fields = match ($class) {
                XxxPipe::class => $this->xxxFields($random),
                CollectionCleanupService::class => $this->cleanupFields($random),
                CollectionsCleaningService::class => $this->subjectFields($random),
                ConfigurationProvider::class => $this->providerFields($random),
                default => $this->repoFields($random),
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $fields[$random->getInt(0, \count($fields) - 1)] = $this->pick($random, ['', ' ', 'x', '-1', '1e3', "caf\xc3\xa9", "a\x01b", str_repeat('n', 300),
                    '9999999999999999', 'maybe', 'TRUE', 'bogus', '(1/2)', 'yEnc', 'App.Name', '{"a":1}', '0', '1.5', 'XXX']);
            }
            $row = implode('|', $uncommon && $random->getInt(0, 4) === 0 ? \array_slice($fields, 0, $random->getInt(1, \count($fields))) : $fields);
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 3), str_replace('.', ' ', $row), str_replace(' ', "\t\t", $row), str_replace('|', '| ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    private function xxxFields(Randomizer $random): array
    {
        $title = $this->pick($random, ['Brazzers', 'Vixen', 'TeamSkeet', 'Some.Studio', 'Random.Show', 'Linux.Distro', 'Tokyohot', 'Great.Documentary', 'MILF.Hunter'])
            .'.'.$this->pick($random, [$random->getInt(19, 26).'.'.sprintf('%02d.%02d', $random->getInt(1, 12), $random->getInt(1, 28)), 'S0'.$random->getInt(1, 9).'E0'.$random->getInt(1, 9), (string) $random->getInt(1990, 2026)])
            .'.'.$this->pick($random, ['Some.Name', 'Two.Names', 'Scene']).'.'.$this->pick($random, ['XXX', 'xxx', '', 'HDTV'])
            .'.'.$this->pick($random, ['1080p', '720p', '2160p', 'DVDR', 'DVDRip', 'WMV', 'XviD', 'MP4', 'Imageset', 'PACK']).'-'.$this->pick($random, ['GRP', 'KTR', 'iPT']);

        return [$title, $this->pick($random, ['alt.binaries.erotica', 'alt.binaries.multimedia.erotica', 'alt.binaries.teevee', 'a.b.xxx', 'alt.binaries.moovee']),
            $this->pick($random, ['Poster <p@x.com>', 'anon@y.com', 'Oz <oz@lot.com>', 'NNTP User <u'.$random->getInt(1, 99).'@host.net>'])];
    }

    /** @return list<string> */
    private function cleanupFields(Randomizer $random): array
    {
        $now = 1_761_480_000 + $random->getInt(0, 86_400);
        $hours = $this->pick($random, ['72', '72', '24', '', '168', (string) $random->getInt(1, 9000)]);
        $added = $now - $random->getInt(0, 3 * 72 * 3600) + ($random->getInt(0, 20) === 0 ? 7200 : 0);

        return [(string) $added, (string) $now, $hours, $this->pick($random, ['0', (string) $random->getInt(1, 400), (string) $random->getInt(1, 400)]),
            $this->pick($random, ['0', (string) $random->getInt(1, 50_000), (string) $random->getInt(1, 50_000)]),
            $this->pick($random, ['0', '1', '2', '3', '4', '5', '10', '15', '16']), $this->pick($random, ['1000', '', '500', (string) $random->getInt(10, 20_000)])];
    }

    /** @return list<string> */
    private function subjectFields(Randomizer $random): array
    {
        $title = $this->pick($random, ['Some.Show.S01E02', 'The.Movie', 'Artist - Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', 'WEB-DL', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb']);
        $files = $random->getInt(1, 90);
        $total = $random->getInt(1, 400);
        $subject = $this->pick($random, ['['.$random->getInt(1, $files).'/'.$files.'] - "'.$title.'.part'.$random->getInt(1, 40).'.rar" yEnc ('.$random->getInt(1, $total).'/'.$total.')',
            '"'.$title.'.mkv" yEnc ('.$random->getInt(1, $total).'/'.$total.')', $title.' - "'.$title.'.nfo" yEnc', $title.'  [Re-Post]  yEnc ('.$random->getInt(1, 3).'/3)',
            $title.' - "'.$title.'.vol0'.$random->getInt(1, 9).'+0'.$random->getInt(1, 9).'.par2" '.$random->getInt(1, 900).' MB yEnc', $title.' ('.$random->getInt(1, 9).'/9)']);

        return [$subject, $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.sounds.mp3', 'alt.binaries.sounds.flac', 'a.b.multimedia'])];
    }

    /** @return list<string> */
    private function providerFields(Randomizer $random): array
    {
        $type = $this->pick($random, ['int', 'bool', 'float', 'string', '', 'integer', 'boolean']);
        $value = match ($type) {
            'int', 'integer' => $this->pick($random, [(string) $random->getInt(-100_000, 100_000), '', ' '.$random->getInt(0, 99).' ']),
            'bool', 'boolean' => $this->pick($random, ['true', 'false', '1', '0', 'yes', 'off', '']),
            'float' => $this->pick($random, [$random->getInt(0, 999).'.'.$random->getInt(0, 99), '.5', (string) $random->getInt(0, 9), '']),
            default => $this->pick($random, ['NNTmux', 'https://example.com', (string) $random->getInt(0, 999), 'on', '']),
        };

        return [$this->pick($random, ['site', 'ingestion', 'tmux', 'post-processing', 'metadata', 'registration', '']),
            $this->pick($random, ['max_messages', 'app.name', 'tmux.'.$random->getInt(1, 40), 'part_retention_hours', 'title']), $type, $value,
            $this->pick($random, ['', '0', '20000', 'false', 'default'])];
    }

    /** @return list<string> */
    private function repoFields(Randomizer $random): array
    {
        return [$this->pick($random, ['site', 'tmux', 'ingestion', 'post-processing', 'Post_Processing', 'metadata', 'registration']),
            $this->pick($random, ['site-name', 'title', 'max_items', 'Max-Items', 'colors.'.$random->getInt(1, 20), 'part_retention_hours']),
            $this->pick($random, ['', 'old', (string) $random->getInt(0, 1000), '{"a":1}']),
            $this->pick($random, ['', '', 'new', (string) $random->getInt(0, 1000), '[1,2]']), $this->pick($random, ['', '300', '0', '3600', (string) $random->getInt(0, 100_000)])];
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
     * Calls a Wave 67.1 export by name.
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
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI is not available.');
        }
        foreach (self::TARGETS as $class => [, , $cdef, , $domain]) {
            if (RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef)) === null) {
                $this->markTestSkipped('libnntmux_pipeline is not available.');
            }
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
