<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RefreshAnimeData;
use App\Services\AnimeProcessor;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class AniDBParseTest extends TestCase
{
    private function makeAnimeProcessorInstance(): object
    {
        $rc = new ReflectionClass(AnimeProcessor::class);

        return $rc->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeExtract(object $instance, string $name): array
    {
        $rm = new ReflectionMethod($instance, 'extractTitleEpisode');
        /** @var array<string, mixed> $result */
        $result = $rm->invoke($instance, $name);

        return $result;
    }

    private function getStatus(object $instance): ?int
    {
        $rp = new ReflectionProperty($instance, 'status');
        /** @var int|null $status */
        $status = $rp->getValue($instance);

        return $status;
    }

    public function test_standard_bracketed_pattern_with_numeric_episode(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = '[HorribleSubs] My Hero Academia - 12 [1080p].mkv';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame(['title' => 'My Hero Academia'], $res);
    }

    public function test_e_prefix_with_underscores_and_dots(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = '[Group] Neon_Genesis.Evangelion E01 [720p]';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame('Neon Genesis Evangelion', $res['title']);
    }

    public function test_bd_release_without_explicit_episode(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = '[SomeGroup] Cowboy Bebop [BD][1080p]';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame('Cowboy Bebop', $res['title']);
    }

    public function test_simple_dash_pattern(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = 'Naruto - 3 [480p]';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame(['title' => 'Naruto'], $res);
    }

    public function test_movie_and_ova_extracts_title(): void
    {
        $sut = $this->makeAnimeProcessorInstance();

        $movieName = '[Group] Cowboy Bebop - Movie - Something [BD]';
        $resMovie = $this->invokeExtract($sut, $movieName);
        $this->assertSame('Cowboy Bebop', $resMovie['title']);

        $ovaName = 'FLCL - OVA - Disc 1 [720p]';
        $resOva = $this->invokeExtract($sut, $ovaName);
        $this->assertSame('FLCL', $resOva['title']);
    }

    public function test_complete_series_extracts_title(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = 'Attack on Titan - Complete Series [BD]';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame('Attack on Titan', $res['title']);
    }

    public function test_invalid_name_sets_status_and_returns_empty(): void
    {
        $sut = $this->makeAnimeProcessorInstance();
        $name = '';
        $res = $this->invokeExtract($sut, $name);

        $this->assertSame([], $res);

        $status = $this->getStatus($sut);
        $this->assertSame(-1, $status, 'Expected PROC_EXTFAIL (-1) status for invalid extraction');
    }

    public function test_refresh_anime_data_extract_title_from_searchname_and_rust_parity(): void
    {
        $rc = new ReflectionClass(RefreshAnimeData::class);
        $cmd = $rc->newInstanceWithoutConstructor();
        $extractCmd = new ReflectionMethod($cmd, 'extractTitleFromSearchname');
        $cleanCmd = new ReflectionMethod($cmd, 'cleanTitle');

        $sut = $this->makeAnimeProcessorInstance();
        $cleanSut = new ReflectionMethod($sut, 'cleanTitle');

        $cases = [
            '[SubsPlease] Sousou no Frieren - 01 (1080p) [F02B5C69].mkv' => 'Sousou no Frieren',
            '[Judas] Jujutsu Kaisen (Season 2) S02E18 [1080p][HEVC x265 10bit][Multi-Subs]' => 'Jujutsu Kaisen',
            '[Ember] Hunter x Hunter (2011) - 1x18 - Episode Title [BDRip 1080p HEVC]' => 'Hunter x Hunter',
            '[Group][SubGroup] Bleach_Thousand-Year_Blood_War [ENG] (JPN) Episode 007 [1080p][x264].mkv' => 'Bleach Thousand-Year Blood War',
            'Ghost in the Shell Stand Alone Complex 200401 Uncensored Mosaic-less FHD Vol. 2' => 'Ghost in the Shell Stand Alone Complex -less',
            'PokÃ©mon HorizonsÃ¢Â_Â - 024Â [1080p][HEVC].mkv' => 'Pokmon Horizons',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(['title' => $expected], $this->invokeExtract($sut, $input));
            $this->assertSame(['title' => $expected], $extractCmd->invoke($cmd, $input));

            if (RustPipelineLibrary::resolve() !== null) {
                $this->assertSame($expected, RustPipelineLibrary::animeExtractTitle($input));
                $this->assertSame($cleanSut->invoke($sut, $input), RustPipelineLibrary::animeCleanTitle($input));
                $this->assertSame($cleanCmd->invoke($cmd, $input), RustPipelineLibrary::animeCleanTitle($input));
            }
        }

        $this->assertSame([], $extractCmd->invoke($cmd, ''));
        $this->assertSame([], $extractCmd->invoke($cmd, '0'));
        $this->assertNull(RustPipelineLibrary::animeExtractTitle('Cowboy Bebop - 01 [1080p]', '/nonexistent/libnntmux_pipeline.so'));
        $this->assertNull(RustPipelineLibrary::animeCleanTitle('Cowboy Bebop [1080p]', '/nonexistent/libnntmux_pipeline.so'));
    }
}
