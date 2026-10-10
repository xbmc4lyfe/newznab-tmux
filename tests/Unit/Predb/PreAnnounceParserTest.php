<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PreAnnounceParserTest extends TestCase
{
    #[Test]
    public function corrupt_net_pre_lines_become_entries_timed_at_receipt(): void
    {
        $at = CarbonImmutable::create(2026, 10, 9, 23, 40, 0, 'UTC');
        $entry = (new PreAnnounceParser('corruptnet', 'corrupt-net'))->parse('PRE: [FLAC] Hoodrich_Pablo_Juan-The_Bloprint-24BIT-WEB-FLAC-2026-MEiDUNG', $at);

        $this->assertNotNull($entry);
        $this->assertSame('Hoodrich_Pablo_Juan-The_Bloprint-24BIT-WEB-FLAC-2026-MEiDUNG', $entry->title);
        $this->assertSame('corrupt-net', $entry->source);
        $this->assertSame('FLAC', $entry->category);
        $this->assertSame(Predb::PRE_NONUKE, $entry->nuked);
        $this->assertTrue($at->equalTo($entry->predate));
    }

    #[Test]
    public function zenet_pre_lines_become_entries(): void
    {
        $entry = (new PreAnnounceParser('zenet', 'zenet'))->parse('(PRE) (MP3-WEB) (Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC)');

        $this->assertNotNull($entry);
        $this->assertSame('Slow_AF-Exceptions-(FGA103)-WEB-2026-PTC', $entry->title);
        $this->assertSame('MP3-WEB', $entry->category);
        $this->assertSame('zenet', $entry->source);
    }

    #[Test]
    public function predataba_se_pre_lines_become_entries(): void
    {
        $entry = (new PreAnnounceParser('predatabase', 'predataba.se'))->parse('pre | MP3-WEB | All_Things_Break-Climb-(SELECTED445)-SINGLE-WEB-2026-PTC');

        $this->assertSame('All_Things_Break-Climb-(SELECTED445)-SINGLE-WEB-2026-PTC', $entry?->title);
        $this->assertSame('MP3-WEB', $entry->category);
        $this->assertSame('predataba.se', $entry->source);
    }

    #[Test]
    public function nuke_lines_carry_status_and_reason_but_no_pre_time(): void
    {
        $corrupt = (new PreAnnounceParser('corruptnet', 'corrupt-net'))->parse('NUKE: Some.Show.S01E01.720p.WEB.h264-GRP [dupe.of.other] [NUKENET]');
        $zenet = (new PreAnnounceParser('zenet', 'zenet'))->parse('(UNNUKE) (Some.Show.S01E01.720p.WEB.h264-GRP) (fixed)');

        $this->assertSame(Predb::PRE_NUKED, $corrupt?->nuked);
        $this->assertSame('dupe.of.other', $corrupt->nukeReason);
        $this->assertNull($corrupt->predate);
        $this->assertSame(Predb::PRE_UNNUKED, $zenet?->nuked);
        $this->assertSame('fixed', $zenet->nukeReason);
    }

    #[Test]
    public function chatter_and_unknown_announce_types_are_ignored(): void
    {
        $corrupt = new PreAnnounceParser('corruptnet', 'corrupt-net');
        $zenet = new PreAnnounceParser('zenet', 'zenet');

        $this->assertNull($corrupt->parse('hello everyone'));
        $this->assertNull($corrupt->parse('INFO: [X] Not.A.Release-GRP'));
        $this->assertNull($corrupt->parse('PRE: [TV] not a release name'));
        $this->assertNull($zenet->parse('(PRE) (TV)'));
        $this->assertNull($zenet->parse('PRE: [FLAC] Wrong.Format-GRP'));
    }

    #[Test]
    public function unknown_formats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreAnnounceParser('nntmux', 'synirc');
    }
}
