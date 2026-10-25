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

    /**
     * Wave 46.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 0.84-0.98x mean / 0.77-1.40x p99 on the common 32-label batch and 1.35-1.41x mean /
     * 1.13-1.32x p99 uncommon (one prefix check per label costs about what packing does), below
     * the 1.5x gate. useW463RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W463 so it stays distinct from the class's existing useRustFfi paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = false;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_safety_net_downgrade_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** shouldDowngradeToMiscPhp() group-only matched-by prefix. */
    public const string W463_GROUP_PREFIX = 'group_name_';

    /** Exactly 5 0x1F-separated decimals of 1-19 digits. */
    private const string W463_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){4}\d{1,19}\z/';

    private static ?bool $w463RustFfiOverride = null;

    private static FFI|false|null $w463RustFfiHandle = null;

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

    /**
     * Enables (true) or disables (false) the Wave 46.3 Rust helper; null restores
     * W463_RUST_FFI_DEFAULT.
     */
    public static function useW463RustFfi(?bool $enable = true): void
    {
        self::$w463RustFfiOverride = $enable;
    }

    public static function usesW463RustFfi(): bool
    {
        return self::$w463RustFfiOverride ?? self::W463_RUST_FFI_DEFAULT;
    }

    public static function resetW463RustFfi(): void
    {
        self::$w463RustFfiOverride = null;
        self::$w463RustFfiHandle = null;
    }

    /**
     * Downgrade summary for a batch of best results, applying shouldDowngradeToMiscPhp() per entry:
     * matched-by labels starting with W463_GROUP_PREFIX, entries whose $lowSignal or $locked value
     * under the same key is exactly true, and entries the pipe would downgrade (group-matched, low
     * signal and not locked). Non-string labels count as empty labels.
     *
     * @param  array<array-key, mixed>  $matchedBy
     * @param  array<array-key, mixed>  $lowSignal
     * @param  array<array-key, mixed>  $locked
     * @return array{total: int, group_matched: int, low_signal: int, locked: int, downgrade: int}
     */
    public static function summarizeDowngradeBatch(array $matchedBy, array $lowSignal = [], array $locked = []): array
    {
        return (self::usesW463RustFfi() ? self::summarizeDowngradeBatchNative($matchedBy, $lowSignal, $locked) : null)
            ?? self::summarizeDowngradeBatchPhp($matchedBy, $lowSignal, $locked);
    }

    /**
     * @param  array<array-key, mixed>  $matchedBy
     * @param  array<array-key, mixed>  $lowSignal
     * @param  array<array-key, mixed>  $locked
     * @return array{total: int, group_matched: int, low_signal: int, locked: int, downgrade: int}
     */
    public static function summarizeDowngradeBatchPhp(array $matchedBy, array $lowSignal = [], array $locked = []): array
    {
        $group = $low = $lockedCount = $downgrade = 0;
        foreach ($matchedBy as $key => $label) {
            $isGroup = \is_string($label) && str_starts_with($label, self::W463_GROUP_PREFIX);
            $isLow = ($lowSignal[$key] ?? false) === true;
            $isLocked = ($locked[$key] ?? false) === true;
            $group += (int) $isGroup;
            $low += (int) $isLow;
            $lockedCount += (int) $isLocked;
            $downgrade += (int) (! $isLocked && $isLow && $isGroup);
        }

        return [
            'total' => \count($matchedBy),
            'group_matched' => $group,
            'low_signal' => $low,
            'locked' => $lockedCount,
            'downgrade' => $downgrade,
        ];
    }

    /**
     * Sends the labels followed by one flags item (a byte per label: bit 0 low signal, bit 1 locked).
     * Declines (null) for non-list or non-string labels and when the library is unavailable.
     *
     * @param  array<array-key, mixed>  $matchedBy
     * @param  array<array-key, mixed>  $lowSignal
     * @param  array<array-key, mixed>  $locked
     * @return array{total: int, group_matched: int, low_signal: int, locked: int, downgrade: int}|null
     */
    public static function summarizeDowngradeBatchNative(array $matchedBy, array $lowSignal = [], array $locked = []): ?array
    {
        if (! array_is_list($matchedBy)) {
            return null;
        }
        $lengths = [];
        foreach ($matchedBy as $label) {
            if (! \is_string($label)) {
                return null;
            }
            $lengths[] = \strlen($label);
        }
        $count = \count($matchedBy);
        $flags = str_repeat("\0", $count);
        foreach (array_keys($lowSignal, true, true) as $key) {
            if (\is_int($key) && $key >= 0 && $key < $count) {
                $flags[$key] = "\x01";
            }
        }
        foreach (array_keys($locked, true, true) as $key) {
            if (\is_int($key) && $key >= 0 && $key < $count) {
                $flags[$key] = $flags[$key] === "\x01" ? "\x03" : "\x02";
            }
        }
        $lengths[] = $count;
        $fields = self::callW463Native(implode('', $matchedBy).$flags, pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'group_matched' => (int) $fields[1],
            'low_signal' => (int) $fields[2],
            'locked' => (int) $fields[3],
            'downgrade' => (int) $fields[4],
        ];
    }

    /**
     * Calls the Wave 46.3 export with the shared scratch buffer, retrying once at the size it asks
     * for. Returns its fields only when they match W463_FIELDS_PATTERN.
     *
     * @return list<string>|null
     */
    private static function callW463Native(string $blob, string $lens): ?array
    {
        $ffi = self::w463RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 192, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w463_safety_net_downgrade_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_safety_net_downgrade_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W463_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w463RustFfi(): ?FFI
    {
        if (self::$w463RustFfiHandle === null) {
            self::$w463RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w463', self::W463_FFI_CDEF) ?? false;
        }

        return self::$w463RustFfiHandle === false ? null : self::$w463RustFfiHandle;
    }
}
