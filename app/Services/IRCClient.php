<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Basic IRC client for fetching IRCScraper.
 *
 *
 * Class IRCClient
 */
class IRCClient
{
    /**
     * Longest wait, in seconds, on the first attempt when the host's other addresses can still be tried.
     */
    private const FIRST_ATTEMPT_TIMEOUT = 10.0;

    /**
     * Hostname IRC server used when connecting.
     */
    protected string $_remote_host = '';

    /**
     * Port number IRC server.
     */
    protected int $_remote_port = 6667;

    /**
     * Socket transport type for the IRC server.
     */
    protected string $_remote_transport = 'tcp';

    /**
     * Hostname the IRC server sent us back.
     */
    protected string $_remote_host_received = '';

    /**
     * String used when creating the stream socket.
     */
    protected string $_remote_socket_string = '';

    /**
     * Are we using tls/ssl?
     */
    protected bool $_remote_tls = false;

    /**
     * Overrides TLS host name verification for this connection; null keeps the nntmux_ssl setting.
     */
    protected ?bool $_tlsVerifyPeerName = null;

    /**
     * Time in seconds to timeout on connect.
     */
    protected int $_remote_connection_timeout = 30;

    /**
     * Time in seconds before we timeout when sending/receiving a command.
     */
    protected int $_socket_timeout = 180;

    /**
     * How many times to retry when connecting to IRC.
     */
    protected int $_reconnectRetries = 3;

    /**
     * Seconds to delay when reconnecting fails.
     */
    protected int $_reconnectDelay = 5;

    /**
     * Stream socket client.
     *
     * @var resource|null
     */
    protected $_socket = null;

    /**
     * Buffer contents.
     */
    protected ?string $_buffer = null;

    /**
     * Incomplete frame retained while the same connection remains alive.
     */
    protected string $_partialReadBuffer = '';

    /**
     * Ignore an oversized frame through its terminating LF, across reads.
     */
    protected bool $_discardReadFrame = false;

    /**
     * When someone types something into a channel, buffer it.
     * array(
     *     'nickname' => string(The nick name of the person who posted.),
     *     'channel'  => string(The channel name.),
     *     'message'  => string(The message the person posted.)
     * );.
     *
     * @note   Used with the processChannelMessages() function.
     *
     * @var array<string, mixed>
     */
    protected array $_channelData = [];

    /**
     * Nickname when we log in.
     */
    protected string $_nickName;

    /**
     * Username when we log in.
     */
    protected string $_userName;

    /**
     * "Real" name when we log in.
     */
    protected string $_realName;

    /**
     * Password when we log in.
     */
    protected ?string $_password = null;

    /**
     * List of channels and passwords to join.
     *
     * @var array<string, mixed>
     */
    protected array $_channels;

    /**
     * Last time we received a ping or sent a ping to the server.
     */
    protected int $_lastPing;

    /**
     * How many times we've tried to reconnect to IRC.
     */
    protected int $_currentRetries = 0;

    /**
     * Turns on or off debugging.
     */
    protected bool $_debug = true;

    /**
     * Are we already logged in to IRC?
     */
    protected bool $_alreadyLoggedIn = false;

    /**
     * Disconnect from IRC.
     */
    public function __destruct()
    {
        $this->quit();
    }

    /**
     * Time before giving up when trying to read or write to the IRC server.
     * The default is fine, it will ping the server if the server does not ping us
     * within this time to keep the connection alive.
     *
     * @param  int  $timeout  Seconds.
     */
    public function setSocketTimeout(int $timeout): void
    {
        $this->_socket_timeout = $timeout;
    }

    /**
     * Amount of time to wait before giving up when connecting.
     *
     * @param  int  $timeout  Seconds.
     */
    public function setConnectionTimeout(int $timeout): void
    {
        $this->_remote_connection_timeout = $timeout;
    }

    /**
     * Amount of times to retry before giving up when connecting.
     */
    public function setConnectionRetries(int $retries): void
    {
        $this->_reconnectRetries = $retries;
    }

    /**
     * Amount of time to wait between failed connects.
     *
     * @param  int  $delay  Seconds.
     */
    public function setReConnectDelay(int $delay): void
    {
        $this->_reconnectDelay = $delay;
    }

    /**
     * Connect to a IRC server.
     *
     * @param  string  $hostname  Host name of the IRC server (can be a IP or a name).
     * @param  int  $port  Port number of the IRC server.
     * @param  bool  $tls  Use encryption for the socket transport? (make sure the port is right).
     */
    public function connect(string $hostname, int $port = 6667, bool $tls = false): bool
    {
        if ($this->_stopRequested()) {
            return false;
        }

        $this->_alreadyLoggedIn = false;
        $transport = ($tls === true ? 'tls' : 'tcp');

        $socket_string = $transport.'://'.$hostname.':'.$port;
        if ($socket_string !== $this->_remote_socket_string || ! $this->_connected()) {
            if ($hostname === '') {
                echo 'ERROR: IRC host name must not be empty!'.PHP_EOL;

                return false;
            }

            $this->_remote_host = $hostname;
            $this->_remote_port = $port;
            $this->_remote_transport = $transport;
            $this->_remote_tls = $tls;
            $this->_remote_socket_string = $socket_string;

            // Try to connect until we run out of retries.
            while ($this->_reconnectRetries >= $this->_currentRetries++) {
                if ($this->_stopRequested()) {
                    break;
                }
                $this->_initiateStream();
                if ($this->_connected()) {
                    break;
                }

                if (! $this->_waitForReconnect()) {
                    break;
                }
            }
        } else {
            $this->_alreadyLoggedIn = true;
        }

        // Set last ping time to now.
        $this->_lastPing = time();
        // Reset retries counter.
        $this->_currentRetries = 0;

        return ! $this->_stopRequested() && $this->_connected();
    }

    /** @phpstan-impure */
    private function _stopRequested(): bool
    {
        return app(ProcessingRuntimeStateRepository::class)->stopRequested();
    }

    private function _waitForReconnect(): bool
    {
        $deadline = hrtime(true) + $this->_reconnectDelay * 1_000_000_000;
        while (hrtime(true) < $deadline) {
            if ($this->_stopRequested()) {
                return false;
            }

            usleep((int) min(100_000, max(0, ($deadline - hrtime(true)) / 1000)));
        }

        return ! $this->_stopRequested();
    }

    /**
     * Log in to a IRC server.
     *
     * @param  string  $nickName  The nick name - visible in the channel.
     * @param  string  $userName  The user name - visible in the host name.
     * @param  string  $realName  The real name - visible in the WhoIs.
     * @param  string|null  $password  The password - some servers require a password.
     */
    public function login(string $nickName, string $userName, string $realName, ?string $password = null): bool
    {
        if ($this->_stopRequested()) {
            return false;
        }

        if (! $this->_connected()) {
            echo 'ERROR: You must connect to IRC first!'.PHP_EOL;

            return false;
        }

        if (empty($nickName) || empty($userName) || empty($realName)) {
            echo 'ERROR: nick/user/real name must not be empty!'.PHP_EOL;

            return false;
        }

        $this->_nickName = $nickName;
        $this->_userName = $userName;
        $this->_realName = $realName;
        $this->_password = $password;

        // Send PASS only if provided to avoid null-to-string deprecation and follow IRC spec.
        if ($password !== null && $password !== '' && ! $this->_writeSocket('PASS '.$password)) {
            return false;
        }

        if (! $this->_writeSocket('NICK '.$nickName)) {
            return false;
        }

        if (! $this->_writeSocket('USER '.$userName.' 0 * :'.$realName)) {
            return false;
        }

        // Loop over the socket buffer until we find "001".
        while (true) {
            if ($this->_stopRequested()) {
                return false;
            }
            $this->_readSocket();
            if ($this->_stopRequested()) {
                return false;
            }

            // We got pinged, reply with a pong.
            if (preg_match('/^PING\s*:(.+?)$/', (string) $this->_buffer, $hits)) {
                $this->_pong($hits[1]);
            } elseif (preg_match('/^:(.*?)\s+(\d+).*?(:.+?)?$/', (string) $this->_buffer, $hits)) {
                // We found 001, which means we are logged in.
                if ((int) $hits[2] === 1) {
                    $this->_remote_host_received = $hits[1];
                    break;

                    // We got 464, which means we need to send a password.
                }

                if ((int) $hits[2] === 464) {
                    // Before the lower check, set the password : username:password
                    $tempPass = $userName.':'.$password;

                    // Check if the user has his password in this format: username/server:password
                    if (is_string($password) && preg_match('/^.+?\/.+?:.+?$/', $password)) {
                        $tempPass = $password;
                    }

                    if ($password !== null && $password !== '' && ! $this->_writeSocket('PASS '.$tempPass)) {
                        return false;
                    }

                    if (isset($hits[3]) && stripos($hits[3], 'invalid password') !== false) {
                        echo 'Invalid password or username for ('.$this->_remote_host.').';

                        return false;
                    }
                }
                // ERROR: Closing Link: kevin123[100.100.100.100] (This server is full.)
            } elseif (preg_match('/^ERROR\s*:/', (string) $this->_buffer)) {
                echo $this->_buffer.PHP_EOL;

                return false;
            }
        }

        return true;
    }

    public function quit(?string $message = null): bool
    {
        if ($this->_connected()) {
            $this->_writeSocket('QUIT'.($message === null ? '' : ' :'.$message));
        }
        $this->_closeStream();

        return $this->_connected();
    }

    /**
     * Read the incoming buffer in a loop.
     */
    public function readIncoming(): void
    {
        while (true) {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                $this->quit('Processing drained');

                return;
            }
            $this->_readSocket();

            $frame = $this->parseInboundLine($this->_buffer);
            if ($frame !== null) {
                if ($frame[0] === 'P') {
                    if ($frame[1] === $this->_remote_host_received) {
                        $this->_pong($frame[1]);
                    }
                } else {
                    $this->_channelData = [
                        'nickname' => $frame[1],
                        'channel' => $frame[2],
                        'message' => $frame[3],
                    ];
                    $this->processChannelMessages();
                }
            }

            // Ping the server if it has not sent us a ping in a while.
            if ((time() - $this->_lastPing) > ($this->_socket_timeout / 2)) {
                $this->_ping($this->_remote_host_received);
            }

            // Small sleep to prevent CPU spinning when no data is available
            // Only sleep if buffer was empty
            if (empty($this->_buffer)) {
                usleep(100000); // 100ms
            }
        }
    }

    /**
     * Join a channel or multiple channels.
     *
     * @param  array<string, mixed>  $channels  Array of channels with their passwords (null if the channel doesn't need a password).
     *                                          array( '#exampleChannel' => 'thePassword', '#exampleChan2' => null );
     */
    public function joinChannels(array $channels = []): bool
    {
        $this->_channels = $channels;

        if (! $this->_connected()) {
            echo 'ERROR: You must connect to IRC first!'.PHP_EOL;

            return false;
        }

        if (! empty($channels)) {
            foreach ($channels as $channel => $password) {
                $this->_joinChannel($channel, $password ?? '');
            }
        }

        return true;
    }

    /**
     * Implementation.
     * Extended classes will use this function to parse the messages in the channel using $this->_channelData.
     */
    protected function processChannelMessages(): void {}

    /**
     * Join a channel.
     */
    protected function _joinChannel(string $channel, string $password): void
    {
        $this->_writeSocket('JOIN '.$channel.(empty($password) ? '' : ' '.$password));
    }

    /**
     * Send PONG to a host.
     */
    protected function _pong(string $host): void
    {
        if (! $this->_writeSocket('PONG '.$host)) {
            $this->_reconnect();
        }

        // If we got a ping from the IRC server, set the last ping time to now.
        if ($host === $this->_remote_host_received) {
            $this->_lastPing = time();
        }
    }

    /**
     * Send PING to a host.
     */
    protected function _ping(string $host): void
    {
        $pong = $this->_writeSocket('PING '.$host);

        // Check if there's a connection error.
        if (
            $pong === false
            || (
                (time() - $this->_lastPing) > ($this->_socket_timeout / 2)
                && ! str_starts_with((string) $this->_buffer, 'PONG')
            )
        ) {
            $this->_reconnect();
        }

        // If sent a ping from the IRC server, set the last ping time to now.
        if ($host === $this->_remote_host_received) {
            $this->_lastPing = time();
        }
    }

    /**
     * Attempt to reconnect to IRC.
     */
    protected function _reconnect(): void
    {
        if ($this->_stopRequested()) {
            return;
        }

        if (! $this->connect($this->_remote_host, $this->_remote_port, $this->_remote_tls)) {
            if ($this->_stopRequested()) {
                return;
            }
            exit('FATAL: Could not reconnect to ('.$this->_remote_host.') after ('.$this->_reconnectRetries.') tries.'.PHP_EOL);
        }

        if (! $this->_alreadyLoggedIn) {
            if (! $this->login($this->_nickName, $this->_userName, $this->_realName, $this->_password)) {
                if ($this->_stopRequested()) {
                    return;
                }
                exit('FATAL: Could not log in to ('.$this->_remote_host.')!'.PHP_EOL);
            }

            if (! $this->_stopRequested()) {
                $this->joinChannels($this->_channels);
            }
        }
    }

    /**
     * Read response from the IRC server.
     */
    protected function _readSocket(): void
    {
        $buffer = $this->_partialReadBuffer;
        $this->_partialReadBuffer = '';
        $this->_buffer = '';
        do {
            if ($this->_stopRequested()) {
                break;
            }
            // Use a shorter timeout for reading to be more responsive
            stream_set_timeout($this->_socket, 5);
            $line = fgets($this->_socket, 1024);

            // Check if the connection is still alive and handle timeout/errors
            if ($line === false) {
                $meta = stream_get_meta_data($this->_socket);

                // If stream timed out, it's OK - just no data available
                if ($meta['timed_out']) {
                    // Check if the connection is still valid
                    if (! $this->_connected()) {
                        echo 'Connection lost (timeout), attempting to reconnect...'.PHP_EOL;
                        $buffer = '';
                        $this->_discardReadFrame = false;
                        $this->_reconnect();
                    }
                    break;
                }

                // If EOF or other error, the connection is dead
                if ($meta['eof'] || ! $this->_connected()) {
                    echo 'Connection lost, attempting to reconnect...'.PHP_EOL;
                    $buffer = '';
                    $this->_discardReadFrame = false;
                    $this->_reconnect();
                    break;
                }

                // No data available but connection is fine
                break;
            }
            $complete = str_ends_with($line, "\n");
            if (! $this->_discardReadFrame) {
                $buffer .= $line;
                if ($this->_readFrameTooLong($buffer, $complete)) {
                    $buffer = '';
                    $this->_discardReadFrame = true;
                }
            }
            if ($complete) {
                $this->_discardReadFrame = false;
                break;
            }
        } while (true);

        if (str_ends_with($buffer, "\n")) {
            $this->_buffer = substr($buffer, 0, str_ends_with($buffer, "\r\n") ? -2 : -1);
        } else {
            $this->_partialReadBuffer = $buffer;
        }

        if ($this->_debug && $this->_buffer !== '') {
            echo 'RECV '.$this->_buffer.PHP_EOL;
        }
    }

    /**
     * Tolerant total frame ceiling: 8703 bytes including CRLF.
     * LF-only frames retain the same 8701-byte payload allowance.
     */
    protected function _readFrameTooLong(string $buffer, bool $complete): bool
    {
        $bytes = \strlen($buffer);
        if (! $complete) {
            // Reserve the final LF; a partial frame may already include its CR.
            return $bytes > 8702;
        }

        return $bytes + (str_ends_with($buffer, "\r\n") ? 0 : 1) > 8703;
    }

    /**
     * Send a command to the IRC server.
     */
    protected function _writeSocket(string $command): bool
    {
        $command .= "\r\n";
        for ($written = 0, $writtenMax = \strlen($command); $written < $writtenMax; $written += $fWrite) {
            stream_set_timeout($this->_socket, $this->_socket_timeout);
            $fWrite = $this->_writeSocketChar(substr($command, $written));

            // http://www.php.net/manual/en/function.fwrite.php#96951 | fwrite can return 0 causing an infinite loop.
            if ($fWrite === false || $fWrite <= 0) {
                // If it failed, try a second time.
                $fWrite = $this->_writeSocketChar(substr($command, $written));
                if ($fWrite === false || $fWrite <= 0) {
                    echo 'ERROR: Could no write to socket! (the IRC server might have closed the connection)'.PHP_EOL;

                    return false;
                }
            }
        }

        if ($this->_debug) {
            echo 'SEND :'.$command;
        }

        return true;
    }

    /**
     * Write a single character to the socket.
     *
     * @param  string  $character(char)  $character  A single character.
     * @return int|bool Number of bytes written or false.
     */
    protected function _writeSocketChar(string $character): bool|int
    {
        return @fwrite($this->_socket, $character);
    }

    /**
     * Initiate stream socket to IRC server.
     */
    protected function _initiateStream(): void
    {
        $this->_closeStream();

        // A round-robin host (irc.efnet.org) can list a dead server first, and PHP only tries one address.
        // Try the rest of the host's addresses before giving up. One budget, equal to the connection timeout,
        // covers the host name attempt and every fallback, so extra addresses never multiply the maximum wait.
        $canFallBack = filter_var($this->_remote_host, FILTER_VALIDATE_IP) === false;
        $deadline = microtime(true) + $this->_remote_connection_timeout;
        $socket = $this->_openSocket(
            $this->_remote_socket_string,
            null,
            $error_number,
            $error_string,
            $canFallBack ? min($this->_remote_connection_timeout / 3, self::FIRST_ATTEMPT_TIMEOUT) : null
        );

        if ($socket === false && $canFallBack && ! $this->_stopRequested()) {
            $addresses = $this->_resolveAddresses($this->_remote_host);
            foreach ($addresses as $index => $address) {
                if ($this->_stopRequested()) {
                    break;
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                // Share what is left evenly, so every address gets an attempt.
                $share = $remaining / (\count($addresses) - $index);
                $literal = str_contains($address, ':') ? '['.$address.']' : $address;
                $socket = $this->_openSocket($this->_remote_transport.'://'.$literal.':'.$this->_remote_port, $this->_remote_host, $error_number, $error_string, $share);
                if ($socket !== false) {
                    break;
                }
            }
        }

        if ($socket === false) {
            $protocol = $this->_remote_tls ? 'TLS/SSL' : 'TCP';
            echo "ERROR: Failed to connect to IRC server via {$protocol}: {$error_string} ({$error_number})".PHP_EOL;
        } else {
            $this->_socket = $socket;
            // Set blocking mode with timeout for proper connection handling
            stream_set_blocking($this->_socket, true);
            stream_set_timeout($this->_socket, $this->_socket_timeout);
        }
    }

    /**
     * All IPv4 and IPv6 addresses of a host, in random order.
     *
     * @return list<string>
     */
    protected function _resolveAddresses(string $host): array
    {
        // The system resolver first, so /etc/hosts and other name services count, as they do for the first attempt.
        $addresses = gethostbynamel($host) ?: [];
        // Query each type on its own: a failing AAAA lookup would make a combined query return nothing at all.
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (\is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }
        $addresses = array_values(array_unique($addresses));
        shuffle($addresses);

        return $addresses;
    }

    /**
     * Open one stream socket.
     *
     * @param  string|null  $peerName  Host name to verify the TLS certificate against when connecting by IP address.
     * @return resource|false
     */
    protected function _openSocket(string $socketString, ?string $peerName, ?int &$errorNumber, ?string &$errorString, ?float $timeout = null)
    {
        $context = null;
        if ($this->_remote_tls) {
            $options = streamSslContextOptions();
            foreach (array_keys($options) as $wrapper) {
                if ($this->_tlsVerifyPeerName !== null) {
                    $options[$wrapper]['verify_peer_name'] = $this->_tlsVerifyPeerName;
                }
                if ($peerName !== null) {
                    $options[$wrapper]['peer_name'] = $peerName;
                }
            }
            $context = stream_context_create($options);
        }

        return @stream_socket_client($socketString, $errorNumber, $errorString, $timeout ?? $this->_remote_connection_timeout, STREAM_CLIENT_CONNECT, $context);
    }

    /**
     * Close the socket.
     */
    protected function _closeStream(): void
    {
        $this->_partialReadBuffer = '';
        $this->_discardReadFrame = false;
        if ($this->_socket !== null) {
            if (\is_resource($this->_socket)) {
                @fclose($this->_socket);
            }
            $this->_socket = null;
        }
    }

    /**
     * Check if we are connected to the IRC server.
     */
    protected function _connected(): bool
    {
        return \is_resource($this->_socket) && ! feof($this->_socket);
    }

    /** @return list<string>|null P + payload, or M + nickname, channel, message. */
    protected function parseInboundLine(string $line): ?array
    {
        static $native = null;
        static $buffer = null;
        static $capacity = 0;
        $native ??= RustPipelineLibrary::resolveDomain('irc_pre_announce',
            'ssize_t nntmux_irc_parse_inbound_line(const char *input, size_t input_len, char *out_buf, size_t out_cap);') ?? false;
        if ($native instanceof FFI) {
            $length = strlen($line);
            if ($buffer === null || $capacity < $length + 4) {
                $buffer = RustPipelineLibrary::obtainScratch($native, $length + 4, $capacity);
            }
            if ($buffer !== null) {
                // @phpstan-ignore method.notFound
                $written = $native->nntmux_irc_parse_inbound_line($line, $length, $buffer, $capacity);
                if ($written === 0) {
                    return null;
                }
                if ($written > 0) {
                    return explode("\0", FFI::string($buffer, $written));
                }
            }
        }

        return $this->parseInboundLinePhp($line);
    }

    /** @return list<string>|null */
    protected function parseInboundLinePhp(string $line): ?array
    {
        return self::w441ParseInboundLinePhp($line, $this->_stripControlCharactersPhp(...));
    }

    /**
     * Shared PHP grammar for parseInboundLinePhp() and the Wave 44.1 summary.
     *
     * @param  callable(string): string  $strip
     * @return list<string>|null
     */
    private static function w441ParseInboundLinePhp(string $line, callable $strip): ?array
    {
        if (preg_match('/^PING\s*:(.+?)$/', $line, $hits)) {
            return ['P', $hits[1]];
        }
        if (preg_match(
            '/^:(?P<nickname>.+?)!.+?\s+PRIVMSG\s+(?P<channel>#.+?)\s+:\s*(?P<message>.+?)\s*$/',
            $strip($line),
            $hits
        )) {
            return ['M', $hits['nickname'], $hits['channel'], $hits['message']];
        }

        return null;
    }

    /**
     * Strip with reusable native scratch, retaining PHP when FFI is unavailable.
     */
    protected function _stripControlCharacters(string $text): string
    {
        $length = strlen($text);
        if ($length === 0 || ($text[0] !== "\x03" && strcspn($text, "\x02\x03\x04\x0f\x11\x16\x1d\x1e\x1f") === $length)) {
            return $text;
        }
        static $native = null;
        static $buffer = null;
        static $capacity = 0;
        $native ??= RustPipelineLibrary::resolveDomain('irc_pre_announce',
            'ssize_t nntmux_irc_strip_control_chars(const char *input, size_t input_len, char *out_buf, size_t out_cap);') ?? false;
        if ($native instanceof FFI) {
            if ($buffer === null || $capacity < $length) {
                $buffer = RustPipelineLibrary::obtainScratch($native, max(1, $length), $capacity);
            }
            if ($buffer !== null) {
                // @phpstan-ignore method.notFound
                $written = $native->nntmux_irc_strip_control_chars($text, $length, $buffer, $capacity);
                if ($written >= 0) {
                    return $written === 0 ? '' : FFI::string($buffer, $written);
                }
            }
        }

        return $this->_stripControlCharactersPhp($text);
    }

    /**
     * Strips control characters from a IRC message.
     */
    protected function _stripControlCharactersPhp(string $text): string
    {
        return self::w441StripControlCharactersPhp($text);
    }

    private static function w441StripControlCharactersPhp(string $text): string
    {
        $result = preg_replace(
            [
                '/\x03\d{1,2}(?:,\d{1,2})?/', // Color code with foreground and optional background
                '/\x03/', // Color code without parameters (reset)
                '/\x04[0-9A-Fa-f]{6}(?:,[0-9A-Fa-f]{6})?/', // Hex foreground and optional background
                '/\x04/', // Hex color reset
                '/\x02/', // Bold
                '/\x0F/', // Reset/Normal
                '/\x16/', // Reverse/Italic
                '/\x1F/', // Underline
                '/\x1D/', // Italic
                '/\x1E/', // Strikethrough
                '/\x11/', // Monospace
            ],
            '',
            $text
        );

        return is_string($result) ? $result : $text;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all nine runs (min 2.32x; see the W44 parity test). */
    public const bool W441_RUST_FFI_DEFAULT = true;

    public const string W441_FFI_CDEF = <<<'C'
        ssize_t nntmux_w441_irc_client_line_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W441_SUMMARY_KEYS = ['total_lines', 'empty_line_count', 'ping_count', 'privmsg_count', 'control_char_count', 'oversized_line_count'];

    private static ?bool $w441UseRustFfi = null;

    private static FFI|false|null $w441Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW441RustFfi(?bool $enable = true): void
    {
        self::$w441UseRustFfi = $enable;
    }

    public static function usesW441RustFfi(): bool
    {
        return self::$w441UseRustFfi ?? self::W441_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW441RustFfi(): void
    {
        self::$w441UseRustFfi = null;
        self::$w441Ffi = null;
    }

    /**
     * Summarizes received lines (CRLF already removed) with parseInboundLinePhp(): PING and PRIVMSG
     * lines, lines holding a control code _stripControlCharactersPhp() removes, and lines over the
     * 8701-byte _readFrameTooLong() payload allowance.
     * Entries come from strings, integers, or the first non-null line, message array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeInboundLineBatch(array $rows): array
    {
        return self::usesW441RustFfi() ? self::summarizeInboundLineBatchNative($rows) : self::summarizeInboundLineBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable, or a line holds NUL.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeInboundLineBatchNative(array $rows): array
    {
        $values = XrefService::w441Invoke(self::$w441Ffi, self::W441_FFI_CDEF, 'nntmux_w441_irc_client_line_batch', $rows, ['line', 'message'], \count(self::W441_SUMMARY_KEYS));

        return $values === null ? self::summarizeInboundLineBatchPhp($rows) : array_combine(self::W441_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeInboundLineBatchPhp(array $rows): array
    {
        $strip = self::w441StripControlCharactersPhp(...);
        $summary = array_fill_keys(self::W441_SUMMARY_KEYS, 0);
        foreach (XrefService::w441Entries($rows, ['line', 'message']) as $entry) {
            $summary['total_lines']++;
            if ($entry === '') {
                $summary['empty_line_count']++;

                continue;
            }
            // Strip once: the PRIVMSG match and the control-code count share the result.
            $stripped = $strip($entry);
            $kind = self::w441ParseInboundLinePhp($entry, static fn (): string => $stripped)[0] ?? null;
            $summary['ping_count'] += (int) ($kind === 'P');
            $summary['privmsg_count'] += (int) ($kind === 'M');
            $summary['control_char_count'] += (int) ($stripped !== $entry);
            $summary['oversized_line_count'] += (int) (\strlen($entry) > 8701);
        }

        return $summary;
    }

    // ========================================================================
    // WAVE 63.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.47x, p99 6.60x; uncommon mean 2.19x, p99 2.00x); see RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_IRC_CLIENT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w631_irc_client_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w63IrcClientPlanBatch(). */
    public const int W63_IRC_CLIENT_TRAILER = 23;

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /** Forces the IRCClient Wave 63 Rust path on until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Forces the IRCClient Wave 63 PHP path until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Runs $callback with the IRCClient Wave 63 Rust path forced on or off, then restores the previous setting.
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
     * Wave 63.1 IRC wire-line plans. Each item is `seconds|line`: a receive time (1-15 digits; empty is none, other
     * text sets flag 32) and the raw line, whose trailing CRLF (flag 1) or LF (flag 2) is dropped as _readSocket()
     * does. A `:prefix` is 1 server, 2 `nick!user@host`, or 3 malformed (empty, no command, or `!` without `@`).
     * The command is indexed 1 three-digit numeric, 2-14 PING PONG PRIVMSG NOTICE JOIN PART QUIT KICK NICK MODE
     * TOPIC ERROR CAP (case-insensitive; flag 512 lowercase), 15 other, 0 none; middle parameters are counted and a
     * `:` parameter is the trailing text. A first parameter starting with `#` or `&` is a channel, invalid (flag
     * 256) over 50 bytes or holding BEL or a comma. Times feed a 10-second rate window over the batch (flag 64 when
     * time goes backwards and the window restarts, flag 128 above 5 lines). Other flags: 4 mIRC control codes, 8
     * over the 8701-byte payload allowance, 16 non-ASCII. Class: 0 empty, 1 keepalive, 2 channel message, 3
     * private message, 4 numeric, 5 membership or mode command, 6 error or malformed, 7 unknown command. Per item
     * `class | command << 3 | prefix << 7 | flags << 9 | min(params, 15) << 19 | trailing << 23 | numeric << 24 |
     * min(window, 63) << 34 | min(controls, 63) << 40 | min(length, 16383) << 46 | needsPong << 60`; then 8 class
     * counts, 10 flag counts, the PONG replies due, the largest window, the control-code sum, the distinct valid
     * channels and the lines with trailing text.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcClientPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63IrcClientPlanBatchNative($items) : self::w63IrcClientPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcClientPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_IRC_CLIENT_TRAILER) ?? self::w63IrcClientPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63IrcClientPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W63_IRC_CLIENT_TRAILER, 0);
        $window = [];
        $head = 0;
        $previous = null;
        $channels = [];
        foreach ($items as $item) {
            [$rawTime, $rawLine] = explode('|', (string) $item, 2) + ['', ''];
            $flags = 0;
            $time = trim($rawTime);
            $t = null;
            if ($time !== '') {
                $length = \strlen($time);
                if ($length <= 15 && strspn($time, '0123456789') === $length) {
                    $t = (int) $time;
                } else {
                    $flags |= 32;
                }
            }
            if (str_ends_with($rawLine, "\r\n")) {
                $flags |= 1;
                $s = substr($rawLine, 0, -2);
            } elseif (str_ends_with($rawLine, "\n")) {
                $flags |= 2;
                $s = substr($rawLine, 0, -1);
            } else {
                $s = $rawLine;
            }
            $len = \strlen($s);
            $at = 0;
            $prefixKind = 0;
            if ($len > 0 && $s[0] === ':') {
                $end = strpos($s, ' ');
                $end = $end === false ? $len : $end;
                $prefix = substr($s, 1, $end - 1);
                if ($prefix === '' || $end === $len) {
                    $prefixKind = 3;
                } else {
                    $bang = strpos($prefix, '!');
                    $prefixKind = $bang === false ? 1 : (str_contains(substr($prefix, $bang + 1), '@') ? 2 : 3);
                }
                $at = $end;
            }
            $at += strspn($s, ' ', $at);
            $end = strpos($s, ' ', $at);
            $end = $end === false ? $len : $end;
            $command = substr($s, $at, $end - $at);
            $at = $end;
            $params = 0;
            $first = $trailing = null;
            while (true) {
                $at += strspn($s, ' ', $at);
                if ($at >= $len) {
                    break;
                }
                if ($s[$at] === ':') {
                    $trailing = substr($s, $at + 1);
                    break;
                }
                $end = strpos($s, ' ', $at);
                $end = $end === false ? $len : $end;
                $params++;
                $first ??= substr($s, $at, $end - $at);
                $at = $end;
            }
            $numeric = 0;
            if ($command === '') {
                $commandIdx = 0;
            } elseif (\strlen($command) === 3 && strspn($command, '0123456789') === 3) {
                $numeric = (int) $command;
                $commandIdx = 1;
            } else {
                $flags |= strpbrk($command, 'abcdefghijklmnopqrstuvwxyz') !== false ? 512 : 0;
                $commandIdx = self::W63_IRC_COMMANDS[strtoupper($command)] ?? 15;
            }
            $channel = $first !== null && ($first[0] === '#' || $first[0] === '&') ? $first : null;
            if ($channel !== null) {
                if (\strlen($channel) > 50 || strpbrk($channel, "\x07,") !== false) {
                    $flags |= 256;
                } else {
                    $channels[strtolower($channel)] = true;
                }
            }
            $controls = $len - \strlen(str_replace(self::W63_IRC_CONTROLS, '', $s));
            $flags |= ($controls > 0 ? 4 : 0) | ($len > 8701 ? 8 : 0) | (preg_match('/[\x80-\xff]/', $s) === 1 ? 16 : 0);
            $size = 0;
            if ($t !== null) {
                if ($previous !== null && $t < $previous) {
                    $flags |= 64;
                    $window = [];
                    $head = 0;
                }
                while ($head < \count($window) && $t >= $window[$head] + 10) {
                    $head++;
                }
                $window[] = $t;
                $size = \count($window) - $head;
                $previous = $t;
                $flags |= $size > 5 ? 128 : 0;
            }
            $hasTrailing = $trailing !== null ? 1 : 0;
            $needsPong = $commandIdx === 2 && (($trailing !== null && $trailing !== '') || $first !== null) ? 1 : 0;
            $class = match (true) {
                $len === 0 => 0,
                $prefixKind === 3 || $commandIdx === 0 || $commandIdx === 13 => 6,
                $commandIdx === 2 || $commandIdx === 3 => 1,
                $commandIdx === 4 || $commandIdx === 5 => $channel !== null ? 2 : 3,
                $commandIdx === 1 => 4,
                $commandIdx === 15 => 7,
                default => 5,
            };
            $tail[$class]++;
            for ($b = 0; $b < 10; $b++) {
                $tail[8 + $b] += $flags >> $b & 1;
            }
            $tail[18] += $needsPong;
            $tail[19] = max($tail[19], $size);
            $tail[20] += $controls;
            $tail[22] += $hasTrailing;
            $out[] = $class | $commandIdx << 3 | $prefixKind << 7 | $flags << 9 | min($params, 15) << 19 | $hasTrailing << 23 | $numeric << 24
                | min($size, 63) << 34 | min($controls, 63) << 40 | min($len, 16383) << 46 | $needsPong << 60;
        }
        $tail[21] = \count($channels);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W63_IRC_COMMANDS = ['PING' => 2, 'PONG' => 3, 'PRIVMSG' => 4, 'NOTICE' => 5, 'JOIN' => 6, 'PART' => 7, 'QUIT' => 8,
        'KICK' => 9, 'NICK' => 10, 'MODE' => 11, 'TOPIC' => 12, 'ERROR' => 13, 'CAP' => 14];

    /** The mIRC formatting codes _stripControlCharactersPhp() removes. */
    private const array W63_IRC_CONTROLS = ["\x02", "\x03", "\x04", "\x0F", "\x11", "\x16", "\x1D", "\x1E", "\x1F"];

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('irc_client_w631', self::W63_IRC_CLIENT_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
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
                $handle = self::w63RustFfiState('ffi');
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
     * Calls the Wave 63.1 IRCClient export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_IRC_CLIENT_FFI_CDEF.)
            $written = $ffi->nntmux_w631_irc_client_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
