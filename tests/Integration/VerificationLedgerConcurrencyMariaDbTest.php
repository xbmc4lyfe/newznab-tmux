<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\VerificationLedger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Lock behavior of the capacity ledger against real InnoDB row locks. */
final class VerificationLedgerConcurrencyMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private string $database = '';

    private string $storagePath = '';

    private string $host = '';

    private string $username = '';

    private string $password = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = (string) getenv('VERIFICATION_LOCK_TEST_HOST');
        if ($this->host === '') {
            $this->markTestSkipped('Set VERIFICATION_LOCK_TEST_HOST to an isolated MariaDB test server.');
        }
        $this->username = (string) (getenv('VERIFICATION_LOCK_TEST_USERNAME') ?: 'root');
        $this->password = (string) getenv('VERIFICATION_LOCK_TEST_PASSWORD');
        $this->administrator = new PDO('mysql:host='.$this->host.';port=3306;charset=utf8mb4', $this->username, $this->password);
        $this->database = 'nntmux_ledger_test_'.bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
        $this->storagePath = storage_path('framework/testing/ledger-'.bin2hex(random_bytes(8)));
        config(['database.default' => 'mariadb', 'database.connections.mariadb.host' => $this->host,
            'database.connections.mariadb.port' => 3306, 'database.connections.mariadb.database' => $this->database,
            'database.connections.mariadb.username' => $this->username, 'database.connections.mariadb.password' => $this->password,
            'database.connections.mariadb.url' => null, 'database.connections.mariadb.unix_socket' => '',
            'database.connections.mariadb.prefix' => '', 'release_verification.required' => true, 'nntmux.echocli' => false,
            'release_verification.storage_path' => $this->storagePath, 'release_verification.manifest_storage' => 'database',
            'release_verification.pending_bytes' => 1048576]);
        DB::purge();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        foreach (['2026_10_11_154028_create_release_verification_candidates_table', '2026_10_17_220000_add_header_check_pending_to_release_verification_candidates', '2026_10_17_000000_create_release_verification_manifests_table',
            '2026_10_17_120000_create_release_verification_locks_table', '2026_10_17_180000_add_release_verification_publication_stripes',
            '2026_10_12_004819_create_release_verification_scratch_table', '2026_10_12_053000_add_shared_verification_scratch_budget', '2026_10_17_200000_add_host_to_release_verification_scratch'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
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
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('mariadb');
            if ($this->administrator !== null && preg_match('/^nntmux_ledger_test_[a-f0-9]{16}$/D', $this->database) === 1) {
                $this->administrator->exec('DROP DATABASE IF EXISTS `'.$this->database.'`');
            }
            if (preg_match('~/framework/testing/ledger-[a-f0-9]{16}$~D', $this->storagePath) === 1) {
                app('files')->deleteDirectory($this->storagePath);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function storageModes(): iterable
    {
        yield 'database manifests run concurrently' => ['database', false];
        yield 'file manifests keep the global lock' => ['files', true];
    }

    #[DataProvider('storageModes')]
    public function test_archive_holds_the_capacity_row_only_in_legacy_file_mode(string $storage, bool $globalLock): void
    {
        config(['release_verification.manifest_storage' => $storage]);
        $id = $this->arrival('concurrent');
        $other = $this->connection();
        $probed = false;
        DB::listen(function (QueryExecuted $query) use ($other, $globalLock, &$probed): void {
            if ($probed || preg_match('/^delete from [`"]?collections[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $probed = true;
            // A concurrent writer applies its own delta while the archive deletes its headers.
            try {
                $other->exec('UPDATE release_verification_state SET pending_bytes = pending_bytes + 7 WHERE id = 1');
                $this->assertFalse($globalLock, 'Legacy file manifests must hold the capacity row for the whole archive.');
            } catch (\PDOException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
                $this->assertTrue($globalLock, 'Database manifests must not hold the capacity row while deleting headers.');
            }
        });

        app(CandidateRepository::class)->archive($id);

        $this->assertTrue($probed);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame((int) $candidate->manifest_bytes + ($globalLock ? 0 : 7), (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_archive_leaves_candidate_and_manifest_gaps_open_for_concurrent_admissions(): void
    {
        $id = $this->arrival('gap-owner');
        $other = $this->connection();
        $inserted = [];
        DB::listen(function (QueryExecuted $query) use ($other, &$inserted): void {
            // After the candidate lookup and the manifest insert, a second admission inserts its own
            // candidate and manifest; a gap lock from either lookup would make it wait (and deadlock).
            if ($inserted !== [] || preg_match('/^insert into [`"]?release_verification_manifests[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $other->beginTransaction();
            try {
                $other->prepare("INSERT INTO release_verification_candidates (identity, source_identity, groups_id, manifest_path, manifest_bytes,
                    manifest_hash, status, last_unique_at, next_attempt_at) VALUES (?, ?, 1, '', 0, ?, 'ready', NOW(), NOW())")
                    ->execute([sha1('gap-other'), sha1('gap-other'), str_repeat('0', 64)]);
                $other->prepare('INSERT INTO release_verification_manifests (name, body, bytes) VALUES (?, ?, 1)')
                    ->execute([str_repeat('f', 64).'.json.gz', 'x']);
                $inserted[] = 'both';
            } catch (\PDOException $error) {
                $inserted[] = (int) $error->errorInfo[1];
            } finally {
                $other->rollBack();
            }
        });

        app(CandidateRepository::class)->archive($id);

        $this->assertSame(['both'], $inserted, 'Concurrent admissions must not wait on gap locks.');
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
    }

    public function test_re_archiving_leaves_neighbouring_uploads_admissible(): void
    {
        $this->partialSchema();
        app(CandidateRepository::class)->archive($this->arrival('gap-owner', totalParts: 3));
        $id = $this->arrival('gap-owner', part: 2, totalParts: 3);
        // Uploads whose source identities sort right beside this one in the source index.
        $owner = sha1('gap-owner');
        $neighbours = [substr($owner, 0, 39).'0', substr($owner, 0, 38).'ff'];
        $other = $this->connection();
        $inserted = [];
        DB::listen(function (QueryExecuted $query) use ($other, $neighbours, &$inserted): void {
            if ($inserted !== [] || preg_match('/^insert into [`"]?release_verification_manifests[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $other->beginTransaction();
            try {
                foreach ($neighbours as $neighbour) {
                    $this->insertCandidate($other, $neighbour);
                }
                $inserted[] = 'both';
            } catch (\PDOException $error) {
                $inserted[] = (int) $error->errorInfo[1];
            } finally {
                $other->rollBack();
            }
        });

        app(CandidateRepository::class)->archive($id);

        $this->assertSame(['both'], $inserted, 'Re-archiving one upload must not gap-lock the uploads beside it.');
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
    }

    public function test_re_archiving_locks_the_uploads_candidates_in_primary_key_order_first(): void
    {
        $this->partialSchema();
        app(CandidateRepository::class)->archive($this->arrival('lock-order', totalParts: 3));
        // A second, older-looking row of the same upload, as a conflicting post archived apart leaves.
        $first = DB::table('release_verification_candidates')->sole();
        DB::table('release_verification_candidates')->insert(['identity' => sha1('lock-order-apart'), 'source_identity' => $first->source_identity,
            'groups_id' => 1, 'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'pending',
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (preg_match('/release_verification_candidates.* for update/i', $query->sql) === 1 && ! str_contains($query->sql, 'skip locked')) {
                $locks[] = $query->sql;
            }
        });

        app(CandidateRepository::class)->archive($this->arrival('lock-order', part: 2, totalParts: 3));

        // Batched candidate updates lock in primary-key order: so must archive(), before it locks the newest row
        // alone, or the two wait on each other.
        $this->assertNotEmpty($locks);
        $this->assertMatchesRegularExpression('/force index \(`?primary`?\).* in \(.*order by `?id`? asc .*for update/i', $locks[0]);
    }

    public function test_promotion_skips_rows_another_session_holds_without_waiting(): void
    {
        $this->partialSchema();
        config(['release_verification.partial_after_hours' => 4]);
        $stalled = now()->subHours(5)->format('Y-m-d H:i:s');
        $inWindow = now()->subHour()->format('Y-m-d H:i:s');
        foreach (['held-stalled' => $stalled, 'held-waiting' => $inWindow, 'free-stalled' => $stalled] as $name => $last) {
            DB::table('release_verification_candidates')->insert(['identity' => sha1($name), 'source_identity' => sha1($name), 'groups_id' => 1,
                'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'waiting_upload',
                'last_unique_at' => $last, 'next_attempt_at' => now()->subMinute()->format('Y-m-d H:i:s')]);
        }
        $before = DB::table('release_verification_candidates')->orderBy('id')->get()->keyBy('identity');
        $other = $this->connection();
        $other->beginTransaction();
        $other->prepare('SELECT id FROM release_verification_candidates WHERE identity IN (?, ?) FOR UPDATE')->execute([sha1('held-stalled'), sha1('held-waiting')]);
        try {
            $started = microtime(true);
            $promoted = app(CandidateRepository::class)->promotePartial(10);
            $elapsed = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        $this->assertSame(1, $promoted);
        $this->assertLessThan(0.9, $elapsed, 'Promotion never waits on rows ingestion holds.');
        $after = DB::table('release_verification_candidates')->orderBy('id')->get()->keyBy('identity');
        $this->assertSame('ready', $after[sha1('free-stalled')]->status);
        foreach (['held-stalled', 'held-waiting'] as $name) {
            $this->assertSame([$before[sha1($name)]->status, $before[sha1($name)]->next_attempt_at],
                [$after[sha1($name)]->status, $after[sha1($name)]->next_attempt_at], $name.' is left to the session holding it.');
        }
    }

    public function test_promotion_takes_later_stalled_uploads_past_a_prefix_another_session_holds(): void
    {
        $this->partialSchema();
        config(['release_verification.partial_after_hours' => 4]);
        $stalled = now()->subHours(5)->format('Y-m-d H:i:s');
        foreach (['held-first' => 2, 'free-later' => 1] as $name => $minutesDue) {
            DB::table('release_verification_candidates')->insert(['identity' => sha1($name), 'source_identity' => sha1($name), 'groups_id' => 1,
                'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'waiting_upload',
                'last_unique_at' => $stalled, 'next_attempt_at' => now()->subMinutes($minutesDue)->format('Y-m-d H:i:s')]);
        }
        $other = $this->connection();
        $other->beginTransaction();
        $other->prepare('SELECT id FROM release_verification_candidates WHERE identity = ? FOR UPDATE')->execute([sha1('held-first')]);
        try {
            // A one-row window: the oldest due row is busy, so the next one is taken instead.
            $promoted = app(CandidateRepository::class)->promotePartial(1);
        } finally {
            $other->rollBack();
        }

        $this->assertSame(1, $promoted);
        $this->assertSame(['held-first' => 'waiting_upload', 'free-later' => 'ready'],
            ['held-first' => DB::table('release_verification_candidates')->where('identity', sha1('held-first'))->value('status'),
                'free-later' => DB::table('release_verification_candidates')->where('identity', sha1('free-later'))->value('status')]);
    }

    public function test_admissions_into_the_due_queue_never_wait_behind_promotion(): void
    {
        $this->partialSchema();
        config(['release_verification.partial_after_hours' => 4]);
        $row = static fn (string $name, string $status, string $due, string $last): array => ['identity' => sha1($name), 'source_identity' => sha1($name),
            'groups_id' => 1, 'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => $status,
            'last_unique_at' => $last, 'next_attempt_at' => $due];
        $stalled = now()->subHours(5)->format('Y-m-d H:i:s');
        DB::table('release_verification_candidates')->insert([
            $row('due', 'waiting_upload', now()->subMinute()->format('Y-m-d H:i:s'), $stalled),
            $row('later', 'waiting_upload', now()->addHour()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')),
            $row('arriving', 'pending', now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')),
        ]);
        $other = $this->connection();
        $result = [];
        DB::listen(function (QueryExecuted $query) use ($other, &$result): void {
            if ($result !== [] || ! str_contains(strtolower($query->sql), 'skip locked')) {
                return;
            }
            // Mid-promotion: an incomplete upload is admitted into the due range.
            try {
                $other->prepare("UPDATE release_verification_candidates SET status = 'waiting_upload', next_attempt_at = NOW() WHERE identity = ?")
                    ->execute([sha1('arriving')]);
                $result[] = 'admitted';
            } catch (\PDOException $error) {
                $result[] = (int) $error->errorInfo[1];
            }
        });

        $this->assertSame(1, app(CandidateRepository::class)->promotePartial(10));
        $this->assertSame(['admitted'], $result, 'Promotion takes no gap locks on the due queue.');
    }

    /** The late-article columns partial publication adds, as deployed. */
    private function partialSchema(): void
    {
        (require database_path('migrations/2026_10_17_230000_add_upgrades_release_id_to_release_verification_candidates.php'))->up();
        DB::statement('CREATE TABLE releases (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            late_article_candidate_id BIGINT UNSIGNED NULL, KEY releases_late_article_candidate_id_index (late_article_candidate_id)) ENGINE=InnoDB');
    }

    public function test_first_time_imports_leave_the_identity_gap_open_for_concurrent_imports(): void
    {
        $other = $this->connection();
        $result = null;
        DB::listen(function (QueryExecuted $query) use ($other, &$result): void {
            if ($result !== null || preg_match('/^insert into [`"]?release_verification_manifests[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $other->beginTransaction();
            try {
                $this->insertCandidate($other, sha1('import:other'));
                $result = 'inserted';
            } catch (\PDOException $error) {
                $result = (int) $error->errorInfo[1];
            } finally {
                $other->rollBack();
            }
        });

        $this->assertTrue(app(CandidateRepository::class)->enqueue(['identity' => sha1('import:first'), 'groups_id' => 1, 'files' => []]));

        $this->assertSame('inserted', $result, 'A first-time import must not gap-lock the identity index.');
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame((int) $candidate->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_an_identity_committed_during_import_reruns_admission_against_that_row(): void
    {
        $identity = sha1('import:raced');
        $other = $this->connection();
        $committed = false;
        DB::listen(function (QueryExecuted $query) use ($other, $identity, &$committed): void {
            if ($committed || preg_match('/^insert into [`"]?release_verification_manifests[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $committed = true;
            $this->insertCandidate($other, $identity);
        });

        $this->assertTrue(app(CandidateRepository::class)->enqueue(['identity' => $identity, 'groups_id' => 1, 'files' => []]));

        $this->assertTrue($committed);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('', $candidate->manifest_path, 'The committed admission is kept.');
        $this->assertSame(0, DB::table('release_verification_manifests')->count(), 'The rerun leaves no unreferenced manifest.');
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_scratch_reservations_hold_the_shared_budget_only_for_their_final_delta(): void
    {
        config(['release_verification.scratch_bytes' => 1000, 'release_verification.free_bytes' => 0]);
        app('files')->ensureDirectoryExists($this->storagePath, 0700);
        DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true, 'reserved_bytes' => 0]);
        $entry = DB::table('release_verification_scratch')->insertGetId(['audit_release_id' => 1, 'reserved_bytes' => 0]);
        $other = $this->connection();
        $probed = false;
        DB::listen(function (QueryExecuted $query) use ($other, &$probed): void {
            if ($probed || preg_match('/^select \\* from [`"]?release_verification_scratch[`"]? where/i', $query->sql) !== 1) {
                return;
            }
            $probed = true;
            // Another worker's reservation commits while this one holds only its own entry.
            $other->exec('UPDATE release_verification_scratch_budget SET reserved_bytes = reserved_bytes + 300 WHERE id = 1');
        });

        (new ScratchBudget)->reserve($entry, 600);

        $this->assertTrue($probed);
        $this->assertSame(900, (int) DB::table('release_verification_scratch_budget')->value('reserved_bytes'));
        try {
            (new ScratchBudget)->reserve($entry, 701);
            $this->fail('A reservation beyond the shared limit must be refused.');
        } catch (RuntimeException $error) {
            $this->assertSame('Verification is waiting for shared scratch capacity.', $error->getMessage());
        }
        $this->assertSame(900, (int) DB::table('release_verification_scratch_budget')->value('reserved_bytes'));
        $this->assertSame(600, (int) DB::table('release_verification_scratch')->where('id', $entry)->value('reserved_bytes'));
        (new ScratchBudget)->forget($entry);
        $this->assertSame(300, (int) DB::table('release_verification_scratch_budget')->value('reserved_bytes'));
    }

    public function test_capacity_refused_at_commit_rolls_back_the_archive_and_keeps_its_headers(): void
    {
        $id = $this->arrival('late-full');
        $other = $this->connection();
        $filled = false;
        DB::listen(function (QueryExecuted $query) use ($other, &$filled): void {
            if ($filled || preg_match('/^delete from [`"]?collections[`"]?/i', $query->sql) !== 1) {
                return;
            }
            $filled = true;
            // Another admission fills the ledger after this one's advisory capacity check.
            $other->exec('UPDATE release_verification_state SET pending_bytes = 1048570 WHERE id = 1');
        });

        try {
            app(CandidateRepository::class)->archive($id);
            $this->fail('An archive that no longer fits must be refused.');
        } catch (RuntimeException $error) {
            $this->assertSame('Pending verification capacity is full; original headers retained.', $error->getMessage());
        }

        $this->assertTrue($filled);
        $this->assertSame(1048570, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        $this->assertSame(0, DB::table('release_verification_manifests')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('parts')->count());
    }

    public function test_manifest_cleanup_waits_for_an_uncommitted_reference_and_then_keeps_it(): void
    {
        $store = app(ManifestStore::class);
        $prepared = $store->prepare(['identity' => 'cleanup-race', 'files' => [], 'note' => str_repeat('compressible ', 20)]);
        $name = $store->writePrepared($prepared);
        app('files')->ensureDirectoryExists(dirname($store->path($name)), 0700);
        file_put_contents($store->path($name), $prepared->compressed);
        $other = $this->connection();
        // An admission reusing this manifest locks its row, then commits a candidate that references it.
        $other->beginTransaction();
        $other->prepare('SELECT name FROM release_verification_manifests WHERE name = ? FOR UPDATE')->execute([$name]);
        $other->prepare("INSERT INTO release_verification_candidates (identity, source_identity, groups_id, manifest_path, manifest_bytes,
            manifest_hash, status, last_unique_at, next_attempt_at) VALUES (?, ?, 1, ?, ?, ?, 'ready', NOW(), NOW())")
            ->execute([sha1('cleanup-race'), sha1('cleanup-race'), $name, strlen($prepared->compressed), substr($name, 0, 64)]);
        try {
            $store->deleteIfUnreferenced($name);
            $this->fail('Cleanup must wait for the admission that holds the manifest row.');
        } catch (QueryException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        } finally {
            $other->commit();
        }

        $store->deleteIfUnreferenced($name);
        $this->assertSame(1, DB::table('release_verification_manifests')->where('name', $name)->count());
        $this->assertFileExists($store->path($name));

        $other->exec('DELETE FROM release_verification_candidates');
        $store->deleteIfUnreferenced($name);
        $this->assertSame(0, DB::table('release_verification_manifests')->where('name', $name)->count());
        $this->assertFileDoesNotExist($store->path($name));
    }

    public function test_publications_serialize_on_their_own_row_and_take_capacity_last(): void
    {
        config(['release_verification.pending_bytes' => 100]);
        DB::table('release_verification_state')->where('id', 1)->update(['pending_bytes' => 40]);
        $ledger = app(VerificationLedger::class);
        $other = $this->connection();
        DB::beginTransaction();
        try {
            $ledger->enterPublication(['size:5']);
            // Only this key's stripe is held; other stripes and the shared fallback row stay free.
            $this->assertLocked($other, $this->stripeLock('size:5'));
            $this->assertNotLocked($other, $this->stripeLock($this->keyOnAnotherStripe('size:5')));
            // The shared row is held in share mode: other striped publications proceed, a keyless one waits.
            $this->assertNotLocked($other, 'SELECT name FROM release_verification_locks WHERE name = \'publication\' LOCK IN SHARE MODE NOWAIT');
            $this->assertLocked($other, 'SELECT name FROM release_verification_locks WHERE name = \'publication\' FOR UPDATE NOWAIT');
            $this->assertNotLocked($other, 'SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE NOWAIT');
            $this->assertFalse($ledger->adjust(61), 'A delta beyond capacity changes nothing.');
            $this->assertTrue($ledger->adjust(60));
            $this->assertLocked($other, 'SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE NOWAIT');
            $this->assertTrue($ledger->adjust(-30));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(70, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_publications_fall_back_to_one_shared_row_before_the_stripes_exist(): void
    {
        DB::table('release_verification_locks')->where('name', 'like', 'publication:%')->delete();
        $other = $this->connection();
        DB::beginTransaction();
        try {
            app(VerificationLedger::class)->enterPublication(['size:5']);
            $this->assertLocked($other, 'SELECT name FROM release_verification_locks WHERE name = \'publication\' FOR UPDATE NOWAIT');
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_keyless_publication_and_a_striped_publication_exclude_each_other(): void
    {
        $other = $this->connection();
        // A publication whose keys could not be resolved holds the shared row exclusively.
        $other->beginTransaction();
        try {
            $other->query("SELECT name FROM release_verification_locks WHERE name = 'publication' FOR UPDATE")->fetchAll();
            $this->assertPublicationWaits(['size:5']);
        } finally {
            $other->rollBack();
        }
        // A striped publication holds the shared row in share mode and its stripes exclusively.
        $other->beginTransaction();
        try {
            $other->query("SELECT name FROM release_verification_locks WHERE name = 'publication' LOCK IN SHARE MODE")->fetchAll();
            $other->query($this->stripeLock('size:5'))->fetchAll();
            $this->assertPublicationWaits([]);
            DB::beginTransaction();
            try {
                app(VerificationLedger::class)->enterPublication([$this->keyOnAnotherStripe('size:5')]);
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        } finally {
            $other->rollBack();
        }
    }

    public function test_entering_publication_leaves_the_read_view_for_the_duplicate_search(): void
    {
        $other = $this->connection();
        DB::beginTransaction();
        try {
            app(VerificationLedger::class)->enterPublication(['size:5']);
            // A release committed after the locks were granted (here, after entering) must be
            // visible to the duplicate search's plain reads that follow.
            $other->exec("INSERT INTO usenet_groups (id, name) VALUES (2, 'alt.committed.meanwhile')");
            $this->assertTrue(DB::table('usenet_groups')->where('id', 2)->exists());
        } finally {
            DB::rollBack();
        }
    }

    /** @param list<string> $keys */
    private function assertPublicationWaits(array $keys): void
    {
        DB::beginTransaction();
        try {
            app(VerificationLedger::class)->enterPublication($keys);
            $this->fail('The publication must wait for the conflicting one.');
        } catch (QueryException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_identical_articles_with_different_sizes_serialize_on_their_shared_fingerprint(): void
    {
        $first = VerificationLedger::publicationKeys(1000, 'ABCDEF', 'Movie.2020', 'Movie 2020', 0);
        $second = VerificationLedger::publicationKeys(1234, 'abcdef', 'Other.Upload', 'Other Upload', 0);
        $this->assertNotSame(VerificationLedger::publicationStripe('size:1000'), VerificationLedger::publicationStripe('size:1234'));
        $other = $this->connection();
        $other->beginTransaction();
        try {
            $stripes = array_map(VerificationLedger::publicationStripe(...), $second);
            $other->query("SELECT name FROM release_verification_locks WHERE name IN ('".implode("','", $stripes)."') FOR UPDATE")->fetchAll();
            DB::beginTransaction();
            try {
                app(VerificationLedger::class)->enterPublication($first);
                $this->fail('A publication sharing a duplicate-search key must wait for the other.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
            } finally {
                DB::rollBack();
            }
        } finally {
            $other->rollBack();
        }
    }

    public function test_names_equal_under_the_collation_share_a_publication_key(): void
    {
        $this->assertSame(VerificationLedger::publicationKeys(0, null, 'Café.Movie 2020 ', '', 0),
            VerificationLedger::publicationKeys(0, null, 'CAFE.movie.2020', '', 0));
        $this->assertSame(['name:moviex264'], VerificationLedger::publicationKeys(0, null, '', 'Movie-x264', 0));
        $this->assertSame([], VerificationLedger::publicationKeys(0, null, '', '', 0));
    }

    private function stripeLock(string $key): string
    {
        return "SELECT name FROM release_verification_locks WHERE name = '".VerificationLedger::publicationStripe($key)."' FOR UPDATE NOWAIT";
    }

    private function keyOnAnotherStripe(string $key): string
    {
        for ($i = 0; VerificationLedger::publicationStripe('size:'.$i) === VerificationLedger::publicationStripe($key); $i++) {
        }

        return 'size:'.$i;
    }

    private function assertLocked(PDO $other, string $sql): void
    {
        $other->beginTransaction();
        try {
            $other->query($sql)->fetchAll();
            $this->fail('Expected a held row lock: '.$sql);
        } catch (\PDOException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        } finally {
            $other->rollBack();
        }
    }

    private function assertNotLocked(PDO $other, string $sql): void
    {
        $other->beginTransaction();
        try {
            $other->query($sql)->fetchAll();
            $this->addToAssertionCount(1);
        } finally {
            $other->rollBack();
        }
    }

    private function insertCandidate(PDO $connection, string $identity): void
    {
        $connection->prepare("INSERT INTO release_verification_candidates (identity, source_identity, groups_id, manifest_path, manifest_bytes,
            manifest_hash, status, last_unique_at, next_attempt_at) VALUES (?, ?, 1, '', 0, ?, 'ready', NOW(), NOW())")
            ->execute([$identity, $identity, str_repeat('0', 64)]);
    }

    private function connection(): PDO
    {
        $pdo = new PDO('mysql:host='.$this->host.';port=3306;dbname='.$this->database.';charset=utf8mb4', $this->username, $this->password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $pdo;
    }

    private function arrival(string $identity, int $part = 1, int $totalParts = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1($identity, true),
            'subject' => 'Movie', 'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1,
            'date' => now()->format('Y-m-d H:i:s')]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5($identity, true),
            'name' => 'movie.mkv', 'totalparts' => $totalParts]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $identity.'-'.$part.'@article', 'size' => 5]);

        return $id;
    }
}
