<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class CaptchaStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'captcha:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check CAPTCHA configuration status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('CAPTCHA Configuration Status');
        $this->info('================================');
        $this->newLine();

        $provider = config('captcha.provider', 'recaptcha');
        $this->line("Active Provider: <fg=cyan>{$provider}</>");
        $this->newLine();

        // Check reCAPTCHA
        $this->info('Google reCAPTCHA:');
        $recaptchaEnabled = config('captcha.recaptcha.enabled');
        $recaptchaSitekey = config('captcha.recaptcha.sitekey');
        $recaptchaSecret = config('captcha.recaptcha.secret');

        $this->line('  Enabled: '.($recaptchaEnabled ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line('  Site Key: '.(! empty($recaptchaSitekey) ? '<fg=green>Configured</>' : '<fg=red>Missing</>'));
        $this->line('  Secret: '.(! empty($recaptchaSecret) ? '<fg=green>Configured</>' : '<fg=red>Missing</>'));
        $this->newLine();

        // Check Turnstile
        $this->info('Cloudflare Turnstile:');
        $turnstileEnabled = config('captcha.turnstile.enabled');
        $turnstileSitekey = config('captcha.turnstile.sitekey');
        $turnstileSecret = config('captcha.turnstile.secret');

        $this->line('  Enabled: '.($turnstileEnabled ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line('  Site Key: '.(! empty($turnstileSitekey) ? '<fg=green>Configured</>' : '<fg=red>Missing</>'));
        $this->line('  Secret: '.(! empty($turnstileSecret) ? '<fg=green>Configured</>' : '<fg=red>Missing</>'));
        $this->newLine();

        // Validation
        $readiness = self::evaluateCaptchaReadiness(
            (bool) $recaptchaEnabled, ! empty($recaptchaSitekey), ! empty($recaptchaSecret),
            (bool) $turnstileEnabled, ! empty($turnstileSitekey), ! empty($turnstileSecret),
        );
        $recaptchaReady = $readiness['recaptcha_ready'];
        $turnstileReady = $readiness['turnstile_ready'];

        if ($readiness['conflict']) {
            $this->error('⚠ WARNING: Both providers are enabled!');
            $this->warn('Only one CAPTCHA provider should be enabled at a time.');
            $this->warn("The system will use: {$provider}");
            $this->newLine();
        }

        if ($provider === 'recaptcha' && $recaptchaReady) {
            $this->info('✓ reCAPTCHA is properly configured and active');
        } elseif ($provider === 'turnstile' && $turnstileReady) {
            $this->info('✓ Turnstile is properly configured and active');
        } elseif ($provider === 'recaptcha' && ! $recaptchaReady) {
            $this->error('✗ reCAPTCHA is selected but not properly configured');
        } elseif ($provider === 'turnstile' && ! $turnstileReady) {
            $this->error('✗ Turnstile is selected but not properly configured');
        } else {
            $this->warn('⚠ No CAPTCHA provider is active');
        }

        $this->newLine();
        $this->comment('To change providers, update CAPTCHA_PROVIDER in your .env file');
        $this->comment('Then run: php artisan config:clear');

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

    /** @return array{recaptcha_ready: bool, turnstile_ready: bool, conflict: bool, active_provider: ?string} */
    public static function evaluateCaptchaReadiness(bool $recaptchaEnabled, bool $hasRecaptchaSitekey, bool $hasRecaptchaSecret, bool $turnstileEnabled, bool $hasTurnstileSitekey, bool $hasTurnstileSecret): array
    {
        return self::useRustFfi() ? self::evaluateCaptchaReadinessNative($recaptchaEnabled, $hasRecaptchaSitekey, $hasRecaptchaSecret, $turnstileEnabled, $hasTurnstileSitekey, $hasTurnstileSecret) : self::evaluateCaptchaReadinessPhp($recaptchaEnabled, $hasRecaptchaSitekey, $hasRecaptchaSecret, $turnstileEnabled, $hasTurnstileSitekey, $hasTurnstileSecret);
    }

    /** @return array{recaptcha_ready: bool, turnstile_ready: bool, conflict: bool, active_provider: ?string} */
    public static function evaluateCaptchaReadinessPhp(bool $recaptchaEnabled, bool $hasRecaptchaSitekey, bool $hasRecaptchaSecret, bool $turnstileEnabled, bool $hasTurnstileSitekey, bool $hasTurnstileSecret): array
    {
        $recaptchaReady = $recaptchaEnabled && $hasRecaptchaSitekey && $hasRecaptchaSecret;
        $turnstileReady = $turnstileEnabled && $hasTurnstileSitekey && $hasTurnstileSecret;

        return [
            'recaptcha_ready' => $recaptchaReady,
            'turnstile_ready' => $turnstileReady,
            'conflict' => $recaptchaReady && $turnstileReady,
            'active_provider' => $recaptchaReady ? 'recaptcha' : ($turnstileReady ? 'turnstile' : null),
        ];
    }

    /** @return array{recaptcha_ready: bool, turnstile_ready: bool, conflict: bool, active_provider: ?string} */
    public static function evaluateCaptchaReadinessNative(bool $recaptchaEnabled, bool $hasRecaptchaSitekey, bool $hasRecaptchaSecret, bool $turnstileEnabled, bool $hasTurnstileSitekey, bool $hasTurnstileSecret): array
    {
        $result = self::nativePacket(3, array_map(static fn (bool $flag): string => $flag ? '1' : '0', [$recaptchaEnabled, $hasRecaptchaSitekey, $hasRecaptchaSecret, $turnstileEnabled, $hasTurnstileSitekey, $hasTurnstileSecret]));
        if ($result === null || count($result) !== 4) {
            return self::evaluateCaptchaReadinessPhp($recaptchaEnabled, $hasRecaptchaSitekey, $hasRecaptchaSecret, $turnstileEnabled, $hasTurnstileSitekey, $hasTurnstileSecret);
        }

        return [
            'recaptcha_ready' => $result[0] === '1',
            'turnstile_ready' => $result[1] === '1',
            'conflict' => $result[2] === '1',
            'active_provider' => $result[3] === '' ? null : $result[3],
        ];
    }
}
