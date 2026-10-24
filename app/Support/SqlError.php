<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Classifies SQL failures and renders concise log messages for them.
 *
 * QueryException messages embed the full statement with every binding, which
 * turns bulk-ingest lock errors into multi-kilobyte log lines. describe()
 * strips that payload; isTransientLock() recognizes retryable lock conflicts.
 */
final class SqlError
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_sql_error_describe(uint8_t is_query_exception, const char *sqlstate, size_t sqlstate_len, int64_t driver_code, const char *message, size_t message_len, uint8_t *is_transient_out, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    private static ?FFI\CData $flag = null;

    private static ?FFI\CData $flagPtr = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    /**
     * InnoDB driver codes: 1020 ER_CHECKREAD (record changed since last read),
     * 1205 lock wait timeout, 1213 deadlock.
     */
    private const array TRANSIENT_DRIVER_CODES = [1020, 1205, 1213];

    private const int MAX_MESSAGE_LENGTH = 500;

    public static function isTransientLock(Throwable $exception): bool
    {
        return (self::$useRustFfi ? self::isTransientLockNative($exception) : null)
            ?? self::isTransientLockPhp($exception);
    }

    public static function isTransientLockPhp(Throwable $exception): bool
    {
        if ($exception instanceof QueryException) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($exception->getCode() === '40001' || \in_array($driverCode, self::TRANSIENT_DRIVER_CODES, true)) {
                return true;
            }
        }

        return str_contains($exception->getMessage(), 'Deadlock found')
            || str_contains($exception->getMessage(), 'Lock wait timeout exceeded')
            || str_contains($exception->getMessage(), 'Record has changed since last read')
            || str_contains($exception->getMessage(), 'database is locked');
    }

    public static function isTransientLockNative(Throwable $exception): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $isQuery = $exception instanceof QueryException ? 1 : 0;
            $sqlstate = $isQuery ? (string) $exception->getCode() : '';
            $driverCode = $isQuery ? (int) ($exception->errorInfo[1] ?? 0) : 0;
            $message = $exception->getMessage();
            if (self::$flag === null || self::$flagPtr === null) {
                self::$flag = $ffi->new('uint8_t');
                self::$flagPtr = FFI::addr(self::$flag);
            }
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_sql_error_describe(
                $isQuery,
                $sqlstate,
                strlen($sqlstate),
                $driverCode,
                $message,
                strlen($message),
                self::$flagPtr,
                null,
                0
            );
            if (! is_int($status) || $status < 0) {
                return null;
            }

            // @phpstan-ignore property.notFound
            return self::$flag->cdata === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Concise single-line description without the "(Connection: …, SQL: …)"
     * payload Laravel appends to QueryException messages.
     */
    public static function describe(Throwable $exception): string
    {
        return (self::$useRustFfi ? self::describeNative($exception) : null)
            ?? self::describePhp($exception);
    }

    public static function describePhp(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $tail = strpos($message, ' (Connection:');
        if ($tail !== false) {
            $message = substr($message, 0, $tail);
        }

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH).'…';
        }

        return $message;
    }

    public static function describeNative(Throwable $exception): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $isQuery = $exception instanceof QueryException ? 1 : 0;
            $sqlstate = $isQuery ? (string) $exception->getCode() : '';
            $driverCode = $isQuery ? (int) ($exception->errorInfo[1] ?? 0) : 0;
            $message = $exception->getMessage();
            $scratch = self::scratch($ffi, max(2048, strlen($message) + 32));
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_sql_error_describe(
                $isQuery,
                $sqlstate,
                strlen($sqlstate),
                $driverCode,
                $message,
                strlen($message),
                null,
                $scratch,
                $cap
            );
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Log a failed statement: warning for transient lock conflicts (the caller
     * retries the chunk), error otherwise. Always without the SQL payload.
     */
    public static function logFailure(string $context, Throwable $exception): void
    {
        $message = $context.': '.self::describe($exception);
        if (self::isTransientLock($exception)) {
            Log::warning($message.' (transient, chunk will be retried)');
        } else {
            Log::error($message);
        }
    }
}
