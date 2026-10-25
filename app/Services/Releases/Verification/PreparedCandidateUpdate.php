<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use PDO;
use PDOStatement;
use Throwable;

/** One preparation run's UPDATE cache; session settings and binding values stay caller-owned. */
final class PreparedCandidateUpdate
{
    public const string FFI_CDEF = CandidatePreparationService::FFI_CDEF;

    /** Update shapes: status and header flag only (a ready promotion). */
    public const int SHAPE_STATUS = 0;

    /** Bit: the update also stores a diagnostic reason. */
    public const int SHAPE_REASON = 1;

    /** Bit: the update also schedules the next attempt. */
    public const int SHAPE_RETRY = 2;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static ?Closure $macro = null;

    private ?PDO $pdo = null;

    private ?PDOStatement $statement = null;

    private ?string $sql = null;

    /** @var list<int|string> */
    private array $bindingKeys = [];

    private bool $executing = false;

    public function __construct(private readonly Connection $connection)
    {
        self::registerMacro();
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** The SHAPE_* bits of one candidate update's optional columns. */
    public static function classifyUpdateShape(bool $hasReason, bool $hasNextAttempt): int
    {
        return self::usesRustFfi() ? self::classifyUpdateShapeNative($hasReason, $hasNextAttempt) : self::classifyUpdateShapePhp($hasReason, $hasNextAttempt);
    }

    public static function classifyUpdateShapePhp(bool $hasReason, bool $hasNextAttempt): int
    {
        return ($hasReason ? self::SHAPE_REASON : 0) | ($hasNextAttempt ? self::SHAPE_RETRY : 0);
    }

    public static function classifyUpdateShapeNative(bool $hasReason, bool $hasNextAttempt): int
    {
        $result = CandidatePreparationService::evaluateNative(4, pack('q2', (int) $hasReason, (int) $hasNextAttempt));
        if ($result !== null && \strlen($result) === 8) {
            $shape = unpack('q', $result)[1] ?? null;
            if (\is_int($shape) && $shape >= 0 && $shape <= (self::SHAPE_REASON | self::SHAPE_RETRY)) {
                return $shape;
            }
        }

        return self::classifyUpdateShapePhp($hasReason, $hasNextAttempt);
    }

    /** @param array<string, mixed> $values */
    public function update(Builder $query, array $values): int
    {
        if ($query->getConnection() !== $this->connection) {
            throw new LogicException('Prepared candidate updates require the original connection.');
        }
        $query->applyBeforeQueryCallbacks();
        if ($query->getConnection() !== $this->connection) {
            throw new LogicException('Prepared candidate update callbacks must retain the original connection.');
        }
        // This caller supplies only status, reason and retry-time values. Leave
        // general Builder subquery/enum/collection normalization to Builder.
        foreach ($values as $value) {
            if ($value !== null && ! is_scalar($value) && ! $value instanceof DateTimeInterface) {
                throw new LogicException('Prepared candidate updates require scalar or date values.');
            }
        }
        $grammar = $query->getGrammar();

        return $this->affectingStatement($grammar->compileUpdate($query, $values), $query->cleanBindings(
            $grammar->prepareBindingsForUpdate($query->getRawBindings(), $values)
        ));
    }

    /** @param array<int|string, mixed> $bindings */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        self::registerMacro();

        return $this->connection->__call('runPreparedCandidateUpdate', [$query, $bindings, function (string $query, array $bindings): int {
            if ($this->connection->pretending()) {
                return 0;
            }

            // Resolve within run's retry callback: reconnects must never reuse
            // a statement owned by the failed PDO connection.
            $pdo = $this->connection->getPdo();
            $nested = $this->executing;
            $this->executing = true;
            try {
                $statement = $nested ? $pdo->prepare($query) : $this->prepare($pdo, $query, array_keys($bindings));
                $this->connection->bindValues($statement, $this->connection->prepareBindings($bindings));
                $statement->execute();
                $count = $statement->rowCount();
                $this->connection->recordsHaveBeenModified($count > 0);

                return $count;
            } catch (Throwable $error) {
                if (! $nested) {
                    $this->statement = null;
                    $this->pdo = null;
                    $this->sql = null;
                    $this->bindingKeys = [];
                }

                throw $error;
            } finally {
                $this->executing = $nested;
            }
        }]);
    }

    /** @param list<int|string> $bindingKeys */
    private function prepare(PDO $pdo, string $query, array $bindingKeys): PDOStatement
    {
        if ($this->statement === null || $this->pdo !== $pdo || $this->sql !== $query || $this->bindingKeys !== $bindingKeys) {
            $this->statement = null;
            $this->pdo = null;
            $this->sql = null;
            $this->bindingKeys = [];
            $statement = $pdo->prepare($query);
            $this->statement = $statement;
            $this->pdo = $pdo;
            $this->sql = $query;
            $this->bindingKeys = $bindingKeys;
        }

        return $this->statement;
    }

    private static function registerMacro(): void
    {
        // Read only our exact Macroable slot, without invoking foreign code or
        // altering unrelated macros. This deliberately couples to Connection's
        // protected Macroable registry as well as its protected run contract.
        $registered = Closure::bind(static fn () => Connection::$macros['runPreparedCandidateUpdate'] ?? null, null, Connection::class)();
        if ($registered !== null && $registered !== self::$macro) {
            throw new LogicException('Prepared candidate update macro is already owned by another registration.');
        }
        if (self::$macro === null) {
            // Macroable supplies Connection scope; no helper is captured.
            Connection::macro('runPreparedCandidateUpdate', function (string $query, array $bindings, Closure $callback): int {
                return $this->run($query, $bindings, $callback);
            });
            self::$macro = Closure::bind(static fn () => Connection::$macros['runPreparedCandidateUpdate'], null, Connection::class)();

            return;
        }
        if ($registered === null) {
            Connection::macro('runPreparedCandidateUpdate', self::$macro);
        }
    }
}
