<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use DateTimeInterface;
use FFI;
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

    /** C signature of the Wave 63.5 {@see self::w63PreparedUpdatePlanBatch()} helper. */
    private const string W63_PREPARED_UPDATE_PLAN_FFI_CDEF = 'int64_t nntmux_w635_prepared_update_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 63.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.13/5.20/6.67/6.67x); see tests/Unit/RustVerificationPolicyPar2ProofPayloadUpdateResetW63ParityTest.php.
     */
    public const bool W63_RUST_FFI_DEFAULT = true;

    /**
     * Wave 63.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w63_5', self::W63_PREPARED_UPDATE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W63_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 63.5 plan batch to Rust until disabled or reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Route the Wave 63.5 plan batch to PHP until enabled or reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restore W63_RUST_FFI_DEFAULT routing and drop the cached Wave 63.5 handle and buffer. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('set', null);
        self::w63RustFfiState('reset', null);
    }

    public static function usesW63RustFfi(): bool
    {
        return self::w63RustFfiState() === true;
    }

    /**
     * Runs $callback with w63PreparedUpdatePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 63.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w63Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w63RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w635_prepared_update_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w63Number(string $field, ?int $default, int &$flags): ?int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch prepared candidate update plan over
     * `current\ttarget\tattempts\tmax_attempts\tdigest\tprevious_digest\tdeclared_bytes\tverified_bytes\treason\tnext_attempt`.
     * Ten words per record: `[current, target, allowed, reason, next_attempt_count, shape, digest_state, digest_changed,
     * size_state, flags]`. Statuses are trimmed W396_STATUSES names (ASCII case-insensitive) or one digit 0-6, else -1.
     * Numbers are trimmed 1-15 ASCII digits (attempts default 0, max attempts 5; invalid sets flag 1). The transition
     * follows validateUpdateTransitionPhp() with reasons coded in W396_REASONS order; the shape follows
     * classifyUpdateShapePhp() for a non-empty trimmed reason and next attempt. The digest is 0 empty, 1 64 lowercase
     * hex digits, 2 64 hex digits with uppercase, 3 invalid; it changed when valid and a non-empty previous digest
     * differs ASCII case-insensitively. Sizes are 0 unknown, 1 equal, 2 verified below declared, 3 above. Flags: 1
     * invalid number or next attempt, 2 not exactly ten fields, 4 unknown status, 8 invalid digest, 16 digest change
     * without a verifying or ready target, 32 reason over 255 bytes, 64 retry without next attempt, 128 next attempt
     * without a retry target, 256 attempts exhausted.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PreparedUpdatePlanBatch(array $records): array
    {
        return self::usesW63RustFfi() ? self::w63PreparedUpdatePlanBatchNative($records) : self::w63PreparedUpdatePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PreparedUpdatePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w63Words($count, implode("\0", $records), 10 * $count) ?? self::w63PreparedUpdatePlanBatchPhp($records);
    }

    /** W396_STATUSES codes by name. */
    private const array W63_STATUS_CODES = ['waiting_upload' => 0, 'verifying' => 1, 'ready' => 2, 'retry' => 3, 'published' => 4, 'rejected' => 5, 'expired' => 6];

    /** W396_REASONS codes by name. */
    private const array W63_REASON_CODES = ['none' => 0, 'unknown_status' => 1, 'terminal_current' => 2, 'unchanged' => 3, 'not_permitted' => 4, 'attempts_exhausted' => 5];

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PreparedUpdatePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 10 ? 0 : 2;
            $current = self::w63Status($fields[0], $flags);
            $target = self::w63Status($fields[1] ?? '', $flags);
            $attempts = (int) self::w63Number($fields[2] ?? '', 0, $flags);
            $transition = self::validateUpdateTransitionPhp($current, $target, $attempts, (int) self::w63Number($fields[3] ?? '', 5, $flags));
            $note = trim($fields[8] ?? '');
            $next = trim($fields[9] ?? '');
            $shape = self::classifyUpdateShapePhp($note !== '', $next !== '');
            if (\strlen($note) > 255) {
                $flags |= 32;
            }
            if ($next !== '' && (\strlen($next) > 15 || strspn($next, '0123456789') !== \strlen($next))) {
                $flags |= 1;
            }
            $digest = trim($fields[4] ?? '');
            $digestState = match (true) {
                $digest === '' => 0,
                \strlen($digest) !== 64 || strspn($digest, '0123456789abcdefABCDEF') !== 64 => 3,
                strpbrk($digest, 'ABCDEF') !== false => 2,
                default => 1,
            };
            if ($digestState === 3) {
                $flags |= 8;
            }
            $previous = trim($fields[5] ?? '');
            $changed = ($digestState === 1 || $digestState === 2) && $previous !== '' && strcasecmp($previous, $digest) !== 0;
            if ($changed && $target !== 1 && $target !== 2) {
                $flags |= 16;
            }
            $declared = self::w63Number($fields[6] ?? '', null, $flags);
            $verified = self::w63Number($fields[7] ?? '', null, $flags);
            $sizeState = $declared === null || $verified === null ? 0 : match ($verified <=> $declared) {
                0 => 1,
                -1 => 2,
                default => 3,
            };
            if ($target === 3 && $next === '') {
                $flags |= 64;
            }
            if ($next !== '' && $target !== 3) {
                $flags |= 128;
            }
            if ($transition['attempts_exhausted']) {
                $flags |= 256;
            }
            array_push($out, $current, $target, $transition['allowed'] ? 1 : 0, self::W63_REASON_CODES[$transition['reason']], $transition['next_attempt_count'], $shape, $digestState, $changed ? 1 : 0, $sizeState, $flags);
        }

        return $out;
    }

    /** A trimmed status name (ASCII case-insensitive) or single digit 0-6 as its code; else -1 with bit 4. */
    private static function w63Status(string $field, int &$flags): int
    {
        $text = strtolower(trim($field));
        if (\strlen($text) === 1 && strspn($text, '0123456') === 1) {
            return (int) $text;
        }
        if (isset(self::W63_STATUS_CODES[$text])) {
            return self::W63_STATUS_CODES[$text];
        }
        $flags |= 4;

        return -1;
    }
}
