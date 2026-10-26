<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\LogViewer\LogEntryParser;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Validates log viewer actions that target a single file (entries, entry, download, truncate, delete).
 */
class AdminLogFileRequest extends FormRequest
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

    /**
     * @var list<int>
     */
    public const array LIMIT_OPTIONS = [50, 100, 200, 500];

    public const int DEFAULT_LIMIT = 100;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->input('file');

        $this->merge(self::usesRustFfi() ? self::normalizeInputNative($file) : self::normalizeInputPhp($file));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'string', 'max:255'],
            'before' => ['nullable', 'integer', 'min:0'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', Rule::in(self::LIMIT_OPTIONS)],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['string', Rule::in(LogEntryParser::LEVELS)],
        ];
    }

    /**
     * @return list<string>
     */
    public function levels(): array
    {
        $levels = (array) $this->validated('levels', []);

        return self::usesRustFfi() ? self::normalizeLevelsNative($levels) : self::normalizeLevelsPhp($levels);
    }

    /** @return array{file: mixed} */
    public static function normalizeInputNative(mixed $file): array
    {
        $result = self::computeNative(7, [$file]);

        return is_array($result) && array_keys($result) === ['file'] ? $result : self::normalizeInputPhp($file);
    }

    /** @return array{file: mixed} */
    public static function normalizeInputPhp(mixed $file): array
    {
        return ['file' => is_string($file) ? trim($file) : $file];
    }

    /**
     * @param  array<array-key, mixed>  $levels
     * @return list<string>
     */
    public static function normalizeLevelsNative(array $levels): array
    {
        $result = self::computeNative(8, [$levels]);
        if (is_array($result) && array_is_list($result)) {
            foreach ($result as $level) {
                if (! is_string($level)) {
                    return self::normalizeLevelsPhp($levels);
                }
            }

            return $result;
        }

        return self::normalizeLevelsPhp($levels);
    }

    /**
     * @param  array<array-key, mixed>  $levels
     * @return list<string>
     */
    public static function normalizeLevelsPhp(array $levels): array
    {
        return array_values(array_unique(array_map('strval', $levels)));
    }

    // ========================================================================
    // WAVE 59.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.54x, p99 4.96x; uncommon mean 5.33x, p99 4.15x); see RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_ADMIN_LOG_FILE_REQUEST_FFI_CDEF = <<<'C'
        ssize_t nntmux_w593_admin_log_file_check_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Bytes w59LogFileCheckBatch() accepts in a log file path. */
    public const string W59_LOG_FILE_SAFE_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._/-';

    /** LIMIT_OPTIONS by their exact spelling, as indexes. */
    public const array W59_LOG_FILE_LIMIT_INDEX = ['50' => 0, '100' => 1, '200' => 2, '500' => 3];

    /** Trailer words after the per-item words of w59LogFileCheckBatch(). */
    public const int W59_LOG_FILE_TRAILER = 18;

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
     * Wave 59.3 log file requests. Each item is `file|before|offset|limit` (missing fields are empty, every
     * field is trimmed). Per item `status | ext << 3 | hidden << 6 | dated << 7 | min(depth, 15) << 8 |
     * beforeState << 12 | offsetState << 14 | limitState << 16 | limitIndex << 18 | mode << 20 | conflict << 22 |
     * min(fileLength, 1023) << 23 | min(start, 2^30 - 1) << 33`: status is 0 ok, 1 empty, 2 over 255 bytes, 3 NUL
     * byte, 4 path traversal (leading `/`, a backslash or a `..` segment) and 5 bytes outside
     * W59_LOG_FILE_SAFE_BYTES; ext classifies the lower-cased base name as 0 `.log`, 1 `.gz`, 2 `.log.<1-3
     * digits>`, 3 `.txt` or 4 other; dated marks a `-YYYY-MM-DD.log` suffix and depth counts `/`; the
     * before/offset states are 0 empty, 1 up to 18 digits and 2 invalid; limit state is 0 empty, 1 an exact
     * LIMIT_OPTIONS value and 2 invalid (both default to DEFAULT_LIMIT); mode is 2 tail (a valid before wins,
     * conflict marks a valid offset too), 1 offset page or 0 head, and the window is `[max(0, before - limit),
     * before)`, `[offset, offset + limit)` or `[0, limit)`. Then six status, five extension and three mode counts,
     * the conflicts, the window span sum, the largest window end and the distinct ok files.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogFileCheckBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::w59LogFileCheckBatchNative($items) : self::w59LogFileCheckBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogFileCheckBatchNative(array $items): array
    {
        return self::w59Invoke($items, \count($items) + self::W59_LOG_FILE_TRAILER) ?? self::w59LogFileCheckBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59LogFileCheckBatchPhp(array $items): array
    {
        $out = [];
        $statuses = [0, 0, 0, 0, 0, 0];
        $exts = [0, 0, 0, 0, 0];
        $modes = [0, 0, 0];
        $conflicts = $spanSum = $maxEnd = 0;
        $files = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 4) + ['', '', '', ''];
            $file = trim($f[0]);
            $length = \strlen($file);
            $status = match (true) {
                $length === 0 => 1,
                $length > 255 => 2,
                str_contains($file, "\0") => 3,
                $file[0] === '/' || str_contains($file, '\\') || $file === '..' || str_starts_with($file, '../')
                    || str_ends_with($file, '/..') || str_contains($file, '/../') => 4,
                strspn($file, self::W59_LOG_FILE_SAFE_BYTES) !== $length => 5,
                default => 0,
            };
            $slash = strrpos($file, '/');
            $base = strtolower($slash === false ? $file : substr($file, $slash + 1));
            $dot = strrpos($base, '.');
            $rotated = false;
            if ($dot !== false) {
                $tail = substr($base, $dot + 1);
                $rotated = $tail !== '' && \strlen($tail) <= 3 && strspn($tail, '0123456789') === \strlen($tail)
                    && str_ends_with(substr($base, 0, $dot), '.log');
            }
            $ext = match (true) {
                str_ends_with($base, '.log') => 0,
                str_ends_with($base, '.gz') => 1,
                $rotated => 2,
                str_ends_with($base, '.txt') => 3,
                default => 4,
            };
            $hidden = $base !== '' && $base[0] === '.';
            $dated = preg_match('/-\d{4}-\d{2}-\d{2}\.log\z/', $base) === 1;
            $depth = min(substr_count($file, '/'), 15);
            $before = trim($f[1]);
            $beforeState = $before === '' ? 0 : (\strlen($before) <= 18 && strspn($before, '0123456789') === \strlen($before) ? 1 : 2);
            $offset = trim($f[2]);
            $offsetState = $offset === '' ? 0 : (\strlen($offset) <= 18 && strspn($offset, '0123456789') === \strlen($offset) ? 1 : 2);
            $limit = trim($f[3]);
            $index = self::W59_LOG_FILE_LIMIT_INDEX[$limit] ?? null;
            $limitState = $limit === '' ? 0 : ($index !== null ? 1 : 2);
            $index ??= 1;
            $limitValue = self::LIMIT_OPTIONS[$index];
            $mode = $beforeState === 1 ? 2 : ($offsetState === 1 ? 1 : 0);
            $conflict = $beforeState === 1 && $offsetState === 1;
            [$start, $end] = match ($mode) {
                2 => [max(0, (int) $before - $limitValue), (int) $before],
                1 => [(int) $offset, (int) $offset + $limitValue],
                default => [0, $limitValue],
            };
            $statuses[$status]++;
            $exts[$ext]++;
            $modes[$mode]++;
            $conflicts += $conflict ? 1 : 0;
            $spanSum += $end - $start;
            $maxEnd = max($maxEnd, $end);
            if ($status === 0) {
                $files[$file] = true;
            }
            $out[] = $status | $ext << 3 | ($hidden ? 1 : 0) << 6 | ($dated ? 1 : 0) << 7 | $depth << 8 | $beforeState << 12
                | $offsetState << 14 | $limitState << 16 | $index << 18 | $mode << 20 | ($conflict ? 1 : 0) << 22
                | min($length, 1023) << 23 | min($start, (1 << 30) - 1) << 33;
        }
        array_push($out, ...$statuses);
        array_push($out, ...$exts);
        array_push($out, ...$modes);
        array_push($out, $conflicts, $spanSum, $maxEnd, \count($files));

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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w593', self::W59_ADMIN_LOG_FILE_REQUEST_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W59_ADMIN_LOG_FILE_REQUEST_FFI_CDEF.)
            $written = $ffi->nntmux_w593_admin_log_file_check_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
