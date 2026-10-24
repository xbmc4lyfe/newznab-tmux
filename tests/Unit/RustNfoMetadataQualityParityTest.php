<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NfoService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustNfoMetadataQualityParityTest extends TestCase
{
    /** @var array<string, string> */
    private const METHODS = [
        'calculateNfoQuality' => 'calculate_quality',
        'hasAsciiArt' => 'has_ascii_art',
        'analyzeStructure' => 'analyze_structure',
        'parseNfoMetadata' => 'parse_metadata',
        'extractUrls' => 'extract_urls',
        'extractGroupName' => 'extract_group_name',
        'extractReleaseDate' => 'extract_release_date',
        'extractCodecInfo' => 'extract_codec_info',
        'extractFileSize' => 'extract_file_size',
        'extractAllMediaIds' => 'extract_all_media_ids',
        'parseShowId' => 'parse_show_id',
        'extractLanguage' => 'extract_language',
        'extractRuntime' => 'extract_runtime',
        'extractGenre' => 'extract_genre',
        'extractSoftwareInfo' => 'extract_software_info',
        'extractReleaseTitle' => 'extract_release_title',
    ];

    /** @var array<string, string> */
    private const TAG_METHODS = [
        'appendLanguageFromNfo' => 'language',
        'appendResolutionFromNfo' => 'resolution',
        'appendSourceFromNfo' => 'source',
        'appendAudioCodecFromNfo' => 'audio_codec',
        'appendTitleYearTags' => 'title_year_tags',
    ];

    private static function invokeFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function service(?string $path = null): NfoService
    {
        // These pure functions need no app, settings, cache or production database.
        $class = new ReflectionClass(NfoService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('libraryPath')->setValue($service, $path);

        return $service;
    }

    /** @return array<string, string> */
    public static function workloads(): array
    {
        $movie = "Example Movie (2026)\nTitle: Example.Movie.2026.1080p-GRP\nGRP presents\nRelease Date: 24/10/26\nVideo: x264\nResolution: 1920x1080\nAudio: DTS-HD MA\nLanguage: English / German\nSubtitle: French\nRuntime: 1h 30m\nSize: 1,5 GiB\nGenre: Action / Sci-Fi\nSource: BD\nhttps://www.imdb.com/title/tt1234567/\n";
        $software = "--- TEAM ---\nTitle: Example Software\nPlatform: Windows\nVersion: 3.12 build 45\nProtection: License Manager\nkeygen patch serial\nRelease: Oct 24, 2026\nSize: 450 MB\nwww.example.org/download\n";

        return [
            'common_movie' => $movie,
            'common_software' => $software,
            'common_plain' => "Release: example\nNotes: no media information here\n",
            'uncommon_empty' => '',
            'uncommon_whitespace' => str_repeat(" \n", 5000).'Actual presents',
            'uncommon_borders' => str_repeat("===\n", 1000).'Actual presents',
            'uncommon_binary' => "Title: Binary\0\xff Example\rLanguage: en\v\n".implode('', array_map(chr(...), range(0, 255))),
            'uncommon_64k' => str_repeat("ordinary unrelated filler text and whitespace \n", 1400).$movie,
            'uncommon_dense' => str_repeat("[Section]\n== Scene ==\nGroup: Test\nhttps://a.example http://b.example https://a.example www.example.org\n", 80).$software,
        ];
    }

    /** @return list<string> */
    private static function corpus(): array
    {
        $cases = array_values(self::workloads());
        array_push($cases, 'aaaaa', '----', '-----', '/|_/|_', '\\\\\\', '┌', '░', "\xe2\x94", "\0\x0b\t \r\n",
            "Field: one\nField: two\nField: three", str_repeat("x\n", 10), str_repeat('x', 99), str_repeat('x', 100),
            str_repeat('x', 500), str_repeat('x', 501), str_repeat('x', 19999), str_repeat('x', 20000),
            'https://a.example https://a.example https://b.example www.a.example www.b.example www.a.example',
            "date: 99/88/123\nreleased 2026-3-9\nRelease: January 7, 2004",
            'date: 2026-03-09', 'DATE: february 31, 1980', 'Size: 0 bytes',
            "audio: dolby\nvideo: mpeg2\nresolution: 8k", "audio: dd+\n1080p\n720 x 480", '1920×1080', 'runtime: 1:59:59', 'length: 90 minutes',
            'Language: french/English & French,German', "genre: Sci-Fi | sci-fi / Horror & RPG\n", "version: 1.2 b   003\nprotection: XX",
            "THE presents\nSCENE presents\n--- rEsPeCt ---\nirc.server.net #MiXeD", 'www.Mixed.net',
            "Title: date: foo\nA Valid Header Title\n", "Title: \0\xffmixed bytes\n", "Title: first\rTitle: second\n",
            'thetvdb.com/series/example-show anidb.net/anime/123 trakt.tv/shows/mixed-show',
            'tvmaze.com/shows/1234567 imdb.com/title/TT123456789 themoviedb.org/movie/123456789',
            'thetvdb.com/?tab=series&id=123 thetvdb.com/series/456 themoviedb.org/tv/8 myanimelist.net/anime/123',
            "Language: DE\nVideo: 528\nSource: BD\nAudio: DTS-HD-MA", "lang: de\nwidth: 1280 @\nsource: bd\naudio: aac lc",
            "langue: Englisch\nframe size: 1 920x1080\nsource: Ripped x\ncodec: h.264\naudio: AC-3",
            "Source: BD\nLanguage: ES\nVideo: 1920X1080\nAudio: Dolby\n TrueHD",
            "Source: Upstream.Movie.2001-GRP\n  inherited path\nActual Movie (2026)\nAudio: AAC LC\n", 'file.pdf Actual Movie (2026)', 'Audio Book Actual Movie (2026)');
        $random = new Randomizer(new Mt19937(8101));
        $chunks = ["\n", "\r", "\r\n", "\t", "\xff", "\0", 'Title:', 'release', 'date:', '20/10/26', 'May 9, 2000', 'x264', 'AAC',
            'Resolution:', '1280x720', '├', 'www.foo.org', 'https://foo.test/a', 'https://foo.test/a', 'Language:English,French',
            'runtime:01:30:04', 'Genre:drama,Action', 'Software v1.2', 'Windows', 'patch', 'Size:1.5 MB', 'TEAM presents', 'Audio:DTS-HD', '======', '[Section]'];
        for ($i = 0; $i < 350; $i++) {
            $text = '';
            for ($j = $random->getInt(1, 30); $j > 0; $j--) {
                $text .= $chunks[$random->getInt(0, count($chunks) - 1)].' ';
            }
            $cases[] = $text;
        }

        return $cases;
    }

    public function test_metadata_quality_candidates_match_php_without_silent_fallback(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_metadata_quality_test', NfoService::METADATA_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release dylib before running parity tests.');
        $php = self::service('/nonexistent/wave8-library');
        $rust = self::service();
        $cap = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $cap);
        self::assertNotNull($buffer);
        foreach (self::corpus() as $index => $text) {
            foreach (self::METHODS as $method => $symbol) {
                $expected = $php->{$method.'Native'}($text);
                $function = 'nntmux_nfo_'.$symbol;
                $scalar = in_array($method, ['calculateNfoQuality', 'hasAsciiArt', 'analyzeStructure'], true);
                if ($scalar) {
                    $actual = self::invokeFfi($ffi, $function, $text, strlen($text));
                    $boundedDecline = $method === 'calculateNfoQuality' && $actual === -1
                        && in_array($text, [self::workloads()['uncommon_whitespace'], self::workloads()['uncommon_borders']], true);
                    if (! $boundedDecline) {
                        self::assertSame((int) $expected, $actual, "$method direct ABI case $index ".hash('sha256', $text));
                    }
                    if ($method === 'calculateNfoQuality') {
                        self::assertSame($expected, self::invokeFfi($ffi, 'nntmux_nfo_calculate_quality_fused', $text, strlen($text)));
                        self::assertSame($expected, $rust->calculateNfoQualityFusedRust($text));
                    }
                } else {
                    $written = self::invokeFfi($ffi, $function, $text, strlen($text), $buffer, $cap);
                    if ($written < -1) {
                        $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                        self::assertNotNull($buffer);
                        $written = self::invokeFfi($ffi, $function, $text, strlen($text), $buffer, $cap);
                    }
                    self::assertGreaterThan(0, $written, "$method must execute Rust, case $index");
                    self::assertSame($expected, unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]), "$method direct ABI case $index ".hash('sha256', $text));
                }
                self::assertSame($expected, $rust->{$method.'Rust'}($text), "$method bridge case $index");
                self::assertSame($expected, (new ReflectionMethod($rust, $method))->invoke($rust, $text), "$method production case $index");
            }
        }
    }

    public function test_title_year_tags_preserve_order_case_binary_and_source_metadata(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_title_year_tags_test', NfoNameExtractor::TAGS_FFI_CDEF);
        self::assertNotNull($ffi);
        $extractor = new NfoNameExtractor;
        $fallback = new NfoNameExtractor('/nonexistent/wave8-library');
        $cap = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 2048, $cap);
        self::assertNotNull($buffer);
        foreach (self::corpus() as $index => $text) {
            foreach (self::TAG_METHODS as $method => $symbol) {
                $name = "Odd\0\xff Name (2026)";
                $expected = $fallback->{$method.'Native'}($name, $text);
                $function = 'nntmux_nfo_append_'.$symbol;
                $written = self::invokeFfi($ffi, $function, $name, strlen($name), $text, strlen($text), $buffer, $cap);
                self::assertGreaterThanOrEqual(0, $written, "$method direct ABI case $index");
                self::assertSame($expected, FFI::string($buffer, $written), "$method direct ABI case $index ".hash('sha256', $text));
                self::assertSame($expected, $extractor->{$method.'Rust'}($name, $text));
                self::assertSame($expected, $fallback->{$method.'Rust'}($name, $text));
            }
            self::assertEquals($fallback->checkForTitleYearNative($text), $extractor->checkForTitleYearTagsRust($text));
            self::assertEquals($fallback->checkForTitleYearNative($text), $extractor->checkForTitleYear($text));
        }
    }

    public function test_missing_library_custom_configuration_and_subclass_hooks_fall_back(): void
    {
        $missing = self::service('/nonexistent/wave8-library');
        foreach (self::METHODS as $method => $_) {
            self::assertSame($missing->{$method.'Native'}('Title: A Movie'), $missing->{$method.'Rust'}('Title: A Movie'));
        }
        $configured = self::service();
        $class = new ReflectionClass(NfoService::class);
        $class->getProperty('_nfoKeywords')->setValue($configured, []);
        $class->getProperty('_sceneGroupPatterns')->setValue($configured, ['/custom: (.+)/']);
        foreach (['calculateNfoQuality', 'parseNfoMetadata', 'extractGroupName'] as $method) {
            self::assertSame($configured->{$method.'Native'}("custom: MixedGroup\nrelease"), $configured->{$method.'Rust'}("custom: MixedGroup\nrelease"));
        }
        $subclass = new class extends NfoService
        {
            public function __construct() {}

            public function extractGroupName(string $nfoContent): string
            {
                return 'Override';
            }

            protected function hasAsciiArt(string $nfoContent): bool
            {
                return true;
            }
        };
        self::assertSame('Override', $subclass->parseNfoMetadataRust('')['group']);
        self::assertSame($subclass->calculateNfoQualityNative(''), $subclass->calculateNfoQualityRust(''));
        $before = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '100');
            self::assertSame($missing->parseNfoMetadataNative('hello'), self::service()->parseNfoMetadataRust('hello'));
        } finally {
            ini_set('pcre.backtrack_limit', $before === false ? '1000000' : $before);
        }
    }

    public function test_evidence_is_not_reused_for_different_text_at_the_same_address(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_metadata_quality_test', NfoService::METADATA_FFI_CDEF);
        self::assertNotNull($ffi);
        $input = $ffi->new('char[10]');
        $output = $ffi->new('char[2048]');
        $php = self::service('/nonexistent/wave8-library');
        for ($round = 0; $round < 100; $round++) {
            foreach (['video x264', 'qqqqqqqqqq', 'audio aac ', 'Title: abc'] as $text) {
                FFI::memcpy($input, $text, 10);
                self::assertSame($php->calculateNfoQualityNative($text), self::invokeFfi($ffi, 'nntmux_nfo_calculate_quality', $input, 10));
                $written = self::invokeFfi($ffi, 'nntmux_nfo_parse_metadata', $input, 10, $output, 2048);
                self::assertGreaterThan(0, $written);
                self::assertSame($php->parseNfoMetadataNative($text), unserialize(FFI::string($output, $written), ['allowed_classes' => false]));
            }
        }
    }

    public function test_overflow_keeps_php_warning_and_value_semantics(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_metadata_quality_test', NfoService::METADATA_FFI_CDEF);
        self::assertNotNull($ffi);
        $buffer = $ffi->new('char[1024]');
        foreach (['Size: 9223372036854775808 PB', 'Size: '.str_repeat('9', 350).' TB'] as $text) {
            foreach (['extractFileSize', 'parseNfoMetadata'] as $method) {
                $expectedWarnings = [];
                $actualWarnings = [];
                set_error_handler(function (int $number, string $message) use (&$expectedWarnings): bool {
                    $expectedWarnings[] = [$number, $message];

                    return true;
                });
                try {
                    $expected = self::service('/nonexistent/wave8-library')->{$method.'Native'}($text);
                } finally {
                    restore_error_handler();
                }
                set_error_handler(function (int $number, string $message) use (&$actualWarnings): bool {
                    $actualWarnings[] = [$number, $message];

                    return true;
                });
                try {
                    $actual = self::service()->{$method.'Rust'}($text);
                } finally {
                    restore_error_handler();
                }
                self::assertSame($expected, $actual);
                self::assertSame($expectedWarnings, $actualWarnings);
                if (PHP_VERSION_ID >= 80500) {
                    self::assertNotEmpty($expectedWarnings);
                }
                $symbol = 'nntmux_nfo_'.self::METHODS[$method];
                self::assertSame(-1, self::invokeFfi($ffi, $symbol, $text, strlen($text), $buffer, 1024));
            }
        }
    }

    public function test_capacity_retry_and_invalid_pointer_contracts(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo_metadata_quality_test', NfoService::METADATA_FFI_CDEF);
        self::assertNotNull($ffi);
        $small = $ffi->new('char[1]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nfo_calculate_quality(null, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nfo_parse_metadata(null, 1, $small, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nfo_parse_metadata('a', 1, null, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nfo_parse_metadata($small, 1, $small, 1));
        $text = implode("\n", array_map(fn (int $i): string => 'https://example.org/'.str_repeat('a', 64).$i, range(0, 5000)));
        $expected = self::service('/nonexistent/wave8-library')->extractUrlsNative($text);
        self::assertSame($expected, self::service()->extractUrlsRust($text));
        // @phpstan-ignore method.notFound
        $required = $ffi->nntmux_nfo_extract_urls($text, strlen($text), $small, 1);
        self::assertLessThan(-1, $required);
        $buffer = $ffi->new('char['.(-$required).']');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nfo_extract_urls($text, strlen($text), $buffer, -$required);
        self::assertSame(-$required, $written);
        self::assertSame($expected, unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]));
    }

    private static function snapshot(mixed $value): string
    {
        return serialize(is_object($value) ? get_object_vars($value) : $value);
    }

    /**
     * Admission record: 2026-10-24, Apple M5 (10 CPUs, 32 GiB), macOS 27.2,
     * PHP 8.5.12, Rust 1.99.1 release (opt=3, LTO, one codegen unit).
     * Two independent runs, each 4096 randomized pairs per method/workload,
     * 64 warmups, nine common/uncommon classes: 1,695,744 pairs total.
     * Values below are the minimum mean/p99 speedups across both runs/classes.
     * All requested candidates fail the 1.5x gate somewhere: keep PHP routing.
     * Exploratory fused quality is multi-scan, and its p99 rank-interval lower
     * bounds reach 1.23x; it does not establish the required single-pass gain.
     * Raw pairs, first calls, input/source/library hashes and protocol:
     * /tmp/wave8-nfo-final-{1,2}.json (incremental .json.jsonl beside each).
     * Frozen measured sources: /tmp/wave8-nfo-measured-source.
     * Hardware/tool/limits/failure record: /tmp/wave8-nfo-environment.json.
     *
     * Candidate                  mean    p99
     * calculateNfoQuality        0.68x  0.80x
     * hasAsciiArt                0.50x  0.50x
     * analyzeStructure           1.16x  1.23x
     * parseNfoMetadata           0.89x  0.75x
     * extractUrls                0.24x  0.13x
     * extractGroupName           0.68x  0.40x
     * extractReleaseDate         0.30x  0.14x
     * extractCodecInfo           0.38x  0.25x
     * extractFileSize            0.22x  0.10x
     * extractAllMediaIds         0.46x  0.26x
     * parseShowId                0.19x  0.14x
     * extractLanguage            0.20x  0.09x
     * extractRuntime             0.29x  0.15x
     * extractGenre               0.13x  0.03x
     * extractSoftwareInfo        0.30x  0.15x
     * extractReleaseTitle        0.24x  0.11x
     * appendLanguageFromNfo      0.34x  0.28x
     * appendResolutionFromNfo    0.40x  0.14x
     * appendSourceFromNfo        0.06x  0.09x
     * appendAudioCodecFromNfo    0.33x  0.23x
     * appendTitleYearTags        0.73x  0.77x
     * calculateNfoQualityFused   1.64x  1.62x
     * checkForTitleYear          0.88x  0.88x
     */
    /**
     * Reproduce outside PHPUnit (no database/app boot):
     * php -r 'require "vendor/autoload.php"; require "tests/Unit/RustNfoMetadataQualityParityTest.php";
     * Tests\Unit\RustNfoMetadataQualityParityTest::benchmark("/tmp/wave8-nfo-benchmark.json");'
     *
     * Each observation times one actual PHP caller, including FFI, scratch and decoding.
     * Keep every randomized pair; 4096 pairs per method/workload, 64 warmups.
     */
    public static function benchmark(string $destination, int $samples = 4096): void
    {
        $native = self::service('/nonexistent/wave8-library');
        $candidate = self::service();
        $nativeTags = new NfoNameExtractor('/nonexistent/wave8-library');
        $candidateTags = new NfoNameExtractor;
        $random = new Randomizer(new Mt19937(8102));
        $results = [];
        file_put_contents($destination.'.jsonl', json_encode([
            'event' => 'protocol', 'samples' => $samples, 'warmups' => 64, 'seed' => 8102,
            'clock' => 'hrtime nanoseconds', 'order' => 'randomized paired single caller invocations',
        ], JSON_THROW_ON_ERROR)."\n");
        foreach (self::workloads() as $workload => $text) {
            $pairs = [];
            foreach (self::METHODS as $method => $_) {
                $pairs[$method] = [$native->{$method.'Native'}(...), $candidate->{$method.'Rust'}(...)];
            }
            foreach (self::TAG_METHODS as $method => $_) {
                $pairs[$method] = [fn (string $s): string => $nativeTags->{$method.'Native'}('Example Movie (2026)', $s), fn (string $s): string => $candidateTags->{$method.'Rust'}('Example Movie (2026)', $s)];
            }
            $pairs['calculateNfoQualityFused'] = [$native->calculateNfoQualityNative(...), $candidate->calculateNfoQualityFusedRust(...)];
            $pairs['checkForTitleYear'] = [$nativeTags->checkForTitleYearNative(...), $candidateTags->checkForTitleYearTagsRust(...)];
            foreach ($pairs as $method => [$php, $rust]) {
                $start = hrtime(true);
                $firstNative = $php($text);
                $firstNativeNs = hrtime(true) - $start;
                $start = hrtime(true);
                $firstRust = $rust($text);
                $firstRustNs = hrtime(true) - $start;
                if (self::snapshot($firstNative) !== self::snapshot($firstRust)) {
                    file_put_contents($destination.'.jsonl', json_encode([
                        'event' => 'invalid', 'method' => $method, 'workload' => $workload, 'reason' => 'parity failure',
                    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                    throw new RuntimeException("Parity failed: $method / $workload");
                }
                for ($i = 0; $i < 64; $i++) {
                    $php($text);
                    $rust($text);
                }
                $raw = [];
                for ($i = 0; $i < $samples; $i++) {
                    $order = $random->getInt(0, 1);
                    $durations = [];
                    foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                        $call = $which === 0 ? $php : $rust;
                        $start = hrtime(true);
                        $call($text);
                        $durations[$which] = hrtime(true) - $start;
                    }
                    $raw[] = [$order, $durations[0], $durations[1]];
                }
                $base = array_column($raw, 1);
                $new = array_column($raw, 2);
                sort($base);
                sort($new);
                $meanBase = array_sum($base) / $samples;
                $meanNew = array_sum($new) / $samples;
                $p99Index = (int) ceil(0.99 * $samples) - 1;
                $rankRadius = (int) ceil(1.96 * sqrt($samples * 0.99 * 0.01));
                $lower = max(0, $p99Index - $rankRadius);
                $upper = min($samples - 1, $p99Index + $rankRadius);
                $results[$method][$workload] = [
                    'sha256' => hash('sha256', $text), 'bytes' => strlen($text), 'samples' => $samples,
                    'first_native_ns' => $firstNativeNs, 'first_rust_ns' => $firstRustNs,
                    'native_mean_ns' => $meanBase, 'rust_mean_ns' => $meanNew,
                    'mean_speedup' => $meanBase / $meanNew,
                    'native_p99_ns' => $base[$p99Index], 'rust_p99_ns' => $new[$p99Index],
                    'p99_speedup' => $base[$p99Index] / $new[$p99Index],
                    'p99_speedup_rank_interval' => [$base[$lower] / $new[$upper], $base[$upper] / $new[$lower]],
                    'raw_pairs_order_native_rust_ns' => $raw,
                ];
                file_put_contents($destination.'.jsonl', json_encode([
                    'event' => 'measurement', 'method' => $method, 'workload' => $workload,
                    'result' => $results[$method][$workload],
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                fwrite(STDOUT, sprintf("%s %-24s mean %.2fx p99 %.2fx\n", $workload, $method, $meanBase / $meanNew, $base[$p99Index] / $new[$p99Index]));
            }
        }
        $root = dirname(__DIR__, 2);
        $metadata = [
            'utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => php_uname(),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'php_jit' => ini_get('opcache.jit'),
            'pcre' => PCRE_VERSION, 'warmups' => 64, 'seed' => 8102,
            'source_sha256' => array_map(fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), [
                'app/Services/NfoService.php', 'app/Services/NameFixing/Extractors/NfoNameExtractor.php',
                'rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php',
                'tests/Unit/RustNfoMetadataQualityParityTest.php',
            ]),
            'library_sha256' => hash_file('sha256', $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')),
            'inputs' => array_map(base64_encode(...), self::workloads()),
            'results' => $results,
        ];
        file_put_contents($destination, json_encode($metadata, JSON_THROW_ON_ERROR));
    }
}
