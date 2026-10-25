<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class PersistenceMetrics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    private static function rust(string $input, int $capacity): ?string
    {
        if (strlen($input) > 16_777_216 || $capacity < 1 || $capacity > 16_777_216) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $output = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w356_dto_provider(4, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $databaseStatements = 0,
        public readonly float $databaseMilliseconds = 0.0,
        public readonly int $searchSyncRequests = 0,
        public readonly int $searchSyncExecutions = 0,
    ) {}

    /**
     * Finite nonnegative timings/counters, mean query cost and requests saved by coalescing.
     *
     * @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int}
     */
    public static function summarizeDatabaseAndSearchLoad(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): array
    {
        return (self::usesRustFfi() ? self::summarizeDatabaseAndSearchLoadNative($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions) : null)
            ?? self::summarizeDatabaseAndSearchLoadPhp($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions);
    }

    /** @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int} */
    public static function summarizeDatabaseAndSearchLoadPhp(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): array
    {
        $databaseStatements = max(0, $databaseStatements);
        $databaseMilliseconds = is_finite($databaseMilliseconds) && $databaseMilliseconds > 0.0 ? $databaseMilliseconds : 0.0;
        $searchSyncRequests = max(0, $searchSyncRequests);
        $searchSyncExecutions = max(0, $searchSyncExecutions);

        return [
            'databaseStatements' => $databaseStatements, 'databaseMilliseconds' => $databaseMilliseconds,
            'searchSyncRequests' => $searchSyncRequests, 'searchSyncExecutions' => $searchSyncExecutions,
            'averageStatementMs' => $databaseStatements > 0 ? $databaseMilliseconds / $databaseStatements : 0.0,
            'coalescedSearchSyncRequests' => max(0, $searchSyncRequests - $searchSyncExecutions),
        ];
    }

    /** @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int}|null */
    public static function summarizeDatabaseAndSearchLoadNative(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): ?array
    {
        $input = pack('qdq2', $databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions);
        $bytes = self::rust($input, 48);
        if ($bytes === null || strlen($bytes) !== 48) {
            return null;
        }
        /** @var array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int} $result */
        $result = unpack('qdatabaseStatements/ddatabaseMilliseconds/qsearchSyncRequests/qsearchSyncExecutions/daverageStatementMs/qcoalescedSearchSyncRequests', $bytes);

        return $result;
    }
}
