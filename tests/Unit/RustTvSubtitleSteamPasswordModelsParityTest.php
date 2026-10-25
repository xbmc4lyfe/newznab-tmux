<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ReleaseNzbPassword;
use App\Models\ReleaseSubtitle;
use App\Models\SteamApp;
use App\Models\TvInfo;
use App\Models\VideoAlias;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustTvSubtitleSteamPasswordModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        TvInfo::class,
        VideoAlias::class,
        ReleaseSubtitle::class,
        SteamApp::class,
        ReleaseNzbPassword::class,
    ];

    /** Strings that exercise trimming, collapsing, truncation, slugging, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        '-',
        '--Half-Life 2--',
        'Counter-Strike: Global Offensive',
        '  The  Office (US) ',
        "Brazilian \n\t Portuguese",
        'English',
        'ENG',
        'America/New_York',
        'Etc/GMT+5',
        'Bad Zone',
        ' Europe/London ',
        'Zürich',
        'Pokémon',
        'Ünïcödé Tïtlé',
        "\u{00A0}nbsp\u{00A0}",
        '進撃の巨人',
        "emoji \u{1F3AE} game",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
        "  p@ss  word\t",
        'P4$$w0rd!',
        '0',
        'abc123XYZ',
    ];

    private const IDS = [-5, -1, 0, 1, 7, 570, 4294967295, 4294967296, PHP_INT_MAX, PHP_INT_MIN];

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

    public function test_eloquent_model_configuration_is_preserved(): void
    {
        $tvInfo = new TvInfo;
        $this->assertSame(['tv_info', 'videos_id', false, false], [$tvInfo->getTable(), $tvInfo->getKeyName(), $tvInfo->getIncrementing(), $tvInfo->usesTimestamps()]);
        $alias = new VideoAlias;
        $this->assertSame(['videos_aliases', 'videos_id', ['videos_id', 'title']], [$alias->getTable(), $alias->getKeyName(), $alias->compositeKeyColumns()]);
        $subtitle = new ReleaseSubtitle;
        $this->assertSame(['release_subtitles', ['id', 'releases_id', 'subsid', 'subslanguage'], false], [$subtitle->getTable(), $subtitle->getFillable(), $subtitle->usesTimestamps()]);
        $steam = new SteamApp;
        $this->assertSame(['steam_apps', [], false], [$steam->getTable(), $steam->getGuarded(), $steam->usesTimestamps()]);
        $password = new ReleaseNzbPassword;
        $this->assertSame(['release_nzb_passwords', 'releases_id', false, 'int', false], [$password->getTable(), $password->getKeyName(), $password->getIncrementing(), $password->getKeyType(), $password->usesTimestamps()]);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'videos_id' => 0,
            'summary' => 'A  show.',
            'publisher' => 'HBO Max',
            'localzone' => 'America/New_York',
            'image' => 1,
        ], TvInfo::normalizeTvInfoAttributesPhp(-3, "  A  show.\n", " HBO \t Max ", ' America/New_York ', 5));
        $this->assertSame('', TvInfo::normalizeTvInfoAttributesPhp(1, '', '', 'Bad Zone', 0)['localzone']);
        $this->assertSame('', TvInfo::normalizeTvInfoAttributesPhp(1, '', '', 'Zürich', 0)['localzone']);
        $this->assertSame(str_repeat('A', 50), TvInfo::normalizeTvInfoAttributesPhp(1, '', '', str_repeat('A', 60), 0)['localzone']);
        $this->assertSame(50, mb_strlen(TvInfo::normalizeTvInfoAttributesPhp(1, '', str_repeat('é', 80), '', 0)['publisher']));
        $this->assertSame(0, TvInfo::normalizeTvInfoAttributesPhp(1, '', '', '', -1)['image']);
        $this->assertSame(4294967295, TvInfo::normalizeTvInfoAttributesPhp(PHP_INT_MAX, '', '', '', 0)['videos_id']);

        $this->assertSame([
            'videos_id' => 7,
            'title' => 'The Office (US)',
            'composite_key' => '7|the office (us)',
            'is_valid' => true,
        ], VideoAlias::normalizeAliasPairPhp(7, '  The  Office (US) '));
        $this->assertFalse(VideoAlias::normalizeAliasPairPhp(-1, 'x')['is_valid']);
        $this->assertFalse(VideoAlias::normalizeAliasPairPhp(1, '   ')['is_valid']);
        $this->assertSame(180, mb_strlen(VideoAlias::normalizeAliasPairPhp(1, str_repeat('ü', 200))['title']));
        $this->assertSame('1|Äb', VideoAlias::normalizeAliasPairPhp(1, 'ÄB')['composite_key']);

        $this->assertSame([
            'releases_id' => 10,
            'subsid' => 2,
            'subslanguage' => 'Brazilian Portuguese',
            'language_key' => 'brazilian portuguese',
            'composite_key' => '10:2',
        ], ReleaseSubtitle::normalizeSubtitleTrackPhp(10, 2, "  Brazilian \t Portuguese "));
        $this->assertSame('0:4294967295', ReleaseSubtitle::normalizeSubtitleTrackPhp(PHP_INT_MIN, PHP_INT_MAX, '')['composite_key']);

        $this->assertSame([
            'appid' => 570,
            'name' => 'Dota 2',
            'slug' => 'dota-2',
            'is_valid' => true,
        ], SteamApp::normalizeSteamAppRecordPhp(570, ' Dota  2 '));
        $this->assertSame('counter-strike-global-offensive', SteamApp::normalizeSteamAppRecordPhp(1, 'Counter-Strike: Global Offensive')['slug']);
        $this->assertSame('half-life-2', SteamApp::normalizeSteamAppRecordPhp(1, '--Half-Life 2--')['slug']);
        $this->assertSame('pok-mon', SteamApp::normalizeSteamAppRecordPhp(1, 'Pokémon')['slug']);
        $this->assertSame('', SteamApp::normalizeSteamAppRecordPhp(1, '進撃')['slug']);
        $this->assertFalse(SteamApp::normalizeSteamAppRecordPhp(0, 'Dota')['is_valid']);

        $this->assertSame([
            'releases_id' => 3,
            'password' => 'p@ss  word',
            'has_password' => true,
        ], ReleaseNzbPassword::normalizePasswordEntryPhp(3, "  p@ss  word\t"));
        $this->assertFalse(ReleaseNzbPassword::normalizePasswordEntryPhp(3, " \0 ")['has_password']);
        $this->assertSame('0', ReleaseNzbPassword::normalizePasswordEntryPhp(3, '0')['password']);
        $this->assertSame(255, mb_strlen(ReleaseNzbPassword::normalizePasswordEntryPhp(1, str_repeat('ß', 300))['password']));
    }

    public function test_tv_info_native_and_dispatch_match_php(): void
    {
        $count = count(self::TEXTS);
        foreach (self::IDS as $i => $id) {
            foreach (self::TEXTS as $j => $text) {
                foreach ([-1, 0, 1, 2] as $image) {
                    $this->assertTvInfoParity($id, $text, self::TEXTS[($i + $j) % $count], self::TEXTS[($j * 7 + $i) % $count], $image);
                    $this->assertTvInfoParity($id, self::TEXTS[($j + 3) % $count], $text, $text, $image);
                }
            }
        }
        $this->assertTvInfoParity(1, str_repeat("Long summary\n\n", 2000), str_repeat('Network ', 20), str_repeat('Zone/', 20), 1);
    }

    public function test_video_alias_native_and_dispatch_match_php(): void
    {
        foreach (self::IDS as $id) {
            foreach ([...self::TEXTS, str_repeat('ü ', 200)] as $title) {
                $this->assertAliasParity($id, $title);
            }
        }
    }

    public function test_release_subtitle_native_and_dispatch_match_php(): void
    {
        foreach (self::IDS as $releaseId) {
            foreach (self::IDS as $subsId) {
                foreach ([...self::TEXTS, str_repeat('日本語 ', 30)] as $language) {
                    $this->assertSubtitleParity($releaseId, $subsId, $language);
                }
            }
        }
    }

    public function test_steam_app_native_and_dispatch_match_php(): void
    {
        foreach (self::IDS as $id) {
            foreach ([...self::TEXTS, str_repeat('Game: Edition ', 30)] as $name) {
                $this->assertSteamParity($id, $name);
            }
        }
    }

    public function test_release_nzb_password_native_and_dispatch_match_php(): void
    {
        foreach (self::IDS as $id) {
            foreach ([...self::TEXTS, str_repeat('ß', 300)] as $password) {
                $this->assertPasswordParity($id, $password);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3304));
        $alphabet = ['a', 'Z', '0', '9', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '-', '_', '+', '/', '|', ':', 'é', 'ß', '進', "\u{1F3AE}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 5000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(4294967290, 4294967300),
        };

        for ($i = 0; $i < 1500; $i++) {
            $this->assertTvInfoParity($int(), $text(), $text(), $text(), $int());
            $this->assertAliasParity($int(), $text());
            $this->assertSubtitleParity($int(), $int(), $text());
            $this->assertSteamParity($int(), $text());
            $this->assertPasswordParity($int(), $text());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_video_alias_normalize_pair', 1, 'Alias', 5, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_video_alias_normalize_pair', 1, 'Alias', 5, $buffer, -$needed));
        $this->assertSame("1\x1FAlias\x1F1|alias\x1F1", FFI::string($buffer, -$needed));

        $out = $ffi->new('char[256]');
        $input = "Summary\x1FBBC\x1FEurope/London";
        $written = $this->callFfi($ffi, 'nntmux_tv_info_normalize_attributes', 4, 1, $input, strlen($input), $out, 256);
        $this->assertSame("4\x1FSummary\x1FBBC\x1FEurope/London\x1F1", FFI::string($out, $written));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_info_normalize_attributes', 1, 0, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_info_normalize_attributes', 1, 0, "a\x1Fb\x1Fc\x1Fd", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_tv_info_normalize_attributes', 1, 0, "\xff\x1F\x1F", 3, $out, 256));
        foreach (["a\x1Fb", "x\x1Ey", "bad \xff", "\xc3"] as $invalid) {
            $length = strlen($invalid);
            $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_video_alias_normalize_pair', 1, $invalid, $length, $out, 256));
            $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_subtitle_normalize_track', 1, 1, $invalid, $length, $out, 256));
            $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_steam_app_normalize_record', 1, $invalid, $length, $out, 256));
            $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_nzb_password_normalize_entry', 1, $invalid, $length, $out, 256));
        }
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_steam_app_normalize_record', 1, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_release_nzb_password_normalize_entry', 1, 'pw', 2, null, 256));

        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        TvInfo::normalizeTvInfoAttributes(1, 'Summary', 'BBC', 'Europe/London', 1);
        VideoAlias::normalizeAliasPair(1, 'Alias');
        ReleaseSubtitle::normalizeSubtitleTrack(1, 2, 'English');
        SteamApp::normalizeSteamAppRecord(570, 'Dota 2');
        ReleaseNzbPassword::normalizePasswordEntry(1, 'secret');
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical TVMaze/Steam/subtitle/NZB values; uncommon = long, Unicode, padded and fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.40x / p99 0.44x, uncommon mean 0.59x / p99 0.63x
        // - Round 2: common mean 0.39x / p99 0.44x, uncommon mean 0.61x / p99 0.66x
        // - Round 3: common mean 0.41x / p99 0.51x, uncommon mean 0.59x / p99 0.51x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustTvSubtitleSteamPasswordModelsParityTest.php --filter benchmark
        // Microsecond PHP string work is cheaper than the FFI round trip, so every model stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3304));
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
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::MODELS as $model) {
                $this->assertFalse($model::usesRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertTvInfoParity(int $id, string $summary, string $publisher, string $zone, int $image): void
    {
        $expected = TvInfo::normalizeTvInfoAttributesPhp($id, $summary, $publisher, $zone, $image);
        $context = json_encode([$id, bin2hex($summary), bin2hex($publisher), bin2hex($zone), $image]) ?: '';
        $this->assertSameAttributes($expected, TvInfo::normalizeTvInfoAttributesNative($id, $summary, $publisher, $zone, $image), $context);
        TvInfo::setUseRustFfi(true);
        $this->assertSameAttributes($expected, TvInfo::normalizeTvInfoAttributes($id, $summary, $publisher, $zone, $image), $context);
        TvInfo::setUseRustFfi(false);
        $this->assertSameAttributes($expected, TvInfo::normalizeTvInfoAttributes($id, $summary, $publisher, $zone, $image), $context);
        TvInfo::setUseRustFfi(null);
    }

    private function assertAliasParity(int $id, string $title): void
    {
        $expected = VideoAlias::normalizeAliasPairPhp($id, $title);
        $context = json_encode([$id, bin2hex($title)]) ?: '';
        $this->assertSameAttributes($expected, VideoAlias::normalizeAliasPairNative($id, $title), $context);
        VideoAlias::setUseRustFfi(true);
        $this->assertSameAttributes($expected, VideoAlias::normalizeAliasPair($id, $title), $context);
        VideoAlias::setUseRustFfi(null);
        $this->assertSameAttributes($expected, VideoAlias::normalizeAliasPair($id, $title), $context);
    }

    private function assertSubtitleParity(int $releaseId, int $subsId, string $language): void
    {
        $expected = ReleaseSubtitle::normalizeSubtitleTrackPhp($releaseId, $subsId, $language);
        $context = json_encode([$releaseId, $subsId, bin2hex($language)]) ?: '';
        $this->assertSameAttributes($expected, ReleaseSubtitle::normalizeSubtitleTrackNative($releaseId, $subsId, $language), $context);
        ReleaseSubtitle::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseSubtitle::normalizeSubtitleTrack($releaseId, $subsId, $language), $context);
        ReleaseSubtitle::setUseRustFfi(null);
        $this->assertSameAttributes($expected, ReleaseSubtitle::normalizeSubtitleTrack($releaseId, $subsId, $language), $context);
    }

    private function assertSteamParity(int $id, string $name): void
    {
        $expected = SteamApp::normalizeSteamAppRecordPhp($id, $name);
        $context = json_encode([$id, bin2hex($name)]) ?: '';
        $this->assertSameAttributes($expected, SteamApp::normalizeSteamAppRecordNative($id, $name), $context);
        SteamApp::setUseRustFfi(true);
        $this->assertSameAttributes($expected, SteamApp::normalizeSteamAppRecord($id, $name), $context);
        SteamApp::setUseRustFfi(null);
        $this->assertSameAttributes($expected, SteamApp::normalizeSteamAppRecord($id, $name), $context);
    }

    private function assertPasswordParity(int $id, string $password): void
    {
        $expected = ReleaseNzbPassword::normalizePasswordEntryPhp($id, $password);
        $context = json_encode([$id, bin2hex($password)]) ?: '';
        $this->assertSameAttributes($expected, ReleaseNzbPassword::normalizePasswordEntryNative($id, $password), $context);
        ReleaseNzbPassword::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleaseNzbPassword::normalizePasswordEntry($id, $password), $context);
        ReleaseNzbPassword::setUseRustFfi(null);
        $this->assertSameAttributes($expected, ReleaseNzbPassword::normalizePasswordEntry($id, $password), $context);
    }

    /**
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
            ['tv', [12345, 'A detective drama set in Baltimore.', 'HBO', 'America/New_York', 1]],
            ['alias', [12345, 'The Wire']],
            ['alias', [12346, 'The Office (US)']],
            ['subtitle', [987654, 1, 'English']],
            ['subtitle', [987654, 2, 'German']],
            ['steam', [570, 'Dota 2']],
            ['steam', [620, 'Portal 2']],
            ['password', [987654, 'secret']],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Tïtlé  ', 30);

        return [
            ['tv', [PHP_INT_MAX, str_repeat("Long summary paragraph.\n\n", 200), "  Nippon \t TV  ", ' Asia/Tokyo ', -1]],
            ['tv', [1, "bad \xff utf8 summary", 'BBC', 'Bad Zone', 0]],
            ['alias', [-1, $long]],
            ['alias', [1, "Alias \x1F with marker"]],
            ['subtitle', [PHP_INT_MIN, 4294967296, str_repeat('日本語 ', 30)]],
            ['steam', [4294967296, 'Counter-Strike: Global Offensive — '.$long]],
            ['password', [1, '  '.str_repeat('pässwörd ', 40).'  ']],
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
                'tv' => TvInfo::normalizeTvInfoAttributes(...$args),
                'alias' => VideoAlias::normalizeAliasPair(...$args),
                'subtitle' => ReleaseSubtitle::normalizeSubtitleTrack(...$args),
                'steam' => SteamApp::normalizeSteamAppRecord(...$args),
                default => ReleaseNzbPassword::normalizePasswordEntry(...$args),
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
            TvInfo::FFI_CDEF,
            VideoAlias::FFI_CDEF,
            ReleaseSubtitle::FFI_CDEF,
            SteamApp::FFI_CDEF,
            ReleaseNzbPassword::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('metadata', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the TV/subtitle/Steam/password model exports is not available.');
        }

        return $ffi;
    }
}
