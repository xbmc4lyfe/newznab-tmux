<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Models\ProcessingRuntimeState;
use App\Support\Configuration\IngestionConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Configuration settings for ProcessReleases operations.
 *
 * Hydrated from typed ingestion configuration and uncached runtime state.
 */
#[TypeScript]
final class ProcessReleasesSettings extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_creation_values(uint32_t mode, const int64_t *input, size_t len, int64_t *out, size_t cap);
        C;

    // PHP remains the default until paired common/uncommon mean and p99 qualify.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>|null
     */
    private static function calculateNative(array $values): ?array
    {
        try {
            $ffi = self::ffi();
            if ($ffi === null) {
                return null;
            }
            $input = $ffi->new('int64_t[4]');
            foreach ($values as $index => $value) {
                $input[$index] = $value;
            }
            $out = $ffi->new('int64_t[5]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_creation_values(0, $input, 4, $out, 5);
            if ($written !== 5) {
                return null;
            }
            $result = [];
            for ($index = 0; $index < 5; $index++) {
                $result[] = (int) $out[$index];
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public int $collectionDelayTime = 2,
        public int $crossPostTime = 2,
        public int $releaseCreationLimit = 1000,
        public int $completion = 0,
        public int $collectionTimeout = 48,
        public int $maxSizeToFormRelease = 0,
        public int $minSizeToFormRelease = 0,
        public int $minFilesToFormRelease = 0,
        public int $releaseRetentionDays = 0,
        public bool $deletePasswordedRelease = false,
        public int $miscOtherRetentionHours = 0,
        public int $miscHashedRetentionHours = 0,
        public int $partRetentionHours = 24,
        public ?string $lastRunTime = null,
    ) {
        // Clamp completion to a sane upper bound (legacy `min(100, …)`).
        $this->completion = self::clampCompletion($this->completion);
    }

    public static function fromConfiguration(
        IngestionConfigurationData $configuration,
        ProcessingRuntimeState $runtimeState,
    ): self {
        return new self(
            collectionDelayTime: $configuration->collectionDelayHours,
            crossPostTime: $configuration->crossPostHours,
            releaseCreationLimit: $configuration->maxReleasesCreated,
            completion: $configuration->completionPercent,
            collectionTimeout: $configuration->collectionTimeoutHours,
            maxSizeToFormRelease: $configuration->maxSizeToFormRelease,
            minSizeToFormRelease: $configuration->minSizeToFormRelease,
            minFilesToFormRelease: $configuration->minFilesToFormRelease,
            releaseRetentionDays: $configuration->releaseRetentionDays,
            deletePasswordedRelease: $configuration->deletePasswordedReleases,
            miscOtherRetentionHours: $configuration->miscOtherRetentionHours,
            miscHashedRetentionHours: $configuration->miscHashedRetentionHours,
            partRetentionHours: $configuration->partRetentionHours,
            lastRunTime: $runtimeState->last_binary_run_at?->toDateTimeString(),
        );
    }

    public static function clampCompletion(int $completion): int
    {
        return self::$useRustFfi ? self::clampCompletionNative($completion) : self::clampCompletionPhp($completion);
    }

    public static function clampCompletionNative(int $completion): int
    {
        return self::calculateNative([$completion, 0, 0, 0])[0] ?? self::clampCompletionPhp($completion);
    }

    public static function clampCompletionPhp(int $completion): int
    {
        return min(100, $completion);
    }

    public function hasValidCompletion(): bool
    {
        return self::$useRustFfi ? $this->hasValidCompletionNative() : $this->hasValidCompletionPhp();
    }

    public function hasValidCompletionNative(): bool
    {
        $values = self::calculateNative([$this->completion, $this->releaseRetentionDays, 0, 0]);

        return $values === null ? $this->hasValidCompletionPhp() : $values[1] === 1;
    }

    public function hasValidCompletionPhp(): bool
    {
        return $this->completion >= 0 && $this->completion <= 100;
    }

    public function hasRetentionCleanup(): bool
    {
        return self::$useRustFfi ? $this->hasRetentionCleanupNative() : $this->hasRetentionCleanupPhp();
    }

    public function hasRetentionCleanupNative(): bool
    {
        $values = self::calculateNative([$this->completion, $this->releaseRetentionDays, 0, 0]);

        return $values === null ? $this->hasRetentionCleanupPhp() : $values[2] === 1;
    }

    public function hasRetentionCleanupPhp(): bool
    {
        return $this->releaseRetentionDays > 0;
    }

    public function hasCompletionCleanup(): bool
    {
        return self::$useRustFfi ? $this->hasCompletionCleanupNative() : $this->hasCompletionCleanupPhp();
    }

    public function hasCompletionCleanupNative(): bool
    {
        $values = self::calculateNative([$this->completion, $this->releaseRetentionDays, 0, 0]);

        return $values === null ? $this->hasCompletionCleanupPhp() : $values[4] === 1;
    }

    public function hasCompletionCleanupPhp(): bool
    {
        return $this->completion > 0;
    }

    /** Cross-post cleanup must honor the current dedupe configuration on every call. */
    public function hasCrossPostDetection(): bool
    {
        return self::$useRustFfi ? $this->hasCrossPostDetectionNative() : $this->hasCrossPostDetectionPhp();
    }

    public function hasCrossPostDetectionNative(): bool
    {
        if ($this->crossPostTime <= 0) {
            return false;
        }
        $dedupe = (bool) config('nntmux.release_dedupe_enabled', true);
        $values = self::calculateNative([$this->completion, $this->releaseRetentionDays, $this->crossPostTime, (int) $dedupe]);

        return $values === null ? $dedupe : $values[3] === 1;
    }

    public function hasCrossPostDetectionPhp(): bool
    {
        return $this->crossPostTime > 0 && (bool) config('nntmux.release_dedupe_enabled', true);
    }
}
