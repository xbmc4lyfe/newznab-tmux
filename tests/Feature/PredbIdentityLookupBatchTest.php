<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbIdentityLookupBatchTest extends TestCase
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
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        DB::table('predb')->insert([
            ['id' => 1, 'title' => 'Synthetic.First-GRP', 'filename' => ''],
            ['id' => 2, 'title' => 'Synthetic.Second-GRP', 'filename' => 'Synthetic.Alias-GRP.rar'],
        ]);
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        $rebuild->chunk(DB::connection(), $start['generation']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    public function test_batch_keeps_order_and_duplicate_observations_with_one_authority_envelope(): void
    {
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $filenames = ['Synthetic.First-GRP.mkv', 'Synthetic.Alias-GRP.mkv', 'Synthetic.Missing-GRP.mkv', 'Synthetic.First-GRP.mkv'];
        $results = (new PredbIdentityLookup)->lookupMany(DB::connection(), array_map(
            static fn (string $name): OriginalFilenameEvidence => new OriginalFilenameEvidence($name),
            $filenames
        ));
        self::assertSame(['unique', 'unique', 'absent', 'unique'], array_column($results, 'status'));
        self::assertSame(1, $results[0]['candidate']['id']);
        self::assertSame(2, $results[1]['candidate']['id']);
        self::assertSame('canonical', $results[0]['tier']);
        self::assertSame('alias', $results[1]['tier']);
        self::assertSame('Synthetic.First-GRP', $results[0]['candidate']['title']);
        self::assertSame('Synthetic.Second-GRP', $results[1]['candidate']['title']);
        self::assertSame($results[0], $results[3]);
        self::assertCount(2, array_filter($queries, static fn (string $sql): bool => str_contains($sql, ' from main.predb_identity_state limit 2')));
        self::assertLessThanOrEqual(6, count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from main.predb_identity_keys k'))));
        self::assertLessThanOrEqual(50, count($queries), 'Four observations must share the initial/final fence.');
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function transactionOwners(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('transactionOwners')]
    public function test_final_epoch_change_invalidates_all_provisional_results_without_adopting_caller_transaction(bool $ambient): void
    {
        $before = (array) DB::table('predb_identity_state')->sole();
        $mutated = false;
        DB::listen(static function ($event) use (&$mutated): void {
            if (! $mutated && str_contains($event->sql, 'from main.predb_identity_keys k')) {
                $mutated = true;
                DB::table('predb')->insert(['id' => 99, 'title' => 'Synthetic.First-GRP', 'filename' => '']);
            }
        });
        if ($ambient) {
            DB::beginTransaction();
        }
        try {
            $results = (new PredbIdentityLookup)->lookupMany(DB::connection(), [
                new OriginalFilenameEvidence('Synthetic.First-GRP.mkv'),
                new OriginalFilenameEvidence('Synthetic.Alias-GRP.mkv'),
            ]);
            self::assertTrue($mutated);
            self::assertSame([['status' => 'unknown'], ['status' => 'unknown']], $results);
            self::assertSame($ambient ? 1 : 0, DB::transactionLevel());
            self::assertSame($ambient, DB::connection()->getPdo()->inTransaction());
            self::assertSame($ambient ? 1 : 0, DB::table('predb')->where('id', 99)->count());
        } finally {
            if ($ambient) {
                DB::rollBack();
            }
        }
        self::assertSame($before, (array) DB::table('predb_identity_state')->sole());
    }

    public function test_empty_batch_does_not_claim_catalogue_absence_or_issue_queries(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        self::assertSame([], (new PredbIdentityLookup)->lookupMany(DB::connection(), []));
        self::assertSame(0, $queries);
    }

    public function test_single_lookup_and_single_observation_batch_have_the_same_witness(): void
    {
        $service = new PredbIdentityLookup;
        $evidence = new OriginalFilenameEvidence('Synthetic.First-GRP.mkv');
        self::assertSame([$service->lookup(DB::connection(), $evidence)], $service->lookupMany(DB::connection(), [$evidence]));
    }

    public function test_successful_ambient_batch_preserves_the_callers_write_and_rollback(): void
    {
        DB::beginTransaction();
        try {
            DB::table('predb')->where('id', 1)->update(['searched' => 1]);
            $results = (new PredbIdentityLookup)->lookupMany(DB::connection(), [new OriginalFilenameEvidence('Synthetic.First-GRP.mkv')]);
            self::assertSame('unique', $results[0]['status']);
            self::assertSame(1, DB::transactionLevel());
            self::assertTrue(DB::connection()->getPdo()->inTransaction());
            self::assertSame(1, DB::table('predb')->where('id', 1)->value('searched'));
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::table('predb')->where('id', 1)->value('searched'));
    }

    public static function unknownPositions(): array
    {
        return [[0], [1], [2]];
    }

    #[DataProvider('unknownPositions')]
    public function test_unknown_observation_at_any_position_invalidates_the_whole_batch(int $position): void
    {
        $evidence = [new OriginalFilenameEvidence('Synthetic.First-GRP.mkv'), new OriginalFilenameEvidence('Synthetic.Alias-GRP.mkv')];
        array_splice($evidence, $position, 0, [new OriginalFilenameEvidence(str_repeat('X', 8193))]);
        self::assertSame(array_fill(0, 3, ['status' => 'unknown']), (new PredbIdentityLookup)->lookupMany(DB::connection(), $evidence));
    }
}
