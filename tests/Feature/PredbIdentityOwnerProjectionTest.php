<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Predb\PredbIdentityProjector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PredbIdentityOwnerProjectionTest extends TestCase
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
        });
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
    }

    public function test_owner_replacement_is_exact_and_preserves_other_owners_and_generations(): void
    {
        DB::table('predb')->insert([
            ['id' => 7, 'title' => 'Synthetic.Canonical-GRP', 'filename' => 'Synthetic.Alias-GRP.rar.par2'],
            ['id' => 8, 'title' => 'Synthetic.Other-GRP', 'filename' => ''],
        ]);
        $generation = DB::table('predb_identity_state')->value('generation');
        DB::table('predb_identity_keys')->insert([
            $this->key($generation, 7), $this->key($generation, 8), $this->key(str_repeat('f', 32), 7),
        ]);
        $state = (array) DB::table('predb_identity_state')->sole();
        $this->project(7, 1);
        $rows = DB::table('predb_identity_keys')->where('generation', $generation)->where('predb_id', 7)
            ->orderBy('kind')->orderBy('key_digest')->get()->map(static fn (object $row): array => (array) $row)->all();
        $expected = [
            $this->key($generation, 7, 1, 'synthetic.canonical-grp'),
            $this->key($generation, 7, 2, 'synthetic.alias-grp'),
            $this->key($generation, 7, 2, 'synthetic.alias-grp.rar'),
            $this->key($generation, 7, 2, 'synthetic.alias-grp.rar.par2'),
        ];
        usort($expected, static fn (array $a, array $b): int => [$a['kind'], $a['key_digest']] <=> [$b['kind'], $b['key_digest']]);
        self::assertSame($expected, $rows);
        self::assertSame($this->key($generation, 8), (array) DB::table('predb_identity_keys')->where('predb_id', 8)->sole());
        self::assertSame($this->key(str_repeat('f', 32), 7), (array) DB::table('predb_identity_keys')->where('generation', str_repeat('f', 32))->sole());
        self::assertSame($state, (array) DB::table('predb_identity_state')->sole());
        $revision = DB::table('predb_identity_revisions')->where('predb_id', 7)->sole();
        self::assertSame(1, (int) $revision->projected_revision);
        self::assertSame($generation, $revision->projected_generation);
    }

    public function test_tombstone_removes_only_current_owner_keys_and_retains_revision(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        DB::table('predb')->where('id', 7)->delete();
        $this->project(7, 2);
        $this->project(7, 2);
        self::assertSame(0, DB::table('predb_identity_keys')->count());
        $revision = DB::table('predb_identity_revisions')->sole();
        self::assertSame(1, (int) $revision->deleted);
        self::assertSame(2, (int) $revision->projected_revision);
    }

    public function test_failed_projection_does_not_destroy_the_previous_keys(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        $before = DB::table('predb_identity_keys')->get()->all();
        DB::table('predb')->where('id', 7)->update(['filename' => str_repeat('.', 128).'mkv']);
        try {
            $this->project(7, 2);
            self::fail('Unsupported owner was silently projected.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB owner identity cannot be projected completely.', $exception->getMessage());
            self::assertEquals($before, DB::table('predb_identity_keys')->get()->all());
        }
        self::assertNull(DB::table('predb_identity_revisions')->value('projected_revision'));
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
    }

    public function test_stale_revision_cannot_replace_current_keys(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->expectException(RuntimeException::class);
        $this->project(7, 0);
    }

    public function test_rollback_restores_keys_and_projection_marker(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        DB::beginTransaction();
        $generation = DB::table('predb_identity_state')->where('id', 1)->lockForUpdate()->value('generation');
        (new PredbIdentityProjector)->replaceOwner(DB::connection(), $generation, 7, 1);
        self::assertSame(1, DB::table('predb_identity_keys')->count());
        DB::rollBack();
        self::assertSame(0, DB::table('predb_identity_keys')->count());
        self::assertNull(DB::table('predb_identity_revisions')->value('projected_revision'));
    }

    public function test_projector_requires_a_caller_owned_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        (new PredbIdentityProjector)->replaceOwner(DB::connection(), str_repeat('a', 32), 7, 1);
    }

    public function test_repeated_projection_is_idempotent(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        $before = DB::table('predb_identity_keys')->get()->all();
        $this->project(7, 1);
        self::assertEquals($before, DB::table('predb_identity_keys')->get()->all());
        self::assertSame(1, (int) DB::table('predb_identity_revisions')->value('projected_revision'));
    }

    public function test_pretrigger_owner_with_explicit_revision_zero_projects_without_source_dml(): void
    {
        $migration = require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php');
        $migration->down();
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Legacy-GRP']);
        $migration->up();
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
            DB::unprepared('CREATE TRIGGER reject_projection_source_'.strtolower($operation).' BEFORE '.$operation
                ." ON predb BEGIN SELECT RAISE(ABORT, 'Unexpected projection source mutation'); END");
        }
        DB::transaction(static function (): void {
            $generation = DB::table('predb_identity_state')->where('id', 1)->lockForUpdate()->value('generation');
            // A rebuild owns bootstrap; the projector must preserve the explicit baseline revision.
            DB::table('predb_identity_revisions')->insert(['predb_id' => 7, 'revision' => 0, 'deleted' => false]);
            (new PredbIdentityProjector)->replaceOwner(DB::connection(), $generation, 7, 0);
        });
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('epoch'));
        self::assertSame(0, (int) DB::table('predb_identity_revisions')->value('projected_revision'));
        self::assertSame(0, (int) DB::table('predb_identity_keys')->value('source_revision'));
        self::assertSame('Synthetic.Legacy-GRP', DB::table('predb')->value('title'));
    }

    public static function projectionFaults(): array
    {
        return [['delete'], ['insert'], ['marker']];
    }

    #[DataProvider('projectionFaults')]
    public function test_write_fault_rolls_back_owner_replacement(string $operation): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        $before = DB::table('predb_identity_keys')->get()->all();
        DB::table('predb')->where('id', 7)->update(['filename' => 'Synthetic.New.Alias-GRP']);
        $fired = false;
        DB::listen(static function (QueryExecuted $query) use ($operation, &$fired): void {
            $matches = $operation === 'marker'
                ? str_starts_with(strtolower($query->sql), 'update ') && str_contains($query->sql, '"predb_identity_revisions"')
                : str_starts_with(strtolower($query->sql), $operation.' ') && str_contains($query->sql, '"predb_identity_keys"');
            if (! $fired && $matches) {
                $fired = true;
                throw new RuntimeException('Synthetic owner replacement fault.');
            }
        });
        try {
            $this->project(7, 2);
            self::fail('Expected owner replacement fault.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic owner replacement fault.', $exception->getMessage());
        }
        self::assertTrue($fired);
        self::assertEquals($before, DB::table('predb_identity_keys')->get()->all());
        self::assertNull(DB::table('predb_identity_revisions')->value('projected_revision'));
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_wrong_generation_preserves_the_existing_projection(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        $before = DB::table('predb_identity_keys')->get()->all();
        try {
            DB::transaction(static function (): void {
                (new PredbIdentityProjector)->replaceOwner(DB::connection(), str_repeat('f', 32), 7, 1);
            });
            self::fail('Wrong generation was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB projection generation changed.', $exception->getMessage());
        }
        self::assertEquals($before, DB::table('predb_identity_keys')->get()->all());
    }

    public static function inconsistentOwners(): array
    {
        return [['missing revision'], ['live tombstone'], ['absent live owner']];
    }

    #[DataProvider('inconsistentOwners')]
    public function test_inconsistent_owner_does_not_destroy_retained_keys(string $case): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Canonical-GRP']);
        $this->project(7, 1);
        $before = DB::table('predb_identity_keys')->get()->all();
        $revision = 1;
        if ($case === 'missing revision') {
            DB::table('predb_identity_revisions')->delete();
        } elseif ($case === 'live tombstone') {
            DB::table('predb_identity_revisions')->update(['deleted' => true]);
        } else {
            DB::table('predb')->where('id', 7)->delete();
            DB::table('predb_identity_revisions')->update(['deleted' => false]);
            $revision = 2;
        }
        try {
            $this->project(7, $revision);
            self::fail('Inconsistent owner was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB owner revision or presence changed.', $exception->getMessage());
        }
        self::assertEquals($before, DB::table('predb_identity_keys')->get()->all());
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
    }

    private function project(int $id, int $revision): void
    {
        DB::transaction(static function () use ($id, $revision): void {
            $generation = DB::table('predb_identity_state')->where('id', 1)->lockForUpdate()->value('generation');
            (new PredbIdentityProjector)->replaceOwner(DB::connection(), $generation, $id, $revision);
        });
    }

    private function key(string $generation, int $id, int $kind = 1, string $value = 'obsolete'): array
    {
        return ['generation' => $generation, 'kind' => $kind, 'key_digest' => hash('sha256', $value), 'predb_id' => $id, 'source_revision' => 1];
    }
}
