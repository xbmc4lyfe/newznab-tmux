<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class DownloadMetrics
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
            $written = self::$ffi->nntmux_w356_dto_provider(1, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $logicalRequests = 0,
        public readonly int $networkRequests = 0,
        public readonly int $cacheHits = 0,
        public readonly int $bytesDownloaded = 0,
        public readonly int $bytesReused = 0,
        public readonly int $mediaBytesDownloaded = 0,
    ) {}

    /**
     * Nonnegative counters and bounded fractions (zero when their denominator is zero).
     *
     * @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float}
     */
    public static function summarizeTransferEfficiency(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): array
    {
        return (self::usesRustFfi() ? self::summarizeTransferEfficiencyNative($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded) : null)
            ?? self::summarizeTransferEfficiencyPhp($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded);
    }

    /** @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float} */
    public static function summarizeTransferEfficiencyPhp(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): array
    {
        $logicalRequests = max(0, $logicalRequests);
        $networkRequests = max(0, $networkRequests);
        $cacheHits = max(0, $cacheHits);
        $bytesDownloaded = max(0, $bytesDownloaded);
        $bytesReused = max(0, $bytesReused);
        $mediaBytesDownloaded = max(0, $mediaBytesDownloaded);
        // Convert before addition so two maximum counters cannot overflow an integer.
        $totalBytes = (float) $bytesDownloaded + (float) $bytesReused;

        return [
            'logicalRequests' => $logicalRequests, 'networkRequests' => $networkRequests,
            'cacheHits' => $cacheHits, 'bytesDownloaded' => $bytesDownloaded,
            'bytesReused' => $bytesReused, 'mediaBytesDownloaded' => $mediaBytesDownloaded,
            'cacheHitRatio' => $logicalRequests > 0 ? min(1.0, (float) $cacheHits / (float) $logicalRequests) : 0.0,
            'byteReuseRatio' => $totalBytes > 0.0 ? $bytesReused / $totalBytes : 0.0,
            'mediaByteRatio' => $bytesDownloaded > 0 ? min(1.0, (float) $mediaBytesDownloaded / (float) $bytesDownloaded) : 0.0,
        ];
    }

    /** @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float}|null */
    public static function summarizeTransferEfficiencyNative(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): ?array
    {
        $bytes = self::rust(pack('q*', $logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded), 72);
        if ($bytes === null || strlen($bytes) !== 72) {
            return null;
        }
        /** @var array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float} $result */
        $result = unpack('qlogicalRequests/qnetworkRequests/qcacheHits/qbytesDownloaded/qbytesReused/qmediaBytesDownloaded/dcacheHitRatio/dbyteReuseRatio/dmediaByteRatio', $bytes);

        return $result;
    }
}
