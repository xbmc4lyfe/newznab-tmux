<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessUpdatePerGroup extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_mac_group_target(const char *text_ptr, size_t text_len, int64_t *out_id);
        CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP parse (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:update-per-group';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download headers, backfill, create releases, and post-process per group using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            (new ForkingService)->updatePerGroup();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the default.
     */
    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * A per-group worker target: `ctype_digit($id) && (int) $id > 0`, with the
     * `update_per_group  <id>` multiprocessing arguments for a valid id.
     *
     * @return array{valid: bool, groupId: int|null, dnrArgs: string|null}
     */
    public static function parseGroupProcessTarget(string $rawGroupId): array
    {
        return self::usesRustFfi()
            ? self::parseGroupProcessTargetNative($rawGroupId)
            : self::parseGroupProcessTargetPhp($rawGroupId);
    }

    /**
     * @return array{valid: bool, groupId: int|null, dnrArgs: string|null}
     */
    public static function parseGroupProcessTargetPhp(string $rawGroupId): array
    {
        if (! ctype_digit($rawGroupId) || (int) $rawGroupId <= 0) {
            return ['valid' => false, 'groupId' => null, 'dnrArgs' => null];
        }

        return self::validTarget((int) $rawGroupId);
    }

    /**
     * @return array{valid: bool, groupId: int|null, dnrArgs: string|null}
     */
    public static function parseGroupProcessTargetNative(string $rawGroupId): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::parseGroupProcessTargetPhp($rawGroupId);
        }

        try {
            $out = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_mac_group_target($rawGroupId, \strlen($rawGroupId), FFI::addr($out));

            return match ($rc) {
                // @phpstan-ignore property.notFound
                1 => self::validTarget((int) $out->cdata),
                0 => ['valid' => false, 'groupId' => null, 'dnrArgs' => null],
                default => self::parseGroupProcessTargetPhp($rawGroupId),
            };
        } catch (Throwable) {
            return self::parseGroupProcessTargetPhp($rawGroupId);
        }
    }

    /**
     * @return array{valid: true, groupId: int, dnrArgs: string}
     */
    private static function validTarget(int $groupId): array
    {
        return ['valid' => true, 'groupId' => $groupId, 'dnrArgs' => 'update_per_group  '.$groupId];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
