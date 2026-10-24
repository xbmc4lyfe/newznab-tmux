<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBinaries extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_mac_max_headers(uint8_t kind, int64_t int_value, const char *text_ptr, size_t text_len, int64_t *out_max);
        CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP cast (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:binaries
                            {max=0 : Maximum new headers to download per group (0 = no limit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download new headers for all active groups using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $argument = $this->argument('max');
        $max = \is_string($argument) || $argument === null
            ? self::normalizeMaxHeadersOption($argument)['max']
            : (int) $argument;

        try {
            (new ForkingService)->binaries($max);

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
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
     * `(int) $maxHeaders`, and whether it limits downloads (`0` and below mean no limit).
     *
     * @return array{max: int, limited: bool}
     */
    public static function normalizeMaxHeadersOption(int|string|null $maxHeaders): array
    {
        return self::usesRustFfi()
            ? self::normalizeMaxHeadersOptionNative($maxHeaders)
            : self::normalizeMaxHeadersOptionPhp($maxHeaders);
    }

    /**
     * @return array{max: int, limited: bool}
     */
    public static function normalizeMaxHeadersOptionPhp(int|string|null $maxHeaders): array
    {
        $max = (int) $maxHeaders;

        return ['max' => $max, 'limited' => $max > 0];
    }

    /**
     * @return array{max: int, limited: bool}
     */
    public static function normalizeMaxHeadersOptionNative(int|string|null $maxHeaders): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeMaxHeadersOptionPhp($maxHeaders);
        }

        try {
            $out = $ffi->new('int64_t');
            $text = \is_string($maxHeaders) ? $maxHeaders : '';
            $kind = match (true) {
                $maxHeaders === null => 0,
                \is_int($maxHeaders) => 2,
                default => 4,
            };
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_mac_max_headers($kind, \is_int($maxHeaders) ? $maxHeaders : 0, $text, \strlen($text), FFI::addr($out));
            if ($rc !== 0 && $rc !== 1) {
                return self::normalizeMaxHeadersOptionPhp($maxHeaders);
            }

            // @phpstan-ignore property.notFound
            return ['max' => (int) $out->cdata, 'limited' => $rc === 1];
        } catch (Throwable) {
            return self::normalizeMaxHeadersOptionPhp($maxHeaders);
        }
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
