<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Throwable;

/**
 * Final guardrail for low-signal names.
 *
 * If a release has no meaningful media markers and only matched because of the
 * group name, prefer OTHER_MISC over trusting the group alone.
 */
class MiscSafetyNetPipe extends AbstractCategorizationPipe
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php is below 1.5x.
     * Names carry a PredbTransaction prefix so they stay distinct from this pipe's own useRustFfi toggle.
     */
    public const bool PREDB_TRANSACTION_RUST_FFI_DEFAULT = false;

    public const string PREDB_TRANSACTION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w383_predb_transaction_retry(
            uint32_t flags,
            int64_t attempt,
            int64_t attempts,
            char *out_buf,
            size_t out_cap
        );
        ssize_t nntmux_w383_predb_transaction_failure(
            const char *message_ptr,
            size_t message_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Failure messages raised by OwnsPredbIdentityTransactions: reason code => [message, discard context]. */
    public const array PREDB_TRANSACTION_FAILURES = [
        'dirty_transaction' => ['PreDB identity requires a clean transaction context.', false],
        'dirty_callbacks' => ['PreDB identity requires a clean callback context.', false],
        'callback_boundary' => ['PreDB identity cannot own the callback boundary.', false],
        'callback_manager_changed' => ['PreDB identity callback manager changed.', false],
        'ownership_changed' => ['PreDB identity connection ownership changed.', false],
        'connection_unusable' => ['PreDB identity connection cleanup failed; discard this context.', true],
        'cleanup_failed' => ['PreDB identity cleanup failed; discard this context.', true],
        'rollback_uncertain' => ['PreDB identity rollback callback progress is uncertain.', false],
        'cleanup_incomplete' => ['PreDB identity cleanup did not finish.', false],
        'unsupported_manager' => ['Unsupported PreDB identity callback manager.', false],
    ];

    private const array PREDB_TRANSACTION_ACTIONS = ['rethrow_committed', 'retry', 'rethrow'];

    private static ?bool $predbTransactionRustFfiOverride = null;

    private static FFI|false|null $predbTransactionRustFfiHandle = null;

    public static bool $useRustFfi = false;

    protected int $priority = 100;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        return CategorizationService::categorizeFfi();
    }

    public function getName(): string
    {
        return 'MiscSafetyNet';
    }

    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        if ($this->shouldDowngradeToMisc($passable)) {
            $result = new CategorizationResult(
                Category::OTHER_MISC,
                0.65,
                'group_only_low_signal',
                [
                    'group_match' => $passable->bestResult->matchedBy,
                    'misc_analysis' => $passable->miscAnalysis,
                ]
            );

            if ($passable->debug) {
                $passable->allResults[$this->getName()] = [
                    'category_id' => $result->categoryId,
                    'confidence' => $result->confidence,
                    'matched_by' => $result->matchedBy,
                ];
            }

            $passable->bestResult = $result;
            $passable->lockToMisc();
        }

        return $next($passable);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->noMatch();
    }

    public function shouldDowngradeToMisc(CategorizationPassable $passable): bool
    {
        return self::$useRustFfi
            ? $this->shouldDowngradeToMiscNative($passable)
            : $this->shouldDowngradeToMiscPhp($passable);
    }

    public function shouldDowngradeToMiscPhp(CategorizationPassable $passable): bool
    {
        return ! $passable->lockedToMisc
            && ($passable->miscAnalysis['lowSignal'] ?? false) === true
            && str_starts_with($passable->bestResult->matchedBy, 'group_name_');
    }

    public function shouldDowngradeToMiscNative(CategorizationPassable $passable): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->shouldDowngradeToMiscPhp($passable);
        }

        $lowSignal = ($passable->miscAnalysis['lowSignal'] ?? false) === true;

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_safety_net_should_downgrade(
            $passable->lockedToMisc ? 1 : 0,
            $lowSignal ? 1 : 0,
            $passable->bestResult->matchedBy,
            \strlen($passable->bestResult->matchedBy)
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldDowngradeToMiscPhp($passable);
        }

        return $res === 1;
    }

    public static function usePredbTransactionRustFfi(?bool $enabled): void
    {
        self::$predbTransactionRustFfiOverride = $enabled;
    }

    public static function usesPredbTransactionRustFfi(): bool
    {
        return self::$predbTransactionRustFfiOverride ?? self::PREDB_TRANSACTION_RUST_FFI_DEFAULT;
    }

    public static function resetPredbTransactionRustFfi(): void
    {
        self::$predbTransactionRustFfiOverride = null;
        self::$predbTransactionRustFfiHandle = null;
    }

    /**
     * Mirrors the failure branch of OwnsPredbIdentityTransactions::runOwnedPredbTransaction(): a
     * committed write is rethrown without cleanup; otherwise the context is cleaned up and a
     * concurrency failure retries while attempts remain. `prefer_body_failure` reports that the
     * body's own failure is rethrown.
     *
     * @return array{action: string, cleanup: bool, next_attempt: int, prefer_body_failure: bool}
     */
    public static function planPredbTransactionRetry(int $attempt, int $attempts, bool $committed, bool $concurrency, bool $bodyFailed): array
    {
        return (self::usesPredbTransactionRustFfi() ? self::planPredbTransactionRetryNative($attempt, $attempts, $committed, $concurrency, $bodyFailed) : null)
            ?? self::planPredbTransactionRetryPhp($attempt, $attempts, $committed, $concurrency, $bodyFailed);
    }

    /**
     * @return array{action: string, cleanup: bool, next_attempt: int, prefer_body_failure: bool}|null
     */
    private static function planPredbTransactionRetryNative(int $attempt, int $attempts, bool $committed, bool $concurrency, bool $bodyFailed): ?array
    {
        $flags = ($committed ? 1 : 0) | ($concurrency ? 2 : 0) | ($bodyFailed ? 4 : 0);
        $fields = self::callPredbTransactionNative('nntmux_w383_predb_transaction_retry', [$flags, $attempt, $attempts], 64);
        if ($fields === null || count($fields) !== 4 || ! in_array($fields[0], self::PREDB_TRANSACTION_ACTIONS, true)
            || preg_match('/\A-?[0-9]{1,19}\z/', $fields[2]) !== 1) {
            return null;
        }

        return [
            'action' => $fields[0],
            'cleanup' => $fields[1] === '1',
            'next_attempt' => (int) $fields[2],
            'prefer_body_failure' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{action: string, cleanup: bool, next_attempt: int, prefer_body_failure: bool}
     */
    private static function planPredbTransactionRetryPhp(int $attempt, int $attempts, bool $committed, bool $concurrency, bool $bodyFailed): array
    {
        if ($committed) {
            return ['action' => 'rethrow_committed', 'cleanup' => false, 'next_attempt' => $attempt, 'prefer_body_failure' => false];
        }
        $retry = $attempt < $attempts && $concurrency;

        return [
            'action' => $retry ? 'retry' : 'rethrow',
            'cleanup' => true,
            'next_attempt' => $retry ? $attempt + 1 : $attempt,
            'prefer_body_failure' => $bodyFailed,
        ];
    }

    /**
     * Maps an exact failure message raised by OwnsPredbIdentityTransactions to its
     * PREDB_TRANSACTION_FAILURES reason; any other message is `foreign`. `discard` marks failures
     * whose connection must be discarded.
     *
     * @return array{owned: bool, reason: string, discard: bool}
     */
    public static function classifyPredbTransactionFailure(string $message): array
    {
        return (self::usesPredbTransactionRustFfi() ? self::classifyPredbTransactionFailureNative($message) : null)
            ?? self::classifyPredbTransactionFailurePhp($message);
    }

    /**
     * @return array{owned: bool, reason: string, discard: bool}|null
     */
    private static function classifyPredbTransactionFailureNative(string $message): ?array
    {
        $fields = self::callPredbTransactionNative('nntmux_w383_predb_transaction_failure', [$message, strlen($message)], 64);
        if ($fields === null || count($fields) !== 3 || ! in_array($fields[0], ['0', '1'], true)) {
            return null;
        }

        return ['owned' => $fields[0] === '1', 'reason' => $fields[1], 'discard' => $fields[2] === '1'];
    }

    /**
     * @return array{owned: bool, reason: string, discard: bool}
     */
    private static function classifyPredbTransactionFailurePhp(string $message): array
    {
        foreach (self::PREDB_TRANSACTION_FAILURES as $reason => [$known, $discard]) {
            if ($known === $message) {
                return ['owned' => true, 'reason' => $reason, 'discard' => $discard];
            }
        }

        return ['owned' => false, 'reason' => 'foreign', 'discard' => false];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callPredbTransactionNative(string $symbol, array $args, int $minBytes): ?array
    {
        $ffi = self::predbTransactionRustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode("\x1F", FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function predbTransactionRustFfi(): ?FFI
    {
        if (self::$predbTransactionRustFfiHandle === null) {
            self::$predbTransactionRustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w383', self::PREDB_TRANSACTION_FFI_CDEF) ?? false;
        }

        return self::$predbTransactionRustFfiHandle === false ? null : self::$predbTransactionRustFfiHandle;
    }
}
