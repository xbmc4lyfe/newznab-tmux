<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lines are real announces captured on 2026-10-10, with IRC colour codes stripped as IRCClient does.
 */
final class PreAnnounceParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function preLines(): iterable
    {
        yield 'corrupt-net and EFnet' => ['corruptnet', 'PRE: [TV-X264] MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'TV-X264'];
        yield 'zenet and Abjects' => ['zenet', '(PRE) (TV-X264-HD) (MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG)', 'MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'TV-X264-HD'];
        yield 'zenet name with parentheses' => ['zenet', '(PRE) (MP3-WEB) (Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC)', 'Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC', 'MP3-WEB'];
        yield 'predataba.se' => ['predatabase', 'pre | TV-HD-CZ | MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'TV-HD-CZ'];
        yield 'ngp.re' => ['ngp', '.::: PRE ::: TV-X264-HD ::: MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG :::.', 'MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'TV-X264-HD'];
        yield 'Rizon' => ['rizon', '[PRE]  [TV-HD]  MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'MASH.S02E12.1973.DUBBED.CZECH.1080p.WEB.x264-OMG', 'TV-HD'];
    }

    #[Test]
    #[DataProvider('preLines')]
    public function pre_lines_become_entries_timed_at_receipt(string $format, string $line, string $title, string $section): void
    {
        $at = CarbonImmutable::create(2026, 10, 10, 10, 50, 0, 'UTC');
        $entry = (new PreAnnounceParser($format, 'src'))->parse($line, $at);

        $this->assertNotNull($entry);
        $this->assertSame($title, $entry->title);
        $this->assertSame($section, $entry->category);
        $this->assertSame('src', $entry->source);
        $this->assertSame(Predb::PRE_NONUKE, $entry->nuked);
        $this->assertFalse($entry->enrichOnly);
        $this->assertTrue($at->equalTo($entry->predate));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function infoLines(): iterable
    {
        yield 'corrupt-net' => ['corruptnet', 'INFO: QuoVadisX.v1.0.85.MultiLanguage.WinALL.Incl.Keygen-BLiZZARD [14F 67MB]', 'QuoVadisX.v1.0.85.MultiLanguage.WinALL.Incl.Keygen-BLiZZARD', '14', '67MB'];
        yield 'predataba.se' => ['predatabase', 'info | QuoVadisX.v1.0.85.MultiLanguage.WinALL.Incl.Keygen-BLiZZARD - 14 F & 67 MB', 'QuoVadisX.v1.0.85.MultiLanguage.WinALL.Incl.Keygen-BLiZZARD', '14', '67MB'];
        yield 'zenet' => ['zenet', '(INFO) (Some.Release.2026.1080p.WEB.h264-GRP) (2 Files) (38 MB)', 'Some.Release.2026.1080p.WEB.h264-GRP', '2', '38MB'];
        yield 'ngp.re' => ['ngp', '[INFO] FOR Coss-Ginkgo-(METANOIA064)-24BIT-WEB-FLAC-2022-BABAS with 5F 158.3MB added.', 'Coss-Ginkgo-(METANOIA064)-24BIT-WEB-FLAC-2022-BABAS', '5', '158.3MB'];
    }

    #[Test]
    #[DataProvider('infoLines')]
    public function info_lines_only_carry_files_and_size(string $format, string $line, string $title, string $files, string $size): void
    {
        $entry = (new PreAnnounceParser($format, 'src'))->parse($line);

        $this->assertNotNull($entry);
        $this->assertSame($title, $entry->title);
        $this->assertSame($files, $entry->files);
        $this->assertSame($size, $entry->size);
        $this->assertTrue($entry->enrichOnly);
        $this->assertNull($entry->predate);
    }

    #[Test]
    public function only_the_winning_p2p_trace_line_is_a_pre(): void
    {
        $parser = new PreAnnounceParser('predatabase', 'predataba.se');

        $win = $parser->parse('p2p | #1 | SP | The.Kardashians.S08E01.Slap.the.S.Outta.Her.2160p.DSNP.WEB-DL.DDP5.1.DV.HDR10Plus.H.265-Kitsune, WIN!');
        $later = $parser->parse('p2p | #3 | IPT | IMPACT.x.Nightline.S05E02.The.Cornell.Seven.1080p.DSNP.WEB-DL.AAC2.0.H.264-RAWR, HDT won 2h 38m 1s ago');

        $this->assertSame('The.Kardashians.S08E01.Slap.the.S.Outta.Her.2160p.DSNP.WEB-DL.DDP5.1.DV.HDR10Plus.H.265-Kitsune', $win?->title);
        $this->assertNotNull($win->predate);
        $this->assertNull($later);
    }

    #[Test]
    public function nuke_lines_carry_status_and_reason_but_no_pre_time(): void
    {
        $corrupt = (new PreAnnounceParser('corruptnet', 'corrupt-net'))->parse('NUKE: Some.Show.S01E01.720p.WEB.h264-GRP [dupe.of.other] [NUKENET]');
        $zenet = (new PreAnnounceParser('zenet', 'zenet'))->parse('(UNNUKE) (Some.Show.S01E01.720p.WEB.h264-GRP) (fixed)');
        $rizon = (new PreAnnounceParser('rizon', 'rizon'))->parse('[NUKE]  Some.Show.S01E01.720p.WEB.h264-GRP  [bad.ivtc]  [NUKENET]');

        $this->assertSame(Predb::PRE_NUKED, $corrupt?->nuked);
        $this->assertSame('dupe.of.other', $corrupt->nukeReason);
        $this->assertNull($corrupt->predate);
        $this->assertSame(Predb::PRE_UNNUKED, $zenet?->nuked);
        $this->assertSame('fixed', $zenet->nukeReason);
        $this->assertSame(Predb::PRE_NUKED, $rizon?->nuked);
        $this->assertSame('bad.ivtc', $rizon->nukeReason);
    }

    #[Test]
    public function chatter_genre_url_and_trace_lines_are_ignored(): void
    {
        $this->assertNull((new PreAnnounceParser('corruptnet', 'c'))->parse('hello everyone'));
        $this->assertNull((new PreAnnounceParser('corruptnet', 'c'))->parse('PRE: [TV] not a release name'));
        $this->assertNull((new PreAnnounceParser('zenet', 'z'))->parse('(GENRE) (Hamilton.2020.LATiN.SPANiSH.SUBBED.2160p.UHD.BluRay.x265-CEBRAY) (History/Drama)'));
        $this->assertNull((new PreAnnounceParser('zenet', 'z'))->parse('(URL) (Hamilton.2020.LATiN.SPANiSH.SUBBED.2160p.UHD.BluRay.x265-CEBRAY) (https://www.imdb.com/title/tt8503618/)'));
        $this->assertNull((new PreAnnounceParser('predatabase', 'p'))->parse('genre | Hamilton.2020.LATiN.SPANiSH.SUBBED.2160p.UHD.BluRay.x265-CEBRAY - History/Drama'));
        $this->assertNull((new PreAnnounceParser('predatabase', 'p'))->parse('trace | #7 | PTM | The.Traitors.New.Blood.S01E04.720p.WEB.H264-iNSiDiOUS @ 2 MB/s, pred 6m 59s ago, DC won 6m 52s ago'));
        $this->assertNull((new PreAnnounceParser('zenet', 'z'))->parse('PRE: [FLAC] Wrong.Format-GRP'));
    }

    #[Test]
    public function unknown_formats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreAnnounceParser('nntmux', 'synirc');
    }
}
