<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\NNTP\FingerprintConnectionReuseNntpService;
use App\Services\NNTP\NNTPService;
use RuntimeException;

/** Finite Unix-pair scripts only; doConnect does not perform TCP, TLS or authentication. */
final class FingerprintCollectionReuseNntpHarness extends FingerprintConnectionReuseNntpService
{
    /** @var resource|null */
    private mixed $peer = null;

    /** @var list<self> */
    private array $alternates = [];

    /** @var list<array{compression: bool, alternate: bool}> */
    public array $connections = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var list<'short'|'zero'|'false'> */
    public array $writeFaults = [];

    public int $statusReads = 0;

    public int $quitCalls = 0;

    public int $alternateFactoryCalls = 0;

    public int $resetCalls = 0;

    public bool $connectionFails = false;

    public bool $eofAfterConnect = false;

    public bool $endResponseStream = false;

    public bool $throwOnReset = false;

    private string $transcript = '';

    public function __construct(private readonly string $responses, private readonly bool $alternateRole = false)
    {
        if (strlen($responses) > 4096) {
            throw new RuntimeException('Fixture responses exceed the preloaded socket budget.');
        }
        $this->_configServer = $this->_currentServer = 'primary.fixture.invalid';
        $this->_configAlternateServer = 'alternate.fixture.invalid';
        $this->_configPort = $this->_currentPort = 119;
        $this->_configAlternatePort = 563;
        $this->_configCompressedHeaders = false;
        $this->_socketTimeout = 1;
    }

    /** @param list<self> $alternates */
    public function queueAlternates(array $alternates): void
    {
        $this->alternates = $alternates;
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections[] = ['compression' => $compression, 'alternate' => $alternate];
        if ($alternate !== $this->alternateRole || $this->_socket !== null || is_resource($this->peer)) {
            throw new RuntimeException('Unexpected fixture admission or reconnect.');
        }
        if ($this->connectionFails) {
            return false;
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Cannot create the owned Unix socket pair.');
        }
        [$this->_socket, $this->peer] = $pair;
        try {
            if (! stream_set_blocking($this->peer, false) || ! stream_set_timeout($this->_socket, 1)) {
                throw new RuntimeException('Cannot configure the owned socket pair.');
            }
            if (fwrite($this->peer, $this->responses) !== strlen($this->responses)) {
                throw new RuntimeException('Short write while preloading fixture responses.');
            }
            if ($this->endResponseStream || $this->eofAfterConnect) {
                stream_socket_shutdown($this->peer, STREAM_SHUT_WR);
            }
            if ($this->eofAfterConnect) {
                if ($this->responses !== '') {
                    throw new RuntimeException('Immediate EOF fixture must have no response bytes.');
                }
                fread($this->_socket, 1);
            }
            $this->_usingAlternateServer = $alternate;
            $this->_currentServer = $alternate ? $this->_configAlternateServer : $this->_configServer;
            $this->_currentPort = $alternate ? $this->_configAlternatePort : $this->_configPort;
        } catch (\Throwable $exception) {
            $this->closeFixture();
            throw $exception;
        }

        return true;
    }

    protected function previewAlternateConnection(): NNTPService
    {
        $this->alternateFactoryCalls++;

        return array_shift($this->alternates)
            ?? throw new RuntimeException('Unexpected extra alternate client allocation.');
    }

    protected function writeFingerprintReuseCommand(string $wire): int|false
    {
        $this->writes[] = $wire;

        return match (array_shift($this->writeFaults)) {
            'short' => fwrite($this->_socket, substr($wire, 0, 3)),
            'zero' => 0,
            'false' => false,
            default => parent::writeFingerprintReuseCommand($wire),
        };
    }

    protected function readFingerprintReuseStatusLine(int $length): string|false
    {
        $this->statusReads++;

        return parent::readFingerprintReuseStatusLine($length);
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quitCalls++;

        return parent::doQuit($force);
    }

    protected function _resetProperties(): void
    {
        $this->resetCalls++;
        if ($this->throwOnReset) {
            throw new RuntimeException('Fixture reset failure.');
        }
        parent::_resetProperties();
    }

    public function observedBytes(): string
    {
        if (! is_resource($this->peer)) {
            return $this->transcript;
        }
        $bytes = stream_get_contents($this->peer);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read fixture command transcript.');
        }
        $this->transcript .= $bytes;

        return $this->transcript;
    }

    /** @return list<string> */
    public function observedCommands(): array
    {
        $bytes = $this->observedBytes();

        return $bytes === '' ? [] : explode("\r\n", substr($bytes, 0, -2));
    }

    /** @return resource|null */
    public function fixtureSocket(): mixed
    {
        return $this->_socket;
    }

    public function useSameHostForBothRoles(): void
    {
        $this->_configAlternateServer = $this->_configServer;
    }

    public function ownedResourceCount(): int
    {
        return (int) is_resource($this->_socket) + (int) is_resource($this->peer);
    }

    public function closeFixture(): void
    {
        foreach ([$this->_socket, $this->peer] as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        $this->_socket = null;
        $this->peer = null;
    }

    public function __destruct()
    {
        $this->closeFixture();
    }
}
