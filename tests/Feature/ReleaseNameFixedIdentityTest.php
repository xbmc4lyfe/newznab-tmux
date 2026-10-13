<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Listeners\RecategorizeReleaseAfterNameFix;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\Par2PacketAccumulator;
use App\Support\Par2CandidateProgress;
use App\Support\Par2RenameProgress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class ReleaseNameFixedIdentityTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const TITLE = 'Exact.Release.2026-GROUP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        if (! Schema::hasTable('par2_rename_candidate_progress')) {
            (require base_path('database/migrations/2026_10_11_230258_create_par2_rename_candidate_progress_table.php'))->up();
        }
        DB::statement('PRAGMA foreign_keys = ON');
    }

    #[DataProvider('identityChanges')]
    public function test_captured_event_rejects_replacement_before_categorization(string $column, ?string $value): void
    {
        $release = $this->pendingPar2Release(['name' => 'Subject', 'searchname' => self::TITLE]);
        $event = $this->event($release);
        DB::table('releases')->where('id', $release->id)->update([$column => $value]);
        $categorization = $this->createMock(CategorizationService::class);
        $categorization->expects($this->never())->method('determineCategory');

        (new RecategorizeReleaseAfterNameFix($categorization))->handle($event);

        self::assertSame($release->categories_id, $release->fresh()->categories_id);
        self::assertSame($value, $release->fresh()->getAttribute($column));
    }

    #[DataProvider('identityChanges')]
    public function test_category_write_rechecks_identity_after_categorization(string $column, ?string $value): void
    {
        $release = $this->pendingPar2Release(['name' => 'Subject', 'searchname' => self::TITLE]);
        $categorization = $this->createMock(CategorizationService::class);
        $categorization->expects($this->once())->method('determineCategory')->willReturnCallback(static function () use ($release, $column, $value): array {
            DB::table('releases')->where('id', $release->id)->update([$column => $value]);

            return ['categories_id' => 2000];
        });

        (new RecategorizeReleaseAfterNameFix($categorization))->handle($this->event($release));

        self::assertSame($release->categories_id, $release->fresh()->categories_id);
        self::assertSame(0, $release->fresh()->iscategorized);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function identityChanges(): iterable
    {
        yield 'replacement GUID' => ['guid', 'replacement-guid'];
        yield 'case-only subject' => ['name', 'subject'];
        yield 'null subject' => ['name', null];
        yield 'case-only new name' => ['searchname', 'exact.Release.2026-GROUP'];
        yield 'replacement new name' => ['searchname', 'Other.Release.2026-GROUP'];
    }

    #[DataProvider('eventVersions')]
    public function test_current_events_recategorize_with_legacy_constructor_compatibility(bool $captured): void
    {
        $release = $this->pendingPar2Release(['name' => 'Subject', 'searchname' => self::TITLE]);
        $categorization = $this->createMock(CategorizationService::class);
        $categorization->expects($this->once())->method('determineCategory')->with(1, self::TITLE, (string) $release->fromname)->willReturn(['categories_id' => 2000]);
        $event = $captured ? $this->event($release) : new ReleaseNameFixed($release->id, 'Old.Name', self::TITLE, $release->categories_id, 1);

        (new RecategorizeReleaseAfterNameFix($categorization))->handle($event);

        self::assertSame(2000, $release->fresh()->categories_id);
        self::assertSame(1, $release->fresh()->iscategorized);
    }

    /** @return iterable<string, array{bool}> */
    public static function eventVersions(): iterable
    {
        yield 'captured snapshot' => [true];
        yield 'legacy constructor' => [false];
    }

    public function test_legacy_event_does_not_apply_to_a_different_current_title(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Replacement.Name']);
        $categorization = $this->createMock(CategorizationService::class);
        $categorization->expects($this->never())->method('determineCategory');
        (new RecategorizeReleaseAfterNameFix($categorization))->handle(new ReleaseNameFixed($release->id, 'Old.Name', self::TITLE, $release->categories_id, 1));
        self::assertSame($release->categories_id, $release->fresh()->categories_id);
    }

    public function test_legacy_event_also_protects_read_identity_on_update(): void
    {
        $release = $this->pendingPar2Release(['searchname' => self::TITLE]);
        $categorization = $this->createStub(CategorizationService::class);
        $categorization->method('determineCategory')->willReturnCallback(static function () use ($release): array {
            DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement']);

            return ['categories_id' => 2000];
        });
        (new RecategorizeReleaseAfterNameFix($categorization))->handle(new ReleaseNameFixed($release->id, 'Old.Name', self::TITLE, $release->categories_id, 1));
        self::assertSame($release->categories_id, $release->fresh()->categories_id);
    }

    #[DataProvider('identityChanges')]
    public function test_completed_cleanup_rejects_replaced_identity(string $column, ?string $value): void
    {
        $release = $this->checkpointRelease();
        $snapshot = $this->snapshot($release);
        DB::table('releases')->where('id', $release->id)->update(['predb_id' => 9, $column => $value]);
        self::assertFalse(Par2RenameProgress::forgetCompletedSnapshot($snapshot));
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    #[DataProvider('terminalFlags')]
    public function test_completed_cleanup_requires_a_terminal_state(string $column, int $value, bool $terminal): void
    {
        $release = $this->checkpointRelease();
        DB::table('releases')->where('id', $release->id)->update([$column => $value]);
        self::assertSame($terminal, Par2RenameProgress::forgetCompletedSnapshot($this->snapshot($release)));
        self::assertSame($terminal ? 0 : 1, DB::table('par2_rename_candidate_progress')->count());
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function terminalFlags(): iterable
    {
        yield 'exact link' => ['predb_id', 9, true];
        yield 'renamed' => ['isrenamed', 1, true];
        yield 'exhausted' => ['proc_par2', 1, true];
        yield 'pending' => ['predb_id', 0, false];
        yield 'retryable' => ['proc_par2', -1, false];
    }

    #[DataProvider('partialTerminalSchemas')]
    public function test_cleanup_requires_present_terminal_evidence_in_partial_schemas(bool $removeEveryFlag): void
    {
        $release = $this->checkpointRelease();
        DB::table('releases')->where('id', $release->id)->update(['predb_id' => 9]);
        Schema::table('releases', static function (Blueprint $table) use ($removeEveryFlag): void {
            $table->dropColumn($removeEveryFlag ? ['predb_id', 'isrenamed', 'proc_par2'] : ['proc_par2']);
        });
        self::assertSame(! $removeEveryFlag, Par2RenameProgress::forgetCompletedSnapshot($this->snapshot($release)));
        self::assertSame($removeEveryFlag ? 1 : 0, DB::table('par2_rename_candidate_progress')->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function partialTerminalSchemas(): iterable
    {
        yield 'missing optional proc flag' => [false];
        yield 'missing every terminal flag' => [true];
    }

    public function test_missing_nullable_identity_field_is_not_treated_as_a_null_match(): void
    {
        $release = $this->checkpointRelease();
        DB::table('releases')->where('id', $release->id)->update(['name' => null, 'predb_id' => 9]);
        $snapshot = $this->snapshot($release->fresh());
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropColumn('name');
        });
        self::assertFalse(Par2RenameProgress::forgetCompletedSnapshot($snapshot));
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_stale_completed_cleanup_preserves_reset_candidate_created_before_commit(): void
    {
        $release = $this->checkpointRelease();
        $snapshot = $this->snapshot($release);
        DB::beginTransaction();
        DB::table('releases')->where('id', $release->id)->update(['isrenamed' => 1]);
        DB::afterCommit(static fn (): bool => Par2RenameProgress::forgetCompletedSnapshot($snapshot));
        DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement', 'name' => 'new subject', 'searchname' => 'new subject', 'isrenamed' => 0]);
        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release->fresh()), str_repeat('c', 64), str_repeat('d', 64), 0, [], (new Par2PacketAccumulator)->exportState(), 1, 10000, static fn (): bool => true));
        DB::commit();
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(str_repeat('c', 64), DB::table('par2_rename_progress')->value('signature'));
    }

    public function test_cleanup_in_nested_transaction_rolls_back_with_parent(): void
    {
        $release = $this->checkpointRelease();
        DB::beginTransaction();
        DB::table('releases')->where('id', $release->id)->update(['predb_id' => 9]);
        self::assertTrue(Par2RenameProgress::forgetCompletedSnapshot($this->snapshot($release)));
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        DB::rollBack();
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(0, $release->fresh()->predb_id);
    }

    private function checkpointRelease(): Release
    {
        $release = $this->pendingPar2Release(['name' => 'Subject', 'searchname' => self::TITLE]);
        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release), str_repeat('a', 64), str_repeat('b', 64), 0, [], (new Par2PacketAccumulator)->exportState(), 1, 10000, static fn (): bool => true));

        return $release;
    }

    /** @return array{id:int,guid:string,name:?string,searchname:?string} */
    private function snapshot(Release $release): array
    {
        return ['id' => $release->id, 'guid' => $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
    }

    private function event(Release $release): ReleaseNameFixed
    {
        return new ReleaseNameFixed($release->id, 'Old.Name', self::TITLE, $release->categories_id, 1, expectedSnapshot: $this->snapshot($release));
    }
}
