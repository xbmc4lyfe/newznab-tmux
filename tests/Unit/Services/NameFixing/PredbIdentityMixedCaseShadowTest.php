<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\Predb\PredbIdentitySchemaAttestation;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PredbIdentityMixedCaseShadowTest extends TestCase
{
    use CertifiedPredbSqlFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCertifiedSqlFixture();
        $migration = require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php');
        $migration->down();
        DB::statement("INSERT INTO main.predb(id,title,filename) VALUES (7,'Synthetic.Pretrigger.Owner-GRP','')");
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            $this->tearDownCertifiedSqlFixture();
        } finally {
            parent::tearDown();
        }
    }

    public function test_empty_mixed_case_source_cannot_certify_false_main_absence(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM main.predb')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM main.predb_identity_revisions')->fetchColumn());
        $before = $this->mainSnapshot($pdo);
        DB::statement("CREATE TEMP TABLE PreDB (id INTEGER PRIMARY KEY NOT NULL, title VARCHAR NOT NULL, filename VARCHAR NOT NULL DEFAULT '')");
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM predb')->fetchColumn());
        $failure = null;
        $observation = null;
        try {
            $rebuild = new PredbIdentityRebuild;
            $start = $rebuild->start($connection);
            $page = $rebuild->chunk($connection, $start['generation']);
            self::assertTrue($page['exhausted']);
            $rebuild->certify($connection, $start['generation']);
            $observation = (new PredbIdentityLookup)->lookup($connection, new OriginalFilenameEvidence('Synthetic.Pretrigger.Owner-GRP.mkv'));
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $failure, 'Shadowed rebuild must reject instead of certifying; observed lookup: '.json_encode($observation));
        self::assertSame('Temporary objects shadow the PreDB identity schema.', $failure->getMessage());
        self::assertSame($before, $this->mainSnapshot($pdo));
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame('unknown', (new PredbIdentityLookup)->lookup($connection, new OriginalFilenameEvidence('Synthetic.Pretrigger.Owner-GRP.mkv'))['status']);
    }

    public static function auxiliaryShadows(): array
    {
        return [
            'state' => ['PrEdB_iDeNtItY_sTaTe', 'predb_identity_state'],
            'revisions' => ['PrEdB_iDeNtItY_rEvIsIoNs', 'predb_identity_revisions'],
            'keys' => ['PrEdB_iDeNtItY_kEyS', 'predb_identity_keys'],
        ];
    }

    #[DataProvider('auxiliaryShadows')]
    public function test_attestation_rejects_mixed_case_auxiliary_shadow_without_mutation(string $shadow, string $main): void
    {
        $pdo = DB::connection()->getPdo();
        $before = $this->mainSnapshot($pdo);
        DB::statement('CREATE TEMP TABLE "'.$shadow.'" AS SELECT * FROM main.'.$main.' WHERE 0');
        $failure = null;
        try {
            (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Temporary objects shadow the PreDB identity schema.', $failure->getMessage());
        self::assertSame($before, $this->mainSnapshot($pdo));
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('auxiliaryShadows')]
    public function test_chunk_rejects_shadow_introduced_after_start_without_mutation(string $shadow, string $main): void
    {
        $connection = DB::connection();
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start($connection);
        $pdo = $connection->getPdo();
        $before = $this->mainSnapshot($pdo);
        DB::statement('CREATE TEMP TABLE "'.$shadow.'" AS SELECT * FROM main.'.$main);
        $failure = null;
        try {
            $rebuild->chunk($connection, $start['generation']);
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Temporary objects shadow the PreDB rebuild schema.', $failure->getMessage());
        self::assertSame($before, $this->mainSnapshot($pdo));
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public static function triggerCaseTargets(): array
    {
        return [
            'main trigger uppercase source target' => [false, 'PREDB'],
            'temporary trigger uppercase auxiliary target' => [true, 'PREDB_IDENTITY_STATE'],
        ];
    }

    #[DataProvider('triggerCaseTargets')]
    public function test_identifier_equivalent_trigger_targets_are_rejected_without_mutation(bool $temporary, string $target): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        if ($temporary) {
            DB::statement('CREATE TEMP TRIGGER unexpected_case_temp AFTER UPDATE ON main.PREDB_IDENTITY_STATE BEGIN SELECT 1; END');
            $metadata = $pdo->query("SELECT tbl_name FROM temp.sqlite_schema WHERE name='unexpected_case_temp'")->fetchColumn();
        } else {
            DB::statement('CREATE TRIGGER unexpected_case_main AFTER INSERT ON PREDB BEGIN SELECT 1; END');
            $metadata = $pdo->query("SELECT tbl_name FROM main.sqlite_schema WHERE name='unexpected_case_main'")->fetchColumn();
        }
        self::assertSame($target, $metadata, 'Fixture must retain the uppercase target spelling in SQLite metadata.');
        $before = $this->mainSnapshot($pdo);
        $schemaBefore = [$pdo->query('SELECT * FROM main.sqlite_schema ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM temp.sqlite_schema ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC)];
        $failure = null;
        try {
            (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame($temporary ? 'Temporary objects shadow the PreDB identity schema.' : 'PreDB identity trigger inventory changed.', $failure->getMessage());
        self::assertSame($before, $this->mainSnapshot($pdo));
        self::assertSame($schemaBefore, [$pdo->query('SELECT * FROM main.sqlite_schema ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM temp.sqlite_schema ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC)]);
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    private function mainSnapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (['predb' => 'id', 'predb_identity_state' => 'id', 'predb_identity_revisions' => 'predb_id', 'predb_identity_keys' => 'generation,kind,key_digest,predb_id'] as $table => $order) {
            $snapshot[$table] = $pdo->query('SELECT * FROM main.'.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
        }

        return $snapshot;
    }
}
