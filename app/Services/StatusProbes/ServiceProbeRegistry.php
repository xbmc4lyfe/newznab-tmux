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

    /**
     * Enabled: the paired benchmark in the W56 parity test measures 2.521x common mean, 2.289x
     * common p99, 2.631x uncommon mean and 2.965x uncommon p99 (per-metric minimum of three runs),
     * above the 1.5x rule.
     */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = 'ssize_t nntmux_w561_probe_identifier_code_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W56_IDENTIFIER_VALID = 1;

    public const int W56_IDENTIFIER_NORMALIZED = 2;

    public const int W56_IDENTIFIER_KNOWN_SHIFT = 4;

    public const int W56_IDENTIFIER_LENGTH_SHIFT = 8;

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 56 batch routed to Rust or PHP, then restores the routing. */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * One word per probe identifier over its normalized form `strtolower(trim($identifier))`:
     * `W56_IDENTIFIER_VALID` for a `[a-z][a-z0-9_-]{0,63}` slug, `W56_IDENTIFIER_NORMALIZED` when
     * normalizing changed it, its PROBE_IDENTIFIERS index + 1 (0 unknown)
     * `<< W56_IDENTIFIER_KNOWN_SHIFT` and its length `<< W56_IDENTIFIER_LENGTH_SHIFT`.
     *
     * @param  array<array-key, string>  $identifiers
     * @return list<int>
     */
    public static function identifierCodes(array $identifiers): array
    {
        return self::usesW56RustFfi() ? self::identifierCodesNative($identifiers) : self::identifierCodesPhp($identifiers);
    }

    /**
     * @param  array<array-key, string>  $identifiers
     * @return list<int>
     */
    public static function identifierCodesPhp(array $identifiers): array
    {
        static $known = null;
        $known ??= array_flip(self::PROBE_IDENTIFIERS);
        $codes = [];
        foreach ($identifiers as $identifier) {
            $normalized = strtolower(trim($identifier));
            $codes[] = (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $normalized) === 1 ? self::W56_IDENTIFIER_VALID : 0)
                | ($normalized !== $identifier ? self::W56_IDENTIFIER_NORMALIZED : 0)
                | (($known[$normalized] ?? -1) + 1) << self::W56_IDENTIFIER_KNOWN_SHIFT
                | \strlen($normalized) << self::W56_IDENTIFIER_LENGTH_SHIFT;
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $identifiers
     * @return list<int>
     */
    public static function identifierCodesNative(array $identifiers): array
    {
        $count = \count($identifiers);
        $words = $count;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w56RustFfiState('out', $words);
        if ($buffer === null) {
            return self::identifierCodesPhp($identifiers);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $identifiers);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w561_probe_identifier_code_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::identifierCodesPhp($identifiers);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 56 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('service_probe_registry_w56', self::W56_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
