<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'cloudflare:reload')]
class CloudflareReload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudflare:reload
                            {--dry-run : Fetch and validate the ranges without updating the stored manifest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh the Cloudflare IP ranges used by the trusted proxy middleware';

    public function __construct(
        private readonly CloudflareIpRangeService $cloudflareIpRangeService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Fetching and validating Cloudflare trusted proxy IP ranges (dry run)...'
            : 'Refreshing Cloudflare trusted proxy IP ranges...');

        try {
            $manifest = $this->cloudflareIpRangeService->refresh(! $dryRun);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Failed to refresh Cloudflare IP ranges: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $manualProxies = $this->cloudflareIpRangeService->manualTrustedProxies();
        $totalTrustedProxies = self::mergeEffectiveProxies($manualProxies, $manifest['proxies'] ?? []);

        foreach (self::formatRangeSummaryLines(
            count($manifest['ipv4'] ?? []),
            count($manifest['ipv6'] ?? []),
            count($manifest['proxies'] ?? []),
            count($manualProxies),
            count($totalTrustedProxies),
        ) as $line) {
            $this->line($line);
        }

        if ($dryRun) {
            $this->info('Dry run complete; manifest file was not updated.');

            return Command::SUCCESS;
        }

        $this->info('Stored Cloudflare manifest: '.$this->cloudflareIpRangeService->manifestPath());
        $this->info('Last updated at: '.($manifest['updated_at'] ?? 'unknown'));

        return Command::SUCCESS;
    }

    // FFI serialization/allocation does not qualify for the 1.5x default-on gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_activity_captcha_cloudflare_expiry(unsigned int mode, const char *input, size_t input_len, char *out, size_t cap);';

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function useRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'activity-captcha-cloudflare-expiry', self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Length-prefixed binary packets preserve PHP byte strings, including NULs.
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
                if (strlen($item) > 16777216) {
                    return null;
                }
                $input .= pack('V', strlen($item)).$item;
                if (strlen($input) > 16777216) {
                    return null;
                }
            }
            $cap = strlen($input) + 256;
            $out = $ffi->new("char[{$cap}]");
            $written = self::callFfi($ffi, 'nntmux_activity_captcha_cloudflare_expiry', $mode, $input, strlen($input), $out, $cap);
            if (is_int($written) && $written < 0 && $written >= -16777216) {
                $cap = -$written;
                $out = $ffi->new("char[{$cap}]");
                $written = self::callFfi($ffi, 'nntmux_activity_captcha_cloudflare_expiry', $mode, $input, strlen($input), $out, $cap);
            }
            if (! is_int($written) || $written < 4 || $written > $cap) {
                return null;
            }
            $packet = FFI::string($out, $written);
            $count = unpack('Vcount', $packet)['count'];
            if ($count > intdiv($written - 4, 4)) {
                return null;
            }
            $offset = 4;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                if ($offset + 4 > $written) {
                    return null;
                }
                $length = unpack('Vlength', $packet, $offset)['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($packet, $offset, $length);
                $offset += $length;
            }

            return $offset === $written ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, string>  $manualProxies
     * @param  array<array-key, string>  $manifestProxies
     * @return list<string>
     */
    public static function mergeEffectiveProxies(array $manualProxies, array $manifestProxies): array
    {
        return self::useRustFfi() ? self::mergeEffectiveProxiesNative($manualProxies, $manifestProxies) : self::mergeEffectiveProxiesPhp($manualProxies, $manifestProxies);
    }

    /**
     * @param  array<array-key, string>  $manualProxies
     * @param  array<array-key, string>  $manifestProxies
     * @return list<string>
     */
    public static function mergeEffectiveProxiesPhp(array $manualProxies, array $manifestProxies): array
    {
        return array_values(array_unique([...$manualProxies, ...$manifestProxies]));
    }

    /**
     * @param  array<array-key, string>  $manualProxies
     * @param  array<array-key, string>  $manifestProxies
     * @return list<string>
     */
    public static function mergeEffectiveProxiesNative(array $manualProxies, array $manifestProxies): array
    {
        $items = array_values([...$manualProxies, ...$manifestProxies]);
        foreach ($items as $item) {
            if (! is_string($item)) {
                return self::mergeEffectiveProxiesPhp($manualProxies, $manifestProxies);
            }
        }

        return self::nativePacket(4, $items) ?? self::mergeEffectiveProxiesPhp($manualProxies, $manifestProxies);
    }

    /** @return list<string> */
    public static function formatRangeSummaryLines(int $v4Count, int $v6Count, int $allCount, int $manualCount, int $effectiveCount): array
    {
        return self::useRustFfi() ? self::formatRangeSummaryLinesNative($v4Count, $v6Count, $allCount, $manualCount, $effectiveCount) : self::formatRangeSummaryLinesPhp($v4Count, $v6Count, $allCount, $manualCount, $effectiveCount);
    }

    /** @return list<string> */
    public static function formatRangeSummaryLinesPhp(int $v4Count, int $v6Count, int $allCount, int $manualCount, int $effectiveCount): array
    {
        return [
            "Cloudflare IPv4 ranges: {$v4Count}",
            "Cloudflare IPv6 ranges: {$v6Count}",
            "Combined Cloudflare proxy count: {$allCount}",
            "Manual proxy count: {$manualCount}",
            "Effective trusted proxy count: {$effectiveCount}",
        ];
    }

    /** @return list<string> */
    public static function formatRangeSummaryLinesNative(int $v4Count, int $v6Count, int $allCount, int $manualCount, int $effectiveCount): array
    {
        return self::nativePacket(5, [(string) $v4Count, (string) $v6Count, (string) $allCount, (string) $manualCount, (string) $effectiveCount]) ?? self::formatRangeSummaryLinesPhp($v4Count, $v6Count, $allCount, $manualCount, $effectiveCount);
    }
}
