<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SceneArchiveSqlSourceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'scene-sql-input-');
        copy(dirname(__DIR__, 2).'/Fixtures/predb/scene-archive-sql-subset.sql', $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_factual_literal_titles_and_music_are_preserved_without_inferred_metadata(): void
    {
        $source = new SceneArchiveSqlSource($this->path);
        self::assertInstanceOf(CompleteCatalogueSource::class, $source);
        self::assertSame('scene_archive_sql', $source->key());
        $entries = $source->fetch();
        self::assertSame([
            'ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA',
            'Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB',
            'Alcatraz-Give_Me_Love-(CATRAZ001)-Bootleg-Vinyl-2007-MPX',
            'Zards.Software.Cleanse.Uninstaller.v3.0.1.0-HERiTAGE',
            'Schach.Und.Matt.2-SiLENTGATE',
            'Harry.Potter.en.de.Orde.van.de.Feniks.DUTCH-VLiSO',
        ], array_column($entries, 'title'));
        self::assertSame(6, $source->acceptedCount());
        self::assertSame([], $source->rejectionCounts());
        foreach ($entries as $entry) {
            self::assertSame('scene-archive-sql', $entry->source);
            self::assertNull($entry->predate);
            self::assertNull($entry->listedAt);
            self::assertNull($entry->category);
            self::assertNull($entry->size);
            self::assertNull($entry->files);
            self::assertNull($entry->nukeReason);
        }
    }

    public function test_pages_repeats_and_nonsequential_calls_are_stable(): void
    {
        $source = new SceneArchiveSqlSource($this->path, pageSize: 2);
        $first = $source->fetch();
        $second = $source->fetch(2);
        $third = $source->fetch(3);
        self::assertEquals($first, $source->fetch(1));
        self::assertEquals($second, $source->fetch(2));
        self::assertEquals($third, $source->fetch(3));
        self::assertSame([], $source->fetch(4));
        self::assertSame([], $source->fetch(PHP_INT_MAX));
        self::assertSame(6, $source->acceptedCount());
    }

    public function test_unique_out_of_order_ids_preserve_published_row_order(): void
    {
        file_put_contents($this->path, str_replace("2694710,'HDTV'", "2699999,'HDTV'", file_get_contents($this->path)));
        $source = new SceneArchiveSqlSource($this->path);
        self::assertCount(6, $source->fetch());
        self::assertSame('ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', $source->fetch()[0]->title);
    }

    public function test_observed_original_unlock_only_ending_is_complete(): void
    {
        file_put_contents($this->path, str_replace('/*!40000 ALTER TABLE `predb` ENABLE KEYS */;', '', file_get_contents($this->path)));
        $source = new SceneArchiveSqlSource($this->path);
        self::assertCount(6, $source->fetch());
        self::assertSame(6, $source->acceptedCount());
    }

    public function test_later_malformed_statement_fails_before_first_page_and_does_not_leak_counts(): void
    {
        file_put_contents($this->path, str_replace('UNLOCK TABLES;', 'INSERT INTO `predb` VALUES (999);\nUNLOCK TABLES;', file_get_contents($this->path)));
        $source = new SceneArchiveSqlSource($this->path, pageSize: 1);
        $this->expectException(RuntimeException::class);
        $source->fetch();
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_sql_or_schema_is_never_executed_or_partially_accepted(string $from, string $to): void
    {
        file_put_contents($this->path, str_replace($from, $to, file_get_contents($this->path)));
        $this->expectException(RuntimeException::class);
        (new SceneArchiveSqlSource($this->path))->fetch();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidChanges(): array
    {
        return [
            'unsupported encoding' => ['CHARSET=latin1', 'CHARSET=utf8mb4'],
            'column reordered' => ['`rlsname` varchar(255)', '`other` varchar(255)'],
            'extra column' => ['`grp` varchar(50)', '`extra` varchar(50) NOT NULL, `grp` varchar(50)'],
            'unexpected command' => ['UNLOCK TABLES;', 'DROP TABLE `predb`; UNLOCK TABLES;'],
            'partial final insert' => ['UNLOCK TABLES;', 'INSERT INTO `predb` VALUES (123,'],
            'missing completion framing' => ['UNLOCK TABLES;', ''],
            'wrong table' => ['INSERT INTO `predb`', 'INSERT INTO `users`'],
            'wrong arity' => ["2694710,'HDTV'", "2694710,'HDTV','extra'"],
            'invalid identifier' => ["2694710,'HDTV'", "'id','HDTV'"],
            'duplicate ID' => ["2694713,'XVID'", "2694710,'XVID'"],
            'nested SQL function' => ["2694710,'HDTV'", "NOW(),'HDTV'"],
            'unterminated string' => ["'ER.S12E13", "'ER.S12E13\\"],
            'unrecognized escape' => ['ER.S12E13', 'ER.\\qS12E13'],
            'duplicate title across statements' => ['Schach.Und.Matt.2-SiLENTGATE', 'ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA'],
        ];
    }

    public function test_file_and_statement_limits_apply_before_any_page(): void
    {
        $caught = 0;
        foreach ([['maxFileBytes' => 100], ['maxStatementBytes' => 100], ['maxScratchBytes' => 100], ['maxFrameIndexBytes' => 45]] as $limits) {
            try {
                (new SceneArchiveSqlSource($this->path, ...$limits))->fetch();
                self::fail('Expected bound rejection.');
            } catch (RuntimeException) {
                $caught++;
            }
        }
        self::assertSame(4, $caught);
    }

    public function test_noncanonical_ascii_or_conflicting_titles_are_counted_without_aliases(): void
    {
        $sql = str_replace('Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB', 'Mean Streets-GTB', file_get_contents($this->path));
        $sql = str_replace('Schach.Und.Matt.2-SiLENTGATE', 'Schach.Und.Matt.2-OTHER', $sql);
        file_put_contents($this->path, $sql);
        $source = new SceneArchiveSqlSource($this->path);
        self::assertCount(4, $source->fetch());
        self::assertSame(4, $source->acceptedCount());
        self::assertSame(['noncanonical_title' => 1, 'group_conflict' => 1], $source->rejectionCounts());
    }

    public function test_semicolon_and_apostrophe_in_literal_string_do_not_split_statements(): void
    {
        $sql = str_replace('ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA', "ER.King\\'s.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA", file_get_contents($this->path));
        $sql = preg_replace("/'0',NULL,'1183557532'/", "'0','reason; ignored','1183557532'", $sql);
        file_put_contents($this->path, $sql);
        $source = new SceneArchiveSqlSource($this->path);
        self::assertSame("ER.King's.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA", $source->fetch()[0]->title);
        self::assertSame(6, $source->acceptedCount());
    }

    public function test_replaced_input_and_inplace_statement_change_are_rejected(): void
    {
        $source = new SceneArchiveSqlSource($this->path, pageSize: 1);
        $source->fetch();
        $mtime = filemtime($this->path);
        file_put_contents($this->path, str_replace('ER.S12E13', 'ER.S12E14', file_get_contents($this->path)));
        touch($this->path, $mtime);
        $this->expectException(RuntimeException::class);
        $source->fetch(2);
    }

    public function test_replaced_input_is_rejected_even_while_original_descriptor_is_open(): void
    {
        $source = new SceneArchiveSqlSource($this->path, pageSize: 1);
        $source->fetch();
        $replacement = tempnam(sys_get_temp_dir(), 'scene-sql-replacement-');
        file_put_contents($replacement, file_get_contents($this->path));
        rename($replacement, $this->path);
        $this->expectException(RuntimeException::class);
        $source->fetch(2);
    }

    public function test_owned_scratch_is_removed_after_success_and_validation_failure(): void
    {
        $before = glob(sys_get_temp_dir().'/scene-sql-cache-*') ?: [];
        $source = new SceneArchiveSqlSource($this->path);
        $source->fetch();
        self::assertCount(1, array_diff(glob(sys_get_temp_dir().'/scene-sql-cache-*') ?: [], $before));
        unset($source);
        self::assertSame($before, glob(sys_get_temp_dir().'/scene-sql-cache-*') ?: []);
        try {
            (new SceneArchiveSqlSource($this->path, maxScratchBytes: 1))->fetch();
            self::fail('Expected scratch bound rejection.');
        } catch (RuntimeException) {
            self::assertSame($before, glob(sys_get_temp_dir().'/scene-sql-cache-*') ?: []);
        }
    }

    public function test_nonlocal_or_invalid_paths_never_open_network_or_archives(): void
    {
        $caught = 0;
        foreach (['https://example.org/data.sql', 'php://memory', '', dirname($this->path)] as $path) {
            try {
                (new SceneArchiveSqlSource($path))->fetch();
                self::fail('Expected local regular file validation.');
            } catch (RuntimeException) {
                $caught++;
            }
        }
        self::assertSame(4, $caught);
    }
}
