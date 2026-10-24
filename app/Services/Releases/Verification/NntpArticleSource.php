<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\NNTP\NNTPService;
use App\Services\NNTP\VerificationPipelineRetryException;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use Exception;
use FFI;
use RuntimeException;
use Throwable;

final class NntpArticleSource implements ArticleSource
{
    public const string FFI_CDEF = 'intptr_t nntmux_w22_article_source_enums_log_requests(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Retain PHP unless every paired common/uncommon mean and p99 gate passes.
    private static bool $useRustFfi = false;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private bool $connected = false;

    private bool $alternateConnected = false;

    /** @var array<int, NNTPService> */
    private array $connections = [];

    /** @var array<string, string|RuntimeException> */
    private array $prefetched = [];

    public function __construct(private readonly NNTPService $nntp, private ?NNTPService $alternate = null,
        private readonly ?YencService $decoder = null, private readonly ?int $maximumBytes = null,
        private readonly bool $allowAlternateFallback = true) {}

    public function batchSize(): int
    {
        return self::batchSizing(
            (int) config('release_verification.download_buffer_bytes', 67108864),
            (int) config('release_verification.article_bytes'),
            (int) config('release_verification.download_connections', 1),
            (int) config('release_verification.pipeline_window', 1),
        );
    }

    public static function batchSizing(int $bufferBytes, int $articleBytes, int $connections, int $window): int
    {
        return (self::$useRustFfi ? self::batchSizingNative($bufferBytes, $articleBytes, $connections, $window) : null)
            ?? self::batchSizingPhp($bufferBytes, $articleBytes, $connections, $window);
    }

    public static function batchSizingPhp(int $bufferBytes, int $articleBytes, int $connections, int $window): int
    {
        $frames = max(1, intdiv(max(1, $bufferBytes), max(1, $articleBytes)));
        $connections = min($frames, max(1, min(8, $connections)));
        $window = min(max(1, intdiv($frames, $connections)), max(1, min(16, $window)));

        return $connections * $window;
    }

    public static function batchSizingNative(int $bufferBytes, int $articleBytes, int $connections, int $window): int
    {
        $result = self::computeNative(0, [$bufferBytes, $articleBytes, $connections, $window]);

        return is_int($result) ? $result : self::batchSizingPhp($bufferBytes, $articleBytes, $connections, $window);
    }

    /** @return array{connections: int, window: int} */
    public static function prefetchSizing(int $count, int $connections): array
    {
        return self::$useRustFfi ? self::prefetchSizingNative($count, $connections) : self::prefetchSizingPhp($count, $connections);
    }

    /**
     * @return array{connections: int, window: int}
     *
     * @throws \DivisionByZeroError
     */
    public static function prefetchSizingPhp(int $count, int $connections): array
    {
        $connections = min($count, max(1, min(8, $connections)));

        return ['connections' => $connections, 'window' => (int) ceil($count / $connections)];
    }

    /**
     * @return array{connections: int, window: int}
     *
     * @throws \DivisionByZeroError
     */
    public static function prefetchSizingNative(int $count, int $connections): array
    {
        $result = self::computeNative(1, [$count, $connections]);
        if (is_array($result) && is_int($result['connections'] ?? null) && is_int($result['window'] ?? null)) {
            return ['connections' => $result['connections'], 'window' => $result['window']];
        }

        return self::prefetchSizingPhp($count, $connections);
    }

    public static function articleByteCap(int $articleBytes, ?int $maximumBytes): int
    {
        return self::$useRustFfi ? self::articleByteCapNative($articleBytes, $maximumBytes) : self::articleByteCapPhp($articleBytes, $maximumBytes);
    }

    public static function articleByteCapPhp(int $articleBytes, ?int $maximumBytes): int
    {
        return min($articleBytes, $maximumBytes ?? PHP_INT_MAX);
    }

    public static function articleByteCapNative(int $articleBytes, ?int $maximumBytes): int
    {
        $result = self::computeNative(2, [$articleBytes, $maximumBytes]);

        return is_int($result) ? $result : self::articleByteCapPhp($articleBytes, $maximumBytes);
    }

    public static function bufferByteCap(int $maximumBytes, int $bufferBytes): int
    {
        return self::$useRustFfi ? self::bufferByteCapNative($maximumBytes, $bufferBytes) : self::bufferByteCapPhp($maximumBytes, $bufferBytes);
    }

    public static function bufferByteCapPhp(int $maximumBytes, int $bufferBytes): int
    {
        return max($maximumBytes, $bufferBytes);
    }

    public static function bufferByteCapNative(int $maximumBytes, int $bufferBytes): int
    {
        $result = self::computeNative(10, [$maximumBytes, $bufferBytes]);

        return is_int($result) ? $result : self::bufferByteCapPhp($maximumBytes, $bufferBytes);
    }

    /** @param list<int|null> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        try {
            $input = serialize($arguments);
            $ffi = RustPipelineLibrary::resolveDomain('wave22-article-source-sizing', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w22_article_source_enums_log_requests($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    public function clearPrefetched(): void
    {
        $this->prefetched = [];
    }

    /** @param list<string> $ids */
    public function prefetch(array $ids, callable $heartbeat): void
    {
        $this->clearPrefetched();
        $ids = array_values(array_unique(array_map(fn (string $id): string => NNTPService::verificationMessageId($id, $this->maximumArticleBytes()), $ids)));
        if ($ids === [] || $this->batchSize() === 1) {
            return;
        }
        if (count($ids) > $this->batchSize()) {
            throw new RuntimeException('Too many articles in a verification prefetch.');
        }
        $maximumBytes = $this->maximumArticleBytes();
        $window = self::prefetchSizing(count($ids), (int) config('release_verification.download_connections', 1))['window'];
        $batches = [];
        if (! $this->connected || ! $this->nntp->isConnected()) {
            try {
                if ($this->nntp->doConnect(false) !== true) {
                    throw new RuntimeException('Primary verification connection is unavailable.');
                }
                $this->connected = true;
            } catch (Exception) {
                $this->disconnect($this->nntp);
                $this->connected = false;
                foreach ($ids as $id) {
                    $this->prefetched[$id] = new VerificationPipelineRetryException('Primary verification connection is unavailable.');
                }

                return;
            }
        }
        try {
            if (! $this->nntp->verificationPipelineAvailable()) {
                return;
            }
        } catch (Exception) {
            return;
        }
        foreach (array_chunk($ids, $window) as $index => $batchIds) {
            $heartbeat();
            $connection = $index === 0 ? $this->nntp : ($this->connections[$index] ??= new NNTPService);
            try {
                if ($index !== 0 && ! $connection->isConnected() && $connection->doConnect(false) !== true) {
                    throw new RuntimeException('Primary verification connection is unavailable.');
                }
                if (! $connection->verificationPipelineAvailable()) {
                    return;
                }
                if ($index === 0) {
                    $this->connected = true;
                }
                $batches[] = ['connection' => $connection, 'ids' => $batchIds];
            } catch (Exception) {
                $this->disconnect($connection);
                foreach ($batchIds as $id) {
                    $this->prefetched[$id] = new VerificationPipelineRetryException('Primary verification connection is unavailable.');
                }
            }
        }
        try {
            $this->prefetched += NNTPService::verificationArticleBatch($batches, $maximumBytes,
                self::bufferByteCap($maximumBytes, (int) config('release_verification.download_buffer_bytes', 67108864)), $heartbeat);
        } finally {
            $this->connected = $this->nntp->isConnected();
        }
    }

    /** STAT is extra availability evidence; it never replaces the sampled BODY checks.
     * @param  list<string>  $ids
     */
    public function stat(array $ids, callable $heartbeat): void
    {
        if ($ids === []) {
            return;
        }
        if (count($ids) > 16) {
            throw new RuntimeException('Too many verification STAT requests.');
        }
        if (! $this->connected || ! $this->nntp->isConnected()) {
            if ($this->nntp->doConnect(false) !== true) {
                throw new RuntimeException('Primary verification connection is unavailable.');
            }
            $this->connected = true;
        }
        if (! $this->nntp->verificationPipelineAvailable()) {
            throw new RuntimeException('Raw verification connection is unavailable.');
        }
        $ids = array_map(static fn (string $id): string => NNTPService::verificationMessageId($id, 1), $ids);
        try {
            $results = NNTPService::verificationArticleBatch([['connection' => $this->nntp, 'ids' => $ids]],
                1, count($ids), $heartbeat, 'stat');
        } finally {
            $this->connected = $this->nntp->isConnected();
        }
        foreach ($ids as $id) {
            if (! array_key_exists($id, $results) || $results[$id] instanceof RuntimeException) {
                throw $results[$id] ?? new RuntimeException('Verification STAT response is unavailable.');
            }
        }
    }

    public function fetch(string $messageId): string
    {
        $fetched = $this->fetchFromProviders($messageId);

        return $fetched instanceof FetchedArticle ? $fetched->raw : $fetched;
    }

    public function fetchBounded(string $messageId, int $remainingBytes, ?callable $heartbeat = null): string
    {
        if ($remainingBytes < 1) {
            throw new RuntimeException('Spot-check transfer budget is exhausted.');
        }
        $fetched = $this->fetchFromProviders($messageId, maximumBytes: $remainingBytes, heartbeat: $heartbeat);

        return $fetched instanceof FetchedArticle ? $fetched->raw : $fetched;
    }

    /** @param callable(VerifiedArticle): void $validate */
    public function fetchValidated(string $messageId, callable $validate): string
    {
        return $this->fetchVerified($messageId, $validate)->raw;
    }

    /** @param callable(VerifiedArticle): void $validate */
    public function fetchVerified(string $messageId, callable $validate): FetchedArticle
    {
        $fetched = $this->fetchFromProviders($messageId, $validate);
        if (! $fetched instanceof FetchedArticle) {
            throw new RuntimeException('Verified article result is unavailable.');
        }

        return $fetched;
    }

    /** @param (callable(VerifiedArticle): void)|null $validate */
    private function fetchFromProviders(string $messageId, ?callable $validate = null, ?int $maximumBytes = null, ?callable $heartbeat = null): string|FetchedArticle
    {
        $maximumBytes = min($this->maximumArticleBytes(), $maximumBytes ?? PHP_INT_MAX);
        $messageId = NNTPService::verificationMessageId($messageId, $maximumBytes);
        try {
            $retryPrefetch = false;
            if (array_key_exists($messageId, $this->prefetched)) {
                $raw = $this->prefetched[$messageId];
                unset($this->prefetched[$messageId]);
                if ($raw instanceof VerificationPipelineRetryException) {
                    $retryPrefetch = true;
                } elseif ($raw instanceof RuntimeException) {
                    throw $raw;
                } elseif (strlen($raw) > $maximumBytes) {
                    throw new ArticleBudgetExceeded('Article exceeds verification memory budget.');
                }

                if (! $retryPrefetch) {
                    return $this->checkIntegrity($raw, $validate);
                }
            }
            if (! $this->connected || ! $this->nntp->isConnected()) {
                if ($this->nntp->doConnect(false) !== true) {
                    throw new RuntimeException('NNTP is unavailable.');
                }
                $this->connected = true;
            }

            if ($retryPrefetch) {
                // The prefetch has fully drained/closed before returning. Retry just this request
                // once, retaining the pipeline parser's response identity fence on the fresh BODY.
                $results = NNTPService::verificationArticleBatch([['connection' => $this->nntp, 'ids' => [$messageId]]],
                    $maximumBytes, $maximumBytes, $heartbeat ?? static function (): void {}, 'body', false);
                $raw = $results[$messageId];
                if ($raw instanceof RuntimeException) {
                    throw $raw;
                }

                return $this->checkIntegrity($raw, $validate);
            }

            return $this->checkIntegrity($this->nntp->verificationArticle($messageId, $maximumBytes), $validate);
        } catch (Exception $error) {
            if ($this->connected && $error instanceof RuntimeException) {
                $this->connected = $this->nntp->isConnected();
            } else {
                $this->disconnect($this->nntp);
                $this->connected = false;
            }
            if (! $this->allowAlternateFallback || ! (bool) config('nntmux_nntp.alternate_article_fallback', false)) {
                throw $error instanceof RuntimeException ? $error : new RuntimeException('NNTP is unavailable.', previous: $error);
            }
        }

        $this->alternate ??= new NNTPService;
        try {
            if (! $this->alternateConnected) {
                if ($this->alternate->doConnect(false, true) !== true) {
                    throw new RuntimeException('Alternate NNTP is unavailable.');
                }
                $this->alternateConnected = true;
            }

            return $this->checkIntegrity($this->alternate->verificationArticle($messageId, $maximumBytes), $validate);
        } catch (Exception $error) {
            if ($this->alternateConnected && $error instanceof RuntimeException) {
                $this->alternateConnected = $this->alternate->isConnected();
            } else {
                $this->disconnect($this->alternate);
                $this->alternateConnected = false;
            }
            throw $error instanceof RuntimeException ? $error : new RuntimeException('Alternate NNTP is unavailable.', previous: $error);
        }
    }

    /** @param (callable(VerifiedArticle): void)|null $validate */
    private function checkIntegrity(string $raw, ?callable $validate = null): string|FetchedArticle
    {
        try {
            $article = VerifiedArticle::decode($raw, $this->decoder);
            if ($validate !== null) {
                $validate($article);

                return new FetchedArticle($raw, $article);
            }
        } catch (ArticleIntegrityFailure $error) {
            throw $error;
        } catch (RuntimeException $error) {
            if ($validate !== null) {
                throw $error;
            }
            // Encoding and identity validation remain the verifier's responsibility.
        }

        return $raw;
    }

    private function disconnect(NNTPService $connection): void
    {
        try {
            $connection->doQuit();
        } catch (Exception) {
            // A failed transport cleanup must not prevent trying the alternate provider.
        }
    }

    private function maximumArticleBytes(): int
    {
        return self::articleByteCap((int) config('release_verification.article_bytes'), $this->maximumBytes);
    }

    public function __destruct()
    {
        foreach ($this->connections as $connection) {
            $this->disconnect($connection);
        }
        if ($this->connected) {
            $this->disconnect($this->nntp);
        }
        if ($this->alternateConnected && $this->alternate !== null) {
            $this->disconnect($this->alternate);
        }
    }
}
