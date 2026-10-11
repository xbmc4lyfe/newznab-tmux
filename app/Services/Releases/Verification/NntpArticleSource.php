<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\NNTP\NNTPService;
use RuntimeException;

final class NntpArticleSource implements ArticleSource
{
    private bool $connected = false;

    public function __construct(private readonly NNTPService $nntp) {}

    public function fetch(string $messageId): string
    {
        if (! $this->connected) {
            if ($this->nntp->doConnect(false) !== true) {
                throw new RuntimeException('NNTP is unavailable.');
            }
            $this->connected = true;
        }

        return $this->nntp->verificationArticle($messageId, (int) config('release_verification.article_bytes'));
    }

    public function __destruct()
    {
        if ($this->connected) {
            $this->nntp->doQuit();
        }
    }
}
