<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NNTPService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NntpArticleMissingExceptionTest extends TestCase
{
    /** @return array<string, array{int, bool}> */
    public static function responses(): array
    {
        return [
            'no such article id' => [430, true],
            'no such article number' => [423, true],
            'authentication required' => [480, false],
            'service discontinued' => [400, false],
        ];
    }

    #[Test]
    #[DataProvider('responses')]
    public function only_absence_responses_raise_the_missing_article_exception(int $code, bool $missing): void
    {
        $nntp = new class($code) extends NNTPService
        {
            public function __construct(private readonly int $code) {}

            protected function _sendCommand(string $cmd): mixed
            {
                return $this->code;
            }

            protected function _currentStatusResponse(): string
            {
                return 'status';
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }
        };
        $readHeaders = new \ReflectionMethod(NNTPService::class, 'readFingerprintArticleHeaders');

        foreach ([
            static fn (): mixed => $nntp->verificationArticle('a@example', 1_000),
            static fn (): mixed => $readHeaders->invoke($nntp, 'a@example', 1_000),
        ] as $request) {
            try {
                $request();
                $this->fail('Expected the request to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($missing, $exception instanceof NntpArticleMissingException);
            }
        }
    }
}
