<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Checkers\AppNameChecker;
use App\Services\NameFixing\Checkers\GameNameChecker;
use App\Services\NameFixing\Checkers\MovieNameChecker;
use App\Services\NameFixing\Checkers\TvNameChecker;
use App\Services\NameFixing\Patterns\AppPatterns;
use App\Services\NameFixing\Patterns\GamePatterns;
use App\Services\NameFixing\Patterns\MoviePatterns;
use App\Services\NameFixing\Patterns\TvPatterns;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RustNameCheckersParityTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    public static function fixtures(): array
    {
        return [
            'tv' => [
                'STREAMING_4K_HDR' => 'Show.Name.S01E01.2160p.AMZN.WEB-DL.HDR10.HEVC-GROUP',
                'STREAMING_HD' => 'Show.Name.S01E01.1080p.NF.WEB-DL-GROUP',
                'TV_RES_SOURCE_VCODEC' => 'Show.Name.S01E01.720p.HDTV.x264-GROUP',
                'TV_AUDIO_SOURCE_RES_VCODEC' => 'Show.Name.S01E01.DD5.1.HDTV.720p.x264-GROUP',
                'TV_SOURCE_VCODEC' => 'Show.Name.S01E01.HDTV.x264-GROUP',
                'TV_SOURCE_GROUP' => 'Show.Name.S01E01.HDTV-GROUP',
                'TV_YEAR_SEASON' => 'Show.Name.2023.S01E01.HDTV-GROUP',
                'TV_WITH_YEAR' => 'Show.Name.S01E01.Something.2023.x264-GROUP',
                'DAILY_SHOW' => 'Daily.Show.2023.12.15.720p.HDTV-GROUP',
                // Preserve the existing leading word character before the date.
                'SPORTS' => 'X2023.12.15.UFC.1080p.WEB-GROUP',
                'COMPLETE_SEASON' => 'Show.Name.S01.COMPLETE.1080p.WEB-DL-GROUP',
                'ANIME' => 'Anime.Name.001.1080p.HEVC.10bit-GROUP',
            ],
            'movie' => [
                'UHD_HDR' => 'Movie.Name.2023.2160p.HDR10.BluRay.HEVC-GROUP',
                'UHD_REMUX' => 'Movie.Name.2023.2160p.Complete.UHD.HEVC-GROUP',
                'STREAMING_4K' => 'Movie.Name.2023.2160p.AMZN.WEB-DL.HDR10.HEVC-GROUP',
                'STREAMING_HD' => 'Movie.Name.2023.1080p.NF.WEB-DL.x264-GROUP',
                'YEAR_RES_VCODEC' => 'Movie.Name.2023.1080p.x264-GROUP',
                'YEAR_SOURCE_VCODEC_RES' => 'Movie.Name.2023.BluRay.x264.1080p-GROUP',
                'YEAR_SOURCE_VCODEC_ACODEC' => 'Movie.Name.2023.BluRay.x264.DTS-GROUP',
                'RES_SOURCE_ACODEC_VCODEC' => 'Movie.Name.1080p.BluRay.DTS.x264-GROUP',
                'RES_ACODEC_SOURCE_YEAR' => 'Movie.Name.1080p.DTS.BluRay.2023-GROUP',
                'MULTI_LANGUAGE' => 'Movie.Name.FRENCH.2023.DTS.BluRay-GROUP',
                'GENERIC' => 'Movie.Name.2023.1080p.BluRay-GROUP',
            ],
            'game' => [
                'MODERN_CONSOLE' => 'Game.Name.PS5.PROPER-GROUP',
                'REGION_CONSOLE' => 'Game.Name.EUR.PS5-GROUP',
                'CONSOLE_SCENE_GROUP' => 'Game.Name.PS5-CODEX.More',
                'PC_SCENE_GROUP' => 'Game.Name.PC-CODEX.More',
                'DLC_UPDATE' => 'Game.Name.Update.v1.2-PLAZA.More',
                'OUTLAWS' => 'Game.Name-OUTLAWS',
                'ALIAS' => 'Game.Name-ALiAS',
                'GOG' => 'Game.Name.GOG.Classic-GROUP',
                'REPACK' => 'Game.Name.REPACK-FitGirl.More',
            ],
            'app' => [
                'WITH_KEYGEN' => 'App.Name.v1.0.Incl.Keygen-GROUP',
                'ADOBE' => 'Adobe.Photoshop.2024.v25.0-GROUP',
                'MICROSOFT' => 'Microsoft.Office.2021.Pro.Plus-GROUP',
                'WINDOWS' => 'App.Name.v1.0.WinAll-GROUP',
                'MACOS' => 'App.Name.v1.0.MacOS-GROUP',
                'LINUX' => 'App.Name.v1.0.Linux.x64-GROUP',
                'WITH_VERSION' => 'App.Name.v1.2.3.Multilingual-GROUP',
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function patterns(): array
    {
        return [
            'tv' => TvPatterns::getAllPatterns(),
            'movie' => MoviePatterns::getAllPatterns(),
            'game' => GamePatterns::getAllPatterns(),
            // check() prioritizes vendors before platforms.
            'app' => ['WITH_KEYGEN' => AppPatterns::WITH_KEYGEN]
                + AppPatterns::getVendorPatterns() + AppPatterns::getPlatformPatterns()
                + ['WITH_VERSION' => AppPatterns::WITH_VERSION],
        ];
    }

    private static function checker(string $domain, ?string $path = null): TvNameChecker|MovieNameChecker|GameNameChecker|AppNameChecker
    {
        return match ($domain) {
            'tv' => new TvNameChecker($path, useRust: true),
            'movie' => new MovieNameChecker($path, useRust: true),
            'game' => new GameNameChecker($path, useRust: true),
            'app' => new AppNameChecker($path, useRust: true),
            default => throw new \InvalidArgumentException($domain),
        };
    }

    private static function ffi(): FFI
    {
        $cdef = (new \ReflectionClass(TvNameChecker::class))->getConstant('FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('name_checkers', $cdef);
        self::assertNotNull($ffi, 'Build the release library before running native parity tests.');

        return $ffi;
    }

    /** @return array{int, string} */
    private static function native(string $domain, string $input, int $capacity = 16384): array
    {
        $ffi = self::ffi();
        $buf = $ffi->new('char['.max(1, $capacity).']');
        $symbol = 'nntmux_name_check_'.$domain;
        $written = $ffi->$symbol($input, strlen($input), $buf, $capacity);
        self::assertIsInt($written);

        return [$written, $written > 0 ? FFI::string($buf, $written) : ''];
    }

    private static function assertParity(string $domain, string $input, bool $nativeRequired = true): void
    {
        $checker = self::checker($domain);
        $release = (object) [];
        $php = $checker->checkPhp($release, $input);
        self::assertEquals($php, $checker->check($release, $input), $domain.' '.bin2hex($input));
        self::assertEquals($php, self::checker($domain, '/nonexistent/name-checkers.so')->check($release, $input));

        [$rc, $raw] = self::native($domain, $input);
        if (! $nativeRequired) {
            self::assertLessThan(0, $rc);

            return;
        }
        self::assertGreaterThanOrEqual(0, $rc, 'Unexpected native decline: '.$domain.' '.bin2hex($input));
        $expected = '';
        foreach (self::patterns()[$domain] as $name => $pattern) {
            if (preg_match($pattern, $input, $matches) === 1) {
                $expected = $name."\0".$matches[0];
                break;
            }
        }
        self::assertSame($expected, $raw, $domain.' '.bin2hex($input));
    }

    public function test_every_declared_pattern_has_a_positive_fixture_and_identical_rust_expression(): void
    {
        $source = file_get_contents(__DIR__.'/../../rust/pipeline-ffi/src/name_checkers.rs');
        self::assertIsString($source);
        foreach (self::patterns() as $domain => $patterns) {
            self::assertSame(array_keys($patterns), array_keys(self::fixtures()[$domain]));
            foreach ($patterns as $name => $pattern) {
                self::assertSame(1, preg_match($pattern, self::fixtures()[$domain][$name]), $domain.'.'.$name);
                self::assertStringContainsString('r#"'.substr($pattern, 1, -2).'"#', $source, $domain.'.'.$name);
            }
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        foreach (self::fixtures() as $domain => $fixtures) {
            foreach ($fixtures as $name => $input) {
                foreach ([$input, strtolower($input), strtoupper($input), '['.$input.']', $input."\n", "prefix\n".$input, "\0".$input."\0", $input.' '.$input] as $i => $variant) {
                    yield "$domain $name $i" => [$domain, $variant, true];
                }
            }
            foreach (['', '0', 'random-file.rar', 'nothing to match', "\n\r\t", 's01e01', '2023.1080p', 'Version.v1.2', '___---...', '1234567890', "a\0b\nc"] as $i => $input) {
                yield "$domain negative $i" => [$domain, $input, true];
            }
            foreach (['Café '.reset($fixtures), "\xff".reset($fixtures), str_repeat('a', 4097)] as $i => $input) {
                yield "$domain declined $i" => [$domain, $input, false];
            }
        }
        foreach ([
            'Show.Name.S01E001.1080p.NF.WEB-DL-GROUP',
            'Show.Name.|01|001.1080p.NF.WEB-DL-GROUP',
            'Show.Name.101x01.HDTV.x264-GROUP',
            'Show.Name.1x01.HDTV.x264-GROUP',
            'Show.Name.S01E01-E03.2160p.NF.WEBRIP.DV.x265-GROUP',
            'X2023.12.15.Formula.1.2024-GROUP',
            'Show.Name.S01E01.(title).HDTV.x264-GROUP',
        ] as $i => $input) {
            yield "tv edge $i" => ['tv', $input, true];
        }
        foreach (['Game.Name.PS5-GROUP', "Game.Name.PS5.PROPER-GROUP\r\n", 'Game.Name.PS5.PROPER-GROUP trailing', 'Game.Name-OUTLAWS-OUTLAWS', 'Game.Name-outlaws', 'Game.Name-ALIAS', "Game.Name\n-OUTLAWS", 'Game.Name-ALiAS and Game-OUTLAWS'] as $i => $input) {
            yield "game edge $i" => ['game', $input, true];
        }
        yield 'app vendor before platform' => ['app', 'Adobe.Photoshop.2024.Win64-GROUP', true];
        yield 'movie priority before position' => ['movie', 'Movie.2024.1080p.NF.WEB-DL.x264-GROUP!Other.Movie.2023.2160p.HDR10.BluRay.HEVC-GROUP', true];
    }

    #[DataProvider('cases')]
    public function test_native_and_php_results_match(string $domain, string $input, bool $nativeRequired): void
    {
        self::assertParity($domain, $input, $nativeRequired);
    }

    public function test_deterministic_randomized_mutations_preserve_full_results(): void
    {
        $state = 0x12345678;
        $random = static function (int $bound) use (&$state): int {
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

            return $state % $bound;
        };
        $alphabet = "abcdefghijklmnopqrstuvwxyz0123456789._- |,&;'()[]\n\r\0";
        foreach (self::fixtures() as $domain => $fixtures) {
            $fixtures = array_values($fixtures);
            for ($i = 0; $i < 500; $i++) {
                $input = $fixtures[$random(count($fixtures))];
                for ($j = 0, $n = 1 + $random(8); $j < $n; $j++) {
                    $pos = $random(strlen($input) + 1);
                    $input = substr_replace($input, $alphabet[$random(strlen($alphabet))], $pos, $random(2));
                }
                self::assertParity($domain, $input);
            }
        }
    }

    public function test_unqualified_candidate_keeps_php_as_the_default(): void
    {
        foreach ([new TvNameChecker, new MovieNameChecker, new GameNameChecker, new AppNameChecker] as $checker) {
            $property = new \ReflectionProperty($checker, 'useRust');
            self::assertFalse($property->getValue($checker));
            foreach (self::fixtures()[strtolower($checker->getName())] as $input) {
                self::assertEquals($checker->checkPhp((object) [], $input), $checker->check((object) [], $input));
            }
        }
    }

    public function test_output_capacity_invalid_utf8_and_decline_codes(): void
    {
        foreach (self::fixtures() as $domain => $fixtures) {
            $input = reset($fixtures);
            [$size, $raw] = self::native($domain, $input);
            self::assertGreaterThan(0, $size);
            self::assertSame([$size, $raw], self::native($domain, $input, $size));
            self::assertSame([-3, ''], self::native($domain, $input, $size - 1));
            self::assertSame([-3, ''], self::native($domain, $input, 0));
            self::assertSame([-1, ''], self::native($domain, "\xff"));
            self::assertSame([-2, ''], self::native($domain, 'é'));
            self::assertSame([-2, ''], self::native($domain, str_repeat('a', 4097)));
        }
    }
}
