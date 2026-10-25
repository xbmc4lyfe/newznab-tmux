<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Services\RegexService;
use App\Services\Releases\Verification\LeaseHeartbeat;
use FFI;
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
}
