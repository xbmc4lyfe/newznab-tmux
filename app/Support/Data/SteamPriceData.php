<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Data Transfer Object for Steam Price information.
 */
#[TypeScript]
final class SteamPriceData extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_steam_price_from_api(const char *ser, size_t ser_len, double *out_initial, double *out_final, int64_t *out_discount, int32_t *out_has_fmt, char *out_buf, size_t out_cap);
        int32_t nntmux_steam_price_get_savings(double initial, double final_price, double *out_savings);
        ssize_t nntmux_steam_price_display(const char *currency, size_t currency_len, double final_price, uint32_t has_formatted, const char *formatted, size_t formatted_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        public string $currency,
        public float $initial,
        public float $final,
        #[MapOutputName('discount_percent')]
        public int $discountPercent,
        #[MapOutputName('formatted')]
        public ?string $formattedPrice = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return self::usesRustFfi()
            ? self::fromApiResponseNative($data)
            : self::fromApiResponsePhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponseNative(array $data): self
    {
        if (($ffi = self::ffi()) === null) {
            return self::fromApiResponsePhp($data);
        }
        try {
            $ser = serialize($data);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $capacity);
            if ($buffer === null) {
                return self::fromApiResponsePhp($data);
            }
            $outInitial = $ffi->new('double[1]');
            $outFinal = $ffi->new('double[1]');
            $outDiscount = $ffi->new('int64_t[1]');
            $outHasFmt = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_steam_price_from_api($ser, $len, $outInitial, $outFinal, $outDiscount, $outHasFmt, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::fromApiResponsePhp($data);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_steam_price_from_api($ser, $len, $outInitial, $outFinal, $outDiscount, $outHasFmt, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::fromApiResponsePhp($data);
            }
            $parts = explode("\0", FFI::string($buffer, $written), 2);
            if (\count($parts) !== 2) {
                return self::fromApiResponsePhp($data);
            }

            return new self(
                currency: $parts[0],
                initial: (float) $outInitial[0],
                final: (float) $outFinal[0],
                discountPercent: (int) $outDiscount[0],
                formattedPrice: (int) $outHasFmt[0] === 1 ? $parts[1] : null,
            );
        } catch (Throwable) {
            return self::fromApiResponsePhp($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponsePhp(array $data): self
    {
        return new self(
            currency: $data['currency'] ?? 'USD',
            initial: ($data['initial'] ?? 0) / 100,
            final: ($data['final'] ?? 0) / 100,
            discountPercent: $data['discount_percent'] ?? 0,
            formattedPrice: $data['final_formatted'] ?? null,
        );
    }

    public static function free(): self
    {
        return new self(
            currency: 'USD',
            initial: 0.0,
            final: 0.0,
            discountPercent: 0,
            formattedPrice: 'Free',
        );
    }

    public function isOnSale(): bool
    {
        return $this->discountPercent > 0;
    }

    public function isFree(): bool
    {
        return $this->final <= 0;
    }

    public function getSavings(): float
    {
        return self::usesRustFfi() ? $this->getSavingsNative() : $this->getSavingsPhp();
    }

    public function getSavingsNative(): float
    {
        if (($ffi = self::ffi()) === null) {
            return $this->getSavingsPhp();
        }
        try {
            $outSavings = $ffi->new('double[1]');
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_steam_price_get_savings($this->initial, $this->final, $outSavings);
            if ($res === 1) {
                return (float) $outSavings[0];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->getSavingsPhp();
    }

    public function getSavingsPhp(): float
    {
        return max(0, $this->initial - $this->final);
    }

    public function getDisplayPrice(): string
    {
        return self::usesRustFfi() ? $this->getDisplayPriceNative() : $this->getDisplayPricePhp();
    }

    public function getDisplayPriceNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->getDisplayPricePhp();
        }
        $hasFormatted = $this->formattedPrice !== null ? 1 : 0;
        $formatted = $this->formattedPrice ?? '';
        $currencyLen = \strlen($this->currency);
        $formattedLen = \strlen($formatted);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $currencyLen + $formattedLen + 64), $capacity);
        if ($buffer === null) {
            return $this->getDisplayPricePhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_steam_price_display(
                $this->currency,
                $currencyLen,
                $this->final,
                $hasFormatted,
                $formatted,
                $formattedLen,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->getDisplayPricePhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_steam_price_display(
                    $this->currency,
                    $currencyLen,
                    $this->final,
                    $hasFormatted,
                    $formatted,
                    $formattedLen,
                    $buffer,
                    $capacity,
                );
            }
            if ($written === 0) {
                return '';
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return $this->getDisplayPricePhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->getDisplayPricePhp();
        }
    }

    public function getDisplayPricePhp(): string
    {
        if ($this->formattedPrice !== null) {
            return $this->formattedPrice;
        }
        if ($this->isFree()) {
            return 'Free';
        }

        return sprintf('%s %.2f', $this->currency, $this->final);
    }
}
