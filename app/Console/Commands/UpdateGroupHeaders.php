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

class UpdateGroupHeaders extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_rgb_cmd_clamp_max_headers(uint8_t kind, int64_t int_value, const char *text_ptr, size_t text_len, int64_t *out_value);
        ssize_t nntmux_cat_rgb_cmd_message(uint8_t kind, const char *text_ptr, size_t text_len, char *out_buf, size_t out_cap);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'group:update-headers {group : Group name} {--max=0 : Maximum headers for this group visit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update a single group\'s article headers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $groupName = $this->argument('group');

        try {
            $nntp = $this->getNntp();
            $groupMySQL = UsenetGroup::getByName($groupName)?->toArray();

            if ($groupMySQL === null) {
                $this->error(self::formatGroupNotFoundMessage((string) $groupName));

                return self::FAILURE;
            }

            $binaries = new BinariesService;
            $binaries->setNntp($nntp);
            $binaries->updateGroup($groupMySQL, self::clampMaxHeaders($this->option('max')));

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
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * `max(0, (int) $max)`: the per-visit header limit, where `0` means no command-level limit.
     */
    public static function clampMaxHeaders(mixed $max): int
    {
        return self::$useRustFfi ? self::clampMaxHeadersNative($max) : self::clampMaxHeadersPhp($max);
    }

    public static function clampMaxHeadersPhp(mixed $max): int
    {
        return max(0, (int) $max);
    }

    public static function clampMaxHeadersNative(mixed $max): int
    {
        $ffi = self::ffi();
        $arguments = match (true) {
            $max === null => [0, 0, '', 0],
            \is_bool($max) => [1, $max ? 1 : 0, '', 0],
            \is_int($max) => [2, $max, '', 0],
            \is_string($max) => [3, 0, $max, \strlen($max)],
            default => null,
        };
        if ($ffi === null || PHP_INT_SIZE !== 8 || $arguments === null) {
            return self::clampMaxHeadersPhp($max);
        }

        try {
            $value = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_rgb_cmd_clamp_max_headers(...[...$arguments, $value]);

            return $rc === 1 ? (int) $value[0] : self::clampMaxHeadersPhp($max);
        } catch (Throwable) {
            return self::clampMaxHeadersPhp($max);
        }
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
        return self::messageNative(1, $groupName) ?? self::formatGroupNotFoundMessagePhp($groupName);
    }

    public static function formatNntpConnectionFailedMessage(string $errorMessage): string
    {
        return self::$useRustFfi ? self::formatNntpConnectionFailedMessageNative($errorMessage) : self::formatNntpConnectionFailedMessagePhp($errorMessage);
    }

    public static function formatNntpConnectionFailedMessagePhp(string $errorMessage): string
    {
        return "NNTP connection failed: {$errorMessage}";
    }

    public static function formatNntpConnectionFailedMessageNative(string $errorMessage): string
    {
        return self::messageNative(0, $errorMessage) ?? self::formatNntpConnectionFailedMessagePhp($errorMessage);
    }

    private static function messageNative(int $kind, string $text): ?string
    {
        $ffi = self::ffi();

        return $ffi === null ? null : self::callForString($ffi, 'nntmux_cat_rgb_cmd_message', \strlen($text) + 32, $kind, $text, \strlen($text));
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
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
            $ffi = RustPipelineLibrary::resolveDomain('w30-3-update-group-headers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
