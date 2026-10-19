<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\YencService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReleaseVerificationAuditDeletionTest extends TestCase
{
    private string $directory;

    private string $nzbPath;

    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/audit-deletion-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'release_verification.storage_path' => $this->directory.'/verification',
            'release_verification.scratch_bytes' => 2147483648, 'release_verification.free_bytes' => 0,
            'release_verification.bytes_per_second' => 0]);
        DB::purge();
        (require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php'))->up();
        (require database_path('migrations/2026_10_17_220000_add_header_check_pending_to_release_verification_candidates.php'))->up();
        (require database_path('migrations/2026_10_11_175742_create_release_verification_audit_retries_table.php'))->up();
        (require database_path('migrations/2026_10_12_004819_create_release_verification_scratch_table.php'))->up();
        (require database_path('migrations/2026_10_12_053000_add_shared_verification_scratch_budget.php'))->up();
        (require database_path('migrations/2026_10_17_200000_add_host_to_release_verification_scratch.php'))->up();
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->string('searchname');
            $table->string('fromname');
            $table->integer('groups_id');
            $table->string('postdate');
        });
        Schema::create('release_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_id')->constrained('releases')->cascadeOnDelete();
        });
        (require database_path('migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'audit-delete', 'searchname' => 'Movie',
            'fromname' => 'poster', 'groups_id' => 1, 'postdate' => '2026-10-11 12:00:00']);
        DB::table('release_children')->insert(['release_id' => 1]);
        $nzb = new class($this->directory) extends NzbService
        {
            public function __construct(string $directory)
            {
                $this->nzbSplitLevel = 1;
                $this->siteNzbPath = $directory.'/nzbs/';
                $this->siteNzbPaths = [$this->siteNzbPath];
            }
        };
        $this->app->instance(NzbService::class, $nzb);
        $this->nzbPath = $nzb->getNzbPath('audit-delete', createIfNotExist: true);
        $images = new ReleaseImageService;
        $images->audSavePath = $images->vidSavePath = $images->imgSavePath = $images->jpgSavePath = $this->directory.'/images/';
        mkdir($images->imgSavePath);
        $this->imagePath = $images->imgSavePath.'audit-delete_thumb.jpg';
        file_put_contents($this->imagePath, 'thumbnail');
        $this->app->instance(ReleaseImageService::class, $images);
        Search::shouldReceive('deleteRelease')->with(1)->andReturnNull();
        $parity = 'bounded metadata fixture';
        $xml = '<nzb><file subject="Release [1/1] &quot;set.par2&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="'.strlen($parity).'" number="1">parity@article</segment></segments></file></nzb>';
        file_put_contents($this->nzbPath, gzencode($xml));
        $this->mock(NNTPService::class, static function ($mock) use ($parity): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturnTrue();
            $mock->shouldReceive('verificationArticle')->with('parity@article', 1048576)->andReturn((new YencService)->encode($parity, 'set.par2'));
            $mock->shouldReceive('doQuit')->andReturnNull();
        });
    }

    protected function tearDown(): void
    {
        File::swap(new Filesystem);
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function databaseFailurePoints(): iterable
    {
        yield 'release delete' => ['BEFORE DELETE ON releases'];
        yield 'cursor update after release delete' => ['BEFORE UPDATE ON release_verification_state'];
    }

    #[DataProvider('databaseFailurePoints')]
    public function test_database_delete_failure_preserves_release_children_nzb_and_proven_audit_for_retry(string $failurePoint): void
    {
        DB::unprepared("CREATE TRIGGER fail_audit_delete {$failurePoint} BEGIN SELECT RAISE(ABORT, 'fixture delete failure'); END");
        try {
            $this->audit();
        } catch (QueryException $error) {
            $this->assertStringContainsString('fixture delete failure', $error->getMessage());
        }
        $this->assertFileExists($this->nzbPath);
        $this->assertFileExists($this->imagePath);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_children')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $entry = DB::table('release_verification_scratch')->sole();
        $this->assertSame('unrepairable', $entry->audit_verdict);
        $this->assertFalse((bool) $entry->terminal);
        $this->assertNull($entry->delete_guid);
        DB::unprepared('DROP TRIGGER fail_audit_delete');
        $this->audit();
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('release_children')->count());
        $this->assertFileExists($this->nzbPath);
        $this->worker();
        $this->assertFileDoesNotExist($this->nzbPath);
        $this->assertFileDoesNotExist($this->imagePath);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
    }

    public function test_committed_deletion_survives_interruption_before_report_and_worker_recovers_all_artifacts(): void
    {
        DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'delete from "releases"')) {
                DB::afterCommit(static function (): void {
                    throw new \RuntimeException('simulated interruption after database commit');
                });
            }
        });
        try {
            $this->audit();
        } catch (\RuntimeException $error) {
            $this->assertSame('simulated interruption after database commit', $error->getMessage());
        }
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('release_children')->count());
        $entry = DB::table('release_verification_scratch')->sole();
        $this->assertTrue((bool) $entry->terminal);
        $this->assertSame('audit-delete', $entry->delete_guid);
        $this->assertSame('unrepairable', $entry->audit_verdict);
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertFileExists($this->nzbPath);
        $this->assertFileDoesNotExist($this->directory.'/verification/audit.jsonl');
        $scratch = $this->scratch((int) $entry->id);
        $this->assertDirectoryExists($scratch);
        $this->worker();
        $this->assertFileDoesNotExist($this->nzbPath);
        $this->assertFileDoesNotExist($this->imagePath);
        $this->assertDirectoryDoesNotExist($scratch);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function artifactFailurePoints(): iterable
    {
        yield 'NZB removal' => [false];
        yield 'thumbnail removal after NZB is removed' => [true];
    }

    public function test_committed_audit_anchor_cleanup_releases_its_retained_scratch_capacity(): void
    {
        $this->audit();
        $entry = DB::table('release_verification_scratch')->sole();
        $inventory = json_decode($entry->delete_paths, true, flags: JSON_THROW_ON_ERROR);
        $anchor = $inventory['nzb_receipt']['anchor'];
        $scratch = $this->scratch((int) $entry->id);
        $this->assertSame(1, $inventory['version']);
        $this->assertSame('inode', $inventory['nzb_receipt']['kind']);
        $this->assertFileExists($this->nzbPath);
        $this->assertFileExists($anchor);
        $this->assertDirectoryExists($scratch);
        $this->assertTrue(app(ScratchBudget::class)->initialize());
        $reserved = (int) DB::table('release_verification_scratch_budget')->value('reserved_bytes');
        $this->assertGreaterThan(0, $reserved);
        $this->assertSame($reserved, (int) DB::table('release_verification_scratch')->value('reserved_bytes'));
        config(['release_verification.scratch_bytes' => $reserved]);

        $this->worker();

        $this->assertFileDoesNotExist($this->nzbPath);
        $this->assertFileDoesNotExist($anchor);
        $this->assertFileDoesNotExist($this->imagePath);
        $this->assertDirectoryDoesNotExist($scratch);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
        $this->assertSame(0, (int) DB::table('release_verification_scratch_budget')->value('reserved_bytes'));
    }

    #[DataProvider('artifactFailurePoints')]
    public function test_failed_artifact_removal_retains_durable_work_even_when_scratch_is_absent(bool $failImage): void
    {
        $this->audit();
        $entry = DB::table('release_verification_scratch')->sole();
        (new Filesystem)->deleteDirectory($this->scratch((int) $entry->id));
        $failedPath = $failImage ? $this->imagePath : $this->nzbPath;
        $parent = dirname($failedPath);
        if ($failImage) {
            $filesystem = \Mockery::mock(Filesystem::class)->makePartial();
            $filesystem->shouldReceive('delete')->with($failedPath)->once()->andReturnFalse();
            File::swap($filesystem);
        } else {
            $this->assertTrue(chmod($parent, 0500));
            clearstatcache(true, $parent);
            $this->assertFalse(is_writable($parent));
        }
        try {
            $this->worker();
            $this->assertFileExists($failedPath);
            $this->assertSame(1, DB::table('release_verification_scratch')->count());
        } finally {
            if (! $failImage) {
                chmod($parent, 0700);
            }
            File::swap(new Filesystem);
        }
        $this->worker();
        $this->assertFileDoesNotExist($this->nzbPath);
        $this->assertFileDoesNotExist($this->imagePath);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
    }

    public function test_spot_check_with_delete_option_keeps_release_and_artifacts(): void
    {
        $this->assertSame(0, Artisan::call('releases:verify-repairable', ['--delete' => true, '--dry-run' => true]));
        $record = json_decode(trim(file_get_contents($this->directory.'/verification/audit.jsonl')), true);
        $this->assertSame('sampled', $record['verdict']);
        $this->worker();
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_children')->count());
        $this->assertFileExists($this->nzbPath);
        $this->assertFileExists($this->imagePath);
    }

    public function test_a_partial_upgraded_after_its_audit_is_never_deleted_on_the_stale_verdict(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('articles_found')->nullable();
            $table->unsignedInteger('late_article_candidate_id')->nullable();
        });
        DB::table('releases')->where('id', 1)->update(['articles_found' => 1]);
        $audited = Release::query()->findOrFail(1);
        $inventory = app(ScratchInventory::class);
        $entry = $inventory->registerAudit(1);
        $inventory->recordAudit((int) $entry->id, 'unrepairable', 'Partial release NZB is truncated.');
        // Late articles upgrade it while the audit does its NNTP work.
        DB::table('releases')->where('id', 1)->update(['articles_found' => 3]);

        try {
            DB::transaction(fn () => app(AuditReleaseDeletion::class)->commit($audited, (int) $entry->id));
            $this->fail('A changed generation must not be deleted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audited release changed before deletion.', $error->getMessage());
        }

        $this->assertSame(1, DB::table('releases')->count());
        $this->assertFileExists($this->nzbPath);
    }

    private function audit(): void
    {
        // Exercise cleanup of a previously committed full-audit verdict without a new full download.
        $inventory = app(ScratchInventory::class);
        $entry = $inventory->registerAudit(1);
        $scratch = $this->scratch((int) $entry->id);
        File::ensureDirectoryExists($scratch);
        file_put_contents($scratch.'/legacy-evidence', 'retained proof from the prior audit');
        $inventory->recordAudit((int) $entry->id, 'unrepairable', 'Previously proved audit result.');
        DB::transaction(function () use ($entry, $inventory): void {
            DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
            app(AuditReleaseDeletion::class)->commit(Release::query()->findOrFail(1), (int) $entry->id);
            $inventory->finishAudit((int) $entry->id);
            DB::table('release_verification_state')->where('id', 1)->update(['audit_cursor' => 1]);
        });
    }

    private function worker(): void
    {
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
    }

    private function scratch(int $entryId): string
    {
        return $this->directory.'/verification/scratch/audit-1-'.$entryId;
    }
}
