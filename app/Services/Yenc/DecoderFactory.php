<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Services\RegexService;
use App\Services\Releases\Verification\LeaseHeartbeat;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DecoderFactory
{
    // Single-call FFI remains opt-in until every paired mean/p99 gate reaches 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** @var array<string, true> */
    private static array $reportedFailures = [];

    /** @var array<string, PayloadDecoder> */
    private array $decoders = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function make(string $mode = 'auto', string $library = ''): PayloadDecoder
    {
        if (! self::validMode($mode)) {
            throw new RuntimeException('YENC_DECODER must be auto, php or native.');
        }
        $key = self::cacheKey((int) getmypid(), $mode, $library);
        if (isset($this->decoders[$key])) {
            return $this->decoders[$key];
        }
        if (self::selectsPhp($mode, $library, PHP_SAPI, PHP_OS_FAMILY)) {
            return $this->decoders[$key] = new PhpPayloadDecoder;
        }
        try {
            return $this->decoders[$key] = new NativePayloadDecoder($library);
        } catch (Throwable $exception) {
            if ($mode === 'native') {
                throw new RuntimeException('Native yEnc is unavailable: '.$exception->getMessage(), previous: $exception);
            }
            $failureKey = self::failureKey((int) getmypid(), $library);
            if (! isset(self::$reportedFailures[$failureKey])) {
                self::$reportedFailures[$failureKey] = true;
                $message = 'Native yEnc is unavailable; using PHP: '.$exception->getMessage();
                if ($this->logger !== null) {
                    $this->logger->warning($message);
                } else {
                    error_log($message);
                }
            }

            return $this->decoders[$key] = new PhpPayloadDecoder;
        }
    }

    public static function validMode(string $mode): bool
    {
        return self::$useRustFfi ? (self::validModeNative($mode) ?? self::validModePhp($mode)) : self::validModePhp($mode);
    }

    public static function validModePhp(string $mode): bool
    {
        return in_array($mode, ['auto', 'php', 'native'], true);
    }

    public static function validModeNative(string $mode): ?bool
    {
        $result = LeaseHeartbeat::computeNative(8, [$mode])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    public static function cacheKey(int $pid, string $mode, string $library): string
    {
        return self::$useRustFfi ? (self::cacheKeyNative($pid, $mode, $library) ?? self::cacheKeyPhp($pid, $mode, $library)) : self::cacheKeyPhp($pid, $mode, $library);
    }

    public static function cacheKeyPhp(int $pid, string $mode, string $library): string
    {
        return $pid.':'.$mode.':'.$library;
    }

    public static function cacheKeyNative(int $pid, string $mode, string $library): ?string
    {
        $result = LeaseHeartbeat::computeNative(9, [$pid, $mode, $library])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    public static function failureKey(int $pid, string $library): string
    {
        return self::$useRustFfi ? (self::failureKeyNative($pid, $library) ?? self::failureKeyPhp($pid, $library)) : self::failureKeyPhp($pid, $library);
    }

    public static function failureKeyPhp(int $pid, string $library): string
    {
        return $pid.':'.$library;
    }

    public static function failureKeyNative(int $pid, string $library): ?string
    {
        $result = LeaseHeartbeat::computeNative(10, [$pid, $library])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    public static function selectsPhp(string $mode, string $library, string $sapi, string $osFamily): bool
    {
        return self::$useRustFfi ? (self::selectsPhpNative($mode, $library, $sapi, $osFamily) ?? self::selectsPhpPhp($mode, $library, $sapi, $osFamily)) : self::selectsPhpPhp($mode, $library, $sapi, $osFamily);
    }

    public static function selectsPhpPhp(string $mode, string $library, string $sapi, string $osFamily): bool
    {
        return $mode === 'php' || ($mode === 'auto' && ($library === '' || $sapi !== 'cli' || $osFamily !== 'Linux'));
    }

    public static function selectsPhpNative(string $mode, string $library, string $sapi, string $osFamily): ?bool
    {
        $result = LeaseHeartbeat::computeNative(11, [$mode, $library, $sapi, $osFamily])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all five runs (min 1.50x common p99; see the W46 parity test). */
    public const bool W461_RUST_FFI_DEFAULT = true;

    public const string W461_FFI_CDEF = <<<'C'
        ssize_t nntmux_w461_yenc_decoder_mode_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W461_SUMMARY_KEYS = ['total_modes', 'empty_mode_count', 'valid_count', 'auto_count', 'php_count', 'native_count', 'selects_php_count', 'invalid_count'];

    private static ?bool $w461UseRustFfi = null;

    private static FFI|false|null $w461Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW461RustFfi(?bool $enable = true): void
    {
        self::$w461UseRustFfi = $enable;
    }

    public static function usesW461RustFfi(): bool
    {
        return self::$w461UseRustFfi ?? self::W461_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; setUseRustFfi() is untouched. */
    public static function resetW461RustFfi(): void
    {
        self::$w461UseRustFfi = null;
        self::$w461Ffi = null;
    }

    /**
     * Summarizes YENC_DECODER values with validModePhp() and selectsPhpPhp() for one library, SAPI and OS
     * family: valid modes by name, those make() would serve with the PHP decoder, and invalid modes.
     * Entries come from strings, integers, or the first non-null mode, decoder array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeModeBatch(array $rows, string $library, string $sapi, string $osFamily): array
    {
        return self::usesW461RustFfi() ? self::summarizeModeBatchNative($rows, $library, $sapi, $osFamily) : self::summarizeModeBatchPhp($rows, $library, $sapi, $osFamily);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeModeBatchNative(array $rows, string $library, string $sapi, string $osFamily): array
    {
        // Rust needs only whether each selectsPhpPhp() environment condition holds.
        $flags = ($library !== '' ? 1 : 0) | ($sapi === 'cli' ? 2 : 0) | ($osFamily === 'Linux' ? 4 : 0);
        $values = RegexService::w461Invoke(self::$w461Ffi, self::W461_FFI_CDEF, 'nntmux_w461_yenc_decoder_mode_batch', $rows, ['mode', 'decoder'], \count(self::W461_SUMMARY_KEYS), pack('V', $flags));

        return $values === null ? self::summarizeModeBatchPhp($rows, $library, $sapi, $osFamily) : array_combine(self::W461_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeModeBatchPhp(array $rows, string $library, string $sapi, string $osFamily): array
    {
        $summary = array_fill_keys(self::W461_SUMMARY_KEYS, 0);
        foreach (RegexService::w461Entries($rows, ['mode', 'decoder']) as $mode) {
            $summary['total_modes']++;
            if ($mode === '') {
                $summary['empty_mode_count']++;

                continue;
            }
            if (! self::validModePhp($mode)) {
                $summary['invalid_count']++;

                continue;
            }
            $summary['valid_count']++;
            $summary[$mode.'_count']++;
            $summary['selects_php_count'] += (int) self::selectsPhpPhp($mode, $library, $sapi, $osFamily);
        }

        return $summary;
    }

    // ---- Wave 63.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 11.78x/11.54x, uncommon 10.49x/7.53x); see RustFingerprintCollectorYencDecoderPartServiceLogFileW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_DECODER_FACTORY_FFI_CDEF = <<<'C'
        intptr_t nntmux_w632_decoder_factory_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W63_DECODER_FACTORY_TAIL = 20;

    /**
     * Wave 63 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w63', self::W63_DECODER_FACTORY_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w63RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w63DecoderFactoryPlanBatch() to Rust until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Routes w63DecoderFactoryPlanBatch() to PHP until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w63DecoderFactoryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63DecoderFactoryPlanBatch(array $rows): array
    {
        return self::usesW63RustFfi() ? self::w63DecoderFactoryPlanBatchNative($rows) : self::w63DecoderFactoryPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63DecoderFactoryPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W63_DECODER_FACTORY_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w63RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w63DecoderFactoryPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W63_DECODER_FACTORY_FFI_CDEF.)
            $written = $ffi->nntmux_w632_decoder_factory_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w63DecoderFactoryPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w63Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w63Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Largest decode buffer w63DecoderFactoryPlanBatch() sizes. */
    public const int W63_DECODER_MAX_BUFFER = 67_108_864;

    /**
     * Wave 63.2 decoder plan. Per `mode\tlibrary\tsapi\tos\tpayload_bytes\tline_length` row `mode |
     * decision << 2 | flags << 4 | buffer_exponent << 11 | line << 16 | min(lines, 2^24 - 1) << 24
     * | (crc32(mode:library) & 0x7FFF) << 48`. Modes are matched exactly (validModePhp()): 0
     * invalid, 1 auto, 2 php, 3 native. Decisions: 0 invalid, 1 PHP decoder (selectsPhpPhp()), 2
     * native with PHP fallback (auto), 3 native required. Flags: 1 library set, 2 SAPI `cli`, 4 OS
     * family `Linux`, 8 absolute library path, 16 payload above W63_DECODER_MAX_BUFFER, 32 line length
     * defaulted (128) or clamped (254), 64 malformed payload size. The payload (w63Int(), 12 digits,
     * else 0) sizes a power-of-two buffer of 4096 to W63_DECODER_MAX_BUFFER bytes; lines is
     * ceil(payload / line). Then four mode, four decision and seven flag counts, the distinct cache
     * keys of valid rows (by crc32), the total payload, the largest buffer exponent, the total
     * lines and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63DecoderFactoryPlanBatchPhp(array $rows): array
    {
        $out = $keys = [];
        $modes = $decisions = [0, 0, 0, 0];
        $flagCounts = array_fill(0, 7, 0);
        $totalPayload = $maxExponent = $totalLines = 0;
        foreach ($rows as $row) {
            [$mode, $library, $sapi, $os, $payload, $line] = explode("\t", (string) $row, 6) + array_fill(0, 6, '');
            $code = match ($mode) {
                'auto' => 1,
                'php' => 2,
                'native' => 3,
                default => 0,
            };
            $decision = match (true) {
                $code === 0 => 0,
                self::selectsPhpPhp($mode, $library, $sapi, $os) => 1,
                $code === 1 => 2,
                default => 3,
            };
            $payload = self::w63Int($payload, 12);
            $bytes = \is_int($payload) ? $payload : 0;
            $length = self::w63Int($line, 3);
            [$line, $adjusted] = match (true) {
                \is_int($length) && $length >= 1 && $length <= 254 => [$length, false],
                \is_int($length) && $length > 254 => [254, true],
                default => [128, true],
            };
            $exponent = \strlen(decbin(max(4096, min($bytes, self::W63_DECODER_MAX_BUFFER)) - 1));
            $lines = $bytes === 0 ? 0 : intdiv($bytes + $line - 1, $line);
            $crc = crc32($mode.':'.$library);
            if ($code !== 0) {
                $keys[$crc] = true;
            }
            $flags = (int) ($library !== '') | (int) ($sapi === 'cli') << 1 | (int) ($os === 'Linux') << 2 | (int) str_starts_with($library, '/') << 3
                | (int) ($bytes > self::W63_DECODER_MAX_BUFFER) << 4 | (int) $adjusted << 5 | (int) ($payload === false) << 6;
            $modes[$code]++;
            $decisions[$decision]++;
            for ($k = 0; $k < 7; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            $totalPayload += $bytes;
            $maxExponent = max($maxExponent, $exponent);
            $totalLines += $lines;
            $out[] = $code | $decision << 2 | $flags << 4 | $exponent << 11 | $line << 16 | min($lines, 0xFFFFFF) << 24 | ($crc & 0x7FFF) << 48;
        }
        array_push($out, ...$modes, ...$decisions, ...$flagCounts);
        array_push($out, \count($keys), $totalPayload, $maxExponent, $totalLines);
        $out[] = self::w63Digest($out);

        return $out;
    }
}
