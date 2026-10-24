<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RolePromotion;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class DisableExpiredPromotions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:disable-expired-promotions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable role promotions that have passed their end date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();

        // Find all active promotions that have passed their end date
        $expiredPromotions = RolePromotion::where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<', $now)
            ->get();

        if ($expiredPromotions->isEmpty()) {
            $this->info(self::formatCompletionMessage(0));

            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($expiredPromotions as $promotion) {
            $promotion->update(['is_active' => false]);
            $this->line(self::formatDisabledPromotionLine($promotion->name, $promotion->end_date->format('Y-m-d')));
            $count++;
        }

        $this->info(self::formatCompletionMessage($count));

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

    public static function formatDisabledPromotionLine(string $name, string $endDateFormatted): string
    {
        return self::useRustFfi() ? self::formatDisabledPromotionLineNative($name, $endDateFormatted) : self::formatDisabledPromotionLinePhp($name, $endDateFormatted);
    }

    public static function formatDisabledPromotionLinePhp(string $name, string $endDateFormatted): string
    {
        return "Disabled promotion: {$name} (ended: {$endDateFormatted})";
    }

    public static function formatDisabledPromotionLineNative(string $name, string $endDateFormatted): string
    {
        return self::nativePacket(6, [$name, $endDateFormatted])[0] ?? self::formatDisabledPromotionLinePhp($name, $endDateFormatted);
    }

    public static function formatCompletionMessage(int $disabledCount): string
    {
        return self::useRustFfi() ? self::formatCompletionMessageNative($disabledCount) : self::formatCompletionMessagePhp($disabledCount);
    }

    public static function formatCompletionMessagePhp(int $disabledCount): string
    {
        return $disabledCount === 0 ? 'No expired promotions found.' : "Successfully disabled {$disabledCount} expired promotion(s).";
    }

    public static function formatCompletionMessageNative(int $disabledCount): string
    {
        return self::nativePacket(7, [(string) $disabledCount])[0] ?? self::formatCompletionMessagePhp($disabledCount);
    }
}
