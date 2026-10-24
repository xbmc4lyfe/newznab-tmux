<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * What is known about a partial media file handed to ffmpeg.
 */
final readonly class MediaSourceHints
{
    public const string FFI_CDEF = <<<'C'
        double nntmux_media_available_fraction(uint32_t position, uint8_t has_real, int64_t real, int64_t available, int32_t *status);
        int32_t nntmux_media_head_hint(uint8_t has_real, int64_t real, int64_t available, int64_t *real_out);
        C;

    private const array POSITION_CODES = ['complete' => 0, 'head' => 1, 'mid' => 2];

    /**
     * @param  int|null  $realSize  size of the whole file, when the release told us
     * @param  int  $availableBytes  bytes of it that were downloaded
     */
    public function __construct(
        public SourcePosition $position,
        public ?int $realSize = null,
        public int $availableBytes = 0,
        // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
        public bool $useRustFfi = false,
    ) {}

    public static function complete(): self
    {
        return new self(SourcePosition::Complete);
    }

    public static function head(?int $realSize, int $availableBytes, bool $useRustFfi = false): self
    {
        return ($useRustFfi ? self::headNative($realSize, $availableBytes) : null) ?? self::headPhp($realSize, $availableBytes);
    }

    public static function headPhp(?int $realSize, int $availableBytes): self
    {
        if ($realSize !== null && $realSize > 0 && $availableBytes >= $realSize) {
            return new self(SourcePosition::Complete, $realSize, $availableBytes);
        }

        return new self(SourcePosition::Head, $realSize !== null && $realSize > 0 ? $realSize : null, $availableBytes);
    }

    public static function headNative(?int $realSize, int $availableBytes): ?self
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $real = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_media_head_hint($realSize !== null ? 1 : 0, $realSize ?? 0, $availableBytes, FFI::addr($real));
            // @phpstan-ignore property.notFound
            $normalized = (int) $real->cdata;
        } catch (Throwable) {
            return null;
        }

        return match ($code) {
            0 => new self(SourcePosition::Complete, $normalized, $availableBytes),
            1 => new self(SourcePosition::Head, $normalized, $availableBytes),
            2 => new self(SourcePosition::Head, null, $availableBytes),
            default => null,
        };
    }

    public static function mid(int $availableBytes = 0): self
    {
        return new self(SourcePosition::Mid, null, $availableBytes);
    }

    /**
     * Fraction of the file that was downloaded, when the real size is known.
     */
    public function availableFraction(): ?float
    {
        if (! $this->useRustFfi) {
            return $this->availableFractionPhp();
        }
        $native = $this->availableFractionNative();

        return $native === false ? $this->availableFractionPhp() : $native;
    }

    public function availableFractionPhp(): ?float
    {
        if ($this->position === SourcePosition::Complete) {
            return 1.0;
        }

        if ($this->realSize === null || $this->availableBytes <= 0) {
            return null;
        }

        return min(1.0, $this->availableBytes / $this->realSize);
    }

    /**
     * @return float|false|null False when PHP must evaluate the fraction (Rust unavailable or a zero size).
     */
    public function availableFractionNative(): float|false|null
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return false;
        }
        try {
            $status = $ffi->new('int32_t');
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_media_available_fraction(self::POSITION_CODES[$this->position->value], $this->realSize !== null ? 1 : 0, $this->realSize ?? 0, $this->availableBytes, FFI::addr($status));
            // @phpstan-ignore property.notFound
            $code = (int) $status->cdata;
        } catch (Throwable) {
            return false;
        }

        return match ($code) {
            0 => null,
            1 => (float) $value,
            default => false,
        };
    }

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
