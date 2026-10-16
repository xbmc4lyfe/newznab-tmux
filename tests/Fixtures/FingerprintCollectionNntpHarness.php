<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\NNTP\NNTPService;
use RuntimeException;

/** Socket-pair transport only: production command writing and response parsing remain intact. */
final class FingerprintCollectionNntpHarness extends NNTPService
{
    /** @var resource|null */
    private mixed $client = null;

    /** @var resource|null */
    private mixed $peer = null;

    /** @var list<self> */
    private array $alternates = [];

    /** @var list<array{compression: bool, alternate: bool}> */
    public array $connections = [];

    public int $quitCalls = 0;

    public int $alternateFactoryCalls = 0;

    private string $transcript = '';

    public function __construct(string $responses, private readonly bool $alternateRole = false)
    {
        if (strlen($responses) > 4096) {
            throw new RuntimeException('Fixture responses exceed the preloaded socket budget.');
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot create the owned Unix socket pair.');
        }
        [$this->client, $this->peer] = $pair;
        try {
            // A full buffer must fail immediately, never block during fixture setup.
            if (! stream_set_blocking($this->peer, false)
                || ! stream_set_timeout($this->client, 1)) {
                throw new RuntimeException('Cannot configure the owned socket pair.');
            }
            if (fwrite($this->peer, $responses) !== strlen($responses)) {
                throw new RuntimeException('Short write while preloading fixture responses.');
            }
            $this->_socketTimeout = 1;
            $this->_configServer = 'primary.fixture.invalid';
            $this->_currentServer = $this->_configServer;
            $this->_configCompressedHeaders = false;
        } catch (\Throwable $exception) {
            $this->closeFixture();
            throw $exception;
        }
    }

    /** @param list<self> $alternates */
    public function queueAlternates(array $alternates): void
    {
        $this->alternates = $alternates;
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections[] = ['compression' => $compression, 'alternate' => $alternate];
        if ($alternate !== $this->alternateRole || ! is_resource($this->client)) {
            throw new RuntimeException('Unexpected provider role or closed fixture client.');
        }
        $this->_socket = $this->client;

        return true;
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return $this->isConnected() ? true : $this->doConnect(false, false);
    }

    protected function previewAlternateConnection(): NNTPService
    {
        $this->alternateFactoryCalls++;

        return array_shift($this->alternates)
            ?? throw new RuntimeException('Unexpected extra alternate client allocation.');
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quitCalls++;
        if (is_resource($this->client)) {
            fclose($this->client);
        }
        $this->client = null;
        $this->_socket = null;

        return true;
    }

    /** @return list<string> Actual commands observed on the peer, without CRLF. */
    public function observedCommands(): array
    {
        if (! is_resource($this->peer)) {
            throw new RuntimeException('Read the transcript before fixture teardown.');
        }
        $bytes = stream_get_contents($this->peer);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read fixture command transcript.');
        }
        $this->transcript .= $bytes;
        if ($this->transcript === '') {
            return [];
        }
        if (! str_ends_with($this->transcript, "\r\n")) {
            throw new RuntimeException('Truncated command on fixture peer.');
        }
        $commands = explode("\r\n", substr($this->transcript, 0, -2));
        foreach ($commands as $command) {
            if (strlen($command) + 2 > 512 || strpbrk($command, "\r\n") !== false) {
                throw new RuntimeException('Malformed or oversized command on fixture peer.');
            }
        }

        return $commands;
    }

    public function ownedResourceCount(): int
    {
        return (int) is_resource($this->client) + (int) is_resource($this->peer);
    }

    /** Raw fixture teardown deliberately does not increment production doQuit observations. */
    public function closeFixture(): void
    {
        foreach ([$this->client, $this->peer] as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        $this->client = null;
        $this->peer = null;
        $this->_socket = null;
    }
}
