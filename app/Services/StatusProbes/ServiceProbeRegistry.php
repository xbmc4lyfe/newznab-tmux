<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Identifiers of the probes the registry constructs, in registration order. */
    public const array PROBE_IDENTIFIERS = ['database', 'redis', 'search', 'nntp', 'queue', 'disk'];

    public const string W50_FFI_CDEF = 'int64_t nntmux_w505_service_probe_registry_resolve(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * Disabled: the common-path gains stay below 1.5x because the per-record PHP work is cheaper than packing
     * and decoding the batch (see RustProbesRedisSearchRegistryCursorFailureW50ParityTest::QUALIFICATION).
     */
    private const W50_RUST_FFI_DEFAULT = false;

    private static ?bool $w50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$w50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 50.5 batch helper routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w50RustFfi;
        self::$w50RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w50RustFfi = $previous;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w50Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /**
     * Resolves many identifiers as run() does. Three words per identifier: `[index of the first equal $known
     * identifier, 0, 0]` for a known probe, else `[-1, reason length, xxh64(reason)]` with the
     * formatUnknownProbeReasonPhp() reason.
     *
     * @param  list<string>  $identifiers
     * @param  list<string>  $known
     * @return list<int>
     */
    public static function resolveProbeBatch(array $identifiers, array $known = self::PROBE_IDENTIFIERS): array
    {
        return self::usesW50RustFfi()
            ? self::resolveProbeBatchNative($identifiers, $known)
            : self::resolveProbeBatchPhp($identifiers, $known);
    }

    /**
     * @param  list<string>  $identifiers
     * @param  list<string>  $known
     * @return list<int>
     */
    public static function resolveProbeBatchPhp(array $identifiers, array $known = self::PROBE_IDENTIFIERS): array
    {
        $index = [];
        foreach ($known as $position => $identifier) {
            $index[$identifier] ??= $position;
        }
        $out = [];
        foreach ($identifiers as $identifier) {
            if (isset($index[$identifier])) {
                array_push($out, $index[$identifier], 0, 0);

                continue;
            }
            $reason = self::formatUnknownProbeReasonPhp($identifier);
            array_push($out, -1, \strlen($reason), self::w50Digest($reason));
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a list is not a list or a value holds a NUL
     * byte.
     *
     * @param  list<string>  $identifiers
     * @param  list<string>  $known
     * @return list<int>
     */
    public static function resolveProbeBatchNative(array $identifiers, array $known = self::PROBE_IDENTIFIERS): array
    {
        $count = \count($identifiers);
        if ($count === 0 || ! array_is_list($known)) {
            return self::resolveProbeBatchPhp($identifiers, $known);
        }
        $data = $known === [] ? implode("\0", $identifiers) : implode("\0", $identifiers)."\0".implode("\0", $known);

        return self::w50Words($count, $data, pack('P', \count($known)), 3 * $count) ?? self::resolveProbeBatchPhp($identifiers, $known);
    }

    /**
     * Exactly $words output words from the Wave 50.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w50Words(int $count, string $data, string $ints, int $words): ?array
    {
        if (self::$w50Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w50Ffi = RustPipelineLibrary::resolveDomain('search_w50', self::W50_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w50Out = null;
            self::$w50OutCap = 0;
        }
        $ffi = self::$w50Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w50Out === null || $words > self::$w50OutCap) {
                $cap = max(1024, $words);
                self::$w50Out = $ffi->new('int64_t['.$cap.']');
                self::$w50OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w505_service_probe_registry_resolve($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w50Out, self::$w50OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w50Out, $words * 8));

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
