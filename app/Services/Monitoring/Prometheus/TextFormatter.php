<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Prometheus;

use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Renders metric families in the Prometheus text exposition format 0.0.4.
 */
final class TextFormatter
{
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_predb_validate_filename_candidate(const char *id_str, size_t id_len, int64_t id_int, uint32_t has_id_int, const char *title, size_t title_len, const char *filename, size_t filename_len, const char *source, size_t source_len);
        ssize_t nntmux_prometheus_format_text(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_plain_search_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int64_t nntmux_datetime_to_unix_utc(const char *input, size_t input_len);
        C;

    /**
     * Rust stays opt-in for the Wave 44.5 summary: common p99 missed 1.5x on small label batches; see
     * tests/Unit/RustLogViewerIndexParserSearchPrometheusDocW44ParityTest.php.
     */
    public const bool W445_RUST_FFI_DEFAULT = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW445RustFfi = null;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    /**
     * @param  iterable<MetricFamily>  $families
     */
    public function format(iterable $families): string
    {
        $list = is_array($families) ? $families : iterator_to_array($families, false);
        if ($list === []) {
            return '';
        }

        if ($this->useNative) {
            $ffi = self::$ffi ??= (RustPipelineLibrary::resolveDomain('search_doc_metrics', self::FFI_CDEF) ?? false);
            if ($ffi !== false) {
                try {
                    $serialized = serialize($list);
                    $inLen = strlen($serialized);
                    $cap = 0;
                    $scratch = RustPipelineLibrary::obtainScratch($ffi, max(4096, $inLen), $cap);
                    if ($scratch !== null) {
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_prometheus_format_text($serialized, $inLen, $scratch, $cap);
                        if (is_int($written) && $written < -2) {
                            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                            if ($scratch !== null) {
                                // @phpstan-ignore method.notFound
                                $written = $ffi->nntmux_prometheus_format_text($serialized, $inLen, $scratch, $cap);
                            }
                        }
                        if (is_int($written) && $written >= 0 && $scratch !== null) {
                            return $written === 0 ? '' : FFI::string($scratch, $written);
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        return $this->formatPhp($list);
    }

    /**
     * @param  iterable<MetricFamily>  $families
     */
    private function formatPhp(iterable $families): string
    {
        $lines = [];

        foreach ($families as $family) {
            if ($family->samples() === []) {
                continue;
            }

            $lines[] = '# HELP '.$family->name.' '.$this->escapeHelp($family->help);
            $lines[] = '# TYPE '.$family->name.' '.$family->type;

            foreach ($family->samples() as $sample) {
                $lines[] = $family->name.$this->formatLabels($sample['labels']).' '.$this->formatValue($sample['value']);
            }
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function formatValue(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /** Pass null to restore {@see W445_RUST_FFI_DEFAULT}. */
    public static function useW445RustFfi(?bool $enable = true): void
    {
        self::$useW445RustFfi = $enable;
    }

    public static function usesW445RustFfi(): bool
    {
        return self::$useW445RustFfi ?? self::W445_RUST_FFI_DEFAULT;
    }

    public static function resetW445RustFfi(): void
    {
        self::$useW445RustFfi = null;
    }

    /**
     * Summarizes values as the formatter escapes them: values changed as a label value
     * (`label_escaped`) or as help text (`help_escaped`), the escaped `backslashes`, `quotes` and
     * `newlines`, `input_bytes`, escaped `label_bytes` / `help_bytes` and `distinct_values`.
     * Non-strings are invalid.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, label_escaped: int, help_escaped: int, backslashes: int, quotes: int, newlines: int, input_bytes: int, label_bytes: int, help_bytes: int, distinct_values: int}
     */
    public function summarizeEscapeBatch(array $values): array
    {
        return self::usesW445RustFfi() ? $this->summarizeEscapeBatchNative($values) : $this->summarizeEscapeBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, label_escaped: int, help_escaped: int, backslashes: int, quotes: int, newlines: int, input_bytes: int, label_bytes: int, help_bytes: int, distinct_values: int}
     */
    public function summarizeEscapeBatchPhp(array $values): array
    {
        $summary = ['values' => \count($values), 'invalid' => 0, 'label_escaped' => 0, 'help_escaped' => 0, 'backslashes' => 0, 'quotes' => 0, 'newlines' => 0, 'input_bytes' => 0, 'label_bytes' => 0, 'help_bytes' => 0, 'distinct_values' => 0];
        $distinct = [];
        foreach ($values as $value) {
            if (! \is_string($value)) {
                $summary['invalid']++;

                continue;
            }
            $label = $this->escapeLabelValue($value);
            $help = $this->escapeHelp($value);
            $summary['label_escaped'] += (int) ($label !== $value);
            $summary['help_escaped'] += (int) ($help !== $value);
            $summary['backslashes'] += substr_count($value, '\\');
            $summary['quotes'] += substr_count($value, '"');
            $summary['newlines'] += substr_count($value, "\n");
            $summary['input_bytes'] += \strlen($value);
            $summary['label_bytes'] += \strlen($label);
            $summary['help_bytes'] += \strlen($help);
            $distinct[$value] = true;
        }
        $summary['distinct_values'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, label_escaped: int, help_escaped: int, backslashes: int, quotes: int, newlines: int, input_bytes: int, label_bytes: int, help_bytes: int, distinct_values: int}
     */
    public function summarizeEscapeBatchNative(array $values): array
    {
        $strings = array_values(array_filter($values, 'is_string'));
        $fields = ReleaseSearchIndexDocument::evaluateW445Native(4, $strings, 9);
        if ($fields === null) {
            return $this->summarizeEscapeBatchPhp($values);
        }

        return ['values' => \count($values), 'invalid' => \count($values) - \count($strings)]
            + array_combine(['label_escaped', 'help_escaped', 'backslashes', 'quotes', 'newlines', 'input_bytes', 'label_bytes', 'help_bytes', 'distinct_values'], $fields);
    }

    /**
     * Enabled: the paired benchmark in the W56 parity test measures 2.987x common mean, 2.803x
     * common p99, 2.861x uncommon mean and 2.58x uncommon p99 (per-metric minimum of three runs),
     * above the 1.5x rule.
     */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = 'ssize_t nntmux_w561_prometheus_token_code_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W56_TOKEN_METRIC_NAME = 1;

    public const int W56_TOKEN_LABEL_NAME = 2;

    public const int W56_TOKEN_RESERVED_LABEL = 4;

    public const int W56_TOKEN_LABEL_ESCAPE = 8;

    public const int W56_TOKEN_HELP_ESCAPE = 16;

    public const int W56_TOKEN_LENGTH_SHIFT = 8;

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
     * One word per exposition token: `W56_TOKEN_*` flags for a valid metric name and label name
     * (the {@see MetricFamily} checks, whose `$` also matches before a final LF), a reserved `__`
     * label prefix and whether the token changes when escaped as a label value or as help text,
     * plus the escaped label value length `<< W56_TOKEN_LENGTH_SHIFT`.
     *
     * @param  array<array-key, string>  $tokens
     * @return list<int>
     */
    public static function expositionTokenCodes(array $tokens): array
    {
        return self::usesW56RustFfi() ? self::expositionTokenCodesNative($tokens) : self::expositionTokenCodesPhp($tokens);
    }

    /**
     * @param  array<array-key, string>  $tokens
     * @return list<int>
     */
    public static function expositionTokenCodesPhp(array $tokens): array
    {
        $codes = [];
        foreach ($tokens as $token) {
            $code = (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $token) === 1 ? self::W56_TOKEN_METRIC_NAME : 0)
                | (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $token) === 1 ? self::W56_TOKEN_LABEL_NAME : 0)
                | (str_starts_with($token, '__') ? self::W56_TOKEN_RESERVED_LABEL : 0)
                | (strpbrk($token, "\\\n") !== false ? self::W56_TOKEN_HELP_ESCAPE : 0);
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $token);
            if ($escaped !== $token) {
                $code |= self::W56_TOKEN_LABEL_ESCAPE;
            }
            $codes[] = $code | \strlen($escaped) << self::W56_TOKEN_LENGTH_SHIFT;
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a token
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $tokens
     * @return list<int>
     */
    public static function expositionTokenCodesNative(array $tokens): array
    {
        $count = \count($tokens);
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w56RustFfiState('out', $count);
        if ($buffer === null) {
            return self::expositionTokenCodesPhp($tokens);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $tokens);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w561_prometheus_token_code_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $count * 8)) : false;
        if (! \is_array($codes)) {
            return self::expositionTokenCodesPhp($tokens);
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
                $ffi ??= RustPipelineLibrary::resolveDomain('prometheus_text_w56', self::W56_FFI_CDEF) ?? false;
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
