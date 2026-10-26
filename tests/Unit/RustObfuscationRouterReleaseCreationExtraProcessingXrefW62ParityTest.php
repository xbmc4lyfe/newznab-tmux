<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\PerArticleObfuscationRouter;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseProcessingService;
use App\Services\XrefService;
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

final class RustObfuscationRouterReleaseCreationExtraProcessingXrefW62ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        PerArticleObfuscationRouter::class => ['w62ArticleRoutePlanBatch', 'nntmux_w621_article_route_plan_batch', 'W62_OBFUSCATION_ROUTER_FFI_CDEF', 31, 'obfuscation_router_w621'],
        ReleaseCreationService::class => ['w62ReleaseCreationPlanBatch', 'nntmux_w621_release_creation_plan_batch', 'W62_RELEASE_CREATION_FFI_CDEF', 16, 'release_creation_w621'],
        ReleaseExtraService::class => ['w62ReleaseExtraPlanBatch', 'nntmux_w621_release_extra_plan_batch', 'W62_RELEASE_EXTRA_FFI_CDEF', 36, 'release_extra_w621'],
        ReleaseProcessingService::class => ['w62ReleaseProcessingPlanBatch', 'nntmux_w621_release_processing_plan_batch', 'W62_RELEASE_PROCESSING_FFI_CDEF', 21, 'release_processing_w621'],
        XrefService::class => ['w62XrefPlanBatch', 'nntmux_w621_xref_plan_batch', 'W62_XREF_FFI_CDEF', 17, 'xref_w621'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        PerArticleObfuscationRouter::class => true,
        ReleaseCreationService::class => true,
        ReleaseExtraService::class => true,
        ReleaseProcessingService::class => true,
        XrefService::class => true,
    ];

    /** A realistic item per class, with `|`-separated fields where the class has them. */
    private const TEMPLATES = [
        PerArticleObfuscationRouter::class => ['[01/12] - "Abc123def456ghi789.part01.rar" yEnc (1/50)'],
        ReleaseCreationService::class => ['Some.Release.2019.1080p.x264-GRP', '1048576000', '50', '3', '104857600', '10', '7'],
        ReleaseExtraService::class => ['video', 'Matroska', 'V_MPEGH/ISO/HEVC', '5 500 kb/s', '1h 30mn', '6 channels', 'en'],
        ReleaseProcessingService::class => ['2040', '-1', '-1', '5368709120', '0', '200'],
        XrefService::class => ['Xref: news.example.com alt.binaries.teevee:12345', 'alt.binaries.multimedia:678', 'a.b.x:0'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r\n", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', '||||||', ' | | ', "\t|\t|\t",
        "\0|\0|\0", 'a|b|c|d|e|f|g|h', '.', '..', '-', '_', ':', '::', 'a:', ':1', 'a:1', 'a:b', 'a:1:2', 'a::1', '"', '""', '"""', '"a"', '"a', 'a"',
        '"x.rar"', '"x.par2"', '"x.vol01+02.par2"', '"X.VOL01+02.PAR2"', '"x.srr"', '"x.sfv"', '"x.nfo"', '"x.nzb"', '"x.r00"', '"x.001"', '"x.7z"',
        '"x.mkv"', '"x.toolong"', '"x."', '".x"', '"x.r0"', '"x.0001"', '"x.e-b"', '(', ')', '()', '(1/2)', '(0/0)', '(1/)', '(/2)', '(a/2)', ' (1/2)',
        '(01/02)', '(1/1)', '(999999/1000000)', '(0000001/2)', '(12345678901234567890/2)', '[', ']', '[]', '[1/2]', '[0/2]', '[3/2]', '[1/0]',
        '[01/12] - "x.rar" yEnc (1/50)', '(1/2) [3/4]', '[3/4] (1/2)', '(1/2(3/4)', 'yenc', 'YENC', 'yEnc (1/2)', 'Usenet Index Post', 'usenet index post',
        'Usenet Index Post 1 yEnc (1/2)', '0123456789abcdef', '0123456789abcde', 'abcdefabcdefabcdef', '0123456789012345', 'Abc123def456ghi789',
        'Ab1Cd2Ef3Gh4Ij5Kl6Mn7', 'AbCdEfGhIjKlMnOpQrStUv', '0', '00', '1', '-', '-0', '-1', '-5', '-6', '--1', '+1', '1-', ' 1 ', '1 2', '3', '03', '4',
        '9999', '10000', '999999999999999', '1000000000000000', '-999999999999999', '-1000000000000000', '1e3', '0x10', '1.5', '1,5', 'video', 'VIDEO',
        ' Video ', 'audio', 'subs', 'subtitle', 'text', 'Text', 'image', 'Matroska', 'MPEG-4', 'mp4', 'AVI', 'AAC', 'AC-3', 'E-AC-3', 'eac3', 'DTS', 'FLAC',
        'MPEG Audio', 'mp3', 'SubRip', 'UTF-8', 'ASS', 'SSA', 'PGS', 'VobSub', '36', 'hevc', 'V_MPEGH/ISO/HEVC', '7', '27', 'H264', 'h264', 'avc1',
        'V_MPEG4/ISO/AVC', '20', 'FMP4', 'MP42', 'mp43', 'MPG4', 'ASP', '2', 'MPEG', 'mpeg', 'DX50', 'DIVX', 'div3', 'XVID', 'xvid', 'WMV3', 'WVC1', 'vp9',
        'av01', '5 500 kb/s', '5.5 Mb/s', '5.5Mbps', '128000', '128 kb/s', '128Kbps', '640 kbit/s', '1.25M', '1.2345k', '1.k', '.5k', '1234567890',
        '123456789 Mb/s', '448 Kbps ', 'kb/s', '12 parsecs', '1 500 000 b/s', '1h 30mn', '1h23mn45s', '90 min', '45s', '500ms', '1 h', 'h', '1', '1 x',
        '5400000', '1234567890123', '123456789012', '1h 2', '1 2 3', '1234567890h', '999999999h', '1 H 2 MIN', '6 channels', '2', '8', '16', '0006',
        '999 channels', 'en', 'eng', 'English', 'english', 'pt-BR', 'zh_CN', 'EN', 'e', 'Unknown', '1030', '1999', '2000', '2040', '2999', '3000',
        '3010', '3030', '3999', '4000', '4010', '4050', '4999', '5000', '5040', '5070', '5999', '6000', '6045', '6999', '7000', '7020', '7999',
        '8000', '9999', '10', '20', '1023', '1024', '512', '5368709120', '1073741824', '1073741823', '21474836480', '21474836480000',
        'Xref:', 'XREF:', 'xref: ', 'Xref:news.example.com', 'Xref: news.example.com', 'news.example.com', 'news.example.com alt.binaries.x:1',
        'alt.binaries.x:1', 'alt.binaries.X:1', 'a.b.x:1', 'A.B.X:1', 'alt.binaries.x:0', 'alt.binaries.x:', 'alt.binaries.x:12a', ':123',
        '.bad:1', '-bad:1', 'bad group:1', 'ba*d:1', 'alt.binaries.x:1 alt.binaries.x:2', 'alt.binaries.x:1 ALT.BINARIES.X:2',
        "alt.binaries.x:1\talt.binaries.y:2", 'alt.binaries.x:123456789012345678', 'alt.binaries.x:1234567890123456789', 'srv a:1 b c:2',
        'Xref: srv '.'alt.binaries.x:1 alt.binaries.y:2 alt.binaries.z:3', 'Some.Release-GRP', 'some.release-grp', 'SOME.RELEASE-GRP',
        'Name "quoted"', "Name\x01ctl", "Name\x7fdel", 'Name yEnc', '[Grp] Title - 01', 'x|1|1|3', 'x|1|1|3|2|0|0', 'x|1|1|3|0|2|0',
        'x|0|1|3', 'x|1|1|2', 'x|a|1|3', '|1|1|3', 'x|1|1|3||| ', '2040|-1|-1|1|0|0', '2040|-5|-6|0|0|0', '5070|0|-1|1073741824|0|1',
        '5040|0|0|1|4|0', '2040|-1|-1|1|1023|0', '2040|x|0|0|0|0', 'video|Matroska|avc1|5 Mb/s|1h|2|en', 'audio|AAC||128 kb/s|5400000|2|',
        'subs|SubRip|||||', 'subs|PGS||||| eng ', 'Video|MPEG-4|xvid|bad|1 parsec|x|e1'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 513), str_repeat('a', 1100), str_repeat('.', 300),
            str_repeat('a1', 40), str_repeat('(1/2) ', 50), str_repeat('[1/2]', 60), str_repeat('|', 300), str_repeat('9', 40), str_repeat('f', 64),
            str_repeat('"', 7), str_repeat('"a', 30), str_repeat("\xc3\xa9", 200), str_repeat('alt.binaries.x:1 ', 80), str_repeat('a.b.x:9 ', 70),
            str_repeat('1h ', 50), str_repeat('999999999h ', 40), str_repeat('x', 1023), str_repeat('x', 1024), 'Xref: '.str_repeat('g', 300).':1',
            '"'.str_repeat('Ab1', 30).'.rar" yEnc (1/2)', str_repeat('-', 64), str_repeat('yEnc ', 100)];
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
            // Wave 62 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w62')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \count($class::{$entry.'Php'}([])), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        // Each class declares its own FFI declaration constant name, so none shadows a sibling's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w62_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W62_RUST_FFI_DEFAULT'), $class);
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
            // Flipping one class's toggle leaves its siblings at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW62RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW62RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW62RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [implode('|', self::TEMPLATES[$class]), 'Xref: srv alt.binaries.x:1', '[01/12] - "x.vol00+01.par2" yEnc (1/50)',
                'video|Matroska|avc1|5 Mb/s|1h|2|en', '2040|-1|-1|1|0|0', 'x|1|1|3', "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW62RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW62RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w62_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults and are independent of Wave 62.
        $this->assertSame(PerArticleObfuscationRouter::RUST_FFI_DEFAULT, PerArticleObfuscationRouter::usesRustFfi());
        $this->assertSame(PerArticleObfuscationRouter::W451_RUST_FFI_DEFAULT, PerArticleObfuscationRouter::usesW451RustFfi());
        $this->assertFalse(ReleaseCreationService::usesRustFfi());
        $this->assertSame(ReleaseCreationService::W431_RUST_FFI_DEFAULT, ReleaseCreationService::usesW431RustFfi());
        $this->assertSame(ReleaseExtraService::W431_RUST_FFI_DEFAULT, ReleaseExtraService::usesW431RustFfi());
        $this->assertSame(ReleaseProcessingService::W431_RUST_FFI_DEFAULT, ReleaseProcessingService::usesW431RustFfi());
        $this->assertSame(XrefService::W441_RUST_FFI_DEFAULT, XrefService::usesW441RustFfi());
        foreach (array_keys(self::TARGETS) as $class) {
            $class::withW62RustFfi(true, function (): void {
                $this->assertSame(PerArticleObfuscationRouter::W451_RUST_FFI_DEFAULT, PerArticleObfuscationRouter::usesW451RustFfi());
                $this->assertSame(ReleaseCreationService::W431_RUST_FFI_DEFAULT, ReleaseCreationService::usesW431RustFfi());
                $this->assertSame(XrefService::W441_RUST_FFI_DEFAULT, XrefService::usesW441RustFfi());
            });
        }
        $this->assertSame(['total_subjects' => 2, 'empty_subject_count' => 1, 'no_part_count' => 0, 'part_count' => 0, 'candidate_count' => 1, 'over_limit_count' => 0],
            PerArticleObfuscationRouter::summarizeRouterSubjectBatchPhp(['Abc123def456ghi789jkl yEnc (1/50)', '']));
        $this->assertSame(1, ReleaseCreationService::summarizeCollectionSubjectBatchPhp(['Some "quoted.rar" subject'])['total_subjects']);
        $this->assertSame('HEVC', (new ReleaseExtraService)->makeCodecPrettyPhp('V_MPEGH/ISO/HEVC'));
        $this->assertSame('h.264', (new ReleaseExtraService)->makeCodecPrettyPhp('avc1'));
        $this->assertSame(1, ReleaseExtraService::summarizeCodecBatchPhp(['avc1'])['h264_count']);
        $this->assertSame('1m 15s', ReleaseProcessingService::formatElapsedTimePhp(75));
        $this->assertSame(1, ReleaseProcessingService::summarizeBinaryNameBatchPhp(['x.par2'])['par2_count']);
        $xref = new XrefService(false);
        $this->assertSame(['alt.binaries.teevee:123', 'alt.binaries.misc:4'], $xref->extractTokensPhp('news.example.com alt.binaries.teevee:123 alt.binaries.misc:4'));
        $this->assertSame(['alt.binaries.teevee'], $xref->extractGroupNamesPhp('srv alt.binaries.teevee:1 alt.binaries.teevee:2'));
        $this->assertSame(1, XrefService::summarizeXrefBatchPhp(['srv alt.binaries.teevee:1 alt.binaries.misc:2'])['multi_group_count']);
    }

    public function test_w62_exports_resolve_and_guard_their_buffers(): void
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
        }
    }

    public function test_w62_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [implode('|', self::TEMPLATES[$class]), 'Xref: srv alt.binaries.x:1', '[01/12] - "x.rar" yEnc (1/50)', 'audio|AAC||128 kb/s|5400000|2|',
                '5070|0|-1|1073741824|0|1', 'x|0|1|3', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w62Invoke');
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

        $router = PerArticleObfuscationRouter::w62ArticleRoutePlanBatchPhp(['[01/12] - "Abc123def456ghi789.part01.rar" yEnc (1/50)',
            '"x.vol00+01.par2" yEnc (1/1)', 'Usenet Index Post 1', '', 'Show.S01E02 [5/3] - "show.mkv" (2/9)', 'plain subject yEnc (1/1)', 'no counter']);
        $this->assertSame([3, 6, 1 | 4 | 8 | 32 | 64, 1, 18, 1, 50, 1, 12], [$bits($router[0], 0, 3), $bits($router[0], 3, 4), $bits($router[0], 7, 8),
            $bits($router[0], 15, 2), $bits($router[0], 17, 6), $bits($router[0], 23, 10), $bits($router[0], 33, 10), $bits($router[0], 43, 10), $bits($router[0], 53, 10)]);
        $this->assertSame([2, 1, 1 | 2 | 4 | 32], [$bits($router[1], 0, 3), $bits($router[1], 3, 4), $bits($router[1], 7, 8)]);
        $this->assertSame(1, $bits($router[2], 0, 3));
        $this->assertSame(0, $router[3]);
        $this->assertSame([5, 8, 2], [$bits($router[4], 0, 3), $bits($router[4], 3, 4), $bits($router[4], 15, 2)]);
        $this->assertSame(4, $bits($router[5], 0, 3));
        $this->assertSame(6, $bits($router[6], 0, 3));
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], \array_slice($router, 7, 7), 'rule counts');
        $this->assertSame([50, 6], \array_slice($router, 7 + 29, 2), 'largest part total and distinct filenames');

        $creation = ReleaseCreationService::w62ReleaseCreationPlanBatchPhp(['Some.Release-GRP|1048576000|50|3|100|10|7', 'some.release-grp|0|50|3',
            'x|1|1|2', 'x|a|1|3', '|1|1|3', 'y|100|5|3|200', 'z|300|5|3|200|10', "Name\x01 \"yEnc\"|1|1|3"]);
        $this->assertSame([0, 1, 30, 50, 16, 0, (int) hexdec(hash('fnv1a32', 'some.release-grp')) & 0x3FFFF], [$bits($creation[0], 0, 3),
            $bits($creation[0], 3, 7), $bits($creation[0], 10, 7), $bits($creation[0], 17, 12), $bits($creation[0], 29, 10), $bits($creation[0], 39, 6),
            $bits($creation[0], 45, 18)]);
        $this->assertSame([3, 64], [$bits($creation[1], 0, 3), $bits($creation[1], 3, 7)]);
        $this->assertSame([2, 6, 1, 4, 5], [$bits($creation[2], 0, 3), $bits($creation[3], 0, 3), $bits($creation[4], 0, 3), $bits($creation[5], 0, 3),
            $bits($creation[6], 0, 3)]);
        $this->assertSame([0, 2 | 4 | 16, 1], [$bits($creation[7], 0, 3), $bits($creation[7], 3, 7), $bits($creation[7], 39, 6)]);
        $this->assertSame([2, 1, 1, 1, 1, 1, 1], \array_slice($creation, 8, 7), 'decision counts');
        $this->assertSame([1000, 50], \array_slice($creation, 8 + 14, 2), 'created MiB and largest file count');

        $extra = ReleaseExtraService::w62ReleaseExtraPlanBatchPhp(['video|Matroska|V_MPEGH/ISO/HEVC|5 500 kb/s|1h 30mn|0|',
            'audio|AC-3||640 Kbps|5400000|6 channels|en', 'subs|SubRip|||||', 'image|PNG|x|bad|1 parsec|99|pt-BR', 'video|MPEG-4|xvid|5.5Mb/s|45s|2|eng']);
        $this->assertSame([1, 0, 1, 0, 5500, 5400, 0, 0], [$bits($extra[0], 0, 2), $bits($extra[0], 2, 4), $bits($extra[0], 6, 4), $bits($extra[0], 10, 6),
            $bits($extra[0], 16, 20), $bits($extra[0], 36, 20), $bits($extra[0], 56, 4), $bits($extra[0], 60, 3)]);
        $this->assertSame([2, 9, 5, 8, 640, 5400, 6, 1], [$bits($extra[1], 0, 2), $bits($extra[1], 2, 4), $bits($extra[1], 6, 4), $bits($extra[1], 10, 6),
            $bits($extra[1], 16, 20), $bits($extra[1], 36, 20), $bits($extra[1], 56, 4), $bits($extra[1], 60, 3)]);
        $this->assertSame([3, 9, 16], [$bits($extra[2], 0, 2), $bits($extra[2], 6, 4), $bits($extra[2], 10, 6)]);
        $this->assertSame([0, 8, 12, 1 | 2 | 4, 15, 4], [$bits($extra[3], 0, 2), $bits($extra[3], 2, 4), $bits($extra[3], 6, 4), $bits($extra[3], 10, 6),
            $bits($extra[3], 56, 4), $bits($extra[3], 60, 3)]);
        $this->assertSame([6, 2, 5500, 45, 2], [$bits($extra[4], 2, 4), $bits($extra[4], 6, 4), $bits($extra[4], 16, 20), $bits($extra[4], 36, 20),
            $bits($extra[4], 60, 3)]);
        $this->assertSame([5400 * 2 + 45, 5500, 3], \array_slice($extra, 5 + 33, 3), 'seconds sum, largest kbps and distinct languages');

        $processing = ReleaseProcessingService::w62ReleaseProcessingPlanBatchPhp(['2040|-1|-1|5368709120|0|0', '5070|0|0|1|0|9', '5040|0|0|1|4|0', 'x',
            '3030|-5|-2|0|0|0', '4010|0|0|0|0|0', '6045|0|0|1|0|20']);
        $this->assertSame([1, 1 | 2 | 512, 2, 15, 15, 2, 3, 0], [$bits($processing[0], 0, 2), $bits($processing[0], 2, 10), $bits($processing[0], 12, 4),
            $bits($processing[0], 16, 10), $bits($processing[0], 26, 24), $bits($processing[0], 50, 4), $bits($processing[0], 54, 4), $bits($processing[0], 58, 4)]);
        $this->assertSame([2, 128, 8, 2, 15], [$bits($processing[1], 0, 2), $bits($processing[1], 2, 10), $bits($processing[1], 12, 4),
            $bits($processing[1], 16, 10), $bits($processing[1], 26, 24)]);
        $this->assertSame([0, 0, 8], [$bits($processing[2], 0, 2), $bits($processing[2], 2, 10), $bits($processing[2], 58, 4)]);
        $this->assertSame(3 | 15 << 26, $processing[3]);
        $this->assertSame([1, 1 | 64, 7, 4, 1 | 2 | 4], [$bits($processing[4], 0, 2), $bits($processing[4], 2, 10), $bits($processing[4], 12, 4),
            $bits($processing[4], 16, 10), $bits($processing[4], 58, 4)]);
        $this->assertSame(0, $bits($processing[5], 0, 2));
        $this->assertSame([2, 256], [$bits($processing[6], 0, 2), $bits($processing[6], 2, 10)], 'running 19 + 2 exceeds the budget of 20');
        $this->assertSame([2, 2, 2, 1], \array_slice($processing, 7, 4), 'decision counts');
        $this->assertSame([19, 4, 15], \array_slice($processing, 7 + 18, 3), 'dispatched, deferred and largest cost');

        $xref = XrefService::w62XrefPlanBatchPhp(['Xref: news.example.com alt.binaries.teevee:123 alt.binaries.TEEVEE:9 a.b.x:0',
            'alt.binaries.misc:5 bogus', '', 'srv-only', "srv\talt.binaries.misc:4294967296"]);
        $this->assertSame([1, 1, 1, 3, 2, 1, 0, 3, 7, 19, 4 | 8], [$bits($xref[0], 0, 2), $bits($xref[0], 2, 1), $bits($xref[0], 3, 1), $bits($xref[0], 4, 6),
            $bits($xref[0], 10, 6), $bits($xref[0], 16, 6), $bits($xref[0], 22, 6), $bits($xref[0], 28, 6), $bits($xref[0], 34, 6), $bits($xref[0], 40, 8),
            $bits($xref[0], 58, 4)]);
        $this->assertSame([3, 0, 1, 1], [$bits($xref[1], 0, 2), $bits($xref[1], 2, 1), $bits($xref[1], 4, 6), $bits($xref[1], 22, 6)]);
        $this->assertSame(0, $xref[2]);
        $this->assertSame([2, 1], [$bits($xref[3], 0, 2), $bits($xref[3], 2, 1)]);
        $this->assertSame([1, 33, 2], [$bits($xref[4], 0, 2), $bits($xref[4], 34, 6), $bits($xref[4], 58, 4)]);
        $this->assertSame([3, 2], \array_slice($xref, 5 + 15, 2), 'distinct groups across the batch and the largest per-header count');
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
        // Edge values in every field position of the class template (and of a seven-field row).
        $fields = self::TEMPLATES[$class] + array_fill(0, 7, '1');
        for ($slot = 0; $slot < \count($fields); $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $row = $fields;
                $row[$slot] = $edge;
                $rows[] = implode('|', $row);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Edges glued to realistic items with every separator.
        foreach ([' ', '.', '-', '_', ':', '|', ' - ', "\t", ''] as $glue) {
            $this->assertParity($class, array_map(static fn (string $e): string => $template.$glue.$e.$glue.'2019', $edges), 'glued '.bin2hex($glue));
            $this->assertParity($class, array_map(static fn (string $e): string => $e.$glue.$template, $edges), 'prefixed '.bin2hex($glue));
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Game.PS4|x', 7 => 'alt.binaries.tv:1', 'a' => '', 'c' => 'Book.epub|10'];
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
        foreach ([621, 622, 623] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6211));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBhHmMsSkKxXyY0123456789|||..::,-_ [](\"/@+\0\t\n\x0b\x7f\xff\xc3\xa9", $length);
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
            $tokenSoup[] = implode($this->pick($random, [' ', '.', '-', '_', ' - ', '', '|', ':', "\t"]), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6212)), true), 0, 80));
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W621_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~6.3-7.4), common mean / p99, uncommon mean / p99:
        // - PerArticleObfuscationRouter:  5.28x / 3.85x, 4.36x / 3.69x
        // - ReleaseCreationService:       5.57x / 4.59x, 4.39x / 3.54x
        // - ReleaseExtraService:          7.85x / 6.01x, 6.01x / 4.78x
        // - ReleaseProcessingService:    12.81x / 10.88x, 8.02x / 5.26x
        // - XrefService:                  3.44x / 2.82x, 3.35x / 2.79x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W621_BENCH=1 php artisan test --compact tests/Unit/RustObfuscationRouterReleaseCreationExtraProcessingXrefW62ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W621_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W621_BENCH=1 to run the Wave 62.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6216));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W621_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6217)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6218)), true),
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
        fwrite(STDERR, "\nRustObfuscationRouterReleaseCreationExtraProcessingXrefW62 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW62RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
            if (! $qualifies) {
                $this->assertFalse($class::usesW62RustFfi(), $class.' retains PHP by default');
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
                PerArticleObfuscationRouter::class => $this->routerItem($random, $uncommon),
                ReleaseCreationService::class => $this->creationItem($random, $uncommon),
                ReleaseExtraService::class => $this->extraItem($random, $uncommon),
                ReleaseProcessingService::class => $this->processingItem($random, $uncommon),
                default => $this->xrefItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace(' ', "\t", $row), str_replace('|', '| ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function hex(Randomizer $random, int $length): string
    {
        return $random->getBytesFromString('0123456789abcdef', $length);
    }

    private function title(Randomizer $random): string
    {
        return $this->pick($random, ['Some.Show', 'The.Movie', 'Artist-Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game', 'Old.Film'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', '2160p', 'WEB-DL', 'BluRay', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX', 'CtrlHD']);
    }

    private function routerItem(Randomizer $random, bool $noisy): string
    {
        $files = $random->getInt(1, 120);
        $file = $random->getInt(1, $files);
        $parts = $random->getInt(1, 200);
        $part = $random->getInt(1, $parts);
        $obfuscated = $random->getInt(0, 1) === 1 ? $random->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $random->getInt(16, 40)) : $this->title($random);
        $ext = $this->pick($random, ['part'.sprintf('%02d', $random->getInt(1, 60)).'.rar', 'rar', 'r'.sprintf('%02d', $random->getInt(0, 99)), sprintf('%03d', $random->getInt(1, 200)),
            'par2', 'vol'.sprintf('%02d', $random->getInt(0, 63)).'+'.sprintf('%02d', $random->getInt(1, 64)).'.par2', 'srr', 'sfv', 'nfo', 'nzb', 'mkv', '7z', 'zip']);
        $subject = $this->pick($random, [
            '['.sprintf('%02d', $file).'/'.sprintf('%02d', $files).'] - "'.$obfuscated.'.'.$ext.'" yEnc ('.$part.'/'.$parts.')',
            $this->hex($random, 32).' ['.$file.'/'.$files.'] - "'.$obfuscated.'.'.$ext.'" yEnc ('.$part.'/'.$parts.') '.$random->getInt(1000, 999999999),
            $this->title($random).' - "'.$obfuscated.'.'.$ext.'" yEnc ('.$part.'/'.$parts.')',
            '"'.$obfuscated.'.'.$ext.'" yEnc ('.$part.'/'.$parts.')',
            'Usenet Index Post '.$random->getInt(1, 99999).' yEnc (1/1)',
            $obfuscated.' ('.$part.'/'.$parts.')',
        ]);
        if ($noisy) {
            $subject = $this->pick($random, [$subject, str_replace('/', '', $subject), str_replace('"', '', $subject), '('.$parts.'/'.$part.') '.$subject,
                '['.$files.'/'.$file.'] '.$subject, str_repeat($subject, 6), $subject.' (0/0)', "\xff".$subject, 'Usenet Index Post', '[1/2]', '(999999999/9999999)']);
        }

        return $subject;
    }

    private function creationItem(Randomizer $random, bool $noisy): string
    {
        $fields = [$this->title($random), (string) $random->getInt(1, 80_000_000_000), (string) $random->getInt(1, 400), $this->pick($random, ['3', '3', '3', '3', '2', '4']),
            $this->pick($random, ['', '0', '104857600', '1048576']), $this->pick($random, ['', '0', '1', '5', '10']), $this->pick($random, ['', '0', '0', (string) $random->getInt(1, 999999)])];
        if ($random->getInt(0, 9) === 0) {
            $fields[0] = $this->pick($random, ['Duplicate.Release-GRP', 'duplicate.release-grp', 'Some "quoted" yEnc name']);
        }
        if ($noisy) {
            $slot = $random->getInt(0, 6);
            $fields[$slot] = $this->pick($random, ['', ' ', 'x', '-1', '1.5', '9999999999999999', "a\x01b", "caf\xc3\xa9", str_repeat('n', 300), '0']);
        }

        return implode('|', \array_slice($fields, 0, $noisy ? $random->getInt(1, 7) : 7));
    }

    private function extraItem(Randomizer $random, bool $noisy): string
    {
        $fields = match ($random->getInt(0, 2)) {
            0 => ['Video', $this->pick($random, ['Matroska', 'MPEG-4', 'AVI']), $this->pick($random, ['V_MPEGH/ISO/HEVC', 'avc1', 'V_MPEG4/ISO/AVC', 'XVID', 'DX50', 'WVC1', 'vp9', '27']),
                $this->pick($random, [$random->getInt(800, 40000).' kb/s', $random->getInt(1, 60).'.'.$random->getInt(0, 999).' Mb/s', (string) $random->getInt(100000, 50000000), '']),
                $this->pick($random, [(string) $random->getInt(1000, 12_000_000), $random->getInt(0, 3).' h '.$random->getInt(0, 59).' min', $random->getInt(1, 59).'mn '.$random->getInt(0, 59).'s', '']),
                '', ''],
            1 => ['Audio', $this->pick($random, ['AAC', 'AC-3', 'E-AC-3', 'DTS', 'FLAC', 'MPEG Audio']), $this->pick($random, ['', 'A_AAC', 'mp4a', 'A_AC3']),
                $this->pick($random, [$random->getInt(64, 1536).' kb/s', $random->getInt(64, 1536).'Kbps', '']), (string) $random->getInt(1000, 12_000_000),
                $random->getInt(1, 8).' channels', $this->pick($random, ['en', 'eng', 'English', 'de', 'ja', '', 'pt-BR'])],
            default => ['Text', $this->pick($random, ['SubRip', 'ASS', 'PGS', 'UTF-8', 'VobSub']), '', '', '', '', $this->pick($random, ['en', 'eng', 'fr', 'Spanish', '', 'zh-Hans'])],
        };
        if ($noisy) {
            $slot = $random->getInt(0, 6);
            $fields[$slot] = $this->pick($random, ['', ' ', 'x', '1.', '1h 2', '999999999 Mb/s', '1234567890123', "caf\xc3\xa9", str_repeat('9 h ', 40), 'MPEG']);
        }

        return implode('|', $fields);
    }

    private function processingItem(Randomizer $random, bool $noisy): string
    {
        $fields = [(string) $this->pick($random, [1030, 1180, 2040, 2045, 2060, 3010, 3030, 3040, 4010, 4050, 5030, 5040, 5070, 6045, 7020, 10, 20]),
            (string) $random->getInt(-6, 1), (string) $random->getInt(-6, 1), (string) $random->getInt(0, 60_000_000_000),
            $this->pick($random, ['0', '0', '1', '2', '512', (string) $random->getInt(0, 1023)]), $this->pick($random, ['0', '0', '50', '200', '1000'])];
        if ($noisy) {
            $slot = $random->getInt(0, 5);
            $fields[$slot] = $this->pick($random, ['', ' ', 'x', '-', '--1', '1.5', '99999', '9999999999999999', '-0', "caf\xc3\xa9"]);
        }

        return implode('|', \array_slice($fields, 0, $noisy ? $random->getInt(1, 6) : 6));
    }

    private function xrefItem(Randomizer $random, bool $noisy): string
    {
        $groups = ['alt.binaries.teevee', 'alt.binaries.multimedia', 'alt.binaries.hdtv.x264', 'alt.binaries.moovee', 'alt.binaries.boneless', 'a.b.erotica',
            'alt.binaries.mom', 'alt.binaries.misc', 'alt.binaries.cores', 'alt.binaries.frogs'];
        $tokens = [];
        for ($i = 0, $n = $random->getInt(1, 6); $i < $n; $i++) {
            $tokens[] = $this->pick($random, $groups).':'.$random->getInt(1, 9_999_999_999);
        }
        $line = $this->pick($random, ['Xref: ', '', '']).$this->pick($random, ['news.example.com', 'news-big.provider.net', 'feeder.eu']).' '.implode(' ', $tokens);
        if ($noisy) {
            $line = $this->pick($random, [$line, $line.' bogus', $line.' alt.binaries.x:', strtoupper($line), str_replace(' ', "\t", $line), $line.' '.$tokens[0],
                'alt.binaries.x:0', "Xref:\t".$line, $line.' :1', str_repeat($line.' ', 5)]);
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
     * Calls a Wave 62.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w62RustFfiState'))->invoke(null, 'set', $enabled);
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
