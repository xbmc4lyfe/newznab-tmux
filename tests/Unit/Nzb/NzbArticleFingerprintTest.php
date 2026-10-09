<?php

declare(strict_types=1);

namespace Tests\Unit\Nzb;

use App\Services\Nzb\NzbArticleFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NzbArticleFingerprintTest extends TestCase
{
    #[Test]
    public function the_fingerprint_ignores_file_and_segment_order(): void
    {
        $a = self::nzb([['<a1@x>', 'a2@x'], ['b1@x']]);
        $b = self::nzb([['b1@x'], ['a2@x', 'a1@x']]);

        $this->assertNotNull(NzbArticleFingerprint::fromContents($a));
        $this->assertSame(NzbArticleFingerprint::fromContents($a), NzbArticleFingerprint::fromContents($b));
    }

    #[Test]
    public function different_message_ids_produce_different_fingerprints(): void
    {
        $this->assertNotSame(
            NzbArticleFingerprint::fromContents(self::nzb([['a1@x', 'a2@x']])),
            NzbArticleFingerprint::fromContents(self::nzb([['c1@y', 'c2@y']])),
        );
    }

    #[Test]
    public function message_id_lists_match_the_nzb_fingerprint(): void
    {
        $this->assertSame(
            NzbArticleFingerprint::fromContents(self::nzb([['a1@x', 'a2@x']])),
            NzbArticleFingerprint::fromMessageIds(['<a2@x>', 'a1@x']),
        );
        $this->assertNull(NzbArticleFingerprint::fromMessageIds([]));
    }

    #[Test]
    public function the_incremental_accumulator_matches_the_list_fingerprint_in_any_order(): void
    {
        $accumulator = new NzbArticleFingerprint;
        foreach (['c3@x', '<a1@x>', 'b2@x'] as $messageId) {
            $accumulator->add($messageId);
        }

        $this->assertSame(NzbArticleFingerprint::fromMessageIds(['a1@x', 'b2@x', 'c3@x']), $accumulator->value());
        $this->assertNull((new NzbArticleFingerprint)->value());
    }

    #[Test]
    public function quoted_and_bracketed_message_ids_match_the_nzb_writer_normalisation(): void
    {
        $this->assertSame('part01@example.test', NzbArticleFingerprint::normalizeMessageId(' "<part01@example.test>" '));
        $this->assertSame(
            NzbArticleFingerprint::fromMessageIds(['part01@example.test', "'part02@example.test'"]),
            NzbArticleFingerprint::fromMessageIds(['"<part01@example.test>"', 'part02@example.test']),
        );
    }

    #[Test]
    public function unreadable_or_empty_nzbs_have_no_fingerprint(): void
    {
        $this->assertNull(NzbArticleFingerprint::fromContents('not xml'));
        $this->assertNull(NzbArticleFingerprint::fromContents(self::nzb([])));
    }

    /**
     * @param  list<list<string>>  $files  Message-IDs per file
     */
    public static function nzb(array $files): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach ($files as $i => $ids) {
            $xml .= '<file poster="p" date="1" subject="s'.$i.'"><groups><group>alt.binaries.test</group></groups><segments>';
            foreach ($ids as $n => $id) {
                $xml .= '<segment bytes="100" number="'.($n + 1).'">'.htmlspecialchars($id).'</segment>';
            }
            $xml .= '</segments></file>';
        }

        return $xml.'</nzb>';
    }
}
