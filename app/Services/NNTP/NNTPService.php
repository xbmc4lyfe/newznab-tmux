<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\Releases\Verification\ArticleBudgetExceeded;
use App\Services\Tmux\Tmux;
use App\Services\Yenc\ArticleFrame;
use App\Services\Yenc\YencPart;
use App\Services\YencService;
use DariusIII\NetNntp\Client as NntpClient;
use DariusIII\NetNntp\Error as NntpError;
use DariusIII\NetNntp\Protocol\ResponseCode;

/**
 * NNTP Service for connecting to usenet, retrieving articles and article headers,
 * decoding yEnc articles, and decompressing article headers.
 *
 * This service wraps the DariusIII\NetNntp\Client class with enhanced functionality
 * and Laravel-friendly dependency injection.
 */
class NNTPService extends NntpClient
{
    private bool $readingBoundedArticleStatus = false;

    public static function verificationMessageId(string $messageId, int $maximumBytes): string
    {
        $messageId = trim($messageId, '<>');
        if ($maximumBytes < 1 || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', $messageId) !== 1) {
            throw new \RuntimeException('Invalid verification article request.');
        }

        return $messageId;
    }

    /**
     * Read one bounded, undecoded article for integrity verification.
     *
     * @param  array<string, mixed>|null  $responseMetadata
     *
     * @param-out array{response_code: int|null, response_text: string|null, response_text_basis: string, error_type: string|null}|null $responseMetadata
     *
     * @throws \RuntimeException
     */
    public function verificationArticle(string $messageId, int $maximumBytes, ?array &$responseMetadata = null): string
    {
        $messageId = self::verificationMessageId($messageId, $maximumBytes);
        $response = $this->_sendCommand('BODY <'.$messageId.'>');
        if ($responseMetadata !== null) {
            $responseMetadata = $this->fingerprintResponseMetadata($response);
        }
        if ($response !== ResponseCode::BodyFollows->value) {
            if ($response !== ResponseCode::NoSuchArticleId->value) {
                $this->doQuit();
            }
            throw in_array($response, [ResponseCode::NoSuchArticleId->value, ResponseCode::NoSuchArticleNumber->value], true)
                ? new NntpArticleMissingException('Article is unavailable; retry verification later.')
                : new \RuntimeException('Article is unavailable; retry verification later.');
        }
        $raw = '';
        while (($line = fgets($this->_socket, $limit = max(4, min($maximumBytes - strlen($raw), 65534) + 2))) !== false) {
            if ($line === ".\r\n" || $line === ".\n") {
                return $raw;
            }
            if (! str_ends_with($line, "\n")) {
                // A partial body line proves the article too large when its unstuffed bytes already
                // exceed the budget, or, when they exactly reach it after filling a budget-capped
                // read, when the line continues at all (its terminator alone overflows). A bare
                // terminator prefix, or a response that ends within budget, is truncated.
                // At exactly 65,534 remaining bytes the budget and the line limit cap the read equally.
                if ($maximumBytes - strlen($raw) <= 65534 && $line !== '.' && $line !== ".\r") {
                    $unstuffed = strlen($raw) + strlen($line) - (str_starts_with($line, '..') ? 1 : 0);
                    $continues = static fn ($socket): bool => is_string($next = fread($socket, 1)) && $next !== '';
                    if ($unstuffed > $maximumBytes
                        || ($unstuffed === $maximumBytes && strlen($line) === $limit - 1 && $continues($this->_socket))) {
                        $this->doQuit();
                        throw new ArticleBudgetExceeded('Article exceeds verification memory budget.');
                    }
                }
                $this->doQuit();
                throw new \RuntimeException('Verification article line is truncated or oversized.');
            }
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            if (strlen($raw) + strlen($line) > $maximumBytes) {
                $this->doQuit();
                throw new ArticleBudgetExceeded('Article exceeds verification memory budget.');
            }
            $raw .= $line;
        }
        $this->doQuit();
        throw new \RuntimeException('Truncated article response.');
    }

    /**
     * Pipeline bounded BODY requests and drain independent connections concurrently.
     * Every response remains attached to its request position; a damaged frame closes that connection.
     *
     * @param  list<array{connection: NNTPService, ids: list<string>}>  $batches
     * @return array<string, string|\RuntimeException>
     */
    public static function verificationArticleBatch(array $batches, int $maximumBytes, int $bufferBytes, callable $heartbeat): array
    {
        if ($maximumBytes < 1 || $bufferBytes < $maximumBytes || count($batches) > 8) {
            throw new \RuntimeException('Invalid verification pipeline budget.');
        }
        $states = [];
        $results = [];
        $bytes = 0;
        $count = 0;
        foreach ($batches as $batch) {
            $count += count($batch['ids']);
            if (count($batch['ids']) > 16 || $count > intdiv($bufferBytes, $maximumBytes)) {
                throw new \RuntimeException('Verification pipeline exceeds its buffer budget.');
            }
            foreach ($batch['ids'] as $id) {
                self::verificationMessageId($id, $maximumBytes);
            }
        }
        $fail = static function (array &$state, \RuntimeException $error) use (&$results): void {
            foreach (array_slice($state['ids'], $state['position']) as $id) {
                $results[$id] = $error;
            }
            if (is_resource($state['socket'])) {
                fclose($state['socket']);
            }
            $state['connection']->_resetProperties();
            $state['done'] = true;
        };
        try {
            foreach ($batches as $index => $batch) {
                $connection = $batch['connection'];
                $socket = $connection->_socket;
                if (! is_resource($socket) || $connection->_compressionEnabled) {
                    foreach ($batch['ids'] as $id) {
                        $results[$id] = new \RuntimeException('Raw verification connection is unavailable.');
                    }

                    continue;
                }
                $heartbeat();
                $command = implode('', array_map(static fn (string $id): string => 'BODY <'.$id.">\r\n", $batch['ids']));
                $states[$index] = ['connection' => $connection, 'socket' => $socket, 'ids' => $batch['ids'],
                    'position' => 0, 'body' => false, 'raw' => '', 'buffer' => '', 'done' => $batch['ids'] === [], 'last' => microtime(true)];
                if (fwrite($socket, $command) !== strlen($command)) {
                    $fail($states[$index], new \RuntimeException('Cannot send verification pipeline.'));

                    continue;
                }
                stream_set_blocking($socket, false);
            }
            $lastHeartbeat = microtime(true);
            while (array_filter($states, static fn (array $state): bool => ! $state['done']) !== []) {
                if (microtime(true) - $lastHeartbeat >= 1.0) {
                    $heartbeat();
                    $lastHeartbeat = microtime(true);
                }
                $read = [];
                foreach ($states as &$state) {
                    if (! $state['done']) {
                        if (microtime(true) - $state['last'] > $state['connection']->_socketTimeout) {
                            $fail($state, new \RuntimeException('Verification pipeline timed out.'));
                        } else {
                            $read[] = $state['socket'];
                        }
                    }
                }
                unset($state);
                if ($read === []) {
                    break;
                }
                $write = $except = [];
                if (stream_select($read, $write, $except, 1) === false) {
                    throw new \RuntimeException('Cannot poll verification connections.');
                }
                foreach ($states as &$state) {
                    if ($state['done'] || ! in_array($state['socket'], $read, true)) {
                        continue;
                    }
                    $chunk = fread($state['socket'], 65536);
                    if ($chunk === false || ($chunk === '' && feof($state['socket']))) {
                        $fail($state, new \RuntimeException('Truncated verification pipeline.'));

                        continue;
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $state['last'] = microtime(true);
                    $state['buffer'] .= $chunk;
                    while (($end = strpos($state['buffer'], "\n")) !== false && ! $state['done']) {
                        $line = substr($state['buffer'], 0, $end + 1);
                        $state['buffer'] = substr($state['buffer'], $end + 1);
                        $id = $state['ids'][$state['position']];
                        if (strlen($line) > 65536) {
                            $fail($state, new \RuntimeException('Verification pipeline line exceeds its limit.'));
                            break;
                        }
                        if (! $state['body']) {
                            if (preg_match('/\A(\d{3})(?:\s|$)/', $line, $status) !== 1) {
                                $fail($state, new \RuntimeException('Invalid verification pipeline status.'));
                                break;
                            }
                            if ((int) $status[1] === 222) {
                                if (preg_match('/<([^<>]+)>/', $line, $identity) !== 1 || $identity[1] !== $id) {
                                    $fail($state, new \RuntimeException('Verification response identity differs from its request.'));
                                    break;
                                }
                                $state['body'] = true;
                            } elseif (in_array((int) $status[1], [430, 423], true)) {
                                $results[$id] = new \RuntimeException('Verification article is unavailable.');
                                $state['position']++;
                            } else {
                                $fail($state, new \RuntimeException('Unexpected verification pipeline status.'));
                                break;
                            }
                        } elseif ($line === ".\r\n" || $line === ".\n") {
                            $results[$id] = $state['raw'];
                            $state['raw'] = '';
                            $state['body'] = false;
                            $state['position']++;
                        } else {
                            if (str_starts_with($line, '..')) {
                                $line = substr($line, 1);
                            }
                            if (strlen($state['raw']) + strlen($line) > $maximumBytes || $bytes + strlen($line) > $bufferBytes) {
                                $fail($state, new \RuntimeException('Verification pipeline exceeds its byte budget.'));
                                break;
                            }
                            $state['raw'] .= $line;
                            $bytes += strlen($line);
                        }
                        $state['done'] = $state['position'] >= count($state['ids']);
                    }
                    if (! $state['done'] && strlen($state['buffer']) >= 65536) {
                        $fail($state, new \RuntimeException('Verification pipeline line is truncated or oversized.'));
                    }
                    if ($state['done'] && $state['buffer'] !== '') {
                        $fail($state, new \RuntimeException('Unexpected data after verification pipeline.'));
                    }
                }
                unset($state);
            }
        } catch (\Throwable $error) {
            foreach ($states as &$state) {
                if (! $state['done']) {
                    $fail($state, new \RuntimeException('Verification pipeline interrupted.', previous: $error));
                }
            }
            unset($state);
            throw $error;
        } finally {
            foreach ($states as $state) {
                if (is_resource($state['socket'])) {
                    stream_set_blocking($state['socket'], true);
                }
            }
        }

        return $results;
    }

    public function verificationPipelineAvailable(): bool
    {
        return is_resource($this->_socket) && ! $this->_compressionEnabled;
    }

    protected bool $_debugBool;

    protected bool $_echo;

    /**
     * Does the server support XFeature GZip header compression?
     */
    protected bool $_compressionSupported = true;

    /**
     * Is header compression enabled for the session?
     */
    protected bool $_compressionEnabled = false;

    /**
     * Currently selected group.
     */
    protected string $_currentGroup = '';

    protected string|int $_currentPort = 'NNTP_PORT';

    /**
     * Address of the current NNTP server.
     */
    protected string $_currentServer = 'NNTP_SERVER';

    protected bool $_usingAlternateServer = false;

    /**
     * Are we allowed to post to usenet?
     */
    protected bool $_postingAllowed = false;

    /**
     * How many times should we try to reconnect to the NNTP server?
     */
    protected int $_nntpRetries;

    /**
     * How many connections should we use on primary NNTP server.
     */
    protected int $_primaryNntpConnections;

    /**
     * How many connections should we use on alternate NNTP server.
     */
    protected int $_alternateNntpConnections;

    /**
     * How many connections do we use on primary NNTP server.
     */
    protected int $_primaryCurrentNntpConnections;

    /**
     * How many connections do we use on alternate NNTP server.
     */
    protected int $_alternateCurrentNntpConnections;

    /**
     * Seconds to wait for the blocking socket to timeout.
     */
    protected int $_socketTimeout = 120;

    protected Tmux $_tmux;

    /**
     * Cached config values for performance.
     */
    protected string $_configServer;

    protected string $_configAlternateServer;

    protected int $_configPort;

    protected int $_configAlternatePort;

    protected bool $_configSsl;

    protected bool $_configAlternateSsl;

    protected string $_configUsername;

    protected string $_configPassword;

    protected string $_configAlternateUsername;

    protected string $_configAlternatePassword;

    protected int $_configSocketTimeout;

    protected int $_configAlternateSocketTimeout;

    protected bool $_configCompressedHeaders;

    /**
     * If on unix, hide yydecode CLI output.
     */
    protected string $_yEncSilence;

    /**
     * Path to temp yEnc input storage file.
     */
    protected string $_yEncTempInput;

    /**
     * Path to temp yEnc output storage file.
     */
    protected string $_yEncTempOutput;

    /**
     * Resolved on first BODY read so diagnostics can boot with an unusable native library.
     */
    protected ?YencService $_yencService = null;

    /**
     * Create a new NNTP service instance.
     */
    public function __construct(?Tmux $tmux = null, ?YencService $yencService = null)
    {
        parent::__construct();

        $this->_echo = config('nntmux.echocli');
        $this->_tmux = $tmux ?? new Tmux;
        $this->_yencService = $yencService;
        $this->_nntpRetries = app(ConfigurationProvider::class)->ingestion()->nntpRetries !== '' ? (int) app(ConfigurationProvider::class)->ingestion()->nntpRetries : 0 + 1;

        $this->initializeConfig();
    }

    /**
     * Initialize configuration values from Laravel config.
     */
    protected function initializeConfig(): void
    {
        $this->_configServer = config('nntmux_nntp.server');
        $this->_configAlternateServer = config('nntmux_nntp.alternate_server');
        $this->_configPort = (int) config('nntmux_nntp.port');
        $this->_configAlternatePort = (int) config('nntmux_nntp.alternate_server_port');
        $this->_configSsl = (bool) config('nntmux_nntp.ssl');
        $this->_configAlternateSsl = (bool) config('nntmux_nntp.alternate_server_ssl');
        $this->_configUsername = config('nntmux_nntp.username') ?? '';
        $this->_configPassword = config('nntmux_nntp.password') ?? '';
        $this->_configAlternateUsername = config('nntmux_nntp.alternate_server_username') ?? '';
        $this->_configAlternatePassword = config('nntmux_nntp.alternate_server_password') ?? '';
        $this->_configSocketTimeout = (int) (config('nntmux_nntp.socket_timeout') ?: $this->_socketTimeout);
        $this->_configAlternateSocketTimeout = (int) (config('nntmux_nntp.alternate_server_socket_timeout') ?: $this->_socketTimeout);
        $this->_configCompressedHeaders = (bool) config('nntmux_nntp.compressed_headers');

        $this->_currentPort = $this->_configPort;
        $this->_currentServer = $this->_configServer;
        $this->_primaryNntpConnections = (int) config('nntmux_nntp.main_nntp_connections');
        $this->_alternateNntpConnections = (int) config('nntmux_nntp.alternate_nntp_connections');
        $this->_selectedGroupSummary = null;
        $this->_overviewFormatCache = null;
    }

    /**
     * Check if a value is a DariusIII\NetNntp\Error instance.
     *
     * @param  mixed  $data  The value to check
     * @return bool True if $data is a DariusIII\NetNntp\Error instance
     */
    public static function isError(mixed $data): bool
    {
        return NntpError::isError($data);
    }

    /**
     * Destruct.
     * Close the NNTP connection if still connected.
     */
    public function __destruct()
    {
        $this->doQuit();
    }

    /**
     * Connect to a usenet server.
     *
     * @param  bool  $compression  Should we attempt to enable XFeature Gzip compression on this connection?
     * @param  bool  $alternate  Use the alternate NNTP connection.
     * @return mixed On success = (bool)   Did we successfully connect to the usenet?
     *
     * @throws \Exception
     *                    On failure = (object) DariusIII\NetNntp\Error.
     */
    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $primaryUSP = [
            'ip' => gethostbyname($this->_configServer),
            'port' => $this->_configPort,
        ];
        $alternateUSP = [
            'ip_a' => gethostbyname($this->_configAlternateServer),
            'port_a' => $this->_configAlternatePort,
        ];
        $primaryConnections = $this->_tmux->getUSPConnections('primary', $primaryUSP);
        $alternateConnections = $this->_tmux->getUSPConnections('alternate', $alternateUSP);
        if ($this->_isConnected() && $this->_usingAlternateServer === $alternate && (($alternate && $this->_currentServer === $this->_configAlternateServer && ($this->_primaryNntpConnections < $alternateConnections['alternate']['active'])) || (! $alternate && $this->_currentServer === $this->_configServer && ($this->_primaryNntpConnections < $primaryConnections['primary']['active'])))) {
            return true;
        }

        $this->doQuit();
        $this->_usingAlternateServer = $alternate;

        $ret = $connected = $cError = $aError = false;

        // Set variables to connect based on if we are using the alternate provider or not.
        if (! $alternate) {
            $sslEnabled = $this->_configSsl;
            $this->_currentServer = $this->_configServer;
            $this->_currentPort = $this->_configPort;
            $userName = $this->_configUsername;
            $password = $this->_configPassword;
            $socketTimeout = $this->_configSocketTimeout;
        } else {
            $sslEnabled = $this->_configAlternateSsl;
            $this->_currentServer = $this->_configAlternateServer;
            $this->_currentPort = $this->_configAlternatePort;
            $userName = $this->_configAlternateUsername;
            $password = $this->_configAlternatePassword;
            $socketTimeout = $this->_configAlternateSocketTimeout;
        }

        $enc = ($sslEnabled ? ' (ssl)' : ' (non-ssl)');
        $sslEnabled = ($sslEnabled ? 'tls' : false);

        // Try to connect until we run of out tries.
        $retries = $this->_nntpRetries;
        while (true) {
            $retries--;
            $authenticated = false;

            // If we are not connected, try to connect.
            if (! $connected) {
                $ret = $this->connect($this->_currentServer, $sslEnabled, (int) $this->_currentPort, 5, $socketTimeout);
            }
            // Check if we got an error while connecting.
            $cErr = self::isError($ret);

            // If no error, we are connected.
            if (! $cErr) {
                // Say that we are connected so we don't retry.
                $connected = true;
                // When there is no error it returns bool if we are allowed to post or not.
                $this->_postingAllowed = $ret;
            } elseif (! $cError) {
                $cError = $ret->getMessage();
            }

            // If error, try to connect again.
            if ($cErr && $retries > 0) {
                $this->pauseBeforeRetry($this->_nntpRetries - $retries);

                continue;
            }

            // If we have no more retries and could not connect, return an error.
            if ($retries === 0 && ! $connected) {
                $message =
                    'Cannot connect to server '.
                    $this->_currentServer.
                    $enc.
                    ': '.
                    $cError;

                return $this->throwError($this->failureMessage($message));
            }

            // If we are connected, try to authenticate.
            if ($connected) {
                // If the username is empty it probably means the server does not require a username.
                if ($userName === '') {
                    $authenticated = true;

                    // Try to authenticate to usenet.
                } else {
                    $ret2 = $this->authenticate($userName, $password);

                    // Check if there was an error authenticating.
                    $aErr = self::isError($ret2);

                    // If there was no error, then we are authenticated.
                    if (! $aErr) {
                        $authenticated = true;
                    } elseif (! $aError) {
                        $aError = $ret2->getMessage();
                    }

                    // If error, try to authenticate again.
                    if ($aErr && $retries > 0) {
                        $this->pauseBeforeRetry($this->_nntpRetries - $retries);

                        continue;
                    }

                    // If we ran out of retries, return an error.
                    if ($retries === 0 && ! $authenticated) {
                        $message =
                            'Cannot authenticate to server '.
                            $this->_currentServer.
                            $enc.
                            ' - '.
                            $userName.
                            ' ('.$aError.')';

                        return $this->throwError($this->failureMessage($message));
                    }
                }
            }
            // If we are connected and authenticated, try enabling compression if we have it enabled.
            if ($connected && $authenticated) {
                // Check if we should use compression on the connection.
                if (! $compression || ! $this->_configCompressedHeaders) {
                    $this->_compressionSupported = false;
                }

                return true;
            }
            // If we reached this point and have not connected after all retries, break out of the loop.
            if ($retries === 0) {
                break;
            }

            $this->pauseBeforeRetry($this->_nntpRetries - $retries);
        }
        // If we somehow got out of the loop, return an error.
        $message = 'Unable to connect to '.$this->_currentServer.$enc;

        return $this->throwError($this->failureMessage($message));
    }

    /**
     * Print an NNTP failure and hand the text back for the Error object. cli()->error() prints and
     * returns void, so passing its result to throwError() raised a TypeError that bypassed every
     * caller's isError() handling (including the alternate-provider fallback) and killed the worker.
     */
    protected function failureMessage(string $message): string
    {
        cli()->error($message);

        return $message;
    }

    /**
     * Wait before retrying a failed connect or login. A provider that rejects logins because the account
     * is at its connection limit needs seconds, not milliseconds, to free a slot, and the retry loop's
     * `continue` paths used to skip the pause entirely, spending every retry within about two seconds.
     */
    protected function pauseBeforeRetry(int $attempt): void
    {
        usleep(min(3000, 400 * max(1, $attempt)) * 1000);
    }

    /**
     * Disconnect from the current NNTP server.
     *
     * @param  bool  $force  Force quit even if not connected?
     * @return mixed On success : (bool)   Did we successfully disconnect from usenet?
     *               On Failure : (object) DariusIII\NetNntp\Error.
     */
    public function doQuit(bool $force = false): mixed
    {
        $this->_resetProperties();

        // Check if we are connected to usenet.
        if ($force || parent::_isConnected()) {
            // Disconnect from usenet.
            return $this->disconnect();
        }

        return true;
    }

    /**
     * Retain the configured provider slot, including shared hostnames with different accounts.
     */
    public function reconnect(bool $compression = true): mixed
    {
        $this->doQuit(\is_resource($this->_socket));

        return $this->doConnect($compression, $this->_usingAlternateServer);
    }

    /**
     * Reset some properties when disconnecting from usenet.
     */
    protected function _resetProperties(): void
    {
        $this->_compressionEnabled = false;
        $this->_compressionSupported = true;
        $this->_currentGroup = '';
        $this->_postingAllowed = false;
        $this->_selectedGroupSummary = null;
        $this->_overviewFormatCache = null;
        $this->_socket = null;
    }

    /**
     * Attempt to enable compression if the admin enabled the site setting.
     *
     * @note   This can be used to enable compression if the server was connected without compression.
     *
     * @throws \Exception
     */
    public function enableCompression(): void
    {
        if (! $this->_configCompressedHeaders) {
            return;
        }
        $this->_enableCompression();
    }

    /**
     * @param  string  $group  Name of the group to select.
     * @param  mixed  $articles  (optional) experimental! When true the article numbers is returned in 'articles'.
     * @param  bool  $force  Force a refresh to get updated data from the usenet server.
     * @return mixed On success : (array)  Group information.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        $connected = $this->_checkConnection(false);
        if ($connected !== true) {
            return $connected;
        }

        // Check if the current selected group is the same, or if we have not selected a group or if a fresh summary is wanted.
        if ($force || $this->_currentGroup !== $group || $this->_selectedGroupSummary === null) {
            $this->_currentGroup = $group;

            return parent::selectGroup($group, $articles);
        }

        return $this->_selectedGroupSummary;
    }

    /**
     * Fetch an overview of article(s) in the currently selected group.
     *
     * @return mixed On success : (array)  Multidimensional array with article headers.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function getOverview(mixed $range = null, bool $names = true, bool $forceNames = true): mixed
    {
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        // Enabled header compression if not enabled.
        $this->_enableCompression();

        return parent::getOverview($range, $names, $forceNames);
    }

    /**
     * Pass a XOVER command to the NNTP provider, return array of articles using the overview format as array keys.
     *
     * @note This is a faster implementation of getOverview.
     *
     * Example successful return:
     *    array(9) {
     *        'Number'     => string(9)  "679871775"
     *        'Subject'    => string(18) "This is an example"
     *        'From'       => string(19) "Example@example.com"
     *        'Date'       => string(24) "26 Jun 2014 13:08:22 GMT"
     *        'Message-ID' => string(57) "<part1of1.uS*yYxQvtAYt$5t&wmE%UejhjkCKXBJ!@example.local>"
     *        'References' => string(0)  ""
     *        'Bytes'      => string(3)  "123"
     *        'Lines'      => string(1)  "9"
     *        'Xref'       => string(66) "e alt.test:679871775"
     *    }
     *
     * @param  string  $range  Range of articles to get the overview for. Examples follow:
     *                         Single article number:         "679871775"
     *                         Range of article numbers:      "679871775-679999999"
     *                         All newer than article number: "679871775-"
     *                         All older than article number: "-679871775"
     *                         Message-ID:                    "<part1of1.uS*yYxQvtAYt$5t&wmE%UejhjkCKXBJ!@example.local>"
     * @return mixed Header rows on success; the underlying NNTP error or connection-failure result otherwise.
     *
     * @throws \Exception
     */
    public function getXOVER(string $range): mixed
    {
        // Check if we are still connected.
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        // Enabled header compression if not enabled.
        $this->_enableCompression();

        // Send XOVER command to NNTP with wanted articles.
        $response = $this->_sendCommand('XOVER '.$range);
        if (self::isError($response)) {
            return $response;
        }

        // Verify the NNTP server got the right command, get the headers data.
        if ($response === ResponseCode::OverviewFollows->value) {
            $data = $this->_getTextResponse();
            if (self::isError($data)) {
                return $data;
            }
        } else {
            return $this->_handleErrorResponse($response);
        }

        // Fetch the header overview format (for setting the array keys on the return array).
        if ($this->_overviewFormatCache !== null && isset($this->_overviewFormatCache['Xref'])) {
            $overview = $this->_overviewFormatCache;
        } else {
            $overview = $this->getOverviewFormat(false, true);
            if (self::isError($overview)) {
                return $overview;
            }
            $this->_overviewFormatCache = $overview;
        }

        // Pre-compute keys array and Xref position for faster processing
        // getOverview() (part repair) caches the format with 'Number' prepended; don't add it twice.
        $keys = array_merge(['Number'], array_keys(array_diff_key($overview, ['Number' => true])));
        $keyCount = \count($keys);
        $xrefIndex = array_search('Xref', $keys, true);

        // Loop over strings of headers.
        foreach ($data as $key => $header) {
            // Split the individual headers by tab.
            $parts = explode("\t", $header);

            // Make sure it's not empty.
            if (empty($parts)) {
                continue;
            }

            // Build header array using pre-computed keys
            $headerArray = [];
            $partCount = \count($parts);

            for ($i = 0; $i < $keyCount && $i < $partCount; $i++) {
                $value = $parts[$i];
                // Strip "Xref: " prefix if this is the Xref field
                if ($i === $xrefIndex && isset($value[5])) {
                    $value = substr($value, 6);
                }
                $headerArray[$keys[$i]] = $value;
            }

            // Add the individual header array back to the return array.
            $data[$key] = $headerArray;
        }

        // Return the array of headers.
        return $data;
    }

    /**
     * Fetch valid groups.
     *
     * Returns a list of valid groups (that the client is permitted to select) and associated information.
     *
     * @param  mixed  $wildMat  (optional) http://tools.ietf.org/html/rfc3977#section-4
     * @return array<string, mixed>|string Pear error on failure, array with groups on success.
     *
     * @throws \Exception
     */
    public function getGroups(mixed $wildMat = null): mixed
    {
        // Enabled header compression if not enabled.
        $this->_enableCompression();

        return parent::getGroups($wildMat);
    }

    /**
     * Fetch exactly one complete article, with a decoded byte ceiling independent of yEnc file size.
     * Failures never expose a prefix. The alternate provider is tried once, except for oversize input.
     */
    public function getMessageBounded(string $groupName, string $messageId, int $maxDecodedBytes, bool $alternate = false, ?callable $onRead = null): BoundedArticleResult
    {
        if ($maxDecodedBytes < 1 || $maxDecodedBytes > intdiv(PHP_INT_MAX - 65538, 5)) {
            throw new \InvalidArgumentException('The decoded article ceiling must be positive and permit a bounded wire ceiling.');
        }
        $identifier = $this->_formatMessageID($messageId);
        if ($identifier === false || strlen($identifier) > 505 || preg_match('/[\x00-\x20\x7f]/', $identifier)) {
            return BoundedArticleResult::failure('Invalid article Message-ID.');
        }

        $ownershipLost = false;
        $heartbeat = $onRead === null ? null : static function () use ($onRead, &$ownershipLost): bool {
            $ownershipLost = $ownershipLost || ! $onRead();

            return ! $ownershipLost;
        };
        $result = $this->fetchBoundedArticle($groupName, $identifier, $maxDecodedBytes, $heartbeat);
        if ($result->body !== null || $result->oversized || $ownershipLost || ! $alternate) {
            return $result;
        }

        $client = $this->createBoundedArticleClient();
        try {
            $connected = $client->doConnect(false, $this->_currentServer === $this->_configServer);
            if ($connected !== true) {
                return BoundedArticleResult::failure('The alternate article provider could not connect.');
            }

            return $client->fetchBoundedArticle($groupName, $identifier, $maxDecodedBytes, $heartbeat);
        } catch (\Exception $exception) {
            return BoundedArticleResult::failure('The alternate article provider failed: '.$exception->getMessage());
        } finally {
            $client->discardBoundedArticleConnection();
        }
    }

    protected function createBoundedArticleClient(): self
    {
        return new self(yencService: $this->_yencService);
    }

    /** Abort without QUIT: an unread BODY must never be reused as a command response. */
    protected function discardBoundedArticleConnection(): void
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_resetProperties();
    }

    private function fetchBoundedArticle(string $groupName, string $identifier, int $maxDecodedBytes, ?callable $onRead): BoundedArticleResult
    {
        try {
            if ($this->_checkConnection(false) !== true) {
                $this->discardBoundedArticleConnection();

                return BoundedArticleResult::failure('The article provider could not connect.');
            }
            $group = $this->selectGroup($groupName);
            if (self::isError($group) || $group === false) {
                $this->discardBoundedArticleConnection();

                return BoundedArticleResult::failure('The article group could not be selected.');
            }
            $this->readingBoundedArticleStatus = true;
            try {
                $response = $this->_sendCommand('BODY '.$identifier);
            } finally {
                $this->readingBoundedArticleStatus = false;
            }
            if ($response !== ResponseCode::BodyFollows->value) {
                // A complete 430 has no BODY; the authenticated socket and group remain usable.
                if ($response !== ResponseCode::NoSuchArticleId->value) {
                    $this->discardBoundedArticleConnection();
                }

                return BoundedArticleResult::failure('The article provider did not return a BODY.');
            }

            return $this->readBoundedArticle($maxDecodedBytes, $onRead);
        } catch (\Exception $exception) {
            $this->discardBoundedArticleConnection();

            return BoundedArticleResult::failure('The article could not be read: '.$exception->getMessage());
        }
    }

    /** Validate bounded BODY status framing before allowing a missing article to reuse the socket. */
    protected function _getStatusResponse(): mixed
    {
        if (! $this->readingBoundedArticleStatus) {
            return parent::_getStatusResponse();
        }

        $this->_currentStatusResponse = null;
        $this->_clearOpensslErrors();
        $response = @fgets($this->_socket, 513);
        $this->_clearOpensslErrors();
        if ($response === false || preg_match('/\A[1-5][0-9]{2}(?: [\x09\x20-\x7e\x80-\xff]*)?\r\n\z/', $response) !== 1) {
            return $this->throwError('Invalid or incomplete bounded article status line.');
        }

        $line = substr($response, 0, -2);
        $this->_logger?->debug('S: '.$line);
        $this->_currentStatusResponse = [(int) substr($line, 0, 3), substr($line, 4)];

        return $this->_currentStatusResponse[0];
    }

    /**
     * Wire ceiling includes framing, line endings, stuffing and the terminator. Five bytes per
     * decoded byte covers escaped one-byte lines; 64 KiB allows control lines and other framing.
     * Each fgets reads at most 8191 bytes and is tightened at the ceiling: at most one excess byte
     * is consumed, never appended. Body plus unfinished line therefore stays within the ceiling.
     */
    private function readBoundedArticle(int $maxDecodedBytes, ?callable $onRead): BoundedArticleResult
    {
        $maxWireBytes = 5 * $maxDecodedBytes + 65536;
        $received = 0;
        $body = '';
        $line = '';
        while (is_resource($this->_socket) && ! feof($this->_socket)) {
            $fragment = @fgets($this->_socket, min(8192, $maxWireBytes - $received + 2));
            if ($fragment === false || $fragment === '') {
                break;
            }
            if ($onRead !== null && ! $onRead()) {
                $this->discardBoundedArticleConnection();

                return BoundedArticleResult::failure('Article read stopped after processing lock ownership was lost.');
            }
            $received += strlen($fragment);
            if ($received > $maxWireBytes) {
                $this->discardBoundedArticleConnection();

                return BoundedArticleResult::failure('Article wire byte ceiling exceeded.', true);
            }
            $line .= $fragment;
            if (! str_ends_with($line, "\n")) {
                continue;
            }
            if ($line === ".\r\n") {
                return $this->decodeBoundedArticle($body, $maxDecodedBytes);
            }
            $body .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            $line = '';
        }
        $this->discardBoundedArticleConnection();

        return BoundedArticleResult::failure('Article ended before the BODY terminator.');
    }

    private function decodeBoundedArticle(string $body, int $maxDecodedBytes): BoundedArticleResult
    {
        $frame = ArticleFrame::parse($body);
        if ($frame === null) {
            if (preg_match('/(?:^|\n)=y(?:begin|part|end)(?:[ \t\r\n]|$)/i', $body)) {
                return BoundedArticleResult::failure('Incomplete or invalid yEnc article framing.');
            }
            if (strlen($body) > $maxDecodedBytes) {
                return BoundedArticleResult::failure('Article decoded byte ceiling exceeded.', true);
            }

            return BoundedArticleResult::success($body);
        }
        if (preg_match_all('/(?:^|\n)=ybegin(?:[ \t\r\n]|$)/i', $body) !== 1) {
            return BoundedArticleResult::failure('Multiple yEnc frames in one article.');
        }

        // Count actual decoded bytes before the decoder allocates output, ignoring claimed sizes.
        $decodedBytes = 0;
        $escaped = false;
        $end = $frame->payloadOffset + $frame->payloadLength;
        for ($offset = $frame->payloadOffset; $offset < $end; $offset++) {
            $byte = $body[$offset];
            if ($byte === "\r" || $byte === "\n") {
                continue;
            }
            if (! $escaped && $byte === '=') {
                $escaped = true;

                continue;
            }
            $escaped = false;
            if (++$decodedBytes > $maxDecodedBytes) {
                return BoundedArticleResult::failure('Article decoded byte ceiling exceeded.', true);
            }
        }
        $this->_yencService ??= app(YencService::class);
        $decoded = $this->_yencService->decode($body);
        if ($decoded === false || strlen($decoded) !== $decodedBytes) {
            return BoundedArticleResult::failure('Article yEnc decoding failed.');
        }

        return BoundedArticleResult::success($decoded, new YencPart(
            name: $frame->header['name'],
            size: (int) $frame->header['size'],
            part: isset($frame->header['part']) ? (int) $frame->header['part'] : null,
            begin: isset($frame->part['begin']) ? (int) $frame->part['begin'] : null,
            end: isset($frame->part['end']) ? (int) $frame->part['end'] : null,
        ));
    }

    /**
     * Download multiple article bodies and string them together.
     *
     * @param  string  $groupName  The name of the group the articles are in.
     * @param  mixed  $identifiers  (string) Message-ID.
     *                              (int)    Article number.
     *                              (array)  Article numbers or Message-ID's (can contain both in the same array)
     * @param  bool  $alternate  Use the alternate NNTP provider?
     * @return mixed On success : (string) The article bodies.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function getMessages(string $groupName, mixed $identifiers, bool $alternate = false, ?callable $onRead = null): mixed
    {
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        $ownershipLost = false;
        $heartbeat = $onRead === null ? null : static function () use ($onRead, &$ownershipLost): bool {
            $ownershipLost = $ownershipLost || ! $onRead();

            return ! $ownershipLost;
        };

        // String to hold all the bodies.
        $body = '';

        $aConnected = false;
        $nntp = ($alternate ? new self : null);

        // Check if the msgIds are in an array.
        if (\is_array($identifiers)) {
            $loops = $messageSize = 0;

            // Loop over the message-ID's or article numbers.
            foreach ($identifiers as $wanted) {
                /* This is to attempt to prevent string size overflow.
                 * We get the size of 1 body in bytes, we increment the loop on every loop,
                 * then we multiply the # of loops by the first size we got and check if it
                 * exceeds 1.7 billion bytes (less than 2GB to give us headroom).
                 * If we exceed, return the data.
                 * If we don't do this, these errors are fatal.
                 */
                if ((++$loops * $messageSize) >= 1700000000) {
                    return $body;
                }

                // Download the body.
                [$message, $ownershipLost] = $this->readMessageWithOwnership($this, $groupName, $wanted, $heartbeat, $ownershipLost);
                if ($ownershipLost) {
                    if ($aConnected === true) {
                        $nntp->doQuit();
                    }

                    return $message;
                }

                // Append the body to $body.
                if (! self::isError($message)) {
                    $body .= $message;

                    if ($messageSize === 0) {
                        $messageSize = \strlen($message);
                    }

                    // If there is an error try the alternate provider or return the error.
                } elseif ($alternate) {
                    if (! $aConnected) {
                        // Check if the current connected server is the alternate or not.
                        $aConnected = $this->_currentServer === $this->_configServer
                            ? $nntp->doConnect($this->_configCompressedHeaders, true)
                            : $nntp->doConnect();
                    }
                    // If we connected successfully to usenet try to download the article body.
                    if ($aConnected === true) {
                        [$newBody, $ownershipLost] = $this->readMessageWithOwnership($nntp, $groupName, $wanted, $heartbeat, $ownershipLost);
                        // Check if we got an error.
                        if ($nntp->isError($newBody)) {
                            $nntp->doQuit();
                            // If we got some data, return it.
                            if (! $ownershipLost && $body !== '') {
                                return $body;
                            }

                            // Return the error.
                            return $newBody;
                        }
                        // Append the alternate body to the main body.
                        $body .= $newBody;
                    }
                } else {
                    // If we got some data, return it.
                    if ($body !== '') {
                        return $body;
                    }

                    return $message;
                }
            }

            // If it's a string check if it's a valid message-ID.
        } elseif (\is_string($identifiers) || is_numeric($identifiers)) {
            [$body, $ownershipLost] = $this->readMessageWithOwnership($this, $groupName, $identifiers, $heartbeat, $ownershipLost);
            if ($alternate && ! $ownershipLost && self::isError($body)) {
                $nntp->doConnect($this->_configCompressedHeaders, true);
                [$body, $ownershipLost] = $this->readMessageWithOwnership($nntp, $groupName, $identifiers, $heartbeat, $ownershipLost);
                $aConnected = true;
            }

            // Else return an error.
        } else {
            $message = 'Wrong Identifier type, array, int or string accepted. This type of var was passed: '.gettype($identifiers);

            return $this->throwError($this->failureMessage($message));
        }

        if ($aConnected === true) {
            $nntp->doQuit();
        }

        return $body;
    }

    /**
     * Capture cancellation together with the body after the synchronous read.
     *
     * @return array{mixed, bool}
     */
    private function readMessageWithOwnership(self $client, string $groupName, mixed $identifier, ?callable $onRead, bool &$ownershipLost): array
    {
        $body = $client->_getMessage($groupName, $identifier, $onRead);

        return [$body, $ownershipLost];
    }

    /**
     * Download multiple article bodies by Message-ID only (no group selection), concatenating them.
     * Falls back to alternate provider if enabled. Message-IDs are yEnc decoded.
     *
     * @param  mixed  $identifiers  string|array Message-ID(s) (with or without < >)
     * @param  bool  $alternate  Use alternate NNTP server if primary fails for any ID.
     * @return mixed string concatenated bodies on success, Error object on total failure.
     *
     * @throws \Exception
     */
    public function getMessagesByMessageID(mixed $identifiers, bool $alternate = false): mixed
    {
        return $this->readOrderedMessages($identifiers, $alternate);
    }

    /**
     * Reuse articles at their original loop position; unresolved failures still stop the batch.
     *
     * @param  callable(mixed): ?string  $lookup
     * @param  callable(int): void  $onReused
     */
    public function getMessagesByMessageIDWithArticleReuse(mixed $identifiers, bool $alternate, callable $lookup, callable $onReused): mixed
    {
        return $this->readOrderedMessages($identifiers, $alternate, $lookup, $onReused);
    }

    private function readOrderedMessages(mixed $identifiers, bool $alternate, ?callable $lookup = null, ?callable $onReused = null): mixed
    {
        $connected = $this->_checkConnection(false); // no need to reselect group
        if ($connected !== true) {
            return $connected; // error passthrough
        }

        $body = '';
        $aConnected = false;
        $alt = ($alternate ? $this->previewAlternateConnection() : null);

        // Normalise to array for loop processing
        $ids = is_array($identifiers) ? $identifiers : [$identifiers];

        $loops = 0;
        $messageSize = 0;
        foreach ($ids as $id) {
            if ((++$loops * $messageSize) >= 1700000000) { // prevent huge string growth
                return $body;
            }
            $reused = $lookup === null ? null : $lookup($id);
            if ($reused !== null) {
                $msg = $reused;
                $onReused(strlen($reused));
            } else {
                $msg = $this->_getMessageByMessageID($id);
            }
            if (! self::isError($msg)) {
                $body .= $msg;
                if ($messageSize === 0) {
                    $messageSize = strlen($msg);
                }

                continue;
            }
            // Primary failed, try alternate if requested
            if ($alternate) {
                if (! $aConnected) {
                    $aConnected = $this->_currentServer === $this->_configServer
                        ? $alt->doConnect($this->_configCompressedHeaders, true)
                        : $alt->doConnect();
                }
                if ($aConnected === true) {
                    $altMsg = $alt->_getMessageByMessageID($id);
                    if ($alt->isError($altMsg)) {
                        $alt->doQuit();

                        return $body !== '' ? $body : $altMsg; // return what we have or error
                    }
                    $body .= $altMsg;
                } else { // alternate connect failed
                    return $body !== '' ? $body : $msg; // return collected or original error
                }
            } else { // no alternate
                return $body !== '' ? $body : $msg;
            }
        }

        if ($aConnected === true) {
            $alt->doQuit();
        }

        return $body;
    }

    /**
     * Internal: fetch single article body by Message-ID (yEnc decoded) without selecting a group.
     * Accepts article numbers but these require a group; will return error if numeric passed.
     *
     * @param  mixed  $identifier  Message-ID or article number.
     * @return mixed string body on success, Error on failure.
     *
     * @throws \Exception
     */
    protected function _getMessageByMessageID(mixed $identifier, bool $raw = false): mixed
    {
        // If numeric we cannot safely fetch without group context – delegate to existing path via error.
        if (is_numeric($identifier)) {
            return $this->throwError('Numeric article number requires group selection');
        }
        $id = $this->_formatMessageID($identifier);
        $response = $this->_sendCommand('BODY '.$id);
        if (self::isError($response)) {
            return $response;
        }
        if ($response !== ResponseCode::BodyFollows->value) {
            return $this->_handleErrorResponse($response);
        }

        return $raw ? $this->readRawBody() : $this->readBody();
    }

    /**
     * Fetch one article by Message-ID and decode it, keeping what its yEnc header says
     * about the file it belongs to. Falls back to the alternate provider if enabled.
     *
     * @return array{data: string, part: YencPart|null}|NntpError
     *
     * @throws \Exception
     */
    public function getArticleByMessageID(string $identifier, bool $alternate = false): array|NntpError
    {
        return $this->readArticleWithMetadata($identifier, $alternate);
    }

    /**
     * Reuse a verified mapped article only after the original connection check succeeds.
     *
     * @param  callable(): (array{data: string, part: YencPart}|null)  $lookup
     * @param  callable(int): void  $onReused
     * @return array{data: string, part: YencPart|null}|NntpError
     */
    public function getArticleByMessageIDWithReuse(string $identifier, bool $alternate, callable $lookup, callable $onReused): array|NntpError
    {
        return $this->readArticleWithMetadata($identifier, $alternate, lookup: $lookup, onReused: $onReused);
    }

    /**
     * @param  callable(string, YencPart): void  $onValidatedPrimary
     * @return array{data: string, part: YencPart|null}|NntpError
     */
    public function getArticleForArchiveMapping(string $identifier, bool $alternate, callable $onValidatedPrimary): array|NntpError
    {
        return $this->readArticleWithMetadata($identifier, $alternate, $onValidatedPrimary);
    }

    /** @return array{data: string, part: YencPart|null}|NntpError */
    private function readArticleWithMetadata(string $identifier, bool $alternate, ?callable $onValidatedPrimary = null, ?callable $lookup = null, ?callable $onReused = null): array|NntpError
    {
        $connected = $this->_checkConnection(false);
        if ($connected !== true) {
            return $connected instanceof NntpError ? $connected : $this->throwError('Unable to connect to usenet!');
        }

        $cached = $lookup === null ? null : $lookup();
        if ($cached !== null) {
            $onReused(strlen($cached['data']));

            return $cached;
        }

        $raw = $this->_getMessageByMessageID($identifier, true);
        $primary = is_string($raw) && $this->_currentServer === $this->_configServer;
        if (self::isError($raw) && $alternate) {
            $alt = $this->previewAlternateConnection();
            $altConnected = $this->_currentServer === $this->_configServer
                ? $alt->doConnect($this->_configCompressedHeaders, true)
                : $alt->doConnect();
            if ($altConnected === true) {
                $raw = $alt->_getMessageByMessageID($identifier, true);
                $alt->doQuit();
            }
        }
        if (! is_string($raw)) {
            return $raw instanceof NntpError ? $raw : $this->throwError('Article could not be read.');
        }

        $this->_yencService ??= app(YencService::class);
        [$data, $part] = $this->_yencService->decodeWithFrame($raw);
        if ($primary && $onValidatedPrimary !== null && $part !== null
            && $this->_yencService->hasValidatedIntegrity($raw, $data)) {
            $onValidatedPrimary($data, $part);
        }

        return ['data' => $data, 'part' => $part];

    }

    /**
     * Read raw HEAD fields and a bounded raw BODY for a known article ID.
     * The alternate provider is queried directly instead of only as a fallback.
     *
     * @return array{headers: list<string>, body: string, head_response: array{response_code: int|null, response_text: string|null, response_text_basis: string, error_type: string|null}, body_response: array{response_code: int|null, response_text: string|null, response_text_basis: string, error_type: string|null}, overview_response_code: int|null, overview_raw: string|null, overview_rows: list<string>, overview_row_count: int, overview_attempted: bool, command_results: list<array<string, mixed>>}
     */
    public function getFingerprintArticleByMessageID(string $identifier, int $maximumBodyBytes = 2_000_000,
        int $maximumHeaderBytes = 65_536, bool $alternate = false, bool $includeOverview = false): array
    {
        $identifier = self::verificationMessageId($identifier, $maximumBodyBytes);
        $connection = $this;
        if ($alternate) {
            $connection = $this->previewAlternateConnection();
            $connected = $this->_currentServer === $this->_configServer
                ? $connection->doConnect($this->_configCompressedHeaders, true) : $connection->doConnect();
        } else {
            $connected = $connection->_checkConnection(false);
        }
        if ($connected !== true) {
            throw new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.');
        }

        try {
            $overviewCode = null;
            $overviewRaw = null;
            $overviewLines = [];
            $overviewAttempted = $includeOverview;
            if ($includeOverview) {
                $overviewCode = $connection->_sendCommand('OVER <'.$identifier.'>');
                if ($overviewCode === 224) {
                    $overviewLines = $connection->readFingerprintMultiline(32_768);
                    $overviewRaw = $overviewLines[0] ?? null;
                }
            }
            $headResponse = [];
            $headers = $connection->readFingerprintArticleHeaders($identifier, $maximumHeaderBytes, $headResponse);
            $bodyResponse = [];
            $body = $connection->verificationArticle($identifier, $maximumBodyBytes, $bodyResponse);

            return [
                'headers' => $headers,
                'body' => $body,
                'head_response' => $headResponse,
                'body_response' => $bodyResponse,
                'overview_response_code' => is_numeric($overviewCode) ? (int) $overviewCode : null,
                'overview_raw' => $overviewRaw,
                'overview_rows' => $overviewLines,
                'overview_row_count' => count($overviewLines),
                'overview_attempted' => $overviewAttempted,
                'command_results' => [
                    ...($includeOverview ? [[
                        'command' => 'OVER MSGID',
                        'attempted' => true,
                        'response_code' => is_numeric($overviewCode) ? (int) $overviewCode : null,
                        'available' => $overviewCode === 224,
                        'error_type' => is_numeric($overviewCode) ? null : class_basename($overviewCode),
                    ]] : []),
                    ['command' => 'HEAD', 'attempted' => true, ...$headResponse, 'available' => $headResponse['response_code'] === 221],
                    ['command' => 'BODY', 'attempted' => true, ...$bodyResponse, 'available' => $bodyResponse['response_code'] === 222],
                ],
            ];
        } finally {
            if ($alternate) {
                try {
                    $connection->doQuit();
                } catch (\Throwable) {
                    // Preserve the article result when alternate cleanup fails.
                }
            }
        }
    }

    /**
     * Capture the responding NNTP server's advertised capabilities and overview schema.
     *
     * @return array<string, mixed>
     */
    public function getFingerprintServerProfile(bool $alternate = false): array
    {
        $connection = $alternate ? $this->previewAlternateConnection() : $this;
        $connected = $alternate
            ? $connection->doConnect($this->_configCompressedHeaders, true)
            : $connection->_checkConnection(false);
        if ($connected !== true) {
            throw new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.');
        }

        try {
            $capabilityCode = $connection->_sendCommand('CAPABILITIES');
            $capabilities = $capabilityCode === 101 ? $connection->readFingerprintMultiline(65_536) : [];
            $supportsOverview = count(array_filter($capabilities, static fn (string $line): bool => str_starts_with(strtoupper($line), 'OVER'))) > 0;
            $overviewCode = null;
            $overviewFormat = [];
            if ($supportsOverview) {
                $overviewCode = $connection->_sendCommand('LIST OVERVIEW.FMT');
                if ($overviewCode === 215) {
                    $overviewFormat = $connection->readFingerprintMultiline(32_768);
                }
            }

            return [
                'capabilities_response_code' => is_numeric($capabilityCode) ? (int) $capabilityCode : null,
                'capabilities_error_type' => is_numeric($capabilityCode) ? null : class_basename($capabilityCode),
                'capabilities_raw' => $capabilities,
                'implementation' => current(array_values(array_filter($capabilities, static fn (string $line): bool => str_starts_with(strtoupper($line), 'IMPLEMENTATION ')))) ?: null,
                'overview_format_response_code' => is_numeric($overviewCode) ? (int) $overviewCode : null,
                'overview_format_error_type' => $overviewCode === null || is_numeric($overviewCode) ? null : class_basename($overviewCode),
                'overview_format_raw' => $overviewFormat,
                'profiled_at_utc' => now()->utc()->toIso8601String(),
            ];
        } finally {
            if ($alternate) {
                try {
                    $connection->doQuit();
                } catch (\Throwable) {
                    // Preserve the profile if alternate cleanup fails.
                }
            }
        }
    }

    /** @return list<string> */
    private function readFingerprintMultiline(int $maximumBytes): array
    {
        $lines = [];
        $bytes = 0;
        while (($line = fgets($this->_socket, 16_385)) !== false) {
            if ($line === ".\r\n" || $line === ".\n") {
                return $lines;
            }
            if (! str_ends_with($line, "\n") || strlen($line) > 16_384) {
                $this->doQuit(true);
                throw new \RuntimeException('NNTP server profile line is truncated or oversized.');
            }
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $bytes += strlen($line);
            if ($bytes > $maximumBytes) {
                $this->doQuit(true);
                throw new \RuntimeException('NNTP server profile exceeds the metadata memory budget.');
            }
            $lines[] = rtrim($line, "\r\n");
        }

        $this->doQuit(true);
        throw new \RuntimeException('NNTP server profile response was truncated.');
    }

    /**
     * @param  array<string, mixed>|null  $responseMetadata
     *
     * @param-out array{response_code: int|null, response_text: string|null, response_text_basis: string, error_type: string|null} $responseMetadata
     *
     * @return list<string>
     */
    private function readFingerprintArticleHeaders(string $identifier, int $maximumBytes, ?array &$responseMetadata = null): array
    {
        $response = $this->_sendCommand('HEAD <'.$identifier.'>');
        $responseMetadata = $this->fingerprintResponseMetadata($response);
        if ($response !== 221) {
            throw in_array($response, [ResponseCode::NoSuchArticleId->value, ResponseCode::NoSuchArticleNumber->value], true)
                ? new NntpArticleMissingException('Article headers are unavailable.')
                : new \RuntimeException('Article headers are unavailable.');
        }

        $headers = [];
        $bytes = 0;
        while (($line = fgets($this->_socket, 16_385)) !== false) {
            if ($line === ".\r\n" || $line === ".\n") {
                return $headers;
            }
            if (! str_ends_with($line, "\n") || strlen($line) > 16_384) {
                $this->doQuit(true);
                throw new \RuntimeException('Article header line is truncated or oversized.');
            }
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $bytes += strlen($line);
            if ($bytes > $maximumBytes) {
                $this->doQuit(true);
                throw new \RuntimeException('Article headers exceed the metadata memory budget.');
            }
            $headers[] = $line;
        }

        $this->doQuit(true);
        throw new \RuntimeException('Article header response was truncated.');
    }

    /** @return array{response_code: int|null, response_text: string|null, response_text_basis: string, error_type: string|null} */
    private function fingerprintResponseMetadata(mixed $response): array
    {
        $responseText = null;
        if (is_numeric($response)) {
            try {
                $responseText = $this->_currentStatusResponse();
            } catch (\Throwable) {
                // Some client/test transports expose no text beyond the response code.
            }
        }

        return [
            'response_code' => is_numeric($response) ? (int) $response : null,
            'response_text' => $responseText,
            'response_text_basis' => 'net_nntp_current_status_response_trimmed_remainder',
            'error_type' => is_numeric($response) ? null : class_basename($response),
        ];
    }

    /**
     * Fetch one complete preview article while counting every BODY byte read, including failures.
     * The primary and optional alternate share the allowance; a partial BODY closes its connection.
     *
     * @return array{data: string|null, bytesDownloaded: int, error: string|null}
     */
    public function getBoundedArticleByMessageID(string $identifier, int $maximumBytes, bool $alternate = false): array
    {
        $bytes = 0;
        $raw = null;
        $error = null;
        try {
            $identifier = self::verificationMessageId($identifier, $maximumBytes);
        } catch (\RuntimeException $exception) {
            return ['data' => null, 'bytesDownloaded' => 0, 'error' => $exception->getMessage()];
        }
        try {
            $connected = $this->_checkConnection(false);
            if ($connected !== true) {
                throw new \RuntimeException('Unable to connect to usenet.');
            }
            $raw = $this->readBoundedPreviewBody($identifier, $maximumBytes, $bytes);
        } catch (\Exception) {
            $error = 'Preview article unavailable or incomplete.';
        }

        if ($raw === null && $alternate && $bytes < $maximumBytes) {
            $alt = null;
            try {
                $alt = $this->previewAlternateConnection();
                $connected = $this->_currentServer === $this->_configServer
                    ? $alt->doConnect($this->_configCompressedHeaders, true) : $alt->doConnect();
                if ($connected === true) {
                    $raw = $alt->readBoundedPreviewBody($identifier, $maximumBytes, $bytes);
                }
            } catch (\Exception) {
                $error = 'Preview article unavailable or incomplete.';
            } finally {
                try {
                    $alt?->doQuit();
                } catch (\Exception) {
                    // Cleanup must not discard the bytes already charged to this attempt.
                }
            }
        }
        if ($raw === null) {
            return ['data' => null, 'bytesDownloaded' => $bytes, 'error' => $error ?? 'Article unavailable.'];
        }
        try {
            $this->_yencService ??= app(YencService::class);
            [$data] = $this->_yencService->decodeWithFrame($raw);

            return ['data' => $data, 'bytesDownloaded' => $bytes, 'error' => null];
        } catch (\Exception) {
            return ['data' => null, 'bytesDownloaded' => $bytes, 'error' => 'Preview article could not be decoded.'];
        }
    }

    protected function previewAlternateConnection(): self
    {
        return new self;
    }

    private function readBoundedPreviewBody(string $identifier, int $maximumBytes, int &$bytes): string
    {
        $response = $this->_sendCommand('BODY <'.$identifier.'>');
        if ($response !== ResponseCode::BodyFollows->value) {
            throw new \RuntimeException('Preview article unavailable.');
        }
        $raw = '';
        try {
            while ($bytes < $maximumBytes) {
                $line = fgets($this->_socket, min(65536, $maximumBytes - $bytes + 1));
                if ($line === false) {
                    throw new \RuntimeException('Truncated preview article.');
                }
                $bytes += strlen($line);
                if ($line === ".\r\n" || $line === ".\n") {
                    return $raw;
                }
                if (! str_ends_with($line, "\n")) {
                    throw new \RuntimeException('Preview article exceeds byte or line limit.');
                }
                $raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            throw new \RuntimeException('Preview article exceeds byte budget.');
        } catch (\Exception $exception) {
            $this->doQuit();
            throw $exception;
        }
    }

    /**
     * Restart the NNTP connection if an error occurs in the selectGroup
     * function, if it does not restart display the error.
     *
     * @param  NNTPService  $nntp  Instance of class NNTPService.
     * @param  string  $group  Name of the group.
     * @param  bool  $comp  Use compression or not?
     * @return mixed On success : (array)  The group summary.
     *
     * @throws \Exception
     *                    On Failure : (object) DariusIII\NetNntp\Error.
     */
    public function dataError(NNTPService $nntp, string $group, bool $comp = true): mixed
    {
        // Try reconnecting. This uses another round of max retries.
        if ($nntp->reconnect($comp) !== true) {
            return $this->throwError('Unable to reconnect to usenet!');
        }

        // Try re-selecting the group.
        $data = $nntp->selectGroup($group);
        if (self::isError($data)) {
            $message = "Code {$data->getCode()}: {$data->getMessage()}\nSkipping group: {$group}";

            if ($this->_echo) {
                cli()->error($message);
            }
            $nntp->doQuit();
        }

        return $data;
    }

    /**
     * Split a string into lines of 510 chars ending with \r\n.
     * Usenet limits lines to 512 chars, with \r\n that leaves us 510.
     *
     * @param  string  $string  The string to split.
     * @param  bool  $compress  Compress the string with gzip?
     * @return string The split string.
     */
    protected function _splitLines(string $string, bool $compress = false): string
    {
        // Check if the length is longer than 510 chars.
        if (\strlen($string) > 510) {
            // If it is, split it @ 510 and terminate with \r\n.
            $string = chunk_split($string, 510, "\r\n");
        }

        // Compress the string if requested.
        return $compress ? gzdeflate($string, 4) : $string;
    }

    /**
     * Try to see if the NNTP server implements XFeature GZip Compression,
     * change the compression bool object if so.
     *
     * @param  bool  $secondTry  This is only used if enabling compression fails, the function will call itself to retry.
     * @return mixed On success : (bool)   True:  The server understood and compression is enabled.
     *               (bool)   False: The server did not understand, compression is not enabled.
     *               On failure : (object) DariusIII\NetNntp\Error.
     *
     * @throws \Exception
     */
    protected function _enableCompression(bool $secondTry = false): mixed
    {
        if ($this->_compressionEnabled) {
            return true;
        }
        if (! $this->_compressionSupported) {
            return false;
        }

        // Send this command to the usenet server.
        $response = $this->_sendCommand('XFEATURE COMPRESS GZIP');

        // Check if it's good.
        if (self::isError($response)) {
            $this->_compressionSupported = false;

            return $response;
        }
        if ($response !== 290) {
            if (! $secondTry) {
                // Retry.
                $this->cmdQuit();
                if ($this->_checkConnection()) {
                    return $this->_enableCompression(true);
                }
            }
            $msg = "Sent 'XFEATURE COMPRESS GZIP' to server, got '$response: ".$this->_currentStatusResponse()."'";

            $this->_compressionSupported = false;

            return false;
        }

        $this->_compressionEnabled = true;
        $this->_compressionSupported = true;

        return true;
    }

    /**
     * Override the parent's _getTextResponse to use our _getXFeatureTextResponse instead
     * of their _getTextResponse function since it is incompatible at decoding
     * headers when XFeature GZip compression is enabled server side.
     *
     * @return mixed Our overridden function's result when compression is enabled, otherwise the
     *               parent's. Either may be a DariusIII\NetNntp\Error when the server answers with
     *               an error; callers check it with NNTPService::isError().
     */
    public function _getTextResponse(): mixed
    {
        if ($this->_compressionEnabled &&
            isset($this->_currentStatusResponse[1]) &&
            stripos($this->_currentStatusResponse[1], 'COMPRESS=GZIP') !== false) {
            return $this->_getXFeatureTextResponse();
        }

        return parent::_getTextResponse();
    }

    /**
     * Read a compressed OVER/XOVER response.
     *
     * The server sends one zlib stream followed by ".\r\n". The stream is inflated as it
     * arrives and the response ends when zlib reports the end of the stream, so a ".\r\n"
     * that happens to appear inside the compressed bytes can't end it early.
     *
     * @return array<string, mixed>|string|NntpError On success : (array)  The headers.
     *                                               On failure : (object) DariusIII\NetNntp\Error.
     */
    protected function &_getXFeatureTextResponse(): array|string|NntpError
    {
        $socket = $this->_socket;
        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $parts = [];
        $bytesReceived = 0;
        $tail = '';
        $streamEnded = false;

        while (true) {
            $buffer = fread($socket, 65536);
            if ($buffer === false || $buffer === '') {
                if (stream_get_meta_data($socket)['timed_out'] || feof($socket)) {
                    $message = 'Error fetching data from usenet server while downloading OVER headers.';
                    $error = $this->throwError($this->failureMessage($message), 1000);

                    return $error;
                }

                continue;
            }
            $bytesReceived += \strlen($buffer);

            if ($streamEnded) {
                $tail .= $buffer;
            } else {
                $readBefore = inflate_get_read_len($inflate);
                $out = @inflate_add($inflate, $buffer, ZLIB_SYNC_FLUSH);
                if ($out === false) {
                    $message = 'Decompression of OVER headers failed.';
                    $error = $this->throwError($this->failureMessage($message), 1000);

                    return $error;
                }
                $parts[] = $out;
                $streamEnded = inflate_get_status($inflate) === ZLIB_STREAM_END;
                if ($streamEnded) {
                    $tail = substr($buffer, inflate_get_read_len($inflate) - $readBefore);
                }
            }

            if ($streamEnded && str_contains($tail, ".\r\n")) {
                break;
            }
        }

        if ($this->_echo && $bytesReceived > 10240) {
            cli()->primaryOver('Received '.round($bytesReceived / 1024).'KB from group ('.$this->group().').');
        }

        $headers = explode("\r\n", trim(implode('', $parts)));

        return $headers; // @phpstan-ignore return.type
    }

    /**
     * Check if the Message-ID has the required opening and closing brackets.
     *
     * @param  string  $messageID  The Message-ID with or without brackets.
     * @return string|false Message-ID with brackets or false if empty.
     */
    protected function _formatMessageID(string $messageID): string|false
    {
        $messageID = (string) $messageID;
        if ($messageID === '') {
            return false;
        }

        // Check if the first char is <, if not add it.
        if ($messageID[0] !== '<') {
            $messageID = ('<'.$messageID);
        }

        // Check if the last char is >, if not add it.
        if (! str_ends_with($messageID, '>')) {
            $messageID .= '>';
        }

        return $messageID;
    }

    /**
     * Download an article body (an article without the header).
     *
     * @return mixed|object|string
     *
     * @throws \Exception
     */
    protected function _getMessage(string $groupName, mixed $identifier, ?callable $onRead = null): mixed
    {
        // Make sure the requested group is already selected, if not select it.
        if ($this->group() !== $groupName) {
            // Select the group.
            $summary = $this->selectGroup($groupName);
            // If there was an error selecting the group, return an error object.
            if (self::isError($summary)) {
                return $summary;
            }
        }

        // Check if this is an article number or message-id.
        if (! is_numeric($identifier)) {
            // It's a message-id so check if it has the triangular brackets.
            $identifier = $this->_formatMessageID($identifier);
        }

        // Tell the news server we want the body of an article.
        $response = $this->_sendCommand('BODY '.$identifier);
        if (self::isError($response)) {
            return $response;
        }

        if ($response === ResponseCode::BodyFollows->value) {
            return $this->readBody($onRead);
        }

        return $this->_handleErrorResponse($response);
    }

    /** Read one dot-terminated BODY response and yEnc decode it. */
    protected function readBody(?callable $onRead = null): mixed
    {
        $body = $this->readRawBody($onRead);
        if (! is_string($body)) {
            return $body;
        }

        $this->_yencService ??= app(YencService::class);

        return $this->_yencService->decodeIgnore($body);
    }

    /** Read one dot-terminated BODY response, removing NNTP dot stuffing exactly once. */
    protected function readRawBody(?callable $onRead = null): mixed
    {
        $socket = $this->_socket;
        if ($onRead === null) {
            $body = '';
            while (true) {
                $first = fgets($socket, 4);
                if ($first === false) {
                    return $this->throwError(feof($socket)
                        ? 'End of stream! Connection lost?'
                        : 'Failed to read line from socket.', null);
                }
                if ($first === ".\r\n") {
                    return $body;
                }
                if (! str_ends_with($first, "\n")) {
                    break;
                }
                $body .= str_starts_with($first, '..') ? substr($first, 1) : $first;
            }
            $lineAndRest = $first;
            while (true) {
                $rest = stream_get_line($socket, PHP_INT_MAX, "\n.\r");
                if ($rest === false) {
                    return $this->throwError(feof($socket)
                        ? 'End of stream! Connection lost?'
                        : 'Failed to read line from socket.', null);
                }
                $lf = fgetc($socket);
                if ($lf === false) {
                    return $this->throwError(feof($socket)
                        ? 'End of stream! Connection lost?'
                        : 'Failed to read line from socket.', null);
                }
                if ($lf === "\n") {
                    $lineAndRest .= $rest;
                    break;
                }
                $lineAndRest .= $rest."\n.\r".$lf;
            }
            if (str_starts_with($lineAndRest, '..')) {
                $lineAndRest = substr($lineAndRest, 1);
            }

            return $body.str_replace("\n..", "\n.", $lineAndRest)."\n";
        }

        $body = '';
        $line = '';
        while (true) {
            $fragment = fgets($socket, 8192);
            if ($fragment === false) {
                return $this->throwError(feof($socket)
                    ? 'End of stream! Connection lost?'
                    : 'Failed to read line from socket.', null);
            }
            if (! $onRead()) {
                $this->doQuit();

                return $this->throwError('NNTP article read stopped after processing lock ownership was lost.', null);
            }
            // Most reads contain a complete line. Keep the accumulator for
            // fragmented lines, without copying/resetting it on ordinary reads.
            if ($line === '' && str_ends_with($fragment, "\n")) {
                if ($fragment === ".\r\n") {
                    return $body;
                }
                $body .= str_starts_with($fragment, '..') ? substr($fragment, 1) : $fragment;

                continue;
            }
            $line .= $fragment;
            if (! str_ends_with($line, "\n")) {
                continue;
            }
            if ($line === ".\r\n") {
                return $body;
            }
            $body .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            $line = '';
        }
    }

    /**
     * Check if we are still connected. Reconnect if not.
     *
     * @param  bool  $reSelectGroup  Select back the group after connecting?
     * @return mixed On success: (bool)   True;
     *
     * @throws \Exception
     *                    On failure: (object) DariusIII\NetNntp\Error
     */
    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        $currentGroup = $this->_currentGroup;
        // Check if we are connected.
        if (parent::_isConnected()) {
            $retVal = true;
        } else {
            $retVal = $this->reconnect();

            if ($retVal === true && $reSelectGroup) {
                $group = $this->selectGroup($currentGroup);
                if (self::isError($group)) {
                    $retVal = $group;
                }
            }
        }

        return $retVal;
    }

    /**
     * Verify NNTP error code and return error.
     *
     * @param  int  $response  NNTP Response code
     * @return object DariusIII\NetNntp\Error
     */
    protected function _handleErrorResponse(int $response): object
    {
        return match ($response) {
            // 381, RFC2980: 'More authentication information required'
            ResponseCode::AuthenticationContinue->value => $this->throwError('More authentication information required', $response, $this->_currentStatusResponse()),
            // 400, RFC977: 'Service discontinued'
            ResponseCode::DisconnectingForced->value => $this->throwError('Server refused connection', $response, $this->_currentStatusResponse()),
            // 411, RFC977: 'no such news group'
            ResponseCode::NoSuchGroup->value => $this->throwError('No such news group on server', $response, $this->_currentStatusResponse()),
            // 412, RFC2980: 'No news group current selected'
            ResponseCode::NoGroupSelected->value => $this->throwError('No news group current selected', $response, $this->_currentStatusResponse()),
            // 420, RFC2980: 'Current article number is invalid'
            ResponseCode::NoArticleSelected->value => $this->throwError('Current article number is invalid', $response, $this->_currentStatusResponse()),
            // 421, RFC977: 'no next article in this group'
            ResponseCode::NoNextArticle->value => $this->throwError('No next article in this group', $response, $this->_currentStatusResponse()),
            // 422, RFC977: 'no previous article in this group'
            ResponseCode::NoPreviousArticle->value => $this->throwError('No previous article in this group', $response, $this->_currentStatusResponse()),
            // 423, RFC977: 'No such article number in this group'
            ResponseCode::NoSuchArticleNumber->value => $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse()),
            // 430, RFC977: 'No such article found'
            ResponseCode::NoSuchArticleId->value => $this->throwError('No such article found', $response, $this->_currentStatusResponse()),
            // 435, RFC977: 'Article not wanted'
            ResponseCode::TransferUnwanted->value => $this->throwError('Article not wanted', $response, $this->_currentStatusResponse()),
            // 436, RFC977: 'Transfer failed - try again later'
            ResponseCode::TransferFailure->value => $this->throwError('Transfer failed - try again later', $response, $this->_currentStatusResponse()),
            // 437, RFC977: 'Article rejected - do not try again'
            ResponseCode::TransferRejected->value => $this->throwError('Article rejected - do not try again', $response, $this->_currentStatusResponse()),
            // 440, RFC977: 'posting not allowed'
            ResponseCode::PostingProhibited->value => $this->throwError('Posting not allowed', $response, $this->_currentStatusResponse()),
            // 441, RFC977: 'posting failed'
            ResponseCode::PostingFailure->value => $this->throwError('Posting failed', $response, $this->_currentStatusResponse()),
            // 481, RFC2980: 'Groups and descriptions unavailable'
            ResponseCode::XgtitleUnavailable->value => $this->throwError('Groups and descriptions unavailable', $response, $this->_currentStatusResponse()),
            // 482, RFC2980: 'Authentication rejected'
            ResponseCode::AuthenticationRejected->value => $this->throwError('Authentication rejected', $response, $this->_currentStatusResponse()),
            // 500, RFC977: 'Command not recognized'
            ResponseCode::UnknownCommand->value => $this->throwError('Command not recognized', $response, $this->_currentStatusResponse()),
            // 501, RFC977: 'Command syntax error'
            ResponseCode::SyntaxError->value => $this->throwError('Command syntax error', $response, $this->_currentStatusResponse()),
            // 502, RFC2980: 'No permission'
            ResponseCode::NotPermitted->value => $this->throwError('No permission', $response, $this->_currentStatusResponse()),
            // 503, RFC2980: 'Program fault - command not performed'
            ResponseCode::NotSupported->value => $this->throwError('Internal server error, function not performed', $response, $this->_currentStatusResponse()),
            // RFC4642: 'Can not initiate TLS negotiation'
            ResponseCode::TlsRefused->value => $this->throwError('Can not initiate TLS negotiation', $response, $this->_currentStatusResponse()),
            default => $this->throwError("Unexpected response: '{$this->_currentStatusResponse()}'", $response, $this->_currentStatusResponse()),
        };
    }

    /**
     * Connect to a NNTP server.
     *
     * @param  string|null  $host  (optional) The address of the NNTP-server to connect to, defaults to 'localhost'.
     * @param  mixed|null  $encryption  (optional) Use TLS/SSL on the connection?
     *                                  (string) 'tcp'                 => Use no encryption.
     *                                  'ssl', 'sslv3', 'tls' => Use encryption.
     *                                  (null)|(false) Use no encryption.
     * @param  int|null  $port  (optional) The port number to connect to, defaults to 119.
     * @param  int|null  $timeout  (optional) How many seconds to wait before giving up when connecting.
     * @param  int  $socketTimeout  (optional) How many seconds to wait before timing out the (blocked) socket.
     * @return mixed (bool) On success: True when posting allowed, otherwise false.
     *                      (object) On failure: DariusIII\NetNntp\Error
     */
    public function connect(?string $host = null, mixed $encryption = null, ?int $port = null, ?int $timeout = 15, int $socketTimeout = 120): mixed
    {
        if ($this->_isConnected()) {
            return $this->throwError('Already connected, disconnect first!', null);
        }
        // v1.0.x API
        if (is_int($encryption)) {
            trigger_error('You are using deprecated API v1.0 in DariusIII\NetNntp\Protocol\Client: connect() !', E_USER_NOTICE);
            $port = $encryption;
            $encryption = false;
        }
        if ($host === null) {
            $host = 'localhost';
        }
        // Choose transport based on encryption, and if no port is given, use default for that encryption.
        switch ($encryption) {
            case null:
            case false:
            case 'tcp':
                $transport = 'tcp';
                $port = $port ?? 119;
                break;
            case 'ssl':
            case 'tls':
                $transport = $encryption;
                $port = $port ?? 563;
                break;
            default:
                $message = '$encryption parameter must be either tcp, tls, ssl.';
                trigger_error($message, E_USER_ERROR);
        }
        // Attempt to connect to usenet.
        // Only create SSL context if using TLS/SSL transport
        $context = preg_match('/tls|ssl/', $transport)
            ? stream_context_create(streamSslContextOptions())
            : null;

        // A failed TLS handshake ("SSL: Handshake timed out") is reported as a PHP warning, which the
        // framework turns into an exception that kills the worker. Silence it and report the failure
        // through the normal NNTP error path below, keeping the warning text when errorString is empty.
        error_clear_last();
        $socket = @stream_socket_client(
            $transport.'://'.$host.':'.$port,
            $errorNumber,
            $errorString,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            if ($errorString === '') {
                $errorString = (string) (error_get_last()['message'] ?? '');
            }
            $message = "Connection to $transport://$host:$port failed.";
            if (preg_match('/tls|ssl/', $transport)) {
                $message .= ' Try disabling SSL/TLS, and/or try a different port.';
            }
            $message .= ' [ERROR '.$errorNumber.': '.$errorString.']';

            return $this->throwError($message);
        }
        // Store the socket resource as property.
        $this->_socket = $socket;
        $this->_socketTimeout = $socketTimeout ?: $this->_socketTimeout;
        // Set the socket timeout.
        stream_set_timeout($this->_socket, $this->_socketTimeout);
        // Retrieve the server's initial response.
        $response = $this->_getStatusResponse();
        if (self::isError($response)) {
            return $response;
        }
        switch ($response) {
            // 200, Posting allowed
            case ResponseCode::ReadyPostingAllowed->value:
                return true;
                // 201, Posting NOT allowed
            case ResponseCode::ReadyPostingProhibited->value:

                return false;
            default:
                return $this->_handleErrorResponse($response);
        }
    }

    /**
     * Test whether we are connected or not.
     *
     * @return bool true or false
     */
    public function _isConnected(): bool
    {
        return is_resource($this->_socket) && ! feof($this->_socket);
    }

    /**
     * Check if we are connected to the usenet server.
     */
    public function isConnected(): bool
    {
        return $this->_isConnected();
    }

    /**
     * Get the current server address.
     */
    public function getCurrentServer(): string
    {
        return $this->_currentServer;
    }

    /**
     * Get the current port.
     */
    public function getCurrentPort(): int|string
    {
        return $this->_currentPort;
    }

    /**
     * Check if posting is allowed on the current connection.
     */
    public function isPostingAllowed(): bool
    {
        return $this->_postingAllowed;
    }

    /**
     * Get the currently selected group.
     */
    public function getCurrentGroup(): string
    {
        return $this->_currentGroup;
    }

    /**
     * Check if compression is enabled.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->_compressionEnabled;
    }

    /**
     * Check if compression is supported.
     */
    public function isCompressionSupported(): bool
    {
        return $this->_compressionSupported;
    }
}
