<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PredbIdentityRebuildTest extends TestCase
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
    }

    private function install(): void
    {
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
    }

    public function test_sparse_union_bootstraps_legacy_rows_and_retains_tombstones_without_certifying(): void
    {
        foreach ([1, 40, 900] as $id) {
            DB::table('predb')->insert(['id' => $id, 'title' => 'Synthetic.Legacy.'.$id.'-GRP']);
        }
        $this->install();
        DB::table('predb')->insert(['id' => 3, 'title' => 'Synthetic.Deleted-GRP']);
        DB::table('predb')->where('id', 3)->delete();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        self::assertSame(900, $start['until_id']);
        $cursors = [];
        do {
            $page = $service->chunk(DB::connection(), $start['generation'], 1);
            $cursors[] = $page['cursor'];
        } while (! $page['exhausted']);
        self::assertSame([1, 3, 40, 900], $cursors);
        self::assertSame([1, 40, 900], DB::table('predb_identity_keys')->orderBy('predb_id')->pluck('predb_id')->all());
        self::assertSame(2, (int) DB::table('predb_identity_revisions')->where('predb_id', 1)->value('revision'));
        self::assertSame(1, (int) DB::table('predb_identity_revisions')->where('predb_id', 3)->value('deleted'));
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
        self::assertNull(DB::table('predb_identity_state')->value('built_epoch'));
    }

    public function test_failed_chunk_rolls_back_earlier_owner_bootstrap_keys_and_cursor(): void
    {
        DB::table('predb')->insert([
            ['id' => 7, 'title' => 'Synthetic.Valid-GRP', 'filename' => ''],
            ['id' => 8, 'title' => 'Synthetic.Unsupported-GRP', 'filename' => str_repeat('.', 128).'mkv'],
        ]);
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        try {
            $service->chunk(DB::connection(), $start['generation'], 2);
            self::fail('Unsupported owner allowed partial progress.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot be projected completely', $exception->getMessage());
        }
        self::assertSame(0, DB::table('predb_identity_keys')->count());
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('rebuild_cursor'));
    }

    public static function lateOwners(): array
    {
        return [[1], [99]];
    }

    #[DataProvider('lateOwners')]
    public function test_identity_mutation_requires_a_fresh_generation(int $lateId): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Legacy-GRP']);
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $service->chunk(DB::connection(), $start['generation'], 1);
        DB::table('predb')->insert(['id' => $lateId, 'title' => 'Synthetic.Late-GRP']);
        try {
            $service->chunk(DB::connection(), $start['generation'], 1);
            self::fail('A changed epoch resumed the old build.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB rebuild generation, epoch or loaded policy changed.', $exception->getMessage());
        }
        $restart = $service->start(DB::connection());
        self::assertNotSame($start['generation'], $restart['generation']);
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('rebuild_cursor'));
        self::assertSame(1, (int) DB::table('predb_identity_state')->value('epoch'));
    }

    public static function invalidOwners(): array
    {
        return [[-1], [0], [4294967296]];
    }

    #[DataProvider('invalidOwners')]
    public function test_unsupported_owner_domain_cannot_be_skipped_by_the_cursor(int $id): void
    {
        DB::table('predb')->insert(['id' => $id, 'title' => 'Synthetic.Unsupported-GRP']);
        $this->install();
        $this->expectException(RuntimeException::class);
        (new PredbIdentityRebuild)->start(DB::connection());
    }

    public function test_ambient_transaction_is_not_adopted_or_rolled_back(): void
    {
        $this->install();
        DB::beginTransaction();
        try {
            (new PredbIdentityRebuild)->start(DB::connection());
            self::fail('Ambient transaction was adopted.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB rebuild requires its own supported transaction.', $exception->getMessage());
            self::assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public function test_nullable_int_primary_key_cannot_hide_an_owner_from_paging(): void
    {
        Schema::drop('predb');
        DB::unprepared("CREATE TABLE predb (id INT PRIMARY KEY, title VARCHAR NOT NULL, filename VARCHAR NOT NULL DEFAULT '')");
        DB::table('predb')->insert(['id' => null, 'title' => 'Synthetic.Hidden-GRP']);
        $this->install();
        $before = (array) DB::table('predb_identity_state')->sole();
        $failure = null;
        try {
            (new PredbIdentityRebuild)->start(DB::connection());
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'A nullable non-rowid owner was silently skipped.');
        self::assertSame($before, (array) DB::table('predb_identity_state')->sole());
    }

    public function test_raw_pdo_transaction_is_not_adopted_or_rolled_back(): void
    {
        $this->install();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            self::assertSame(0, DB::transactionLevel());
            (new PredbIdentityRebuild)->start(DB::connection());
            self::fail('Raw PDO transaction was adopted.');
        } catch (RuntimeException $exception) {
            self::assertSame('PreDB rebuild requires its own supported transaction.', $exception->getMessage());
            self::assertTrue($pdo->inTransaction());
            self::assertSame(0, DB::transactionLevel());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_empty_inventory_requires_explicit_attested_certification(): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $page = $service->chunk(DB::connection(), $start['generation']);
        self::assertSame(0, $page['processed']);
        self::assertTrue($page['exhausted']);
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
        $service->certify(DB::connection(), $start['generation']);
        self::assertSame(1, (int) DB::table('predb_identity_state')->value('ready'));
        self::assertSame(0, DB::table('predb_identity_state')->value('built_epoch'));
        self::assertSame(0, DB::table('predb_identity_keys')->count());
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
    }

    public function test_explicit_certificate_binds_the_completed_frozen_generation(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Legacy-GRP']);
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $page = $service->chunk(DB::connection(), $start['generation']);
        self::assertTrue($page['exhausted']);
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
        $before = [DB::table('predb')->get()->all(), DB::table('predb_identity_keys')->get()->all(), DB::table('predb_identity_revisions')->get()->all()];
        $policy = DB::table('predb_identity_state')->value('policy_fingerprint');
        $service->certify(DB::connection(), $start['generation']);
        self::assertEquals($before, [DB::table('predb')->get()->all(), DB::table('predb_identity_keys')->get()->all(), DB::table('predb_identity_revisions')->get()->all()]);
        $state = DB::table('predb_identity_state')->sole();
        self::assertSame(1, (int) $state->ready);
        self::assertSame(0, $state->built_epoch);
        self::assertSame($policy, $state->policy_fingerprint);
        self::assertSame(0, $state->rebuild_cursor);
        self::assertSame($start['generation'], $state->generation);
        self::assertNull($state->rebuild_epoch);
        self::assertNull($state->rebuild_until_id);
        self::assertSame(0, DB::table('predb_identity_revisions')->value('projected_revision'));
        DB::table('predb')->insert(['id' => 8, 'title' => 'Synthetic.New-GRP']);
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
    }

    public function test_unprocessed_owner_cannot_be_certified(): void
    {
        DB::table('predb')->insert([
            ['id' => 7, 'title' => 'Synthetic.First-GRP'], ['id' => 8, 'title' => 'Synthetic.Second-GRP'],
        ]);
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $service->chunk(DB::connection(), $start['generation'], 1);
        $this->assertCertificationRejected($service, $start['generation'], 'PreDB rebuild inventory is incomplete.');
    }

    public function test_changed_trigger_prevents_certification_after_successful_traversal(): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $service->chunk(DB::connection(), $start['generation']);
        DB::unprepared('DROP TRIGGER predb_identity_insert');
        $this->assertCertificationRejected($service, $start['generation'], 'PreDB identity trigger inventory changed.');
    }

    public function test_rebuild_cannot_start_under_an_unmodeled_helper_trigger(): void
    {
        $this->install();
        DB::unprepared('CREATE TRIGGER unexpected_key_trigger AFTER INSERT ON predb_identity_keys BEGIN DELETE FROM predb_identity_keys; END');
        $before = (array) DB::table('predb_identity_state')->sole();
        $failure = null;
        try {
            (new PredbIdentityRebuild)->start(DB::connection());
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Unsupported initial helper machinery entered a rebuild.');
        self::assertSame('PreDB identity trigger inventory changed.', $failure->getMessage());
        self::assertSame($before, (array) DB::table('predb_identity_state')->sole());
    }

    #[DataProvider('lateOwners')]
    public function test_mutation_after_exhaustion_prevents_certification(int $lateId): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Legacy-GRP']);
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        self::assertTrue($service->chunk(DB::connection(), $start['generation'])['exhausted']);
        DB::table('predb')->insert(['id' => $lateId, 'title' => 'Synthetic.Late-GRP']);
        $this->assertCertificationRejected($service, $start['generation'], 'PreDB rebuild generation, epoch or loaded policy changed.');
    }

    public function test_wrong_generation_cannot_certify_an_exhausted_build(): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $wrong = ($start['generation'][0] === 'a' ? 'b' : 'a').substr($start['generation'], 1);
        $this->assertCertificationRejected($service, $wrong, 'PreDB rebuild generation, epoch or loaded policy changed.');
    }

    public function test_changed_policy_cannot_certify_an_exhausted_build(): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $policy = DB::table('predb_identity_state')->value('policy_fingerprint');
        DB::table('predb_identity_state')->update(['policy_fingerprint' => ($policy[0] === 'a' ? 'b' : 'a').substr($policy, 1)]);
        $this->assertCertificationRejected($service, $start['generation'], 'PreDB rebuild generation, epoch or loaded policy changed.');
    }

    public static function ambientTransactions(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('ambientTransactions')]
    public function test_certification_preserves_ambient_transaction_ownership(bool $raw): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $pdo = DB::connection()->getPdo();
        if ($raw) {
            $pdo->beginTransaction();
        } else {
            DB::beginTransaction();
        }
        try {
            $this->assertCertificationRejected($service, $start['generation'], 'PreDB rebuild requires its own supported transaction.');
            self::assertSame($raw ? 0 : 1, DB::transactionLevel());
            self::assertTrue($pdo->inTransaction());
        } finally {
            if ($raw && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (! $raw && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function rebuildOperations(): array
    {
        return [['start'], ['chunk'], ['certify']];
    }

    #[DataProvider('rebuildOperations')]
    public function test_runtime_policy_change_during_a_rebuild_operation_rolls_back_its_state(string $operation): void
    {
        $this->install();
        $service = new PredbIdentityRebuild;
        $start = $service->start(DB::connection());
        $service->chunk(DB::connection(), $start['generation']);
        $before = (array) DB::table('predb_identity_state')->sole();
        $jit = ini_get('pcre.jit');
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed, $jit): void {
            if ($armed && str_contains($query->sql, 'from "predb_identity_state"')) {
                $armed = false;
                ini_set('pcre.jit', $jit === '1' ? '0' : '1');
            }
        });
        $failure = null;
        try {
            if ($operation === 'start') {
                $service->start(DB::connection());
            } elseif ($operation === 'chunk') {
                $service->chunk(DB::connection(), $start['generation']);
            } else {
                $service->certify(DB::connection(), $start['generation']);
            }
        } catch (RuntimeException $exception) {
            $failure = $exception;
        } finally {
            $armed = false;
            ini_set('pcre.jit', $jit);
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('PreDB rebuild loaded policy changed during the transaction.', $failure->getMessage());
        self::assertSame($before, (array) DB::table('predb_identity_state')->sole());
    }

    private function assertCertificationRejected(PredbIdentityRebuild $service, string $generation, string $message): void
    {
        $snapshot = static fn (): array => array_map(
            static fn (string $table): array => DB::table($table)->orderBy($table === 'predb_identity_keys' || $table === 'predb_identity_revisions' ? 'predb_id' : 'id')->when($table === 'predb_identity_keys', static fn ($query) => $query->orderBy('generation')->orderBy('kind')->orderBy('key_digest'))->get()->map(static fn (object $row): array => (array) $row)->all(),
            ['predb', 'predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'],
        );
        $before = $snapshot();
        $failure = null;
        try {
            $service->certify(DB::connection(), $generation);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame($message, $failure->getMessage());
        self::assertSame($before, $snapshot());
        self::assertSame(0, (int) DB::table('predb_identity_state')->value('ready'));
    }
}
