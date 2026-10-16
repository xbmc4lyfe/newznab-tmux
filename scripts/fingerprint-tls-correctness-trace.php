<?php

declare(strict_types=1);

use App\Services\NNTP\FingerprintConnectionReuseNntpService;
use App\Services\NNTP\NNTPService;

/** Correctness instrumentation only: no socket references, probes, retries or payloads. */
final class FingerprintTlsCorrectnessTrace
{
    /** @var list<array<string, bool|int|float|string|null>> */
    private array $events = [];

    private int $eventBytes = 0;

    private int $nextClientId = 0;

    /** @var array{reason: string}|null */
    private ?array $failure = null;

    public function allocateClientId(): int
    {
        return ++$this->nextClientId;
    }

    public function fail(string $reason): void
    {
        $this->failure ??= ['reason' => $reason];
    }

    /** @param array<string, bool|int|string|null> $event */
    public function record(array $event): void
    {
        if ($this->failure !== null) {
            return;
        }

        $event = ['sequence' => count($this->events) + 1, 'monotonic_ns' => hrtime(true)] + $event;
        $encoded = json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE);
        // Reserve metadata space, including a possible sticky infrastructure failure.
        if ($encoded === false || count($this->events) >= 256 || $this->eventBytes + strlen($encoded) + 1 > 64_512) {
            $this->fail('Correctness trace exceeded its event or serialized byte bound.');

            return;
        }
        $this->events[] = $event;
        $this->eventBytes += strlen($encoded) + 1;
    }

    public function command(string $command): string
    {
        if (strlen($command) > 256) {
            $this->fail('Correctness command exceeded its 256 byte bound.');

            return '[oversized command omitted]';
        }

        return preg_match('/\AAUTHINFO\s+PASS\b/i', $command) === 1 ? 'AUTHINFO pass [redacted]' : $command;
    }

    /**
     * @return array{
     *     events: list<array<string, bool|int|float|string|null>>,
     *     serialized_bytes: int,
     *     max_events: int,
     *     max_serialized_bytes: int,
     *     max_command_bytes: int,
     *     infrastructure_failure: array{reason: string}|null
     * }
     */
    public function snapshot(): array
    {
        $trace = [
            'events' => $this->events, 'serialized_bytes' => 0,
            'max_events' => 256, 'max_serialized_bytes' => 65_536, 'max_command_bytes' => 256,
            'infrastructure_failure' => $this->failure,
        ];
        // Include the byte-count field itself in the compact serialized trace size.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $trace['serialized_bytes'] = strlen((string) json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return $trace;
    }
}

trait FingerprintTlsCorrectnessCalls
{
    private FingerprintTlsCorrectnessTrace $fingerprintTrace;

    private int $fingerprintClientId;

    private string $fingerprintClientRole;

    private int $fingerprintConnectAttempt = 0;

    public function __construct(FingerprintTlsCorrectnessTrace $trace, string $role = 'primary')
    {
        $this->fingerprintTrace = $trace;
        $this->fingerprintClientId = $trace->allocateClientId();
        $this->fingerprintClientRole = $role;
        $this->fingerprintTraceMarker('client_created');
        parent::__construct();
    }

    /** @param array<string, bool|int|string|null> $details */
    public function fingerprintTraceMarker(string $event, array $details = []): void
    {
        $this->fingerprintTrace->record([
            'event' => $event, 'client_id' => $this->fingerprintClientId,
            'client_role' => $this->fingerprintClientRole,
            'socket_id' => $this->fingerprintSocketId(),
        ] + $details);
    }

    private function fingerprintSocketId(): ?int
    {
        return is_resource($this->_socket) ? get_resource_id($this->_socket) : null;
    }

    /**
     * @param  callable(): mixed  $call
     * @param  array<string, bool|int|string|null>  $details
     */
    private function fingerprintTraceCall(string $event, callable $call, array $details = []): mixed
    {
        $this->fingerprintTraceMarker($event.'_enter', $details);
        try {
            $result = $call();
        } catch (Throwable $exception) {
            $this->fingerprintTraceMarker($event.'_throw', ['exception_type' => $exception::class]);
            throw $exception;
        }
        $this->fingerprintTraceMarker($event.'_return', $this->fingerprintResult($result));

        return $result;
    }

    /** @return array{result_type: string, result?: bool|int|null, result_code?: int} */
    private function fingerprintResult(mixed $result): array
    {
        $details = ['result_type' => get_debug_type($result)];
        if (is_bool($result) || is_int($result) || $result === null) {
            $details['result'] = $result;
        }
        if (is_int($result)) {
            $details['result_code'] = $result;
        }

        return $details;
    }

    protected function previewAlternateConnection(): NNTPService
    {
        return new self($this->fingerprintTrace, 'alternate');
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return $this->fingerprintTraceCall('do_connect', fn (): mixed => parent::doConnect($compression, $alternate), [
            'compression' => $compression, 'alternate' => $alternate,
        ]);
    }

    public function connect(?string $host = null, mixed $encryption = null, ?int $port = null, ?int $timeout = 15, int $socketTimeout = 120): mixed
    {
        return $this->fingerprintTraceCall('connect', fn (): mixed => parent::connect($host, $encryption, $port, $timeout, $socketTimeout), [
            'host' => $host, 'port' => $port, 'encryption' => $encryption,
            'timeout' => $timeout, 'socket_timeout' => $socketTimeout,
            'connect_attempt' => ++$this->fingerprintConnectAttempt,
        ]);
    }

    protected function _sendCommand(string $cmd): mixed
    {
        $command = $this->fingerprintTrace->command($cmd);
        $this->fingerprintTraceMarker('command_attempt', ['command' => $command]);
        try {
            $result = parent::_sendCommand($cmd);
        } catch (Throwable $exception) {
            $this->fingerprintTraceMarker('command_throw', ['command' => $command, 'exception_type' => $exception::class]);
            throw $exception;
        }
        $this->fingerprintTraceMarker('command_return', ['command' => $command] + $this->fingerprintResult($result));

        return $result;
    }

    public function _isConnected(): bool
    {
        $socketId = $this->fingerprintSocketId();
        try {
            // Exactly the original virtual call: observing must not consume an extra EOF probe.
            $result = parent::_isConnected();
        } catch (Throwable $exception) {
            $this->fingerprintTraceMarker('is_connected_throw', ['socket_id_before' => $socketId, 'exception_type' => $exception::class]);
            throw $exception;
        }
        $this->fingerprintTraceMarker('is_connected_result', ['socket_id_before' => $socketId, 'result' => $result]);

        return $result;
    }

    public function doQuit(bool $force = false): mixed
    {
        // A logical call only: base reset semantics do not prove a QUIT command was sent.
        return $this->fingerprintTraceCall('quit', fn (): mixed => parent::doQuit($force), ['force' => $force]);
    }
}

final class FingerprintTlsCorrectnessBaselineNntp extends NNTPService
{
    use FingerprintTlsCorrectnessCalls;
}

final class FingerprintTlsCorrectnessReuseNntp extends FingerprintConnectionReuseNntpService
{
    use FingerprintTlsCorrectnessCalls;

    /** @param callable(): mixed $collect */
    public function withFingerprintConnectionReuse(callable $collect): mixed
    {
        return $this->fingerprintTraceCall('scope', fn (): mixed => parent::withFingerprintConnectionReuse($collect));
    }

    public function releaseFingerprintAlternateTransport(): void
    {
        $this->fingerprintTraceCall('alternate_release', function (): void {
            parent::releaseFingerprintAlternateTransport();
        });
    }
}
