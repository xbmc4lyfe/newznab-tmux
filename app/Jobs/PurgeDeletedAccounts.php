<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PurgeDeletedAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const string FFI_CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyReindexProxyMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Summary of the most recent {@see handle()} run.
     *
     * @var array{purged: int, failed: int, total: int, status: string, message: string}|array{}
     */
    private array $lastSummary = [];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * Find users that were soft-deleted 6 months ago and permanently delete them.
     */
    public function handle(): void
    {
        $purged = 0;

        // Find users that were soft-deleted 6 months ago
        User::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(config('nntmux.purge_inactive_users_days')))
            ->get()
            ->each(function ($user) use (&$purged) {
                $user->forceDelete(); // Permanently delete the user
                $purged++;
            });

        $this->lastSummary = self::buildPurgeBatchSummary($purged, 0);
    }

    /**
     * @return array{purged: int, failed: int, total: int, status: string, message: string}|array{}
     */
    public function lastSummary(): array
    {
        return $this->lastSummary;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Negative counts clamp to zero and the total saturates at PHP_INT_MAX.
     *
     * @return array{purged: int, failed: int, total: int, status: string, message: string}
     */
    public static function buildPurgeBatchSummary(int $purgedCount, int $failedCount): array
    {
        return self::usesRustFfi()
            ? self::buildPurgeBatchSummaryNative($purgedCount, $failedCount)
            : self::buildPurgeBatchSummaryPhp($purgedCount, $failedCount);
    }

    /**
     * @return array{purged: int, failed: int, total: int, status: string, message: string}
     */
    public static function buildPurgeBatchSummaryPhp(int $purgedCount, int $failedCount): array
    {
        $purged = max(0, $purgedCount);
        $failed = max(0, $failedCount);

        return [
            'purged' => $purged,
            'failed' => $failed,
            'total' => $purged > PHP_INT_MAX - $failed ? PHP_INT_MAX : $purged + $failed,
            'status' => match (true) {
                $failed === 0 && $purged === 0 => 'noop',
                $failed === 0 => 'complete',
                $purged === 0 => 'failed',
                default => 'partial',
            },
            'message' => $failed === 0
                ? "Purged {$purged} deleted account(s)."
                : "Purged {$purged} deleted account(s); {$failed} failed.",
        ];
    }

    /**
     * @return array{purged: int, failed: int, total: int, status: string, message: string}
     */
    public static function buildPurgeBatchSummaryNative(int $purgedCount, int $failedCount): array
    {
        $result = self::nativePacket(3, [(string) $purgedCount, (string) $failedCount]);
        if ($result === null || count($result) !== 5) {
            return self::buildPurgeBatchSummaryPhp($purgedCount, $failedCount);
        }

        return [
            'purged' => (int) $result[0],
            'failed' => (int) $result[1],
            'total' => (int) $result[2],
            'status' => $result[3],
            'message' => $result[4],
        ];
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w32_passkey_reindex_proxy_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackNativePacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackNativePacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search',
                self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
