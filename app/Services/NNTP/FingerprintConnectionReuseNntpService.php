<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use LogicException;
use RuntimeException;
use Throwable;

/** An explicitly scoped candidate; ordinary NNTPService callers retain their existing lifecycle. */
class FingerprintConnectionReuseNntpService extends NNTPService
{
    private bool $collectionActive = false;

    private bool $operationActive = false;

    private bool $reuseEligible = false;

    private bool $claimed = false;

    private ?self $alternateConnection = null;

    public function withFingerprintConnectionReuse(callable $collect): mixed
    {
        if ($this->collectionActive || $this->operationActive || $this->claimed || $this->_socket !== null
            || $this->_usingAlternateServer || $this->_currentServer !== $this->_configServer
            || $this->_currentPort !== $this->_configPort) {
            throw new LogicException('Fingerprint reuse requires a fresh primary owner.');
        }

        $this->collectionActive = true;
        try {
            return $collect();
        } finally {
            $alternate = $this->alternateConnection;
            $this->alternateConnection = null;
            $this->collectionActive = false;
            $alternate?->closeOwnedSocket();
            $this->closeOwnedSocket();
        }
    }

    public function getFingerprintServerProfile(bool $alternate = false): array
    {
        if (! $this->collectionActive || ! $alternate) {
            return parent::getFingerprintServerProfile($alternate);
        }

        return $this->readFromAlternate(static fn (self $client): array => $client->readCurrentProfile());
    }

    public function getFingerprintArticleByMessageID(string $identifier, int $maximumBodyBytes = 2_000_000,
        int $maximumHeaderBytes = 65_536, bool $alternate = false, bool $includeOverview = false): array
    {
        if (! $this->collectionActive || ! $alternate) {
            return parent::getFingerprintArticleByMessageID($identifier, $maximumBodyBytes, $maximumHeaderBytes, $alternate, $includeOverview);
        }

        $identifier = self::verificationMessageId($identifier, $maximumBodyBytes);

        return $this->readFromAlternate(static fn (self $client): array => $client->readCurrentArticle(
            $identifier, $maximumBodyBytes, $maximumHeaderBytes, $includeOverview,
        ));
    }

    /**
     * @param  callable(self): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function readFromAlternate(callable $read): array
    {
        $client = $this->alternateConnection;
        if ($client !== null && (! $client->reuseEligible || ! $client->isConnected())) {
            $this->alternateConnection = null;
            $client->closeOwnedSocket();
            $client = null;
        }
        if ($client === null) {
            $client = $this->previewAlternateConnection();
            if (! $client instanceof self || $client === $this || $client->claimed
                || $client->collectionActive || $client->operationActive || $client->_socket !== null) {
                throw new LogicException('Fingerprint reuse requires a distinct fresh alternate client.');
            }
            $client->claimed = true;
            $this->alternateConnection = $client;
            try {
                if ($client->doConnect($this->_configCompressedHeaders, true) !== true) {
                    throw new RuntimeException('Unable to connect to the requested NNTP provider.');
                }
            } catch (Throwable $exception) {
                $this->alternateConnection = null;
                $client->closeOwnedSocket();
                throw $exception;
            }
        }

        try {
            return $read($client);
        } finally {
            if (! $client->reuseEligible) {
                $this->alternateConnection = null;
            }
        }
    }

    /** @return array<string, mixed> */
    private function readCurrentProfile(): array
    {
        return $this->observeOperation(fn (): array => parent::getFingerprintServerProfile(false));
    }

    /** @return array<string, mixed> */
    private function readCurrentArticle(string $identifier, int $maximumBodyBytes, int $maximumHeaderBytes, bool $includeOverview): array
    {
        return $this->observeOperation(fn (): array => parent::getFingerprintArticleByMessageID(
            $identifier, $maximumBodyBytes, $maximumHeaderBytes, false, $includeOverview,
        ));
    }

    /**
     * @param  callable(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function observeOperation(callable $read): array
    {
        $this->operationActive = true;
        $this->reuseEligible = true;
        try {
            return $read();
        } catch (Throwable $exception) {
            $this->reuseEligible = false;
            throw $exception;
        } finally {
            $this->operationActive = false;
            if (! $this->reuseEligible || ! $this->isConnected()) {
                $this->reuseEligible = false;
                $this->closeOwnedSocket();
            }
        }
    }

    protected function previewAlternateConnection(): NNTPService
    {
        return new self;
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        // The alternate path already admitted this child. An EOF race must not add another login.
        return $this->operationActive ? true : parent::_checkConnection($reSelectGroup);
    }

    protected function _sendCommand(string $cmd): mixed
    {
        if (! $this->operationActive) {
            return parent::_sendCommand($cmd);
        }

        // Keep the current request's vendor write/parser behavior; observations only veto reuse.
        $expected = match (true) {
            $cmd === 'CAPABILITIES' => 101,
            $cmd === 'LIST OVERVIEW.FMT' => 215,
            str_starts_with($cmd, 'OVER <') => 224,
            str_starts_with($cmd, 'HEAD <') => 221,
            str_starts_with($cmd, 'BODY <') => 222,
            default => null,
        };
        if (strlen($cmd) > 510) {
            $this->reuseEligible = false;

            return $this->throwError('Failed writing to socket! (Command too long - max 510 chars)');
        }
        if (strpbrk($cmd, "\r\n") !== false) {
            $this->reuseEligible = false;
            $this->_logger?->debug('Illegal character in command: contains carriage return/new line');

            return $this->throwError('Illegal character(s) in NNTP command!');
        }
        if (! $this->_isConnected()) {
            $this->reuseEligible = false;

            return $this->throwError('Failed to write to socket! (connection lost!)');
        }
        $wire = $cmd."\r\n";
        $written = $this->writeFingerprintReuseCommand($wire);
        if ($written !== strlen($wire)) {
            $this->reuseEligible = false;
        }
        if ($written === false) {
            return $this->throwError('Failed to write to socket!');
        }
        $this->_logger?->debug('C: '.$cmd);
        $response = $this->_getStatusResponse();
        if ($expected === null || $response !== $expected) {
            $this->reuseEligible = false;
        }

        return $response;
    }

    protected function _getStatusResponse(): mixed
    {
        if (! $this->operationActive) {
            return parent::_getStatusResponse();
        }

        $this->_clearOpensslErrors();
        $response = $this->readFingerprintReuseStatusLine(4096);
        $this->_clearOpensslErrors();
        if ($response === false) {
            $this->reuseEligible = false;
            $meta = stream_get_meta_data($this->_socket);

            return $this->throwError($meta['timed_out'] ? 'Connection timed out' : 'Failed to read from socket...!', null);
        }
        if (strlen($response) > 512 || preg_match('/\A[1-5][0-9]{2}(?: [\x09\x20-\x7e\x80-\xff]*)?\r\n\z/', $response) !== 1) {
            $this->reuseEligible = false;
        }
        $response = trim($response);
        $this->_logger?->debug('S: '.$response);
        $this->_currentStatusResponse = [(int) substr($response, 0, 3), substr($response, 4)];

        return $this->_currentStatusResponse[0];
    }

    protected function writeFingerprintReuseCommand(string $wire): int|false
    {
        return @fwrite($this->_socket, $wire);
    }

    protected function readFingerprintReuseStatusLine(int $length): string|false
    {
        return @fgets($this->_socket, $length);
    }

    private function closeOwnedSocket(): void
    {
        $socket = $this->_socket;
        try {
            $this->_resetProperties();
        } catch (Throwable) {
            // Cleanup cannot replace the current collection result or exception.
        } finally {
            $this->_socket = null;
            if (is_resource($socket)) {
                try {
                    @fclose($socket);
                } catch (Throwable) {
                    // Attempt every owned socket independently.
                }
            }
        }
    }
}
