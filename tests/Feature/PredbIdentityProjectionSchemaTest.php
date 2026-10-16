<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PredbIdentityProjectionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('predb', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('filename')->default('');
            $table->integer('searched')->default(0);
        });
        $path = database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php');
        self::assertFileExists($path, 'Authoritative identity schema is not implemented.');
        (require $path)->up();
    }

    public function test_initial_state_is_unready_and_direct_insert_is_tracked(): void
    {
        $state = DB::table('predb_identity_state')->sole();
        self::assertSame(0, (int) $state->ready);
        self::assertSame(0, (int) $state->epoch);
        self::assertNull($state->built_epoch);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $state->generation);
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
        self::assertSame(1, (int) DB::table('predb_identity_state')->value('epoch'));
        $row = DB::table('predb_identity_revisions')->where('predb_id', 7)->sole();
        self::assertSame(1, (int) $row->revision);
        self::assertSame(0, (int) $row->deleted);
        self::assertNull($row->projected_revision);
    }

    public function test_only_byte_changing_identity_updates_invalidate_readiness(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
        DB::table('predb_identity_state')->update(['ready' => true, 'built_epoch' => 1]);
        DB::table('predb')->where('id', 7)->update(['searched' => 1]);
        self::assertSame(1, (int) DB::table('predb_identity_state')->value('ready'));
        DB::table('predb')->where('id', 7)->update(['title' => 'SYNTHETIC.Identity-GRP']);
        self::assertSame(2, (int) DB::table('predb_identity_state')->value('epoch'));
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
        DB::table('predb')->where('id', 7)->update(['filename' => 'Synthetic.Alias-GRP']);
        self::assertSame(3, (int) DB::table('predb_identity_revisions')->value('revision'));
        DB::table('predb')->where('id', 7)->update(['filename' => 'Synthetic.Alias-GRP']);
        self::assertSame(3, (int) DB::table('predb_identity_state')->value('epoch'));
    }

    public function test_id_change_delete_and_reuse_keep_monotonic_tombstones(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
        DB::table('predb')->where('id', 7)->update(['id' => 8]);
        self::assertSame(1, (int) DB::table('predb_identity_revisions')->where('predb_id', 7)->value('deleted'));
        self::assertSame(2, (int) DB::table('predb_identity_revisions')->where('predb_id', 8)->value('revision'));
        DB::table('predb')->where('id', 8)->delete();
        self::assertSame(1, (int) DB::table('predb_identity_revisions')->where('predb_id', 8)->value('deleted'));
        DB::table('predb')->insert(['id' => 8, 'title' => 'Synthetic.Replacement-GRP']);
        $row = DB::table('predb_identity_revisions')->where('predb_id', 8)->sole();
        self::assertSame(4, (int) $row->revision);
        self::assertSame(0, (int) $row->deleted);
        self::assertSame(2, (int) DB::table('predb_identity_revisions')->where('predb_id', 7)->value('revision'));
    }

    public function test_rollback_restores_catalogue_state_and_revision_together(): void
    {
        DB::beginTransaction();
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
        self::assertSame(1, DB::table('predb_identity_revisions')->count());
        DB::rollBack();
        self::assertSame(0, DB::table('predb')->count());
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('epoch'));
    }

    public function test_missing_state_aborts_untracked_source_mutation(): void
    {
        DB::table('predb_identity_state')->delete();
        try {
            DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
            self::fail('Missing certificate state allowed untracked source mutation.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('PreDB identity state unavailable', $exception->getMessage());
        }
        self::assertSame(0, DB::table('predb')->count());
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
    }

    public function test_epoch_exhaustion_aborts_instead_of_reusing_revision_authority(): void
    {
        DB::table('predb_identity_state')->update(['epoch' => PHP_INT_MAX - 1]);
        try {
            DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Identity-GRP']);
            self::fail('Exhausted epoch allowed source mutation.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('PreDB identity epoch exhausted', $exception->getMessage());
        }
        self::assertSame(0, DB::table('predb')->count());
        self::assertSame(PHP_INT_MAX - 1, (int) DB::table('predb_identity_state')->value('epoch'));
    }
}
