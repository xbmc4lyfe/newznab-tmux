<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\NNTP\NNTPService;
use App\Services\YencService;

/** Uses the production status parser, command writer, BODY reader and connection check. */
final class MappingArticleNntpHarness extends NNTPService
{
    /** @var resource */
    private mixed $peer;

    private string $transcript = '';

    public ?self $alternate = null;

    public int $quits = 0;

    public bool $connectionFailure = false;

    /** @param array<string, string> $responsesByCommand */
    public function __construct(string $wire, private readonly array $responsesByCommand = [])
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Could not create fixture socket pair.');
        }
        [$this->_socket, $this->peer] = $pair;
        socket_set_option(socket_import_stream($this->peer), SOL_SOCKET, SO_SNDBUF, 1_048_576);
        socket_set_option(socket_import_stream($this->_socket), SOL_SOCKET, SO_RCVBUF, 1_048_576);
        fwrite($this->peer, $wire);
        if ($this->responsesByCommand === []) {
            stream_socket_shutdown($this->peer, STREAM_SHUT_WR);
        }
        stream_set_blocking($this->peer, false);
        stream_set_timeout($this->_socket, 1);
        $this->_yencService = new YencService;
        $this->_currentServer = $this->_configServer = 'primary';
        $this->_configAlternateServer = 'alternate';
        $this->_currentGroup = '';
        $this->_configCompressedHeaders = false;
    }

    public function __destruct()
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        fclose($this->peer);
    }

    /** @return list<string> */
    public function sentCommands(): array
    {
        $this->transcript .= stream_get_contents($this->peer) ?: '';

        return $this->transcript === '' ? [] : explode("\r\n", rtrim($this->transcript, "\r\n"));
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return $this->connectionFailure ? $this->throwError('Fixture connection failed.') : true;
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quits++;
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;

        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        if ($this->responsesByCommand !== []) {
            $response = $this->responsesByCommand[$cmd] ?? "430 missing\r\n";
            if (fwrite($this->peer, $response) !== strlen($response)) {
                throw new \RuntimeException('Could not write the fixture response.');
            }
        }

        return parent::_sendCommand($cmd);
    }

    protected function previewAlternateConnection(): NNTPService
    {
        return $this->alternate ?? throw new \LogicException('Unexpected alternate connection.');
    }

    public function useAlternateAsCurrentConnection(): void
    {
        $this->_currentServer = $this->_configAlternateServer;
    }

    public static function body(string $data, string $name = 'archive.rar'): string
    {
        return self::rawBody((new YencService)->encode($data, $name));
    }

    public static function rawBody(string $body): string
    {
        return "222 0 <fixture@test> body follows\r\n".preg_replace('/^\./m', '..', $body)."\r\n.\r\n";
    }
}
