<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RegistrationStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'nntmux:disable-expired-registration-periods')]
class DisableExpiredRegistrationPeriods extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:disable-expired-registration-periods';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable completed registration periods and mark them as done';

    public function __construct(
        private readonly RegistrationStatusService $registrationStatusService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiredPeriods = $this->registrationStatusService->disableExpiredPeriods();

        if ($expiredPeriods->isEmpty()) {
            $this->info(self::formatCompletionMessage(0));

            return Command::SUCCESS;
        }

        foreach ($expiredPeriods as $period) {
            $this->line(self::formatCompletedPeriodLine(
                $period->name,
                $period->ends_at->format('Y-m-d H:i')
            ));
        }

        $this->info(self::formatCompletionMessage($expiredPeriods->count()));

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

    public static function formatCompletedPeriodLine(string $name, string $endsAtFormatted): string
    {
        return self::useRustFfi() ? self::formatCompletedPeriodLineNative($name, $endsAtFormatted) : self::formatCompletedPeriodLinePhp($name, $endsAtFormatted);
    }

    public static function formatCompletedPeriodLinePhp(string $name, string $endsAtFormatted): string
    {
        return "Marked registration period as done: {$name} (ended: {$endsAtFormatted})";
    }

    public static function formatCompletedPeriodLineNative(string $name, string $endsAtFormatted): string
    {
        return self::nativePacket(8, [$name, $endsAtFormatted])[0] ?? self::formatCompletedPeriodLinePhp($name, $endsAtFormatted);
    }

    public static function formatCompletionMessage(int $completedCount): string
    {
        return self::useRustFfi() ? self::formatCompletionMessageNative($completedCount) : self::formatCompletionMessagePhp($completedCount);
    }

    public static function formatCompletionMessagePhp(int $completedCount): string
    {
        return $completedCount === 0 ? 'No expired registration periods found.' : "Successfully completed {$completedCount} expired registration period(s).";
    }

    public static function formatCompletionMessageNative(int $completedCount): string
    {
        return self::nativePacket(9, [(string) $completedCount])[0] ?? self::formatCompletionMessagePhp($completedCount);
    }
}
