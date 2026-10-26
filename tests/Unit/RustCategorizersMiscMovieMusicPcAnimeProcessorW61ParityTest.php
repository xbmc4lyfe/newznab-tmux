<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnimeProcessor;
use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\Categorizers\MiscCategorizer;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\Categorizers\MusicCategorizer;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\ReleaseContext;
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

final class RustCategorizersMiscMovieMusicPcAnimeProcessorW61ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        MiscCategorizer::class => ['w61MiscTokenBatch', 'nntmux_w611_misc_token_batch', 'W61_MISC_CATEGORIZER_FFI_CDEF', 29, 'categorize_w611'],
        MovieCategorizer::class => ['w61MovieTokenBatch', 'nntmux_w611_movie_token_batch', 'W61_MOVIE_CATEGORIZER_FFI_CDEF', 42, 'categorize_w611'],
        MusicCategorizer::class => ['w61MusicTokenBatch', 'nntmux_w611_music_token_batch', 'W61_MUSIC_CATEGORIZER_FFI_CDEF', 32, 'categorize_w611'],
        PcCategorizer::class => ['w61PcTokenBatch', 'nntmux_w611_pc_token_batch', 'W61_PC_CATEGORIZER_FFI_CDEF', 37, 'categorize_w611'],
        AnimeProcessor::class => ['w61AnimeReleasePlanBatch', 'nntmux_w611_anime_release_plan_batch', 'W61_ANIME_PROCESSOR_FFI_CDEF', 35, 'anime_w611'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        MiscCategorizer::class => true,
        MovieCategorizer::class => true,
        MusicCategorizer::class => true,
        PcCategorizer::class => true,
        AnimeProcessor::class => true,
    ];

    /** The targets that extend AbstractCategorizer and inherit its Wave 60 toggle. */
    private const CATEGORIZERS = [MiscCategorizer::class, MovieCategorizer::class, MusicCategorizer::class, PcCategorizer::class];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r\n", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', ' | | ', "\t|\t|\t",
        "\0|\0|\0", 'a|b|c|d', '.', '..', '-', '_', '[', ']', '[]', '[a]', '[[a]]', '(', ')', '()', ' - ', ' - 1', ' - 12345', 'x - 0001',
        'x -  1', 'x - 1a', '0', '00', '1', '19', '1899', '1900', '1999', '2000', '2099', '2100', '12019', '20191', 'x2019x', 'abc', 'a.b',
        'a b', 'a_b', 'd41d8cd98f00b204e9800998ecf8427e', 'D41D8CD98F00B204E9800998ECF8427E', 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', '0123456789abcdef', '0123456789abcde', 'abcdefabcdefabcdef',
        '123e4567-e89b-12d3-a456-426614174000', '123e4567-e89b-12d3-a456-42661417400g', '123e4567e89b-12d3-a456-4266141740000',
        'x.rar', 'x.RAR', 'x.7z', 'x.par2', 'x.001', 'x.0001', 'x.r00', 'x.nfo', 'x.csv', 'x.toolong', 'x.', '.x', 'x.e-b', 'x.r_r',
        'bcdfghjklmnp', 'bcdfghjklm', 'bcdfghjkl', 'rhythmrhythm', 'bcdfgh1234', 'Ab1Cd2Ef3Gh4Ij5Kl6Mn7', 'AbCdEfGhIjKlMnOpQrStUv',
        'part1', 'part01', 'part0001', 'PART12', 'vol', 'dataset', 'dump', 'sample', 'password', 'repost', 'S01E02', 's1e1', 'S100E1000',
        'S1000E1', 's01e12345', 'Show.S01E02', '1x18', '12x123', '123x12', '1x1234', '1920x1080', '1280X720', '720x480', '640x360',
        '1080p', '2160P', '720p', '480p', '576p', '1080i', '4k', '4K', 'uhd', 'UHD', 'web', 'WEB-DL', 'webrip', 'bluray', 'Blu-Ray',
        'bd25', 'remux', 'dvd', 'dvd9', 'dvdr', 'cam', 'ts', 'hdts', '3d', '3D.SBS', 'x264', 'x265', 'H.265', 'hevc', 'avc', 'xvid',
        'divx', 'hdr10', 'dv', 'german', 'castellano', 'trailer', 'teaser', 'dts', 'atmos', '1', 'true', 'TRUE', 'yes', 'on', 'On', '0',
        'false', 'no', 'off', '01', 'maybe', 'flac', 'FLAC', 'mp3', 'aac', 'ogg', '320kbps', '320K', '96kbps', '9kbps', '12345kbps',
        '128kbit', '320kb', '44khz', '192khz', '1234khz', '24bit', '16BIT', '123bit', 'vbr', 'audiobook', 'm4b', 'podcast', 'mvid',
        'concert', 'kpop', 'discography', 'vinyl', 'cdm', 'single', 'ep', 'va', 'x86', 'x64', 'win32', 'win64', 'amd64', 'arm', 'arm64',
        'v1', 'v2024', 'v12345', 'V2', 'v', 'multi', 'multi5', 'MULTi12', 'multi123', 'iso', 'dmg', 'macos', 'ios', 'ipa', 'apk',
        'android', 'game', 'gog', 'fitgirl', 'codex', 'windows', 'linux', 'adobe', 'udemy', 'tutorial', 'keygen', 'crack', 'ps4', 'switch',
        '0day', 'movie', 'ova', 'special', 'batch', 'complete', 'bd', 'cr', '10bit', 'dub', 'dual', 'sub', 'eng', 'raw', 'v3', 'v9', 'v0',
        'v10', 'ep', 'ep12', 'Ep 12', 'episode 7', 'episode', 'e5', 'e12345', 'E01', '[ABCD1234]', '(abcd1234)', '[ABCD123]', '[ABCD12345]',
        '[ABCD1234)', '[GHIJ1234]', '[SubsPlease] Title - 01 (1080p) [ABCD1234].mkv', '[Grp][Grp2] Title', '[Grp] [Grp2]  Title',
        '[unclosed Title', '[] Title', '_ . [Grp] Title_-_01', 'Title - 01 - Name', 'Title (2019) - 03', 'Title [Grp] - 04', 'Title.E01.720p',
        'Title EP01', 'Title Episode 1080p', '[Grp] Title S2 - 05', 'x.gz', 'x.tgz', 'x.json', 'x.DB', 'x.md5', 'bd50', 'hsbs', 'dvdscr', 'hdcam',
        'telesync', 'ddp5', 'unabridged', 'videoclips', 'schlager', 'cassette', 'maxi', 'various', 'aarch64', '32bit', '64bit', 'appimage',
        'pluralsight', 'activator', 'zeroday', 'gekijouban', 'bdremux', 'crunchyroll', 'hi10p', 'dualaudio', 'multisub'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 1100), str_repeat('.', 300), str_repeat('a.', 20),
            str_repeat('bcdfg', 40), str_repeat('S01E02 ', 50), str_repeat('|', 300), str_repeat('9', 40), str_repeat('f', 64), str_repeat('f', 65),
            str_repeat('[a]', 40).'Title', str_repeat(' - 1', 30), str_repeat("\xc3\xa9", 200), str_repeat('flac ', 100), str_repeat('a1', 60),
            str_repeat('x', 63), str_repeat('x', 64), '['.str_repeat('g', 40).'] Title', str_repeat('v2 ', 40), str_repeat('1080p ', 70)];
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
            // Toggle state lives in function-local statics only, never in static properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w61')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \count($class::{$entry.'Php'}([])), $class);
        }
        // Each class declares its own FFI declaration constant name, so none shadows a sibling's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w61_toggles_are_independent_of_abstract_w60(): void
    {
        foreach (self::CATEGORIZERS as $class) {
            $this->assertSame(AbstractCategorizer::class, get_parent_class($class));
            // The categorizers keep inheriting the AbstractCategorizer Wave 60 toggle and default.
            $this->assertSame(AbstractCategorizer::class, (new ReflectionMethod($class, 'usesW60RustFfi'))->getDeclaringClass()->getName(), $class);
            $this->assertSame(AbstractCategorizer::class, (new ReflectionMethod($class, 'withW60RustFfi'))->getDeclaringClass()->getName(), $class);
            $this->assertSame(AbstractCategorizer::class, (new ReflectionClass($class))->getReflectionConstant('W60_RUST_FFI_DEFAULT')?->getDeclaringClass()->getName(), $class);
            $this->assertFalse((new ReflectionClass(AbstractCategorizer::class))->hasMethod('usesW61RustFfi'));
        }
        foreach ([true, false] as $enabled) {
            // Flipping the parent's Wave 60 toggle leaves every Wave 61 toggle at its default.
            foreach (['withW60RustFfi', 'withAbstractCategorizerW60RustFfi'] as $parentToggle) {
                AbstractCategorizer::$parentToggle($enabled, function () use ($enabled): void {
                    $this->assertSame($enabled, AbstractCategorizer::usesW60RustFfi());
                    foreach (array_keys(self::TARGETS) as $class) {
                        $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), $class);
                    }
                    foreach (self::CATEGORIZERS as $class) {
                        $this->assertSame($enabled, $class::usesW60RustFfi(), $class.' follows the parent Wave 60 toggle');
                    }
                });
            }
            // Flipping a Wave 61 toggle leaves the parent's Wave 60 toggle, the siblings and the parent batch alone.
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW61RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW61RustFfi(), $class);
                    $this->assertSame(AbstractCategorizer::W60_RUST_FFI_DEFAULT, AbstractCategorizer::usesW60RustFfi(), $class);
                    $this->assertSame(AbstractCategorizer::W60_RUST_FFI_DEFAULT, AbstractCategorizer::usesAbstractCategorizerW60RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW61RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // The parent's Wave 60 batch still dispatches on the parent toggle when called through a categorizer.
        $items = ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', ''];
        $php = AbstractCategorizer::w60CategorizerGateBatchPhp($items);
        $this->assertSame($php, MovieCategorizer::withW61RustFfi(false, static fn (): array => MovieCategorizer::w60CategorizerGateBatch($items)));
        $this->assertSame($php, PcCategorizer::withW61RustFfi(true, static fn (): array => PcCategorizer::w60CategorizerGateBatch($items)));
    }

    public function test_w61_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W61_RUST_FFI_DEFAULT'), $class);
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
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['Dune.2021.2160p.UHD.BluRay.x265-GRP|1|0', 'Artist-Album-2020-320kbps-MP3', 'Adobe.Photoshop.v2024.x64.Incl.Keygen',
                '[SubsPlease] Frieren - 12 (1080p) [ABCD1234].mkv', 'd41d8cd98f00b204e9800998ecf8427e', ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW61RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW61RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w61_existing_public_api_is_unchanged(): void
    {
        $this->assertSame(['total' => 2, 'hash' => 1, 'archive' => 1, 'dataset' => 0, 'other' => 0, 'empty' => 0],
            MiscCategorizer::summarizeMiscContentBatchPhp(['d41d8cd98f00b204e9800998ecf8427e', 'Backup.rar']));
        $this->assertSame(['total' => 1, 'uhd' => 1, 'hd' => 0, 'sd' => 0, 'unknown' => 0, 'hevc' => 1, 'bluray' => 1],
            MovieCategorizer::summarizeMovieQualityBatchPhp(['Dune.2021.2160p.UHD.BluRay.x265-GRP']));
        $this->assertSame(1, MusicCategorizer::summarizeMusicContentBatchPhp(['Artist-Album-2020-FLAC'])['lossless']);
        $this->assertSame(1, PcCategorizer::summarizePcPlatformBatchPhp(['Adobe.Photoshop.2024.x64-GRP'])['zeroday']);
        $this->assertSame(1, AnimeProcessor::summarizeAnimeTitleBatchPhp(['[Grp] Frieren - 12 (1080p)'])['episode_tagged_count']);
        // Earlier-wave toggles keep their own defaults.
        $this->assertSame(MiscCategorizer::W423_RUST_FFI_DEFAULT, MiscCategorizer::usesW423RustFfi());
        $this->assertSame(MovieCategorizer::W423_RUST_FFI_DEFAULT, MovieCategorizer::usesW423RustFfi());
        $this->assertSame(MusicCategorizer::W433_RUST_FFI_DEFAULT, MusicCategorizer::usesW433RustFfi());
        $this->assertSame(PcCategorizer::W433_RUST_FFI_DEFAULT, PcCategorizer::usesW433RustFfi());
        $this->assertSame(AnimeProcessor::W414_RUST_FFI_DEFAULT, AnimeProcessor::usesW414RustFfi());
        $this->assertSame(AbstractCategorizer::W60_RUST_FFI_DEFAULT, MiscCategorizer::usesW60RustFfi());
        $this->assertSame('hash_md5', (new MiscCategorizer)->categorizePhp('d41d8cd98f00b204e9800998ecf8427e')->matchedBy);
        $this->assertSame('uhd', (new MovieCategorizer)->categorizePhp(new ReleaseContext('Dune.2021.2160p.UHD.BluRay.x265-GRP', 1))->matchedBy);
        $this->assertSame('mac', (new PcCategorizer)->categorizePhp(new ReleaseContext('Adobe.Photoshop.2024.MacOSX-GRP', 1))->matchedBy);
        $this->assertNotSame('', (new MusicCategorizer)->categorizePhp(new ReleaseContext('Artist-Album-2020-FLAC-GRP', 1))->matchedBy);
    }

    public function test_w61_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer, $domain]) {
            $ffi = RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef));
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = ['Dune.2021.2160p.UHD.BluRay.x265-GRP|1|0', '[Grp] Title - 01'];
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
            $items = ['Dune.2021.2160p.UHD.BluRay.x265-GRP|1|0', 'Artist-Album-2020-320kbps-MP3|yes', 'Adobe.Photoshop.v2024.x64.Incl.Keygen',
                '[SubsPlease] Frieren - 12 (1080p) [ABCD1234].mkv', 'd41d8cd98f00b204e9800998ecf8427e', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w61Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $misc = MiscCategorizer::w61MiscTokenBatchPhp(['d41d8cd98f00b204e9800998ecf8427e', 'Backup.Files.rar', 'Ab1Cd2Ef3Gh4Ij5Kl6Mn7', 'bcdfghjklmnp.mkv']);
        $this->assertSame([1, 1, 0, 950], [$bits($misc[0], 0, 3), $bits($misc[0], 3, 3), $bits($misc[0], 6, 3), $bits($misc[0], 39, 10)]);
        $this->assertSame([4, 0, 1, 1, 600, 3, 16], [$bits($misc[1], 0, 3), $bits($misc[1], 3, 3), $bits($misc[1], 6, 3), $bits($misc[1], 9, 8), $bits($misc[1], 39, 10),
            $bits($misc[1], 49, 6), $bits($misc[1], 55, 8)]);
        $this->assertSame([3, 13, 7], [$bits($misc[2], 0, 3), $bits($misc[2], 17, 6), $bits($misc[2], 31, 8)]);
        $this->assertSame([2, 64, 5], [$bits($misc[3], 0, 3), $bits($misc[3], 9, 8), $bits($misc[3], 6, 3)]);
        $this->assertSame([0, 1, 1, 1], \array_slice($misc, 4, 4), 'rule counts');
        $this->assertSame(2, $misc[4 + 28], 'distinct extensions');

        $movie = MovieCategorizer::w61MovieTokenBatchPhp(['Dune.2021.2160p.UHD.BluRay.x265-GRP|0|1', 'Film.1999.German.720p.x264|yes', 'Show.S01E02.720p',
            'Film.2010.WEB-DL.1080p|0|on', 'Film.2010.WEB-DL|0|0']);
        $this->assertSame([3, 5, 1, 2021 - 1899, 0, 1, 900], [$bits($movie[0], 0, 4), $bits($movie[0], 4, 3), $bits($movie[0], 7, 2), $bits($movie[0], 25, 8),
            $bits($movie[0], 33, 1), $bits($movie[0], 34, 1), $bits($movie[0], 35, 10)]);
        $this->assertSame([1, 3, 2, 1], [$bits($movie[1], 0, 4), $bits($movie[1], 4, 3), $bits($movie[1], 7, 2), $bits($movie[1], 33, 1)]);
        $this->assertSame([0, 1], [$bits($movie[2], 0, 4), $bits($movie[2], 24, 1)]);
        $this->assertSame([7, 850], [$bits($movie[3], 0, 4), $bits($movie[3], 35, 10)]);
        $this->assertSame([8, 800], [$bits($movie[4], 0, 4), $bits($movie[4], 35, 10)]);
        $this->assertSame([4, 1999, 2021, 3350, 3], \array_slice($movie, 5 + 37, 5), 'year and confidence trailer');

        $music = MusicCategorizer::w61MusicTokenBatchPhp(['Artist-Album-2020-320kbps-MP3', 'Artist - Album (FLAC 24bit 96kHz)', 'Stephen.King-The.Stand.Unabridged.m4b',
            'Artist-Album-WEB-2019-GRP', 'Kpop Group - Album FLAC|1']);
        $this->assertSame([6, 2, 320, 2020 - 1899, 900], [$bits($music[0], 0, 3), $bits($music[0], 3, 2), $bits($music[0], 21, 12), $bits($music[0], 33, 8), $bits($music[0], 41, 10)]);
        $this->assertSame([5, 1, 950], [$bits($music[1], 0, 3), $bits($music[1], 3, 2), $bits($music[1], 41, 10)]);
        $this->assertSame([1, 850], [$bits($music[2], 0, 3), $bits($music[2], 41, 10)]);
        $this->assertSame([7, 700], [$bits($music[3], 0, 3), $bits($music[3], 41, 10)]);
        $this->assertSame([3, 800], [$bits($music[4], 0, 3), $bits($music[4], 41, 10)]);
        $this->assertSame([320, 320], \array_slice($music, 5 + 28, 2), 'bitrate trailer');

        $pc = PcCategorizer::w61PcTokenBatchPhp(['Adobe.Photoshop.v2024.x64.Incl.Keygen', 'Game.PS4-GRP', 'Some.App.iOS.ipa', 'Tool.x86.x64.Linux',
            'Cyberpunk.2077-FitGirl', 'Udemy.Python.Course.x64']);
        $this->assertSame([6, 1, 2, 2024, 800], [$bits($pc[0], 0, 3), $bits($pc[0], 3, 3), $bits($pc[0], 6, 3), $bits($pc[0], 25, 14), $bits($pc[0], 39, 10)]);
        $this->assertSame(0, $bits($pc[1], 0, 3));
        $this->assertSame([1, 4], [$bits($pc[2], 0, 3), $bits($pc[2], 3, 3)]);
        $this->assertSame([6, 3, 3, 750], [$bits($pc[3], 0, 3), $bits($pc[3], 3, 3), $bits($pc[3], 6, 3), $bits($pc[3], 39, 10)]);
        $this->assertSame([4, 850], [$bits($pc[4], 0, 3), $bits($pc[4], 39, 10)]);
        $this->assertSame(0, $bits($pc[5], 0, 3), 'courses block');
        $this->assertSame([2024, 1], \array_slice($pc, 6 + 35, 2), 'version trailer');

        $anime = AnimeProcessor::w61AnimeReleasePlanBatchPhp(['[SubsPlease] Frieren - 12 (1080p) [ABCD1234].mkv', '[Grp] Show S02E05 1920x1080 v2',
            'Title 1x18 Name', '[Grp] Title Ep 7 [720p]', 'Title.Movie.BD.1080p', '[Grp] Title Batch 01-12', '[Grp] - 01', '[]', '[grp] Other']);
        $this->assertSame([2, 3, 4, 1, 1, 0, 12, 7, 1, 10], [$bits($anime[0], 0, 3), $bits($anime[0], 3, 3), $bits($anime[0], 6, 3), $bits($anime[0], 9, 2),
            $bits($anime[0], 11, 1), $bits($anime[0], 24, 7), $bits($anime[0], 31, 14), $bits($anime[0], 45, 8), $bits($anime[0], 53, 5), $bits($anime[0], 58, 5)]);
        $this->assertSame([2, 1, 4, 2, 5, 4, 2048], [$bits($anime[1], 0, 3), $bits($anime[1], 3, 3), $bits($anime[1], 6, 3), $bits($anime[1], 24, 7),
            $bits($anime[1], 31, 14), $bits($anime[1], 45, 8), $bits($anime[1], 12, 12) & 2048]);
        $this->assertSame([2, 2, 1, 18, 5], [$bits($anime[2], 0, 3), $bits($anime[2], 3, 3), $bits($anime[2], 24, 7), $bits($anime[2], 31, 14), $bits($anime[2], 45, 8)]);
        $this->assertSame([2, 4, 3, 7], [$bits($anime[3], 0, 3), $bits($anime[3], 3, 3), $bits($anime[3], 6, 3), $bits($anime[3], 31, 14)]);
        $this->assertSame([3, 0, 4], [$bits($anime[4], 0, 3), $bits($anime[4], 3, 3), $bits($anime[4], 6, 3)]);
        $this->assertSame(4, $bits($anime[5], 0, 3));
        $this->assertSame([0, 3], [$bits($anime[6], 0, 3), $bits($anime[6], 3, 3)], 'episode before the title start');
        $this->assertSame([0, 2], [$bits($anime[7], 0, 3), $bits($anime[7], 9, 2)]);
        $this->assertSame(2, end($anime), 'distinct case-insensitive subgroups');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(300, \count($edges));
        $this->assertParity($class, ['Dune.2021.2160p.UHD.BluRay.x265-GRP|1|0', ...$edges], 'edge batch');
        foreach ($edges as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['[Grp] Title - 01 (1080p)|1|1', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position.
        for ($slot = 0; $slot < 3; $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $fields = ['Some.Release.2019.1080p.x264-GRP', '1', 'yes'];
                $fields[$slot] = $edge;
                $rows[] = implode('|', $fields);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Edges glued to realistic names with every separator.
        foreach ([' ', '.', '-', '_', ' - ', ''] as $glue) {
            $this->assertParity($class, array_map(static fn (string $e): string => 'Title'.$glue.$e.$glue.'2019', $edges), 'glued '.bin2hex($glue));
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Game.PS4|x', 7 => 'alt.binaries.tv', 'a' => '', 'c' => 'Book.epub|10'];
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
        foreach ([611, 612, 613] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6111));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBeEpPsSvVxXkK0123456789|||..,-_ [](@+\0\t\n\x0b\x7f\xff\xc3\xa9", $length);
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
            for ($j = 0, $n = $random->getInt(1, 8); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, [' ', '.', '-', '_', ' - ', '', '|']), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6112)), true), 0, 80));
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W611_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~6.5-8.4), common mean / p99, uncommon mean / p99:
        // - MiscCategorizer:  9.84x / 7.06x, 7.84x / 5.56x
        // - MovieCategorizer: 7.65x / 5.87x, 6.73x / 4.69x
        // - MusicCategorizer: 7.99x / 6.18x, 7.47x / 5.05x
        // - PcCategorizer:    8.51x / 3.60x, 6.88x / 4.95x
        // - AnimeProcessor:   6.46x / 4.63x, 7.12x / 5.76x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W611_BENCH=1 php artisan test --compact tests/Unit/RustCategorizersMiscMovieMusicPcAnimeProcessorW61ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W611_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W611_BENCH=1 to run the Wave 61.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6116));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W611_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6117)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6118)), true),
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
        fwrite(STDERR, "\nRustCategorizersMiscMovieMusicPcAnimeProcessorW61 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW61RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
            if (! $qualifies) {
                $this->assertFalse($class::usesW61RustFfi(), $class.' retains PHP by default');
            }
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
                MiscCategorizer::class => $this->miscItem($random, $uncommon),
                MovieCategorizer::class => $this->movieItem($random, $uncommon),
                MusicCategorizer::class => $this->musicItem($random, $uncommon),
                PcCategorizer::class => $this->pcItem($random, $uncommon),
                default => $this->animeItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace(' ', '_', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function miscItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            bin2hex($random->getBytes(16)), bin2hex($random->getBytes(20)), bin2hex($random->getBytes(32)), bin2hex($random->getBytes(10)),
            'Backup.Files.'.$random->getInt(2000, 2026).'.part'.$random->getInt(1, 40).'.rar', 'Some.Release.'.$random->getInt(1990, 2026).'.1080p.WEB-DL-GRP',
            'Database.Dump.'.$random->getInt(1, 99).'.sql', 'Archive.'.str_pad((string) $random->getInt(1, 999), 3, '0', STR_PAD_LEFT),
            $random->getBytesFromString('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz0123456789', $random->getInt(16, 32)),
            'bcdfghjklmnpqrst'.$random->getInt(1, 99).'.mkv', 'Proof.Sample.'.$random->getInt(1, 9).'.par2', 'Dataset.Collection.v'.$random->getInt(1, 9).'.zip',
        ]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'x.', 'x.toolong', strtoupper($name), str_repeat($name, 3), "Fichier.caf\xc3\xa9.rar"]);
        }

        return $name;
    }

    private function movieItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            'Dune.Part.Two.'.$random->getInt(1990, 2026).'.2160p.UHD.BluRay.x265-TERMiNAL', 'Some.Movie.'.$random->getInt(1950, 2026).'.German.DL.1080p.BluRay.x264-GRP',
            'Film.'.$random->getInt(1980, 2026).'.720p.WEB-DL.DDP5.1.H.264-NTb', 'Old.Movie.'.$random->getInt(1950, 1999).'.DVDRip.XviD-GRP',
            'Avatar.'.$random->getInt(2009, 2022).'.3D.HSBS.1080p.BluRay-GRP', 'New.Film.'.$random->getInt(2020, 2026).'.HDCAM.x264', 'Movie.Trailer.'.$random->getInt(2020, 2026).'.1080p',
            'Film.'.$random->getInt(2000, 2026).'.DVD9.Untouched', 'Show.S0'.$random->getInt(1, 9).'E'.str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT).'.720p.HDTV',
            'Castellano.Film.'.$random->getInt(1990, 2026).'.HDR10.DV.2160p.WEBRip.HEVC', 'Movie.'.$random->getInt(1990, 2026).'.480p.SD.DVDScr',
        ]);
        $foreign = $this->pick($random, ['', '1', '0', 'true', 'false']);
        $webdl = $this->pick($random, ['', '1', '0', 'yes', 'no']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'S1000E1.1080p', 'x.2160p.S01E02', str_repeat('1080p ', 20), "Film.Fran\xc3\xa7ais.720p"]);
            $foreign = $this->pick($random, [$foreign, 'YES', 'On', 'maybe', '01']);
            $webdl = $this->pick($random, [$webdl, 'TRUE', 'off', '2']);
        }

        return implode('|', [$name, $foreign, $webdl]);
    }

    private function musicItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            'Artist-Album-'.$random->getInt(1970, 2026).'-'.$this->pick($random, ['128', '192', '256', '320']).'kbps-MP3-GRP', 'Artist - Album ('.$random->getInt(1970, 2026).') [FLAC 24bit 96kHz]',
            'Stephen.King-The.Stand.Unabridged.Audiobook.m4b', 'Podcast.Show.Episode.'.$random->getInt(1, 500).'.MP3', 'Artist-Live.Concert-'.$random->getInt(2000, 2026).'-1080p-MVid',
            'VA-Discography-'.$random->getInt(1990, 2026).'-WEB-FLAC', 'Kpop.Group-Single-'.$random->getInt(2015, 2026).'-WEB-AAC', 'Artist-Vinyl.LP-'.$random->getInt(1960, 2026).'-GRP',
            'Band-Album-'.$random->getInt(1990, 2026).'-CD-OGG', 'Artist-Track-WEB-'.$random->getInt(1990, 2026).'-VBR',
        ]);
        $foreign = $this->pick($random, ['', '1', '0', 'yes']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', '12345kbps', '9k', 'x.192khz.1234khz', str_repeat('flac ', 30), "Chanson.Fran\xc3\xa7aise.MP3"]);
            $foreign = $this->pick($random, [$foreign, 'ON', 'nope', ' 1 ']);
        }

        return $name.'|'.$foreign;
    }

    private function pcItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            'Adobe.Photoshop.'.$random->getInt(2019, 2026).'.v'.$random->getInt(20, 26).'.x64.Incl.Keygen-GRP', 'Microsoft.Office.'.$random->getInt(2016, 2026).'.Pro.Plus.x86.x64-AiO',
            'Some.App.v'.$random->getInt(1, 20).'.iOS.ipa', 'Tool.v'.$random->getInt(1, 9).'.Android.apk', 'Final.Cut.Pro.v'.$random->getInt(10, 12).'.MacOSX.dmg',
            'Cyberpunk.'.$random->getInt(2000, 2026).'-FitGirl.Repack', 'Game.Name.MULTi'.$random->getInt(2, 12).'-CODEX', 'Ubuntu.'.$random->getInt(18, 26).'.04.Desktop.amd64.iso',
            'Windows.11.Enterprise.'.$random->getInt(2021, 2026).'.ARM64', 'Udemy.Python.Course.'.$random->getInt(2019, 2026), 'Game.PS4-DUPLEX', 'Utility.0day.Portable',
        ]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'v12345', 'multi123', 'V', str_repeat('x64 ', 30), "Logiciel.Fran\xc3\xa7ais.x64"]);
        }

        return $name;
    }

    private function animeItem(Randomizer $random, bool $noisy): string
    {
        $group = $this->pick($random, ['SubsPlease', 'Erai-raws', 'HorribleSubs', 'Judas', 'ASW', 'EMBER']);
        $title = $this->pick($random, ['Frieren', 'Sousou no Frieren', 'One Piece', 'Shingeki no Kyojin', 'Kimetsu no Yaiba', 'Dungeon Meshi']);
        $episode = str_pad((string) $random->getInt(1, 1100), 2, '0', STR_PAD_LEFT);
        $name = $this->pick($random, [
            '['.$group.'] '.$title.' - '.$episode.' ('.$this->pick($random, ['480p', '720p', '1080p']).') ['.strtoupper(bin2hex($random->getBytes(4))).'].mkv',
            '['.$group.'] '.$title.' S0'.$random->getInt(1, 5).'E'.$episode.' [1080p][HEVC 10bit]', $title.' '.$random->getInt(1, 5).'x'.$episode.' Episode Name',
            '['.$group.'] '.$title.' Ep '.$episode.' [720p] [Dual Audio]', '['.$group.'] '.$title.' The Movie [BD 1920x1080 x264]',
            '['.$group.'] '.$title.' (Batch) [BD 1080p]', str_replace(' ', '_', '['.$group.'] '.$title.' - '.$episode.' [v2]'),
            $title.'.OVA.'.$random->getInt(1, 3).'.BDRip.1080p', '['.$group.'][Raw] '.$title.' E'.$episode,
        ]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', '[', '[]', '[unclosed', ' - 12345', 'Ep', str_repeat('[a]', 20), "[Grp] Caf\xc3\xa9 - 01"]);
        }

        return $name;
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
     * Calls a Wave 61.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w61RustFfiState'))->invoke(null, 'set', $enabled);
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
