<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Prometheus;

use App\Support\RustPipelineLibrary;
use FFI;
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

    private static FFI|false|null $ffi = null;

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
            $pairs[] = $name.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
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
}
