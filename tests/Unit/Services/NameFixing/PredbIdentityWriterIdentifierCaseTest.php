<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\PredbIdentityWriter;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class PredbIdentityWriterIdentifierCaseTest extends TestCase
{
    use CertifiedPredbSqlFixture;

    protected function setUp(): void
    {
        $this->setUpCertifiedSqlFixture();
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->down();
        foreach (['source', 'category', 'size', 'files', 'predate', 'nukereason'] as $column) {
            DB::statement('ALTER TABLE predb ADD COLUMN '.$column.' TEXT NULL');
        }
        DB::statement('ALTER TABLE predb ADD COLUMN nuked INTEGER NOT NULL DEFAULT 0');
    }

    protected function tearDown(): void
    {
        $this->tearDownCertifiedSqlFixture();
    }

    /** @return array<string, array{string, string}> */
    public static function identifierCases(): array
    {
        return [
            'different-case temporary source' => ['temp_source', 'Temporary objects shadow PreDB writer capability.'],
            'different-case main reserved partial object' => ['main_partial', 'Unsupported PreDB schema table: predb_identity_state'],
            'different-case temporary trigger target' => ['temp_target', 'Temporary objects shadow PreDB writer capability.'],
        ];
    }

    #[DataProvider('identifierCases')]
    public function test_actual_writer_rejects_identifier_equivalent_capability_objects_before_source_dml(string $case, string $message): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $name = $connection->getName();
        if ($case === 'temp_source') {
            $connection->statement("CREATE TEMP TABLE PreDB (id INTEGER PRIMARY KEY NOT NULL, title VARCHAR NOT NULL, filename VARCHAR NOT NULL DEFAULT '', source TEXT, category TEXT, size TEXT, files TEXT, predate TEXT, nuked INTEGER NOT NULL DEFAULT 0, nukereason TEXT)");
            self::assertSame('PreDB', $pdo->query("SELECT name FROM temp.sqlite_schema WHERE type='table'")->fetchColumn());
        } else {
            $connection->statement('CREATE TABLE PREDB_IDENTITY_STATE (id INTEGER PRIMARY KEY NOT NULL)');
            self::assertSame('PREDB_IDENTITY_STATE', $pdo->query("SELECT name FROM main.sqlite_schema WHERE name='PREDB_IDENTITY_STATE'")->fetchColumn());
            if ($case === 'temp_target') {
                $connection->statement('CREATE TEMP TRIGGER writer_case_target AFTER UPDATE ON main.PREDB_IDENTITY_STATE BEGIN SELECT 1; END');
                self::assertSame('PREDB_IDENTITY_STATE', $pdo->query("SELECT tbl_name FROM temp.sqlite_schema WHERE name='writer_case_target'")->fetchColumn());
            }
        }
        $before = $pdo->query('SELECT * FROM main.predb ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $sourceWrites = 0;
        $previousDispatcher = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $connection->setEventDispatcher($dispatcher);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, &$sourceWrites): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'insert into "predb" ')) {
                $sourceWrites++;
            }
        });
        try {
            $observed = null;
            try {
                (new PredbIdentityWriter($connection))->insertFeed('Synthetic.Case.Fence-GRP', new PredbFeedEntry('Synthetic.Case.Fence-GRP', 'SyntheticFeed'));
            } catch (Throwable $error) {
                $observed = $error;
            }
            // First causal assertion: the old case-sensitive detector returns a successful absent-capability insert.
            self::assertInstanceOf(RuntimeException::class, $observed);
            self::assertSame($message, $observed->getMessage());
            self::assertSame(0, $sourceWrites);
            self::assertSame($before, $pdo->query('SELECT * FROM main.predb ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            if ($case === 'temp_source') {
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM temp.PreDB')->fetchColumn());
            }
            self::assertSame($pdo, $connection->getRawPdo());
            self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            self::assertSame(0, $connection->transactionLevel());
            self::assertFalse($pdo->inTransaction());
            self::assertFalse($manager->getPendingTransactions()->contains(static fn ($record): bool => $record->connection === $name));
            self::assertFalse($manager->getCommittedTransactions()->contains(static fn ($record): bool => $record->connection === $name));
        } finally {
            if ($previousDispatcher === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($previousDispatcher);
            }
        }
    }
}
