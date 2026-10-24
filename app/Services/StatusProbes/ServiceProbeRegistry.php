<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class ServiceProbeRegistry
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_service_probe_format_unknown_reason(
        const char *id_ptr,
        size_t id_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMonitoringProbeComposerParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @var array<string, ServiceProbeInterface>
     */
    private array $probes;

    public function __construct(
        DatabaseProbe $databaseProbe,
        RedisProbe $redisProbe,
        SearchProbe $searchProbe,
        NntpProbe $nntpProbe,
        QueueProbe $queueProbe,
        DiskProbe $diskProbe,
    ) {
        $this->probes = [
            $databaseProbe->identifier() => $databaseProbe,
            $redisProbe->identifier() => $redisProbe,
            $searchProbe->identifier() => $searchProbe,
            $nntpProbe->identifier() => $nntpProbe,
            $queueProbe->identifier() => $queueProbe,
            $diskProbe->identifier() => $diskProbe,
        ];
    }

    public function run(string $identifier): ProbeResult
    {
        $probe = $this->probes[$identifier] ?? null;
        if ($probe === null) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Major,
                reason: self::formatUnknownProbeReason($identifier),
            );
        }

        return $probe->probe();
    }

    public static function formatUnknownProbeReason(string $identifier): string
    {
        return self::$useRustFfi
            ? self::formatUnknownProbeReasonNative($identifier)
            : self::formatUnknownProbeReasonPhp($identifier);
    }

    public static function formatUnknownProbeReasonNative(string $identifier): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatUnknownProbeReasonPhp($identifier);
        }

        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($identifier) + 64), $capacity);
            if ($outBuf === null) {
                return self::formatUnknownProbeReasonPhp($identifier);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_service_probe_format_unknown_reason',
                $identifier,
                strlen($identifier),
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? '' : FFI::string($outBuf, $written);
            }
        } catch (Throwable) {
        }

        return self::formatUnknownProbeReasonPhp($identifier);
    }

    public static function formatUnknownProbeReasonPhp(string $identifier): string
    {
        return sprintf('Unknown probe identifier "%s"', $identifier);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
