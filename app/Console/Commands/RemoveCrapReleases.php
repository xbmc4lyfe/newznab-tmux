<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReleaseRemoverService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class RemoveCrapReleases extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w30_nzb_release_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    protected $signature = 'releases:remove-crap
                            {--type= : Type of crap to remove}
                            {--time=full : Time limit in hours or full}
                            {--blacklist-id= : Specific blacklist ID}
                            {--delete : Actually delete releases}';

    protected $description = 'Remove crap releases based on various criteria';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Unset options take their defaults; a given value, even "0" or "", is kept so it can't widen the removal.
     *
     * @return array{type: string, time: string, blacklist_id: string, delete: bool}
     */
    public static function normalizeOptions(mixed $type, mixed $time, mixed $blacklistId, bool $delete): array
    {
        return self::$useRustFfi
            ? self::normalizeOptionsNative($type, $time, $blacklistId, $delete)
            : self::normalizeOptionsPhp($type, $time, $blacklistId, $delete);
    }

    /**
     * @return array{type: string, time: string, blacklist_id: string, delete: bool}
     */
    public static function normalizeOptionsPhp(mixed $type, mixed $time, mixed $blacklistId, bool $delete): array
    {
        return [
            'type' => (string) ($type ?? ''),
            'time' => (string) ($time ?? 'full'),
            'blacklist_id' => (string) ($blacklistId ?? ''),
            'delete' => $delete,
        ];
    }

    /**
     * @return array{type: string, time: string, blacklist_id: string, delete: bool}
     */
    public static function normalizeOptionsNative(mixed $type, mixed $time, mixed $blacklistId, bool $delete): array
    {
        foreach ([$type, $time, $blacklistId] as $value) {
            if ($value !== null && ! is_scalar($value)) {
                return self::normalizeOptionsPhp($type, $time, $blacklistId, $delete);
            }
        }
        if (self::callNative(['remove_crap.normalize_options', $type, $time, $blacklistId, $delete], $result)
            && is_array($result) && array_keys($result) === ['type', 'time', 'blacklist_id', 'delete']
            && is_string($result['type']) && is_string($result['time']) && is_string($result['blacklist_id']) && is_bool($result['delete'])) {
            return [
                'type' => $result['type'],
                'time' => $result['time'],
                'blacklist_id' => $result['blacklist_id'],
                'delete' => $result['delete'],
            ];
        }

        return self::normalizeOptionsPhp($type, $time, $blacklistId, $delete);
    }

    /**
     * @return array{status: 'info'|'warn', message: string}
     */
    public static function formatSummaryMessage(mixed $result, bool $delete): array
    {
        return self::$useRustFfi ? self::formatSummaryMessageNative($result, $delete) : self::formatSummaryMessagePhp($result, $delete);
    }

    /**
     * @return array{status: 'info'|'warn', message: string}
     */
    public static function formatSummaryMessagePhp(mixed $result, bool $delete): array
    {
        if ($result === true) {
            return ['status' => 'info', 'message' => $delete
                ? 'Crap release removal complete.'
                : 'Dry run complete. Use --delete to remove the matched releases.'];
        }
        if (is_string($result) && $result !== '') {
            return ['status' => 'warn', 'message' => $result];
        }

        return ['status' => 'warn', 'message' => 'Crap release removal failed.'];
    }

    /**
     * @return array{status: 'info'|'warn', message: string}
     */
    public static function formatSummaryMessageNative(mixed $result, bool $delete): array
    {
        if (($result === null || is_scalar($result))
            && self::callNative(['remove_crap.format_summary_message', $result, $delete], $summary)
            && is_array($summary) && array_keys($summary) === ['status', 'message']
            && ($summary['status'] === 'info' || $summary['status'] === 'warn') && is_string($summary['message'])) {
            return ['status' => $summary['status'], 'message' => $summary['message']];
        }

        return self::formatSummaryMessagePhp($result, $delete);
    }

    public function handle(): int
    {
        $options = self::normalizeOptions(
            $this->option('type'),
            $this->option('time'),
            $this->option('blacklist-id'),
            (bool) $this->option('delete')
        );

        if (! $options['delete']) {
            $this->warn('Running in DRY-RUN mode. Use --delete to actually remove releases.');
        }

        try {
            $remover = new ReleaseRemoverService;
            $result = $remover->removeCrap($options['delete'], $options['time'], $options['type'], $options['blacklist_id']);
            $summary = self::formatSummaryMessage($result, $options['delete']);

            if ($summary['status'] === 'info') {
                $this->info($summary['message']);

                return self::SUCCESS;
            }

            $this->error($summary['message']);

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w30_nzb_release_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
