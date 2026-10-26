<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Predb\Irc\PreAnnounceParser;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant and the trailer word count. */
    private const TARGETS = [
        PredbFeedEntry::class => ['w61FeedEntryBatch', 'nntmux_w613_predb_feed_entry_batch', 'W61_PREDB_FEED_ENTRY_FFI_CDEF', 44],
        PredbFeedImporter::class => ['w61ImportPlanBatch', 'nntmux_w613_predb_feed_importer_batch', 'W61_PREDB_FEED_IMPORTER_FFI_CDEF', 22],
        PredbFeedSourceFactory::class => ['w61SourcePlanBatch', 'nntmux_w613_predb_feed_source_factory_batch', 'W61_PREDB_FEED_SOURCE_FACTORY_FFI_CDEF', 31],
        IrcNetworks::class => ['w61NetworkPlanBatch', 'nntmux_w613_irc_networks_batch', 'W61_IRC_NETWORKS_FFI_CDEF', 34],
        PreAnnounceParser::class => ['w61AnnounceParseBatch', 'nntmux_w613_pre_announce_parser_batch', 'W61_PRE_ANNOUNCE_PARSER_FFI_CDEF', 29],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        PredbFeedEntry::class => true,
        PredbFeedImporter::class => true,
        PredbFeedSourceFactory::class => true,
        IrcNetworks::class => true,
        PreAnnounceParser::class => true,
    ];

    /** Trailer constants each class declares for its own batch. */
    private const TRAILER_CONSTANTS = [
        PredbFeedEntry::class => 'W61_PREDB_FEED_ENTRY_TRAILER',
        PredbFeedImporter::class => 'W61_PREDB_FEED_IMPORTER_TRAILER',
        PredbFeedSourceFactory::class => 'W61_PREDB_FEED_SOURCE_FACTORY_TRAILER',
        IrcNetworks::class => 'W61_IRC_NETWORKS_TRAILER',
        PreAnnounceParser::class => 'W61_PRE_ANNOUNCE_PARSER_TRAILER',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL, control and mIRC bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\r\n", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', '||||', '|||||',
        '||||||', '|||||||', ' | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h', '.', '..', '-', '--', '_', '[', '(', ']', ')',
        '[]', '()', '[PRE]', '(PRE)', 'PRE:', 'PRE', 'pre |', '.:::', '.::: PRE :::', 'ab', 'a-b', 'Name-GRP', 'Name-', '-GRP', 'Na me-GRP',
        'Name-GR P', 'Name-GRP,', 'Name-GRP!', 'Name.2019-GRP', 'Some_Show_S01-GRP', '[Group] Show - 01', '(Show) x', 'Show 2019', 'x_y',
        "Name\x01-GRP", 'Name-GRP|predb|TV|1MB|1|0|1700000000', '0', '00', '01', '1', '2', '3', '4', '5', '6', '9', '10', '99', '100', '-1', '+1',
        '+', '++1', '1e3', '0x10', '0.5', '1.5MB', '1.55MB', '1.555MB', '.5MB', '1.MB', '999999MB', '1000000MB', '1KB', '1kb', '1GB', '1TB',
        'MB', 'B', '1B', '67 MB', '14F', '14 F', '2 Files', '1 File', 'Filesx', '5F 158.3MB', '1234567890', '123456789', '12345678901234',
        '946684799', '946684800', '1700000000', '4102444799', '4102444800', '999999999999', '1000000000000', 'TV', 'tv-x264', 'MP3', 'FLAC',
        'GAMES', 'APPS', 'XXX', 'EBOOK', 'ABOOK', 'BLURAY', 'BLURAYS', 'X264', '0DAY', 'LONGPREFIX', '-TV', 'TV:HD', 'TV/HD', 'TV.HD',
        'predb_club', 'PREDB_CLUB', 'predb-club', 'predb club', 'predb.me', 'Predb.Me', 'srrdb.com', 'xrel.to', 'xrel_p2p', '3dsdb', 'threedsdb',
        'scene_archive_sql', 'nope', ' predb_me ', 'a_very_long_source_key_over_thirty_two', 'synirc', 'SynIRC', 'corrupt', 'net-1', 'bad name',
        'irc.example.net', 'IRC.Example.NET', 'localhost', '10.0.0.1', '10.0.0.01', '256.1.1.1', '1.2.3', '1.2.3.4.5', '1..2.3', '.host', 'host.',
        '-bad.host', 'bad-.host', 'a.b-c.d', '::1', '[::1]', 'h_st', '6667', '6697', '+6697', '+', '0', '65535', '65536', '123456', 'true',
        'TRUE', 'yes', 'on', 'false', 'No', 'OFF', 'maybe', '#pre', '&pre', '#', '#a b', "#a\x07", '#pre,#pre-info', ' #a , #b ,, ', 'pre', '#x,y',
        'corruptnet', 'zenet', 'predatabase', 'ngp', 'rizon', 'opentrackers', 'nntmux', 'CorruptNet', 'unknownfmt', "\x02", "\x03", "\x0304",
        "\x0304,12", "\x03,12", "\x03123", "\x0f\x16\x1d\x1f\x1e\x11", "\x02PRE:\x02 [FLAC] Name-GRP", "\x0304PRE:\x03 [TV] Name-GRP",
        'PRE: [FLAC] Name-GRP', 'INFO: Name-GRP [14F 67MB]', 'NUKE: Name-GRP [dupe]', '(PRE) (MP3-WEB) (Name-GRP)',
        '(INFO) (Name-GRP) (2 Files) (38 MB)', '(NUKE) (Name-GRP) (bad.rip)', 'pre | MP3-WEB | Name-GRP', 'info | Name-GRP - 14 F & 67 MB',
        'p2p | #1 | BTN | Name-GRP, WIN!', '.::: PRE ::: TV-X264-HD ::: Name-GRP :::.', '[INFO] FOR Name-GRP with 5F 158.3MB added.',
        '[PRE]  [TV-HD]  Name-GRP', '[NUKE] Name-GRP [reason] [NUKENET]', 'PRE: [FLAC', 'INFO: Name-GRP [14F', 'UNNUKE: Name-GRP', 'MODNUKE:',
        'renuke | Name-GRP | x', 'OLDNUKE: Name-GRP [old]', 'BOGUS: Name-GRP', 'PRE : [x] Name-GRP', '(PRE ) (x) (Name-GRP)', '[PRE] [] Name-GRP'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255).'-G', str_repeat('a', 254).'-G', str_repeat('a', 256), str_repeat('a', 1100), str_repeat('.', 300),
            str_repeat('|', 300), str_repeat('9', 40), str_repeat('x', 32), str_repeat('x', 33), str_repeat("\xc3\xa9", 200), str_repeat('[', 40),
            str_repeat('(x) ', 30), str_repeat('#a,', 300), str_repeat('a.', 130).'b', str_repeat('a', 63).'.b', str_repeat('a', 64).'.b',
            str_repeat("\x0304,05x", 60), 'PRE: '.str_repeat('[s] ', 20).'Name-GRP', 'INFO: Name-GRP '.str_repeat('1F ', 30), str_repeat('-', 40).'AB'];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w61_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW61RustFfi', 'withW61RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W61_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W61_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            if ($reflection->getConstant(self::TRAILER_CONSTANTS[$class]) !== $trailer) {
                $missing[] = $class.'::'.self::TRAILER_CONSTANTS[$class];
            }
            foreach (['w61RustFfiState', 'w61Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w61RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w61RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW61RustFfi');
            $with = new ReflectionMethod($class, 'withW61RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW61RustFfi/withW61RustFfi signatures';
            }
            // Wave 61 state lives in function-local statics only: the classes gain no properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w61')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
        }
        // Each class declares its own FFI declaration constant name.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w61_adds_no_properties(): void
    {
        // Property sets as of Wave 60 (static and instance), so Wave 61 cannot add state outside w61RustFfiState().
        $expected = [
            PredbFeedEntry::class => ['ffi', 'files', 'listedAt', 'nukeReason', 'nuked', 'predate', 'size', 'source', 'title', 'category', 'enrichOnly',
                'useRustFfi', 'w48EntryRustFfiHandle', 'w48EntryRustFfiOverride'],
            PredbFeedImporter::class => ['dryRunKeys', 'dryRunRows', 'ffi', 'useRustFfi', 'w462RustFfiHandle', 'w462RustFfiOverride'],
            PredbFeedSourceFactory::class => ['ffi', 'useRustFfi', 'w48SourceKeyRustFfiHandle', 'w48SourceKeyRustFfiOverride'],
            IrcNetworks::class => ['ffi', 'useRustFfi', 'w47EndpointRustFfi', 'w47Ffi'],
            PreAnnounceParser::class => ['format', 'native', 'nativeBuffer', 'nativeCapacity', 'nativeFormat', 'source', 'w451Ffi', 'w451UseRustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
        }
    }

    public function test_w61_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), $class);
            $this->assertTrue($class::withW61RustFfi(true, static fn (): bool => $class::usesW61RustFfi()));
            $this->assertFalse($class::withW61RustFfi(false, static fn (): bool => $class::usesW61RustFfi()));
            $this->assertTrue($class::withW61RustFfi(false, static fn (): bool => $class::withW61RustFfi(true, static fn (): bool => $class::usesW61RustFfi())));
            $this->assertFalse($class::withW61RustFfi(true, static fn (): bool => $class::withW61RustFfi(false, static fn (): bool => $class::usesW61RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), $class);
            try {
                $class::withW61RustFfi(! $class::usesW61RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), $class.' restores after an exception');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW61RustFfi();
            $class::withW61RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW61RustFfi(), $class.' vs '.$other);
                }
            });
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['Some.Show.S01E02-GRP|predb.net|TV-X264|1.5MB|12|2|1700000000', 'Name-GRP|1|2|0|5|1', 'predb_me|200|30|',
                'corrupt|irc.corrupt-net.org|+6697||#pre|corruptnet', "\x02PRE:\x02 [FLAC] Name-GRP", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW61RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW61RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
        // A forced Rust path still returns the PHP words when the export declines (word count mismatch through the dispatcher).
        foreach (self::TARGETS as $class => [$entry]) {
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW61RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w61_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertFalse(PredbFeedEntry::usesRustFfi());
        $this->assertSame(PredbFeedEntry::W48_ENTRY_RUST_FFI_DEFAULT, PredbFeedEntry::usesW48EntryRustFfi());
        $this->assertFalse(PredbFeedImporter::usesRustFfi());
        $this->assertSame(PredbFeedImporter::W462_RUST_FFI_DEFAULT, PredbFeedImporter::usesW462RustFfi());
        $this->assertSame(PredbFeedSourceFactory::W48_SOURCE_KEY_RUST_FFI_DEFAULT, PredbFeedSourceFactory::usesW48SourceKeyRustFfi());
        $this->assertSame(IrcNetworks::W47_ENDPOINT_RUST_FFI_DEFAULT, IrcNetworks::usesW47EndpointRustFfi());
        $this->assertSame(PreAnnounceParser::W451_RUST_FFI_DEFAULT, PreAnnounceParser::usesW451RustFfi());
        // Existing helpers behave as before, whatever the Wave 61 toggles.
        foreach ([true, false] as $enabled) {
            PredbFeedEntry::withW61RustFfi($enabled, function (): void {
                $this->assertSame('1.5MB', PredbFeedEntry::sizeFromMegabytesPhp(1.5));
                $this->assertSame('512KB', PredbFeedEntry::sizeFromMegabytesPhp(0.5));
                $this->assertSame('12', PredbFeedEntry::filesFromCountPhp('12'));
                $this->assertNull(PredbFeedEntry::filesFromCountPhp(0));
                $summary = PredbFeedEntry::summarizeEntryBatchPhp([new PredbFeedEntry('Name-GRP', 'x', 'TV', '1MB', '2')]);
                $this->assertSame([1, 1, 1024, 2], [$summary['total'], $summary['category_tv'], $summary['size_kb'], $summary['files_total']]);
            });
        }
        $this->assertSame(['total' => 2, 'invalid' => 0, 'empty' => 0, 'overlength' => 0, 'padded' => 1, 'duplicate' => 1, 'accepted' => 1],
            PredbFeedImporter::summarizeTitleBatchPhp(['Name-GRP', ' Name-GRP']));
        $this->assertSame(1, PredbFeedSourceFactory::classifySourceKeyBatchPhp(['predb_me'])['exact']);
        $this->assertSame([1 | 1 << 2 | 6667 << 8, crc32('irc.example.net:6667')], IrcNetworks::routeEndpointsPhp(['irc.example.net']));
        $entry = (new PreAnnounceParser('corruptnet', 'corrupt'))->parse('PRE: [FLAC] Name-GRP', CarbonImmutable::createFromTimestampUTC(0));
        $this->assertNotNull($entry);
        $this->assertSame(['Name-GRP', 'FLAC', Predb::PRE_NONUKE], [$entry->title, $entry->category, $entry->nuked]);
        $this->assertSame(1, (new PreAnnounceParser('zenet', 'zenet'))->summarizeAnnounceBatchPhp(['(INFO) (Name-GRP) (2 Files) (38 MB)'])['info_count']);
    }

    public function test_w61_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('predb_w613', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = ['Name-GRP|predb|TV|1MB|2|0|1700000000', 'PRE: [TV] Name-GRP'];
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

    public function test_w61_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = ['Some.Show.S01E02-GRP|predb.net|TV-X264|1.5MB|12|2|1700000000', 'Name-GRP|yes|2|0|5|1', 'Predb-Club|0|x|5',
                'synirc|irc.synirc.net||yes|#pre|', '(INFO) (Name-GRP) (2 Files) (38 MB)', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w61Invoke');
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
            'entry: scene title, spaced duplicate' => [PredbFeedEntry::class,
                ['Some.Show.S01E02-GRP|predb.net|TV-X264|1.5MB|12|2|1700000000', 'Some show s01e02-grp|x|||0|9|1'],
                [0 => 3 | 1 << 2 | 1 << 5 | 1 << 7 | 1 << 11 | 1 << 13 | 1 << 15 | 1 << 18 | 20 << 21 | 12 << 31,
                    1 => 3 | 2 << 2 | 1 << 5 | 2 << 13 | 3 << 15 | 2 << 18 | 1 << 20 | 20 << 21, 2 => 0, 3 => 0, 4 => 0, 5 => 2,
                    37 => 1, 38 => 0, 39 => 1, 40 => 2, 41 => 1536, 42 => 1536, 43 => 12, 44 => 1700000000, 45 => 1700000000]],
            'importer: insert, duplicate, nuke, fill and reject' => [PredbFeedImporter::class,
                ['Name-GRP|0', ' name-grp |1', 'Other-GRP|yes|2|0', 'Third-GRP|1|0|0|5|1', '|x'],
                [0 => 2 | 1 << 5 | 8 << 13, 1 => 1 | 1 << 5 | 1 << 11 | 8 << 13, 2 => 3 | 1 << 5 | 1 << 7 | 9 << 13, 3 => 4 | 1 << 5 | 4 << 8 | 9 << 13,
                    4 => 1 << 3 | 2 << 5 | 1 << 12, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 0, 23 => 1, 24 => 26, 25 => 1, 26 => 3]],
            'source factory: exact rss, normalized, alias and unknown' => [PredbFeedSourceFactory::class,
                ['predb_me|200|30|', 'Predb-Club', 'srrdb.com|0|x|5', 'nope'],
                [0 => 1 | 3 << 3 | 1 << 9 | 16 << 11 | 1 << 18 | 1 << 20 | 1 << 22 | 50 << 26 | 30 << 36 | 8 << 44,
                    1 => 2 | 1 << 3 | 2 << 18 | 100 << 26 | 15 << 36 | 10 << 44,
                    2 => 3 | 6 << 3 | 32 << 11 | 3 << 18 | 2 << 20 | 3 << 22 | 2 << 24 | 1 << 26 | 15 << 36 | 9 << 44, 3 => 4 | 100 << 26 | 15 << 36 | 4 << 44,
                    30 => 1 | 1 << 2 | 1 << 5, 32 => 30, 34 => 2000 + 1000 + 3000]],
            'irc networks: scene bot with tls port and legacy bot' => [IrcNetworks::class,
                ['corrupt|irc.corrupt-net.org|+6697||#pre,#pre-info|corruptnet', 'synirc|irc.synirc.net||yes|#pre|', 'x|-bad|1||#a|zenet'],
                [0 => 1 | 1 << 4 | 1 << 6 | 6697 << 8 | 1 << 26 | 1 << 27 | 4 << 31 | 2 << 34 | 19 << 50,
                    1 => 1 | 1 << 2 | 1 << 4 | 6697 << 8 | 1 << 24 | 1 << 26 | 2 << 31 | 1 << 34 | 14 << 50, 25 => 1, 27 => 1, 29 => 1, 31 => 2,
                    32 => 4, 33 => 0, 34 => 0, 35 => 2, 36 => 1]],
            'pre announce: coded PRE, zenet INFO and noise' => [PreAnnounceParser::class,
                ["\x02PRE:\x02 [FLAC] Name-GRP", '(INFO) (Name-GRP) (2 Files) (38 MB)', 'nonsense', 'info | Name-GRP - 14 F & 67.5 MB',
                    '.::: PRE ::: TV-X264-HD ::: Other-GRP :::.', 'NUKE: Name-GRP [dupe.rip]'],
                [0 => 1 | 1 << 3 | 1 << 7 | 2 << 8 | 1 << 16 | 4 << 17 | 1 << 58, 1 => 2 | 3 << 3 | 1 << 16 | 1 << 23 | 2 << 24 | 1 << 40 | 38 << 41 | 1 << 58,
                    2 => 0, 3 => 3 | 3 << 3 | 1 << 16 | 1 << 23 | 14 << 24 | 1 << 40 | 67 << 41 | 1 << 58,
                    4 => 4 | 1 << 3 | 1 << 16 | 10 << 17 | 1 << 58, 5 => 1 | 4 << 3 | 1 << 16 | 1 << 57 | 1 << 58, 21 => 5, 26 => 16, 27 => 3800 + 6750,
                    29 => 1, 30 => 2, 31 => 2, 32 => 2, 33 => 42]],
            'entry empty batch' => [PredbFeedEntry::class, [], array_fill(0, 44, 0)],
            'announce empty batch' => [PreAnnounceParser::class, [], array_fill(0, 29, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['Name-GRP|predb|TV|1MB|2|0|1700000000', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['PRE: [TV] Name-GRP|predb_me|1|yes|#pre|ngp', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position.
        $edges = self::edgeCases();
        for ($slot = 0; $slot < 7; $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $fields = ['Name-GRP', 'predb', 'TV', '1MB', '2', '0', '1700000000'];
                $fields[$slot] = $edge;
                $rows[] = implode('|', $fields);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Name-GRP|x', 7 => 'PRE: [TV] Name-GRP', 'a' => '', 'c' => 'predb_me|10'];
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
        foreach ([611, 612, 613] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6133));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBpPrReEiInNfFoOmMgG0123456789|||..::-_ #&+,!()[]\0\t\n\r\x02\x03\x0f\x1f\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6134)), true), 0, 80));
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
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W613_BENCH_ROUNDS
        // interleaved paired rounds per helper and workload (default 301, minimum 60), 5 iterations per round).
        // Common = one batch of 320 well-formed items of the class's format; uncommon = 320 items mixing edge
        // cases, malformed, long, NUL, CR/LF, mIRC-coded, case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~6.8-7.4), common mean / p99, uncommon mean / p99:
        // - PredbFeedEntry:         3.87x / 3.20x, 3.98x / 3.52x
        // - PredbFeedImporter:      5.77x / 5.44x, 5.08x / 4.07x
        // - PredbFeedSourceFactory: 10.55x / 8.76x, 6.83x / 5.53x
        // - IrcNetworks:            5.67x / 5.14x, 7.23x / 5.43x
        // - PreAnnounceParser:      5.79x / 5.15x, 6.60x / 4.68x
        // Every helper clears every gate in all three runs and defaults to Rust. Each helper defaults to Rust only
        // when it clears 1.5x on both workloads' mean and p99, per .ai/rules/rust-performance.md; the assertion
        // below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W613_BENCH=1 php artisan test --compact tests/Unit/RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W613_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W613_BENCH=1 to run the Wave 61.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6136));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W613_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6137)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6138)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW61RustFfi($useRust, static fn (): array => $class::$entry($items)));
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
        fwrite(STDERR, "\nRustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW61RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW61RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW61RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL, mIRC and non-ASCII bytes.
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
                PredbFeedEntry::class => $this->entryItem($random, $uncommon),
                PredbFeedImporter::class => $this->importItem($random, $uncommon),
                PredbFeedSourceFactory::class => $this->sourceItem($random, $uncommon),
                IrcNetworks::class => $this->networkItem($random, $uncommon),
                default => $this->announceItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x0304".$row, $row."\r\n"]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function releaseName(Randomizer $random): string
    {
        return $this->pick($random, [
            'The.Expanse.S0'.$random->getInt(1, 6).'E'.str_pad((string) $random->getInt(1, 13), 2, '0', STR_PAD_LEFT).'.1080p.WEB-DL.DDP5.1.H.264-NTb',
            'Dune.Part.Two.'.$random->getInt(1990, 2026).'.2160p.UHD.BluRay.x265-TERMiNAL', 'Severance.S02.Complete.720p.AMZN.WEBRip-GRP',
            'Pink_Floyd-The_Dark_Side_Of_The_Moon-'.$random->getInt(1970, 2024).'-FLAC', 'Some.Movie.'.$random->getInt(1950, 2026).'.German.DL.1080p.BluRay-GRP',
            'Artist-Album-WEB-'.$random->getInt(2000, 2026).'-ENRiCH', 'Show.Name.S1.PROPER.480p.HDTV.x264-LOL', 'Game.Title.PS5-DUPLEX',
            'Microsoft.Office.2024.x64-AiO', 'Linux_Mint_22_iso-GRP', 'VA-Club_Hits_'.$random->getInt(2000, 2026).'-WEB-'.$random->getInt(2000, 2026).'-OMA',
        ]);
    }

    private function entryItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->releaseName($random);
        $source = $this->pick($random, ['predb.net', 'predb_club', 'srrdb', 'corrupt-net', 'zenet', 'xrel', 'opentrackers-relay', '']);
        $category = $this->pick($random, ['TV-X264', 'TV-HD', 'X265', 'MP3', 'FLAC', 'GAMES-PS5', 'APPS', 'XXX', 'EBOOK', 'MOVIES-UHD', '0DAY', '']);
        $size = $this->pick($random, ['', $random->getInt(1, 999).'MB', $random->getInt(1, 99).'.'.$random->getInt(0, 99).'GB', $random->getInt(100, 9999).'KB',
            $random->getInt(1, 900).'.'.$random->getInt(0, 9).'MB']);
        $files = $this->pick($random, ['', (string) $random->getInt(1, 200), '1']);
        $nuked = $this->pick($random, ['0', '0', '', (string) $random->getInt(1, 5)]);
        $predate = $this->pick($random, ['', (string) $random->getInt(1_000_000_000, 1_800_000_000)]);
        if ($noisy) {
            $title = $this->pick($random, [$title, '', 'Bad Title 2019', '[Grp] Show - 01', "Bad\x01Title-GRP", str_repeat('a', 300), 'No_Group_Here']);
            $source = $this->pick($random, [$source, 'bad source', str_repeat('s', 70), 'UPPER.Source']);
            $size = $this->pick($random, [$size, '1.234MB', '1TB', '.5MB', 'big', '1000000MB']);
            $files = $this->pick($random, [$files, '0', 'many', '1234567890', '-1']);
            $nuked = $this->pick($random, [$nuked, '9', '-1', 'nuked', '123']);
            $predate = $this->pick($random, [$predate, '1', '9999999999', '1e9', '1700000000.5']);
        }

        return implode('|', [$title, $source, $category, $size, $files, $nuked, $predate]);
    }

    private function importItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->pick($random, [$this->releaseName($random), $this->releaseName($random), 'Repeat.Title-GRP']);
        $existing = $this->pick($random, ['0', '1', '', 'yes', 'no', 'true']);
        $nuked = $this->pick($random, ['0', '0', '', (string) $random->getInt(1, 5)]);
        $prior = $this->pick($random, ['0', '', (string) $random->getInt(1, 5)]);
        $details = (string) $random->getInt(0, 7);
        $known = (string) $random->getInt(0, 7);
        if ($noisy) {
            $title = $this->pick($random, [$title, '', ' '.$title.' ', "Bad\x02Title", str_repeat('t', 260), strtoupper($title)]);
            $existing = $this->pick($random, [$existing, 'maybe', 'ON', '2']);
            $nuked = $this->pick($random, [$nuked, '123', 'x', '-1']);
            $details = $this->pick($random, [$details, '8', '12', 'x']);
        }

        return implode('|', [$title, $existing, $nuked, $prior, $details, $known]);
    }

    private function sourceItem(Randomizer $random, bool $noisy): string
    {
        $key = $this->pick($random, ['predb_club', 'predb_net', 'predb_me', 'predb_org', 'predatabase', 'srrdb', 'xrel', 'xrel_p2p', 'scnsrc_me', 'eztv',
            'predb_ovh', 'apibay', 'nfodb', 'one_techno', 'hdencode', 'nima4k', 'g4u', 'nswdb', '3dsdb', 'knaben', 'crowdnfo', 'nzedb_archive',
            'scene_archive_sql', 'Predb-Club', 'PREDB ME', 'predb.me', 'srrdb.com', 'xrel.to']);
        $page = $this->pick($random, ['', '100', (string) $random->getInt(1, 1000), '50']);
        $timeout = $this->pick($random, ['', '15', (string) $random->getInt(1, 120)]);
        $body = $this->pick($random, ['', '1048576', (string) $random->getInt(1024, 67108864)]);
        if ($noisy) {
            $key = $this->pick($random, [$key, '', 'unknown_source', str_repeat('k', 40), ' '.$key.' ', 'predb__club', "pr\xc3\xa9db"]);
            $page = $this->pick($random, [$page, '0', '5000', 'x', '1234567890']);
            $timeout = $this->pick($random, [$timeout, '0', '999', '-5']);
            $body = $this->pick($random, [$body, '1', '999999999', 'big']);
        }

        return implode('|', [$key, $page, $timeout, $body]);
    }

    private function networkItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, ['synirc', 'corruptnet', 'zenet', 'efnet', 'predatabase', 'ngp', 'rizon', 'opentrackers', 'abjects']);
        $host = $this->pick($random, ['irc.corrupt-net.org', 'irc.zenet.org', 'irc.efnet.org', 'irc.predataba.se', 'irc.ngp.re', 'irc.rizon.net',
            '192.168.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254), 'irc.synirc.net']);
        $port = $this->pick($random, ['', '6667', '6697', '+6697', (string) $random->getInt(1024, 65535)]);
        $tls = $this->pick($random, ['', 'true', 'false', '1', '0']);
        $channels = $this->pick($random, ['#pre', '#pre,#pre-info', '#pre-all,#p2p-pre', '&local', '#ngpre', '']);
        $format = $this->pick($random, ['corruptnet', 'zenet', 'predatabase', 'ngp', 'rizon', 'opentrackers', 'nntmux', '']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'Bad Name', str_repeat('n', 40), 'SYNIRC']);
            $host = $this->pick($random, [$host, '', '-bad.host', '1.2.3', '256.0.0.1', '10.0.0.01', 'host..x', str_repeat('a', 64).'.net', 'h_st']);
            $port = $this->pick($random, [$port, '0', '70000', '+', 'abc', '123456']);
            $tls = $this->pick($random, [$tls, 'maybe', 'YES', 'Off']);
            $channels = $this->pick($random, [$channels, 'pre', '#', '#a b,#c', str_repeat('#x,', 50), ' #a , ,#b ']);
            $format = $this->pick($random, [$format, 'unknown', 'CORRUPTNET', 'irc']);
        }

        return implode('|', [$name, $host, $port, $tls, $channels, $format]);
    }

    private function announceItem(Randomizer $random, bool $noisy): string
    {
        $title = $this->releaseName($random);
        $section = $this->pick($random, ['TV-X264', 'MP3-WEB', 'FLAC', 'X265', 'GAMES', 'TV-HD', 'EBOOK', 'APPS']);
        $files = (string) $random->getInt(1, 120);
        $size = $random->getInt(1, 9000).$this->pick($random, ['', '.'.$random->getInt(0, 9), '.'.$random->getInt(10, 99)]);
        $line = $this->pick($random, [
            "PRE: [{$section}] {$title}", "INFO: {$title} [{$files}F {$size}MB]", "NUKE: {$title} [dupe.{$random->getInt(1, 9)}]",
            "(PRE) ({$section}) ({$title})", "(INFO) ({$title}) ({$files} Files) ({$size} MB)", "(UNNUKE) ({$title}) (fixed)",
            "pre | {$section} | {$title}", "info | {$title} - {$files} F & {$size} MB", "p2p | #1 | BTN | {$title}, WIN!",
            ".::: PRE ::: {$section} ::: {$title} :::.", "[INFO] FOR {$title} with {$files}F {$size}MB added.", "[PRE]  [{$section}]  {$title}",
            "[NUKE] {$title} [bad.rip] [NUKENET]",
        ]);
        if ($random->getInt(0, 2) === 0) {
            $line = $this->pick($random, ["\x02".$line, "\x0304".$line."\x0f", "\x0303,01".str_replace(' ', " \x02", $line), "\x1f".$line."\x1f"]);
        }
        if ($noisy) {
            $line = $this->pick($random, [$line, '', 'PRE: [FLAC', 'INFO: '.$title, 'PRE: [x] Bad Title', "\x03\x03\x03".$line, $line."\nPRE: [x] y-z",
                str_repeat('[x] ', 30).$title, 'BOGUS: '.$title, "INFO: {$title} [".str_repeat('9', 12).'F 1MB]']);
        }

        return $line;
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
     * Calls a Wave 61.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w61RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W61_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('predb_w613', PreAnnounceParser::W61_PRE_ANNOUNCE_PARSER_FFI_CDEF) === null) {
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
