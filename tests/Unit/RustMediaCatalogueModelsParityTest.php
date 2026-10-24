<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnidbTitle;
use App\Models\AudioData;
use App\Models\MusicInfo;
use App\Models\TvEpisode;
use App\Models\VideoData;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustMediaCatalogueModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        AnidbTitle::class,
        AudioData::class,
        MusicInfo::class,
        TvEpisode::class,
        VideoData::class,
    ];

    /** Strings that exercise trimming, collapsing, truncation, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'Main',
        '  Official  ',
        "x-jat\t",
        'Cowboy  Bebop',
        "Kimi \n\t no  Na wa",
        'Ünïcödé Tïtlé',
        "\u{00A0}nbsp\u{00A0}",
        '進撃の巨人',
        "emoji \u{1F3B5} track",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
        '6 channels',
        '2.0',
        '1000 channels',
        '0',
        '1969-09-26',
        'c. 1975 remaster',
        '12345 2001',
        '1799',
        's02e21+22',
        'S2021E365 extra',
        'B000002UAX',
        '48.0 kHz',
        ' 192 kb/s ',
    ];

    protected function tearDown(): void
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(true);
            $this->assertTrue($model::usesRustFfi(), $model);
            $model::setUseRustFfi(false);
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(null);
            $this->assertFalse($model::usesRustFfi(), $model);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'anidbid' => 7,
            'type' => 'main',
            'lang' => 'x-jat',
            'title' => 'Kimi no Na wa',
            'composite_key' => '7|main|x-jat|kimi no na wa',
        ], AnidbTitle::normalizeTitleAttributesPhp(7, ' Main ', "X-JAT\t", " Kimi \n\t no  Na wa "));
        $this->assertSame(0, AnidbTitle::normalizeTitleAttributesPhp(-5, '', '', '')['anidbid']);
        $this->assertSame(4294967295, AnidbTitle::normalizeTitleAttributesPhp(PHP_INT_MAX, '', '', '')['anidbid']);
        $this->assertSame(255, mb_strlen(AnidbTitle::normalizeTitleAttributesPhp(1, '', '', str_repeat('é', 300))['title']));

        $this->assertSame([
            'releases_id' => 10,
            'audioid' => 2,
            'audioformat' => 'AAC',
            'audiomode' => null,
            'audiobitratemode' => 'CBR',
            'audiobitrate' => '192 kb/s',
            'audiochannels' => '6 channels',
            'audiosamplerate' => '48.0 kHz',
            'audiolanguage' => null,
            'channel_count' => 6,
            'composite_key' => '10:2',
        ], AudioData::normalizeAudioTrackAttributesPhp(10, 2, 'AAC', null, 'CBR', ' 192 kb/s ', '6 channels', '48.0 kHz', ' '));
        $this->assertSame('1234567890', AudioData::normalizeAudioTrackAttributesPhp(1, 1, null, null, null, '12345678901', null, null, null)['audiobitrate']);
        $this->assertNull(AudioData::normalizeAudioTrackAttributesPhp(1, 1, null, null, null, null, '0', null, null)['channel_count']);
        $this->assertNull(AudioData::normalizeAudioTrackAttributesPhp(1, 1, null, null, null, null, '1000', null, null)['channel_count']);

        $this->assertSame([
            'title' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => '1969',
            'asin' => 'B000002UAX',
            'genres_id' => null,
            'cover' => 1,
            'search_key' => 'the beatles - abbey road',
        ], MusicInfo::normalizeMusicCatalogueAttributesPhp('Abbey  Road', 'The Beatles', '1969-09-26', ' B000002UAX ', -1, true));
        $music = MusicInfo::normalizeMusicCatalogueAttributesPhp(' Kind of Blue ', '  ', 'c. 1799 / 12345 2001', null, 12, false);
        $this->assertSame(['Kind of Blue', null, '2001', 12, 0, 'kind of blue'], [
            $music['title'], $music['artist'], $music['year'], $music['genres_id'], $music['cover'], $music['search_key'],
        ]);

        $this->assertSame([
            'series' => 2,
            'episode' => 21,
            'se_complete' => 'S02E21+22',
            'canonical' => 'S02E21',
            'is_special' => false,
            'is_unnumbered' => false,
        ], TvEpisode::formatSeasonEpisodeCodePhp(2, 21, ' s02e21+22 '));
        $this->assertSame('S00E65535', TvEpisode::formatSeasonEpisodeCodePhp(-1, 70000)['se_complete']);
        $this->assertSame('S65535E655', TvEpisode::formatSeasonEpisodeCodePhp(65535, 65535, '  ')['se_complete']);
        $this->assertTrue(TvEpisode::formatSeasonEpisodeCodePhp(0, 0)['is_special']);

        $this->assertSame([
            'releases_id' => 3,
            'containerformat' => 'Matroska',
            'videocodec' => null,
            'videowidth' => 1920,
            'videoheight' => 1080,
            'videoaspect' => '16:9',
            'resolution_class' => '1080p',
            'videoframerate' => 23.976,
        ], VideoData::normalizeVideoStreamAttributesPhp(3, ' Matroska ', '', 1920, 1080, 23.976));
        $this->assertSame('2.39:1', VideoData::normalizeVideoStreamAttributesPhp(1, null, null, 1920, 800, null)['videoaspect']);
        $this->assertSame('10.00:1', VideoData::normalizeVideoStreamAttributesPhp(1, null, null, 1000, 100, null)['videoaspect']);
        $this->assertSame('2147483647', VideoData::normalizeVideoStreamAttributesPhp(1, null, null, 2147483647, 1, null)['videoaspect']);
        $this->assertSame('unknown', VideoData::normalizeVideoStreamAttributesPhp(1, null, null, 0, -1, NAN)['resolution_class']);
        $this->assertNull(VideoData::normalizeVideoStreamAttributesPhp(1, null, null, null, null, INF)['videoframerate']);
    }

    public function test_anidb_title_native_and_dispatch_match_php(): void
    {
        foreach ([-5, 0, 7, 4294967295, 4294967296, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            foreach (self::TEXTS as $type) {
                foreach (['en', ' X-JAT ', "bad \xff", "\x1F"] as $lang) {
                    foreach (self::TEXTS as $title) {
                        $this->assertAnidbParity($id, $type, $lang, $title);
                    }
                }
            }
        }
        $this->assertAnidbParity(1, str_repeat('t', 40), str_repeat('l', 40), str_repeat('ü ', 400));
    }

    public function test_audio_data_native_and_dispatch_match_php(): void
    {
        $values = [...self::TEXTS, null, str_repeat('ä', 80)];
        foreach ($values as $i => $value) {
            foreach ([[10, 2], [-1, -1], [PHP_INT_MAX, 4294967296]] as [$release, $audio]) {
                $rotated = [];
                for ($field = 0; $field < 7; $field++) {
                    $rotated[] = $values[($i + $field * 3) % count($values)];
                }
                $this->assertAudioParity($release, $audio, $rotated);
                $this->assertAudioParity($release, $audio, array_fill(0, 7, $value));
            }
        }
    }

    public function test_music_info_native_and_dispatch_match_php(): void
    {
        $values = [...self::TEXTS, null, str_repeat('Ω', 300)];
        foreach ($values as $i => $value) {
            foreach ([null, -1, 0, 5, 2147483647, 2147483648, PHP_INT_MIN] as $genre) {
                foreach ([true, false] as $cover) {
                    $title = $value ?? 'Untitled';
                    $artist = $values[($i + 1) % count($values)];
                    $year = $values[($i + 5) % count($values)];
                    $this->assertMusicParity($title, $artist, $year, $value, $genre, $cover);
                    $this->assertMusicParity($title, $value, $value, $artist, $genre, $cover);
                }
            }
        }
    }

    public function test_tv_episode_native_and_dispatch_match_php(): void
    {
        foreach ([-1, 0, 1, 9, 10, 99, 100, 2021, 65535, 65536, PHP_INT_MAX, PHP_INT_MIN] as $series) {
            foreach ([-3, 0, 2, 365, 65535, 70000] as $episode) {
                foreach ([null, ...self::TEXTS, str_repeat('é', 20)] as $raw) {
                    $this->assertTvParity($series, $episode, $raw);
                }
            }
        }
    }

    public function test_video_data_native_and_dispatch_match_php(): void
    {
        $dimensions = [null, -1, 0, 1, 3, 320, 400, 480, 536, 540, 576, 640, 700, 720, 800, 1000, 1080, 1260, 1280, 1400, 1440, 1900, 1920, 2000, 2160, 2500, 3800, 3840, 2147483647, 2147483648, PHP_INT_MAX];
        $rates = [null, 23.976, 25.0, 0.0, -1.0, 999.9999, 1000.0, NAN, INF, -INF, 5e-324];
        $texts = [...self::TEXTS, null];
        foreach ($dimensions as $i => $width) {
            foreach ($dimensions as $j => $height) {
                $rate = $rates[($i + $j) % count($rates)];
                $container = $texts[$i % count($texts)];
                $codec = $texts[($j * 7) % count($texts)];
                $this->assertVideoParity($i * 97 - 100, $container, $codec, $width, $height, $rate);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3204));
        $alphabet = ['a', 'Z', '0', '9', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '-', '|', ':', 'é', 'ß', '進', "\u{1F3B5}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $maybe = static fn (): ?string => $random->getInt(0, 4) === 0 ? null : $text();
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 5000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(0, 70000),
        };

        for ($i = 0; $i < 1500; $i++) {
            $this->assertAnidbParity($int(), $text(), $text(), $text());
            $this->assertAudioParity($int(), $int(), [$maybe(), $maybe(), $maybe(), $maybe(), $maybe(), $maybe(), $maybe()]);
            $this->assertMusicParity($text(), $maybe(), $maybe(), $maybe(), $random->getInt(0, 3) === 0 ? null : $int(), $random->getInt(0, 1) === 1);
            $this->assertTvParity($int(), $int(), $maybe());
            $this->assertVideoParity(
                $int(),
                $maybe(),
                $maybe(),
                $random->getInt(0, 4) === 0 ? null : $int(),
                $random->getInt(0, 4) === 0 ? null : $int(),
                $random->getInt(0, 4) === 0 ? null : $random->getFloat(-10, 1200),
            );
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $input = "main\x1Fen\x1Ftitle";
        $needed = $this->callFfi($ffi, 'nntmux_anidb_title_normalize_attributes', 1, $input, strlen($input), $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_anidb_title_normalize_attributes', 1, $input, strlen($input), $buffer, -$needed));
        $this->assertSame("1\x1Fmain\x1Fen\x1Ftitle\x1F1|main|en|title", FFI::string($buffer, -$needed));

        $out = $ffi->new('char[256]');
        $invalid = "\xff\x1Fen\x1Ftitle";
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_anidb_title_normalize_attributes', 1, $invalid, strlen($invalid), $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_anidb_title_normalize_attributes', 1, 'a', 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_audio_data_normalize_track', 1, 1, 0, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_audio_data_normalize_track', 1, 1, 0, "\x1E\x1F\x1F\x1F\x1F\x1F\x1F", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_audio_data_normalize_track', 1, 1, 1 << 7, "\x1F\x1F\x1F\x1F\x1F\x1F", 6, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_audio_data_normalize_track', 1, 1, 1, "x\x1F\x1F\x1F\x1F\x1F\x1F", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_music_info_normalize_catalogue', 2, 0, 0, 0, "t\x1F\x1F\x1F", 4, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_music_info_normalize_catalogue', 0, 0, 0, 0b1111, "\x1F\x1F\x1F", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_episode_format_se_code', 1, 2, 1, "S01\x1FE02", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_episode_format_se_code', 1, 2, 1, "\x1E", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_episode_format_se_code', 1, 2, 2, '', 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_video_data_normalize_stream', 1, 3, 0, 0, 0, 0, 0.0, 0b11, "\x1F", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_video_data_normalize_stream', 1, 0, 0, 0, 0, 0, 0.0, 0, "a\x1Fb\x1Fc", 5, $out, 256));
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        AnidbTitle::normalizeTitleAttributes(1, 'main', 'en', 'Title');
        AudioData::normalizeAudioTrackAttributes(1, 1, 'AAC', null, null, null, '2', null, null);
        MusicInfo::normalizeMusicCatalogueAttributes('Title', null, '1999', null, null, false);
        TvEpisode::formatSeasonEpisodeCode(1, 2);
        VideoData::normalizeVideoStreamAttributes(1, 'Matroska', null, 1920, 1080, 25.0);
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }

        $written = $this->callFfi($ffi, 'nntmux_video_data_normalize_stream', 1, 1, 1920, 1, 800, 1, NAN, 0b11, "\x1F", 1, $out, 256);
        $this->assertSame("1\x1F\x1E\x1F\x1E\x1F1920\x1F800\x1F2.39:1\x1F1080p\x1F\x1E", FFI::string($out, $written));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_anidb_title_normalize_attributes', 1, $input, strlen($input), null, 256));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical MediaInfo/AniDB/provider values; uncommon = long, Unicode, padded and fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.48x / p99 0.51x, uncommon mean 0.59x / p99 0.58x
        // - Round 2: common mean 0.48x / p99 0.50x, uncommon mean 0.59x / p99 0.57x
        // - Round 3: common mean 0.47x / p99 0.36x, uncommon mean 0.59x / p99 0.58x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustMediaCatalogueModelsParityTest.php --filter benchmark
        // Microsecond PHP string work is cheaper than the FFI round trip, so every model stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3204));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::MODELS as $model) {
                $model::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        if ($speedups['common']['mean'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::MODELS as $model) {
                $this->assertFalse($model::usesRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertAnidbParity(int $id, string $type, string $lang, string $title): void
    {
        $expected = AnidbTitle::normalizeTitleAttributesPhp($id, $type, $lang, $title);
        $context = json_encode([$id, bin2hex($type), bin2hex($lang), bin2hex($title)]) ?: '';
        $this->assertSameAttributes($expected, AnidbTitle::normalizeTitleAttributesNative($id, $type, $lang, $title), $context);
        AnidbTitle::setUseRustFfi(true);
        $this->assertSameAttributes($expected, AnidbTitle::normalizeTitleAttributes($id, $type, $lang, $title), $context);
        AnidbTitle::setUseRustFfi(false);
        $this->assertSameAttributes($expected, AnidbTitle::normalizeTitleAttributes($id, $type, $lang, $title), $context);
        AnidbTitle::setUseRustFfi(null);
    }

    /**
     * @param  list<?string>  $fields
     */
    private function assertAudioParity(int $release, int $audio, array $fields): void
    {
        $expected = AudioData::normalizeAudioTrackAttributesPhp($release, $audio, ...$fields);
        $context = json_encode([$release, $audio, array_map(static fn (?string $f) => $f === null ? null : bin2hex($f), $fields)]) ?: '';
        $this->assertSameAttributes($expected, AudioData::normalizeAudioTrackAttributesNative($release, $audio, ...$fields), $context);
        AudioData::setUseRustFfi(true);
        $this->assertSameAttributes($expected, AudioData::normalizeAudioTrackAttributes($release, $audio, ...$fields), $context);
        AudioData::setUseRustFfi(null);
        $this->assertSameAttributes($expected, AudioData::normalizeAudioTrackAttributes($release, $audio, ...$fields), $context);
    }

    private function assertMusicParity(string $title, ?string $artist, ?string $year, ?string $asin, ?int $genre, bool $cover): void
    {
        $expected = MusicInfo::normalizeMusicCatalogueAttributesPhp($title, $artist, $year, $asin, $genre, $cover);
        $context = json_encode(array_map(static fn ($v) => is_string($v) ? bin2hex($v) : $v, [$title, $artist, $year, $asin, $genre, $cover])) ?: '';
        $this->assertSameAttributes($expected, MusicInfo::normalizeMusicCatalogueAttributesNative($title, $artist, $year, $asin, $genre, $cover), $context);
        MusicInfo::setUseRustFfi(true);
        $this->assertSameAttributes($expected, MusicInfo::normalizeMusicCatalogueAttributes($title, $artist, $year, $asin, $genre, $cover), $context);
        MusicInfo::setUseRustFfi(null);
        $this->assertSameAttributes($expected, MusicInfo::normalizeMusicCatalogueAttributes($title, $artist, $year, $asin, $genre, $cover), $context);
    }

    private function assertTvParity(int $series, int $episode, ?string $raw): void
    {
        $expected = TvEpisode::formatSeasonEpisodeCodePhp($series, $episode, $raw);
        $context = json_encode([$series, $episode, $raw === null ? null : bin2hex($raw)]) ?: '';
        $this->assertSameAttributes($expected, TvEpisode::formatSeasonEpisodeCodeNative($series, $episode, $raw), $context);
        TvEpisode::setUseRustFfi(true);
        $this->assertSameAttributes($expected, TvEpisode::formatSeasonEpisodeCode($series, $episode, $raw), $context);
        TvEpisode::setUseRustFfi(null);
        $this->assertSameAttributes($expected, TvEpisode::formatSeasonEpisodeCode($series, $episode, $raw), $context);
    }

    private function assertVideoParity(int $release, ?string $container, ?string $codec, ?int $width, ?int $height, ?float $rate): void
    {
        $expected = VideoData::normalizeVideoStreamAttributesPhp($release, $container, $codec, $width, $height, $rate);
        $context = json_encode([$release, $container === null ? null : bin2hex($container), $codec === null ? null : bin2hex($codec), $width, $height, var_export($rate, true)]) ?: '';
        $this->assertSameAttributes($expected, VideoData::normalizeVideoStreamAttributesNative($release, $container, $codec, $width, $height, $rate), $context);
        VideoData::setUseRustFfi(true);
        $this->assertSameAttributes($expected, VideoData::normalizeVideoStreamAttributes($release, $container, $codec, $width, $height, $rate), $context);
        VideoData::setUseRustFfi(null);
        $this->assertSameAttributes($expected, VideoData::normalizeVideoStreamAttributes($release, $container, $codec, $width, $height, $rate), $context);
    }

    /**
     * Exact equality (including float bits); NAN never survives normalization.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['anidb', [12345, 'main', 'x-jat', 'Shingeki no Kyojin']],
            ['anidb', [12345, 'official', 'en', 'Attack on Titan']],
            ['audio', [987654, 1, 'AAC', 'CBR', 'Constant', '192 kb/s', '2', '48.0 kHz', 'English']],
            ['audio', [987654, 2, 'AC-3', null, 'Constant', '640 kb/s', '6', '48.0 kHz', 'German']],
            ['music', ['Abbey Road', 'The Beatles', '1969', 'B000002UAX', 12, true]],
            ['tv', [2, 21, null]],
            ['tv', [5, 3, 'S05E03']],
            ['video', [987654, 'Matroska', 'V_MPEG4/ISO/AVC', 1920, 1080, 23.976]],
            ['video', [987655, 'MPEG-4', 'avc1', 1280, 720, 25.0]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Tïtlé  ', 30);

        return [
            ['anidb', [PHP_INT_MAX, '  SYNONYM  ', '  ZH-HANS ', $long]],
            ['anidb', [1, 'main', 'ja', "bad \xff utf8 title"]],
            ['audio', [-1, 9, '  E-AC-3 JOC  ', 'Dolby Digital Plus', 'Variable', '1 509 kb/s', '8 / 6 channels', '96.0 kHz', str_repeat('日本語', 30)]],
            ['music', [$long, "  Various \t Artists  ", 'c. 1975 / 2001 remaster', str_repeat('A', 200), 2147483648, false]],
            ['music', ["Title \x1F with marker", null, null, null, null, false]],
            ['tv', [70000, -1, "  s2021e365+366 extended  \u{1F3AC}"]],
            ['video', [PHP_INT_MAX, str_repeat(' Matroska ', 10), 'HEVC', 3840, 1608, 1000.0]],
            ['video', [1, '', null, 2147483647, 1, NAN]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'anidb' => AnidbTitle::normalizeTitleAttributes(...$args),
                'audio' => AudioData::normalizeAudioTrackAttributes(...$args),
                'music' => MusicInfo::normalizeMusicCatalogueAttributes(...$args),
                'tv' => TvEpisode::formatSeasonEpisodeCode(...$args),
                default => VideoData::normalizeVideoStreamAttributes(...$args),
            };
            $count += count($result);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            AnidbTitle::FFI_CDEF,
            AudioData::FFI_CDEF,
            MusicInfo::FFI_CDEF,
            TvEpisode::FFI_CDEF,
            VideoData::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('metadata', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the media catalogue model exports is not available.');
        }

        return $ffi;
    }
}
