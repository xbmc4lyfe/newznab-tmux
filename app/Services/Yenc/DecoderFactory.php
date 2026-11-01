<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DecoderFactory
{
    /** @var array<string, true> */
    private static array $reportedFailures = [];

    /** @var array<string, PayloadDecoder> */
    private array $decoders = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function make(string $mode = 'auto', string $library = ''): PayloadDecoder
    {
        if (! in_array($mode, ['auto', 'php', 'native'], true)) {
            throw new RuntimeException('YENC_DECODER must be auto, php or native.');
        }
        $key = getmypid().':'.$mode.':'.$library;
        if (isset($this->decoders[$key])) {
            return $this->decoders[$key];
        }
        if ($mode === 'php' || ($mode === 'auto' && ($library === '' || PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux'))) {
            return $this->decoders[$key] = new PhpPayloadDecoder;
        }
        try {
            return $this->decoders[$key] = new NativePayloadDecoder($library);
        } catch (Throwable $exception) {
            if ($mode === 'native') {
                throw new RuntimeException('Native yEnc is unavailable: '.$exception->getMessage(), previous: $exception);
            }
            $failureKey = getmypid().':'.$library;
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
}
