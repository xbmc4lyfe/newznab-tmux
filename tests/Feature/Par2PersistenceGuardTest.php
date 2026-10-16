<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Facades\Search;
use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Support\Par2RenameProgress;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleXMLElement;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class Par2PersistenceGuardTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const TITLE = 'Valid.Scene.Release.2026-GROUP';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux.echocli' => false, 'tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $this->createPar2RenameSchema();
        Schema::table('par_hashes', static function (Blueprint $table): void {
            $table->unique(['releases_id', 'hash']);
        });
        Schema::table('release_files', static function (Blueprint $table): void {
            $table->integer('size')->nullable();
            $table->integer('passworded')->nullable();
            $table->string('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    #[DataProvider('parsePhases')]
    public function test_claim_loss_during_parsing_preserves_pending_release_and_checkpoint(string $phase, bool $collect): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        $par2 = $this->createMock(Par2Info::class);
        $par2->expects($this->once())->method('setData')->willReturnCallback(static function () use ($phase, &$allowed): bool {
            if ($phase === 'setData') {
                $allowed = false;
            }

            return true;
        });
        $par2->method('getFileList')->willReturnCallback(static function () use ($phase, &$allowed): array {
            if ($phase === 'fileList') {
                $allowed = false;
            }

            return [['name' => self::TITLE, 'size' => 1024]];
        });
        $processor = new Par2Processor(new NameFixingService, $par2, $collect, false);
        Search::shouldReceive('updateRelease')->never();

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertFalse($allowed);
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 0);
    }

    /** @return array<string, array{string, bool}> */
    public static function parsePhases(): array
    {
        return ['packet parse' => ['setData', false], 'file-list parse' => ['fileList', false],
            'packet parse with collection' => ['setData', true], 'file-list parse with collection' => ['fileList', true]];
    }

    public function test_claim_loss_between_descriptors_stops_later_metadata_and_attachment(): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        $par2 = $this->parser([['name' => 'abcdef1234567890', 'size' => 1024], ['name' => self::TITLE, 'size' => 2048]]);
        DB::listen(static function (QueryExecuted $event) use (&$allowed): void {
            if (str_starts_with(strtolower($event->sql), 'insert or ignore into "release_files"')) {
                $allowed = false;
            }
        });
        Search::shouldReceive('updateRelease')->never();
        $processor = new Par2Processor(new NameFixingService, $par2, true, false);

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 0);
        $this->assertSame(0, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_claim_loss_during_name_check_prevents_later_file_count_write(): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        $names = $this->createMock(NameFixingService::class);
        $names->expects($this->once())->method('reset');
        $names->expects($this->once())->method('checkName')->willReturnCallback(static function () use (&$allowed): bool {
            $allowed = false;

            return false;
        });
        $processor = new Par2Processor($names, $this->parser([['name' => 'abcdef1234567890']]), false, false);

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertSame(0, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_multiple_permission_checks_count_each_received_article_once(): void
    {
        $release = $this->pendingRelease();
        $checks = 0;
        $processor = $this->getMockBuilder(Par2Processor::class)->disableOriginalConstructor()->onlyMethods(['applyInventoryForRename'])->getMock();
        $processor->expects($this->exactly(2))->method('applyInventoryForRename')->willReturnCallback(static function (Par2PacketAccumulator $inventory, int $releaseId, int $groupId, int $show, ?callable $canPersist) use (&$checks): Par2RenameOutcome {
            for ($i = 0; $i < 3; $i++) {
                $canPersist();
                $checks++;
            }

            return Par2RenameOutcome::NoMatch;
        });
        $post = new PostProcessService(par2Processor: $processor);
        $service = $this->service($post, ['one', 'two']);

        $outcome = $service->checkPar2Result($release->guid, $release->id, 1, 1, 0);

        $this->assertSame(Par2RenameOutcome::NoMatch, $outcome);
        $this->assertSame(6, $checks);
        $this->assertSame(['candidates_probed' => 2, 'articles_received' => 2], $service->par2Stats());
    }

    public function test_valid_claim_keeps_exact_attachment_and_counts_one_received_article(): void
    {
        $release = $this->pendingRelease();
        $names = new NameFixingService;
        $processor = new Par2Processor($names, new Par2Info, false, false);
        $post = new PostProcessService(nameFixingService: $names, par2Processor: $processor);
        $service = $this->service($post, ['one']);

        $outcome = $service->checkPar2Result($release->guid, $release->id, 1, 1, 0);

        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertSame(['candidates_probed' => 1, 'articles_received' => 1], $service->par2Stats());
        $this->assertSame(7, (int) $release->fresh()->predb_id);
        $this->assertNull(Par2RenameProgress::get($release->id));
    }

    public function test_retry_after_claim_loss_counts_every_persisted_descriptor_once(): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        $loseOnce = true;
        $files = [['name' => 'abcdef1234567890', 'size' => 1024], ['name' => self::TITLE, 'size' => 2048]];
        DB::listen(static function (QueryExecuted $event) use (&$allowed, &$loseOnce): void {
            if ($loseOnce && str_starts_with(strtolower($event->sql), 'insert or ignore into "release_files"')) {
                $allowed = false;
                $loseOnce = false;
            }
        });
        Search::shouldReceive('updateRelease')->twice()->with($release->id);
        $claim = static function () use (&$allowed): bool {
            return $allowed;
        };
        $first = new Par2Processor(new NameFixingService, $this->parser($files), true, false);

        $this->assertSame(Par2RenameOutcome::Deferred, $first->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, $claim));
        $this->assertDatabaseCount('release_files', 0);
        $this->assertSame(0, (int) $release->fresh()->rarinnerfilecount);
        $allowed = true;
        $retry = new Par2Processor(new NameFixingService, $this->parser($files), true, false);

        $outcome = $retry->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, $claim);

        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertDatabaseCount('release_files', 2);
        $this->assertSame(7, (int) $release->fresh()->predb_id);
        $this->assertSame(2, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_later_claim_loss_preserves_earlier_committed_file_and_its_count(): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        $firstName = 'abcdef1234567890';
        $secondName = 'fedcba0987654321';
        $files = [['name' => $firstName, 'size' => 1024], ['name' => $secondName, 'size' => 2048], ['name' => self::TITLE, 'size' => 4096]];
        DB::listen(static function (QueryExecuted $event) use ($secondName, &$allowed): void {
            if (str_starts_with(strtolower($event->sql), 'insert or ignore into "release_files"') && in_array($secondName, $event->bindings, true)) {
                $allowed = false;
            }
        });
        Search::shouldReceive('updateRelease')->once()->with($release->id);
        $processor = new Par2Processor(new NameFixingService, $this->parser($files), true, false);

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 1);
        $this->assertDatabaseHas('release_files', ['releases_id' => $release->id, 'name' => $firstName]);
        $this->assertSame(1, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_existing_file_hash_enrichment_does_not_add_another_file_count(): void
    {
        $release = $this->pendingRelease();
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => self::TITLE, 'size' => 1024]);
        DB::table('releases')->where('id', $release->id)->update(['rarinnerfilecount' => 1]);
        $hash = '1234567890abcdef1234567890abcdef';
        $processor = new Par2Processor(new NameFixingService, $this->parser([['name' => self::TITLE, 'size' => 1024, 'hash_16K' => $hash]]), true, false);
        Search::shouldReceive('updateRelease')->never();

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static fn (): bool => true);

        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertDatabaseCount('release_files', 1);
        $this->assertDatabaseHas('par_hashes', ['releases_id' => $release->id, 'hash' => $hash]);
        $this->assertSame(1, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_claim_loss_during_duplicate_enrichment_rolls_back_only_the_new_hash(): void
    {
        $release = $this->pendingRelease();
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => self::TITLE, 'size' => 1024]);
        DB::table('releases')->where('id', $release->id)->update(['rarinnerfilecount' => 1]);
        $allowed = true;
        DB::listen(static function (QueryExecuted $event) use (&$allowed): void {
            if (str_starts_with(strtolower($event->sql), 'insert or ignore into "par_hashes"')) {
                $allowed = false;
            }
        });
        Search::shouldReceive('updateRelease')->never();
        $processor = new Par2Processor(new NameFixingService, $this->parser([['name' => self::TITLE, 'hash_16K' => '1234567890abcdef1234567890abcdef']]), true, false);

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 1);
        $this->assertDatabaseCount('par_hashes', 0);
        $this->assertSame(1, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_claim_loss_after_count_update_rolls_back_before_commit_and_search_sync(): void
    {
        $release = $this->pendingRelease();
        $allowed = true;
        DB::listen(static function (QueryExecuted $event) use (&$allowed): void {
            if (str_starts_with(strtolower($event->sql), 'update "releases"') && str_contains($event->sql, 'rarinnerfilecount')) {
                $allowed = false;
            }
        });
        Search::shouldReceive('updateRelease')->never();
        $processor = new Par2Processor(new NameFixingService, $this->parser([['name' => self::TITLE, 'size' => 1024]]), true, false);

        $outcome = $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 0);
        $this->assertSame(0, (int) $release->fresh()->rarinnerfilecount);
    }

    public function test_unrelated_database_error_rolls_back_the_file_unit_and_is_not_deferred(): void
    {
        $release = $this->pendingRelease();
        DB::unprepared("CREATE TRIGGER fail_file_count BEFORE UPDATE OF rarinnerfilecount ON releases BEGIN SELECT RAISE(ABORT, 'forced count failure'); END");
        Search::shouldReceive('updateRelease')->never();
        $processor = new Par2Processor(new NameFixingService, $this->parser([['name' => self::TITLE, 'size' => 1024]]), true, false);

        try {
            $processor->parseForRename('synthetic@example', $release->id, 1, $this->transport(), 0, static fn (): bool => true);
            $this->fail('The database error must propagate.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('forced count failure', $error->getMessage());
        }

        $this->assertPending($release);
        $this->assertDatabaseCount('release_files', 0);
        $this->assertSame(0, (int) $release->fresh()->rarinnerfilecount);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function pendingRelease(): Release
    {
        $release = $this->pendingPar2Release(['name' => self::TITLE, 'searchname' => self::TITLE, 'postdate' => '2026-10-11 00:00:00']);
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $this->assertTrue(Par2RenameProgress::save($release->id, 'checkpoint', 1, [0]));

        return $release;
    }

    private function assertPending(Release $release): void
    {
        $this->assertSame(0, (int) $release->fresh()->predb_id);
        $this->assertSame(0, (int) $release->fresh()->proc_par2);
        $this->assertSame(0, (int) $release->fresh()->isrenamed);
        $this->assertNotNull(Par2RenameProgress::get($release->id));
    }

    /** @param list<array{name: string, size?: int, hash_16K?: string}> $files */
    private function parser(array $files): Par2Info
    {
        $parser = $this->createMock(Par2Info::class);
        $parser->expects($this->once())->method('setData')->willReturn(true);
        $parser->expects($this->once())->method('getFileList')->willReturn($files);

        return $parser;
    }

    private function transport(): NNTPService
    {
        $nntp = $this->getMockBuilder(NNTPService::class)->disableOriginalConstructor()->onlyMethods(['getMessages'])->getMock();
        $nntp->expects($this->once())->method('getMessages')->willReturn('synthetic-par2-body');

        return $nntp;
    }

    private function descriptorBody(): string
    {
        $length = pack('V2', 1024, 0);
        $hash16 = md5('first bytes', true);
        $id = md5($hash16.$length.self::TITLE, true);
        $body = $id.md5('whole file', true).$hash16.$length.self::TITLE;
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = md5('fixture set', true).Par2Info::PACKET_FILEDESC.$body;

        return Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
    }

    /** @param list<string> $ids */
    private function service(PostProcessService $post, array $ids): NzbContentsService
    {
        $xml = new SimpleXMLElement('<nzb/>');
        foreach ($ids as $id) {
            $file = $xml->addChild('file');
            $file->addAttribute('subject', '"release.par2" yEnc (1/1)');
            $segment = $file->addChild('segments')->addChild('segment', $id);
            $segment->addAttribute('number', '1');
            $segment->addAttribute('bytes', '1000');
        }
        $nntp = $this->createStub(NNTPService::class);
        $nntp->method('getMessageBounded')->willReturn(BoundedArticleResult::success($this->descriptorBody()));

        return new class($xml, $nntp, $post) extends NzbContentsService
        {
            public function __construct(private SimpleXMLElement $xml, NNTPService $nntp, PostProcessService $post)
            {
                $this->nntp = $nntp;
                $this->postProcessService = $post;
                $this->parserService = new NzbParserService;
            }

            public function loadNzb(string $guid): SimpleXMLElement
            {
                return $this->xml;
            }
        };
    }
}
