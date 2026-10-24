<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\CollectionCleanupService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archive the late headers of dirty candidates. Ingestion marks a pending candidate dirty when more of its
 * articles arrive, and only archive() of that collection makes it claimable again. Release creation reaches
 * a collection only when its group's id cursor does, which on a group with a million ready collections can
 * take weeks, so this walks the dirty candidates themselves, oldest first, resuming after the last one it
 * looked at and starting over once it reaches the newest that was dirty when the sweep began. archive() keeps every guarantee it gives release
 * creation, and over-budget uploads keep their deferral.
 */
final class DirtyCandidateSettler
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(64 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 64 * 1024 * 1024) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    private const string CURSOR = 'release-verification:dirty-settle:cursor';

    public function __construct(private readonly CollectionCleanupService $cleanup) {}

    /**
     * @return array{inspected: int, settled: int, kept: int} Candidates looked at, those no longer dirty
     *                                                        afterwards, and late collections kept for later.
     */
    public function settle(int $limit, float $seconds): array
    {
        $totals = ['inspected' => 0, 'settled' => 0, 'kept' => 0];
        if (! config('release_verification.required', true)) {
            return $totals;
        }
        $window = self::settleWindow($limit, $seconds, microtime(true));
        $limit = $window['limit'];
        $deadline = $window['deadline'];
        $cursor = Cache::get(self::CURSOR);
        $after = self::cursorPosition($cursor, 'at', 'id');
        // Each sweep ends at the time it began, so it always comes round again to those it passed over (not
        // ready, kept, failed) however many become dirty while it runs: archive() moves a settled candidate's
        // next attempt to now, and a dirty one keeps its own, which may lie days ahead until it falls due.
        $upper = self::cursorPosition($cursor, 'upper_at', 'upper_id');
        if ($upper === null) {
            $upper = ['at' => now()->format('Y-m-d H:i:s'), 'id' => PHP_INT_MAX];
            $after = null;
        }
        $rows = DB::table('release_verification_candidates')->where('status', 'dirty')
            ->when($after !== null, static fn ($query) => $query->where(static fn ($q) => $q->where('next_attempt_at', '>', $after['at'])
                ->orWhere(static fn ($same) => $same->where('next_attempt_at', $after['at'])->where('id', '>', $after['id']))))
            ->where(static fn ($q) => $q->where('next_attempt_at', '<', $upper['at'])
                ->orWhere(static fn ($same) => $same->where('next_attempt_at', $upper['at'])->where('id', '<=', $upper['id'])))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get(['id', 'source_identity', 'next_attempt_at']);
        if ($rows->isEmpty()) {
            // This sweep is done: the next run starts a new one from the oldest.
            Cache::forget(self::CURSOR);

            return $totals;
        }
        foreach ($rows as $row) {
            if ($totals['inspected'] > 0 && microtime(true) >= $deadline) {
                return $totals;
            }
            // Moved past first: a process that dies inside archive() (out of memory, killed) skips this candidate
            // until the walk comes round again instead of retrying it on every run ahead of the rest.
            Cache::forever(self::CURSOR, ['at' => (string) $row->next_attempt_at, 'id' => (int) $row->id, 'upper_at' => $upper['at'], 'upper_id' => $upper['id']]);
            $totals['inspected']++;
            $hash = self::sourceHash($row->source_identity);
            $ids = $hash === null ? [] : $this->cleanup->verificationReadyCollections()->where('collectionhash', $hash)
                ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $kept = app(OversizedCollectionDeferrals::class)->archiveEach($ids, static function (int $id): void {
                app(CandidateRepository::class)->archive($id);
            });
            foreach ($kept as $id => $outcome) {
                if ($outcome['error'] !== null) {
                    Log::warning('Verification archival failed while settling dirty candidates; original headers retained.', ['collection_id' => $id,
                        'error_type' => $outcome['error']::class] + ($outcome['retry_after'] === null ? [] : ['retry_after' => $outcome['retry_after']]));
                }
            }
            $totals['kept'] += count($kept);
            if (DB::table('release_verification_candidates')->where('id', $row->id)->value('status') !== 'dirty') {
                $totals['settled']++;
            }
        }
        if ($rows->count() < $limit) {
            // The sweep reached its end: the next run starts a new one from the oldest.
            Cache::forget(self::CURSOR);
        }

        return $totals;
    }

    /** @return array{limit: int, deadline: float} */
    public static function settleWindow(int $limit, float $seconds, float $now): array
    {
        return self::$useRustFfi ? (self::settleWindowNative($limit, $seconds, $now) ?? self::settleWindowPhp($limit, $seconds, $now)) : self::settleWindowPhp($limit, $seconds, $now);
    }

    /** @return array{limit: int, deadline: float} */
    public static function settleWindowPhp(int $limit, float $seconds, float $now): array
    {
        return ['limit' => max(1, $limit), 'deadline' => $now + max(0.0, $seconds)];
    }

    /** @return array{limit: int, deadline: float}|null */
    public static function settleWindowNative(int $limit, float $seconds, float $now): ?array
    {
        $result = self::computeNative(30, [$limit, $seconds, $now])['value'] ?? null;

        return is_array($result) && is_int($result['limit'] ?? null) && is_float($result['deadline'] ?? null) ? $result : null;
    }

    /** @return array{at: string, id: int}|null */
    public static function cursorPosition(mixed $cursor, string $atKey, string $idKey): ?array
    {
        return self::$useRustFfi ? (self::cursorPositionNative($cursor, $atKey, $idKey) ?? self::cursorPositionPhp($cursor, $atKey, $idKey)) : self::cursorPositionPhp($cursor, $atKey, $idKey);
    }

    /** @return array{at: string, id: int}|null */
    public static function cursorPositionPhp(mixed $cursor, string $atKey, string $idKey): ?array
    {
        return is_array($cursor) && is_string($cursor[$atKey] ?? null) && is_numeric($cursor[$idKey] ?? null)
            ? ['at' => $cursor[$atKey], 'id' => (int) $cursor[$idKey]] : null;
    }

    /** @return array{at: string, id: int}|null */
    public static function cursorPositionNative(mixed $cursor, string $atKey, string $idKey): ?array
    {
        $result = self::computeNative(31, [$cursor, $atKey, $idKey])['value'] ?? null;

        return is_array($result) && is_string($result['at'] ?? null) && is_int($result['id'] ?? null) ? $result : null;
    }

    public static function sourceHash(mixed $identity): ?string
    {
        return self::$useRustFfi ? (self::sourceHashNative($identity) ?? self::sourceHashPhp($identity)) : self::sourceHashPhp($identity);
    }

    public static function sourceHashPhp(mixed $identity): ?string
    {
        return is_string($identity) && strlen($identity) === 40 && ctype_xdigit($identity) ? (string) hex2bin($identity) : null;
    }

    public static function sourceHashNative(mixed $identity): ?string
    {
        $result = self::computeNative(32, [$identity])['value'] ?? null;

        return is_string($result) ? $result : null;
    }
}
