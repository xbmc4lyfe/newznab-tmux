<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;

final class NntpFingerprintFullArticleTest extends TestCase
{
    private const HEADERS = "Message-ID: <a@example>\r\nSubject: test\r\n ..folded continuation\r\nUser-Agent: ngPost v4.16\r\n";

    #[Test]
    public function one_article_request_returns_the_same_headers_and_body_as_head_and_body(): void
    {
        $body = YencArticles::article('0123456789abcdef')."\r\n..dot-stuffed line\r\n";
        $pair = self::connection("221 0 <a@example>\r\n".self::HEADERS.".\r\n222 0 <a@example>\r\n".$body.".\r\n");
        $single = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\n".$body.".\r\n");

        $expected = $pair->getFingerprintArticleByMessageID('a@example');
        $actual = $single->getFingerprintFullArticleByMessageID('<a@example>');

        $this->assertSame(['ARTICLE <a@example>'], $single->commands);
        $this->assertSame($expected['headers'], $actual['headers']);
        $this->assertSame($expected['body'], $actual['body']);
        $this->assertStringContainsString("\r\n.dot-stuffed line\r\n", $actual['body']);
        $this->assertSame(220, $actual['article_response']['response_code']);
        $this->assertSame('0 <a@example>', $actual['article_response']['response_text']);
        $this->assertFalse($single->quit);
        $this->assertSame('', (string) stream_get_contents($single->stream()), 'The next response is left unread.');
    }

    #[Test]
    public function an_article_without_a_body_returns_its_headers_and_an_empty_body(): void
    {
        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS.".\r\n");

        $article = $nntp->getFingerprintFullArticleByMessageID('a@example');

        $this->assertCount(4, $article['headers']);
        $this->assertSame('', $article['body']);
    }

    /** @return array<string, array{int, bool, bool}> */
    public static function failures(): array
    {
        return [
            'no such article id' => [430, true, false],
            'no such article number' => [423, true, true],
            'authentication required' => [480, false, true],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function only_absence_responses_raise_the_missing_article_exception(int $code, bool $missing, bool $quits): void
    {
        $nntp = self::connection($code." status\r\n");

        try {
            $nntp->getFingerprintFullArticleByMessageID('a@example');
            $this->fail('Expected the request to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($missing, $exception instanceof NntpArticleMissingException);
        }
        $this->assertSame($quits, $nntp->quit);
    }

    #[Test]
    public function the_status_line_is_returned_for_the_identity_check(): void
    {
        $nntp = self::connection("220 0 <other@example>\r\n".self::HEADERS."\r\nbody\r\n.\r\n");

        $this->assertSame('0 <other@example>', $nntp->getFingerprintFullArticleByMessageID('a@example')['article_response']['response_text']);
    }

    #[Test]
    public function the_body_budget_applies_to_the_body_alone(): void
    {
        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\n12345678\r\n.\r\n");
        $this->assertSame("12345678\r\n", $nntp->getFingerprintFullArticleByMessageID('a@example', 10)['body']);
        $this->assertFalse($nntp->quit);
    }

    /** @return array<string, array{string, int, int, string}> */
    public static function abandonedResponses(): array
    {
        return [
            'header budget' => ["220 0 <a@example>\r\n".self::HEADERS."\r\nbody\r\n.\r\n", 2_000_000, 40, 'Article headers exceed the metadata memory budget.'],
            'oversized header line' => ["220 0 <a@example>\r\nX-Long: ".str_repeat('a', 16_400)."\r\n\r\nbody\r\n.\r\n", 2_000_000, 65_536, 'Article header line is truncated or oversized.'],
            'headers cut off' => ["220 0 <a@example>\r\n".self::HEADERS, 2_000_000, 65_536, 'Article header response was truncated.'],
            'body budget' => ["220 0 <a@example>\r\n".self::HEADERS."\r\n123456789\r\n.\r\n", 10, 65_536, 'Article exceeds verification memory budget.'],
            'body cut off' => ["220 0 <a@example>\r\n".self::HEADERS."\r\nbody\r\n", 2_000_000, 65_536, 'Truncated article response.'],
        ];
    }

    #[Test]
    #[DataProvider('abandonedResponses')]
    public function an_abandoned_response_drops_the_connection(string $script, int $bodyBytes, int $headerBytes, string $message): void
    {
        $nntp = self::connection($script);

        try {
            $nntp->getFingerprintFullArticleByMessageID('a@example', $bodyBytes, $headerBytes);
            $this->fail('Expected the request to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertTrue($nntp->quit, 'A connection left mid-response must not be reused.');
    }

    #[Test]
    public function the_alternate_provider_is_queried_directly_and_released(): void
    {
        $primary = self::connection('');
        $alternate = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\nbody\r\n.\r\n");
        $primary->alternate = $alternate;

        $article = $primary->getFingerprintFullArticleByMessageID('a@example', alternate: true);

        $this->assertSame("body\r\n", $article['body']);
        $this->assertSame([], $primary->commands);
        $this->assertSame(['ARTICLE <a@example>'], $alternate->commands);
        $this->assertTrue($alternate->quit);
    }

    #[Test]
    public function an_unreachable_alternate_provider_is_reported_as_unavailable(): void
    {
        $primary = self::connection('');
        $primary->alternate = self::connection('');
        $primary->alternate->connects = false;

        $this->expectException(NntpProviderUnavailableException::class);
        $primary->getFingerprintFullArticleByMessageID('a@example', alternate: true);
    }

    /**
     * A primary connection whose server replies with $script; each command consumes one status line.
     */
    private static function connection(string $script): ScriptedNntpConnection
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $script);
        rewind($stream);

        return new ScriptedNntpConnection($stream);
    }
}

final class ScriptedNntpConnection extends NNTPService
{
    /** @var list<string> */
    public array $commands = [];

    public bool $quit = false;

    public ?self $alternate = null;

    public bool $connects = true;

    private string $status = '';

    public function __construct(mixed $stream)
    {
        $this->_socket = $stream;
        $this->_configServer = 'primary.example';
        $this->_currentServer = 'primary.example';
        $this->_configCompressedHeaders = false;
    }

    protected function previewAlternateConnection(): NNTPService
    {
        return $this->alternate ?? throw new \LogicException('No alternate connection scripted.');
    }

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        return $this->connects;
    }

    public function stream(): mixed
    {
        return $this->_socket;
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        $this->commands[] = $cmd;
        $line = rtrim((string) fgets($this->_socket), "\r\n");
        $this->status = substr($line, 4);

        return (int) substr($line, 0, 3);
    }

    protected function _currentStatusResponse(): string
    {
        return $this->status;
    }

    public function doQuit(bool $force = false): mixed
    {
        $this->quit = true;

        return true;
    }
}
