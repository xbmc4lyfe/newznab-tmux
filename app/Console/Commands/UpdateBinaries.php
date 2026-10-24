<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateBinaries extends Command
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
    protected $signature = 'update:binaries
                            {group? : Group name to update (optional, processes all if omitted)}
                            {max? : Maximum headers to download}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update binaries for a specific group or all groups';

    private bool $echoCLI;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->echoCLI = (bool) config('nntmux.echocli');

        $groupName = $this->argument('group');
        $max = $this->argument('max');

        // The configured limit is read only when the argument does not set one.
        $maxHeaders = is_numeric($max) && $max > 0
            ? self::resolveMaxHeaders($max, 0)
            : self::resolveMaxHeaders($max, (int) app(ConfigurationProvider::class)->ingestion()->maxHeadersPerIteration);

        $startTime = now()->toImmutable();

        try {
            $this->outputBanner();

            $nntp = $this->getNntp();
            $binaries = new BinariesService;
            $binaries->setNntp($nntp);

            if ($groupName && ! is_numeric($groupName)) {
                $this->outputHeader('Updating Single Group');
                $this->outputInfo("Group: {$groupName}");
                $this->outputInfo('Max headers: '.number_format($maxHeaders));
                $this->updateSingleGroup($binaries, $groupName, $maxHeaders);
            } else {
                $this->outputHeader('Updating All Groups');
                $this->outputInfo('Max headers: '.number_format($maxHeaders));
                $binaries->updateAllGroups($maxHeaders);
            }

            $this->outputSummary($startTime);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            cli()->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Update a single group.
     */
    private function updateSingleGroup(BinariesService $binaries, string $groupName, int $maxHeaders): void
    {
        $group = UsenetGroup::getByName($groupName);

        if ($group === null) {
            throw new \RuntimeException("Group not found: {$groupName}");
        }

        $binaries->updateGroup($group->toArray(), $maxHeaders);
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \RuntimeException($errorMessage);
        }

        return $nntp;
    }

    /**
     * Output the banner.
     */
    private function outputBanner(): void
    {
        if (! $this->echoCLI) {
            return;
        }

        echo PHP_EOL;
        cli()->header('NNTmux Binary Update');
        cli()->info('Started: '.now()->format('Y-m-d H:i:s'));
    }

    /**
     * Output a section header.
     */
    private function outputHeader(string $title): void
    {
        if (! $this->echoCLI) {
            return;
        }

        [$heading, $underline] = self::formatSectionHeaderLines($title);
        echo PHP_EOL;
        cli()->header($heading);
        cli()->header($underline);
    }

    /**
     * Output an info line.
     */
    private function outputInfo(string $message): void
    {
        if (! $this->echoCLI) {
            return;
        }

        cli()->info("  {$message}");
    }

    /**
     * Output the summary.
     */
    private function outputSummary(\DateTimeInterface $startTime): void
    {
        if (! $this->echoCLI) {
            return;
        }

        $elapsed = now()->diffInSeconds($startTime, true);
        $timeStr = self::formatElapsedTime($elapsed);
        [$heading, $underline] = self::formatSectionHeaderLines('Complete');

        echo PHP_EOL;
        cli()->header($heading);
        cli()->header($underline);
        cli()->info("  Total time: {$timeStr}");
        echo PHP_EOL;
    }

    /**
     * Format elapsed time.
     */
    public static function formatElapsedTime(float $seconds): string
    {
        return self::$useRustFfi ? self::formatElapsedTimeNative($seconds) : self::formatElapsedTimePhp($seconds);
    }

    public static function formatElapsedTimeNative(float $seconds): string
    {
        if (self::callNative(['update_binaries.format_elapsed_time', $seconds], $result) && is_string($result)) {
            return $result;
        }

        return self::formatElapsedTimePhp($seconds);
    }

    public static function formatElapsedTimePhp(float $seconds): string
    {
        if ($seconds < 1) {
            return sprintf('%dms', (int) ($seconds * 1000));
        }

        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, (int) $remainingSeconds);
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %dm', $hours, $remainingMinutes);
    }

    /**
     * Header-limit argument when it is a positive number, otherwise the configured limit (default 1,000,000).
     */
    public static function resolveMaxHeaders(mixed $maxHeadersArg, int $configuredMaxHeaders): int
    {
        return self::$useRustFfi
            ? self::resolveMaxHeadersNative($maxHeadersArg, $configuredMaxHeaders)
            : self::resolveMaxHeadersPhp($maxHeadersArg, $configuredMaxHeaders);
    }

    public static function resolveMaxHeadersPhp(mixed $maxHeadersArg, int $configuredMaxHeaders): int
    {
        return is_numeric($maxHeadersArg) && $maxHeadersArg > 0
            ? (int) $maxHeadersArg
            : ($configuredMaxHeaders ?: 1000000);
    }

    public static function resolveMaxHeadersNative(mixed $maxHeadersArg, int $configuredMaxHeaders): int
    {
        if (($maxHeadersArg === null || is_scalar($maxHeadersArg) || is_array($maxHeadersArg))
            && self::callNative(['update_binaries.resolve_max_headers', $maxHeadersArg, $configuredMaxHeaders], $result) && is_int($result)) {
            return $result;
        }

        return self::resolveMaxHeadersPhp($maxHeadersArg, $configuredMaxHeaders);
    }

    /**
     * Upper-cased section title and its dash underline.
     *
     * @return array{0: string, 1: string}
     */
    public static function formatSectionHeaderLines(string $title): array
    {
        return self::$useRustFfi ? self::formatSectionHeaderLinesNative($title) : self::formatSectionHeaderLinesPhp($title);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatSectionHeaderLinesPhp(string $title): array
    {
        return [strtoupper($title), str_repeat('-', strlen($title))];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatSectionHeaderLinesNative(string $title): array
    {
        if (self::callNative(['update_binaries.format_section_header_lines', $title], $result)
            && is_array($result) && array_is_list($result) && count($result) === 2
            && is_string($result[0]) && is_string($result[1])) {
            return [$result[0], $result[1]];
        }

        return self::formatSectionHeaderLinesPhp($title);
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
