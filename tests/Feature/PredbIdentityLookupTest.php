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

final class PredbIdentityLookupTest extends TestCase
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

    private function certify(array $rows): void
    {
        DB::table('predb')->insert($rows);
        $this->rebuildCurrentCatalogue();
    }

    private function rebuildCurrentCatalogue(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    private function lookup(string $filename): array
    {
        return (new PredbIdentityLookup)->lookup(DB::connection(), new OriginalFilenameEvidence($filename));
    }

    public function test_unready_catalogue_is_unknown_and_cannot_prove_absence(): void
    {
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_unique_canonical_and_absence_use_certified_sql_inventory(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        $result = $this->lookup('Synthetic.Release-GRP.mkv');
        self::assertSame('unique', $result['status']);
        self::assertSame(1, $result['candidate']['id']);
        self::assertSame('canonical', $result['tier']);
        self::assertSame('absent', $this->lookup('Synthetic.Missing-GRP.mkv')['status']);
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_two_owner_ids_with_same_title_are_ambiguous(): void
    {
        $this->certify([
            ['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => ''],
            ['id' => 2, 'title' => 'synthetic.release-grp', 'filename' => ''],
        ]);
        self::assertSame('ambiguous', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_alias_is_considered_only_after_complete_canonical_absence(): void
    {
        $this->certify([
            ['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => ''],
            ['id' => 2, 'title' => 'Synthetic.Other-GRP', 'filename' => 'Synthetic.Release-GRP.rar'],
        ]);
        $result = $this->lookup('Synthetic.Release-GRP.mkv');
        self::assertSame('unique', $result['status']);
        self::assertSame(1, $result['candidate']['id']);
        DB::table('predb_identity_revisions')->where('predb_id', 2)->update(['projected_revision' => null]);
        self::assertSame('unique', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_alias_only_owner_is_accepted_with_current_literal_evidence(): void
    {
        $this->certify([['id' => 2, 'title' => 'Synthetic.Other-GRP', 'filename' => 'Synthetic.Release-GRP.rar']]);
        $result = $this->lookup('Synthetic.Release-GRP.mkv');
        self::assertSame('unique', $result['status']);
        self::assertSame(2, $result['candidate']['id']);
        self::assertSame('alias', $result['tier']);
    }

    public function test_stale_nominated_competitor_is_unknown_before_literal_filtering(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        DB::table('predb_identity_revisions')->where('predb_id', 1)->update(['projected_revision' => null]);
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_source_mutation_invalidates_prior_certificate(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        DB::table('predb')->insert(['id' => 2, 'title' => 'Synthetic.Release-GRP']);
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_final_state_query_cannot_change_runtime_policy_after_the_fence(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        $original = ini_get('pcre.jit');
        $reads = 0;
        DB::listen(static function ($event) use (&$reads, $original): void {
            if (str_contains($event->sql, ' from main.predb_identity_state limit 2') && ++$reads === 2) {
                ini_set('pcre.jit', $original === '1' ? '0' : '1');
            }
        });
        try {
            self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        } finally {
            ini_set('pcre.jit', $original);
        }
    }

    public function test_missing_owner_revision_and_corrupt_digest_are_not_filtered_to_absence(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Other-GRP', 'filename' => '']]);
        $generation = DB::table('predb_identity_state')->value('generation');
        DB::table('predb_identity_keys')->insert(['generation' => $generation, 'kind' => 1,
            'key_digest' => hash('sha256', 'synthetic.release-grp'), 'predb_id' => 1, 'source_revision' => 1]);
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        DB::table('predb_identity_revisions')->where('predb_id', 1)->delete();
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_nomination_limit_is_global_for_each_tier_and_alias_is_not_read_after_canonical_success(): void
    {
        $rows = [];
        for ($id = 1; $id <= 1000; $id++) {
            $rows[] = ['id' => $id, 'title' => 'Synthetic.Release-GRP', 'filename' => ''];
        }
        $this->certify($rows);
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        DB::table('predb')->where('id', 1000)->delete();
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        $this->rebuildCurrentCatalogue();
        self::assertSame('ambiguous', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        DB::table('predb')->where('id', '>', 1)->delete();
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        $this->rebuildCurrentCatalogue();
        $aliasQueries = 0;
        DB::listen(static function ($event) use (&$aliasQueries): void {
            if (str_contains($event->sql, 'k.kind = ?') && ($event->bindings[1] ?? null) === 2) {
                $aliasQueries++;
            }
        });
        self::assertSame('unique', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        self::assertSame(0, $aliasQueries);
    }

    public function test_corrupt_helper_text_is_bounded_in_the_sql_result_before_php_validation(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        DB::table('predb_identity_revisions')->where('predb_id', 1)->update(['projected_generation' => str_repeat('x', 20000), 'projected_revision' => str_repeat('x', 20000)]);
        $query = null;
        DB::listen(static function ($event) use (&$query): void {
            if (str_contains($event->sql, 'from main.predb_identity_keys k')) {
                $query = [$event->sql, $event->bindings];
            }
        });
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        self::assertNotNull($query);
        $rows = DB::connection()->select($query[0], $query[1], false);
        self::assertLessThanOrEqual(33, strlen($rows[0]->projected_generation));
        self::assertNull($rows[0]->projected_revision);
    }

    public function test_ambient_transaction_is_preserved_and_raw_pdo_transaction_is_not_adopted(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        DB::beginTransaction();
        try {
            self::assertSame('unique', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
            self::assertSame(1, DB::transactionLevel());
            self::assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
            self::assertSame(0, DB::transactionLevel());
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_alias_overflow_does_not_veto_unique_canonical_owner(): void
    {
        $rows = [['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']];
        for ($id = 2; $id <= 1001; $id++) {
            $rows[] = ['id' => $id, 'title' => 'Synthetic.Other.'.$id.'-GRP', 'filename' => 'Synthetic.Release-GRP.rar'];
        }
        $this->certify($rows);
        self::assertSame('unique', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        DB::table('predb_identity_keys')->where('predb_id', 1)->delete();
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
    }

    public function test_corrupt_state_text_is_not_materialized_without_a_byte_bound(): void
    {
        $this->certify([['id' => 1, 'title' => 'Synthetic.Release-GRP', 'filename' => '']]);
        DB::table('predb_identity_state')->update(['generation' => str_repeat('x', 20000), 'epoch' => str_repeat('x', 20000)]);
        $query = null;
        DB::listen(static function ($event) use (&$query): void {
            if (str_contains($event->sql, ' from main.predb_identity_state limit 2')) {
                $query = [$event->sql, $event->bindings];
            }
        });
        self::assertSame('unknown', $this->lookup('Synthetic.Release-GRP.mkv')['status']);
        self::assertNotNull($query);
        $rows = DB::connection()->select($query[0], $query[1], false);
        self::assertLessThanOrEqual(33, strlen($rows[0]->generation));
        self::assertNull($rows[0]->epoch);
    }

    public static function rejectedNominations(): array
    {
        return [['ΟΣ.rar.par2', 'Canonical.Greek-GRP', 'οσ.rar'],
            ['١٢.mkv', 'Canonical.Numeric-GRP', '١٢.rar'],
            ['Ⅻ.mkv', 'Canonical.Numeric-GRP', 'Ⅻ.rar']];
    }

    #[DataProvider('rejectedNominations')]
    public function test_real_nomination_false_positive_is_rejected_but_stale_owner_still_blocks_absence(string $filename, string $title, string $alias): void
    {
        $evidence = new OriginalFilenameEvidence($filename);
        self::assertNotEmpty(array_intersect($evidence->canonicalNominationKeys(), (new OriginalFilenameEvidence($alias))->canonicalNominationKeys()));
        self::assertFalse($evidence->accepts($title, $alias));
        $this->certify([['id' => 1, 'title' => $title, 'filename' => $alias]]);
        self::assertSame('absent', $this->lookup($filename)['status']);
        DB::table('predb_identity_revisions')->where('predb_id', 1)->update(['projected_revision' => null]);
        self::assertSame('unknown', $this->lookup($filename)['status']);
    }
}
