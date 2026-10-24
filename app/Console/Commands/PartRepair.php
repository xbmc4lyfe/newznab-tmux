<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartRepair extends Command
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
    protected $signature = 'binaries:part-repair {group : Group name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Do part repair for a group';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $groupName = $this->argument('group');

        try {
            $groupMySQL = UsenetGroup::getByName($groupName)->toArray();

            if ($groupMySQL === null) {
                $this->error(self::formatGroupNotFoundMessage($groupName));

                return self::FAILURE;
            }

            $nntp = $this->getNntp();

            $data = $nntp->selectGroup($groupMySQL['name']);

            if (NNTPService::isError($data) && $nntp->dataError($nntp, $groupMySQL['name']) === false) {
                return self::FAILURE;
            }

            $binaries = new BinariesService;
            $binaries->setNntp($nntp);
            $binaries->partRepair($groupMySQL);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = NNTPService::isError($connectResult)
                ? self::formatNntpErrorMessage($connectResult->getMessage())
                : 'Unable to connect to usenet.';
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }

    public static function formatGroupNotFoundMessage(string $groupName): string
    {
        return self::$useRustFfi ? self::formatGroupNotFoundMessageNative($groupName) : self::formatGroupNotFoundMessagePhp($groupName);
    }

    public static function formatGroupNotFoundMessagePhp(string $groupName): string
    {
        return "Group not found: {$groupName}";
    }

    public static function formatGroupNotFoundMessageNative(string $groupName): string
    {
        if (self::callNative(['part_repair.format_group_not_found_message', $groupName], $result) && is_string($result)) {
            return $result;
        }

        return self::formatGroupNotFoundMessagePhp($groupName);
    }

    public static function formatNntpErrorMessage(string $errorMessage): string
    {
        return self::$useRustFfi ? self::formatNntpErrorMessageNative($errorMessage) : self::formatNntpErrorMessagePhp($errorMessage);
    }

    public static function formatNntpErrorMessagePhp(string $errorMessage): string
    {
        return 'Unable to connect to usenet. Error: '.$errorMessage;
    }

    public static function formatNntpErrorMessageNative(string $errorMessage): string
    {
        if (self::callNative(['part_repair.format_nntp_error_message', $errorMessage], $result) && is_string($result)) {
            return $result;
        }

        return self::formatNntpErrorMessagePhp($errorMessage);
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
