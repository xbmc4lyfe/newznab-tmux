<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NNTPService;
use App\Services\NNTP\VerificationPipelineRetryException;
use App\Services\Releases\Verification\ArticleBudgetExceeded;
use App\Services\Releases\Verification\ArticleIntegrityFailure;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\Yenc\PayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Tests\Fixtures\YencArticles;

final class NntpVerificationArticleSourceTest extends TestCase
{
    /** @var list<string> */
    private array $framerPaths = [];

    /** @return iterable<string, array{bool}> */
    public static function recoveryIdentities(): iterable
    {
        yield 'valid recovery' => [true];
        yield 'wrong recovery identity' => [false];
    }

    #[DataProvider('recoveryIdentities')]
    public function test_worker_death_preserves_the_offender_fence_and_recovers_the_unread_tail_in_php(bool $valid): void
    {
        $this->configureRecovery('native');
        $worker = $this->framerFixture('fwrite(STDOUT, "\\0"); feed(); exit(9);');
        config(['release_verification.rust_worker' => $worker]);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <wrong@example> body\r\nplausible\r\n.\r\n");
        $identity = $valid ? 'b@example' : 'wrong@example';
        fwrite($next[1], '222 0 <'.$identity."> body\r\nbee\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            self::assertFalse($nntp->isConnected());
            try {
                $source->fetch('a@example');
                self::fail('The worker failure must fence the unacknowledged offending response.');
            } catch (RuntimeException $error) {
                self::assertNotInstanceOf(VerificationPipelineRetryException::class, $error);
                self::assertSame(1, $nntp->connects);
            }
            if ($valid) {
                self::assertSame("bee\r\n", $source->fetchBounded('b@example', 64));
            } else {
                try {
                    $source->fetchBounded('b@example', 64);
                    self::fail('Recovery must validate the response identity.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('identity', $error->getMessage());
                }
            }
            self::assertSame(2, $nntp->connects);
            self::assertSame($worker, config('release_verification.rust_worker'));
            self::assertSame("BODY <a@example>\r\nBODY <b@example>\r\n", stream_get_contents($first[1]));
            stream_set_blocking($next[1], false);
            self::assertSame("BODY <b@example>\r\n", stream_get_contents($next[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    public function test_worker_death_on_a_later_feed_preserves_only_previously_acknowledged_bodies(): void
    {
        $this->configureRecovery('native');
        $marker = $this->framerPath();
        $worker = $this->framerFixture('fwrite(STDOUT, "\\0"); feed();'
            .'reply(["done" => false, "error" => null, "results" => [["index" => 0, "status" => "body", "length" => 5]]], "see\\r\\n");'
            .'file_put_contents('.var_export($marker, true).', "ack"); feed(); exit(9);');
        config(['release_verification.rust_worker' => $worker]);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $child = pcntl_fork();
        if ($child === 0) {
            fclose($first[0]);
            fclose($next[0]);
            fclose($next[1]);
            stream_set_timeout($first[1], 5);
            foreach (['c@example', 'a@example', 'b@example'] as $id) {
                if (fgets($first[1]) !== 'BODY <'.$id.">\r\n") {
                    exit(2);
                }
            }
            fwrite($first[1], "222 0 <c@example> body\r\nsee\r\n.\r\n");
            $deadline = hrtime(true) + 5_000_000_000;
            while (! is_file($marker)) {
                if (hrtime(true) >= $deadline) {
                    exit(3);
                }
                usleep(1000);
            }
            fwrite($first[1], "222 0 <wrong@example> body\r\nunacknowledged\r\n.\r\n");
            fclose($first[1]);
            exit(0);
        }
        self::assertGreaterThan(0, $child);
        fclose($first[1]);
        fwrite($next[1], "222 0 <b@example> body\r\nbee\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['c@example', 'a@example', 'b@example'], static function (): void {});
            self::assertSame("see\r\n", $source->fetch('c@example'));
            try {
                $source->fetch('a@example');
                self::fail('The incomplete feed must remain fenced.');
            } catch (RuntimeException $error) {
                self::assertNotInstanceOf(VerificationPipelineRetryException::class, $error);
                self::assertSame(1, $nntp->connects);
            }
            self::assertSame("bee\r\n", $source->fetchBounded('b@example', 64));
            self::assertSame(2, $nntp->connects);
            stream_set_blocking($next[1], false);
            self::assertSame("BODY <b@example>\r\n", stream_get_contents($next[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($next[1]);
            pcntl_waitpid($child, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function heartbeatErrors(): iterable
    {
        yield 'RuntimeException' => [false];
        yield 'Error' => [true];
    }

    #[DataProvider('heartbeatErrors')]
    public function test_source_startup_heartbeat_failure_never_falls_back_or_sends_requests(bool $error): void
    {
        $this->configureRecovery('native');
        $started = $this->framerPath();
        $worker = $this->framerFixture('file_put_contents('.var_export($started, true).', "started"); usleep(400000); fwrite(STDOUT, "\\0"); feed();');
        config(['release_verification.rust_worker' => $worker]);
        Container::getInstance()->instance('log', new NullLogger);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <a@example> body\r\naye\r\n.\r\n222 0 <b@example> body\r\nbee\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$pair[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        $cancelled = $error ? new \Error('Owner lost during startup.') : new RuntimeException('Owner lost during startup.');
        $fired = false;
        try {
            try {
                $source->prefetch(['a@example', 'b@example'], static function () use ($started, $cancelled, &$fired): void {
                    if (! $fired && is_file($started)) {
                        $fired = true;
                        throw $cancelled;
                    }
                });
                self::fail('A startup heartbeat failure must abort the source.');
            } catch (\Throwable $caught) {
                self::assertSame($cancelled, $caught);
            }
            self::assertTrue($fired);
            self::assertFalse($nntp->isConnected());
            stream_set_blocking($pair[1], false);
            self::assertSame('', stream_get_contents($pair[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_source_inflight_heartbeat_failure_propagates_the_exact_error_and_closes_the_socket(string $engine): void
    {
        $this->configureRecovery($engine);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($engine === 'rust') {
            fwrite($pair[1], "222 0 <a@example> body\r\naye\r\n.\r\n");
        }
        stream_set_blocking($pair[1], false);
        $nntp = new ReconnectingVerificationNntpHarness([$pair[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        $cancelled = new RuntimeException('Owner lost during pipeline.');
        $request = '';
        try {
            try {
                $source->prefetch(['a@example', 'b@example'], static function () use ($pair, $cancelled, &$request): void {
                    $request .= stream_get_contents($pair[1]);
                    if ($request !== '') {
                        throw $cancelled;
                    }
                });
                self::fail('An in-flight heartbeat failure must abort the source.');
            } catch (RuntimeException $caught) {
                self::assertSame($cancelled, $caught);
            }
            self::assertSame("BODY <a@example>\r\nBODY <b@example>\r\n", $request);
            self::assertFalse($nntp->isConnected());
            self::assertSame(1, $nntp->connects);
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    private function framerPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'verification-source-worker-');
        self::assertNotFalse($path);
        unlink($path);
        $this->framerPaths[] = $path;

        return $path;
    }

    private function framerFixture(string $body): string
    {
        $path = $this->framerPath();
        $source = '#!'.PHP_BINARY."\n<?php\n".<<<'PHP'
function exact(int $length): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread(STDIN, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') { exit(10); }
        $bytes .= $chunk;
    }
    return $bytes;
}
function feed(): array { return [ord(exact(1)), exact(unpack('N', exact(4))[1])]; }
function reply(array $metadata, string $body = ''): void {
    $json = json_encode($metadata, JSON_THROW_ON_ERROR);
    $wire = pack('N', strlen($json)).$json.$body;
    while ($wire !== '') { $n = fwrite(STDOUT, $wire); $wire = substr($wire, $n); }
}
$init = json_decode(exact(unpack('N', exact(4))[1]), true, flags: JSON_THROW_ON_ERROR);
PHP;
        file_put_contents($path, $source."\n".$body);
        chmod($path, 0700);

        return $path;
    }

    /** @return iterable<string, array{bool}> */
    public static function recoveryOperations(): iterable
    {
        yield 'STAT then BODY' => [true];
        yield 'BODY directly' => [false];
    }

    #[DataProvider('recoveryOperations')]
    public function test_a_damaged_prefetch_reconnects_before_the_next_stat_pass(bool $stat): void
    {
        config(['release_verification.article_bytes' => 64, 'release_verification.download_buffer_bytes' => 128,
            'release_verification.download_connections' => 1, 'release_verification.pipeline_window' => 2]);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <wrong@example> body\r\n");
        if ($stat) {
            fwrite($next[1], "223 0 <new@example> present\r\n");
        }
        $nntp = new class([$first[0], $next[0]]) extends NNTPService
        {
            public int $connects = 0;

            /** @param list<resource> $sockets */
            public function __construct(private array $sockets)
            {
                $this->_socketTimeout = 1;
            }

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                $this->connects++;
                $this->_socket = array_shift($this->sockets);

                return $this->isConnected();
            }

            public function doQuit(bool $force = false): mixed
            {
                if (is_resource($this->_socket)) {
                    fclose($this->_socket);
                }
                $this->_socket = null;

                return true;
            }
        };
        $source = new NntpArticleSource($nntp, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->prefetch(['old@example', 'old2@example'], static function (): void {});
            if ($stat) {
                $source->stat(['new@example'], static function (): void {});
            }
            fwrite($next[1], "222 0 <new@example> body\r\nbody\r\n.\r\n");
            $this->assertSame("body\r\n", $source->fetchBounded('new@example', 64));
            $this->assertSame(2, $nntp->connects);
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function engines(): iterable
    {
        yield 'native' => ['native'];
        yield 'rust' => ['rust'];
    }

    #[DataProvider('engines')]
    public function test_oversized_batch_classifies_only_the_offending_article_as_deterministic(string $engine): void
    {
        if ($engine === 'rust') {
            $binary = getenv('NNTMUX_VERIFICATION_FRAMER');
            if (! is_string($binary) || ! is_executable($binary)) {
                $this->markTestSkipped('Release-built Rust executable required.');
            }
            config(['release_verification.rust_worker' => $binary]);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <big@example> body\r\n".str_repeat('x', 20)."\r\n.\r\n222 0 <next@example> body\r\nbody\r\n.\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        try {
            $result = NNTPService::verificationArticleBatch([['connection' => $primary,
                'ids' => ['big@example', 'next@example']]], 16, 32, static function (): void {});
            $this->assertInstanceOf(ArticleBudgetExceeded::class, $result['big@example']);
            $this->assertInstanceOf(RuntimeException::class, $result['next@example']);
            $this->assertNotInstanceOf(ArticleBudgetExceeded::class, $result['next@example']);
        } finally {
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_healthy_trailing_articles_retry_once_after_the_oversized_pipeline_closes(string $engine): void
    {
        $this->configureRecovery($engine);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <big@example> body\r\n".str_repeat('x', 65)."\r\n.\r\n");
        fwrite($next[1], "222 0 <b@example> body\r\nbee\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['big@example', 'b@example', 'c@example'], static function (): void {});
            self::assertFalse($nntp->isConnected());
            try {
                $source->fetch('big@example');
                self::fail('Oversized article must stay deterministic.');
            } catch (ArticleBudgetExceeded) {
                self::assertSame(1, $nntp->connects);
            }
            self::assertSame("bee\r\n", $source->fetch('b@example'));
            fwrite($next[1], "222 0 <c@example> body\r\nsee\r\n.\r\n");
            self::assertSame("see\r\n", $source->fetch('c@example'));
            self::assertSame(2, $nntp->connects);
            self::assertSame("BODY <big@example>\r\nBODY <b@example>\r\nBODY <c@example>\r\n", stream_get_contents($first[1]));
            stream_set_blocking($next[1], false);
            self::assertSame("BODY <b@example>\r\nBODY <c@example>\r\n", stream_get_contents($next[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_bounded_recovery_renews_the_callers_heartbeat_while_a_body_is_incomplete(string $engine): void
    {
        $this->configureRecovery($engine);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <big@example> body\r\n".str_repeat('x', 65)."\r\n.\r\n");
        stream_set_blocking($next[1], false);
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        $request = '';
        $started = null;
        $completed = false;
        $waitingPulses = 0;
        try {
            $source->prefetch(['big@example', 'b@example'], static function (): void {});
            $body = $source->fetchBounded('b@example', 64, static function () use ($next, &$request, &$started, &$completed, &$waitingPulses): void {
                $request .= stream_get_contents($next[1]);
                if ($request === '') {
                    return;
                }
                self::assertSame("BODY <b@example>\r\n", $request);
                if ($started === null) {
                    $started = hrtime(true);
                    fwrite($next[1], "222 0 <b@example> body\r\nbe");
                } elseif (! $completed && hrtime(true) - $started >= 1_000_000_000) {
                    $waitingPulses++;
                    $completed = true;
                    fwrite($next[1], "e\r\n.\r\n");
                }
            });
            self::assertSame("bee\r\n", $body);
            self::assertTrue($completed);
            self::assertSame(1, $waitingPulses);
            self::assertSame(2, $nntp->connects);
            self::assertTrue($nntp->isConnected());
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_bounded_recovery_cancellation_closes_the_pending_socket_without_another_retry(string $engine): void
    {
        $this->configureRecovery($engine);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <big@example> body\r\n".str_repeat('x', 65)."\r\n.\r\n");
        stream_set_blocking($next[1], false);
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]], timeout: 5);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        $cancelled = new RuntimeException('Owner lease lost.');
        $request = '';
        try {
            $source->prefetch(['big@example', 'b@example'], static function (): void {});
            try {
                $source->fetchBounded('b@example', 64, static function () use ($next, $cancelled, &$request): void {
                    $request .= stream_get_contents($next[1]);
                    if ($request !== '') {
                        throw $cancelled;
                    }
                });
                self::fail('Recovery must observe owner cancellation.');
            } catch (RuntimeException $error) {
                self::assertSame($cancelled, $error);
            }
            self::assertSame("BODY <b@example>\r\n", $request);
            self::assertSame(2, $nntp->connects);
            self::assertFalse($nntp->isConnected());
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_recovery_never_downgrades_response_identity_validation(string $engine): void
    {
        $this->configureRecovery($engine);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <wrong@example> body\r\n");
        fwrite($next[1], "222 0 <also-wrong@example> body\r\nvalid-looking\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            foreach (['a@example', 'b@example'] as $id) {
                try {
                    $source->fetch($id);
                    self::fail('An uncorrelated body must never be returned.');
                } catch (RuntimeException) {
                    self::assertFalse($nntp->isConnected());
                }
            }
            self::assertSame(2, $nntp->connects);
            stream_set_blocking($next[1], false);
            self::assertSame("BODY <b@example>\r\n", stream_get_contents($next[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    #[DataProvider('engines')]
    public function test_transport_failure_fences_the_offending_request_and_recovers_only_its_unread_tail(string $engine): void
    {
        $this->configureRecovery($engine);
        $first = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $next = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($first[1], "222 0 <a@example> body\r\npartial\r\n");
        stream_socket_shutdown($first[1], STREAM_SHUT_WR);
        fwrite($next[1], "222 0 <b@example> body\r\nokay\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$first[0], $next[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            try {
                $source->fetch('a@example');
                self::fail('Truncated article must propagate without retry.');
            } catch (RuntimeException $error) {
                self::assertNotInstanceOf(ArticleBudgetExceeded::class, $error);
                self::assertSame(1, $nntp->connects);
            }
            self::assertSame("okay\r\n", $source->fetch('b@example'));
            self::assertSame(2, $nntp->connects);
            stream_set_blocking($next[1], false);
            self::assertSame("BODY <b@example>\r\n", stream_get_contents($next[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($first[1]);
            fclose($next[1]);
        }
    }

    public function test_setup_failure_retries_primary_once_and_a_failed_reconnect_stays_bounded(): void
    {
        $this->configureRecovery('native');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <a@example> body\r\nokay\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([false, $pair[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            self::assertSame("okay\r\n", $source->fetch('a@example'));
            self::assertSame(2, $nntp->connects);
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($pair[1]);
        }
        $nntp = new ReconnectingVerificationNntpHarness([false, false]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        $source->prefetch(['a@example', 'b@example'], static function (): void {});
        try {
            $source->fetch('a@example');
            self::fail('Failed recovery must propagate.');
        } catch (RuntimeException) {
            self::assertSame(2, $nntp->connects);
        }
    }

    #[DataProvider('engines')]
    public function test_prefetched_missing_articles_are_typed_and_never_reissued(string $engine): void
    {
        $this->configureRecovery($engine);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "430 absent\r\n423 absent\r\n222 0 <c@example> body\r\nokay\r\n.\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$pair[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example', 'c@example'], static function (): void {});
            foreach (['a@example', 'b@example'] as $id) {
                try {
                    $source->fetch($id);
                    self::fail('Missing article must propagate.');
                } catch (NntpArticleMissingException) {
                    self::assertTrue($nntp->isConnected());
                }
            }
            self::assertSame("okay\r\n", $source->fetch('c@example'));
            stream_set_blocking($pair[1], false);
            self::assertSame("BODY <a@example>\r\nBODY <b@example>\r\nBODY <c@example>\r\n", stream_get_contents($pair[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    public function test_clearing_prefetched_articles_at_a_new_batch_forces_fresh_transport(): void
    {
        $this->configureRecovery('native');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <a@example> body\r\nstale\r\n.\r\n430 absent\r\n");
        $nntp = new ReconnectingVerificationNntpHarness([$pair[0]]);
        $source = new NntpArticleSource($nntp, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            $source->clearPrefetched();
            fwrite($pair[1], "222 0 <a@example> body\r\nfresh\r\n.\r\n222 0 <b@example> body\r\nnew\r\n.\r\n");
            self::assertSame("fresh\r\n", $source->fetch('a@example'));
            self::assertSame("new\r\n", $source->fetch('b@example'));
            stream_set_blocking($pair[1], false);
            self::assertSame("BODY <a@example>\r\nBODY <b@example>\r\nBODY <a@example>\r\nBODY <b@example>\r\n", stream_get_contents($pair[1]));
        } finally {
            unset($source);
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    public function test_non_utf8_opaque_ids_fall_back_before_any_pipeline_command_is_sent(): void
    {
        config(['release_verification.rust_worker' => '/unsupported-json-framer']);
        Container::getInstance()->instance('log', new NullLogger);
        $id = "opaque-\xff@example";
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], '222 0 <'.$id."> body\r\nokay\r\n.\r\n");
        $nntp = new PipelineSocketNntpHarness($pair[0]);
        try {
            $results = NNTPService::verificationArticleBatch([['connection' => $nntp, 'ids' => [$id]]],
                64, 64, static function (): void {});
            self::assertSame("okay\r\n", $results[$id]);
            self::assertSame('BODY <'.$id.">\r\n", fgets($pair[1]));
        } finally {
            $nntp->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_failed_rust_startup_logs_once_per_worker_without_sensitive_context(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{mixed, string|Stringable, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        Container::getInstance()->instance('log', $logger);
        config(['release_verification.rust_worker' => '/missing-private-worker-'.bin2hex(random_bytes(8))]);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $nntp = new PipelineSocketNntpHarness($pair[0]);
        try {
            foreach (['first', 'second'] as $body) {
                fwrite($pair[1], "222 0 <private@example> body\r\n".$body."\r\n.\r\n");
                $results = NNTPService::verificationArticleBatch([['connection' => $nntp, 'ids' => ['private@example']]],
                    64, 64, static function (): void {});
                self::assertSame($body."\r\n", $results['private@example']);
            }
            self::assertCount(1, $logger->records);
            self::assertSame(['warning', 'Rust verification framer startup failed; using PHP framing.', []], $logger->records[0]);
        } finally {
            $nntp->closeFixture();
            fclose($pair[1]);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function unavailableLoggers(): iterable
    {
        yield 'standalone container' => [false];
        yield 'broken logger' => [true];
    }

    #[DataProvider('unavailableLoggers')]
    public function test_rust_fallback_survives_an_unavailable_application_logger(bool $broken): void
    {
        if ($broken) {
            Container::getInstance()->instance('log', new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    throw new RuntimeException('Private logger setup failure.');
                }
            });
        }
        $log = tempnam(sys_get_temp_dir(), 'framer-warning-');
        $previous = ini_set('error_log', $log);
        config(['release_verification.rust_worker' => '/missing-private-worker-'.bin2hex(random_bytes(8))]);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $nntp = new PipelineSocketNntpHarness($pair[0]);
        try {
            fwrite($pair[1], "222 0 <private@example> body\r\nsecret\r\n.\r\n");
            $results = NNTPService::verificationArticleBatch([['connection' => $nntp, 'ids' => ['private@example']]],
                64, 64, static function (): void {});
            self::assertSame("secret\r\n", $results['private@example']);
            $warning = file_get_contents($log);
            self::assertStringContainsString('using PHP framing', $warning);
            self::assertStringNotContainsString('private', $warning);
            self::assertStringNotContainsString('secret', $warning);
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
            $nntp->closeFixture();
            fclose($pair[1]);
        }
    }

    private function configureRecovery(string $engine): void
    {
        config(['release_verification.article_bytes' => 64, 'release_verification.download_buffer_bytes' => 192,
            'release_verification.download_connections' => 1, 'release_verification.pipeline_window' => 3]);
        if ($engine === 'rust') {
            $binary = getenv('NNTMUX_VERIFICATION_FRAMER');
            if (! is_string($binary) || ! is_executable($binary)) {
                self::markTestSkipped('Release-built Rust executable required.');
            }
            config(['release_verification.rust_worker' => $binary]);
        }
    }

    public function test_stat_pipelines_correlate_statuses_without_treating_availability_as_body_evidence(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "223 0 <a@example> exists\r\n430 absent\r\n223 0 <c@example> exists\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        try {
            $result = NNTPService::verificationArticleBatch([['connection' => $primary,
                'ids' => ['a@example', 'b@example', 'c@example']]], 1, 3, static function (): void {}, 'stat');
            $this->assertSame('', $result['a@example']);
            $this->assertInstanceOf(RuntimeException::class, $result['b@example']);
            $this->assertSame('', $result['c@example']);
            $this->assertSame("STAT <a@example>\r\n", fgets($pair[1]));
            $this->assertSame("STAT <b@example>\r\n", fgets($pair[1]));
            $this->assertSame("STAT <c@example>\r\n", fgets($pair[1]));
        } finally {
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_full_stat_pass_cannot_publish_when_any_unsampled_article_is_missing(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "223 0 <a@example> exists\r\n430 absent\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 1024, allowAlternateFallback: false);
        $verifier = new SpotCheckVerifier($source,
            ['stat_all_articles' => true, 'spot_check_articles' => 1, 'spot_check_bytes' => 1024, 'spot_check_article_bytes' => 1024]);
        try {
            $result = $verifier->verify(['identity' => 'test', 'expected_files' => 1, 'files' => [['key' => 'file',
                'totalparts' => 2, 'segments' => [['number' => 1, 'messageid' => 'a@example', 'bytes' => 5],
                    ['number' => 2, 'messageid' => 'b@example', 'bytes' => 5]]]]], static function (): void {});
            $this->assertFalse($result->passed());
            $this->assertStringContainsString('unavailable', $result->reason);
            stream_set_blocking($pair[1], false);
            $this->assertSame("STAT <a@example>\r\nSTAT <b@example>\r\n", stream_get_contents($pair[1]));
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_prefetched_sample_cannot_bypass_its_remaining_transfer_budget(): void
    {
        config(['release_verification.article_bytes' => 64, 'release_verification.download_buffer_bytes' => 128,
            'release_verification.download_connections' => 1, 'release_verification.pipeline_window' => 2]);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <a@example> body\r\nabcdefgh\r\n.\r\n222 0 <b@example> body\r\nabcdefgh\r\n.\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->prefetch(['a@example', 'b@example'], static function (): void {});
            $this->assertSame("abcdefgh\r\n", $source->fetchBounded('a@example', 64));
            $this->expectException(ArticleBudgetExceeded::class);
            $source->fetchBounded('b@example', 4);
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function sessionFailures(): iterable
    {
        yield 'authentication required' => [480];
        yield 'connection closing' => [400];
        yield 'encryption required' => [483];
        yield 'access denied' => [502];
    }

    #[DataProvider('sessionFailures')]
    public function test_session_failure_reply_closes_transport_for_reauthentication(int $status): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], $status." session requires reset\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            try {
                $source->fetchBounded('next@example', 64);
                $this->fail('A session failure cannot verify an article.');
            } catch (RuntimeException) {
                $this->assertSame("BODY <next@example>\r\n", fgets($pair[1]));
                $this->assertFalse($primary->isConnected(), 'The next candidate must reconnect and authenticate.');
            }
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_body_status_timeout_closes_an_open_transport_before_session_reuse(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($pair[0], 0, 1000);
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            try {
                $source->fetchBounded('late@example', 64);
                $this->fail('An unanswered BODY command must not verify an article.');
            } catch (RuntimeException) {
                $this->assertSame("BODY <late@example>\r\n", fgets($pair[1]));
                $this->assertFalse($primary->isConnected(), 'A timed-out socket cannot be reused for another candidate.');
            }
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_an_oversized_bounded_article_reports_a_typed_budget_failure(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <big@example> body\r\n".str_repeat("0123456789abcdef\r\n", 8).".\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->fetchBounded('big@example', 32);
            $this->fail('An article above the bounded budget must not be returned.');
        } catch (ArticleBudgetExceeded $error) {
            $this->assertSame('Article exceeds verification memory budget.', $error->getMessage());
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_a_truncated_response_within_budget_stays_a_transport_failure(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <cut@example> body\r\npartial");
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 1024, allowAlternateFallback: false);
        try {
            $source->fetchBounded('cut@example', 1024);
            $this->fail('A truncated response must not be returned.');
        } catch (RuntimeException $error) {
            $this->assertNotInstanceOf(ArticleBudgetExceeded::class, $error);
            $this->assertSame('Verification article line is truncated or oversized.', $error->getMessage());
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_a_dot_stuffed_partial_read_that_exactly_fills_the_budget_stays_a_transport_failure(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // 33 wire bytes unstuff to exactly the 32-byte budget, then the connection ends.
        fwrite($pair[1], "222 0 <stuffed@example> body\r\n..".str_repeat('a', 31));
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->fetchBounded('stuffed@example', 32);
            $this->fail('A truncated response must not be returned.');
        } catch (RuntimeException $error) {
            $this->assertNotInstanceOf(ArticleBudgetExceeded::class, $error);
            $this->assertSame('Verification article line is truncated or oversized.', $error->getMessage());
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_a_dot_stuffed_line_that_continues_past_the_budget_is_oversized(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // The unstuffed body is 34 bytes; the capped read sees exactly 32 of them before more arrive.
        fwrite($pair[1], "222 0 <stuffed@example> body\r\n..".str_repeat('a', 31)."\r\n.\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->fetchBounded('stuffed@example', 32);
            $this->fail('An article above the bounded budget must not be returned.');
        } catch (ArticleBudgetExceeded $error) {
            $this->assertFalse($primary->isConnected(), 'An unread remainder closes the session.');
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function smallRemainingBudgets(): iterable
    {
        yield 'one-byte budget, two body bytes then close' => ['aa', 1, true];
        yield 'nothing left after a full line, one more byte' => ["0123456789abcdef\r\nx", 18, true];
        yield 'nothing left, bare terminator prefix then close' => ["0123456789abcdef\r\n.", 18, false];
        yield 'nothing left, terminator CR then close' => ["0123456789abcdef\r\n.\r", 18, false];
        yield 'within budget then close' => ['abc', 8, false];
    }

    #[DataProvider('smallRemainingBudgets')]
    public function test_partial_lines_below_the_minimum_read_are_classified_by_their_body_bytes(string $body, int $budget, bool $oversized): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <small@example> body\r\n".$body);
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            $source->fetchBounded('small@example', $budget);
            $this->fail('A partial response must not be returned.');
        } catch (RuntimeException $error) {
            $this->assertSame($oversized, $error instanceof ArticleBudgetExceeded, $error->getMessage());
            $this->assertSame($oversized ? 'Article exceeds verification memory budget.' : 'Verification article line is truncated or oversized.',
                $error->getMessage());
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_a_line_past_a_remaining_budget_of_exactly_the_line_limit_is_oversized(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($pair[1], false);
        $source = null;
        $primary = new PipelineSocketNntpHarness($pair[0]);
        try {
            fwrite($pair[1], "222 0 <wide@example> body\r\n");
            $source = new NntpArticleSource($primary, maximumBytes: 65534, allowAlternateFallback: false);
            $writer = str_repeat('a', 70000)."\r\n.\r\n";
            // Feed the long line while the reader consumes it.
            $reader = static fn () => $source->fetchBounded('wide@example', 65534);
            $pending = $writer;
            $error = null;
            $pid = pcntl_fork();
            if ($pid === 0) {
                stream_set_blocking($pair[1], true);
                fwrite($pair[1], $pending);
                posix_kill(getmypid(), SIGKILL);
            }
            try {
                $reader();
            } catch (\Throwable $caught) {
                $error = $caught;
            }
            pcntl_waitpid($pid, $status);
            $this->assertInstanceOf(ArticleBudgetExceeded::class, $error, $error?->getMessage() ?? 'returned');
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_article_unavailable_response_keeps_the_session_aligned_for_the_next_request(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "430 missing\r\n222 0 <next@example> body\r\nnext-body\r\n.\r\n");
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $source = new NntpArticleSource($primary, maximumBytes: 64, allowAlternateFallback: false);
        try {
            try {
                $source->fetchBounded('missing@example', 64);
                $this->fail('An unavailable article must remain unverified.');
            } catch (RuntimeException) {
                $this->assertTrue($primary->isConnected());
            }
            $this->assertSame("next-body\r\n", $source->fetchBounded('next@example', 64));
            $this->assertSame("BODY <missing@example>\r\n", fgets($pair[1]));
            $this->assertSame("BODY <next@example>\r\n", fgets($pair[1]));
        } finally {
            unset($source);
            $primary->closeFixture();
            fclose($pair[1]);
        }
    }

    public function test_bounded_sample_fetch_honours_remaining_budget_and_never_retries_another_provider(): void
    {
        $primary = new VerificationNntpHarness("too-large-body\r\n.\r\n");
        $alternate = new VerificationNntpHarness("alternate-body\r\n.\r\n");
        $source = new NntpArticleSource($primary, $alternate, maximumBytes: 1048576, allowAlternateFallback: false);
        try {
            $source->fetchBounded('sample@example', 4);
            $this->fail('A sample cannot exceed its remaining transfer budget.');
        } catch (RuntimeException) {
            $this->assertSame(['BODY <sample@example>'], $primary->commands);
            $this->assertSame([], $alternate->commands);
            $this->assertSame([], $alternate->connections);
        }
        $this->expectException(RuntimeException::class);
        $source->fetchBounded('second@example', 0);
    }

    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['nntmux_nntp' => ['alternate_article_fallback' => true],
            'release_verification' => ['article_bytes' => 1048576]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        foreach ($this->framerPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        Container::setInstance($this->previousContainer);
    }

    /** @return iterable<string, array{string, string}> */
    public static function pipelineFrames(): iterable
    {
        foreach (['native', 'rust'] as $engine) {
            foreach (['valid', 'identity', 'oversized'] as $fault) {
                yield $engine.' '.$fault => [$fault, $engine];
            }
        }
    }

    #[DataProvider('pipelineFrames')]
    public function test_actual_socket_pipelines_are_dispatched_concurrently_and_keep_bounded_response_correlation(string $fault, string $engine): void
    {
        if ($engine === 'rust') {
            $binary = getenv('NNTMUX_VERIFICATION_FRAMER');
            if (! is_string($binary) || ! is_executable($binary)) {
                $this->markTestSkipped('Set NNTMUX_VERIFICATION_FRAMER to the release-built Rust executable.');
            }
            config(['release_verification.rust_worker' => $binary]);
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Socket server fixture needs pcntl.');
        }
        $pairs = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP),
            stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)];
        $ids = [['a@example', 'missing@example', 'b@example'], ['c@example', 'd@example']];
        $child = pcntl_fork();
        if ($child === 0) {
            foreach ($pairs as $index => $pair) {
                fclose($pair[0]);
                stream_set_timeout($pair[1], 5);
                foreach ($ids[$index] as $id) {
                    if (fgets($pair[1]) !== 'BODY <'.$id.">\r\n") {
                        exit(2);
                    }
                }
            }
            // Neither connection gets a response until all pipeline commands reached both sockets.
            foreach ($pairs as $index => $pair) {
                foreach ($ids[$index] as $id) {
                    if ($id === 'missing@example') {
                        fwrite($pair[1], "430 missing\r\n");
                    } else {
                        $responseId = $index === 0 && $fault === 'identity' ? 'wrong@example' : $id;
                        $body = $index === 0 && $fault === 'oversized' ? str_repeat('x', 65) : '..'.$id;
                        @fwrite($pair[1], '222 0 <'.$responseId."> body\r\n".$body."\r\n.\r\n");
                    }
                }
                fclose($pair[1]);
            }
            exit(0);
        }
        $this->assertGreaterThan(0, $child);
        $connections = [];
        foreach ($pairs as $pair) {
            fclose($pair[1]);
            $connections[] = new PipelineSocketNntpHarness($pair[0]);
        }
        $beats = 0;
        try {
            $results = NNTPService::verificationArticleBatch([
                ['connection' => $connections[0], 'ids' => $ids[0]], ['connection' => $connections[1], 'ids' => $ids[1]],
            ], 64, 320, static function () use (&$beats): void {
                $beats++;
            });
            $this->assertSame(".c@example\r\n", $results['c@example']);
            $this->assertSame(".d@example\r\n", $results['d@example']);
            if ($fault === 'valid') {
                $this->assertSame(".a@example\r\n", $results['a@example']);
                $this->assertInstanceOf(RuntimeException::class, $results['missing@example']);
                $this->assertSame(".b@example\r\n", $results['b@example']);
            } else {
                foreach ($ids[0] as $id) {
                    $this->assertInstanceOf(RuntimeException::class, $results[$id]);
                }
                $this->assertFalse($connections[0]->isConnected());
            }
            $this->assertGreaterThan(0, $beats);
        } finally {
            foreach ($connections as $connection) {
                $connection->closeFixture();
            }
            pcntl_waitpid($child, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public function test_prefetched_primary_semantic_mismatch_uses_corrected_alternate_before_checkpointing(): void
    {
        config(['release_verification.article_bytes' => 1024, 'release_verification.download_buffer_bytes' => 4096,
            'release_verification.download_connections' => 1, 'release_verification.pipeline_window' => 2]);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $first = YencArticles::part('hell', 'movie.mkv', 8, 1, 1, 2);
        $tail = YencArticles::part('tail', 'movie.mkv', 8, 2, 5, 2);
        $bad = YencArticles::part('hell', 'movie.mkv', 8, 2, 1, 2);
        $child = pcntl_fork();
        if ($child === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 5);
            if (fgets($pair[1]) !== "BODY <first@example>\r\n" || fgets($pair[1]) !== "BODY <tail@example>\r\n") {
                exit(2);
            }
            fwrite($pair[1], "222 0 <first@example> body\r\n".$bad."\r\n.\r\n222 0 <tail@example> body\r\n".$tail."\r\n.\r\n");
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        $primary = new PipelineSocketNntpHarness($pair[0]);
        $alternate = new VerificationNntpHarness($first."\r\n.\r\n");
        $source = new NntpArticleSource($primary, $alternate);
        $this->assertSame(2, $source->batchSize());
        $verifier = new PayloadVerifier($source,
            ['scratch_bytes' => 1048576, 'free_bytes' => 0, 'article_bytes' => 1024, 'bytes_per_second' => 0, 'par2_binary' => 'par2']);
        $scratch = sys_get_temp_dir().'/prefetched-copy-'.bin2hex(random_bytes(8)).'/candidate';
        $manifest = ['expected_files' => 1, 'files' => [['key' => 'source', 'subject' => 'movie.mkv', 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'first@example', 'bytes' => 4],
                ['number' => 2, 'messageid' => 'tail@example', 'bytes' => 4]]]]];
        try {
            $result = $verifier->verify($manifest, $scratch, static function (): void {});
            $this->assertTrue($result->passed(), $result->reason);
            $this->assertSame('helltail', file_get_contents($scratch.'/data/movie.mkv'));
            $this->assertSame(['BODY <first@example>'], $alternate->commands);
            $this->assertSame(1, VerifiedArticle::decode(file_get_contents($scratch.'/articles/'.hash('sha256', 'first@example')))->part);
            $this->assertTrue($verifier->verify($manifest, $scratch, static function (): void {})->passed());
            $this->assertSame(['BODY <first@example>'], $alternate->commands, 'Valid resumed cache must not download again.');
        } finally {
            $primary->closeFixture();
            pcntl_waitpid($child, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($scratch);
            rmdir(dirname($scratch));
        }
    }

    public function test_verified_fetch_decodes_once_and_cached_resume_revalidates_with_the_same_decoder(): void
    {
        $decoder = new class implements PayloadDecoder
        {
            public int $calls = 0;

            public function decode(string $payload): string
            {
                $this->calls++;

                return (new PhpPayloadDecoder)->decode($payload);
            }
        };
        $yenc = new YencService($decoder);
        $primary = new VerificationNntpHarness(YencArticles::article('hello')."\r\n.\r\n");
        $source = new NntpArticleSource($primary, decoder: $yenc);
        $verifier = new PayloadVerifier($source,
            ['scratch_bytes' => 1048576, 'free_bytes' => 0, 'article_bytes' => 1048576, 'bytes_per_second' => 0, 'par2_binary' => 'par2'],
            decoder: $yenc);
        $root = sys_get_temp_dir().'/single-decode-'.bin2hex(random_bytes(8));
        $manifest = ['expected_files' => 1, 'files' => [['key' => 'file', 'subject' => 'fixture file.bin', 'totalparts' => 1,
            'segments' => [['number' => 1, 'messageid' => 'once@example', 'bytes' => 5]]]]];
        try {
            $first = $verifier->verify($manifest, $root.'/candidate', static function (): void {});
            $this->assertSame('complete', $first->verdict, $first->reason);
            $this->assertSame(1, $decoder->calls, 'Network integrity and file verification must share the decoded result.');
            $second = $verifier->verify($manifest, $root.'/candidate', static function (): void {});
            $this->assertSame('complete', $second->verdict, $second->reason);
            $this->assertSame(2, $decoder->calls, 'A resumed raw checkpoint must still be decoded and revalidated.');
            $this->assertSame(['BODY <once@example>'], $primary->commands);
            $this->assertSame('hello', file_get_contents($root.'/candidate/data/fixture file.bin'));
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }

    public function test_primary_connect_failure_fetches_raw_body_from_alternate_without_group_selection(): void
    {
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness("..leading dot\r\n.\r\nNEXT\r\n");
        $source = new NntpArticleSource($primary, $alternate);
        $this->assertSame(".leading dot\r\n", $source->fetch('test@example'));
        $this->assertSame([[false, false]], $primary->connections);
        $this->assertSame([[false, true]], $alternate->connections);
        $this->assertSame([], $primary->commands);
        $this->assertSame(['BODY <test@example>'], $alternate->commands);
        $this->assertSame("NEXT\r\n", $alternate->nextLine());
    }

    public function test_primary_article_missing_falls_back_but_valid_primary_articles_remain_preferred(): void
    {
        $primary = new VerificationNntpHarness("primary\r\n.\r\n", [true], [430, 222]);
        $alternate = new VerificationNntpHarness("alternate\r\n.\r\n");
        $source = new NntpArticleSource($primary, $alternate);
        $this->assertSame("alternate\r\n", $source->fetch('missing@example'));
        $this->assertSame("primary\r\n", $source->fetch('present@example'));
        $this->assertSame(['BODY <missing@example>', 'BODY <present@example>'], $primary->commands);
        $this->assertSame(['BODY <missing@example>'], $alternate->commands);
        $this->assertSame([[false, false]], $primary->connections);
    }

    public function test_enabled_alternate_is_never_called_when_primary_body_succeeds(): void
    {
        $primary = new VerificationNntpHarness("primary\r\n.\r\n");
        $alternate = new VerificationNntpHarness("unused\r\n.\r\n");
        $this->assertSame("primary\r\n", (new NntpArticleSource($primary, $alternate))->fetch('present@example'));
        $this->assertSame([], $alternate->connections);
        $this->assertSame([], $alternate->commands);
    }

    /** @return iterable<string, array{bool}> */
    public static function primaryFailures(): iterable
    {
        yield 'connect failure' => [false];
        yield 'missing article' => [true];
    }

    #[DataProvider('primaryFailures')]
    public function test_disabled_fallback_never_connects_or_reads_alternate(bool $connects): void
    {
        config(['nntmux_nntp.alternate_article_fallback' => false, 'nntmux_nntp.use_alternate_nntp_server' => true]);
        $primary = new VerificationNntpHarness('', [$connects], [430]);
        $alternate = new VerificationNntpHarness("unused\r\n.\r\n");
        try {
            (new NntpArticleSource($primary, $alternate))->fetch('missing@example');
            $this->fail('Primary failure must remain unavailable when fallback is disabled.');
        } catch (RuntimeException) {
            $this->assertSame([], $alternate->connections);
            $this->assertSame([], $alternate->commands);
        }
    }

    #[DataProvider('primaryFailures')]
    public function test_all_provider_failures_remain_unavailable(bool $alternateConnects): void
    {
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness('', [$alternateConnects], [430]);
        try {
            (new NntpArticleSource($primary, $alternate))->fetch('missing@example');
            $this->fail('Neither provider can supply this article.');
        } catch (RuntimeException) {
            $this->assertSame([[false, true]], $alternate->connections);
            $this->assertSame($alternateConnects ? ['BODY <missing@example>'] : [], $alternate->commands);
        }
    }

    public function test_alternate_body_uses_the_same_exact_byte_limit_and_dot_unstuffing(): void
    {
        config(['release_verification.article_bytes' => 6]);
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness("..abc\r\n.\r\n");
        $this->assertSame(".abc\r\n", (new NntpArticleSource($primary, $alternate))->fetch('test@example'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function rejectedAlternateBodies(): iterable
    {
        yield 'over byte limit' => ["123456\r\n.\r\n", 7];
        yield 'missing line terminator' => ['truncated', 128];
        yield 'missing BODY terminator' => ["truncated\r\n", 128];
        yield 'over line limit' => [str_repeat('x', 65536)."\r\n.\r\n", 1048576];
    }

    #[DataProvider('rejectedAlternateBodies')]
    public function test_alternate_cannot_bypass_body_limits_or_truncation_checks(string $wire, int $limit): void
    {
        config(['release_verification.article_bytes' => $limit]);
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness($wire);
        try {
            (new NntpArticleSource($primary, $alternate))->fetch('test@example');
            $this->fail('An unsafe alternate BODY must not be returned.');
        } catch (RuntimeException) {
            $this->assertSame(['BODY <test@example>'], $alternate->commands);
            $this->assertGreaterThanOrEqual(1, $alternate->quits);
        }
    }

    public function test_corrupt_primary_checksum_uses_valid_alternate_without_group_selection(): void
    {
        $good = YencArticles::article('payload');
        $bad = preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', $good);
        $primary = new VerificationNntpHarness($bad."\r\n.\r\n");
        $alternate = new VerificationNntpHarness($good."\r\n.\r\n");
        $raw = (new NntpArticleSource($primary, $alternate))->fetch('test@example');
        $this->assertSame('payload', VerifiedArticle::decode($raw)->bytes);
        $this->assertSame(['BODY <test@example>'], $primary->commands);
        $this->assertSame(['BODY <test@example>'], $alternate->commands);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function semanticProviderCopies(): iterable
    {
        yield 'wrong part then corrected alternate' => [false, true, true];
        yield 'wrong total then corrected alternate' => [true, true, true];
        yield 'wrong part with disabled alternate' => [false, false, true];
        yield 'wrong total with disabled alternate' => [true, false, true];
        yield 'both providers have wrong part' => [false, true, false];
        yield 'both providers have wrong total' => [true, true, false];
    }

    #[DataProvider('semanticProviderCopies')]
    public function test_checksum_valid_wrong_identity_can_use_only_a_semantically_valid_configured_alternate(bool $wrongTotal, bool $enabled, bool $corrected): void
    {
        config(['nntmux_nntp.alternate_article_fallback' => $enabled]);
        $first = YencArticles::part('hell', 'movie.mkv', 8, 1, 1, 2);
        $good = YencArticles::part('tail', 'movie.mkv', 8, 2, 5, 2);
        $bad = YencArticles::part('tail', 'movie.mkv', 8, $wrongTotal ? 2 : 1, 5, $wrongTotal ? 3 : 2);
        $this->assertSame('tail', VerifiedArticle::decode($bad)->bytes);
        $primary = new VerificationNntpHarness($first."\r\n.\r\n".$bad."\r\n.\r\n");
        $alternate = new VerificationNntpHarness(($corrected ? $good : $bad)."\r\n.\r\n");
        $verifier = new PayloadVerifier(new NntpArticleSource($primary, $alternate),
            ['scratch_bytes' => 1048576, 'free_bytes' => 0, 'article_bytes' => 1048576, 'bytes_per_second' => 0, 'par2_binary' => 'par2']);
        $scratch = sys_get_temp_dir().'/semantic-copy-'.bin2hex(random_bytes(8)).'/candidate';
        $manifest = ['expected_files' => 1, 'files' => [['key' => 'source', 'subject' => 'movie.mkv', 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'first@example', 'bytes' => 4],
                ['number' => 2, 'messageid' => 'tail@example', 'bytes' => 4]]]]];
        try {
            $result = $verifier->verify($manifest, $scratch, static function (): void {});
            $this->assertSame($enabled && $corrected, $result->passed(), $result->reason);
            $this->assertSame(['BODY <first@example>', 'BODY <tail@example>'], $primary->commands);
            $this->assertSame($enabled ? ['BODY <tail@example>'] : [], $alternate->commands);
            $cachePath = $scratch.'/articles/'.hash('sha256', 'tail@example');
            if ($result->passed()) {
                $this->assertSame('helltail', file_get_contents($scratch.'/data/movie.mkv'));
                $this->assertSame(2, VerifiedArticle::decode(file_get_contents($cachePath))->part);
                $this->assertSame(2, VerifiedArticle::decode(file_get_contents($cachePath))->totalParts);
            } else {
                $this->assertSame('unknown', $result->verdict);
                $this->assertFileDoesNotExist($cachePath);
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($scratch);
            rmdir(dirname($scratch));
        }
    }

    public function test_both_corrupt_provider_checksums_remain_retryable_and_never_return_raw(): void
    {
        $bad = preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', YencArticles::article('payload'));
        $primary = new VerificationNntpHarness($bad."\r\n.\r\n");
        $alternate = new VerificationNntpHarness($bad."\r\n.\r\n");
        try {
            (new NntpArticleSource($primary, $alternate))->fetch('test@example');
            $this->fail('Neither corrupt copy can establish article integrity.');
        } catch (ArticleIntegrityFailure) {
            $this->assertSame(['BODY <test@example>'], $primary->commands);
            $this->assertSame(['BODY <test@example>'], $alternate->commands);
        }
    }

    public function test_corrupt_primary_checksum_does_not_use_disabled_alternate(): void
    {
        config(['nntmux_nntp.alternate_article_fallback' => false]);
        $bad = preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', YencArticles::article('payload'));
        $alternate = new VerificationNntpHarness(YencArticles::article('payload')."\r\n.\r\n");
        try {
            (new NntpArticleSource(new VerificationNntpHarness($bad."\r\n.\r\n"), $alternate))->fetch('test@example');
            $this->fail('A corrupt primary article must not be returned.');
        } catch (ArticleIntegrityFailure) {
            $this->assertSame([], $alternate->connections);
            $this->assertSame([], $alternate->commands);
        }
    }

    public function test_corrupt_alternate_checksum_is_still_rejected_by_integrity_decoder(): void
    {
        $raw = preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', YencArticles::article('payload'));
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness($raw."\r\n.\r\n");
        $this->expectException(ArticleIntegrityFailure::class);
        (new NntpArticleSource($primary, $alternate))->fetch('test@example');
    }

    public function test_successful_alternate_keeps_valid_yenc_framing_for_decoder(): void
    {
        $raw = YencArticles::article('payload');
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness($raw."\r\n.\r\n");
        $article = (new NntpArticleSource($primary, $alternate))->fetch('test@example');
        $this->assertStringStartsWith('=ybegin', $article);
        $this->assertSame('payload', VerifiedArticle::decode($article)->bytes);
    }

    public function test_closed_primary_reader_is_not_reused_and_alternate_connection_is_reused(): void
    {
        config(['release_verification.article_bytes' => 7]);
        $primary = new VerificationNntpHarness("oversized-body\r\n.\r\n", [true, false]);
        $alternate = new VerificationNntpHarness("ok\r\n.\r\nagain\r\n.\r\n");
        $source = new NntpArticleSource($primary, $alternate);
        $this->assertSame("ok\r\n", $source->fetch('first@example'));
        $this->assertSame("again\r\n", $source->fetch('second@example'));
        $this->assertSame([[false, false], [false, false]], $primary->connections);
        $this->assertSame([[false, true]], $alternate->connections);
        $this->assertSame(['BODY <first@example>'], $primary->commands);
        $this->assertSame(['BODY <first@example>', 'BODY <second@example>'], $alternate->commands);
    }

    public function test_transport_exceptions_on_primary_connect_or_body_can_use_alternate(): void
    {
        foreach ([true, false] as $duringConnect) {
            $error = new \ErrorException('Fixture transport failed.');
            $primary = new VerificationNntpHarness('', $duringConnect ? [$error] : [true], $duringConnect ? [222] : [$error]);
            $alternate = new VerificationNntpHarness("ok\r\n.\r\n");
            $this->assertSame("ok\r\n", (new NntpArticleSource($primary, $alternate))->fetch('test@example'));
            $this->assertGreaterThanOrEqual(1, $primary->quits);
        }
    }

    public function test_alternate_transport_exception_is_a_retryable_source_failure(): void
    {
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness('', [new \ErrorException('Fixture transport failed.')]);
        $this->expectException(RuntimeException::class);
        (new NntpArticleSource($primary, $alternate))->fetch('test@example');
    }

    public function test_primary_cleanup_failure_does_not_prevent_alternate_fetch(): void
    {
        $primary = new VerificationNntpHarness('', [false]);
        $primary->quitFailure = new \ErrorException('Fixture cleanup failed.');
        $alternate = new VerificationNntpHarness("ok\r\n.\r\n");
        $this->assertSame("ok\r\n", (new NntpArticleSource($primary, $alternate))->fetch('test@example'));
        $this->assertSame([[false, true]], $alternate->connections);
    }

    public function test_provider_connections_are_closed_when_the_source_is_released(): void
    {
        $primary = new VerificationNntpHarness('', [true], [430]);
        $alternate = new VerificationNntpHarness("ok\r\n.\r\n");
        $source = new NntpArticleSource($primary, $alternate);
        $this->assertSame("ok\r\n", $source->fetch('test@example'));
        unset($source);
        $this->assertSame(1, $primary->quits);
        $this->assertSame(1, $alternate->quits);
    }

    public function test_tiny_byte_limit_can_read_empty_body_terminator_without_returning_payload(): void
    {
        config(['release_verification.article_bytes' => 1]);
        $primary = new VerificationNntpHarness('', [false]);
        $alternate = new VerificationNntpHarness(".\r\n");
        $this->assertSame('', (new NntpArticleSource($primary, $alternate))->fetch('test@example'));
    }

    public function test_nonpositive_article_limit_is_rejected_before_any_provider_connection(): void
    {
        config(['release_verification.article_bytes' => 0]);
        $primary = new VerificationNntpHarness('');
        $alternate = new VerificationNntpHarness('');
        try {
            (new NntpArticleSource($primary, $alternate))->fetch('test@example');
            $this->fail('A nonpositive byte limit must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame([], $primary->connections);
            $this->assertSame([], $alternate->connections);
        }
    }

    public function test_invalid_verification_request_does_not_connect_to_either_provider(): void
    {
        $primary = new VerificationNntpHarness('');
        $alternate = new VerificationNntpHarness('');
        try {
            (new NntpArticleSource($primary, $alternate))->fetch("bad\r\nGROUP alt.test");
            $this->fail('Invalid message IDs must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame([], $primary->connections);
            $this->assertSame([], $alternate->connections);
            $this->assertSame([], $primary->commands);
            $this->assertSame([], $alternate->commands);
        }
    }
}

class PipelineSocketNntpHarness extends NNTPService
{
    /** @param resource $socket */
    public function __construct($socket)
    {
        $this->_socket = $socket;
        $this->_socketTimeout = 5;
    }

    public function closeFixture(): void
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return $this->isConnected();
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->closeFixture();

        return true;
    }
}

/** Real bounded BODY reader with in-memory transport and fixture connection outcomes. */
final class VerificationNntpHarness extends NNTPService
{
    /** @var list<array{bool, bool}> */
    public array $connections = [];

    /** @var list<string> */
    public array $commands = [];

    public int $quits = 0;

    public ?\Exception $quitFailure = null;

    /** @param list<bool|\Exception> $connectResults
     * @param  list<int|\Exception>  $responses
     */
    public function __construct(string $wire, private array $connectResults = [true], private array $responses = [222])
    {
        $this->_socket = fopen('php://memory', 'w+');
        fwrite($this->_socket, $wire);
        rewind($this->_socket);
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections[] = [$compression, $alternate];

        $result = array_shift($this->connectResults) ?? true;
        if ($result instanceof \Exception) {
            throw $result;
        }

        return $result;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        $this->commands[] = $cmd;

        $result = array_shift($this->responses) ?? 222;
        if ($result instanceof \Exception) {
            throw $result;
        }

        return $result;
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quits++;
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;
        if ($this->quitFailure !== null) {
            $error = $this->quitFailure;
            $this->quitFailure = null;
            throw $error;
        }

        return true;
    }

    public function nextLine(): string|false
    {
        return fgets($this->_socket);
    }
}

/** Replaces connection setup only; framing, socket writes and reads use NNTPService. */
final class ReconnectingVerificationNntpHarness extends NNTPService
{
    public int $connects = 0;

    /** @param list<resource|false> $sockets */
    public function __construct(private array $sockets, int $timeout = 1)
    {
        $this->_socketTimeout = $timeout;
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connects++;
        $this->_socket = array_shift($this->sockets) ?: null;

        return $this->isConnected();
    }

    public function doQuit(bool $force = false): mixed
    {
        if (is_resource($this->_socket)) {
            fclose($this->_socket);
        }
        $this->_socket = null;

        return true;
    }
}
