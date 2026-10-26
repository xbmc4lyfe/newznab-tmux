<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Media\MediaSourceStage;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use App\Services\AdditionalProcessing\MediaExtractionService;
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

final class RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        MediaSourceStage::class => ['w65MediaSourceStagePlanBatch', 'nntmux_w651_media_source_stage_plan_batch', 'W65_MEDIA_SOURCE_STAGE_FFI_CDEF', 43,
            'media_source_stage_w651'],
        MediaToolFactory::class => ['w65MediaToolFactoryPlanBatch', 'nntmux_w651_media_tool_factory_plan_batch', 'W65_MEDIA_TOOL_FACTORY_FFI_CDEF', 35,
            'media_tool_factory_w651'],
        Par2FileMapper::class => ['w65Par2FileMapperPlanBatch', 'nntmux_w651_par2_file_mapper_plan_batch', 'W65_PAR2_FILE_MAPPER_FFI_CDEF', 37,
            'par2_file_mapper_w651'],
        ThumbnailFrameSelector::class => ['w65ThumbnailSelectorPlanBatch', 'nntmux_w651_thumbnail_selector_plan_batch', 'W65_THUMBNAIL_SELECTOR_FFI_CDEF', 26,
            'thumbnail_selector_w651'],
        MediaExtractionService::class => ['w65MediaExtractionPlanBatch', 'nntmux_w651_media_extraction_plan_batch', 'W65_MEDIA_EXTRACTION_FFI_CDEF', 53,
            'media_extraction_w651'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        MediaSourceStage::class => true,
        MediaToolFactory::class => true,
        Par2FileMapper::class => true,
        ThumbnailFrameSelector::class => true,
        MediaExtractionService::class => true,
    ];

    /** A realistic `|`-separated item per class. */
    private const TEMPLATES = [
        MediaSourceStage::class => ['video', '734003200', '980', 'mid', 'mkv', '5242880', '0', 'Some.Show.S01E02.1080p.WEB-DL-GRP.mkv'],
        MediaToolFactory::class => ['ffmpeg', '/usr/local/bin/ffmpeg', '120', '4', '-y -i {input} -t {timeout} {output}', '7.1.1'],
        Par2FileMapper::class => ['0123456789abcdef0123456789abcdef', 'Some.Show.S01E02.mkv', '734003200', '[01/80] - "Some.Show.S01E02.mkv" yEnc (1/980)',
            '752000000', '980'],
        ThumbnailFrameSelector::class => ['1260000', '2640000', '118', '64', '420', '1920', '1080'],
        MediaExtractionService::class => ['5040', 'vm', '2640000', '1920', '1080', 'h264', '0', '734003200'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed values. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r", "\r\n", "\x7f", "\x01", "\xff", "caf\xc3\xa9", "\xe2\x80\x8b", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', '||||||||', '|||||||||', ' | | ', "\t|\t|\t", "\0|\0|\0", 'a|b|c|d|e|f|g|h|i|j', '.', '..', '...', '-', '_',
        ':', '/', '//', '\\', '\\\\', '"', '""', '"a"', '" "', '"x.mkv', 'x"', '{', '}', '{}', '{input}', '{output}', '{timeout}', '{input} {output}',
        '{{input}', '{input}}', ';', '&', '$', '`', '<', '>', 'a;b', '$(id)', '0', '00', '1', '2', '3', '9', '10', '11', '12', '15', '16', '19', '20', '-0',
        '-1', '-2', '--1', '+1', '1-', ' 1 ', '1 2', '1.5', '1e3', '0x10', '63', '64', '65', '127', '128', '129', '239', '240', '241', '255', '256', '499',
        '500', '501', '599', '600', '999', '1000', '1001', '1023', '1024', '1079', '1080', '2159', '2160', '3599', '3600', '3601', '4095', '4096',
        '65535', '65536', '65537', '100000', '5242879', '5242880', '5242881', '10799999', '10800000', '4294967295', '4294967296', '53687091200',
        '53687091201', '1099511627776', '999999999999999', '1000000000000000', '-999999999999999', 'video', 'VIDEO', 'sample', 'archive', 'audio',
        'image', 'bogus', 'head', 'mid', 'middle', 'MIDDLE', 'complete', 'tail', 'mkv', 'MKV', 'mp4', 'avi', 'ts', 'mov', 'wmv', 'mpg', 'm2ts', 'flv',
        'ffmpeg', 'FFMPEG', 'ffprobe', 'mediainfo', 'unrar', '7z', '7Z', 'par2', 'curl', '/usr/bin/ffmpeg', '/usr/local/bin/ffprobe', 'ffmpeg ',
        './ffmpeg', 'bin/ffmpeg', '/usr/bin/../ffmpeg', '../ffmpeg', '/opt/my tools/ffmpeg', '/usr/bin/ffmpeg;rm', '/bin/$X', "/usr/bin/ff\x01mpeg",
        '6', '6.1', '6.1.1', 'n7.0', 'N7.0', 'v5.1', 'vv5', 'n', 'v', 'git-2024', '12345', '1234', '0.9', '19.09', '20.09', '23.01', '4.4.2-0ubuntu',
        '0123456789abcdef0123456789abcdef', '0123456789ABCDEF0123456789ABCDEF', '0123456789abcdef0123456789abcde', '0123456789abcdef0123456789abcdef0',
        'g123456789abcdef0123456789abcdef', '00000000000000000000000000000000', 'movie.mkv', 'Movie.MKV', 'movie', 'movie.nfo', 'movie.srt', 'movie.r00',
        'movie.r1', 'movie.001', 'movie.7z', 'movie.zip', 'movie.rar', 'movie.par2', 'movie.vol00+01.par2', 'movie.diz', 'movie.idx', 'movie.flac',
        'movie.jpg', 'movie.webp', 'Movie.Sample.mkv', 'sample', 'dir/movie.mkv', 'dir\\movie.mkv', 'movie.abcdef', 'movie.', '.movie', '"movie.mkv"',
        '[1/9] - "movie.mkv" yEnc (1/10)', '"" yEnc', '"a" "b"', 'yEnc "movie.mkv', 'v', 'a', 's', 'j', 'm', 'vasjm', 'VJ', 'vx', 'x', 'vv', 'v a',
        'h264', 'H264', 'hevc', 'h265', 'av1', 'mpeg2', 'mpeg4', 'vc1', 'vp9', 'aac', 'mp3', 'flac', 'ac3', 'dts', 'opus', 'xvid', '1000', '2000', '2040',
        '3010', '5030', '5040', '6000', '7020', '8000', '99000', 'clip.ts', 'clip.m2ts', 'clip.MOV', 'Sample.JPG',
        'a.tar.gz', '/usr/bin/7z', 'C:\\ffmpeg.exe', 'ffmpeg -version', 'n6.1-static', '2.0.0-beta', '719', '720', '721', '2159', '19999', '20000', '4|1|1', '50000|100000', 'x|1', '1|x', '5000|100000|128|60|400|1920|1080|extra'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 15), str_repeat('9', 15), str_repeat('9', 16), str_repeat('a', 255), str_repeat('a', 256),
            str_repeat('a', 1023), str_repeat('a', 1024), str_repeat('x', 5000), str_repeat('.', 300), str_repeat('|', 300), str_repeat('/', 100),
            str_repeat('a/', 70).'x.mkv', str_repeat('../', 40), str_repeat('"', 70), str_repeat('\\', 70), str_repeat("\xc3\xa9", 200),
            str_repeat("\x01", 80), str_repeat('{input}', 30), str_repeat('vasjm', 40), str_repeat('f', 32), str_repeat('0', 32)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW65RustFfi();
        }
        parent::tearDown();
    }

    public function test_w65_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW65RustFfi', 'enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', 'withW65RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W65_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W65_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            foreach (['w65RustFfiState', 'w65Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w65RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w65RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW65RustFfi');
            $with = new ReflectionMethod($class, 'withW65RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW65RustFfi/withW65RustFfi signatures';
            }
            foreach (['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
            // Wave 65 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if (str_contains(strtolower($property->getName()), 'w65')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \constant($class.'::'.str_replace('_FFI_CDEF', '_TRAILER', $cdef)), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        $mapper = new ReflectionClass(Par2FileMapper::class);
        $this->assertTrue($mapper->isReadOnly() && $mapper->isFinal(), 'the mapper stays a final readonly class');
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w65_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W65_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class);
            $class::enableW65RustFfi();
            $this->assertTrue($class::usesW65RustFfi(), $class);
            $this->assertFalse($class::withW65RustFfi(false, static fn (): bool => $class::usesW65RustFfi()));
            $this->assertTrue($class::usesW65RustFfi(), $class.' restores the enabled override');
            $class::disableW65RustFfi();
            $this->assertFalse($class::usesW65RustFfi(), $class);
            $this->assertTrue($class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi()));
            $this->assertFalse($class::usesW65RustFfi(), $class.' restores the disabled override');
            $class::resetW65RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class);
            $this->assertTrue($class::withW65RustFfi(false, static fn (): bool => $class::withW65RustFfi(true, static fn (): bool => $class::usesW65RustFfi())));
            $this->assertFalse($class::withW65RustFfi(true, static fn (): bool => $class::withW65RustFfi(false, static fn (): bool => $class::usesW65RustFfi())));
            try {
                $class::withW65RustFfi(! $class::usesW65RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class.' restores after an exception');
            // Flipping one class's toggle leaves the others at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW65RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW65RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW65RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)), "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW65RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW65RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w65_existing_public_api_is_unchanged(): void
    {
        $earlier = static fn (): array => [
            MediaSourceStage::usesW45RustFfi(), MediaSourceStage::usesW52RustFfi(),
            MediaToolFactory::usesW45RustFfi(), MediaToolFactory::usesW52RustFfi(),
            Par2FileMapper::usesRustFfi(), Par2FileMapper::usesW52RustFfi(),
            ThumbnailFrameSelector::usesW45RustFfi(), ThumbnailFrameSelector::usesW52RustFfi(),
            MediaExtractionService::usesW406RustFfi(), MediaExtractionService::usesW52RustFfi(),
        ];
        $before = $earlier();
        $this->assertSame([MediaSourceStage::W45_RUST_FFI_DEFAULT, MediaToolFactory::W45_RUST_FFI_DEFAULT, Par2FileMapper::W44_RUST_FFI_DEFAULT,
            ThumbnailFrameSelector::W45_RUST_FFI_DEFAULT, MediaExtractionService::W406_RUST_FFI_DEFAULT],
            [$before[0], $before[2], $before[4], $before[6], $before[8]]);
        foreach (array_keys(self::TARGETS) as $class) {
            foreach ([true, false] as $enabled) {
                $class::withW65RustFfi($enabled, function () use ($earlier, $before, $class): void {
                    $this->assertSame($before, $earlier(), $class.' leaves earlier-wave toggles alone');
                });
            }
            $class::resetW65RustFfi();
            $this->assertSame($before, $earlier(), $class.' reset leaves earlier-wave toggles alone');
        }
        $this->assertSame(4, MediaSourceStage::W52_MIDDLE_HEADER);
        $this->assertSame(5, MediaToolFactory::W52_DRIVER_HEADER);
        $this->assertSame(6, ThumbnailFrameSelector::W52_WINDOW_HEADER);
        $this->assertSame(1, MediaExtractionService::W52_TAG_VALID);
        $this->assertNull(MediaSourceStage::middleVolumeIndexPhp([]));
        $this->assertSame(['timeout' => 60, 'ffmpeg.binaries' => '/usr/bin/ffmpeg', 'ffprobe.binaries' => '/usr/bin/ffprobe'],
            MediaToolFactory::driverConfigurationPhp(60, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'));
        $this->assertTrue(ThumbnailFrameSelector::looksLikeTextPhp("\xEF\xBB\xBF #EXTM3U\n"));
        $this->assertFalse(ThumbnailFrameSelector::looksLikeTextPhp("\x1A\x45\xDF\xA3"));
        $this->assertSame('Artist - Album (2020) MP3', MediaExtractionService::audioReleaseTitlePhp('Artist', 'Album', '2020', 'MP3'));
        $this->assertSame(Par2FileMapper::candidatePlanBatchPhp([], []), Par2FileMapper::candidatePlanBatch([], []));
    }

    public function test_w65_exports_resolve_and_guard_their_buffers(): void
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

    public function test_w65_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [...array_map(static fn (array $fields): string => implode('|', $fields), array_values(self::TEMPLATES)),
                'sample|1000000|4|mid|mp4|300000|0|Show.Sample.mp4', 'unrar|/usr/bin/unrar|9999|1|x {input} {output}|', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w65Invoke');
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
            $class::resetW65RustFfi();
            $this->assertSame($class::{$entry.'Php'}($items), $invoke->invoke(null, $items, $trailer + \count($items)), $class.' after reset');
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $stage = MediaSourceStage::w65MediaSourceStagePlanBatchPhp(['video|10000000|10|head|mkv||0|show.mkv', 'sample|1000000|4|mid|mp4|300000|0|Show.Sample.mp4',
            'archive|50000000|50|complete||||show.part01.rar', 'video|10000000|0|head|mkv|||x.mkv', 'video|10000000|10|head|mkv||3|y.mkv', 'audio|100|1|head||||a.flac',
            'video|10000000|10|head|flv|||z.flv', 'video|10000000|1|head|mkv|1000||big.mkv', 'bogus|1|1', '', 'video|10000000|10|head|mkv||0|SHOW.MKV',
            'video|10000000|10|middle|mp4||0|clip.mkv']);
        $this->assertSame([7, 2, 5, 64, 10000000, 1], [$bits($stage[0], 0, 4), $bits($stage[0], 14, 5), $bits($stage[0], 27, 12), $bits($stage[0], 19, 8),
            $stage[12 + 41], $bits($stage[0], 10, 4)]);
        $this->assertSame([7, 1 | 4, 1, 1, 1 | 64], [$bits($stage[1], 0, 4), $bits($stage[1], 14, 5), $bits($stage[1], 27, 12), $bits($stage[1], 39, 12),
            $bits($stage[1], 19, 8)]);
        $this->assertSame([8, 2 | 4 | 8, 2, 3, 4, 5, 6, 1, 0, 32], [$bits($stage[2], 0, 4), $bits($stage[2], 14, 5), $bits($stage[3], 0, 4), $bits($stage[4], 0, 4),
            $bits($stage[5], 0, 4), $bits($stage[6], 0, 4), $bits($stage[7], 0, 4), $bits($stage[8], 0, 4), $stage[9] ^ (2 << 14 | 8 << 19), $bits($stage[10], 19, 8) & 32]);
        $this->assertSame([7, 2, 4, 16, 2], [$bits($stage[11], 0, 4), $bits($stage[11], 7, 3), $bits($stage[11], 14, 5), $bits($stage[11], 19, 8) & 16,
            $bits($stage[11], 39, 12)]);
        $this->assertSame([9, 5 + 1 + 5 + 5 + 5], [$stage[12 + 42], $stage[12 + 39]]);

        $tool = MediaToolFactory::w65MediaToolFactoryPlanBatchPhp(['ffmpeg|/usr/bin/ffmpeg|120|4|-i {input} {output}|6.1.1', 'ffprobe|/usr/bin/ffprobe||0|{input}|n7.0',
            'ffmpeg||60|1|{input} {output}|6', 'ffmpeg|/usr/bin/../ffmpeg|60|1|{input} {output}|6', 'ffmpeg|/usr/bin/ffmpeg|60|1|{input}|6',
            'mediainfo|mediainfo|60|1|{input}|19.09', 'unrar|/usr/bin/unrar|9999|1|x {input} {output}|', 'curl|/usr/bin/curl|1|1|x|1', '',
            '7z|bin/7z|60|300|{input} {output} {timeout}|23.01', 'par2|/usr/bin/ffmpeg|60|1|{{input}|git']);
        $this->assertSame([7, 1, 1, 3, 0, 120, 4, 6, 15], [$bits($tool[0], 0, 3), $bits($tool[0], 3, 3), $bits($tool[0], 6, 2), $bits($tool[0], 8, 3),
            $bits($tool[0], 11, 8), $bits($tool[0], 19, 12), $bits($tool[0], 31, 8), $bits($tool[0], 39, 10), $bits($tool[0], 49, 10)]);
        $this->assertSame([7, 2, 60, 7], [$bits($tool[1], 0, 3), $bits($tool[1], 11, 8), $bits($tool[1], 19, 12), $bits($tool[1], 39, 10)]);
        $this->assertSame([2, 3, 4, 5, 2, 128, 6, 3600, 1, 1, 0], [$bits($tool[2], 0, 3), $bits($tool[3], 0, 3), $bits($tool[4], 0, 3), $bits($tool[5], 0, 3),
            $bits($tool[5], 6, 2), $bits($tool[5], 11, 8), $bits($tool[6], 0, 3), $bits($tool[6], 19, 12), $bits($tool[6], 11, 8), $bits($tool[7], 0, 3), $tool[8] ^ (60 << 19 | 2 << 11)]);
        $this->assertSame([7, 3, 7, 255, 23, 128], [$bits($tool[9], 0, 3), $bits($tool[9], 6, 2), $bits($tool[9], 8, 3), $bits($tool[9], 31, 8), $bits($tool[9], 39, 10),
            $bits($tool[9], 11, 8)]);
        $this->assertSame([6, 16 | 32 | 64], [$bits($tool[10], 0, 3), $bits($tool[10], 11, 8)]);
        $this->assertSame([1 << 1 | 1 << 2 | 1 << 5, 7, 120 + 60 * 8 + 3600 + 1], [$tool[11 + 34], $tool[11 + 33], $tool[11 + 31]]);

        $par2 = Par2FileMapper::w65Par2FileMapperPlanBatchPhp(['0123456789abcdef0123456789abcdef|movie.mkv|1000000|[1/3] "movie.mkv" yEnc|1030000|10',
            '0123456789abcdef0123456789abcdef|movie.mkv|1|x|1|1', 'zz|movie.mkv|1|x|1|1', '00000000000000000000000000000001|Movie.MKV|100|"movie.mkv"|100|1',
            '00000000000000000000000000000002|movie.nfo|100|"movie.mkv"|100|1', '00000000000000000000000000000003|other.srt|100|"movie.mkv"|100|1',
            '00000000000000000000000000000004|movie.mkv|1000|"movie.mkv"|10|1', '|||', '', 'ABCDEF0123456789ABCDEF0123456789|dir/Sample.R01|5||0|0']);
        $this->assertSame([7, 3, 1, 1, 1, 10, 9], [$bits($par2[0], 0, 3), $bits($par2[0], 3, 2), $bits($par2[0], 5, 2), $bits($par2[0], 7, 4), $bits($par2[0], 11, 8),
            $bits($par2[0], 31, 12), $bits($par2[0], 55, 8)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', 'movie.mkv')) & 0xFFF, $bits($par2[0], 43, 12));
        $this->assertSame([3, 64, 2, 7, 2, 6, 6, 4, 7, 5, 2, 1], [$bits($par2[1], 0, 3), $bits($par2[1], 11, 8), $bits($par2[2], 0, 3), $bits($par2[3], 0, 3),
            $bits($par2[3], 3, 2), $bits($par2[4], 0, 3), $bits($par2[4], 7, 4), $bits($par2[5], 0, 3), $bits($par2[5], 7, 4), $bits($par2[6], 0, 3),
            $bits($par2[6], 5, 2), $bits($par2[7], 0, 3)]);
        $this->assertSame([(int) hexdec(hash('fnv1a32', '')) & 0xFFF, 128], [$bits($par2[8], 43, 12), $bits($par2[8], 11, 8)]);
        $this->assertSame([4, 4, 8 | 16 | 32 | 128], [$bits($par2[9], 0, 3), $bits($par2[9], 7, 4), $bits($par2[9], 11, 8)]);
        $this->assertSame([1000000 + 100 + 100, 10 + 1 + 1, 6, 4], [$par2[10 + 33], $par2[10 + 34], $par2[10 + 35], $par2[10 + 36]]);

        $thumb = ThumbnailFrameSelector::w65ThumbnailSelectorPlanBatchPhp(['50000|100000|128|60|400|1920|1080', '50500|100000|128|80|900|1920|1080',
            '5000|100000|128|60|400|1920|1080', '200000|100000|128|60|400|1920|1080', '30000|100000|5|60|400|1920|1080', '30000|100000|250|60|400|1920|1080',
            '30000|100000|128|5|400|1920|1080', '30000|100000|128|60|400|32|32', 'x|1', '', '70000|100000|120|90|800|1280|720', '0||128|60|600|720|1280|x', '0||128|60|600|720|1280']);
        $this->assertSame([8, 400 + 120 + 256 + 200 + 207, 50, 128, 60], [$bits($thumb[0], 0, 4), $bits($thumb[0], 12, 12), $bits($thumb[0], 24, 7),
            $bits($thumb[0], 31, 8), $bits($thumb[0], 39, 8)]);
        $this->assertSame([7, 8, 0, 2, 4, 5, 6, 3, 1, 64, 8, 64], [$bits($thumb[1], 0, 4), $bits($thumb[2], 0, 4), $bits($thumb[2], 4, 8), $bits($thumb[3], 0, 4),
            $bits($thumb[4], 0, 4), $bits($thumb[5], 0, 4), $bits($thumb[6], 0, 4), $bits($thumb[7], 0, 4), $bits($thumb[8], 0, 4), $thumb[9], $bits($thumb[10], 0, 4),
            $bits($thumb[10], 4, 8)]);
        $this->assertSame([1, 4 | 64 | 128, 8, 4 | 8 | 64, 600 + 120 + 256 + 92], [$bits($thumb[11], 0, 4), $bits($thumb[11], 4, 8), $bits($thumb[12], 0, 4),
            $bits($thumb[12], 4, 8), $bits($thumb[12], 12, 12)]);
        $this->assertSame([11, 800 + 180 + 240 + 200 + 92, 70000, 200000], [$thumb[13 + 21], $thumb[13 + 22], $thumb[13 + 23], $thumb[13 + 25]]);
        $this->assertSame([4, 4], [$thumb[13 + 8], array_sum(\array_slice($thumb, 13 + 17, 4))]);
        $none = ThumbnailFrameSelector::w65ThumbnailSelectorPlanBatchPhp(['1|1|1', '']);
        $this->assertSame([0, 0, 0], \array_slice($none, 2 + 21, 3), 'no winner without an accepted frame');

        $media = MediaExtractionService::w65MediaExtractionPlanBatchPhp(['2040|v|5400000|1920|1080|h264|0|4000000000', '5030|vj|2400000|1280|720|hevc|4|1',
            '3010|a|200000|0|0|flac|0|1', '7020||0|0|0||0|0', '2040|v|5400000|1920|1080|h264|63|1', '2040|v|0|1920|1080|h264|0|1', '2040|v|500|1920|1080|h264|0|1',
            '2040|v|5400000|1920|1080|h264|0|99999999999999', '2040|x|1', '', '|VSJM|12000000|32|3840|xvid|1|5']);
        $this->assertSame([7, 1 | 4 | 32, 1 | 4 | 32, 2, 1, 3, 1, 3], [$bits($media[0], 0, 3), $bits($media[0], 3, 6), $bits($media[0], 9, 6), $bits($media[0], 15, 4),
            $bits($media[0], 19, 4), $bits($media[0], 23, 3), $bits($media[0], 26, 3), $bits($media[0], 49, 3)]);
        $this->assertSame([1 | 4 | 16, 1 | 16, 2, 2 | 4, 5, 2, 3, 4, 5, 6, 1, 0], [$bits($media[1], 3, 6), $bits($media[1], 9, 6), $bits($media[1], 23, 3),
            $bits($media[2], 3, 6), $bits($media[2], 26, 3), $bits($media[3], 0, 3), $bits($media[4], 0, 3), $bits($media[5], 0, 3), $bits($media[6], 0, 3),
            $bits($media[7], 0, 3), $bits($media[8], 0, 3), $media[9] ^ (128 << 29)]);
        $this->assertSame([7, 4 | 8 | 16, 4 | 8 | 16, 2, 1 | 4 | 32 | 64 | 128, 1, 4], [$bits($media[10], 0, 3), $bits($media[10], 3, 6), $bits($media[10], 9, 6),
            $bits($media[10], 26, 3), $bits($media[10], 29, 8), $bits($media[10], 52, 6), $bits($media[10], 23, 3)]);
        $this->assertSame([5400000 + 2400000 + 200000 + 12000000, 4000000000 + 1 + 1 + 5, 12000000, 4],
            [$media[11 + 49], $media[11 + 50], $media[11 + 51], $media[11 + 52]]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(300, \count($edges));
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
        foreach ([' ', '.', '-', '_', ':', '|', '/', '\\', '"', ',', "\t", ''] as $glue) {
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
        foreach ([651, 652, 653] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6511));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAvVsSjJmMhHrRzZ\"{}0123456789|||||..//\\\\-_ :;\$+\0\t\r\n\x01\x7f\xff\xc3\xa9", $length);
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
            for ($j = 0, $n = $random->getInt(1, 9); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, ['|', '|', '|', ' ', '.', '/', '\\', '"', '']), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6512)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
        // Frames clustered within a few seconds exercise the timestamp diversity buckets.
        $frames = [];
        for ($i = 0; $i < 400; $i++) {
            $frames[] = implode('|', [(string) $random->getInt(0, 6000), '6000', (string) $random->getInt(10, 250), (string) $random->getInt(0, 255),
                (string) $random->getInt(0, 1000), '640', '360']);
        }
        $this->assertParity($class, $frames, 'clustered frames');
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W651_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release dylib, Apple
        // Silicon, shared host at load average ~4.6-5.8), common mean / p99, uncommon mean / p99:
        // - MediaSourceStage:       5.42x / 4.69x, 6.10x / 4.28x
        // - MediaToolFactory:       6.20x / 4.70x, 5.96x / 4.32x
        // - Par2FileMapper:         3.99x / 3.64x, 4.49x / 3.84x
        // - ThumbnailFrameSelector: 8.48x / 6.96x, 8.02x / 6.15x
        // - MediaExtractionService: 10.97x / 8.63x, 10.25x / 7.97x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 65.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6516));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W651_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6517)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6518)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW65RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW65RustFfi() : $class::disableW65RustFfi();
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
        fwrite(STDERR, "\nRustAdditionalMediaStageToolPar2MapperThumbExtractionW65 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW65RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
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
        $this->assertSame($php, $class::withW65RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW65RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
                MediaSourceStage::class => $this->stageFields($random),
                MediaToolFactory::class => $this->toolFields($random),
                Par2FileMapper::class => $this->par2Fields($random),
                ThumbnailFrameSelector::class => $this->frameFields($random),
                default => $this->extractionFields($random),
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $fields[$random->getInt(0, \count($fields) - 1)] = $this->pick($random, ['', ' ', 'x', '-1', '1e3', "caf\xc3\xa9", "a\x01b", str_repeat('n', 300),
                    '9999999999999999', '..', '/abs', '{input', 'TRUE', 'unknown', 'Show.R00', '"q.mkv"']);
            }
            $row = implode('|', $uncommon && $random->getInt(0, 4) === 0 ? \array_slice($fields, 0, $random->getInt(1, \count($fields))) : $fields);
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 3), str_replace('.', ' ', $row), str_replace('/', '\\', $row), str_replace('|', '| ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function title(Randomizer $random): string
    {
        return $this->pick($random, ['Some.Show.S01E02', 'The.Movie', 'Artist-Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game', 'Old.Film'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', '2160p', 'WEB-DL', 'BluRay', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX']);
    }

    /** @return list<string> */
    private function stageFields(Randomizer $random): array
    {
        $kind = $this->pick($random, ['video', 'video', 'video', 'sample', 'archive', 'audio', 'image']);
        $container = $this->pick($random, ['mkv', 'mkv', 'mp4', 'avi', 'ts', '']);
        $name = $this->title($random).match ($kind) {
            'sample' => '-sample.'.($container ?: 'mkv'),
            'archive' => '.part'.sprintf('%02d', $random->getInt(1, 40)).'.rar',
            'audio' => '.flac',
            'image' => '.jpg',
            default => '.'.($container ?: 'mkv'),
        };

        return [$kind, (string) $random->getInt(0, 8_000_000_000), (string) $random->getInt(0, 4000), $this->pick($random, ['head', 'head', 'mid', 'complete', '']),
            $container, $this->pick($random, ['', '', '5242880', '1048576', (string) $random->getInt(0, 50_000_000)]), $this->pick($random, ['0', '0', '', '1', '2', '3']), $name];
    }

    /** @return list<string> */
    private function toolFields(Randomizer $random): array
    {
        $tool = $this->pick($random, ['ffmpeg', 'ffmpeg', 'ffprobe', 'mediainfo', 'unrar', '7z', 'par2']);

        return [$tool, $this->pick($random, ['/usr/bin/'.$tool, '/usr/local/bin/'.$tool, '/opt/homebrew/bin/'.$tool, $tool, '', 'bin/'.$tool]),
            $this->pick($random, ['', '60', '120', '600', (string) $random->getInt(0, 9000)]), $this->pick($random, ['', '0', '1', '4', '8']),
            $this->pick($random, ['-y -i {input} {output}', '{input}', '-i {input} -t {timeout} {output}', 'x -o+ {input} {output}', '--Output=JSON {input}', '']),
            $this->pick($random, [$random->getInt(3, 8).'.'.$random->getInt(0, 9).'.'.$random->getInt(0, 9), 'n'.$random->getInt(4, 8).'.0', (string) $random->getInt(15, 25).'.0'.$random->getInt(1, 9), '', 'git-2024'])];
    }

    /** @return list<string> */
    private function par2Fields(Randomizer $random): array
    {
        $file = $this->title($random).$this->pick($random, ['.mkv', '.part01.rar', '.r00', '.nfo', '.srt', '.flac', '.jpg', '.vol00+01.par2', '.7z.001', '-sample.mkv']);
        $nzb = $this->pick($random, [$file, $file, strtolower($file), 'other.'.$file, preg_replace('/\.[^.]+$/', '.bin', $file) ?? $file]);
        $size = $random->getInt(0, 4_000_000_000);

        return [$random->getInt(0, 9) === 0 ? bin2hex($random->getBytes(15)) : bin2hex($random->getBytes(16)), $file, (string) $size,
            $random->getInt(0, 2) === 0 ? $nzb : '['.$random->getInt(1, 90).'/90] - "'.$nzb.'" yEnc ('.$random->getInt(1, 140).'/140)',
            (string) ($size + $random->getInt(-1000, (int) ($size / 15))), (string) $random->getInt(0, 4000)];
    }

    /** @return list<string> */
    private function frameFields(Randomizer $random): array
    {
        $duration = $random->getInt(0, 7_200_000);

        return [(string) $random->getInt(0, $duration + 1000), (string) $duration, (string) $random->getInt(0, 255), (string) $random->getInt(0, 255),
            (string) $random->getInt(0, 1000), $this->pick($random, ['1920', '1280', '720', '3840', '32']), $this->pick($random, ['1080', '720', '480', '2160', '1280'])];
    }

    /** @return list<string> */
    private function extractionFields(Randomizer $random): array
    {
        return [$this->pick($random, ['2040', '2050', '5030', '5040', '3010', '3040', '6000', '7020', '']), $this->pick($random, ['v', 'vm', 'vj', 'vs', 'a', 'am', 'vasjm', 'j', '']),
            (string) $random->getInt(0, 12_000_000), $this->pick($random, ['1920', '1280', '720', '3840', '0']), $this->pick($random, ['1080', '720', '480', '2160', '0']),
            $this->pick($random, ['h264', 'hevc', 'av1', 'mpeg4', 'aac', 'flac', 'xvid', '']), (string) $random->getInt(0, 63), (string) $random->getInt(0, 60_000_000_000)];
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
     * Calls a Wave 65.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w65RustFfiState'))->invoke(null, 'set', $enabled);
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
