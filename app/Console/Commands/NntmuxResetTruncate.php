<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class NntmuxResetTruncate extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w31_benchmark_binaries_cbp_reset_commands(
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

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:reset-truncate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command removes releases with no NZBs, resets all groups, truncates article tables. All other releases are left alone.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(VerificationResetService $verification): int
    {
        try {
            $verification->run(fn () => $this->resetDatabase());
        } catch (Throwable $error) {
            $this->error('Database reset stopped: '.$error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resetDatabase(): void
    {
        UsenetGroup::query()->update(self::buildGroupResetPayload());
        $this->info('Reseting all groups completed.');
        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        try {

            foreach (self::tablesToTruncate() as $value) {
                DB::statement("TRUNCATE TABLE $value");
                $this->info("Truncating $value completed.");
            }

        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
        $delcount = Release::query()->where('nzbstatus', '=', 0)->delete();
        $this->info(self::formatTruncatedSuccessMessage($delcount));
    }

    /**
     * Column values that reset every group's article window.
     *
     * @return array{first_record: int, first_record_postdate: null, last_record: int, last_record_postdate: null, last_updated: null}
     */
    public static function buildGroupResetPayload(): array
    {
        return self::$useRustFfi ? self::buildGroupResetPayloadNative() : self::buildGroupResetPayloadPhp();
    }

    /**
     * @return array{first_record: int, first_record_postdate: null, last_record: int, last_record_postdate: null, last_updated: null}
     */
    public static function buildGroupResetPayloadPhp(): array
    {
        return ['first_record' => 0, 'first_record_postdate' => null, 'last_record' => 0, 'last_record_postdate' => null, 'last_updated' => null];
    }

    /**
     * @return array{first_record: int, first_record_postdate: null, last_record: int, last_record_postdate: null, last_updated: null}
     */
    public static function buildGroupResetPayloadNative(): array
    {
        if (self::callNative(['reset_truncate.build_group_reset_payload'], $result) && $result === self::buildGroupResetPayloadPhp()) {
            return $result;
        }

        return self::buildGroupResetPayloadPhp();
    }

    /**
     * Article tables emptied by the reset, in truncation order.
     *
     * @return list<string>
     */
    public static function tablesToTruncate(): array
    {
        return self::$useRustFfi ? self::tablesToTruncateNative() : self::tablesToTruncatePhp();
    }

    /**
     * @return list<string>
     */
    public static function tablesToTruncatePhp(): array
    {
        return ['parts', 'missed_parts', 'binaries', 'collections'];
    }

    /**
     * @return list<string>
     */
    public static function tablesToTruncateNative(): array
    {
        if (self::callNative(['reset_truncate.tables_to_truncate'], $result) && $result === self::tablesToTruncatePhp()) {
            return $result;
        }

        return self::tablesToTruncatePhp();
    }

    public static function formatTruncatedSuccessMessage(int $deletedCount): string
    {
        return self::$useRustFfi ? self::formatTruncatedSuccessMessageNative($deletedCount) : self::formatTruncatedSuccessMessagePhp($deletedCount);
    }

    public static function formatTruncatedSuccessMessagePhp(int $deletedCount): string
    {
        return $deletedCount.' releases had no nzb, deleted.';
    }

    public static function formatTruncatedSuccessMessageNative(int $deletedCount): string
    {
        if (self::callNative(['reset_truncate.format_truncated_success_message', $deletedCount], $result) && is_string($result)) {
            return $result;
        }

        return self::formatTruncatedSuccessMessagePhp($deletedCount);
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
                $written = $ffi->nntmux_w31_benchmark_binaries_cbp_reset_commands($json, strlen($json), $buffer, $capacity);
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
