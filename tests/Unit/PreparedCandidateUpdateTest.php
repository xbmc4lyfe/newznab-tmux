<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\PreparedCandidateUpdate;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class PreparedCandidateUpdateTest extends TestCase
{
    public function test_builder_updates_preserve_compilation_callbacks_and_immediate_visibility(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $callbacks = 0;
        foreach ([1 => 'first', 2 => 'second'] as $id => $reason) {
            $query = $connection->table('candidates')->where('id', $id);
            $query->beforeQuery(static function ($query) use (&$callbacks): void {
                $callbacks++;
                $query->where('active', 1);
            });
            self::assertSame(1, $updates->update($query, ['reason' => $reason, 'active' => false]));
            self::assertSame($reason, $pdo->query('SELECT reason FROM candidates WHERE id = '.$id)->fetchColumn());
            self::assertSame([], $query->beforeQueryCallbacks);
        }
        self::assertSame(2, $callbacks);
        $blocked = $connection->table('candidates')->where('id', 1);
        $blocked->beforeQuery(static function ($query): void {
            $query->where('active', 1);
        });
        self::assertSame(0, $updates->update($blocked, ['reason' => 'must not replace first', 'active' => false]));
        self::assertSame('first', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        self::assertSame([], $blocked->beforeQueryCallbacks);
        self::assertSame(1, $pdo->prepareCount);
    }

    public function test_builder_update_uses_current_grammar_for_date_and_where_binding_order(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $date = new DateTimeImmutable('2026-10-15 12:34:56');
        self::assertSame(1, $updates->update(
            $connection->table('candidates')->where('active', true)->where('id', 2),
            ['reason' => $date, 'active' => false],
        ));
        self::assertSame(['reason' => '2026-10-15 12:34:56', 'active' => 0], $pdo->query('SELECT reason, active FROM candidates WHERE id = 2')->fetch(PDO::FETCH_ASSOC));
        self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_builder_from_a_different_connection_is_rejected_without_mutation(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $updates = new PreparedCandidateUpdate(new SQLiteConnection($first));
        try {
            $updates->update((new SQLiteConnection($second))->table('candidates')->where('id', 1), ['reason' => 'wrong session']);
            self::fail('A builder belonging to another connection must be rejected.');
        } catch (LogicException) {
            self::assertSame(0, $first->prepareCount);
            self::assertSame(0, $second->prepareCount);
            self::assertSame('old', $second->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        }
    }

    public function test_builder_callback_cannot_redirect_the_update_to_another_connection(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $connection = new SQLiteConnection($first);
        $updates = new PreparedCandidateUpdate($connection);
        $query = $connection->table('candidates')->where('id', 1);
        $query->beforeQuery(static function ($query) use ($second): void {
            $query->connection = new SQLiteConnection($second);
        });
        try {
            $updates->update($query, ['reason' => 'wrong session']);
            self::fail('A callback must not redirect a cached update to another connection.');
        } catch (LogicException) {
            self::assertSame(0, $first->prepareCount);
            self::assertSame(0, $second->prepareCount);
        }
    }

    public function test_builder_adapter_rejects_values_requiring_general_update_normalization(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        foreach ([['nested'], new Expression("'expression'"), static function (): never {
            self::fail('Unsupported update closures must not execute.');
        }] as $value) {
            try {
                $updates->update($connection->table('candidates')->where('id', 1), ['reason' => $value]);
                self::fail('This bounded adapter must reject unsupported update values.');
            } catch (LogicException) {
                self::assertSame(0, $pdo->prepareCount);
            }
        }
        self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_reuses_the_native_statement_while_executing_each_update_immediately(): void
    {
        $pdo = new PreparedCandidateCountingPdo;
        $pdo->exec('CREATE TABLE candidates (id INTEGER PRIMARY KEY, reason TEXT)');
        $pdo->exec("INSERT INTO candidates VALUES (1, 'old'), (2, 'old')");
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';

        self::assertSame(1, $updates->affectingStatement($sql, ['first', 1]));
        self::assertSame('first', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        self::assertSame(1, $updates->affectingStatement($sql, ['second', 2]));
        self::assertSame('second', $pdo->query('SELECT reason FROM candidates WHERE id = 2')->fetchColumn());
        self::assertSame(1, $pdo->prepareCount, 'Same SQL on the same native PDO must prepare only once.');
    }

    public function test_sql_and_current_pdo_identity_each_invalidate_the_statement(): void
    {
        $first = $this->fixture();
        $connection = new SQLiteConnection($first);
        $updates = new PreparedCandidateUpdate($connection);
        self::assertSame(1, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['one', 1]));
        self::assertSame(1, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ? AND active = 1', ['two', 2]));
        self::assertSame(2, $first->prepareCount);
        $second = $this->fixture();
        $connection->setPdo($second);
        self::assertSame(1, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ? AND active = 1', ['new PDO', 1]));
        self::assertSame(1, $second->prepareCount);
        self::assertSame('one', $first->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        self::assertSame('new PDO', $second->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_missing_positional_and_named_bindings_cannot_retain_old_values(): void
    {
        foreach (['UPDATE candidates SET reason = ? WHERE id = ?', 'UPDATE candidates SET reason = :reason WHERE id = :id'] as $sql) {
            $pdo = $this->fixture();
            $updates = new PreparedCandidateUpdate(new SQLiteConnection($pdo));
            $named = str_contains($sql, ':reason');
            self::assertSame(1, $updates->affectingStatement($sql, $named ? ['reason' => 'first', 'id' => 1] : ['first', 1]));
            self::assertSame(0, $updates->affectingStatement($sql, $named ? ['reason' => 'missing'] : ['missing']));
            self::assertSame('first', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
            self::assertSame(2, $pdo->prepareCount);
        }
    }

    public function test_bindings_hooks_events_query_log_and_modification_state_use_the_connection_pipeline(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $connection->setEventDispatcher(new Dispatcher);
        $connection->enableQueryLog();
        $before = [];
        $events = [];
        $connection->beforeExecuting(static function ($sql, $bindings) use (&$before): void {
            $before[] = [$sql, $bindings];
        });
        $connection->listen(static function (QueryExecuted $event) use (&$events): void {
            $events[] = $event;
        });
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ?, active = ? WHERE id = ?';
        $date = new DateTimeImmutable('2026-10-15 12:34:56');
        self::assertSame(1, $updates->affectingStatement($sql, [$date, false, 1]));
        self::assertSame(['reason' => '2026-10-15 12:34:56', 'active' => 0], $pdo->query('SELECT reason, active FROM candidates WHERE id = 1')->fetch(PDO::FETCH_ASSOC));
        self::assertTrue($connection->hasModifiedRecords());
        $connection->forgetRecordModificationState();
        self::assertSame(0, $updates->affectingStatement($sql, ['none', true, 99]));
        self::assertFalse($connection->hasModifiedRecords());
        self::assertCount(2, $before);
        self::assertSame([$date, false, 1], $before[0][1]);
        self::assertCount(2, $events);
        self::assertSame($sql, $events[0]->sql);
        self::assertSame([$date, false, 1], $events[0]->bindings);
        self::assertCount(2, $connection->getQueryLog());
        self::assertSame(1, $pdo->prepareCount);
    }

    public function test_pretend_never_prepares_or_mutates_and_does_not_poison_later_execution(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $log = $connection->pretend(static function () use ($updates): void {
            self::assertSame(0, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['pretend', 1]));
        });
        self::assertCount(1, $log);
        self::assertSame(0, $pdo->prepareCount);
        self::assertFalse($connection->hasModifiedRecords());
        self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        self::assertSame(1, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['real', 1]));
        self::assertSame(1, $pdo->prepareCount);
    }

    public function test_outer_transaction_rollback_is_preserved_with_statement_reuse(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        $connection->beginTransaction();
        self::assertSame(1, $updates->affectingStatement($sql, ['inside', 1]));
        self::assertTrue($pdo->inTransaction());
        $connection->rollBack();
        self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
        self::assertSame(1, $updates->affectingStatement($sql, ['outside', 2]));
        self::assertSame(1, $pdo->prepareCount);
        self::assertFalse($pdo->inTransaction());
    }

    public function test_failed_execute_is_wrapped_and_evicts_the_uncertain_statement(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET active = ? WHERE id = ?';
        try {
            $updates->affectingStatement($sql, [null, 1]);
            self::fail('NOT NULL violation must remain a QueryException.');
        } catch (QueryException $error) {
            self::assertSame($sql, $error->getSql());
            self::assertSame([null, 1], $error->getBindings());
        }
        self::assertFalse($connection->hasModifiedRecords());
        self::assertSame(1, $updates->affectingStatement($sql, [false, 1]));
        self::assertSame(2, $pdo->prepareCount);
        self::assertSame(0, $pdo->query('SELECT active FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_missing_connection_reconnects_before_selecting_the_cached_statement(): void
    {
        $first = $this->fixture();
        $connection = new SQLiteConnection($first);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        $updates->affectingStatement($sql, ['first', 1]);
        $second = $this->fixture();
        $connection->setReconnector(static function ($connection) use ($second): void {
            $connection->setPdo($second);
        });
        $connection->setPdo(null);
        self::assertSame(1, $updates->affectingStatement($sql, ['reconnected', 1]));
        self::assertSame(1, $first->prepareCount);
        self::assertSame(1, $second->prepareCount);
        self::assertSame('reconnected', $second->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_lost_connection_execute_retries_on_a_fresh_real_pdo(): void
    {
        $first = $this->fixture();
        $connection = new SQLiteConnection($first);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        $updates->affectingStatement($sql, ['primed', 1]);
        $second = $this->fixture();
        $connection->setReconnector(static function ($connection) use ($second): void {
            $connection->setPdo($second);
        });
        // Inject only a transport exception at the native execute boundary;
        // all successful statements and resulting state use actual SQLite PDO.
        $first->loseNextExecute = true;
        self::assertSame(1, $updates->affectingStatement($sql, ['retried', 2]));
        self::assertSame('old', $first->query('SELECT reason FROM candidates WHERE id = 2')->fetchColumn());
        self::assertSame('retried', $second->query('SELECT reason FROM candidates WHERE id = 2')->fetchColumn());
        self::assertSame(1, $first->prepareCount);
        self::assertSame(1, $second->prepareCount);
    }

    public function test_nested_execute_cannot_overwrite_the_active_statement_bindings(): void
    {
        $pdo = $this->fixture();
        $updates = new PreparedCandidateUpdate(new SQLiteConnection($pdo));
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        $pdo->beforeNextExecute = static function () use ($updates, $sql): void {
            self::assertSame(1, $updates->affectingStatement($sql, ['nested', 2]));
        };
        self::assertSame(1, $updates->affectingStatement($sql, ['outer', 1]));
        self::assertSame(['outer', 'nested'], $pdo->query('SELECT reason FROM candidates ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(2, $pdo->prepareCount);
        self::assertSame(1, $updates->affectingStatement($sql, ['later', 1]));
        self::assertSame(2, $pdo->prepareCount);
    }

    public function test_zero_affected_rows_preserve_sticky_modification_state(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        self::assertSame(1, $updates->affectingStatement($sql, ['changed', 1]));
        self::assertSame(0, $updates->affectingStatement($sql, ['absent', 99]));
        self::assertTrue($connection->hasModifiedRecords());
    }

    public function test_macro_does_not_retain_the_per_run_helper(): void
    {
        $updates = new PreparedCandidateUpdate(new SQLiteConnection($this->fixture()));
        $reference = WeakReference::create($updates);
        unset($updates);
        self::assertNull($reference->get());
    }

    public function test_connection_default_update_path_remains_unchanged_after_opt_in_registration(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        new PreparedCandidateUpdate($connection);
        $sql = 'UPDATE candidates SET reason = ? WHERE id = ?';
        self::assertSame(1, $connection->affectingStatement($sql, ['first', 1]));
        self::assertSame(1, $connection->affectingStatement($sql, ['second', 2]));
        self::assertSame(2, $pdo->prepareCount);
    }

    public function test_lost_connection_inside_outer_transaction_is_not_retried(): void
    {
        $pdo = $this->fixture();
        $connection = new SQLiteConnection($pdo);
        $updates = new PreparedCandidateUpdate($connection);
        $connection->setReconnector(static function (): void {
            self::fail('Outer transaction must not reconnect and retry.');
        });
        $connection->beginTransaction();
        $pdo->loseNextExecute = true;
        try {
            $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['lost', 1]);
            self::fail('Transport failure inside the transaction must propagate.');
        } catch (QueryException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $connection->rollBack();
        }
        self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
    }

    public function test_same_binding_keys_replace_nonnull_with_null_and_integer_with_string(): void
    {
        $pdo = $this->fixture();
        $pdo->exec('CREATE TABLE payloads (id INTEGER PRIMARY KEY, value)');
        $pdo->exec("INSERT INTO payloads VALUES (1, 'old')");
        $updates = new PreparedCandidateUpdate(new SQLiteConnection($pdo));
        $sql = 'UPDATE payloads SET value = ? WHERE id = ?';
        self::assertSame(1, $updates->affectingStatement($sql, [7, 1]));
        self::assertSame('integer', $pdo->query('SELECT typeof(value) FROM payloads')->fetchColumn());
        self::assertSame(1, $updates->affectingStatement($sql, ['007', 1]));
        self::assertSame(['value' => '007', 'kind' => 'text'], $pdo->query('SELECT value, typeof(value) kind FROM payloads')->fetch(PDO::FETCH_ASSOC));
        self::assertSame(1, $updates->affectingStatement($sql, [null, 1]));
        self::assertNull($pdo->query('SELECT value FROM payloads')->fetchColumn());
        self::assertSame(1, $pdo->prepareCount);
    }

    public function test_foreign_macro_replacement_is_rejected_before_invocation_without_flushing_others(): void
    {
        $pdo = $this->fixture();
        $updates = new PreparedCandidateUpdate(new SQLiteConnection($pdo));
        $original = Closure::bind(static fn () => Connection::$macros['runPreparedCandidateUpdate'], null, Connection::class)();
        Connection::macro('runPreparedCandidateUpdate', function (): never {
            TestCase::fail('Foreign macro must never be invoked.');
        });
        try {
            try {
                new PreparedCandidateUpdate(new SQLiteConnection($pdo));
                self::fail('Foreign registration must block construction.');
            } catch (LogicException) {
                self::assertSame(0, $pdo->prepareCount);
            }
            try {
                $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['blocked', 1]);
                self::fail('Foreign replacement must block an existing helper.');
            } catch (LogicException) {
                self::assertSame('old', $pdo->query('SELECT reason FROM candidates WHERE id = 1')->fetchColumn());
            }
        } finally {
            Connection::macro('runPreparedCandidateUpdate', $original);
        }
        self::assertSame(1, $updates->affectingStatement('UPDATE candidates SET reason = ? WHERE id = ?', ['restored', 1]));
    }

    private function fixture(): PreparedCandidateCountingPdo
    {
        $pdo = new PreparedCandidateCountingPdo;
        $pdo->exec('CREATE TABLE candidates (id INTEGER PRIMARY KEY, reason TEXT, active INTEGER NOT NULL DEFAULT 1)');
        $pdo->exec("INSERT INTO candidates (id, reason) VALUES (1, 'old'), (2, 'old')");

        return $pdo;
    }
}

final class PreparedCandidateCountingPdo extends PDO
{
    public int $prepareCount = 0;

    public bool $loseNextExecute = false;

    public ?Closure $beforeNextExecute = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PreparedCandidateNativeStatement::class, [$this]]);
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCount++;

        return parent::prepare($query, $options);
    }
}

final class PreparedCandidateNativeStatement extends PDOStatement
{
    protected function __construct(private readonly PreparedCandidateCountingPdo $owner) {}

    /** @param array<int|string, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        if ($this->owner->loseNextExecute) {
            $this->owner->loseNextExecute = false;
            throw new PDOException('server has gone away');
        }
        $callback = $this->owner->beforeNextExecute;
        $this->owner->beforeNextExecute = null;
        $callback?->__invoke();

        return parent::execute($params);
    }
}
