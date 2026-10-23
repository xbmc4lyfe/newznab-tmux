<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class RustAdditionalNzbPar2ParityTest extends TestCase
{
    private const string DEFAULT_AUDIO_FILE_REGEX = '\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)';

    private const string DEFAULT_IGNORE_BOOK_REGEX = '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i';

    private const string DEFAULT_SUPPORT_FILE_REGEX = '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)';

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    #[Test]
    public function par2_file_mapper_rust_matches_php_across_workloads(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());

        $rustMapper = new Par2FileMapper;
        $phpMapper = new Par2FileMapper('/nonexistent/libnntmux_pipeline.so');

        $nzbContents = [
            $this->nzbEntry(3000, 1),
            $this->nzbEntry(51000, 10),
            $this->nzbEntry(51000, 10),
            $this->nzbEntry(49800, 10),
            $this->nzbEntry(100000, 3),
            $this->nzbEntry(100000, 4),
            $this->nzbEntry(12000, 2, fileCount: 2),
        ];

        $part01 = new Par2FileEntry('Movie.part01.rar', 50000);
        $part02 = new Par2FileEntry('Movie.part02.rar', 50000);
        $part03 = new Par2FileEntry('Movie.part03.rar', 47800);
        $sample = new Par2FileEntry('Movie.sample.mkv', 2900);
        $b90 = new Par2FileEntry('Boundary90.mkv', 90000);
        $b1001 = new Par2FileEntry('Boundary1001.mkv', 100100);
        $files = [$part01, $part02, $part03, $sample, $b90, $b1001];

        foreach ([null, 5000, 30000] as $partSize) {
            foreach ([[], [1 => $part02], [2 => $part01, 4 => $b90]] as $verified) {
                $expected = $phpMapper->map($nzbContents, $files, $partSize, $verified);
                $actual = $rustMapper->map($nzbContents, $files, $partSize, $verified);
                $this->assertEquals($expected, $actual);
                $this->assertSame(array_keys($expected), array_keys($actual));
            }

            foreach ($files as $file) {
                $this->assertSame(
                    $phpMapper->candidateIndexes($nzbContents, $file, $partSize),
                    $rustMapper->candidateIndexes($nzbContents, $file, $partSize),
                );
            }
        }
    }

    #[Test]
    public function nzb_extract_message_ids_rust_matches_php_across_edge_cases(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());

        $nzbService = $this->createStub(NzbService::class);
        $nzbParserService = new NzbParserService;
        $rustParser = new NzbContentParser($nzbService, $nzbParserService);
        $phpParser = new NzbContentParser($nzbService, $nzbParserService, libraryPath: '/nonexistent/libnntmux_pipeline.so');

        $nzbContents = [
            ['title' => '"Release.2026.nfo" yEnc (1/1)', 'segments' => ['nfo@example.com']],
            ['title' => '"Release.vol00+01.par2" yEnc (1/2)', 'segments' => ['par1@example.com', 'par2@example.com']],
            ['title' => '"Release.part01.rar" yEnc (1/50)', 'segments' => ['rar1@example.com', 'rar2@example.com', 'rar3@example.com']],
            ['title' => '"Release.epub.rar" yEnc (1/5)', 'segments' => ['book1@example.com']],
            ['title' => '"Release.pdf.part02.rar" yEnc (1/5)', 'segments' => ['book2@example.com']],
            ['title' => '"Release.sample.jpg" yEnc (1/2)', 'segments' => ['samplejpg1@example.com', 'samplejpg2@example.com']],
            ['title' => '"Release.sample.mkv" yEnc (1/3)', 'segments' => ['samplemkv1@example.com', 'samplemkv2@example.com', 'samplemkv3@example.com']],
            ['title' => '"Release.Track01.FLAC" yEnc (1/4)', 'segments' => ['flac1@example.com', 'flac2@example.com']],
            ['title' => '"EmptySegments.mp3" yEnc (1/1)', 'segments' => []],
        ];

        $configs = [
            $this->makeDefaultConfig([
                'processThumbnails' => true,
                'processJPGSample' => true,
                'processMediaInfo' => true,
                'processAudioInfo' => true,
                'segmentsToDownload' => 2,
            ]),
            $this->makeDefaultConfig([
                'processThumbnails' => false,
                'processJPGSample' => true,
                'processMediaInfo' => false,
                'processAudioInfo' => true,
                'segmentsToDownload' => 1,
            ]),
            $this->makeDefaultConfig([
                'processThumbnails' => true,
                'processJPGSample' => false,
                'processMediaInfo' => true,
                'processAudioInfo' => false,
                'segmentsToDownload' => 3,
            ]),
        ];

        foreach (['alt.binaries.movies', 'alt.binaries.sounds.flac', 'alt.binaries.mp3'] as $group) {
            foreach ($configs as $config) {
                $expected = $phpParser->extractMessageIDsPhp($nzbContents, $group, $config);
                $actual = $rustParser->extractMessageIDs($nzbContents, $group, $config);
                $fallback = $phpParser->extractMessageIDs($nzbContents, $group, $config);
                $this->assertSame($expected, $actual);
                $this->assertSame($expected, $fallback);
            }
        }
    }

    #[Test]
    public function nzb_source_file_inventory_rust_matches_php_across_valid_and_invalid_xml(): void
    {
        $this->assertTrue(RustPipelineLibrary::available());

        $nzbService = $this->createStub(NzbService::class);
        $nzbParserService = new NzbParserService;
        $rustParser = new NzbContentParser($nzbService, $nzbParserService);
        $phpParser = new NzbContentParser($nzbService, $nzbParserService, libraryPath: '/nonexistent/libnntmux_pipeline.so');

        $validNzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/2003/nzb/nzb-1.1.dtd">
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head>
    <meta type="title">Movie.2026.1080p</meta>
    <meta type="nntmux:declared-file-count">3</meta>
    <meta type="nntmux:verified-file-count">3</meta>
  </head>
  <file poster="poster@example.com" date="1700000000" subject="[01/03] - &quot;Movie.2026.1080p.mkv&quot; yEnc (1/3)">
    <groups><group>alt.binaries.movies</group></groups>
    <segments>
      <segment bytes="750000" number="3">m-seg3@example.com</segment>
      <segment bytes="750000" number="1">m-seg1@example.com</segment>
      <segment bytes="750000" number="2">m-seg2@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com" date="1700000001" subject="Movie.2026.sample.mkv yEnc (1/1)">
    <groups><group>alt.binaries.movies</group></groups>
    <segments>
      <segment bytes="50000" number="1">s-seg1@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com" date="1700000002" subject="&quot;Movie.2026.nfo&quot;">
    <groups><group>alt.binaries.movies</group></groups>
    <segments>
      <segment bytes="1000" number="1">nfo-seg1&amp;2@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $xmlCases = [
            $validNzb,
            // Declared file count exceeds actual files
            str_replace('<meta type="nntmux:declared-file-count">3</meta>', '<meta type="nntmux:declared-file-count">4</meta>', $validNzb),
            // Non-digit declared file count
            str_replace('<meta type="nntmux:declared-file-count">3</meta>', '<meta type="nntmux:declared-file-count">abc</meta>', $validNzb),
            // Missing segment 2 (gap in segment numbers)
            str_replace('<segment bytes="750000" number="2">m-seg2@example.com</segment>', '', $validNzb),
            // Duplicate segment number 1
            str_replace('number="2"', 'number="1"', $validNzb),
            // Duplicate firstMessageId across files
            str_replace('>s-seg1@example.com<', '>m-seg1@example.com<', $validNzb),
            // Subject part total mismatch (1/4 instead of 1/3)
            str_replace('yEnc (1/3)', 'yEnc (1/4)', $validNzb),
            // Path traversal / slash in filename
            str_replace('Movie.2026.sample.mkv', 'sub/Movie.2026.sample.mkv', $validNzb),
            // Backslash in filename
            str_replace('Movie.2026.sample.mkv', 'sub\\Movie.2026.sample.mkv', $validNzb),
            // Leading dot in filename
            str_replace('Movie.2026.sample.mkv', '.hidden.mkv', $validNzb),
            // Leading dash in filename
            str_replace('Movie.2026.sample.mkv', '-dash.mkv', $validNzb),
            // ENTITY declaration rejected
            "<?xml version=\"1.0\"?>\n<!DOCTYPE nzb [<!ENTITY xxe \"test\">]>\n<nzb></nzb>",
            // CDATA fallback to SimpleXML
            str_replace('>s-seg1@example.com<', '><![CDATA[s-seg1@example.com]]><', $validNzb),
        ];

        foreach ($xmlCases as $idx => $xml) {
            $parsedFiles = $nzbParserService->parseNzbFileList($xml, ['no-file-key' => false, 'strip-count' => true]);
            $expected = $phpParser->sourceFileInventoryPhp($xml, $parsedFiles);
            $actual = $rustParser->sourceFileInventory($xml, $parsedFiles);
            $fallback = $phpParser->sourceFileInventory($xml, $parsedFiles);
            $this->assertSame($expected, $actual, "Inventory mismatch on case #{$idx}");
            $this->assertSame($expected, $fallback, "Fallback mismatch on case #{$idx}");
        }
    }

    /**
     * @return array{title: string, size: int, filecount: int, segments: list<string>}
     */
    private function nzbEntry(int $size, int $segments = 1, int $fileCount = 1): array
    {
        static $counter = 0;
        $counter++;

        return [
            'title' => '"'.md5((string) $counter).'" yEnc (1/'.$segments.')',
            'size' => $size,
            'filecount' => $fileCount,
            'segments' => array_map(static fn (int $part): string => '<'.$counter.'.'.$part.'>', range(1, $segments)),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDefaultConfig(array $overrides = []): ProcessingConfiguration
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        /** @var ProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        $values = array_merge([
            'echoCLI' => false,
            'innerFileBlacklist' => false,
            'maxNestedLevels' => 1,
            'extractUsingRarInfo' => true,
            'fetchLastFiles' => false,
            'unrarPath' => false,
            'unzipPath' => false,
            'sevenZipPath' => false,
            'timeoutPath' => false,
            'timeoutSeconds' => 0,
            'queryLimit' => 25,
            'segmentsToDownload' => 2,
            'maximumRarSegments' => 3,
            'maximumRarPasswordChecks' => 1,
            'archiveProbeFiles' => 3,
            'seasonPackArchiveSets' => 3,
            'seasonPackMaxReleaseFiles' => 30,
            'maxSizeBytes' => 107374182400,
            'minSizeBytes' => 0,
            'alternateNNTP' => false,
            'ffmpegDuration' => 5,
            'addPAR2Files' => false,
            'processVideo' => false,
            'processThumbnails' => true,
            'processAudioSample' => false,
            'processJPGSample' => true,
            'processMediaInfo' => true,
            'processAudioInfo' => true,
            'processPasswords' => false,
            'audioSavePath' => sys_get_temp_dir().'/',
            'tmpUnrarPath' => sys_get_temp_dir().'/',
            'debugMode' => false,
            'searchEnabled' => false,
            'searchDriver' => 'manticore',
            'renameMusicMediaInfo' => false,
            'renamePar2' => false,
            'ffmpegPath' => false,
            'ffprobePath' => false,
            'mediaInfoPath' => false,
            'par2MediaMapping' => true,
            'par2IndexMaxSegments' => 2,
            'par2VerifyFiles' => 2,
            'mediaArchiveSlicing' => true,
            'mediaMainVideoFallback' => true,
            'mediaMidSources' => false,
            'mediaMaxBytes' => 16777216,
            'mediaSampleMaxBytes' => 8388608,
            'mediaMidBytes' => 3145728,
            'mediaMainVideoMinBytes' => 104857600,
            'mediaImageMaxBytes' => 8388608,
            'mediaStageMaxSeconds' => 30,
            'mediaFfmpegTimeout' => 30,
            'thumbnailFrameAttempts' => 4,
            'thumbnailMinStddev' => 12.0,
            'thumbnailMinLuma' => 24.0,
            'thumbnailMaxLuma' => 232.0,
            'releaseProcessingTimeout' => 0,
            'maxPpTimeoutCount' => 3,
            'audioFileRegex' => self::DEFAULT_AUDIO_FILE_REGEX,
            'ignoreBookRegex' => self::DEFAULT_IGNORE_BOOK_REGEX,
            'supportFileRegex' => self::DEFAULT_SUPPORT_FILE_REGEX,
            'videoFileRegex' => self::DEFAULT_VIDEO_FILE_REGEX,
        ], $overrides);

        foreach ($values as $property => $value) {
            $prop = new ReflectionProperty(ProcessingConfiguration::class, $property);
            $prop->setValue($config, $value);
        }

        return $config;
    }
}
