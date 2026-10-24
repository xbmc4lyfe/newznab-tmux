<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Prometheus;

use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;

/**
 * One Prometheus metric family: a name, type, help text and its samples.
 */
final class MetricFamily
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_prometheus_validate_metric_definition(const char *name_ptr, size_t name_len, const char *type_ptr, size_t type_len);
        ssize_t nntmux_prometheus_normalize_labels_nul(const char *input_ptr, size_t input_len, size_t pair_count, size_t *out_invalid_index, char *out_buf, size_t out_cap);
        C;

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
}
