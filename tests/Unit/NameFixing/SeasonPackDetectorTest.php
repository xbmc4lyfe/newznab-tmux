<?php

declare(strict_types=1);

namespace Tests\Unit\NameFixing;

use App\Services\NameFixing\SeasonPackDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeasonPackDetectorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function seasonPackNames(): array
    {
        return [
            'dotted season pack' => ['Show.S01.1080p.WEB-DL.DDP5.1.H.264-GRP', true],
            'spaced lowercase' => ['show s01 web', true],
            'season range' => ['Show.S01-S03.1080p.BluRay.x264-GRP', true],
            'short season range' => ['Show.S01-03.720p.HDTV.x264-GRP', true],
            'dvd disc' => ['Show.S02D1.PAL.DVDR-GRP', true],
            'disc word' => ['Show.S01.Disc.2.DVDR-GRP', true],
            'four digit season token' => ['Show.S2024.1080p.WEB-DL-GRP', true],
            'season word' => ['Show.Season.1.720p.WEB-DL-GRP', true],
            'complete series' => ['Show.Complete.Series.1080p.BluRay-GRP', true],
            'episode' => ['Show.S01E01.1080p.WEB-DL-GRP', false],
            'double episode' => ['Show.S01E01E02.720p.HDTV-GRP', false],
            'episode range' => ['Show.S01E01-E03.720p.HDTV-GRP', false],
            'dotted episode' => ['Show.S01.E05.720p.HDTV-GRP', false],
            'x episode' => ['Show.1x05.HDTV.XviD-GRP', false],
            'episode word' => ['Show.S01.Episode.5.720p-GRP', false],
            'dts audio' => ['Movie.2020.1080p.BluRay.DTS5.1-GRP', false],
            'atmos audio' => ['Movie.2020.2160p.WEB-DL.Atmos7.1-GRP', false],
            'ddp audio' => ['Movie.2019.1080p.BluRay.DDP5.1.x264-GRP', false],
            'resolution' => ['Movie.2020.1920x1080.x264-GRP', false],
            'word ending in s' => ['Pass2.Videos2020.Collection', false],
            'daily show' => ['Show.2024.01.15.Guest.720p.WEB-GRP', false],
            'anime episode' => ['[Grp] Show - 01 [1080p]', false],
        ];
    }

    #[Test]
    #[DataProvider('seasonPackNames')]
    public function it_recognises_season_packs_by_name(string $name, bool $expected): void
    {
        $this->assertSame($expected, SeasonPackDetector::isSeasonPack($name));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string|null}>
     */
    public static function episodeSets(): array
    {
        return [
            'shared release info' => [
                ['Show.S01E01.1080p.WEB-DL-GRP.mkv', 'Show.S01E02.1080p.WEB-DL-GRP.mkv'],
                'Show.S01.1080p.WEB-DL-GRP',
            ],
            'episode titles are dropped' => [
                ['Show.S01E01.Pilot.1080p.WEB-DL-GRP.mkv', 'Show.S01E02.The.Title.1080p.WEB-DL-GRP.mkv'],
                'Show.S01.1080p.WEB-DL-GRP',
            ],
            'titles sharing a word are dropped' => [
                ['Show.S01E01.Part.One.720p.HDTV.x264-G.mkv', 'Show.S01E02.Chapter.One.720p.HDTV.x264-G.mkv'],
                'Show.S01.720p.HDTV.x264-G',
            ],
            'tag on one episode is dropped' => [
                ['Show.S01E01.1080p-G.mkv', 'Show.S01E02.REPACK.1080p-G.mkv'],
                'Show.S01.1080p-G',
            ],
            'double episode file' => [
                ['Show.S01E01E02.720p.HDTV-G.mkv', 'Show.S01E03.720p.HDTV-G.mkv'],
                'Show.S01.720p.HDTV-G',
            ],
            'two seasons' => [
                ['Show.S01E01.720p.HDTV-G.mkv', 'Show.S02E01.720p.HDTV-G.mkv'],
                'Show.S01-S02.720p.HDTV-G',
            ],
            'archive volumes inside a folder' => [
                ['Show.S01.1080p-GRP/Show.S01E01.1080p.WEB-DL-GRP.part01.rar', 'Show.S01.1080p-GRP/Show.S01E02.1080p.WEB-DL-GRP.part01.rar'],
                'Show.S01.1080p.WEB-DL-GRP',
            ],
            'different groups use the first episode' => [
                ['Show.S01E01.1080p.WEB-DL-GRP.mkv', 'Show.S01E02.1080p.WEB-DL-OTH.mkv'],
                'Show.S01.1080p.WEB-DL-GRP',
            ],
            'one episode repeated' => [
                ['Show.S01E01.720p-G.mkv', 'Show.S01E01.720p-G.mkv'],
                null,
            ],
            'different shows' => [
                ['ShowA.S01E01.720p-G.mkv', 'ShowB.S01E02.720p-G.mkv'],
                null,
            ],
            'sample is ignored' => [
                ['Show.S01E01.720p-G.mkv', 'Show.S01E02.720p-G.sample.mkv'],
                null,
            ],
        ];
    }

    /**
     * @param  list<string>  $names
     */
    #[Test]
    #[DataProvider('episodeSets')]
    public function it_collapses_episodes_to_the_season_name(array $names, ?string $expected): void
    {
        $this->assertSame($expected, SeasonPackDetector::seasonNameFromEpisodes($names));
        $this->assertSame($expected !== null, SeasonPackDetector::isMultiEpisodeSet($names));
    }

    #[Test]
    #[DataProvider('supportedSeasonEpisodeForms')]
    public function it_parses_supported_season_bearing_forms_without_inventing_seasons(array $names, ?string $expected): void
    {
        self::assertSame($expected, SeasonPackDetector::seasonNameFromEpisodes($names));
        self::assertSame($expected !== null, SeasonPackDetector::isMultiEpisodeSet($names));
    }

    public static function supportedSeasonEpisodeForms(): array
    {
        return [
            'x notation' => [['Show.1x01.1080p.WEB-DL-GRP.mkv', 'Show.1x02.1080p.WEB-DL-GRP.mkv'], 'Show.S01.1080p.WEB-DL-GRP'],
            'four digit season' => [['Show.S2024E01.720p.WEB-GRP.mkv', 'Show.S2024E02.720p.WEB-GRP.mkv'], 'Show.S2024.720p.WEB-GRP'],
            'three digit season' => [['Show.S123E01.720p.WEB-GRP.mkv', 'Show.S123E02.720p.WEB-GRP.mkv'], 'Show.S123.720p.WEB-GRP'],
            'mixed equivalent season notation' => [['Show.1x01.1080p.WEB-DL-GRP.mkv', 'Show.S01E02.1080p.WEB-DL-GRP.mkv'], 'Show.S01.1080p.WEB-DL-GRP'],
            'mixed separators in show name' => [['Show.Name.1x01.720p.WEB-GRP.mkv', 'show_name.S01E02.720p.WEB-GRP.mkv'], 'Show.Name.S01.720p.WEB-GRP'],
            'same canonical episode is not a pack' => [['Show.1x01.720p.WEB-GRP.mkv', 'Show.S01E01.720p.WEB-GRP.mkv'], null],
            'year seasons preserve range width' => [['Show.S2024E01.720p.WEB-GRP.mkv', 'Show.S2025E01.720p.WEB-GRP.mkv'], 'Show.S2024-S2025.720p.WEB-GRP'],
            'four digit multi episode' => [['Show.S2024E01E02.720p.WEB-GRP.mkv', 'Show.S2024E03.720p.WEB-GRP.mkv'], 'Show.S2024.720p.WEB-GRP'],
            'standard episode range' => [['Show.S01E01-E03.720p.WEB-GRP.mkv', 'Show.S01E04.720p.WEB-GRP.mkv'], 'Show.S01.720p.WEB-GRP'],
            'x episode range' => [['Show.1x01-03.720p.WEB-GRP.mkv', 'Show.1x04.720p.WEB-GRP.mkv'], 'Show.S01.720p.WEB-GRP'],
            'explicit season plus episode word' => [['Show.S01.Episode.5.720p.WEB-GRP.mkv', 'Show.S01.Episode.6.720p.WEB-GRP.mkv'], 'Show.S01.720p.WEB-GRP'],
            'x sample ignored' => [['Show.1x01.720p.WEB-GRP.mkv', 'Show.1x02.720p.WEB-GRP.sample.mkv'], null],
            'episode words lack a season' => [['Show.Episode.1.720p.WEB-GRP.mkv', 'Show.Episode.2.720p.WEB-GRP.mkv'], null],
            'episode tokens lack a season' => [['Show.E01.720p.WEB-GRP.mkv', 'Show.E02.720p.WEB-GRP.mkv'], null],
            'EP tokens lack a season' => [['Show.EP01.720p.WEB-GRP.mkv', 'Show.EP02.720p.WEB-GRP.mkv'], null],
            'resolution is not an episode' => [['Movie.1920x1080.720p.WEB-GRP.mkv', 'Movie.1920x1081.720p.WEB-GRP.mkv'], null],
            'x episode boundary rejects attached title letters' => [['Show.1x01Bonus.720p.WEB-GRP.mkv', 'Show.1x02Bonus.720p.WEB-GRP.mkv'], null],
            'season width outside supported range' => [['Show.S12345E01.720p.WEB-GRP.mkv', 'Show.S12345E02.720p.WEB-GRP.mkv'], null],
            'episode width outside supported range' => [['Show.S01E12345.720p.WEB-GRP.mkv', 'Show.S01E12346.720p.WEB-GRP.mkv'], null],
        ];
    }

    #[Test]
    public function it_derives_single_season_names_for_supported_season_notation(): void
    {
        self::assertSame('Show.S01.720p.WEB-GRP', SeasonPackDetector::seasonNameFromEpisode('Show.1x01.Pilot.720p.WEB-GRP.mkv'));
        self::assertSame('Show.S2024.720p.WEB-GRP', SeasonPackDetector::seasonNameFromEpisode('Show.S2024E01.Pilot.720p.WEB-GRP.mkv'));
        self::assertNull(SeasonPackDetector::seasonNameFromEpisode('Show.Episode.1.720p.WEB-GRP.mkv'));
        self::assertNull(SeasonPackDetector::seasonNameFromEpisode('Show.S01E12345.720p.WEB-GRP.mkv'));
    }

    #[Test]
    public function it_turns_one_episode_into_its_season_name(): void
    {
        $this->assertSame(
            'Sister.Wives.2010.S19.1080p.DSCP.WEB-DL.DDP2.0.H.265-FUZEER',
            SeasonPackDetector::seasonNameFromEpisode('Sister.Wives.2010.S19E14.The.Truth.Will.Set.You.Free.1080p.DSCP.WEB-DL.DDP2.0.H.265-FUZEER.mkv'),
        );
        $this->assertNull(SeasonPackDetector::seasonNameFromEpisode('Show.S01E01.Pilot-GRP'));
        $this->assertNull(SeasonPackDetector::seasonNameFromEpisode('Movie.2020.1080p.BluRay-GRP'));
    }

    #[Test]
    public function it_finds_the_season_folder(): void
    {
        $this->assertSame('Show.S01.1080p-GRP', SeasonPackDetector::seasonFolder(['Show.S01.1080p-GRP/Show.S01E01.mkv']));
        $this->assertSame('Show.S01.1080p-GRP', SeasonPackDetector::seasonFolder(['Show.S01.1080p-GRP\\Subs\\Show.S01E01.srt']));
        $this->assertNull(SeasonPackDetector::seasonFolder(['Show.S01E01.mkv']));
        $this->assertNull(SeasonPackDetector::seasonFolder(['Movie.2020/VIDEO_TS/VTS_01_1.VOB']));
    }
}
