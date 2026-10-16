<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityProjector;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\Predb\PredbIdentitySchemaAttestation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameSqlAuthorityTest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        Schema::table('predb', static function (Blueprint $table): void {
            $table->string('filename')->default('');
        });
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
    }

    public static function callers(): array
    {
        $cases = [];
        foreach (['files', 'standard', 'prefile', 'list'] as $caller) {
            foreach ([false, true] as $alias) {
                foreach ([false, true] as $sameName) {
                    $cases[$caller.($alias ? ' alias' : ' canonical').($sameName ? ' attach' : ' rename')] = [$caller, $alias, $sameName];
                }
            }
        }

        return $cases;
    }

    private function certify(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    /** @return array{object, NameFixingService, string, string} */
    private function fixture(bool $alias, bool $sameName, ?PredbMatchSelector $selector = null, ?bool $fallback = null): array
    {
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $filename = ($alias ? 'Synthetic.Upload.Alias-GRP' : $title).'.mkv';
        $release = $this->pendingPar2Release([
            'searchname' => $sameName ? $title : 'Unresolved.Synthetic.2026-GRP',
            'leftguid' => 'a', 'predb_id' => 0, 'proc_files' => 0,
            'proc_uid' => 1, 'proc_crc32' => 1, 'proc_hash16k' => 1,
            'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        $release->releases_id = $release->id;
        DB::table('predb')->insert(['id' => 7, 'title' => $title,
            'filename' => $alias ? 'Synthetic.Upload.Alias-GRP.rar' : '']);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $extractor = $this->createMock(FileNameExtractor::class);
        if ($fallback === null) {
            $extractor->expects(self::never())->method('extractFromFile');
        } else {
            $extractor->expects(self::once())->method('extractFromFile')->willReturn($fallback
                ? NameFixResult::fromMatch('Synthetic.Fallback.Release.2026-GRP', 'fixture', 'File')
                : null);
        }
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);

        return [$release, new NameFixingService($updates, fileExtractor: $extractor, predbMatchSelector: $selector), $filename, $title];
    }

    private function runCaller(string $caller, object $release, NameFixingService $service, string $filename): void
    {
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $service->matchPreDbFileNames($release, [$filename], true, true, false);
        }
    }

    #[DataProvider('callers')]
    public function test_certified_sql_identity_is_used_without_backend_discovery(string $caller, bool $alias, bool $sameName): void
    {
        [$release, $service, $filename, $title] = $this->fixture($alias, $sameName);
        $this->certify();
        $this->runCaller($caller, $release, $service, $filename);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        if ($sameName) {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
        self::assertSame([$filename], DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
    }

    #[DataProvider('callers')]
    public function test_unready_sql_inventory_cannot_rename_attach_or_complete(string $caller, bool $alias, bool $sameName): void
    {
        [$release, $service, $filename] = $this->fixture($alias, $sameName);
        $before = $release->searchname;
        $this->runCaller($caller, $release, $service, $filename);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame($before, $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('callers')]
    public function test_catalogue_mutation_during_write_rolls_back_assignment_and_notifications(string $caller, bool $alias, bool $sameName): void
    {
        [$release, $service, $filename, $title] = $this->fixture($alias, $sameName);
        $this->certify();
        $before = $release->searchname;
        $stateBefore = (array) DB::table('predb_identity_state')->sole();
        $mutated = false;
        DB::listen(static function ($event) use (&$mutated, $alias, $title): void {
            if (! $mutated && str_starts_with(strtolower($event->sql), 'update "releases"')) {
                $mutated = true;
                DB::table('predb')->insert(['id' => 8, 'title' => $alias ? 'Synthetic.Upload.Alias-GRP' : $title, 'filename' => '']);
            }
        });
        $this->runCaller($caller, $release, $service, $filename);
        self::assertTrue($mutated, 'The intended release-write boundary was not exercised.');
        self::assertSame(0, DB::table('predb')->where('id', 8)->count(), 'The injected competing owner must roll back with assignment.');
        self::assertSame($stateBefore, (array) DB::table('predb_identity_state')->sole());
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($before, $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('callers')]
    public function test_unique_sql_evidence_still_requires_the_existing_selector(string $caller, bool $alias, bool $sameName): void
    {
        $selector = $this->createMock(PredbMatchSelector::class);
        $selector->expects(self::atLeastOnce())->method('selectBestMatch')->willReturn(null);
        [$release, $service, $filename] = $this->fixture($alias, $sameName, $selector);
        $this->certify();
        $before = $release->searchname;
        $this->runCaller($caller, $release, $service, $filename);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($before, $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_whole_pass_other_observation_changes_after_write_even_when_ready_restored(): void
    {
        [$release, $service, $filename] = $this->fixture(false, false);
        $other = 'Synthetic.Secondary.Observation-GRP.mkv';
        DB::table('predb')->where('id', 7)->update(['filename' => 'Synthetic.Secondary.Observation-GRP.rar']);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $other]);
        $this->certify();
        $before = $release->searchname;
        $mutated = false;
        $restored = false;
        $releaseWrites = 0;
        $authorityBefore = $this->authoritySnapshot();
        DB::listen(function ($event) use (&$mutated, &$restored, &$releaseWrites): void {
            if (str_starts_with(strtolower($event->sql), 'update "releases"')) {
                $releaseWrites++;
            }
            if (! $mutated && str_starts_with(strtolower($event->sql), 'update "releases"')) {
                $mutated = true;
                DB::table('predb')->insert(['id' => 8, 'title' => 'Synthetic.Secondary.Observation-GRP', 'filename' => '']);
                $this->projectInsertedOwnerAndRestoreReadyForFixture();
                $restored = (int) DB::table('predb_identity_state')->value('ready') === 1;
            }
        });
        $service->matchPreDbFileNames($release, [$filename, $other], true, true, false);
        self::assertTrue($mutated);
        self::assertTrue($restored);
        self::assertSame(1, $releaseWrites, 'Stale observations must not be retried after rollback.');
        self::assertSame($authorityBefore, $this->authoritySnapshot(), 'Source, state, revisions and keys must roll back together.');
        self::assertSame(0, $release->fresh()->predb_id, 'Whole-pass positive assignment must roll back');
        self::assertSame($before, $release->fresh()->searchname);
        self::assertSame(0, DB::table('predb')->where('id', 8)->count());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function wholePassModes(): array
    {
        return ['fallback' => [true], 'completion' => [false]];
    }

    #[DataProvider('wholePassModes')]
    public function test_whole_pass_absence_mutation_during_fallback_or_completion(bool $fallback): void
    {
        [$release, $service, $filename, $title] = $this->fixture(false, false, null, $fallback);
        DB::table('predb')->delete();
        $this->certify();
        $before = $release->searchname;
        $mutated = false;
        $restored = false;
        $releaseWrites = 0;
        $authorityBefore = $this->authoritySnapshot();
        DB::listen(function ($event) use (&$mutated, &$restored, &$releaseWrites, $title): void {
            if (str_starts_with(strtolower($event->sql), 'update "releases"')) {
                $releaseWrites++;
            }
            if (! $mutated && str_starts_with(strtolower($event->sql), 'update "releases"')) {
                $mutated = true;
                DB::table('predb')->insert(['id' => 8, 'title' => $title, 'filename' => '']);
                $this->projectInsertedOwnerAndRestoreReadyForFixture();
                $restored = (int) DB::table('predb_identity_state')->value('ready') === 1;
            }
        });
        $service->fixNamesWithFiles(0, true, 0, true, false);
        self::assertTrue($mutated);
        self::assertTrue($restored);
        self::assertSame(1, $releaseWrites, 'Stale observations must not be retried after rollback.');
        self::assertSame($authorityBefore, $this->authoritySnapshot(), 'Source, state, revisions and keys must roll back together.');
        self::assertSame($before, $release->fresh()->searchname, 'Stale absence must not rename');
        self::assertSame(0, $release->fresh()->proc_files, 'Stale absence must not complete');
        self::assertSame(0, DB::table('predb')->where('id', 8)->count());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('wholePassModes')]
    public function test_whole_pass_complete_ambiguous_sibling_does_not_veto_absent(bool $fallback): void
    {
        [$release, $service, $filename, $title] = $this->fixture(false, false, null, $fallback);
        DB::table('predb')->insert(['id' => 8, 'title' => $title, 'filename' => '']);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Synthetic.Absent.Observation-GRP.mkv']);
        $this->certify();
        $service->fixNamesWithFiles(0, true, 0, true, false);
        self::assertSame(1, $release->fresh()->proc_files);
        self::assertSame($fallback ? 'Synthetic.Fallback.Release.2026-GRP' : 'Unresolved.Synthetic.2026-GRP', $release->fresh()->searchname);
    }

    /** Fixture-only completed owner delta; public Rebuild deliberately rejects ambient transactions. */
    private function projectInsertedOwnerAndRestoreReadyForFixture(): void
    {
        $state = DB::table('predb_identity_state')->sole();
        $revision = DB::table('predb_identity_revisions')->where('predb_id', 8)->value('revision');
        (new PredbIdentityProjector)->replaceOwner(DB::connection(), $state->generation, 8, (int) $revision);
        DB::table('predb_identity_state')->where('id', 1)->update(['ready' => 1, 'built_epoch' => $state->epoch]);
        (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
        $observed = (new PredbIdentityLookup)->lookup(DB::connection(), new OriginalFilenameEvidence(DB::table('predb')->where('id', 8)->value('title').'.mkv'));
        self::assertSame('unique', $observed['status']);
        self::assertSame(8, $observed['candidate']['id']);
    }

    #[DataProvider('wholePassModes')]
    public function test_whole_pass_complete_rejected_sibling_does_not_veto_absent(bool $fallback): void
    {
        $selector = $this->createMock(PredbMatchSelector::class);
        $selector->expects(self::atLeastOnce())->method('selectBestMatch')->willReturn(null);
        [$release,$service] = $this->fixture(false, false, $selector, $fallback);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Synthetic.Absent.Observation-GRP.mkv']);
        $this->certify();
        $service->fixNamesWithFiles(0, true, 0, true, false);
        self::assertSame(1, $release->fresh()->proc_files);
        self::assertSame($fallback ? 'Synthetic.Fallback.Release.2026-GRP' : 'Unresolved.Synthetic.2026-GRP', $release->fresh()->searchname);
    }

    public function test_whole_pass_unknown_blocks_fallback_and_completion(): void
    {
        [$release,$service] = $this->fixture(false, false);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Synthetic.Absent.Observation-GRP.mkv']);
        $service->fixNamesWithFiles(0, true, 0, true, false);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Unresolved.Synthetic.2026-GRP', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array> */
    private function authoritySnapshot(): array
    {
        return [
            'source' => DB::table('predb')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'state' => (array) DB::table('predb_identity_state')->sole(),
            'revisions' => DB::table('predb_identity_revisions')->orderBy('predb_id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'keys' => DB::table('predb_identity_keys')->orderBy('generation')->orderBy('kind')->orderBy('key_digest')->orderBy('predb_id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }

    public static function inventoryMutationCases(): array
    {
        $cases = [];
        foreach (['rename', 'attach', 'fallback', 'completion'] as $route) {
            foreach ([false, true] as $ambient) {
                $cases[$route.($ambient ? ' ambient' : ' owned')] = [$route, $ambient];
            }
        }

        return $cases;
    }

    #[DataProvider('inventoryMutationCases')]
    public function test_inventory_mutation_during_write_rolls_back_without_consuming_caller_transaction(string $route, bool $ambient): void
    {
        $fallback = in_array($route, ['fallback', 'completion'], true) ? $route === 'fallback' : null;
        [$release, $service, $filename] = $this->fixture(false, $route === 'attach', null, $fallback);
        if ($fallback !== null) {
            DB::table('predb')->delete();
        }
        $this->certify();
        $releaseBefore = (array) DB::table('releases')->where('id', $release->id)->first();
        $authorityBefore = $this->authoritySnapshot();
        $filesBefore = DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all();
        DB::statement('CREATE TABLE caller_marker (value INTEGER NOT NULL)');
        $connection = DB::connection();
        $callerCallback = false;
        if ($ambient) {
            $connection->beginTransaction();
            DB::table('caller_marker')->insert(['value' => 77]);
            DB::afterCommit(static function () use (&$callerCallback): void {
                $callerCallback = true;
            });
        }
        $releaseWrites = 0;
        DB::listen(static function ($event) use (&$releaseWrites, $release): void {
            if (str_starts_with(strtolower($event->sql), 'update "releases"')) {
                if (++$releaseWrites === 1) {
                    DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Synthetic.New||Literal.Observation-GRP.mkv']);
                }
            }
        });
        try {
            if ($fallback !== null) {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } else {
                $service->matchPreDbFileNames($release, [$filename], true, true, false);
            }
            self::assertSame(1, $releaseWrites, 'Stale captured inventory must not be retried.');
            self::assertSame($releaseBefore, (array) DB::table('releases')->where('id', $release->id)->first());
            self::assertSame($filesBefore, DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
            self::assertSame($authorityBefore, $this->authoritySnapshot());
            self::assertSame($ambient ? 1 : 0, $connection->transactionLevel());
            self::assertSame($ambient, $connection->getPdo()->inTransaction());
            self::assertSame($ambient ? 1 : 0, DB::table('caller_marker')->count());
            self::assertFalse($callerCallback);
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            if ($ambient && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
        self::assertSame(0, DB::table('caller_marker')->count());
        DB::transaction(static fn (): bool => true);
        self::assertFalse($callerCallback, 'Rolled-back caller callbacks must not leak to a later transaction.');
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function finalCanonicalFaults(): array
    {
        return [
            'rename catalogue' => [false, false],
            'attach catalogue' => [true, false],
            'rename policy' => [false, true],
            'attach policy' => [true, true],
        ];
    }

    #[DataProvider('finalCanonicalFaults')]
    public function test_final_selected_row_read_cannot_escape_the_last_whole_pass_fence(bool $attach, bool $policyFault): void
    {
        [$release, $service, $filename, $title] = $this->fixture(false, $attach);
        $this->certify();
        $authorityBefore = $this->authoritySnapshot();
        $releaseBefore = (array) DB::table('releases')->where('id', $release->id)->first();
        $originalEncoding = mb_internal_encoding();
        $releaseWrites = 0;
        $selectedReads = 0;
        $injection = null;
        $trace = [];
        $runtimeFaultObserved = false;
        DB::listen(static function ($event) use (&$releaseWrites, &$selectedReads, &$injection, &$trace, $title, $policyFault): void {
            $sql = strtolower($event->sql);
            if (str_starts_with($sql, 'update "releases"')) {
                $releaseWrites++;
            }
            if ($releaseWrites === 0) {
                return;
            }
            $trace[] = $event->sql;
            // Lookup helper nominations join main.predb_identity_keys; this exact query is
            // predbCanonicalTitle's independent selected-row recheck after release DML.
            if (str_starts_with($sql, 'select * from "predb" where "id" = ? limit 1')) {
                $selectedReads++;
                if ($injection === null) {
                    $injection = ['sql' => $event->sql, 'bindings' => $event->bindings, 'trace' => $trace];
                    if ($policyFault) {
                        mb_internal_encoding('ISO-8859-1');
                    } else {
                        DB::table('predb')->insert(['id' => 8, 'title' => $title, 'filename' => '']);
                    }
                }
            }
        });
        try {
            // A bounded rejected pipe sibling carries the local policy token without
            // becoming an ABSENT observation or changing the selected owner's title.
            $observations = $policyFault ? [$filename, 'Synthetic|Locally.Rejected-GRP.mkv'] : [$filename];
            $service->matchPreDbFileNames($release, $observations, true, true, false);
            $operationTrace = $trace;
            $runtimeFaultObserved = mb_internal_encoding() !== $originalEncoding;
        } finally {
            mb_internal_encoding($originalEncoding);
        }
        self::assertNotNull($injection, 'The final selected-row read must be exercised.');
        self::assertSame('select * from "predb" where "id" = ? limit 1', $injection['sql']);
        self::assertSame([7], $injection['bindings']);
        self::assertSame(1, $releaseWrites, 'The stale write must not be retried.');
        self::assertSame(1, $selectedReads, 'Inject only at the independent postwrite canonical recheck.');
        $afterInjection = array_slice($operationTrace, count($injection['trace']));
        if ($policyFault) {
            self::assertTrue($runtimeFaultObserved, 'The local policy token must reject the changed runtime.');
        } else {
            self::assertNotEmpty(array_filter($afterInjection, static fn (string $sql): bool => str_contains($sql, ' from main.predb_identity_state limit 2')), 'The final SQL state fence must follow the selected-row injection.');
        }
        self::assertSame($title, DB::table('predb')->where('id', 7)->value('title'));
        self::assertSame($releaseBefore, (array) DB::table('releases')->where('id', $release->id)->first());
        self::assertSame($authorityBefore, $this->authoritySnapshot());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function incompleteObservationCases(): array
    {
        return ['uninspected sibling' => [false], 'unpersisted observation' => [true]];
    }

    #[DataProvider('incompleteObservationCases')]
    public function test_direct_completion_requires_observation_coverage_of_captured_inventory(bool $external): void
    {
        [$release, $service, $filename] = $this->fixture(false, false, null, false);
        DB::table('predb')->delete();
        if ($external) {
            $release->textstring = 'Synthetic.External.Observation-GRP.mkv';
        } else {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Synthetic.Uninspected.Sibling-GRP.mkv']);
            $release->textstring = $filename;
        }
        $this->certify();
        $service->checkName($release, true, 'Filenames, ', true, false);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }
}
