<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Prometheus;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * One Prometheus metric family: a name, type, help text and its samples.
 */
final class MetricFamily
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_prometheus_validate_metric_definition(const char *name_ptr, size_t name_len, const char *type_ptr, size_t type_len);
        ssize_t nntmux_prometheus_normalize_labels_nul(const char *input_ptr, size_t input_len, size_t pair_count, size_t *out_invalid_index, char *out_buf, size_t out_cap);
        C;

    public const string W49_FFI_CDEF = 'int64_t nntmux_w49_metric_family_definition_check(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Rust: ~1.9-2.2x mean, p99 >=1.5x in 9 of 10 strict runs; see tests/Unit/RustMonitoringBoundedMetricFamilyExporterSnapshotJwtW49ParityTest.php. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public const string GAUGE = 'gauge';

    public const string COUNTER = 'counter';

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var list<array{labels: array<string, string>, value: float|int}>
     */
    private array $samples = [];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49.5 batch helper routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Checks many `[name, type, label names]` definitions like {@see validateDefinitionPhp()} and
     * {@see normalizeSamplePhp()}. Per definition: `[status, type_code, label_count, first_invalid_label,
     * name_xxh64, schema_xxh64]` — status 0 valid, 1 invalid name, 2 unsupported type, 3 invalid label name;
     * type code 0 gauge, 1 counter, -1 other; first invalid label index or -1; the schema hash covers
     * `name{label,label}` so a changed label set changes the fingerprint.
     *
     * @param  list<array{string, string, list<string>}>  $definitions
     * @return list<list<int>>
     */
    public static function definitionCheckBatch(array $definitions): array
    {
        return self::usesW49RustFfi() ? self::definitionCheckBatchNative($definitions) : self::definitionCheckBatchPhp($definitions);
    }

    /**
     * @param  list<array{string, string, list<string>}>  $definitions
     * @return list<list<int>>
     */
    public static function definitionCheckBatchPhp(array $definitions): array
    {
        $rows = [];

        foreach ($definitions as [$name, $type, $labels]) {
            $badLabel = -1;
            foreach ($labels as $index => $label) {
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $label) !== 1) {
                    $badLabel = $index;
                    break;
                }
            }
            $typeCode = match ($type) {
                self::GAUGE => 0,
                self::COUNTER => 1,
                default => -1,
            };
            $status = match (true) {
                preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name) !== 1 => 1,
                $typeCode < 0 => 2,
                $badLabel >= 0 => 3,
                default => 0,
            };
            /** @var array{1: int} $hash */
            $hash = unpack('J', hash('xxh64', $name, true));
            /** @var array{1: int} $schema */
            $schema = unpack('J', hash('xxh64', $name.'{'.implode(',', $labels).'}', true));
            $rows[] = [$status, $typeCode, \count($labels), $badLabel, $hash[1], $schema[1]];
        }

        return $rows;
    }

    /**
     * @param  list<array{string, string, list<string>}>  $definitions
     * @return list<list<int>>
     */
    public static function definitionCheckBatchNative(array $definitions): array
    {
        $count = \count($definitions);
        if ($count === 0) {
            return [];
        }

        // NUL-delimited columns (names, types, then every label); Rust declines a value holding NUL (the field count no longer matches).
        $labels = array_column($definitions, 2);
        $labelCounts = array_map(\count(...), $labels);
        $data = implode("\0", array_merge(array_column($definitions, 0), array_column($definitions, 1), ...$labels));
        $words = self::w49Words($count, $data, pack('P*', ...$labelCounts), 6 * $count);

        return $words === null ? self::definitionCheckBatchPhp($definitions) : array_chunk($words, 6);
    }

    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly string $type = self::GAUGE,
    ) {
        self::validateDefinition($name, $type);
    }

    public static function validateDefinition(string $name, string $type = self::GAUGE): void
    {
        if (self::$useRustFfi) {
            self::validateDefinitionNative($name, $type);

            return;
        }

        self::validateDefinitionPhp($name, $type);
    }

    public static function validateDefinitionPhp(string $name, string $type = self::GAUGE): void
    {
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Prometheus metric name [{$name}].");
        }

        if (! \in_array($type, [self::GAUGE, self::COUNTER], true)) {
            throw new InvalidArgumentException("Unsupported Prometheus metric type [{$type}].");
        }
    }

    public static function validateDefinitionNative(string $name, string $type = self::GAUGE): void
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            self::validateDefinitionPhp($name, $type);

            return;
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_prometheus_validate_metric_definition(
            $name,
            \strlen($name),
            $type,
            \strlen($type)
        );
        if (! \is_int($res) || $res === -1) {
            self::validateDefinitionPhp($name, $type);

            return;
        }
        if ($res === -2) {
            throw new InvalidArgumentException("Invalid Prometheus metric name [{$name}].");
        }
        if ($res === -3) {
            throw new InvalidArgumentException("Unsupported Prometheus metric type [{$type}].");
        }
    }

    public static function gauge(string $name, string $help): self
    {
        return self::$useRustFfi
            ? self::gaugeNative($name, $help)
            : self::gaugePhp($name, $help);
    }

    public static function gaugePhp(string $name, string $help): self
    {
        self::validateDefinitionPhp($name, self::GAUGE);

        return new self($name, $help, self::GAUGE);
    }

    public static function gaugeNative(string $name, string $help): self
    {
        self::validateDefinitionNative($name, self::GAUGE);

        return new self($name, $help, self::GAUGE);
    }

    public static function counter(string $name, string $help): self
    {
        return self::$useRustFfi
            ? self::counterNative($name, $help)
            : self::counterPhp($name, $help);
    }

    public static function counterPhp(string $name, string $help): self
    {
        self::validateDefinitionPhp($name, self::COUNTER);

        return new self($name, $help, self::COUNTER);
    }

    public static function counterNative(string $name, string $help): self
    {
        self::validateDefinitionNative($name, self::COUNTER);

        return new self($name, $help, self::COUNTER);
    }

    /**
     * @param  array<string, string|int|float>  $labels
     */
    public function add(float|int $value, array $labels = []): self
    {
        $this->samples[] = self::normalizeSample($value, $labels);

        return $this;
    }

    /**
     * @param  array<string, string|int|float>  $labels
     * @return array{labels: array<string, string>, value: float|int}
     */
    public static function normalizeSample(float|int $value, array $labels = []): array
    {
        return self::$useRustFfi
            ? self::normalizeSampleNative($value, $labels)
            : self::normalizeSamplePhp($value, $labels);
    }

    /**
     * @param  array<string, string|int|float>  $labels
     * @return array{labels: array<string, string>, value: float|int}
     */
    public static function normalizeSamplePhp(float|int $value, array $labels = []): array
    {
        $normalized = [];

        foreach ($labels as $label => $labelValue) {
            if (! \is_string($label) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $label) !== 1) {
                throw new InvalidArgumentException("Invalid Prometheus label name [{$label}].");
            }

            $normalized[$label] = (string) $labelValue;
        }

        return ['labels' => $normalized, 'value' => $value];
    }

    /**
     * @param  array<string, string|int|float>  $labels
     * @return array{labels: array<string, string>, value: float|int}
     */
    public static function normalizeSampleNative(float|int $value, array $labels = []): array
    {
        if ($labels === []) {
            return ['labels' => [], 'value' => $value];
        }

        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::normalizeSamplePhp($value, $labels);
        }

        $flat = [];
        $keys = [];
        $pairCount = 0;
        foreach ($labels as $label => $labelValue) {
            if (! \is_string($label)) {
                throw new InvalidArgumentException("Invalid Prometheus label name [{$label}].");
            }
            $strVal = (string) $labelValue;
            if (str_contains($label, "\0") || str_contains($strVal, "\0")) {
                return self::normalizeSamplePhp($value, $labels);
            }
            $keys[] = $label;
            $flat[] = $label;
            $flat[] = $strVal;
            $pairCount++;
        }

        $joined = implode("\0", $flat);
        $len = \strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $cap);
        if ($scratch === null) {
            return self::normalizeSamplePhp($value, $labels);
        }
        $badIndex = $ffi->new('size_t[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_prometheus_normalize_labels_nul(
            $joined,
            $len,
            $pairCount,
            $badIndex,
            $scratch,
            $cap
        );
        if (\is_int($written) && $written < -2) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::normalizeSamplePhp($value, $labels);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_prometheus_normalize_labels_nul(
                $joined,
                $len,
                $pairCount,
                $badIndex,
                $scratch,
                $cap
            );
        }
        if ($written === -2) {
            /** @var int $invalidIdx */
            $invalidIdx = $badIndex[0];
            $badLabel = $keys[$invalidIdx] ?? (string) array_key_first($labels);
            throw new InvalidArgumentException("Invalid Prometheus label name [{$badLabel}].");
        }
        if (! \is_int($written) || $written < 0) {
            return self::normalizeSamplePhp($value, $labels);
        }

        $parts = explode("\0", FFI::string($scratch, $written));
        if (\count($parts) !== $pairCount * 2) {
            return self::normalizeSamplePhp($value, $labels);
        }

        $normalized = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $normalized[$parts[$i * 2]] = $parts[$i * 2 + 1];
        }

        return ['labels' => $normalized, 'value' => $value];
    }

    /**
     * @return list<array{labels: array<string, string>, value: float|int}>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * Exactly $words output words from the Wave 49.5 Rust helper, or null to fall back to PHP.
     *
     * @return array<int, int>|null
     */
    private static function w49Words(int $count, string $data, string $ints, int $words): ?array
    {
        if (self::$w49Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w49Ffi = RustPipelineLibrary::resolveDomain('search_w49', self::W49_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w49Out = null;
            self::$w49OutCap = 0;
        }
        $ffi = self::$w49Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w49Out === null || $words > self::$w49OutCap) {
                $cap = max(1024, $words);
                self::$w49Out = $ffi->new('int64_t['.$cap.']');
                self::$w49OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_metric_family_definition_check($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            return \is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enabled: the paired benchmark in the W60 parity test measures 5.091x common mean, 4.889x
     * common p99, 4.185x uncommon mean and 3.895x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_METRIC_FAMILY_FFI_CDEF = 'ssize_t nntmux_w601_metric_family_summary_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W60_FAMILY_WORDS = 6;

    public const int W60_FAMILY_TAIL = 9;

    public const int W60_TYPE_EXACT = 8;

    public const int W60_TYPE_SUPPORTED = 16;

    public const int W60_NAME_VALID = 32;

    public const int W60_NAME_RESERVED = 64;

    public const int W60_NAME_TOTAL = 128;

    public const int W60_COUNTER_NO_TOTAL = 256;

    public const int W60_GAUGE_TOTAL = 512;

    public const int W60_HELP_EMPTY = 1024;

    public const int W60_HELP_ESCAPED = 2048;

    public const int W60_LABEL_INVALID = 4096;

    public const int W60_LABEL_DUPLICATE = 8192;

    public const int W60_LABEL_RESERVED = 16384;

    public const int W60_SERIES_CLAMPED = 32768;

    public const int W60_CARDINALITY_EXCEEDED = 65536;

    public const int W60_DEFINITION_VALID = 131072;

    public const int W60_MALFORMED = 262144;

    private const array W60_TYPES = ['gauge' => 1, 'counter' => 2, 'histogram' => 3, 'summary' => 4, 'untyped' => 5];

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 60 batch routed to Rust or PHP, then restores the routing. */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function familySummaryBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::familySummaryBatchNative($rows) : self::familySummaryBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function familySummaryBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W60_FAMILY_WORDS + self::W60_FAMILY_TAIL;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($buffer === null) {
            return self::familySummaryBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound (Declared by W60_METRIC_FAMILY_FFI_CDEF.)
            $written = $ffi->nntmux_w601_metric_family_summary_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::familySummaryBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Six words per `name\ttype\thelp\tlabels\tseries\tmax_series` row (labels comma-separated):
     * the type code (0 unknown, 1 gauge, 2 counter, 3 histogram, 4 summary, 5 untyped; trimmed,
     * case-insensitive) with the exact-type, supported (exactly gauge or counter), name valid
     * ({@see validateDefinitionPhp()}'s pattern), reserved `__` name, `_total` suffix, counter
     * without / gauge with `_total`, empty help, escaped help, invalid, duplicate or reserved
     * label, series clamped (0-1000000000), cardinality exceeded (max > 0 and series > max) and
     * definition-valid flags; then the label count, first invalid label index (-1), escaped help
     * length, clamped series and headroom (max - series, -1 without a max). A malformed row
     * yields `[W60_MALFORMED, 0, 0, 0, 0, 0]`. The W60_FAMILY_TAIL words count rows, valid
     * definitions, labels, series, exceeded rows, the largest series, gauges, counters and
     * malformed rows.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function familySummaryBatchPhp(array $rows): array
    {
        $words = [];
        $tail = array_fill(0, self::W60_FAMILY_TAIL, 0);
        foreach ($rows as $row) {
            $tail[0]++;
            $f = explode("\t", $row);
            $ints = [];
            if (\count($f) === 6) {
                for ($i = 4; $i <= 5 && preg_match('/\A-?[0-9]{1,18}\z/', $f[$i]) === 1; $i++) {
                    $ints[] = (int) $f[$i];
                }
            }
            if (\count($ints) !== 2) {
                array_push($words, self::W60_MALFORMED, 0, 0, 0, 0, 0);
                $tail[8]++;

                continue;
            }
            [$name, $type, $help, $labelList] = $f;
            [$series, $max] = $ints;
            $code = self::W60_TYPES[strtolower(trim($type))] ?? 0;
            $nameValid = preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name) === 1;
            $supported = $type === self::GAUGE || $type === self::COUNTER;
            $total = str_ends_with($name, '_total');
            $escaped = \strlen(str_replace(['\\', "\n"], ['\\\\', '\\n'], $help));
            $flags = $code
                | (isset(self::W60_TYPES[$type]) ? self::W60_TYPE_EXACT : 0)
                | ($supported ? self::W60_TYPE_SUPPORTED : 0)
                | ($nameValid ? self::W60_NAME_VALID : 0)
                | (str_starts_with($name, '__') ? self::W60_NAME_RESERVED : 0)
                | ($total ? self::W60_NAME_TOTAL : 0)
                | ($code === 2 && ! $total ? self::W60_COUNTER_NO_TOTAL : 0)
                | ($code === 1 && $total ? self::W60_GAUGE_TOTAL : 0)
                | (trim($help) === '' ? self::W60_HELP_EMPTY : 0)
                | ($escaped !== \strlen($help) ? self::W60_HELP_ESCAPED : 0);
            $labels = $labelList === '' ? [] : explode(',', $labelList);
            $bad = -1;
            $reserved = false;
            foreach ($labels as $index => $label) {
                if ($bad < 0 && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $label) !== 1) {
                    $bad = $index;
                }
                $reserved = $reserved || str_starts_with($label, '__');
            }
            $count = \count($labels);
            $flags |= ($bad >= 0 ? self::W60_LABEL_INVALID : 0)
                | (\count(array_unique($labels)) !== $count ? self::W60_LABEL_DUPLICATE : 0)
                | ($reserved ? self::W60_LABEL_RESERVED : 0);
            $clamped = max(0, min(1_000_000_000, $series));
            $exceeded = $max > 0 && $clamped > $max;
            $valid = $nameValid && $supported && $bad < 0;
            $flags |= ($clamped !== $series ? self::W60_SERIES_CLAMPED : 0)
                | ($exceeded ? self::W60_CARDINALITY_EXCEEDED : 0)
                | ($valid ? self::W60_DEFINITION_VALID : 0);
            array_push($words, $flags, $count, $bad, $escaped, $clamped, $max > 0 ? $max - $clamped : -1);
            $tail[1] += $valid ? 1 : 0;
            $tail[2] += $count;
            $tail[3] += $clamped;
            $tail[4] += $exceeded ? 1 : 0;
            $tail[5] = max($tail[5], $clamped);
            $tail[6] += $code === 1 ? 1 : 0;
            $tail[7] += $code === 2 ? 1 : 0;
        }

        return [...$words, ...$tail];
    }

    /**
     * Wave 60 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics so the
     * class gains no Wave 60 static properties.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('metric_family_w60', self::W60_METRIC_FAMILY_FFI_CDEF) ?? false;
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
