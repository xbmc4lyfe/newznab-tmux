<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Console\Commands\PredbIdentityRebuildCommand;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class PredbIdentityRebuildCommandTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacade;

    private Application $application;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->application = new Application(dirname(__DIR__, 3));
        $this->application->instance('config', new Repository(['app' => ['env' => 'testing'], 'database' => ['default' => 'testing', 'connections' => ['testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null, 'prefix' => '', 'foreign_key_constraints' => true]]]]));
        Container::setInstance($this->application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->application);
    }

    protected function tearDown(): void
    {
        try {
            $this->connection?->disconnect();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacade);
            Container::setInstance($this->previousContainer);
            parent::tearDown();
        }
    }

    public static function invalidInputs(): array
    {
        $cases = [
            'unknown action' => [['action' => 'other']],
            'missing chunk generation' => [['action' => 'chunk']],
            'missing certificate generation' => [['action' => 'certify']],
            'start with generation' => [['action' => 'start', '--generation' => str_repeat('a', 32)]],
            'start with empty generation' => [['action' => 'start', '--generation' => '']],
            'start with custom chunk' => [['action' => 'start', '--chunk' => '1']],
            'certify with custom chunk' => [['action' => 'certify', '--generation' => str_repeat('a', 32), '--chunk' => '1']],
        ];
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), ' '.str_repeat('a', 32), str_repeat('a', 32)."\n"] as $index => $generation) {
            foreach (['chunk', 'certify'] as $action) {
                $cases[$action.' invalid generation '.$index] = [['action' => $action, '--generation' => $generation]];
            }
        }
        foreach (['0', '257', '-1', '+1', '1.0', '1e2', '01', ' 1', '1 ', str_repeat('9', 1000), ''] as $index => $chunk) {
            $cases['invalid chunk '.$index] = [['action' => 'chunk', '--generation' => str_repeat('a', 32), '--chunk' => $chunk]];
        }

        return $cases;
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_before_database_resolution(array $input): void
    {
        $resolutions = 0;
        $this->application->bind('db', static function () use (&$resolutions): never {
            $resolutions++;
            throw new RuntimeException('Synthetic database detail must not be exposed.');
        });
        [$exit, $output] = $this->invoke($input);
        self::assertSame(1, $exit);
        self::assertNotSame('', $output);
        self::assertSame(0, $resolutions);
    }

    public function test_one_phase_per_invocation_requires_explicit_exhausted_generation_certification(): void
    {
        $this->install();
        $this->connection->table('predb')->insert([['id' => 1, 'title' => 'Synthetic.First-GRP'], ['id' => 7, 'title' => 'Synthetic.Second-GRP']]);
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(0, $exit);
        $start = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $start['generation']);
        self::assertSame(7, $start['until_id']);
        self::assertFalse($start['certified']);
        self::assertSame(0, $this->connection->table('predb_identity_keys')->count());
        [$exit] = $this->invoke(['action' => 'certify', '--generation' => $start['generation']]);
        self::assertSame(1, $exit);
        self::assertSame(0, $this->connection->table('predb_identity_state')->value('ready'));
        [$exit, $output] = $this->invoke(['action' => 'chunk', '--generation' => $start['generation'], '--chunk' => '1']);
        self::assertSame(0, $exit);
        $chunk = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $chunk['processed']);
        self::assertSame(1, $chunk['cursor']);
        self::assertFalse($chunk['exhausted']);
        self::assertFalse($chunk['certified']);
        [$exit, $output] = $this->invoke(['action' => 'chunk', '--generation' => $start['generation'], '--chunk' => '256']);
        self::assertSame(0, $exit);
        $chunk = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $chunk['processed']);
        self::assertTrue($chunk['exhausted']);
        self::assertFalse($chunk['certified']);
        self::assertSame(0, $this->connection->table('predb_identity_state')->value('ready'));
        [$exit, $output] = $this->invoke(['action' => 'certify', '--generation' => $start['generation']]);
        self::assertSame(0, $exit);
        self::assertSame(['phase' => 'certify', 'generation' => $start['generation'], 'certified' => true], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->connection->table('predb_identity_state')->value('ready'));
    }

    public function test_valueless_generation_on_start_is_rejected_before_database_resolution(): void
    {
        $resolutions = 0;
        $this->application->bind('db', static function () use (&$resolutions): never {
            $resolutions++;
            throw new RuntimeException('Synthetic database detail.');
        });
        $command = new PredbIdentityRebuildCommand;
        $command->setLaravel($this->application);
        $output = new BufferedOutput;
        self::assertSame(1, $command->run(new ArgvInput(['console', 'start', '--generation']), $output));
        self::assertSame(0, $resolutions);
    }

    public function test_reporting_failure_does_not_mislabel_successful_certification(): void
    {
        $this->install();
        [$exit, $text] = $this->invoke(['action' => 'start']);
        self::assertSame(0, $exit);
        $generation = json_decode($text, true, 512, JSON_THROW_ON_ERROR)['generation'];
        $command = new PredbIdentityRebuildCommand;
        $command->setLaravel($this->application);
        $output = new class extends BufferedOutput
        {
            private bool $failed = false;

            protected function doWrite(string $message, bool $newline): void
            {
                if (! $this->failed && str_contains($message, '"certified":true')) {
                    $this->failed = true;
                    throw new RuntimeException('Synthetic private output detail.');
                }
                parent::doWrite($message, $newline);
            }
        };
        self::assertSame(1, $command->run(new ArgvInput(['console', 'certify', '--generation='.$generation]), $output));
        self::assertSame(1, $this->connection->table('predb_identity_state')->value('ready'));
        $text = $output->fetch();
        self::assertStringContainsString('operation committed', $text);
        self::assertStringNotContainsString('rebuild failed', $text);
        self::assertStringNotContainsString('Synthetic private output detail.', $text);
    }

    public function test_default_chunk_is_bounded_and_wrong_generation_is_not_substituted(): void
    {
        $this->install();
        for ($id = 1; $id <= 129; $id++) {
            $this->connection->table('predb')->insert(['id' => $id, 'title' => 'Synthetic.Release.'.$id.'-GRP']);
        }
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(0, $exit);
        $generation = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['generation'];
        $wrong = $generation === str_repeat('a', 32) ? str_repeat('b', 32) : str_repeat('a', 32);
        foreach (['chunk', 'certify'] as $action) {
            [$exit, $output] = $this->invoke(['action' => $action, '--generation' => $wrong]);
            self::assertSame(1, $exit);
            self::assertStringNotContainsString('"certified":true', $output);
            self::assertSame(0, $this->connection->table('predb_identity_state')->value('rebuild_cursor'));
        }
        [$exit, $output] = $this->invoke(['action' => 'chunk', '--generation' => $generation]);
        self::assertSame(0, $exit);
        $chunk = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(128, $chunk['processed']);
        self::assertSame(128, $chunk['cursor']);
        self::assertFalse($chunk['exhausted']);
        self::assertFalse($chunk['certified']);
        self::assertSame(0, $this->connection->table('predb_identity_state')->value('ready'));
    }

    public function test_uninstalled_schema_is_not_created_or_certified(): void
    {
        $this->install(false);
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(1, $exit);
        self::assertStringNotContainsString('"certified":true', $output);
        self::assertFalse($this->connection->getSchemaBuilder()->hasTable('predb_identity_state'));
        self::assertFalse($this->connection->getSchemaBuilder()->hasTable('predb_identity_keys'));
    }

    public function test_service_query_failure_is_redacted_after_connection_resolution(): void
    {
        $this->install();
        $queryCalls = 0;
        $this->connection->beforeExecuting(static function () use (&$queryCalls): never {
            $queryCalls++;
            throw new RuntimeException('Synthetic private query detail.');
        });
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(1, $exit);
        self::assertSame(1, $queryCalls);
        self::assertStringNotContainsString('Synthetic private query detail.', $output);
        self::assertStringNotContainsString('"certified":true', $output);
        self::assertStringContainsString('PreDB identity rebuild failed', $output);
    }

    public function test_missing_transaction_manager_rejects_before_query_and_remains_redacted(): void
    {
        $this->install();
        $pdo = $this->connection->getPdo();
        $tables = ['predb', 'predb_identity_state', 'predb_identity_keys', 'predb_identity_revisions'];
        $snapshot = static function () use ($pdo, $tables): array {
            $rows = [];
            foreach ($tables as $table) {
                $rows[$table] = $pdo->query('SELECT * FROM '.$table)->fetchAll(\PDO::FETCH_ASSOC);
            }

            return $rows;
        };
        $before = $snapshot();
        $this->connection->unsetTransactionManager();
        $begins = 0;
        $this->connection->beforeStartingTransaction(static function () use (&$begins): void {
            $begins++;
        });
        $queries = 0;
        $this->connection->beforeExecuting(static function () use (&$queries): void {
            $queries++;
        });
        try {
            (new PredbIdentityRebuild)->start($this->connection);
            self::fail('Missing manager must reject.');
        } catch (RuntimeException $failure) {
            self::assertSame(RuntimeException::class, $failure::class);
            self::assertSame('Unsupported PreDB identity callback manager.', $failure->getMessage());
        }
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('PreDB identity rebuild failed', $output);
        self::assertStringNotContainsString('Unsupported PreDB identity callback manager.', $output);
        self::assertStringNotContainsString('"certified":true', $output);
        self::assertSame(0, $queries);
        self::assertSame(0, $begins);
        self::assertSame($pdo, $this->connection->getRawPdo());
        self::assertSame($pdo, $this->connection->getPdo());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $this->connection->transactionLevel());
        $property = new \ReflectionProperty(Connection::class, 'transactionsManager');
        self::assertNull($property->getValue($this->connection));
        self::assertSame($before, $snapshot());
    }

    public function test_unknown_database_failure_does_not_expose_exception_text(): void
    {
        $this->application->bind('db', static fn () => throw new RuntimeException('Synthetic private connection detail.'));
        [$exit, $output] = $this->invoke(['action' => 'start']);
        self::assertSame(1, $exit);
        self::assertStringNotContainsString('Synthetic private connection detail.', $output);
        self::assertStringNotContainsString('certified', $output);
        self::assertStringContainsString('PreDB identity rebuild failed', $output);
    }

    private function install(bool $projection = true): void
    {
        $capsule = new Capsule($this->application);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'url' => null, 'prefix' => '', 'foreign_key_constraints' => true], 'testing');
        $capsule->getDatabaseManager()->setDefaultConnection('testing');
        $this->application->instance('db', $capsule->getDatabaseManager());
        $this->connection = $capsule->getConnection('testing');
        $this->connection->setTransactionManager(new DatabaseTransactionsManager);
        $this->application->instance('db.schema', $this->connection->getSchemaBuilder());
        $this->connection->getSchemaBuilder()->create('predb', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('filename')->default('');
        });
        if ($projection) {
            (require dirname(__DIR__, 3).'/database/migrations/2026_10_15_150000_create_predb_identity_projection.php')->up();
        }
    }

    /** @return array{int, string} */
    private function invoke(array $input): array
    {
        self::assertTrue(class_exists(PredbIdentityRebuildCommand::class), 'The finite rebuild command is not implemented.');
        $command = new PredbIdentityRebuildCommand;
        $command->setLaravel($this->application);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input, ['interactive' => false, 'decorated' => false]);

        return [$exit, trim($tester->getDisplay())];
    }
}
