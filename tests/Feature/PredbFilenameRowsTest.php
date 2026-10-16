<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameRowsTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function rows(): array
    {
        return [
            'one literal row' => [['Auxiliary.Asset||Exact.Release.2026-GRP.mkv'], false],
            'two independent rows' => [['Auxiliary.Asset', 'Exact.Release.2026-GRP.mkv'], true],
            'single normal row' => [['Exact.Release.2026-GRP.mkv'], true],
            'show preserves filename diagnostic' => [['Exact.Release.2026-GRP.mkv'], true, true],
        ];
    }

    #[DataProvider('rows')]
    public function test_persisted_filename_row_boundaries_survive_public_prefile_caller(array $names, bool $matches, bool $show = false): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => $show]);
        if ($show) {
            DB::statement('CREATE TABLE categories (id INTEGER, title TEXT)');
        }
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $show ? 'Original.Release.2026-GRP' : $title, 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $before = DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all();
        $discoveredQueries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $this->certifySqlIdentityFixture();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        $queries = new NameFixingQueryService;
        self::assertCount(count($names), $queries->fileRows([$release->id]));
        $service = new NameFixingService($updates, queries: $queries);
        ob_start();
        try {
            $service->getPreFileNames($show ? ['prefile', '1', 'show'] : ['prefile', '1']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if ($show) {
            self::assertStringContainsString('Filename:', $output);
        }
        self::assertSame($before, DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id, json_encode(['rows' => count($names), 'discovered_queries' => $discoveredQueries]));
        self::assertSame($title, $release->fresh()->searchname);

    }

    public static function expandedArchiveDiscovery(): array
    {
        $cases = [];
        foreach (['.7z.01', '.7z.0001', '.part12345.rar', '.tar.zst', '.tgz', '.lz4', '.enc', '.rev', '.rar.par2'] as $suffix) {
            foreach (['prefile', 'list'] as $caller) {
                foreach ([false, true] as $alias) {
                    $cases[$caller.$suffix.($alias ? ' alias' : ' canonical')] = [$suffix, $caller, $alias, true];
                }
            }
        }
        $cases['no nomination'] = ['.7z.01', 'list', false, false];
        $cases['malformed wrapper'] = ['.7z.00001', 'list', false, true];
        $cases['malformed prefile wrapper'] = ['.7z.00001', 'prefile', false, true];

        foreach (['part', 'vol', 'cd', 'dvd', 'disc', 'disk'] as $token) {
            foreach (['prefile', 'list'] as $caller) {
                foreach (['plain', 'case', 'leading', 'archive', 'alias', 'wrapper', 'path', 'nohit', 'malformed', 'wrapper-path', 'encoded-suffix', 'wrong-title'] as $variant) {
                    $cases[$caller.' terminal '.$token.' '.$variant] = ['.mkv', $caller, $variant === 'alias', $variant !== 'nohit', $token, $variant];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('expandedArchiveDiscovery')]
    public function test_expanded_archive_discovery_reaches_persisted_filename_callers(string $suffix, string $caller, bool $alias, bool $nominate, string $terminal = '', string $variant = ''): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = ($variant === 'leading' ? '01.' : '').'Exact.Release.2026-GRP'.$terminal.($terminal === '' ? '' : '1');
        $stem = $alias ? 'Synthetic.Filename.Alias-GRP' : $title;
        if ($alias && $terminal !== '') {
            $stem .= $terminal.'1';
        }
        $queryStem = $variant === 'case' ? strtolower($stem) : $stem;
        $suffix = $variant === 'archive' ? '.part12345.rar' : $suffix;
        $filename = $queryStem.$suffix;
        if ($variant === 'wrapper' || $variant === 'malformed') {
            $filename = '__NZBSPLIT__'.($variant === 'malformed' ? 'bad' : 'abcdef1234').'__NZBSPLIT__'.str_replace('.', '_', $queryStem).$suffix;
        }
        if ($variant === 'wrapper-path') {
            $filename = '__NZBSPLIT__abcdef1234__NZBSPLIT__directory/'.$queryStem.$suffix;
        }
        if ($variant === 'encoded-suffix') {
            $filename = '__NZBSPLIT__abcdef1234__NZBSPLIT__'.str_replace('.', '_', $queryStem.$suffix);
        }
        if ($variant === 'wrong-title') {
            $filename = 'Extra.'.$queryStem.$suffix;
        }
        if ($variant === 'path') {
            $filename = 'directory/'.$filename;
        }
        $release = $this->pendingPar2Release(['searchname' => 'Original.Release.2026-GRP', 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        if ($alias) {
            DB::table('predb')->where('id', 7)->update(['filename' => $stem]);
        }
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $queries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        if (! $nominate) {
            DB::table('predb')->where('id', 7)->delete();
        }
        $this->certifySqlIdentityFixture();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $service = new NameFixingService(new ReleaseUpdateService($category, searchSyncCoordinator: $sync));
        if ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $service->matchPreDbFileNames($release, [$filename], true, true, false);
        }
        $matches = $nominate && $suffix !== '.7z.00001' && ! in_array($variant, ['malformed', 'wrapper-path', 'encoded-suffix', 'wrong-title'], true);
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id, json_encode($queries));
        self::assertSame($matches ? $title : 'Original.Release.2026-GRP', $release->fresh()->searchname);
        self::assertSame($filename, DB::table('release_files')->where('releases_id', $release->id)->value('name'));

    }

    public static function mixedRows(): array
    {
        return ['literal first' => [false], 'independent first' => [true]];
    }

    #[DataProvider('mixedRows')]
    public function test_mixed_persisted_rows_attach_only_the_independent_filename(bool $reverse): void
    {
        $this->createPar2RenameSchema();
        $this->installSqlIdentityFixture();
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
        $this->certifySqlIdentityFixture();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $queries = new NameFixingQueryService;
        self::assertCount(2, $queries->fileRows([$release->id]));
        (new NameFixingService($updates, queries: $queries))->getPreFileNames(['prefile', '1']);

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
        $this->installSqlIdentityFixture();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
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
        $this->certifySqlIdentityFixture();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        self::assertSame($matches ? 1 : 0, (new NameFixingService($updates))->matchPreDbFileNames($entry, [$title.'.mkv'], true, true, false));
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertArrayNotHasKey('observed_additional_pp_claim', $release->getAttributes());
        self::assertSame($matches, $updates->lastCommittedIdentity() !== null);
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
