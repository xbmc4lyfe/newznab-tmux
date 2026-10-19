<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NntpArticleMissingException;
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
    public function the_header_budget_applies_to_the_header_section(): void
    {
        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\nbody\r\n.\r\n");

        $this->expectExceptionMessage('Article headers exceed the metadata memory budget.');
        $nntp->getFingerprintFullArticleByMessageID('a@example', 2_000_000, 40);
    }

    #[Test]
    public function the_body_budget_applies_to_the_body_alone(): void
    {
        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\n12345678\r\n.\r\n");
        $this->assertSame("12345678\r\n", $nntp->getFingerprintFullArticleByMessageID('a@example', 10)['body']);

        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\n123456789\r\n.\r\n");
        $this->expectExceptionMessage('Article exceeds verification memory budget.');
        $nntp->getFingerprintFullArticleByMessageID('a@example', 10);
    }

    #[Test]
    public function a_truncated_article_is_rejected(): void
    {
        $nntp = self::connection("220 0 <a@example>\r\n".self::HEADERS."\r\nbody\r\n");

        $this->expectExceptionMessage('Truncated article response.');
        $nntp->getFingerprintFullArticleByMessageID('a@example');
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

    private string $status = '';

    public function __construct(mixed $stream)
    {
        $this->_socket = $stream;
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
