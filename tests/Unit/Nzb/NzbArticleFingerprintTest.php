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
    public function repeated_normalized_message_ids_do_not_change_article_identity(): void
    {
        $singleReference = self::nzb([['a1@x', 'a2@x']]);
        $repeatedReferences = self::nzb([['a2@x', '<a1@x>', 'a1@x'], ['"<a2@x>"']]);

        $this->assertSame(NzbArticleFingerprint::fromContents($singleReference), NzbArticleFingerprint::fromContents($repeatedReferences));
        $this->assertSame(NzbArticleFingerprint::fromMessageIds(['a1@x', 'a2@x']), NzbArticleFingerprint::fromMessageIds(['a1@x', '<a1@x>', 'a2@x', 'a2@x']));
    }

    #[Test]
    public function incremental_repeated_references_do_not_change_the_fingerprint(): void
    {
        $fingerprint = new NzbArticleFingerprint;
        $fingerprint->add('a1@x');
        $original = $fingerprint->value();
        $fingerprint->add('<a1@x>');
        $fingerprint->add(' "<a1@x>" ');

        $this->assertSame($original, $fingerprint->value());
    }

    #[Test]
    public function overlapping_unequal_sets_and_case_distinct_ids_remain_independent(): void
    {
        $original = NzbArticleFingerprint::fromMessageIds(['a1@x', 'a2@x']);

        $this->assertNotSame($original, NzbArticleFingerprint::fromMessageIds(['a1@x', 'b2@x']));
        $this->assertNotSame($original, NzbArticleFingerprint::fromMessageIds(['a1@x']));
        $this->assertNotSame($original, NzbArticleFingerprint::fromMessageIds(['A1@x', 'a2@x']));
    }

    #[Test]
    public function high_byte_message_ids_preserve_literal_byte_identity(): void
    {
        $this->assertSame('é@example.test', NzbArticleFingerprint::validatedMessageId('<é@example.test>'));
        $this->assertNotSame(NzbArticleFingerprint::fromMessageIds(['é@example.test']), NzbArticleFingerprint::fromMessageIds(['É@example.test']));
        $this->assertNotSame(NzbArticleFingerprint::fromMessageIds(['é@example.test']), NzbArticleFingerprint::fromMessageIds(['e@example.test']));
    }

    #[Test]
    public function unique_input_retains_the_existing_persisted_digest(): void
    {
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', NzbArticleFingerprint::fromMessageIds(['a1@x', 'a2@x']));
        $fingerprint = new NzbArticleFingerprint;
        $fingerprint->add('a1@x');
        $this->assertSame('764304775c571be7575ff3978f5ca595beeeb322', $fingerprint->value());
        $fingerprint->add('<a1@x>');
        $fingerprint->add('a2@x');
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', $fingerprint->value());
    }

    #[Test]
    public function repeated_ids_across_sorted_spills_preserve_the_unique_input_digest(): void
    {
        $fingerprint = new NzbArticleFingerprint;
        for ($id = 9999; $id >= 0; $id--) {
            $fingerprint->add('article'.$id.'@example.test');
        }
        for ($id = 0; $id < 10000; $id++) {
            $fingerprint->add('<article'.$id.'@example.test>');
        }

        $this->assertSame('6680c8aaf57d9432a22b1abf62ee41c12e691fec', $fingerprint->value());
        $this->assertSame('6680c8aaf57d9432a22b1abf62ee41c12e691fec', $fingerprint->value());
        $fingerprint->add('article10000@example.test');
        $this->assertNotSame('6680c8aaf57d9432a22b1abf62ee41c12e691fec', $fingerprint->value());
    }

    #[Test]
    public function unsupported_ids_invalidate_all_partial_identity(): void
    {
        foreach (['', '<>', 'bad id@example.test', "bad\0id@example.test", str_repeat('x', 256)] as $invalid) {
            $fingerprint = new NzbArticleFingerprint;
            $fingerprint->add('valid@example.test');
            $this->assertNotNull($fingerprint->value());
            $fingerprint->add($invalid);
            $fingerprint->add('later@example.test');
            $this->assertNull($fingerprint->value());
        }
        $this->assertNotNull(NzbArticleFingerprint::fromMessageIds([str_repeat('x', 255)]));
    }

    #[Test]
    public function temporary_stream_creation_failure_never_returns_partial_identity(): void
    {
        $streams = [];
        $factory = static function () use (&$streams) {
            if (count($streams) === 2) {
                return false;
            }

            return $streams[] = tmpfile();
        };
        $fingerprint = new NzbArticleFingerprint($factory);
        for ($id = 0; $id < 8192; $id++) {
            $fingerprint->add('article'.$id.'@example.test');
        }

        $this->assertNull($fingerprint->value());
        $this->assertCount(2, $streams);
        foreach ($streams as $stream) {
            $this->assertFalse(is_resource($stream));
        }
    }

    #[Test]
    public function final_merge_failure_closes_multiple_pending_run_levels(): void
    {
        $streams = [];
        $fingerprint = new NzbArticleFingerprint(static function () use (&$streams) {
            if (count($streams) === 4) {
                return false;
            }

            return $streams[] = tmpfile();
        });
        for ($id = 0; $id < 12288; $id++) {
            $fingerprint->add('article'.$id.'@example.test');
        }

        $this->assertNull($fingerprint->value());
        $this->assertCount(4, $streams);
        foreach ($streams as $stream) {
            $this->assertFalse(is_resource($stream));
        }
    }

    #[Test]
    public function unreadable_temporary_run_returns_no_fingerprint(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'article-writeonly-');
        $stream = fopen($path, 'wb');
        try {
            $fingerprint = new NzbArticleFingerprint(static fn () => $stream);
            $fingerprint->add('valid@example.test');
            $this->assertNull($fingerprint->value());
            $this->assertFalse(is_resource($stream));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function unseekable_temporary_run_returns_no_fingerprint(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        try {
            $fingerprint = new NzbArticleFingerprint(static fn () => $sockets[0]);
            $fingerprint->add('valid@example.test');
            $this->assertNull($fingerprint->value());
            $this->assertFalse(is_resource($sockets[0]));
        } finally {
            fclose($sockets[1]);
        }
    }

    #[Test]
    public function failed_temporary_write_returns_no_fingerprint_and_closes_the_stream(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'article-readonly-');
        $stream = fopen($path, 'rb');
        try {
            $fingerprint = new NzbArticleFingerprint(static fn () => $stream);
            $fingerprint->add('valid@example.test');
            $this->assertNull($fingerprint->value());
            $this->assertFalse(is_resource($stream));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function completed_fingerprints_remove_their_temporary_files_when_released(): void
    {
        $paths = [];
        $fingerprint = new NzbArticleFingerprint(static function () use (&$paths) {
            $stream = tmpfile();
            $paths[] = stream_get_meta_data($stream)['uri'];

            return $stream;
        });
        $fingerprint->add('valid@example.test');
        $this->assertNotNull($fingerprint->value());
        $this->assertCount(1, $paths);
        $this->assertFileExists($paths[0]);

        unset($fingerprint);

        $this->assertFileDoesNotExist($paths[0]);
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
