<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Fixtures\ManifestCompressionProbe;
use Tests\TestCase;

final class ReleaseVerificationArchiveLockMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private string $database = '';

    private bool $ownsDatabase = false;

    private string $storagePath = '';

    private string $host = '';

    private string $username = '';

    private string $password = '';

    private ?Filesystem $fixtureFiles = null;

    private ?DatabaseManager $fixtureConnections = null;

    private bool $ownsConnection = false;

    private ?\Closure $afterSchemaInitialized = null;

    private ?\Closure $beforeCleanup = null;

    /** @var list<\Throwable> */
    private array $cleanupFailures = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $this->host = (string) getenv('VERIFICATION_LOCK_TEST_HOST');
        if ($this->host === '') {
            $this->markTestSkipped('Set VERIFICATION_LOCK_TEST_HOST to an isolated MariaDB test server.');
        }
        $this->username = (string) (getenv('VERIFICATION_LOCK_TEST_USERNAME') ?: 'root');
        $this->password = (string) getenv('VERIFICATION_LOCK_TEST_PASSWORD');
        $this->administrator = new PDO('mysql:host='.$this->host.';port=3306;charset=utf8mb4', $this->username, $this->password);
        $this->initializeDatabase(function (): void {
            $this->fixtureFiles = app('files');
            $this->fixtureConnections = app('db');
            $this->ownsConnection = true;
            $this->storagePath = storage_path('framework/testing/lock-'.bin2hex(random_bytes(8)));
            config(['database.default' => 'mariadb', 'database.connections.mariadb.host' => $this->host,
                'database.connections.mariadb.port' => 3306, 'database.connections.mariadb.database' => $this->database,
                'database.connections.mariadb.username' => $this->username, 'database.connections.mariadb.password' => $this->password,
                'database.connections.mariadb.url' => null, 'database.connections.mariadb.unix_socket' => '',
                'database.connections.mariadb.prefix' => '',
                'release_verification.required' => true, 'nntmux.echocli' => false,
                'release_verification.storage_path' => $this->storagePath]);
            DB::purge();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            (require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php'))->up();
            (require database_path('migrations/2026_10_17_220000_add_header_check_pending_to_release_verification_candidates.php'))->up();
            DB::statement('CREATE TABLE collections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collectionhash BINARY(20) NOT NULL, subject VARCHAR(255) NOT NULL, fromname VARCHAR(255) NOT NULL,
                totalfiles INT NOT NULL, groups_id INT NOT NULL, collection_regexes_id INT DEFAULT 0,
                date DATETIME NOT NULL, filecheck INT DEFAULT 3, filesize BIGINT DEFAULT 5,
                UNIQUE KEY ix_collection_collectionhash (collectionhash)) ENGINE=InnoDB');
            DB::statement('CREATE TABLE binaries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collections_id BIGINT UNSIGNED NOT NULL, binaryhash BINARY(16) NOT NULL,
                name VARCHAR(255) NOT NULL, totalparts INT NOT NULL,
                FOREIGN KEY (collections_id) REFERENCES collections(id) ON DELETE CASCADE) ENGINE=InnoDB');
            DB::statement('CREATE TABLE parts (binaries_id BIGINT UNSIGNED NOT NULL, partnumber INT NOT NULL,
                messageid VARCHAR(255) NOT NULL, size INT NOT NULL, PRIMARY KEY (binaries_id, partnumber),
                FOREIGN KEY (binaries_id) REFERENCES binaries(id) ON DELETE CASCADE) ENGINE=InnoDB');
            DB::statement('CREATE TABLE usenet_groups (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB');
            DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
            ($this->afterSchemaInitialized ?? static function (): void {})();
        });
    }

    private function initializeDatabase(callable $initialize): void
    {
        $this->database = 'nntmux_archive_lock_test_'.bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
        $this->ownsDatabase = true;
        try {
            $initialize();
        } catch (\Throwable $error) {
            $this->cleanupFixture();
            throw $error;
        }
    }

    /**
     * Retain failures independently of the primary setup exception; failed resources remain owned for retry.
     *
     * @return list<\Throwable>
     */
    private function cleanupFixture(): array
    {
        $failures = [];
        try {
            if ($this->storagePath !== '') {
                ($this->beforeCleanup ?? static function (): void {})('storage');
                if (preg_match('~/framework/testing/lock-[a-f0-9]{16}$~D', $this->storagePath) !== 1
                    || $this->fixtureFiles === null
                    || ($this->fixtureFiles->exists($this->storagePath) && ! $this->fixtureFiles->deleteDirectory($this->storagePath))) {
                    throw new \RuntimeException('Could not remove owned archive-lock storage.');
                }
                $this->storagePath = '';
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        }
        try {
            if ($this->ownsConnection) {
                ($this->beforeCleanup ?? static function (): void {})('connection');
                $this->fixtureConnections->purge('mariadb');
                $this->ownsConnection = false;
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        }
        try {
            if ($this->ownsDatabase && $this->administrator !== null
                && preg_match('/^nntmux_archive_lock_test_[a-f0-9]{16}$/D', $this->database) === 1) {
                ($this->beforeCleanup ?? static function (): void {})('database');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
                $this->ownsDatabase = false;
            }
        } catch (\Throwable $error) {
            $failures[] = $error;
        }
        array_push($this->cleanupFailures, ...$failures);
        foreach ($failures as $failure) {
            fwrite(STDOUT, 'Archive-lock fixture cleanup failure: '.$failure::class.': '.$failure->getMessage().PHP_EOL);
        }

        return $failures;
    }

    protected function tearDown(): void
    {
        ManifestCompressionProbe::$onCompress = null;
        try {
            $failures = $this->cleanupFixture();
            if ($failures !== []) {
                throw $failures[0];
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_setup_failure_drops_only_the_database_created_by_that_fixture(): void
    {
        $fixture = new self(__FUNCTION__);
        $fixture->administrator = $this->administrator;
        $failure = new \RuntimeException('Injected schema initialization failure.');
        $createdDatabase = '';

        try {
            $fixture->initializeDatabase(function () use ($fixture, $failure, &$createdDatabase): void {
                $createdDatabase = $fixture->database;
                $fixture->administrator->exec('CREATE TABLE `'.$createdDatabase.'`.partial_setup (id INT PRIMARY KEY)');
                throw $failure;
            });
            $this->fail('The initialization failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
            $statement = $this->administrator->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $statement->execute([$createdDatabase]);
            $this->assertFalse($statement->fetchColumn(), 'Failed setup must drop its database before tearDown.');
            $statement->execute([$this->database]);
            $this->assertSame($this->database, $statement->fetchColumn(), 'Cleanup must preserve another fixture database.');
        } finally {
            if ($createdDatabase !== '') {
                $this->administrator->exec('DROP DATABASE IF EXISTS `'.$createdDatabase.'`');
            }
        }
    }

    public static function setupCleanupFailures(): iterable
    {
        yield 'successful cleanup' => [''];
        yield 'database cleanup failure' => ['database'];
        yield 'storage cleanup failure' => ['storage'];
        yield 'connection cleanup failure' => ['connection'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('setupCleanupFailures')]
    public function test_failed_setup_preserves_primary_failure_and_retries_owned_cleanup(string $failedResource): void
    {
        $fixture = new self(__FUNCTION__);
        $original = new \RuntimeException('Original schema initialization failure.');
        $cleanupFailure = new \RuntimeException('Injected '.$failedResource.' cleanup failure.');
        $database = '';
        $path = '';
        $attempts = [];
        $files = app('files');
        $survivorPath = $this->storagePath;
        $files->ensureDirectoryExists($survivorPath);
        file_put_contents($survivorPath.'/survivor', 'separate fixture');
        $fixture->afterSchemaInitialized = function () use ($fixture, $original, &$database, &$path, $files): void {
            $database = $fixture->database;
            $path = $fixture->storagePath;
            $files->ensureDirectoryExists($path);
            file_put_contents($path.'/owned', 'failed fixture');
            $fixture->administrator->exec('CREATE TABLE `'.$database.'`.partial_setup (id INT PRIMARY KEY)');
            throw $original;
        };
        $fixture->beforeCleanup = function (string $resource) use ($failedResource, $cleanupFailure, &$attempts): void {
            $attempts[] = $resource;
            if ($resource === $failedResource) {
                throw $cleanupFailure;
            }
        };
        $caught = null;
        $secondary = null;
        try {
            try {
                $fixture->setUp();
            } catch (\Throwable $error) {
                $caught = $error;
            }
            $statement = $this->administrator->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $statement->execute([$database]);
            $databaseRemains = $statement->fetchColumn() !== false;
            $pathRemains = is_dir($path);
            $ownershipRetained = $fixture->ownsDatabase;
            $connectionOwnershipRetained = $fixture->ownsConnection;
            $pathOwnershipRetained = $fixture->storagePath === $path;
            $reported = in_array($cleanupFailure, $fixture->cleanupFailures, true);
            $setupAttempts = $attempts;
            // This is the after-test hook PHPUnit invokes even when setUp throws.
            $fixture->beforeCleanup = function (string $resource) use (&$attempts): void {
                $attempts[] = $resource;
            };
            try {
                $fixture->tearDown();
            } catch (\Throwable $error) {
                $secondary = $error;
            }
            $statement->execute([$database]);
            $remainingDatabase = $statement->fetchColumn();
            $statement->execute([$this->database]);
            $survivorDatabase = $statement->fetchColumn();
            $observed = [
                'primary_identity' => $caught === $original,
                'primary' => $caught?->getMessage(),
                'secondary' => $secondary?->getMessage(),
                'database_retained_before_retry' => $databaseRemains,
                'storage_retained_before_retry' => $pathRemains,
                'database_ownership' => $ownershipRetained,
                'connection_ownership' => $connectionOwnershipRetained,
                'storage_ownership' => $pathOwnershipRetained,
                'cleanup_failure_reported' => $reported,
                'attempts' => $setupAttempts,
            ];
            fwrite(STDOUT, "\nSETUP_CLEANUP_PROBE ".json_encode($observed, JSON_THROW_ON_ERROR)."\n");
            $this->assertSame($original, $caught, json_encode($observed));
            $this->assertNull($secondary, json_encode($observed));
            $this->assertSame($failedResource === 'database', $databaseRemains);
            $this->assertSame($failedResource === 'storage', $pathRemains);
            $this->assertSame($failedResource === 'database', $ownershipRetained);
            $this->assertSame($failedResource === 'connection', $connectionOwnershipRetained);
            $this->assertSame(['storage', 'connection', 'database'], $setupAttempts);
            $this->assertSame($failedResource === '' ? $setupAttempts : [...$setupAttempts, $failedResource], $attempts);
            $this->assertSame($failedResource === 'storage', $pathOwnershipRetained);
            $this->assertSame($failedResource !== '', $reported);
            $this->assertContains('storage', $setupAttempts);
            $this->assertContains('connection', $setupAttempts);
            $this->assertContains('database', $setupAttempts);
            $this->assertFalse($remainingDatabase);
            $this->assertDirectoryDoesNotExist($path);
            $this->assertFalse($fixture->ownsDatabase);
            $this->assertSame('', $fixture->storagePath);
            $this->assertFalse($fixture->ownsConnection);
            $this->assertSame($failedResource === '' ? [] : [$cleanupFailure], $fixture->cleanupFailures);
            $fixture->tearDown();
            $this->assertSame($failedResource === '' ? $setupAttempts : [...$setupAttempts, $failedResource], $attempts, 'Repeated teardown must not retry released resources.');
            $this->assertSame($this->database, $survivorDatabase);
            $this->assertSame('separate fixture', file_get_contents($survivorPath.'/survivor'));
        } finally {
            // Restore the outer fixture's application after the nested lifecycle.
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
            Facade::clearResolvedInstances();
            $fixture->beforeCleanup = null;
            if ($database !== '' && preg_match('/^nntmux_archive_lock_test_[a-f0-9]{16}$/', $database) === 1) {
                $this->administrator->exec('DROP DATABASE IF EXISTS `'.$database.'`');
            }
            if ($path !== '') {
                $files->deleteDirectory($path);
            }
        }
    }

    public static function preparationModes(): iterable
    {
        yield 'archive standalone' => [false, false];
        yield 'archive caller transaction' => [true, false];
        yield 'enqueue standalone' => [false, true];
        yield 'enqueue caller transaction' => [true, true];
    }

    #[DataProvider('preparationModes')]
    public function test_real_compression_keeps_capacity_available_until_manifest_publication(bool $outerTransaction, bool $enqueue): void
    {
        require_once __DIR__.'/../Fixtures/ManifestCompressionProbe.php';
        $id = $enqueue ? null : $this->arrival('compression');
        $other = $this->connection();
        $compressions = 0;
        ManifestCompressionProbe::$onCompress = function () use ($id, $other, &$compressions): void {
            $compressions++;
            $other->beginTransaction();
            try {
                try {
                    $other->query('SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE NOWAIT')->fetchAll();
                } catch (\PDOException $error) {
                    $this->assertSame(1205, (int) $error->errorInfo[1]);
                    $this->fail('JSON/gzip preparation must not own shared capacity.');
                }
                $this->assertSame(0, (int) $other->query('SELECT pending_bytes FROM release_verification_state WHERE id = 1')->fetchColumn());
                $this->assertSame([], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
                if ($id !== null) {
                    try {
                        $lock = $other->prepare('SELECT id FROM collections WHERE id = ? FOR UPDATE NOWAIT');
                        $lock->execute([$id]);
                        $this->fail('Preparation must retain its collection parent.');
                    } catch (\PDOException $error) {
                        $this->assertSame(1205, (int) $error->errorInfo[1]);
                    }
                }
            } finally {
                $other->rollBack();
            }
        };
        if ($outerTransaction) {
            DB::beginTransaction();
        }
        try {
            if ($enqueue) {
                app(CandidateRepository::class)->enqueue(['identity' => sha1('compression-import'), 'groups_id' => 1, 'files' => []]);
            } else {
                app(CandidateRepository::class)->archive($id);
            }
            if ($outerTransaction) {
                DB::commit();
            }
        } finally {
            ManifestCompressionProbe::$onCompress = null;
            if ($outerTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(1, $compressions);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertFileExists(app(ManifestStore::class)->path($candidate->manifest_path));
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        if (! $enqueue) {
            $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
            $this->assertSame('compression@article', $manifest['files'][0]['segments'][0]['messageid']);
            $this->assertSame(0, DB::table('collections')->count());
            $this->assertSame(0, DB::table('parts')->count());
        }
    }

    public function test_candidate_created_during_preparation_keeps_its_committed_articles(): void
    {
        $id = $this->arrival('prepared-race');
        DB::table('binaries')->where('collections_id', $id)->update(['totalparts' => 2]);
        $otherManifest = ['legacy_release_id' => null, 'identity' => sha1('prepared-race'), 'subject' => 'Movie',
            'fromname' => 'poster', 'date' => now()->format('Y-m-d H:i:s'), 'groups_id' => 1,
            'regex_id' => 0, 'expected_files' => 1, 'groups' => ['alt.test'],
            'files' => [['key' => md5('prepared-race'), 'subject' => 'movie.mkv', 'totalparts' => 2,
                'segments' => [['number' => 2, 'messageid' => 'later@article', 'bytes' => 5]]]], 'conflict' => false];
        config(['database.connections.interleaved_admission' => config('database.connections.mariadb')]);
        $interleaved = false;
        $compressions = 0;
        ManifestCompressionProbe::$onCompress = function () use ($otherManifest, &$interleaved, &$compressions): void {
            $compressions++;
            if ($interleaved) {
                return;
            }
            $interleaved = true;
            $probe = ManifestCompressionProbe::$onCompress;
            ManifestCompressionProbe::$onCompress = null;
            DB::setDefaultConnection('interleaved_admission');
            try {
                app(CandidateRepository::class)->enqueue($otherManifest);
            } finally {
                DB::setDefaultConnection('mariadb');
                ManifestCompressionProbe::$onCompress = $probe;
            }
        };
        try {
            app(CandidateRepository::class)->archive($id);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
            DB::setDefaultConnection('mariadb');
            DB::purge('interleaved_admission');
        }
        $this->assertTrue($interleaved);
        $candidate = DB::table('release_verification_candidates')->sole();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame(['prepared-race@article', 'later@article'], array_column($manifest['files'][0]['segments'], 'messageid'));
        $this->assertSame(2, $compressions, 'A late candidate needs a fresh, locked merge.');
        $this->assertSame(2, (int) $candidate->version);
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_preparation_retains_capacity_already_owned_by_an_outer_transaction(): void
    {
        $id = $this->arrival('inherited-capacity');
        $other = $this->connection();
        $compressions = 0;
        DB::beginTransaction();
        DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
        ManifestCompressionProbe::$onCompress = function () use ($other, &$compressions): void {
            $compressions++;
            $other->beginTransaction();
            try {
                try {
                    $other->query('SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE NOWAIT')->fetchAll();
                    $this->fail('Admission must not release its caller\'s capacity ownership.');
                } catch (\PDOException $error) {
                    $this->assertSame(1205, (int) $error->errorInfo[1]);
                }
            } finally {
                $other->rollBack();
            }
        };
        try {
            app(CandidateRepository::class)->archive($id);
        } finally {
            ManifestCompressionProbe::$onCompress = null;
            DB::rollBack();
        }
        $this->assertSame(1, $compressions);
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame([], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
    }

    /** @return iterable<string, array{bool}> */
    public static function heldCollectionIndexes(): iterable
    {
        yield 'primary row held' => [false];
        yield 'unique hash held' => [true];
    }

    #[DataProvider('heldCollectionIndexes')]
    public function test_locked_collection_keeps_capacity_free_and_retries_without_losing_headers(bool $hashIndex): void
    {
        $id = $this->arrival('busy');
        $otherId = $this->arrival('unrelated');
        $other = $this->connection();
        $other->beginTransaction();
        $lock = $other->prepare($hashIndex
            ? 'SELECT collectionhash FROM collections FORCE INDEX (ix_collection_collectionhash) WHERE collectionhash = ? LOCK IN SHARE MODE'
            : 'SELECT id FROM collections FORCE INDEX (PRIMARY) WHERE id = ? FOR UPDATE');
        $lock->execute([$hashIndex ? sha1('busy', true) : $id]);
        $lock->fetchAll();
        $capacityReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$capacityReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_state')) {
                $capacityReads++;
            }
        });
        $repository = app(CandidateRepository::class);
        try {
            $repository->archive($id);
            $this->assertSame(0, $capacityReads);
            $this->assertSame(2, DB::table('collections')->count());
            $this->assertSame(2, DB::table('parts')->count());
            $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
            $repository->archive($otherId);
            $this->assertSame(1, DB::table('release_verification_candidates')->count());
            $this->assertSame(1, DB::table('collections')->count());
            $other->query('SELECT pending_bytes FROM release_verification_state WHERE id = 1 FOR UPDATE')->fetchAll();
            $capacityReads = 0;
            $repository->archive($id);
            $this->assertSame(0, $capacityReads);
        } finally {
            $other->rollBack();
        }

        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([$id]));
        $this->assertSame(1, DB::table('parts')->count());
        $repository->archive($id);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(2, DB::table('release_verification_candidates')->count());
        $this->assertSame((int) DB::table('release_verification_candidates')->sum('manifest_bytes'),
            (int) DB::table('release_verification_state')->value('pending_bytes'));
        $candidate = DB::table('release_verification_candidates')->where('source_identity', sha1('busy'))->first();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame('busy@article', $manifest['files'][0]['segments'][0]['messageid']);
    }

    public function test_locking_lookup_uses_unique_hash_index_before_capacity(): void
    {
        $id = $this->arrival('plan');
        $key = null;
        DB::listen(static function (QueryExecuted $query) use (&$key): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `collections`')
                && str_contains($query->sql, 'for update')) {
                $plan = DB::selectOne('EXPLAIN '.$query->sql, $query->bindings);
                $key = $plan->key;
            }
        });

        app(CandidateRepository::class)->archive($id);

        $this->assertSame('ix_collection_collectionhash', $key);
    }

    /** @return iterable<string, array{bool}> */
    public static function snapshotTransactionModes(): iterable
    {
        yield 'standalone archival' => [false];
        yield 'caller-owned transaction' => [true];
    }

    public function test_filtered_oversized_replay_uses_locked_status_despite_an_older_repeatable_read_view(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue(['identity' => sha1('oversized-replay'), 'groups_id' => 1, 'files' => []]);
        $claim = $repository->claim();
        $this->assertNotNull($claim);
        $id = $this->arrival('oversized-replay');
        $binary = (int) DB::table('binaries')->where('collections_id', $id)->value('id');
        for ($start = 2; $start <= 44001; $start += 500) {
            $parts = [];
            for ($number = $start; $number < $start + 500; $number++) {
                $parts[] = ['binaries_id' => $binary, 'partnumber' => $number,
                    'messageid' => str_repeat('x', 255), 'size' => 5];
            }
            DB::table('parts')->insert($parts);
        }
        $this->assertGreaterThan(16777216, 44000 * (128 + 255));
        config(['database.connections.policy_filter' => config('database.connections.mariadb')]);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame('verifying', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));
            DB::setDefaultConnection('policy_filter');
            try {
                $repository->rejectByPolicy($claim, 'Test policy');
                $this->assertSame('filtered', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));
            } finally {
                DB::setDefaultConnection('mariadb');
            }
            $this->assertSame('verifying', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));

            $repository->archive($id);
            DB::commit();
        } finally {
            DB::setDefaultConnection('mariadb');
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('policy_filter');
        }
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('filtered', $candidate->status);
        $this->assertSame('', $candidate->manifest_path);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function capacitySnapshotModes(): iterable
    {
        yield 'new standalone' => [false, false];
        yield 'new outer transaction' => [true, false];
        yield 'legacy standalone' => [false, true];
        yield 'legacy outer transaction' => [true, true];
    }

    #[DataProvider('capacitySnapshotModes')]
    public function test_header_snapshot_keeps_capacity_available_and_parent_locked(bool $outerTransaction, bool $legacy): void
    {
        $id = $this->arrival('snapshot');
        if ($legacy) {
            DB::statement('ALTER TABLE collections ADD releases_id BIGINT UNSIGNED NULL');
            DB::statement('CREATE TABLE releases (id BIGINT UNSIGNED PRIMARY KEY, nzbstatus INT NOT NULL) ENGINE=InnoDB');
            DB::table('releases')->insert(['id' => 7, 'nzbstatus' => NzbService::NZB_NONE]);
            DB::table('collections')->where('id', $id)->update(['filecheck' => 4, 'releases_id' => 7]);
        }
        for ($number = 1; $number <= 200; $number++) {
            $binary = DB::table('binaries')->insertGetId(['collections_id' => $id,
                'binaryhash' => md5('batch-'.$number, true), 'name' => 'file-'.$number, 'totalparts' => 1]);
            DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1,
                'messageid' => 'batch-'.$number.'@article', 'size' => 5]);
        }
        $other = $this->connection();
        $snapshots = 0;
        $parentProtected = false;
        $archiving = true;
        DB::listen(function (QueryExecuted $query) use ($id, $other, &$snapshots, &$parentProtected, &$archiving): void {
            if (! $archiving || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from `parts`')) {
                return;
            }
            $snapshots++;
            $other->beginTransaction();
            try {
                try {
                    $other->query('SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE NOWAIT')->fetchAll();
                } catch (\PDOException $error) {
                    $this->assertSame(1205, (int) $error->errorInfo[1]);
                    $this->fail('The header snapshot must not hold the shared capacity lock.');
                }
                $this->assertSame(0, (int) $other->query('SELECT pending_bytes FROM release_verification_state WHERE id = 1')->fetchColumn());
                try {
                    $lock = $other->prepare('SELECT id FROM collections WHERE id = ? FOR UPDATE NOWAIT');
                    $lock->execute([$id]);
                    $this->fail('The header snapshot must retain its collection parent lock.');
                } catch (\PDOException $error) {
                    $this->assertSame(1205, (int) $error->errorInfo[1]);
                    $parentProtected = true;
                }
            } finally {
                $other->rollBack();
            }
        });
        if ($outerTransaction) {
            DB::beginTransaction();
        }
        try {
            app(CandidateRepository::class)->archive($id);
            if ($outerTransaction) {
                DB::commit();
            }
        } finally {
            $archiving = false;
            if ($outerTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(3, $snapshots);
        $this->assertTrue($parentProtected);
        $candidate = DB::table('release_verification_candidates')->sole();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertCount(201, $manifest['files']);
        $articles = array_merge(...array_column($manifest['files'], 'segments'));
        $this->assertCount(201, $articles);
        $this->assertContains('snapshot@article', array_column($articles, 'messageid'));
        $this->assertSame(1005, array_sum(array_column($articles, 'bytes')));
        $this->assertSame($legacy ? 7 : null, $manifest['legacy_release_id']);
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    #[DataProvider('snapshotTransactionModes')]
    public function test_late_parts_committed_after_hash_read_are_preserved_in_repeatable_read(bool $outerTransaction): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $id = $this->arrival('late');
        $binary = (int) DB::table('binaries')->where('collections_id', $id)->value('id');
        $other = $this->connection();
        $committed = false;
        DB::listen(static function (QueryExecuted $query) use ($id, $binary, $other, &$committed): void {
            if (! $committed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'collectionhash')
                && str_contains($query->sql, 'collections') && $query->bindings === [$id]) {
                $committed = true;
                $other->beginTransaction();
                try {
                    $statement = $other->prepare('UPDATE collections SET filesize = filesize + 7 WHERE id = ?');
                    $statement->execute([$id]);
                    $statement = $other->prepare('UPDATE binaries SET totalparts = 2 WHERE id = ?');
                    $statement->execute([$binary]);
                    $statement = $other->prepare('INSERT INTO parts (binaries_id, partnumber, messageid, size) VALUES (?, 2, ?, 7)');
                    $statement->execute([$binary, 'late-second@article']);
                    $other->commit();
                } catch (\Throwable $error) {
                    $other->rollBack();
                    throw $error;
                }
            }
        });

        if ($outerTransaction) {
            DB::beginTransaction();
        }
        try {
            app(CandidateRepository::class)->archive($id);
            if ($outerTransaction) {
                DB::commit();
            }
        } catch (\Throwable $error) {
            if ($outerTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $error;
        }

        $this->assertTrue($committed);
        $candidate = DB::table('release_verification_candidates')->sole();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        $this->assertSame(['late@article', 'late-second@article'], array_column($manifest['files'][0]['segments'], 'messageid'));
        $this->assertSame(2, $manifest['files'][0]['totalparts']);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_missing_collection_does_not_wait_on_locked_capacity(): void
    {
        $other = $this->connection();
        $other->beginTransaction();
        $other->query('SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE')->fetchAll();
        try {
            app(CandidateRepository::class)->archive(999);
            $this->assertSame(0, DB::table('release_verification_candidates')->count());
        } finally {
            $other->rollBack();
        }
    }

    public function test_claim_skips_locked_due_head_and_leaves_capacity_available(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['first', 'second'] as $name) {
            $repository->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'files' => []]);
        }
        $ids = DB::table('release_verification_candidates')->orderBy('id')->pluck('id')->all();
        $bytes = (int) DB::table('release_verification_state')->value('pending_bytes');
        $other = $this->connection();
        $other->beginTransaction();
        $statement = $other->prepare('SELECT id FROM release_verification_candidates FORCE INDEX (PRIMARY) WHERE id = ? FOR UPDATE');
        $statement->execute([$ids[0]]);
        $statement->fetchAll();
        $other->query('SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE')->fetchAll();
        try {
            $claim = $repository->claim();
            $this->assertSame((int) $ids[1], (int) $claim->id);
            $statement = $other->prepare('UPDATE release_verification_candidates SET status = ? WHERE id = ?');
            $statement->execute(['unknown', $ids[0]]);
        } finally {
            $other->commit();
        }
        $next = $repository->claim();
        $this->assertSame((int) $ids[0], (int) $next->id);
        $this->assertNotSame($claim->lease_token, $next->lease_token);
        $this->assertNull($repository->claim());
        $this->assertSame($bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_claim_queue_lookup_uses_due_index_without_sorting_all_statuses(): void
    {
        $this->freezeTime();
        $rows = [];
        for ($number = 1; $number <= 3000; $number++) {
            $rows[] = ['identity' => sha1('plan-'.$number), 'groups_id' => 1, 'manifest_path' => '',
                'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64),
                'status' => ['pending', 'unknown', 'verifying'][$number % 3],
                'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('release_verification_candidates')->insert($chunk);
        }
        $lookups = [];
        DB::listen(static function (QueryExecuted $query) use (&$lookups): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`release_verification_candidates`')
                && str_contains($query->sql, 'order by') && ! str_contains($query->sql, 'for update')) {
                $lookups[] = [$query->sql, $query->bindings];
            }
        });
        $attempts = [];
        $this->assertNull(app(CandidateRepository::class)->claim(static function (int $id) use (&$attempts): bool {
            $attempts[] = $id;

            return false;
        }));
        $this->assertSame(range(1, 100), $attempts);
        $this->assertNotEmpty($lookups);
        foreach ($lookups as [$sql, $bindings]) {
            $plan = DB::select('EXPLAIN '.$sql, $bindings)[0];
            $this->assertSame('verification_due', $plan->key);
            $this->assertStringNotContainsString('filesort', strtolower((string) $plan->Extra));
        }
    }

    public function test_refused_claim_releases_its_row_before_trying_another_candidate(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['first', 'second'] as $name) {
            $repository->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'files' => []]);
        }
        $ids = DB::table('release_verification_candidates')->orderBy('id')->pluck('id')->all();
        $other = $this->connection();
        $claim = $repository->claim(static function (int $id) use ($ids, $other): bool {
            if ($id === (int) $ids[0]) {
                return false;
            }
            $statement = $other->prepare('UPDATE release_verification_candidates SET next_attempt_at = ? WHERE id = ?');
            $statement->execute([now()->addHour()->format('Y-m-d H:i:s'), $ids[0]]);

            return true;
        });

        $this->assertSame((int) $ids[1], (int) $claim->id);
        $this->assertNull(DB::table('release_verification_candidates')->where('id', $ids[0])->value('lease_token'));
    }

    public function test_claim_revalidates_selected_ids_and_uses_primary_locking_plan(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['first', 'second'] as $name) {
            $repository->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'files' => []]);
        }
        $ids = DB::table('release_verification_candidates')->orderBy('id')->pluck('id')->all();
        $other = $this->connection();
        $changed = false;
        $keys = [];
        DB::listen(static function (QueryExecuted $query) use ($ids, $other, &$changed, &$keys): void {
            if (! $changed && str_starts_with($query->sql, 'select')
                && str_contains($query->sql, '`release_verification_candidates`') && str_contains($query->sql, 'order by')) {
                $changed = true;
                $statement = $other->prepare('UPDATE release_verification_candidates SET lease_until = ? WHERE id = ?');
                $statement->execute([now()->addHour()->format('Y-m-d H:i:s'), $ids[0]]);
            }
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_candidates')
                && str_contains($query->sql, 'for update')) {
                $plan = DB::selectOne('EXPLAIN '.$query->sql, $query->bindings);
                if ($plan->key !== null) {
                    $keys[] = $plan->key;
                }
            }
        });

        $claim = $repository->claim();

        $this->assertTrue($changed);
        $this->assertSame((int) $ids[1], (int) $claim->id);
        $this->assertSame(['PRIMARY'], array_values(array_unique($keys)));
    }

    private function connection(): PDO
    {
        $pdo = new PDO('mysql:host='.$this->host.';port=3306;dbname='.$this->database.';charset=utf8mb4', $this->username, $this->password);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $pdo;
    }

    private function arrival(string $identity): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1($identity, true),
            'subject' => 'Movie', 'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1,
            'date' => now()->format('Y-m-d H:i:s')]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5($identity, true),
            'name' => 'movie.mkv', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1, 'messageid' => $identity.'@article', 'size' => 5]);

        return $id;
    }
}
