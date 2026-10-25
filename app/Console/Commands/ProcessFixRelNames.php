<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessFixRelNames extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w371_fix_names_reset_verify(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private const array FORK_TYPES = [null, 'standard', 'predbft'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:fixrelnames
                            {type : Type: standard or predbft}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix release names using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');

        if (! \in_array($type, ['standard', 'predbft'], true)) {
            $this->error('Type must be either: standard or predbft');
            $this->line('');
            $this->line('standard => Attempt to fix release name using standard methods');
            $this->line('predbft  => Attempt to fix release name using Predb full text matching');

            return self::FAILURE;
        }

        try {
            (new ForkingService)->fixRelNames($type);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Normalizes a `multiprocessing:fixrelnames` type: trims PHP whitespace and lowercases ASCII.
     * `exact` is true only when the raw value is already canonical, as the command requires.
     *
     * @return array{type: string|null, valid: bool, exact: bool}
     */
    public static function normalizeForkType(?string $type): array
    {
        return (self::usesRustFfi() ? self::normalizeForkTypeNative($type) : null) ?? self::normalizeForkTypePhp($type);
    }

    /**
     * @return array{type: string|null, valid: bool, exact: bool}
     */
    private static function normalizeForkTypePhp(?string $type): array
    {
        $normalized = strtolower(trim($type ?? ''));
        $valid = $normalized === 'standard' || $normalized === 'predbft';

        return ['type' => $valid ? $normalized : null, 'valid' => $valid, 'exact' => $valid && $type === $normalized];
    }

    /**
     * @return array{type: string|null, valid: bool, exact: bool}|null
     */
    private static function normalizeForkTypeNative(?string $type): ?array
    {
        $fields = self::unpackRustInts(self::callRustFfi(4, $type === null ? 0 : 1, self::packRustString($type ?? '')), 2);
        if ($fields === null || ! array_key_exists($fields[0], self::FORK_TYPES) || $fields[1] & ~1) {
            return null;
        }
        $normalized = self::FORK_TYPES[$fields[0]];

        return ['type' => $normalized, 'valid' => $normalized !== null, 'exact' => $fields[1] === 1];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 37.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w371', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
