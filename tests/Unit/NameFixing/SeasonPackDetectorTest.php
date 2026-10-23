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
            'x episode format' => [
                ['Show.1x01.1080p.WEB-DL-GRP.mkv', 'Show.1x02.1080p.WEB-DL-GRP.mkv'],
                'Show.S01.1080p.WEB-DL-GRP',
            ],
            'year season format' => [
                ['Show.S2024E01.1080p.WEB-DL-GRP.mkv', 'Show.S2024E02.1080p.WEB-DL-GRP.mkv'],
                'Show.S2024.1080p.WEB-DL-GRP',
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
        if ($expected !== null) {
            $this->assertTrue(SeasonPackDetector::isSeasonPack($expected));
        }
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

    #[Test]
    public function it_preserves_single_episode_identity_across_release_info_tokens_and_rejects_continuations(): void
    {
        $tokens = [
            '720p', '1080p', '2160p', '1080i', '4k', 'uhd', 'hdr', 'hdr10', 'hdr10plus', 'dv', 'sdr',
            'web', 'webrip', 'webdl', 'bluray', 'blu', 'bd', 'bdrip', 'bdremux', 'brrip', 'remux',
            'dvd', 'dvdrip', 'dvdr', 'dvd5', 'dvd9', 'hdtv', 'pdtv', 'sdtv', 'hdrip', 'dsr',
            'amzn', 'nf', 'dsnp', 'hmax', 'atvp', 'hulu', 'pcok', 'pmtp', 'stan', 'crav',
            'x264', 'x265', 'X264', 'X265', 'h264', 'h265', 'hevc', 'avc', 'xvid', 'divx',
            '10bit', '8bit', 'aac', 'aac2', 'ac3', 'eac3', 'dd', 'dd5', 'ddp', 'ddp5', 'dts', 'truehd', 'atmos', 'flac',
            'proper', 'repack', 'repack2', 'rerip', 'internal', 'multi', 'dual', 'subbed', 'dubbed', 'extended', 'uncut',
            'german', 'french', 'spanish', 'italian', 'dutch', 'nordic', 'swedish', 'danish', 'finnish', 'norwegian',
            'polish', 'russian', 'hindi', 'japanese', 'korean',
        ];

        foreach ($tokens as $token) {
            $name = 'Show.S01E01.1080p.WEB.'.$token.'-GRP.mkv';
            $this->assertSame('show:1:1', SeasonPackDetector::episodeIdentity($name), $token);
            $this->assertFalse(SeasonPackDetector::hasNonSingleEpisodeEvidence([$name]), $token);
        }

        foreach (['S01E01' => 'show:1:1', 'S01.E01' => 'show:1:1', 'S2024E01' => 'show:2024:1', '1x01' => 'show:1:1'] as $marker => $identity) {
            foreach (['x264', 'x265', 'X264', 'X265'] as $codec) {
                foreach (['.', '-', '_', ' '] as $sep) {
                    $name = 'Show'.$sep.$marker.$sep.'1080p'.$sep.'WEB'.$sep.$codec.'-GRP.mkv';
                    $this->assertSame($identity, SeasonPackDetector::episodeIdentity($name), $name);
                    $this->assertFalse(SeasonPackDetector::hasNonSingleEpisodeEvidence([$name]), $name);
                }
            }
        }

        foreach ([
            'Show.S01E01.1080p.WEB.x02-GRP.mkv',
            'Show.1x01x02.1080p.WEB.x264-GRP.mkv',
            'Show.S01E01.1080p.WEB.x264E02-GRP.mkv',
            'Show.S01E01.1080p.WEB.x265x02-GRP.mkv',
            'Show.S01E01.1080p.WEB.x264.E02-GRP.mkv',
            'Show.S01E01.1080p.WEB.x265.S02-GRP.mkv',
            'Show.S01E01.1080p.WEB.x264.Complete.Season-GRP.mkv',
        ] as $ambiguous) {
            $this->assertNull(SeasonPackDetector::episodeIdentity($ambiguous), $ambiguous);
            $this->assertNull(SeasonPackDetector::episodeIdentityPhp($ambiguous), $ambiguous);
            $this->assertTrue(SeasonPackDetector::hasNonSingleEpisodeEvidence([$ambiguous]), $ambiguous);
            $this->assertTrue(SeasonPackDetector::hasNonSingleEpisodeEvidencePhp([$ambiguous]), $ambiguous);
        }
    }
}
