<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityWriter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\SQLiteProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PredbIdentityWriterRuntimeControlsTest extends TestCase
{
    use CertifiedPredbSqlFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCertifiedSqlFixture();
        foreach (['source', 'category', 'size', 'files', 'predate', 'nukereason'] as $column) {
            DB::statement('ALTER TABLE predb ADD COLUMN '.$column.' TEXT NULL');
        }
        DB::statement('ALTER TABLE predb ADD COLUMN nuked INTEGER NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE predb ADD COLUMN searched INTEGER NOT NULL DEFAULT 0');
        DB::statement('CREATE UNIQUE INDEX runtime_control_title_unique ON predb(title)');
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Control.Release-GRP', 'filename' => '', 'source' => 'SyntheticControl']);
        $this->certifySqlFixture();
    }

    protected function tearDown(): void
    {
        try {
            $this->tearDownCertifiedSqlFixture();
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_source_unique_rejection_preserves_exception_and_rolls_back_without_publication_or_retry(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        // A real source constraint rejects the new title without inventing a competing title row.
        $connection->statement('CREATE UNIQUE INDEX runtime_control_source_unique ON predb(source)');
        $before = $this->snapshot();
        $search = $this->publicationCapture();
        $originalProcessor = $connection->getPostProcessor();
        $processor = new PredbRuntimeSourceInsertProcessor;
        $connection->setPostProcessor($processor);
        $title = 'Synthetic.Source.Rejected-GRP';
        $entry = new PredbFeedEntry($title, 'SyntheticControl');
        try {
            $outcome = (new PredbIdentityWriter($connection))->insertFeed($title, $entry);
            self::assertSame(1, $processor->attempts);
            self::assertInstanceOf(UniqueConstraintViolationException::class, $processor->failure);
            self::assertSame('source_rejected', $outcome['status']);
            self::assertSame($processor->failure, $outcome['source_failure'], 'The actual source exception must survive verified cleanup unchanged.');
            self::assertNull($outcome['id']);
            self::assertNull($outcome['row']);
            self::assertFalse($outcome['identity_changed']);
            self::assertSame('unchanged', $outcome['readiness']);
            self::assertSame($before, $this->snapshot());
            self::assertSame([], $search->calls);
            $this->assertCleanConnection($connection, $pdo, $manager);

            // Exercise the real importer fallback separately: one rejected INSERT,
            // no matching competing title and therefore no Search retry/publication.
            $processor->attempts = 0;
            $processor->failure = null;
            $result = (new PredbFeedImporter)->import([$entry]);
            self::assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
            self::assertSame(1, $processor->attempts);
            self::assertInstanceOf(UniqueConstraintViolationException::class, $processor->failure);
            self::assertSame($before, $this->snapshot());
            self::assertSame([], $search->calls);
            $this->assertCleanConnection($connection, $pdo, $manager);
        } finally {
            $connection->setPostProcessor($originalProcessor);
        }
    }

    public static function outerOutcomes(): array
    {
        return ['caller commits' => [true], 'caller rolls back' => [false]];
    }

    #[DataProvider('outerOutcomes')]
    public function test_actual_importer_publication_follows_only_the_callers_outer_completion(bool $commit): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $before = $this->snapshot();
        $search = $this->publicationCapture();
        $title = 'Synthetic.Ambient.Feed-GRP';
        $connection->beginTransaction();
        try {
            $result = (new PredbFeedImporter)->import([new PredbFeedEntry($title, 'SyntheticAmbient')]);
            self::assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0], $result);
            self::assertSame([], $search->calls, 'Returning from the writer savepoint must not publish.');
            self::assertSame(1, $connection->transactionLevel());
            self::assertTrue($pdo->inTransaction());
            $row = $connection->table('predb')->where('title', $title)->sole();
            self::assertSame(1, (int) $connection->table('predb_identity_state')->value('ready'));
            if ($commit) {
                $connection->commit();
                self::assertSame([['method' => 'insert', 'document' => [
                    'id' => (int) $row->id, 'title' => $title, 'filename' => '', 'source' => 'SyntheticAmbient',
                ]]], $search->calls);
                self::assertSame((array) $row, (array) $connection->table('predb')->where('id', $row->id)->sole());
            } else {
                $connection->rollBack();
                self::assertSame($before, $this->snapshot());
                self::assertSame([], $search->calls);
            }
            $this->assertCleanConnection($connection, $pdo, $manager);
            $fresh = 0;
            $connection->transaction(static function () use ($connection, &$fresh): void {
                $connection->afterCommit(static function () use (&$fresh): void {
                    $fresh++;
                });
            });
            self::assertSame(1, $fresh);
            self::assertCount($commit ? 1 : 0, $search->calls, 'A later unrelated commit must not replay the captured publication.');
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    public function test_actual_importer_keeps_an_initially_unready_catalogue_unready(): void
    {
        $connection = DB::connection();
        $connection->table('predb_identity_state')->where('id', 1)->update(['ready' => 0]);
        $before = $this->snapshot();
        $stateBefore = $before['predb_identity_state'][0];
        $search = $this->publicationCapture();
        $title = 'Synthetic.Unready.Feed-GRP';
        $result = (new PredbFeedImporter)->import([new PredbFeedEntry($title, 'SyntheticUnready')]);
        self::assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $row = $connection->table('predb')->where('title', $title)->sole();
        $expectedState = $stateBefore;
        $expectedState['epoch']++;
        self::assertSame($expectedState, (array) $connection->table('predb_identity_state')->sole());
        self::assertSame(0, (int) $connection->table('predb_identity_state')->value('ready'));
        self::assertSame([
            'predb_id' => (int) $row->id,
            'revision' => $expectedState['epoch'],
            'deleted' => 0,
            'projected_revision' => null,
            'projected_generation' => null,
        ], (array) $connection->table('predb_identity_revisions')->where('predb_id', $row->id)->sole());
        self::assertSame(0, $connection->table('predb_identity_keys')->where('predb_id', $row->id)->count());
        self::assertSame('unknown', (new PredbIdentityLookup)->lookup($connection, new OriginalFilenameEvidence($title.'.mkv'))['status']);
        self::assertSame([['method' => 'insert', 'document' => [
            'id' => (int) $row->id, 'title' => $title, 'filename' => '', 'source' => 'SyntheticUnready',
        ]]], $search->calls);
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
    }

    private function publicationCapture(): object
    {
        $capture = new class
        {
            public array $calls = [];

            public function insertPredb(array $document): void
            {
                TestCase::assertSame(0, DB::connection()->transactionLevel());
                TestCase::assertFalse(DB::connection()->getPdo()->inTransaction());
                $this->calls[] = ['method' => 'insert', 'document' => $document];
            }

            public function updatePreDb(array $document): void
            {
                TestCase::fail('These new-title controls must not publish an update.');
            }
        };
        Search::swap($capture);

        return $capture;
    }

    private function assertCleanConnection(Connection $connection, PDO $pdo, DatabaseTransactionsManager $manager): void
    {
        $name = $connection->getName();
        self::assertSame($pdo, $connection->getRawPdo());
        self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertFalse($manager->getPendingTransactions()->contains(static fn ($record): bool => $record->connection === $name));
        self::assertFalse($manager->getCommittedTransactions()->contains(static fn ($record): bool => $record->connection === $name));
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['predb' => ['id'], 'predb_identity_state' => ['id'], 'predb_identity_revisions' => ['predb_id'],
            'predb_identity_keys' => ['generation', 'kind', 'key_digest', 'predb_id']] as $table => $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $result[$table] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}

/** Observes the actual parent INSERT/constraint exception without replacing its SQL. */
final class PredbRuntimeSourceInsertProcessor extends SQLiteProcessor
{
    public int $attempts = 0;

    public ?UniqueConstraintViolationException $failure = null;

    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $this->attempts++;
        try {
            return parent::processInsertGetId($query, $sql, $values, $sequence);
        } catch (UniqueConstraintViolationException $error) {
            $this->failure = $error;
            throw $error;
        }
    }
}
