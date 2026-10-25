<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NNTPService;
use RuntimeException;

final class FingerprintDispatch
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const array ROUTES = ['database', 'job', 'rejected'];

    private const array REASONS = ['queued', 'forced', 'generation', 'no_generation', 'invalid_release_id'];

    private const int HAS_FORCE = 1;

    private const int HAS_WORKER = 2;

    private static ?bool $useRustFfi = null;

    public static function enqueue(int $releaseId, string $generation = '', bool $force = false): void
    {
        if (config('nntmux.fingerprint_worker', 'rust') === 'rust') {
            if (! app(DatabaseFingerprintQueue::class)->enqueue($releaseId, $force)) {
                throw new RuntimeException('Fingerprint work has no database NZB source.');
            }

            return;
        }
        CollectReleaseFingerprintMetadata::dispatch($releaseId, $generation);
    }

    public static function useRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
    }

    /**
     * Plan where enqueue() sends a release without reading config or touching the queue or bus. $worker is the
     * configured nntmux.fingerprint_worker value (null reads as the "rust" default); exactly "rust" routes to the
     * database queue, where only $force applies, and anything else to the metadata job, where only the trimmed
     * $generation applies. Release ids below one are rejected before routing.
     *
     * @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool}
     */
    public static function planEnqueue(int $releaseId, string $generation = '', bool $force = false, ?string $worker = 'rust'): array
    {
        return (self::usesRustFfi() ? self::planEnqueueNative($releaseId, $generation, $force, $worker) : null)
            ?? self::planEnqueuePhp($releaseId, $generation, $force, $worker);
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool} */
    private static function planEnqueuePhp(int $releaseId, string $generation, bool $force, ?string $worker): array
    {
        $trimmed = trim($generation);
        [$route, $reason] = match (true) {
            $releaseId < 1 => [2, 4],
            $worker === null || $worker === 'rust' => [0, $force ? 1 : 0],
            default => [1, $trimmed !== '' ? 2 : 3],
        };

        return self::plan($releaseId, $route, $reason, $trimmed, $route === 0 && $force, $route === 1 && $trimmed !== '');
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool}|null */
    private static function planEnqueueNative(int $releaseId, string $generation, bool $force, ?string $worker): ?array
    {
        $flags = ($force ? self::HAS_FORCE : 0) | ($worker !== null ? self::HAS_WORKER : 0);
        $slots = NNTPService::evaluateWave382Native(1, $flags,
            pack('q3', $releaseId, \strlen($generation), \strlen($worker ?? '')).$generation.($worker ?? ''), 6);
        if ($slots === null) {
            return null;
        }
        [$route, $reason, $start, $length, $forceApplies, $generationApplies] = $slots;
        if (! isset(self::ROUTES[$route], self::REASONS[$reason]) || $start < 0 || $length < 0 || $start > \strlen($generation) - $length
            || ($forceApplies !== 0 && $forceApplies !== 1) || ($generationApplies !== 0 && $generationApplies !== 1)) {
            return null;
        }

        return self::plan($releaseId, $route, $reason, substr($generation, $start, $length), $forceApplies === 1, $generationApplies === 1);
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool} */
    private static function plan(int $releaseId, int $route, int $reason, string $generation, bool $forceApplies, bool $generationApplies): array
    {
        return ['route' => self::ROUTES[$route], 'reason' => self::REASONS[$reason], 'release_id' => $releaseId, 'generation' => $generation,
            'force_applies' => $forceApplies, 'generation_applies' => $generationApplies];
    }
}
