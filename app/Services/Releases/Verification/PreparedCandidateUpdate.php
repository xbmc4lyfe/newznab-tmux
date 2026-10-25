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

    /** Wave 39.6 paired benchmarks decide this default; see RustVerificationStorePrelockManifestResetW39ParityTest. */
    public const bool W396_RUST_FFI_DEFAULT = false;

    /** Wave 39.6 transition codes; published, rejected and expired are terminal. */
    public const array W396_STATUSES = [0 => 'waiting_upload', 1 => 'verifying', 2 => 'ready', 3 => 'retry', 4 => 'published', 5 => 'rejected', 6 => 'expired'];

    /** Permitted targets per non-terminal status. */
    private const array W396_TRANSITIONS = [0 => [1, 6], 1 => [2, 3, 5, 6], 2 => [3, 4, 5], 3 => [1, 5, 6]];

    private const int W396_VERIFYING = 1;

    private const int W396_RETRY = 3;

    private const array W396_REASONS = ['none', 'unknown_status', 'terminal_current', 'unchanged', 'not_permitted', 'attempts_exhausted'];

    private static ?bool $w396UseRustFfi = null;

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

    public static function useW396RustFfi(?bool $enabled): void
    {
        self::$w396UseRustFfi = $enabled;
    }

    public static function usesW396RustFfi(): bool
    {
        return self::$w396UseRustFfi ?? self::W396_RUST_FFI_DEFAULT;
    }

    public static function resetW396RustFfi(): void
    {
        self::$w396UseRustFfi = null;
    }

    /**
     * Validate a candidate status transition over the W396_STATUSES codes. Checks run in order: an unknown
     * code, a terminal current status, an unchanged status, a transition W396_TRANSITIONS does not permit,
     * then a move to verifying or retry once max(0, $attemptCount) reaches max(1, $maxAttempts). An
     * allowed move to verifying consumes one attempt.
     *
     * @return array{allowed: bool, terminal_target: bool, attempts_exhausted: bool, next_attempt_count: int, reason: string}
     */
    public static function validateUpdateTransition(int $currentStatus, int $targetStatus, int $attemptCount, int $maxAttempts = 5): array
    {
        return (self::usesW396RustFfi() ? self::validateUpdateTransitionNative($currentStatus, $targetStatus, $attemptCount, $maxAttempts) : null)
            ?? self::validateUpdateTransitionPhp($currentStatus, $targetStatus, $attemptCount, $maxAttempts);
    }

    /** @return array{allowed: bool, terminal_target: bool, attempts_exhausted: bool, next_attempt_count: int, reason: string} */
    public static function validateUpdateTransitionPhp(int $currentStatus, int $targetStatus, int $attemptCount, int $maxAttempts = 5): array
    {
        $attempts = max(0, $attemptCount);
        $exhausted = $attempts >= max(1, $maxAttempts);
        $reason = match (true) {
            ! isset(self::W396_STATUSES[$currentStatus], self::W396_STATUSES[$targetStatus]) => 'unknown_status',
            ! isset(self::W396_TRANSITIONS[$currentStatus]) => 'terminal_current',
            $currentStatus === $targetStatus => 'unchanged',
            ! \in_array($targetStatus, self::W396_TRANSITIONS[$currentStatus], true) => 'not_permitted',
            ($targetStatus === self::W396_VERIFYING || $targetStatus === self::W396_RETRY) && $exhausted => 'attempts_exhausted',
            default => 'none',
        };

        return [
            'allowed' => $reason === 'none',
            'terminal_target' => isset(self::W396_STATUSES[$targetStatus]) && ! isset(self::W396_TRANSITIONS[$targetStatus]),
            'attempts_exhausted' => $exhausted,
            'next_attempt_count' => $reason === 'none' && $targetStatus === self::W396_VERIFYING ? $attempts + 1 : $attempts,
            'reason' => $reason,
        ];
    }

    /** @return array{allowed: bool, terminal_target: bool, attempts_exhausted: bool, next_attempt_count: int, reason: string}|null */
    public static function validateUpdateTransitionNative(int $currentStatus, int $targetStatus, int $attemptCount, int $maxAttempts = 5): ?array
    {
        $slots = CollectionReleasePolicy::w396Slots(3, 0, pack('q4', $currentStatus, $targetStatus, $attemptCount, $maxAttempts), 5);
        if ($slots === null || ($slots[0] & ~1) !== 0 || ($slots[1] & ~1) !== 0 || ($slots[2] & ~1) !== 0 || $slots[3] < 0
            || ! isset(self::W396_REASONS[$slots[4]]) || ($slots[0] === 1) !== ($slots[4] === 0)) {
            return null;
        }

        return [
            'allowed' => $slots[0] === 1,
            'terminal_target' => $slots[1] === 1,
            'attempts_exhausted' => $slots[2] === 1,
            'next_attempt_count' => $slots[3],
            'reason' => self::W396_REASONS[$slots[4]],
        ];
    }
}
