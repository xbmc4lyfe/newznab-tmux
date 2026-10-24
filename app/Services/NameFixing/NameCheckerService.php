<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Services\NameFixing\Checkers\AppNameChecker;
use App\Services\NameFixing\Checkers\GameNameChecker;
use App\Services\NameFixing\Checkers\MovieNameChecker;
use App\Services\NameFixing\Checkers\TvNameChecker;
use App\Services\NameFixing\Contracts\NameCheckerInterface;
use App\Services\NameFixing\Data\NameFixResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Collection;

/**
 * Service for checking release names against multiple patterns.
 *
 * Orchestrates multiple name checkers (TV, Movie, Game, App) to find
 * potential name fixes for releases.
 */
class NameCheckerService
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_check_all_first(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_name_check_all_collect(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private static bool $ffiResolved = false;

    private static ?FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    private bool $defaultCheckersUnmodified = true;

    /**
     * Collection of registered name checkers.
     *
     * @var Collection<int, NameCheckerInterface>
     */
    protected Collection $checkers;

    /**
     * Whether checkers are sorted by priority.
     */
    protected bool $sorted = false;

    // Fused caller measurements pass the 1.5x common and uncommon p99 gates.
    // Individual checker defaults remain PHP; fusion amortizes the shared scan.
    public function __construct(
        private readonly ?string $libraryPath = null,
        private readonly bool $useRust = true,
    ) {
        $this->checkers = new Collection;
        $this->registerDefaultCheckers();
        $this->defaultCheckersUnmodified = get_class($this) === self::class;
    }

    /**
     * Register the default name checkers.
     */
    protected function registerDefaultCheckers(): void
    {
        $this->addChecker(new TvNameChecker);
        $this->addChecker(new MovieNameChecker);
        $this->addChecker(new GameNameChecker);
        $this->addChecker(new AppNameChecker);
    }

    /**
     * Add a name checker to the service.
     */
    public function addChecker(NameCheckerInterface $checker): self
    {
        $this->defaultCheckersUnmodified = false;
        $this->checkers->push($checker);
        $this->sorted = false;

        return $this;
    }

    /**
     * Check a release name against all registered checkers.
     *
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return NameFixResult|null The first matching result, or null if no match
     */
    public function check(object $release, string $textstring): ?NameFixResult
    {
        if ($this->defaultCheckersUnmodified && $this->useRust) {
            $raw = $this->checkNative($textstring, false);
            if ($raw !== false) {
                return $raw === '' ? null : $this->nativeResult($raw);
            }
        }
        $this->ensureSorted();

        foreach ($this->checkers as $checker) {
            $result = $checker->check($release, $textstring);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Check all registered checkers and return all matches.
     *
     * Useful for debugging or when you want to see all potential matches.
     *
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return Collection<int, NameFixResult> All matching results
     */
    public function checkAll(object $release, string $textstring): Collection
    {
        if ($this->defaultCheckersUnmodified && $this->useRust) {
            $raw = $this->checkNative($textstring, true);
            if ($raw !== false) {
                $results = new Collection;
                for ($offset = 0, $length = strlen($raw); $offset < $length;) {
                    $patternEnd = strpos($raw, "\0", $offset + 2);
                    $matchEnd = strpos($raw, "\0", $patternEnd + 1);
                    $end = $matchEnd === false ? $length : $matchEnd;
                    $results->push($this->nativeResult(substr($raw, $offset, $end - $offset)));
                    $offset = $end + 1;
                }

                return $results;
            }
        }
        $this->ensureSorted();
        $results = new Collection;

        foreach ($this->checkers as $checker) {
            $result = $checker->check($release, $textstring);
            if ($result !== null) {
                $results->push($result);
            }
        }

        return $results;
    }

    /** Empty string means no match; false asks the caller to run PHP. */
    private function checkNative(string $input, bool $collect): string|false
    {
        try {
            if ($this->libraryPath !== null) {
                $ffi = RustPipelineLibrary::resolveDomain('name_checker_service', self::FFI_CDEF, $this->libraryPath);
            } else {
                if (! self::$ffiResolved) {
                    self::$defaultFfi = RustPipelineLibrary::resolveDomain('name_checker_service', self::FFI_CDEF);
                    if (self::$defaultFfi !== null) {
                        // Four maximum-length matches, pattern names, and separators.
                        self::$defaultBuf = RustPipelineLibrary::obtainScratch(self::$defaultFfi, 16640, self::$defaultCap);
                    }
                    self::$ffiResolved = true;
                }
                $ffi = self::$defaultFfi;
            }
            if ($ffi === null) {
                return false;
            }
            $cap = self::$defaultCap;
            $buf = $this->libraryPath === null ? self::$defaultBuf : RustPipelineLibrary::obtainScratch($ffi, 16640, $cap);
            if ($buf === null) {
                return false;
            }
            if ($collect) {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_name_check_all_collect($input, strlen($input), $buf, $cap);
            } else {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_name_check_all_first($input, strlen($input), $buf, $cap);
            }

            return $written >= 0 ? ($written === 0 ? '' : FFI::string($buf, $written)) : false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nativeResult(string $raw): NameFixResult
    {
        [$pattern, $matched] = explode("\0", substr($raw, 2), 2);
        // Default registration order is also priority order. Keep formatting and
        // confidence in each checker, shared with its individual native path.
        /** @var TvNameChecker|MovieNameChecker|GameNameChecker|AppNameChecker $checker */
        $checker = $this->checkers[ord($raw[0])];

        return $checker->fromNativeMatch($pattern, $matched);
    }

    /**
     * Check using a specific checker by name.
     *
     * @param  string  $checkerName  The name of the checker to use
     * @param  object  $release  The release object
     * @param  string  $textstring  The text to check
     * @return NameFixResult|null The result if matched, null otherwise
     */
    public function checkWith(string $checkerName, object $release, string $textstring): ?NameFixResult
    {
        $checker = $this->checkers->first(
            fn (NameCheckerInterface $c) => strtolower($c->getName()) === strtolower($checkerName)
        );

        return $checker?->check($release, $textstring);
    }

    /**
     * Get all registered checkers.
     *
     * @return Collection<int, NameCheckerInterface>
     */
    public function getCheckers(): Collection
    {
        // The returned collection is mutable; subsequent calls must honor edits.
        $this->defaultCheckersUnmodified = false;
        $this->ensureSorted();

        return $this->checkers;
    }

    /**
     * Get checker statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->checkers->map(fn (NameCheckerInterface $checker) => [
            'name' => $checker->getName(),
            'priority' => $checker->getPriority(),
            'class' => get_class($checker),
        ])->toArray();
    }

    /**
     * Ensure checkers are sorted by priority.
     */
    protected function ensureSorted(): void
    {
        if (! $this->sorted) {
            $this->checkers = $this->checkers->sortBy(
                fn (NameCheckerInterface $checker) => $checker->getPriority()
            )->values();
            $this->sorted = true;
        }
    }
}
