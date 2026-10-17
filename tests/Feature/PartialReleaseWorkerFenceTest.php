<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NfoService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Workers that read a release's NZB must skip a partial whose NZB late articles may still replace. */
final class PartialReleaseWorkerFenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
            $table->string('guid')->default('fixture');
            $table->unsignedInteger('articles_expected')->nullable();
        });
    }

    public function test_par2_and_standard_name_fixing_select_only_settled_releases(): void
    {
        $sql = [];
        $database = $this->createStub(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(static function (string $query) use (&$sql): array {
            $sql[] = $query;

            return [];
        });
        $service = new NameFixingQueryService($database);

        $service->candidateBatch(NameFixingQueryService::SOURCE_PAR2, 2, 3, 0, 100);
        $service->standardCandidateBatch('a', 100);
        $service->candidateBatch(NameFixingQueryService::SOURCE_FILES, 2, 3, 0, 100);

        $this->assertStringContainsString('r.late_article_candidate_id IS NULL', $sql[0]);
        $this->assertStringContainsString('r.late_article_candidate_id IS NULL', $sql[1]);
        $this->assertStringNotContainsString('late_article_candidate_id', $sql[2], 'File names come from release_files, not the NZB.');
        $this->assertMatchesRegularExpression('/proc_par2 = 0 AND \(r\.nzbstatus = 1\) AND r\.late_article_candidate_id IS NULL THEN 1/',
            NameFixingQueryService::pendingWorkCondition(), 'Held partials are not counted as pending PAR2 work.');
    }

    public function test_nfo_selection_is_settled_only(): void
    {
        $this->assertStringContainsString('r.late_article_candidate_id IS NULL', NfoService::NfoQueryString());

        $query = (new \ReflectionMethod(NfoService::class, 'buildNfoProcessingQuery'))
            ->invoke((new \ReflectionClass(NfoService::class))->newInstanceWithoutConstructor(), '', '');
        $this->assertStringContainsString('"releases"."late_article_candidate_id" is null', $query->toSql());
    }

    public function test_a_fully_read_partial_nzb_never_proves_a_complete_naming_scope(): void
    {
        DB::table('releases')->insert([['id' => 1, 'articles_expected' => null], ['id' => 2, 'articles_expected' => 934]]);
        $authoritative = function (int $id): bool {
            $context = new ReleaseProcessingContext(Release::query()->findOrFail($id));
            $context->archiveNamingInspectionComplete = true;

            return ReleaseFileManager::archiveNamingIsAuthoritative($context);
        };

        $this->assertTrue($authoritative(1));
        $this->assertFalse($authoritative(2), 'One episode of a partial season pack is not a single-episode release.');
    }
}
