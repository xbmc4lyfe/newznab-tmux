<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminLogViewerRequest extends FormRequest
{
    public const string FFI_CDEF = 'intptr_t nntmux_w22_article_source_enums_log_requests(uint32_t operation, const uint8_t *input, size_t length, uint8_t *output, size_t capacity);';

    public static function enableRustFfi(): void
    {
        self::toggleState(true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false);
    }

    public static function resetRustFfi(): void
    {
        self::toggleState(false);
        self::ffi(true);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState();
    }

    private static function toggleState(?bool $next = null): bool
    {
        static $enabled = false;
        if ($next !== null) {
            $enabled = $next;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('wave22-article-source-enums-log-requests', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<array-key, mixed>|string|null
     */
    private static function computeNative(int $operation, array $arguments): array|string|null
    {
        try {
            foreach ($arguments as $key => $value) {
                if (\ReflectionReference::fromArrayElement($arguments, $key) !== null) {
                    return null;
                }
                if ($operation === 8 && is_array($value)) {
                    if (! array_is_list($value)) {
                        return null;
                    }
                    foreach ($value as $index => $level) {
                        if (! is_string($level) || \ReflectionReference::fromArrayElement($value, $index) !== null) {
                            return null;
                        }
                    }
                } elseif ($value !== null && ! is_string($value) && ! is_bool($value) && ! is_int($value)) {
                    return null;
                }
            }
            $input = serialize($arguments);
            $length = strlen($input);
            if ($length > 64 * 1024 * 1024 - 1024 || ($ffi = self::ffi()) === null) {
                return null;
            }
            $capacity = $length + 1024;
            $inputBuffer = $ffi->new('uint8_t['.max(1, $length).']');
            FFI::memcpy($inputBuffer, $input, $length);
            $output = $ffi->new('uint8_t['.$capacity.']');
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w22_article_source_enums_log_requests($operation, $inputBuffer, $length, $output, $capacity);
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $result = unserialize(FFI::string($output, $written), ['allowed_classes' => false]);

            return is_array($result) || is_string($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->input('file');
        $query = $this->input('q', $this->input('search'));

        $this->merge(self::usesRustFfi() ? self::normalizeInputNative($file, $query) : self::normalizeInputPhp($file, $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{file: mixed, q: mixed} */
    public static function normalizeInputNative(mixed $file, mixed $query): array
    {
        $result = self::computeNative(9, [$file, $query]);

        return is_array($result) && array_keys($result) === ['file', 'q'] ? $result : self::normalizeInputPhp($file, $query);
    }

    /** @return array{file: mixed, q: mixed} */
    public static function normalizeInputPhp(mixed $file, mixed $query): array
    {
        return [
            'file' => is_string($file) ? trim($file) : $file,
            'q' => is_string($query) ? trim($query) : $query,
        ];
    }

    // ========================================================================
    // WAVE 59.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.33x, p99 6.99x; uncommon mean 4.96x, p99 3.88x); see RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_ADMIN_LOG_VIEWER_REQUEST_FFI_CDEF = <<<'C'
        ssize_t nntmux_w593_admin_log_viewer_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** LogEntryParser::LEVELS as bit indexes of w59LogViewerPlanBatch()'s level mask. */
    public const array W59_LOG_VIEWER_LEVEL_BITS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5,
        'alert' => 6, 'emergency' => 7];

    /** Known log channels, indexed from 1 (0 absent, 8 other `[a-z0-9_-]` names, 9 invalid). */
    public const array W59_LOG_VIEWER_CHANNELS = ['laravel' => 1, 'single' => 2, 'daily' => 3, 'stack' => 4, 'horizon' => 5, 'scheduler' => 6,
        'indexer' => 7];

    /** Regex-like search bytes that raise w59LogViewerPlanBatch()'s query complexity. */
    public const array W59_LOG_VIEWER_SPECIAL = ['(', ')', '[', ']', '{', '}', '^', '$', '\\', '+'];

    /** Trailer words after the per-item words of w59LogViewerPlanBatch(). */
    public const int W59_LOG_VIEWER_TRAILER = 26;

    public static function usesW59RustFfi(): bool
    {
        $override = self::w59RustFfiState();

        return \is_bool($override) ? $override : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 59 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 59.3 log viewer requests. Each item is `file|q|levels|channel|page` (missing fields are empty, every
     * field is trimmed). Per item `fileState | qState << 2 | plan << 4 | channel << 7 | pageState << 11 |
     * nonAscii << 13 | unbalancedQuote << 14 | levelMask << 15 | min(unknownLevels, 15) << 23 | complexity << 27 |
     * min(terms, 255) << 35 | min(qLength, 1023) << 43 | min(page, 1023) << 53`: file and query states are 0
     * empty, 1 up to 255 bytes and 2 longer; complexity is `min(255, 2 terms + 3 phrases + 4 wildcards + 5 special
     * bytes + 10 unbalanced + qLength / 16)` over space/tab separated terms, `"` pairs, `*`/`?` and
     * W59_LOG_VIEWER_SPECIAL; levels are comma separated, case-insensitive W59_LOG_VIEWER_LEVEL_BITS (others are
     * unknown); channel is 0 empty, 1-7 W59_LOG_VIEWER_CHANNELS (case-insensitive), 8 another `[a-z0-9_-]` name or
     * 9 invalid; page state is 0 empty, 1 a 1-6 digit page of at least 1 and 2 invalid (page 1); plan is 4
     * rejected (an oversized field, invalid channel or page, or an unknown level), else 0 browse or 1 level
     * filter without a query, 2 simple search (complexity below 20) or 3 complex search. Then five plan counts,
     * eight per-level counts, ten channel counts, the complexity sum, the largest complexity and the largest page.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogViewerPlanBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::w59LogViewerPlanBatchNative($items) : self::w59LogViewerPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogViewerPlanBatchNative(array $items): array
    {
        return self::w59Invoke($items, \count($items) + self::W59_LOG_VIEWER_TRAILER) ?? self::w59LogViewerPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogViewerPlanBatchPhp(array $items): array
    {
        $out = [];
        $plans = [0, 0, 0, 0, 0];
        $levelBits = array_fill(0, 8, 0);
        $channels = array_fill(0, 10, 0);
        $complexitySum = $maxComplexity = $maxPage = 0;
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $fileLength = \strlen(trim($f[0]));
            $fileState = $fileLength === 0 ? 0 : ($fileLength > 255 ? 2 : 1);
            $q = trim($f[1]);
            $length = \strlen($q);
            $qState = $length === 0 ? 0 : ($length > 255 ? 2 : 1);
            $terms = $length === 0 ? 0 : \count(preg_split('/[ \t]+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $quotes = substr_count($q, '"');
            $unbalanced = $quotes % 2;
            $wild = substr_count($q, '*') + substr_count($q, '?');
            $special = $length - \strlen(str_replace(self::W59_LOG_VIEWER_SPECIAL, '', $q));
            $complexity = min(255, $terms * 2 + intdiv($quotes, 2) * 3 + $wild * 4 + $special * 5 + $unbalanced * 10 + intdiv($length, 16));
            $nonAscii = preg_match('/[\x80-\xff]/', $q) === 1;
            $mask = $unknown = 0;
            foreach (explode(',', $f[2]) as $level) {
                $level = strtolower(trim($level));
                if ($level === '') {
                    continue;
                }
                if (isset(self::W59_LOG_VIEWER_LEVEL_BITS[$level])) {
                    $mask |= 1 << self::W59_LOG_VIEWER_LEVEL_BITS[$level];
                } else {
                    $unknown++;
                }
            }
            $channel = strtolower(trim($f[3]));
            $channelIdx = $channel === '' ? 0 : (self::W59_LOG_VIEWER_CHANNELS[$channel]
                ?? (strspn($channel, 'abcdefghijklmnopqrstuvwxyz0123456789_-') === \strlen($channel) ? 8 : 9));
            $page = trim($f[4]);
            if ($page === '') {
                [$pageState, $pageValue] = [0, 1];
            } elseif (\strlen($page) <= 6 && strspn($page, '0123456789') === \strlen($page) && (int) $page >= 1) {
                [$pageState, $pageValue] = [1, (int) $page];
            } else {
                [$pageState, $pageValue] = [2, 1];
            }
            $plan = match (true) {
                $fileState === 2 || $qState === 2 || $channelIdx === 9 || $pageState === 2 || $unknown > 0 => 4,
                $length === 0 => $mask === 0 ? 0 : 1,
                $complexity < 20 => 2,
                default => 3,
            };
            $plans[$plan]++;
            for ($bit = 0; $bit < 8; $bit++) {
                $levelBits[$bit] += $mask >> $bit & 1;
            }
            $channels[$channelIdx]++;
            $complexitySum += $complexity;
            $maxComplexity = max($maxComplexity, $complexity);
            $maxPage = max($maxPage, $pageValue);
            $out[] = $fileState | $qState << 2 | $plan << 4 | $channelIdx << 7 | $pageState << 11 | ($nonAscii ? 1 : 0) << 13
                | $unbalanced << 14 | $mask << 15 | min($unknown, 15) << 23 | $complexity << 27 | min($terms, 255) << 35
                | min($length, 1023) << 43 | min($pageValue, 1023) << 53;
        }
        array_push($out, ...$plans);
        array_push($out, ...$levelBits);
        array_push($out, ...$channels);
        array_push($out, $complexitySum, $maxComplexity, $maxPage);

        return $out;
    }

    /**
     * Wave 59 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w593', self::W59_ADMIN_LOG_VIEWER_REQUEST_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 59.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w59Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w59RustFfiState('ffi');
            $out = self::w59RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w59RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W59_ADMIN_LOG_VIEWER_REQUEST_FFI_CDEF.)
            $written = $ffi->nntmux_w593_admin_log_viewer_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
