<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Services\Releases\Verification\LeaseHeartbeat;
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
}
