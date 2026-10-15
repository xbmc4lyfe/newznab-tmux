<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class CompleteTitleSuffixTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function rows(): array
    {
        $rows = [];
        foreach (['rar', '001', 'par2', 'part12345'] as $token) {
            foreach (['both', 'long', 'short'] as $set) {
                foreach ([false, true] as $reverse) {
                    foreach ([0, 1, 2] as $wrapped) {
                        $rows[$token.$set.(int) $reverse.(int) $wrapped] = [$token, $set, $reverse, $wrapped];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('rows')]
    public function test_complete_title_suffix_authority(string $token, string $set, bool $reverse, int $wrapped): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $short = 'Exact.Release.2026';
        $long = $short.'.'.$token;
        $filename = $long.'.'.$token;
        if ($wrapped) {
            $filename = 'Outer__NZBSPLIT__abcdef01__NZBSPLIT__'.str_replace('.', '_', $wrapped === 1 ? $long : $short).($wrapped === 2 ? '.'.$token : '').'.'.$token;
        }
        $release = $this->pendingPar2Release(['searchname' => $long, 'predb_id' => 0]);
        $hits = [];
        if ($set !== 'long') {
            $hits[] = ['id' => 7, 'title' => $short];
        }
        if ($set !== 'short') {
            $hits[] = ['id' => 8, 'title' => $long];
        }
        if ($reverse) {
            $hits = array_reverse($hits);
        }
        DB::table('predb')->insert($hits);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturn($hits);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->getPreFileNames(['prefile', '1']);
        // Protocol separators belong to the title; only literal dots delimit archive suffixes.
        $expected = $set === 'both' && $wrapped !== 1 ? 0 : ($set === 'long' ? 8 : ($set === 'short' && $wrapped !== 1 ? 7 : 8));
        if ($wrapped === 1 && $set === 'short') {
            $expected = 0;
        }
        self::assertSame($expected, $release->fresh()->predb_id);
        self::assertSame($expected === 7 ? $short : $long, $release->fresh()->searchname);
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
        self::assertSame($expected > 0, $updates->lastCommittedIdentity() !== null);
    }

    public static function aliasRows(): array
    {
        return [
            'control in alias wrapper prefix rejects' => ['Publisher.Asset-GRP.mkv', "Outer\x01__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar", false],
            'pipe in alias wrapper prefix rejects' => ['Publisher.Asset-GRP.mkv', 'Outer||__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar', false],
            'invalid UTF8 in alias wrapper prefix rejects' => ['Publisher.Asset-GRP.mkv', "Outer\xff__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar", false],
            'repeat alias retains intermediate title' => ['Publisher.Asset-GRP.rar.rar', 'Publisher.Asset-GRP.rar.mkv', true],
            'repeat alias encoded intermediate title' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Publisher_Asset-GRP.rar.rar', 'Publisher.Asset-GRP.rar.mkv', true],
            'authoritative ordinary alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.rar', true],
            'authoritative parity alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.rar.par2', true],
            'authoritative long part parity alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.part12345.rar.par2', true],
            'authoritative short 7z alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.7z.01', true],
            'authoritative long bare part alias' => ['Publisher.Asset-GRP.mkv', 'Publisher.Asset-GRP.part12345', true],
            'malformed NZBSPLIT alias cannot become a path' => ['Publisher.Asset-GRP.mkv', 'Outer__NZBSPLIT__abcdef01__NZBSPLIT__Wrong_Release.rar yEnc (1/Publisher.Asset-GRP.mkv', false],
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

    public static function additionalRows(): array
    {
        return [
            'contextual Greek sigma payload' => ['Exact.Release.ΟΣ.mkv', 'Exact.Release.ΟΣ', false, 8],
            'contextual Greek sigma archive' => ['Exact.Release.ΟΣ.rar', 'Exact.Release.ΟΣ', false, 8],
            'contextual Greek sigma lowercase canonical' => ['Exact.Release.ΟΣ.mkv', 'Exact.Release.Ος', false, 8],
            'contextual Greek sigma distinguishes nonfinal canonical' => ['Exact.Release.ΟΣ.mkv', 'Exact.Release.Οσ', false, 0],
            'contextual Greek sigma wrapped parity' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_ΟΣ.vol00+01.par2', 'Exact.Release.ΟΣ', false, 8],
            'contextual Greek sigma wrapped transport' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_ΟΣ.rar yEnc (1)', 'Exact.Release.ΟΣ', false, 8],
            'archive trailer zip' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.zip yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer z01' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.z01 yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer gz' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.gz yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer cab' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.cab yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer tar' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.tar yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer bz2' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.bz2 yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer xz' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.xz yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer lz' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.lz yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer lzma' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.lzma yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer arj' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.arj yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer ace' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.ace yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer arc' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.arc yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer zst' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.zst yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer tgz' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.tgz yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer lz4' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.lz4 yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer enc' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.enc yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'archive trailer rev' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.rev yEnc (1/42)', 'Exact.Release.2026-GRP', false, 8],
            'Unicode fold expands raw prefix' => ['İ.Exact.Release.2026-GRP.rar', 'i̇.Exact.Release.2026-GRP', false, 8],
            'wrapper Unicode fold expands raw prefix' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__İ_Exact_Release_2026-GRP.rar', 'i̇.Exact.Release.2026-GRP', false, 8],
            'PAR2 plus remains suffix byte' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP.vol00+01.par2', 'Exact.Release.2026-GRP', false, 8],
            'underscore title token remains title byte' => ['Outer__NZBSPLIT__abcdef01__NZBSPLIT__Exact_Release_2026-GRP_rar.rar', 'Exact.Release.2026-GRP.rar', true, 8],
            'double archive full literal token' => ['Exact.Release.2026-GRP.rar.mkv', 'Exact.Release.2026-GRP.rar', true, 8],
            'payload literal codec token' => ['Exact.Release.2026-GRP.flac.mkv', 'Exact.Release.2026-GRP.flac', true, 8],
        ];
    }

    #[DataProvider('additionalRows')]
    public function test_casefold_and_raw_suffix_boundaries(string $filename, string $title, bool $shorter, int $expected): void
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

    public static function ambiguityRows(): array
    {
        $rows = [];
        foreach ([false, true] as $alias) {
            foreach ([51, 5001] as $tail) {
                foreach ([false, true] as $reverse) {
                    $rows[(int) $alias.'-'.$tail.'-'.(int) $reverse] = [$alias, $tail, $reverse];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('ambiguityRows')]
    public function test_all_nominated_competitors_survive_suffix_authority(bool $alias, int $tail, bool $reverse): void
    {
        $this->createPar2RenameSchema();
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $short = 'Exact.Release.2026';
        $long = $short.'.rar';
        $filename = $long.'.rar';
        $release = $this->pendingPar2Release(['searchname' => $long, 'predb_id' => 0]);
        $first = ['id' => 7, 'title' => $alias ? 'Canonical.One-GRP' : $short, 'filename' => $alias ? $short.'.mkv' : ''];
        $last = ['id' => 8, 'title' => $alias ? 'Canonical.Two-GRP' : $long, 'filename' => $alias ? $long.'.mkv' : ''];
        DB::table('predb')->insert([$first, $last]);
        $hits = [$first];
        for ($i = 0; $i < $tail; $i++) {
            $hits[] = ['id' => $i + 100, 'title' => 'Unrelated.Fixture.'.$i, 'filename' => ''];
        }
        $hits[] = $last;
        if ($reverse) {
            $hits = array_reverse($hits);
        }
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturn($hits);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->getPreFileNames(['prefile', '1']);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($long, $release->fresh()->searchname);
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
        self::assertNull($updates->lastCommittedIdentity());
    }

    public static function contextualCanonicalPriorityRows(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('contextualCanonicalPriorityRows')]
    public function test_contextual_casefold_keeps_canonical_priority_over_alias(bool $reverse, bool $opaque): void
    {
        $this->createPar2RenameSchema();
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.ΟΣ';
        $filename = $title.'.mkv';
        $release = $this->pendingPar2Release(['searchname' => $opaque ? 'Opaque.Original.Release' : $title, 'predb_id' => 0]);
        $hits = [
            ['id' => 7, 'title' => $title, 'filename' => ''],
            ['id' => 8, 'title' => 'Unrelated.Canonical.Release', 'filename' => $filename.'.mkv'],
        ];
        if ($reverse) {
            $hits = array_reverse($hits);
        }
        DB::table('predb')->insert($hits);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturn($hits);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        (new NameFixingService($updates))->getPreFileNames(['prefile', '1']);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
        self::assertNotNull($updates->lastCommittedIdentity());
    }
}
