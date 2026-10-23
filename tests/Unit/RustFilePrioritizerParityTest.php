<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\FilePrioritizer;
use App\Support\FilenameSanitizer;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RustFilePrioritizerParityTest extends TestCase
{
    private const string MISSING_LIBRARY = '/nonexistent/libnntmux_pipeline.so';

    private const array TOKENS = [
        'Some', 'Release', 'Name', '2020', '1080p', 'WEB-DL', 'x264', 'GRP', 'sample', 'Sample', 'proof',
        'PROOF', 'subs', 'sub', 'Subs', 'thumb', 'thumbs', 'VIDEO_TS', 'AUDIO_TS', 'video-ts', 'vts_01_1',
        'VTS0101', 'vts.1.12', '1', '01', '123', '1234', 'part', 'Part', 'cover', 'a', 'b', 'Ünï', 'x',
    ];

    private const array SEPARATORS = ['.', '-', '_', ' ', '/', '\\', '', '..', '.-'];

    private const array EXTENSIONS = [
        '.rar', '.RAR', '.part01.rar', '.part1.rar', '.part001.rar', '.part02.rar', '.PART10.RAR', '.part0.rar',
        '.r00', '.r01', '.R123', '.r1234', '.r1', '.001', '.0001', '.srr', '.SRS', '.nfo', '.NFO', '.mkv', '.MKV',
        '.avi', '.mp4', '.m4v', '.wmv', '.divx', '.ts', '.m2ts', '.url', '.URL', '.jpg', '.txt', '.sfv', '.par2',
        '.vob', '', '.', '/', '\\', "\n", ".rar\n", ".mkv\n", ".url\n", ".r01\n", ".srr\n", '.mkv/',
    ];

    private static function ffiAvailable(): bool
    {
        return RustPipelineLibrary::resolveDomain('file_prioritizer', FilePrioritizer::FFI_CDEF) !== null;
    }

    private static function randomName(int $seed): string
    {
        mt_srand($seed);
        $parts = [];
        $tokens = mt_rand(0, 6);
        for ($i = 0; $i < $tokens; $i++) {
            $parts[] = self::TOKENS[mt_rand(0, \count(self::TOKENS) - 1)];
            $parts[] = self::SEPARATORS[mt_rand(0, \count(self::SEPARATORS) - 1)];
        }
        if (mt_rand(0, 3) === 0) {
            $parts[] = '-'.self::TOKENS[mt_rand(0, \count(self::TOKENS) - 1)];
        }
        $parts[] = self::EXTENSIONS[mt_rand(0, \count(self::EXTENSIONS) - 1)];
        if (mt_rand(0, 9) === 0) {
            $parts[] = self::EXTENSIONS[mt_rand(0, \count(self::EXTENSIONS) - 1)];
        }
        $name = implode('', $parts);

        return mt_rand(0, 4) === 0 ? strtoupper($name) : $name;
    }

    /**
     * @return list<string>
     */
    private static function curatedNames(): array
    {
        return [
            '', '.', '/', '\\', "\n", 'Film ;-)/VIDEO_TS/VIDEO_TS.VOB', 'Film ;-)/VIDEO_TS/VTS_01_1.VOB', 'Film ;-)/1.jpg',
            'Film ;-)/cover the fisher king.jpg', 'Film ;-)/Extreem Online Contact.url', 'Film ;-)/release.nfo',
            'Some.Release.2020-GRP.part01.rar', 'Some.Release.2020-GRP.part02.rar', 'Some.Release.2020-GRP.rar',
            'Some.Release.2020-GRP.r00', 'Some.Release.2020-GRP.001', 'some.release.2020-grp.sample.mkv',
            'Some.Release.2020-subs', 'Some.Release.2020-GRP.rar'."\n", "Some.Release.2020-GRP\n",
            "Some.Release.2020-GRP\n.rar", 'Some.Release.2020-GRP.part1.rar'."\n", 'a-bc.rar', 'abcdefghij-sample.rar',
            'abcdefghi_-proof', 'abcdefghij.-sub', 'Release.Name-GRPGRPGRPGRPGRPG.rar', 'Release.Name-GRPGRPGRPGRPGRPGX.rar',
            'dir\\Some.Release.2021-GRP.rar', 'dir/', 'dir/\n', 'x.mkv/', 'x.MKV', 'VTS_1_1.vob', 'vts-01-12.vob',
            'vts011.vob', 'vts01112.vob', '123.jpg', '1234.jpg', '_1_.jpg', 'video_ts.ifo', 'audiots.bup', 'video__ts',
            'Show.S01E01.720p.HDTV.x264-GRP.mkv', 'Show.S01E01.720p.HDTV.x264-GRP.sample.mkv', 'Show.thumbs.db',
            'Ünïcødé.Release-GRP.rar', "Bad\x01Name.rar", 'Some.Release.2020-ÜGRP.rar',
        ];
    }

    public function test_rust_ffi_domain_is_available(): void
    {
        $this->assertTrue(self::ffiAvailable(), 'Rust file_prioritizer FFI domain must be available');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function fileListProvider(): array
    {
        $cases = ['curated' => [self::curatedNames()], 'empty' => [[]], 'single' => [['Some.Release.2020-GRP.rar']]];
        for ($seed = 1; $seed <= 400; $seed++) {
            $files = [];
            mt_srand($seed * 7919);
            $size = mt_rand(1, 40);
            for ($i = 0; $i < $size; $i++) {
                $files[] = self::randomName($seed * 1000 + $i);
            }
            $cases["random_{$seed}"] = [$files];
        }

        return $cases;
    }

    /**
     * @param  list<string>  $files
     */
    #[DataProvider('fileListProvider')]
    public function test_file_prioritizer_parity(array $files): void
    {
        if (! self::ffiAvailable()) {
            $this->markTestSkipped('Rust file_prioritizer FFI domain unavailable');
        }
        $rust = new FilePrioritizer;
        $php = new FilePrioritizer(self::MISSING_LIBRARY);

        $this->assertSame($php->prioritizeForMatching($files), $rust->prioritizeForMatching($files));
        $this->assertSame($php->prioritizeForPreDb($files), $rust->prioritizeForPreDb($files));
        $this->assertSame($php->findReleaseNameFromRarFiles($files), $rust->findReleaseNameFromRarFiles($files));
        foreach ($files as $file) {
            $this->assertSame($php->getCrcPriority($file), $rust->getCrcPriority($file), var_export($file, true));
        }
    }

    public function test_release_name_tie_breaks_by_count_then_first_longest(): void
    {
        $files = [
            'Other.Release.Name.2021-GRP.rar',
            'dir/Some.Release.2020-GRP.part01.rar',
            'dir/Some.Release.2020-GRP.part02.rar',
            'Some.Release.2020-ABC.rar',
            'Some.Release.2020-ABD.rar',
        ];
        $this->assertSame('Some.Release.2020-GRP', (new FilePrioritizer)->findReleaseNameFromRarFiles($files));
        $this->assertSame(
            'Other.Release.Name.2021-GRP',
            (new FilePrioritizer)->findReleaseNameFromRarFiles(array_slice($files, 0, 2)),
        );
    }

    public function test_non_string_and_nul_inputs_use_php_path(): void
    {
        $prioritizer = new FilePrioritizer;
        $this->assertSame(["a\0b.mkv"], $prioritizer->prioritizeForMatching(["a\0b.mkv"]));
        $this->assertSame(50, $prioritizer->getCrcPriority("a\0b"));

        $this->expectException(\TypeError::class);
        $prioritizer->findReleaseNameFromRarFiles([123]);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function sanitizerProvider(): array
    {
        $cases = [
            'null' => [null, 'release-123'],
            'empty' => ['', 'release-123'],
            'separators' => [' Show / Name \\ Part ', 'download'],
            'controls' => ["Bad\0Name:*?\"<>|\x1FTest", 'download'],
            'spaces' => [' The   File,  Name , Part 2 ', 'download'],
            'junk' => ['...___---', 'release-123'],
            'long' => [str_repeat('a', 300), 'download'],
            'long_trailing_dots' => [str_repeat('a', 199).'....b', 'download'],
            'unicode' => ['Résumé／Season 1\\Finale', 'download'],
            'unicode_fallback' => ['', 'Résumé'],
            'control_fallback' => ['', "nfo\t-1"],
            'empty_fallback' => ['', ''],
            'dot_fallback' => ['...', '..'],
            'high_byte' => ["Bad\xFFName", 'download'],
            'leading_dots' => ['..hidden.file', 'download'],
            'vertical_tab' => ["A\x0BB\x0CC", 'x'],
        ];
        mt_srand(424242);
        $alphabet = " \t\n\r\0\x0B\x0C\x1F\x7F._-/\\:*?\"<>|,()[]{}!@#\$%^&+=~`'abcXYZ019";
        for ($i = 0; $i < 300; $i++) {
            $name = '';
            $len = mt_rand(0, $i % 10 === 0 ? 260 : 30);
            for ($j = 0; $j < $len; $j++) {
                $name .= $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
            }
            $fallback = '';
            $fbLen = mt_rand(0, 12);
            for ($j = 0; $j < $fbLen; $j++) {
                $fallback .= $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
            }
            $cases["random_{$i}"] = [$name, $fallback];
        }

        return $cases;
    }

    #[DataProvider('sanitizerProvider')]
    public function test_filename_sanitizer_parity(?string $name, string $fallback): void
    {
        $this->assertSame(FilenameSanitizer::sanitizePhp($name, $fallback), FilenameSanitizer::sanitize($name, $fallback));
        $this->assertSame(
            FilenameSanitizer::asciiFallbackPhp($name, $fallback),
            FilenameSanitizer::asciiFallback($name, $fallback),
        );
    }

    public function test_printable_ascii_is_identity_under_str_ascii(): void
    {
        $printable = implode('', array_map(chr(...), range(0x20, 0x7E)));
        $this->assertSame($printable, Str::ascii($printable));
    }
}
