<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Categorizers\MiscCategorizer;
use App\Services\Categorization\ReleaseContext;
use Closure;
use FFI;

/**
 * Pipe for miscellaneous content and hash detection.
 * This runs FIRST with high priority to detect hashes early and prevent
 * them from being incorrectly categorized by group-based or content-based rules.
 *
 * When a hashed/obfuscated/gibberish release is detected, the passable is
 * locked to misc categories so no downstream pipe can override the result.
 */
class MiscPipe extends AbstractCategorizationPipe
{
    public static bool $useRustFfi = false;

    protected int $priority = 1; // Run first to catch hashes early

    private MiscCategorizer $categorizer;

    /**
     * matchedBy prefixes that indicate a hashed/obfuscated/gibberish result
     * and should trigger the misc lock.
     */
    private const LOCK_PREFIXES = [
        'hash_',
        'obfuscated_',
        'gibberish_',
    ];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        return CategorizationService::categorizeFfi();
    }

    public function __construct()
    {
        $this->categorizer = new MiscCategorizer;
    }

    public function getName(): string
    {
        return 'Misc';
    }

    /**
     * Override handle() to lock the passable when a hash/gibberish release is detected.
     */
    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        // MiscPipe always runs regardless of shouldStopProcessing — it IS the first pipe.
        [$passable->miscAnalysis, $result] = $this->categorizer->inspectAndCategorize($passable->context);

        // Record the result for debug
        $passable->updateBestResult($result, $this->getName());

        // Lock to misc if this is a hashed/obfuscated/gibberish detection
        if ($result->isSuccessful() && $this->shouldLock($result)) {
            $passable->bestResult = $result;
            $passable->lockToMisc();
        }

        return $next($passable);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    /**
     * Determine if a result should trigger the misc lock.
     */
    public function shouldLock(CategorizationResult $result): bool
    {
        return self::$useRustFfi
            ? $this->shouldLockNative($result)
            : $this->shouldLockPhp($result);
    }

    public function shouldLockPhp(CategorizationResult $result): bool
    {
        // Always lock OTHER_HASHED results
        if ($result->categoryId === Category::OTHER_HASHED) {
            return true;
        }

        // Lock OTHER_MISC results that were matched by hash/obfuscated/gibberish checks
        foreach (self::LOCK_PREFIXES as $prefix) {
            if (str_starts_with($result->matchedBy, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function shouldLockNative(CategorizationResult $result): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->shouldLockPhp($result);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_misc_pipe_should_lock(
            $result->categoryId,
            $result->matchedBy,
            \strlen($result->matchedBy)
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldLockPhp($result);
        }

        return $res === 1;
    }
}
