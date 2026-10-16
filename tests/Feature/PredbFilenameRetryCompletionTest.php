<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameRetryCompletionTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function callers(): array
    {
        return [['files'], ['standard'], ['check'], ['prefile']];
    }

    #[DataProvider('callers')]
    public function test_unknown_filename_lookup_remains_pending_and_next_public_pass_can_attach(string $caller): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $alias = 'Synthetic.Observed.Alias-GRP';
        $filename = $alias.'.mkv';
        $release = $this->pendingPar2Release([
            'searchname' => $title, 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $alias]);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $calls = 0;
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        // Deliberately unready until the next public pass.
        $service = new NameFixingService;
        $run = static function () use ($service, $release, $caller, $filename): void {
            if ($caller === 'files') {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } elseif ($caller === 'standard') {
                $service->processStandardBatch('a', 1, false);
            } elseif ($caller === 'prefile') {
                $service->getPreFileNames(['prefile', '1']);
            } else {
                $entry = $release->fresh();
                $entry->releases_id = $entry->id;
                $entry->textstring = $filename;
                $service->reset();
                $service->checkName($entry, true, 'Filenames, ', true, false);
            }
        };
        $run();
        self::assertSame(0, $release->fresh()->proc_files, 'Unknown nominations must not complete filename inspection');
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->isrenamed);
        $this->certifySqlIdentityFixture();
        $run();
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame([$filename], DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
    }

    public static function positivePassCases(): array
    {
        $cases = [];
        foreach (['files', 'standard', 'list', 'prefile'] as $caller) {
            foreach ([false, true] as $reverse) {
                foreach ([false, true] as $sameName) {
                    $cases[$caller.' '.($reverse ? 'positive first' : 'unknown first').' '.($sameName ? 'attach' : 'rename')] = [$caller, $reverse, $sameName];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('positivePassCases')]
    public function test_unknown_observation_blocks_every_positive_write_for_the_whole_pass(string $caller, bool $reverse, bool $sameName): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $alias = 'Synthetic.Positive.Observation-GRP';
        $original = $sameName ? $title : 'Unresolved.Synthetic.2026-GRP';
        $release = $this->pendingPar2Release([
            'searchname' => $original, 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $alias]);
        $names = ['Synthetic.Unknown.Observation-GRP.mkv', $alias.'.mkv'];
        if ($reverse) {
            $names = array_reverse($names);
        }
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $calls = 0;
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $this->makeUnknownSqlObservation('Synthetic.Unknown.Observation-GRP');
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $release->releases_id = $release->id;
            $service->matchPreDbFileNames($release, $names, true, true, false);
        }
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($original, $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->isrenamed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function wholePassIdentityCases(): array
    {
        $cases = [];
        foreach (['files', 'standard', 'list', 'prefile'] as $caller) {
            foreach ([false, true] as $conflict) {
                $cases[$caller.' '.($conflict ? 'conflicting identities' : 'repeated identity')] = [$caller, $conflict];
            }
        }

        return $cases;
    }

    #[DataProvider('wholePassIdentityCases')]
    public function test_whole_pass_rejects_conflicting_identities_but_accepts_repeated_witnesses(string $caller, bool $conflict): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        $title = 'Synthetic.First.Release.2026-GRP';
        $other = 'Synthetic.Second.Release.2026-GRP';
        $release = $this->pendingPar2Release([
            'searchname' => $title, 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        if ($conflict) {
            DB::table('predb')->insert(['id' => 8, 'title' => $other]);
        }
        $names = [$title.'.mkv', $conflict ? $other.'.mkv' : $title.'.mp4'];
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $calls = 0;
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $this->certifySqlIdentityFixture();
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $release->releases_id = $release->id;
            $service->matchPreDbFileNames($release, $names, true, true, false);
        }
        self::assertSame($conflict ? 0 : 7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        if ($conflict) {
            self::assertSame(0, $release->fresh()->proc_files);
        }
    }

    public static function staleWitnessCases(): array
    {
        $cases = [];
        foreach (['files', 'standard', 'list', 'prefile'] as $caller) {
            foreach ([false, true] as $sameName) {
                $cases[$caller.' '.($sameName ? 'attach' : 'rename')] = [$caller, $sameName];
            }
        }

        return $cases;
    }

    #[DataProvider('staleWitnessCases')]
    public function test_rejected_stale_alias_cannot_mask_a_valid_later_same_identity_witness(string $caller, bool $sameName): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $alias = 'Synthetic.Observed.Long.Stale.Alias.Before.Canonical.2026-GRP';
        $release = $this->pendingPar2Release([
            'searchname' => $sameName ? $title : 'Unresolved.Synthetic.2026-GRP', 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => 'Different.Current.Alias-GRP']);
        $names = [$alias.'.mkv', $title.'.mkv'];
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $queries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $this->certifySqlIdentityFixture();
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $release->releases_id = $release->id;
            $service->matchPreDbFileNames($release, $names, true, true, false);
        }
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
    }

    #[DataProvider('staleWitnessCases')]
    public function test_stale_sql_projection_blocks_later_valid_canonical_witness(string $caller, bool $sameName): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $alias = 'Synthetic.Observed.Long.Stale.Alias.Before.Canonical.2026-GRP';
        $release = $this->pendingPar2Release([
            'searchname' => $sameName ? $title : 'Unresolved.Synthetic.2026-GRP', 'leftguid' => 'a', 'predb_id' => 0,
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => 'Different.Current.Alias-GRP']);
        $names = [$alias.'.mkv', $title.'.mkv'];
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $queries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $this->makeUnknownSqlObservation($alias);
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $release->releases_id = $release->id;
            $service->matchPreDbFileNames($release, $names, true, true, false);
        }
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame($sameName ? $title : 'Unresolved.Synthetic.2026-GRP', $release->fresh()->searchname);
    }

    public static function genericDispatchCases(): array
    {
        return ['rename' => [false], 'attach' => [true]];
    }

    #[DataProvider('genericDispatchCases')]
    public function test_filename_dispatch_cannot_bypass_unknown_literal_evidence_with_generic_title_extraction(bool $sameName): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => false]);
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $original = $sameName ? $title : 'Unresolved.Synthetic.2026-GRP';
        $filename = $title.' + unrelated.mkv';
        $release = $this->pendingPar2Release(['searchname' => $original, 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $release->releases_id = $release->id;
        $release->textstring = $filename;
        $calls = 0;
        // Unready SQL inventory blocks generic extraction.
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        self::assertFalse($service->checkName($release, true, 'Filenames, ', true, false));
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($original, $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->proc_files);
    }

    #[DataProvider('secondLookupCases')]
    public function test_reused_filename_dispatch_does_not_report_a_previous_release_match_for_unknown_evidence(bool $unknown): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => false]);
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $first = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $second = $this->pendingPar2Release(['searchname' => 'Unresolved.Synthetic.2026-GRP', 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $first->releases_id = $first->id;
        $first->textstring = $title.'.mkv';
        $second->releases_id = $second->id;
        $second->textstring = 'Synthetic.Unknown.Observation-GRP.mkv';
        DB::table('release_files')->insert(['releases_id' => $second->id, 'name' => $second->textstring]);
        $calls = 0;
        $this->certifySqlIdentityFixture();
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects($unknown ? self::never() : self::once())->method('extractFromFile')->willReturn(null);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        self::assertTrue($service->checkName($first, true, 'Filenames, ', true, false));
        self::assertSame(7, $first->fresh()->predb_id);
        if ($unknown) {
            DB::table('predb_identity_state')->update(['ready' => 0]);
        }
        self::assertFalse($service->checkName($second, true, 'Filenames, ', true, false));
        self::assertSame(0, $second->fresh()->predb_id);
        self::assertSame($unknown ? 0 : 1, $second->fresh()->proc_files);
    }

    public static function xxxPassCases(): array
    {
        return [['unknown', true], ['unknown', false], ['conflict', true], ['absent', true], ['absent', false]];
    }

    #[DataProvider('xxxPassCases')]
    public function test_xxx_filename_pass_requires_complete_nonconflicting_observations(string $response, bool $rename): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => false, 'tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        Event::fake([ReleaseNameFixed::class]);
        $original = 'Unresolved.Synthetic.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $original, 'predb_id' => 0, 'proc_files' => 0]);
        $alpha = 'Synthetic.Alpha.2026.1080p-SDPORN';
        $beta = 'Synthetic.Beta.2026.1080p-GRP';
        $names = $rename ? [$alpha.'.mkv', $beta.'.mkv'] : ['SDPORN.mkv'];
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        if ($response === 'conflict') {
            DB::table('predb')->insert([['id' => 7, 'title' => $alpha], ['id' => 8, 'title' => $beta]]);
        }
        $calls = 0;
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        if ($response !== 'unknown') {
            $this->certifySqlIdentityFixture();
        }
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->fixXXXNamesWithFiles(0, true, 0, true, false);
        if ($response !== 'absent') {
            self::assertSame($original, $release->fresh()->searchname);
            self::assertSame(0, $release->fresh()->predb_id);
            self::assertSame(0, $release->fresh()->proc_files);
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } elseif ($rename) {
            self::assertSame($alpha, $release->fresh()->searchname);
        } else {
            self::assertSame(1, $release->fresh()->proc_files);
        }
    }

    public static function secondLookupCases(): array
    {
        return [[true], [false]];
    }

    public static function completionCases(): array
    {
        return [
            ['files', false], ['standard', false], ['check', false],
            ['files', true], ['standard', true],
        ];
    }

    #[DataProvider('completionCases')]
    public function test_only_complete_observations_can_complete_an_unmatched_filename_pass(string $caller, bool $mixedUnknown): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $release = $this->pendingPar2Release([
            'searchname' => 'Unresolved.Synthetic.2026-GRP', 'leftguid' => 'a',
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        $names = ['opaque1234567890.mkv'];
        if ($mixedUnknown) {
            $names[] = 'opaque0987654321.mkv';
        }
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $calls = 0;
        if ($mixedUnknown) {
            $this->makeUnknownSqlObservation('opaque1234567890');
        } else {
            $this->certifySqlIdentityFixture();
        }
        $service = new NameFixingService;
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } else {
            $release->releases_id = $release->id;
            $release->textstring = $names[0];
            $service->checkName($release, true, 'Filenames, ', true, false);
        }
        self::assertSame($mixedUnknown ? 0 : 1, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->isrenamed);
    }

    public static function fallbackPasses(): array
    {
        return [['files', false], ['files', true], ['standard', false], ['standard', true]];
    }

    #[DataProvider('fallbackPasses')]
    public function test_any_incomplete_filename_suppresses_fallback_for_the_whole_pass(string $caller, bool $reverse): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $release = $this->pendingPar2Release([
            'searchname' => 'Unresolved.Synthetic.2026-GRP', 'leftguid' => 'a',
            'proc_files' => 0, 'proc_uid' => 1, 'proc_crc32' => 1,
            'proc_hash16k' => 1, 'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        $names = ['Synthetic.Unknown.2026-GRP.mkv', 'Synthetic.Fallback.2026-GRP.mkv'];
        foreach ($reverse ? array_reverse($names) : $names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $calls = 0;
        $this->makeUnknownSqlObservation('Synthetic.Unknown.2026-GRP');
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $service = new NameFixingService(fileExtractor: $extractor);
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } else {
            $service->processStandardBatch('a', 1, false);
        }
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->isrenamed);
    }

    private function installSqlIdentityFixture(): void
    {
        DB::statement("ALTER TABLE predb ADD COLUMN filename varchar NOT NULL DEFAULT ''");
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
    }

    private function certifySqlIdentityFixture(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    private function makeUnknownSqlObservation(string $title): void
    {
        DB::table('predb')->insert(['id' => 99, 'title' => $title, 'filename' => '']);
        $this->certifySqlIdentityFixture();
        DB::table('predb_identity_revisions')->where('predb_id', 99)->update(['projected_revision' => null]);
    }
}
