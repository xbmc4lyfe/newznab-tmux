<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Writer-ready publication regression; certified source insertion remains ready. */
final class PredbFeedPublicationFailureTest extends TestCase
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
        DB::statement('CREATE UNIQUE INDEX private_predb_title_unique ON predb(title)');
        DB::table('predb')->insert([
            ['id' => 7, 'title' => 'Synthetic.Original.Release-GRP', 'filename' => '', 'source' => ''],
            ['id' => 8, 'title' => 'Synthetic.Untouched.Release-GRP', 'filename' => '', 'source' => ''],
        ]);
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

    public static function publicationFailures(): array
    {
        return [
            'unique-looking index failure' => ['unique'],
            'SQLSTATE 22 index failure' => ['data22'],
            'generic index failure' => ['generic'],
        ];
    }

    #[DataProvider('publicationFailures')]
    public function test_importer_does_not_retry_or_label_committed_publication_as_skipped(string $kind): void
    {
        $previous = new PDOException('Synthetic publication failure.', $kind === 'data22' ? 22001 : 23000);
        $failure = match ($kind) {
            'unique' => new UniqueConstraintViolationException('synthetic-index', 'synthetic publication', [], $previous),
            'data22' => new QueryException('synthetic-index', 'synthetic publication', [], $previous),
            default => new RuntimeException('Synthetic publication failure.'),
        };
        $warnings = new class
        {
            public array $warnings = [];

            public function warning(string $message, array $context): void
            {
                $this->warnings[] = $message;
            }
        };
        Log::swap($warnings);
        $search = new class($failure)
        {
            public array $calls = [];

            public function __construct(private readonly Throwable $failure) {}

            public function insertPredb(array $document): void
            {
                $this->publish('insert', $document);
            }

            public function updatePreDb(array $document): void
            {
                $this->publish('update', $document);
            }

            private function publish(string $method, array $document): never
            {
                $connection = DB::connection();
                TestCase::assertSame(0, $connection->transactionLevel());
                TestCase::assertFalse($connection->getPdo()->inTransaction(), 'Publication must follow durable owned SQL commit.');
                TestCase::assertSame($document['title'], DB::table('predb')->where('id', $document['id'])->value('title'));
                $this->calls[] = ['method' => $method, 'id' => $document['id']];
                throw $this->failure;
            }
        };
        Search::swap($search);
        $title = 'Synthetic.Publication.Release-GRP';
        $epoch = DB::table('predb_identity_state')->where('id', 1)->value('epoch');
        $result = null;
        $observed = null;
        try {
            $result = (new PredbFeedImporter)->import([new PredbFeedEntry($title, 'SyntheticFeed')]);
        } catch (Throwable $exception) {
            $observed = $exception;
        }
        self::assertSame(1, DB::table('predb')->where('title', $title)->count());
        self::assertSame($epoch + 1, DB::table('predb_identity_state')->where('id', 1)->value('epoch'));
        // The integrated writer must preserve certified readiness after committed publication failure.
        self::assertSame(1, (int) DB::table('predb_identity_state')->where('id', 1)->value('ready'));
        self::assertSame([
            'exact_failure_propagated' => true,
            'no_false_skipped_result' => true,
            'publication_attempts' => 1,
            'data_rejection_warnings' => [],
        ], [
            'exact_failure_propagated' => $observed === $failure,
            'no_false_skipped_result' => $result === null,
            'publication_attempts' => count($search->calls),
            'data_rejection_warnings' => $warnings->warnings,
        ]);
    }
}
