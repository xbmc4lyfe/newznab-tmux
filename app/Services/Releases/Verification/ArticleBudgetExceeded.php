<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/** The article is larger than the bounded spot-check fetch allows; refetching it cannot change that. */
final class ArticleBudgetExceeded extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_article_budget_summarize(
            int64_t articles_read,
            int64_t max_articles,
            int64_t bytes_read,
            int64_t max_bytes,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBudgetIntegrityExceptionsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const array REASONS = ['articles_and_bytes', 'articles', 'bytes', 'within_budget'];

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Summarizes a spot-check fetch budget. Negative inputs clamp to zero, a zero limit disables
     * it and reports `-1` remaining; an exceeded budget can never succeed by refetching.
     *
     * @return array{articles_read: int, max_articles: int, bytes_read: int, max_bytes: int, articles_exceeded: bool, bytes_exceeded: bool, overrun_articles: int, overrun_bytes: int, remaining_articles: int, remaining_bytes: int, reason: string, exceeded: bool, refetch_can_succeed: bool}
     */
    public static function summarizeArticleBudgetOverrun(int $articlesRead, int $maxArticles, int $bytesRead, int $maxBytes): array
    {
        return self::usesRustFfi()
            ? self::summarizeArticleBudgetOverrunNative($articlesRead, $maxArticles, $bytesRead, $maxBytes)
            : self::summarizeArticleBudgetOverrunPhp($articlesRead, $maxArticles, $bytesRead, $maxBytes);
    }

    /**
     * @return array{articles_read: int, max_articles: int, bytes_read: int, max_bytes: int, articles_exceeded: bool, bytes_exceeded: bool, overrun_articles: int, overrun_bytes: int, remaining_articles: int, remaining_bytes: int, reason: string, exceeded: bool, refetch_can_succeed: bool}
     */
    public static function summarizeArticleBudgetOverrunNative(int $articlesRead, int $maxArticles, int $bytesRead, int $maxBytes): array
    {
        $fields = self::callNative('nntmux_article_budget_summarize', [$articlesRead, $maxArticles, $bytesRead, $maxBytes], 256);
        if ($fields === null || count($fields) !== 13 || ! in_array($fields[10], self::REASONS, true)) {
            return self::summarizeArticleBudgetOverrunPhp($articlesRead, $maxArticles, $bytesRead, $maxBytes);
        }
        foreach ([0, 1, 2, 3, 6, 7, 8, 9] as $index) {
            if (! is_numeric($fields[$index])) {
                return self::summarizeArticleBudgetOverrunPhp($articlesRead, $maxArticles, $bytesRead, $maxBytes);
            }
        }

        return [
            'articles_read' => (int) $fields[0],
            'max_articles' => (int) $fields[1],
            'bytes_read' => (int) $fields[2],
            'max_bytes' => (int) $fields[3],
            'articles_exceeded' => $fields[4] === '1',
            'bytes_exceeded' => $fields[5] === '1',
            'overrun_articles' => (int) $fields[6],
            'overrun_bytes' => (int) $fields[7],
            'remaining_articles' => (int) $fields[8],
            'remaining_bytes' => (int) $fields[9],
            'reason' => $fields[10],
            'exceeded' => $fields[11] === '1',
            'refetch_can_succeed' => $fields[12] === '1',
        ];
    }

    /**
     * @return array{articles_read: int, max_articles: int, bytes_read: int, max_bytes: int, articles_exceeded: bool, bytes_exceeded: bool, overrun_articles: int, overrun_bytes: int, remaining_articles: int, remaining_bytes: int, reason: string, exceeded: bool, refetch_can_succeed: bool}
     */
    public static function summarizeArticleBudgetOverrunPhp(int $articlesRead, int $maxArticles, int $bytesRead, int $maxBytes): array
    {
        $articles = max(0, $articlesRead);
        $articleLimit = max(0, $maxArticles);
        $bytes = max(0, $bytesRead);
        $byteLimit = max(0, $maxBytes);
        $articlesExceeded = $articleLimit > 0 && $articles > $articleLimit;
        $bytesExceeded = $byteLimit > 0 && $bytes > $byteLimit;
        $exceeded = $articlesExceeded || $bytesExceeded;

        return [
            'articles_read' => $articles,
            'max_articles' => $articleLimit,
            'bytes_read' => $bytes,
            'max_bytes' => $byteLimit,
            'articles_exceeded' => $articlesExceeded,
            'bytes_exceeded' => $bytesExceeded,
            'overrun_articles' => $articlesExceeded ? $articles - $articleLimit : 0,
            'overrun_bytes' => $bytesExceeded ? $bytes - $byteLimit : 0,
            'remaining_articles' => $articleLimit > 0 ? max(0, $articleLimit - $articles) : -1,
            'remaining_bytes' => $byteLimit > 0 ? max(0, $byteLimit - $bytes) : -1,
            'reason' => match (true) {
                $articlesExceeded && $bytesExceeded => 'articles_and_bytes',
                $articlesExceeded => 'articles',
                $bytesExceeded => 'bytes',
                default => 'within_budget',
            },
            'exceeded' => $exceeded,
            'refetch_can_succeed' => ! $exceeded,
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
