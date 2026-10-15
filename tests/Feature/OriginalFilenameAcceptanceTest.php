<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\FilenameNominationCache;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class OriginalFilenameAcceptanceTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function rows(): array
    {
        return [
            'wrong shorter attachment with both canonical candidates' => ['Exact.Release.2026-GRPdisc1.mkv', 'Exact.Release.2026-GRPdisc1', true, 8],
            'canonical group false negative' => ['Exact.Release.2026-GRPdisc1.mkv', 'Exact.Release.2026-GRPdisc1', false, 8],
            'technical canonical suffix false negative' => ['Exact.Release.2026.DTS.rar', 'Exact.Release.2026.DTS', false, 8],
            'ASCII control is not literal evidence' => ["Exact.Release.2026-GRP\x01.mkv", 'Exact.Release.2026-GRP', false, 0],
            'space is not literal evidence' => ['Exact.Release.2026-GRP .mkv', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT multipart original title' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT two digit multipart' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.01', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT four digit multipart' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.0001', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT long part parity chain' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.part12345.rar.par2', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT payload under long part' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.mkv.part12345.rar', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT archive token in literal title' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026.rar.mkv', 'Exact.Release.2026.rar', false, 8],
            'NZBSPLIT codec title' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026_DTS.rar', 'Exact.Release.2026.DTS', false, 8],
            'NZBSPLIT yEnc subject counter' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073 yEnc (1)', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT quoted yEnc fraction' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073" yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'NZBSPLIT arbitrary subject tail cannot authorize' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073 yEnc unknown', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT repeated subject tail cannot authorize' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073 yEnc (1) yEnc (2)', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT unbounded subject counter cannot authorize' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.7z.073 yEnc (1/42/77)', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT group spelling survives nomination' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRPdisc1.mkv', 'Exact.Release.2026-GRPdisc1', true, 8],
            'NZBSPLIT payload whitespace cannot authorize' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__ Exact_Release_2026-GRP.7z.073', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT repeated separators cannot authorize' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact__Release_2026-GRP.7z.073', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT overlapping marker cannot authorize' => ['__NZBSPLIT__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.rar', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT nested wrapper cannot authorize' => ['Outer__NZBSPLIT__invalid__NZBSPLIT__Junk__NZBSPLIT__abcdef0123456789__NZBSPLIT__Exact_Release_2026-GRP.rar', 'Exact.Release.2026-GRP', false, 0],
            'NZBSPLIT invalid wrapper hash cannot authorize' => ['Outer__NZBSPLIT__invalid__NZBSPLIT__Exact_Release_2026-GRP.7z.073', 'Exact.Release.2026-GRP', false, 0],
            'part archive positive' => ['Exact.Release.2026-GRP.part01.rar', 'Exact.Release.2026-GRP', false, 8],
            'single payload preserves canonical suffix' => ['Exact.Release.2026.flac.mkv', 'Exact.Release.2026.flac', true, 8],
            'ordinary positive' => ['Exact.Release.2026-GRP.mkv', 'Exact.Release.2026-GRP', false, 8],
            'unicode positive' => ['Éxact.Release.2026-GRP.mkv', 'Éxact.Release.2026-GRP', false, 8],
            'canonical case folding' => ['EXACT.RELEASE.2026-GRP.MKV', 'Exact.Release.2026-GRP', false, 8],
            'unicode canonical case folding' => ['ÉXACT.RELEASE.2026-GRP.mkv', 'éxact.release.2026-grp', false, 8],
            'uppercase part archive positive' => ['Exact.Release.2026-GRP.PART01.RAR', 'Exact.Release.2026-GRP', false, 8],
            'leading number canonical positive' => ['01.Exact.Release.2026-GRP.mkv', '01.Exact.Release.2026-GRP', false, 8],
        ];
    }

    #[DataProvider('rows')]
    public function test_canonical_evidence(string $filename, string $title, bool $shorter, int $expected): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $hits = [['id' => 8, 'title' => $title]];
        if ($shorter) {
            $hits[] = ['id' => 7, 'title' => 'Exact.Release.2026-GRP'];
        }
        DB::table('predb')->insert($hits);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $discoveredQueries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturnUsing(static function (string $query) use (&$discoveredQueries, $hits): array {
            $discoveredQueries[] = $query;

            return $hits;
        });
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->getPreFileNames(['prefile', '1']);
        self::assertSame($expected, $release->fresh()->predb_id);
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
        self::assertSame($expected > 0, $updates->lastCommittedIdentity() !== null);
    }

    public static function nzbSplitPipelineCases(): array
    {
        $rows = [];
        foreach (['.7z.073', '.7z.01', '.7z.0001', '.part12345.rar.par2', '.mkv.part12345.rar', '.7z.073 yEnc (1)', '.7z.073" yEnc (1/42)'] as $suffix) {
            $rows[$suffix.' canonical hit'] = [true, $suffix];
            $rows[$suffix.' extractor fallback'] = [false, $suffix];
        }

        return $rows;
    }

    public static function wrappedCodecRows(): array
    {
        $rows = [];
        foreach (['DIVX', 'APE', 'MP2', 'MPC', 'SHN', 'TAK', 'TTA', 'WV', 'DTS'] as $codec) {
            foreach (['both', 'correct only', 'shorter only'] as $candidates) {
                $rows[$codec.' '.$candidates] = [$codec, $candidates];
            }
        }

        return $rows;
    }

    #[DataProvider('wrappedCodecRows')]
    public function test_archive_wrapped_codec_preserves_canonical_identity(string $codec, string $candidates): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $shorter = 'Exact.Release.2026';
        $title = $shorter.'.'.$codec;
        $filename = $title.'.rar';
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $rows = [];
        if ($candidates !== 'correct only') {
            $rows[] = ['id' => 7, 'title' => $shorter];
        }
        if ($candidates !== 'shorter only') {
            $rows[] = ['id' => 8, 'title' => $title];
        }
        DB::table('predb')->insert($rows);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturn($rows);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->getPreFileNames(['prefile', '1']);
        $accepted = $candidates !== 'shorter only';
        self::assertSame($accepted ? 8 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame($accepted, $updates->lastCommittedIdentity() !== null);
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
    }

    #[DataProvider('nzbSplitPipelineCases')]
    public function test_nzbsplit_file_pipeline_preserves_matching_and_fallback(bool $hit, string $suffix): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026.1080p.WEB-DL.x264-GRP';
        $release = $this->pendingPar2Release(['searchname' => 'Obfuscated.Payload']);
        $filename = 'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__'.str_replace('.', '_', $title).$suffix;
        $rows = $hit ? [['id' => 7, 'title' => $title]] : [];
        if ($hit) {
            DB::table('predb')->insert($rows);
        }
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturn($rows);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates);
        $entry = clone $release;
        $entry->releases_id = $release->id;
        (new \ReflectionMethod($service, 'processFileCandidates'))->invoke($service, $entry, [(object) ['textstring' => $filename]], true, true, false);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame($hit ? 7 : 0, $release->fresh()->predb_id);
        self::assertTrue($updates->matched);
    }

    public function test_same_cleaned_query_reuses_accepted_match_across_distinct_original_evidence(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        $a = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $b = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $queries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturnUsing(static function (string $query) use (&$queries, $title): array {
            $queries[] = $query;

            return [['id' => 7, 'title' => $title]];
        });
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $service = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync));
        foreach ([[$a, $title.'.mkv'], [$b, $title.'disc1.mkv']] as [$release, $filename]) {
            $entry = clone $release;
            $entry->releases_id = $release->id;
            self::assertSame($filename === $title.'.mkv' ? 1 : 0, $service->matchPreDbFileNames($entry, [$filename], true, true, false));
        }
        self::assertSame([7, 0], [$a->fresh()->predb_id, $b->fresh()->predb_id]);
        self::assertSame([$title], $queries);
    }

    public static function mixedRows(): array
    {
        return ['literal first' => [false], 'independent first' => [true]];
    }

    #[DataProvider('mixedRows')]
    public function test_mixed_persisted_rows_attach_only_the_independent_filename(bool $reverse): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $suffixTitle = 'Exact.Release.2026-GRP';
        $trueTitle = 'True.Title-GRP';
        $release = $this->pendingPar2Release(['searchname' => $trueTitle, 'predb_id' => 0]);
        DB::table('predb')->insert([
            ['id' => 7, 'title' => $suffixTitle],
            ['id' => 8, 'title' => $trueTitle],
        ]);
        $names = ['Auxiliary.Asset||'.$suffixTitle.'.mkv', $trueTitle.'.mkv'];
        if ($reverse) {
            $names = array_reverse($names);
        }
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $before = DB::table('release_files')->where('releases_id', $release->id)->orderBy('id')->pluck('name')->all();
        $discoveredQueries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturnUsing(static function (string $query) use (&$discoveredQueries, $suffixTitle, $trueTitle): array {
            $discoveredQueries[] = $query;

            return [['id' => 7, 'title' => $suffixTitle], ['id' => 8, 'title' => $trueTitle]];
        });
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $queries = new NameFixingQueryService;
        self::assertCount(2, $queries->fileRows([$release->id]));
        (new NameFixingService($updates, queries: $queries))->getPreFileNames(['prefile', '1']);

        self::assertNotContains($suffixTitle, $discoveredQueries, json_encode(['predb_id' => $release->fresh()->predb_id, 'searchname' => $release->fresh()->searchname]));
        self::assertContains('Auxiliary.Asset '.$suffixTitle, $discoveredQueries);
        self::assertContains($trueTitle, $discoveredQueries);
        self::assertSame(8, $release->fresh()->predb_id);
        self::assertSame($trueTitle, $release->fresh()->searchname);
        self::assertSame($before, DB::table('release_files')->where('releases_id', $release->id)->orderBy('id')->pluck('name')->all());
    }

    public static function admissions(): array
    {
        return ['current clone and claim' => [false, false, true], 'stale clone' => [true, false, false], 'stale claim' => [false, true, false]];
    }

    #[DataProvider('admissions')]
    public function test_list_path_preserves_cloned_release_snapshot_and_observed_claim(bool $staleSnapshot, bool $staleClaim, bool $matches): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE releases ADD COLUMN additional_pp_claim_token TEXT');
        DB::statement('ALTER TABLE releases ADD COLUMN additional_pp_claimed_at TEXT');
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $stamp = now()->format('Y-m-d H:i:s');
        DB::table('releases')->where('id', $release->id)->update(['additional_pp_claim_token' => 'claim-token', 'additional_pp_claimed_at' => $stamp]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $entry = clone $release;
        $entry->releases_id = $release->id;
        $entry->setAttribute('observed_additional_pp_claim', ['token' => $staleClaim ? 'expired-token' : 'claim-token', 'claimed_at' => $stamp]);
        if ($staleSnapshot) {
            DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement-guid']);
        }
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => $title]]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        self::assertSame($matches ? 1 : 0, (new NameFixingService($updates))->matchPreDbFileNames($entry, [$title.'.mkv'], true, true, false));
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertArrayNotHasKey('observed_additional_pp_claim', $release->getAttributes());
        self::assertSame($matches, $updates->lastCommittedIdentity() !== null);
    }

    public static function aliasRows(): array
    {
        return [
            'authoritative ordinary alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.rar', true],
            'authoritative parity alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.rar.par2', true],
            'authoritative long part parity alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.part12345.rar.par2', true],
            'authoritative short 7z alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.7z.01', true],
            'authoritative NZBSPLIT parity alias' => ['Publisher.Asset-GRP.mkv', 'Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Publisher_Asset-GRP.rar.par2', true],
            'authoritative preserved group alias' => ['Publisher.Asset-GRPdisc1.mkv', 'Publisher.Asset-GRPdisc1.rar', true],
            'lossy group alias cannot authorize' => ['Publisher.Asset-GRPdisc1.mkv', 'Publisher.Asset-GRP.mkv', false],
            'lossy payload alias cannot authorize' => ['Publisher.Asset-GRP.flac.mkv', 'Publisher.Asset-GRP.mkv', false],
            'technical alias literal match' => ['Publisher.Asset.DTS.rar', 'Publisher.Asset.DTS.mkv', true],
            'NZBSPLIT payload alias' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Publisher_Asset-GRP.7z.073', 'Publisher.Asset-GRP.mkv', true],
            'NZBSPLIT short multipart alias' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Publisher_Asset-GRP.7z.01', 'Publisher.Asset-GRP.mkv', true],
            'NZBSPLIT long parity chain alias' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Publisher_Asset-GRP.part12345.rar.par2', 'Publisher.Asset-GRP.mkv', true],
            'NZBSPLIT lossy alias rejected' => ['Outer__NZBSPLIT__abcdef0123456789__NZBSPLIT__Publisher__Asset-GRP.7z.073', 'Publisher.Asset-GRP.mkv', false],
            'part archive alias' => ['Publisher.Asset-GRP.part01.rar', 'Publisher.Asset-GRP.mkv', true],
            'authoritative alias case folding' => ['PUBLISHER.ASSET-GRP.MKV', 'publisher.asset-grp.rar', true],
            'authoritative unicode alias case folding' => ['ÉDITEUR.Asset-GRP.mkv', 'éditeur.asset-grp.rar', true],
            'control alias cannot authorize' => ["Publisher.Asset-GRP\x01.mkv", 'Publisher.Asset-GRP.rar', false],
            'space alias cannot authorize' => ['Publisher.Asset-GRP .mkv', 'Publisher.Asset-GRP.rar', false],
            'unknown suffix alias' => ['Publisher.Asset-GRP.unknown', 'Publisher.Asset-GRP.mkv', false],
        ];
    }

    #[DataProvider('aliasRows')]
    public function test_original_alias_evidence_is_revalidated(string $original, string $alias, bool $accept): void
    {
        $this->createPar2RenameSchema();
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Canonical.Title.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $title]);
        $row = ['id' => 7, 'title' => $title, 'filename' => $alias];
        DB::table('predb')->insert($row);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturn([$row]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates);
        $entry = clone $release;
        $entry->releases_id = $release->id;
        self::assertSame($accept ? 1 : 0, $service->matchPreDbFileNames($entry, [$original], true, true, false));
        self::assertSame($accept ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        if ($accept) {
            $second = $this->pendingPar2Release(['searchname' => $title]);
            $second->releases_id = $second->id;
            DB::table('predb')->where('id', 7)->update(['filename' => 'Replacement.Alias-GRP.mkv']);
            // Search nominations are stale and reused; the DB mapping must still be revalidated.
            self::assertSame(0, $service->matchPreDbFileNames($second, [$original], true, true, false));
            self::assertSame(0, $second->fresh()->predb_id);
        }
    }

    public static function strongestTierRows(): array
    {
        $rows = [];
        foreach (['.mkv', '.7z.001', '.mkv.001', '.epub', '.iso', '.r100', '.z01'] as $suffix) {
            $rows[$suffix.' canonical outranks alias'] = [false, false, 7, $suffix];
            $rows[$suffix.' canonical outranks alias reversed'] = [true, false, 7, $suffix];
            $rows[$suffix.' conflicting canonical IDs'] = [false, true, 0, $suffix];
            $rows[$suffix.' conflicting canonical IDs reversed'] = [true, true, 0, $suffix];
        }

        return $rows;
    }

    #[DataProvider('strongestTierRows')]
    public function test_original_canonical_tier_preserves_identity_and_ambiguity(bool $reverse, bool $duplicateCanonical, int $expected, string $suffix): void
    {
        $this->createPar2RenameSchema();
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Éxact.Release.2026-GRP';
        $original = 'ÉXACT.RELEASE.2026-GRP'.$suffix;
        $rows = [
            ['id' => 7, 'title' => $title, 'filename' => $original],
            ['id' => 8, 'title' => $duplicateCanonical ? mb_strtolower($title) : 'Different.Canonical.2026-GRP', 'filename' => $original],
        ];
        DB::table('predb')->insert($rows);
        if ($reverse) {
            $rows = array_reverse($rows);
        }
        $release = $this->pendingPar2Release(['searchname' => $title]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturn($rows);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates);
        $entry = clone $release;
        $entry->releases_id = $release->id;
        self::assertSame($expected > 0 ? 1 : 0, $service->matchPreDbFileNames($entry, [$original], true, true, false));
        self::assertSame($expected, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
    }

    public function test_uncached_tail_alias_ambiguity_is_not_truncated(): void
    {
        $title = 'Exact.Release.2026-GRP';
        $rows = [
            ['id' => 7, 'title' => 'Other.Canonical.One', 'filename' => $title],
            ['id' => 8, 'title' => 'Other.Canonical.Two', 'filename' => $title],
        ];
        $service = new NameFixingService($this->createStub(ReleaseUpdateService::class));
        (new \ReflectionProperty($service, 'filenameNominations'))->setValue($service,
            new FilenameNominationCache(1));
        Search::shouldReceive('searchPredb')->twice()->andReturn($rows);
        $lookup = new \ReflectionMethod($service, 'findBestPredbFilenameMatch');
        self::assertNull($lookup->invoke($service, $title, $title.'.mkv'));
        self::assertNull($lookup->invoke($service, $title, $title.'.mkv'));
    }

    public function test_uncached_tail_canonical_evidence_still_outranks_alias(): void
    {
        $title = 'Exact.Release.2026-GRP';
        $rows = [
            ['id' => 7, 'title' => 'Other.Canonical.One', 'filename' => $title],
            ['id' => 8, 'title' => $title, 'filename' => ''],
        ];
        $service = new NameFixingService($this->createStub(ReleaseUpdateService::class));
        (new \ReflectionProperty($service, 'filenameNominations'))->setValue($service,
            new FilenameNominationCache(1));
        Search::shouldReceive('searchPredb')->twice()->andReturn($rows);
        $lookup = new \ReflectionMethod($service, 'findBestPredbFilenameMatch');
        self::assertSame(8, $lookup->invoke($service, $title, $title.'.mkv')['id']);
        self::assertSame(8, $lookup->invoke($service, $title, $title.'.mkv')['id']);
    }

    public function test_oversized_nominations_report_evidence_without_retaining_separate_query_keys(): void
    {
        $service = new NameFixingService($this->createStub(ReleaseUpdateService::class));
        (new \ReflectionProperty($service, 'filenameNominations'))->setValue($service,
            new FilenameNominationCache(1));
        Search::shouldReceive('searchPredb')->twice()->andReturn([
            ['id' => 7, 'title' => 'Other.Canonical.One'],
            ['id' => 8, 'title' => 'Other.Canonical.Two'],
        ]);
        $lookup = new \ReflectionMethod($service, 'findBestPredbFilenameMatch');
        foreach (['First.Release.2026-GRP', 'Second.Release.2026-GRP'] as $query) {
            $hadEvidence = false;
            self::assertNull($lookup->invokeArgs($service, [$query, $query.'.mkv', &$hadEvidence]));
            self::assertTrue($hadEvidence);
        }
        self::assertSame([], (new \ReflectionProperty($service, 'predbEvidenceCache'))->getValue($service));
    }
}
