<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * A video stored uncompressed in a downloaded archive volume, and the bytes of it we hold.
 */
final readonly class ArchivedMediaEntry
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_media_continuation_bytes(int64_t range_start, int64_t head_length, int64_t volume_bytes_downloaded, int64_t in_volume_end, int64_t real_size, int64_t target);
        C;

    /**
     * @param  int  $sourceIndex  NZB file of the volume
     * @param  string  $headBytes  start of the video, as far as it was downloaded
     * @param  int  $rangeStart  offset of the video's first byte in the volume
     * @param  int  $inVolumeEnd  offset of the video's last byte in the volume
     * @param  int  $volumeBytesDownloaded  leading bytes of the volume that were downloaded
     * @param  int  $volumeSegmentsDownloaded  leading NZB segments those bytes came from
     */
    public function __construct(
        public string $name,
        public int $realSize,
        public int $sourceIndex,
        public string $headBytes,
        public int $rangeStart,
        public int $inVolumeEnd,
        public int $volumeBytesDownloaded,
        public int $volumeSegmentsDownloaded,
        public bool $isSample,
        public ?VideoContainer $container,
    ) {}

    public function isComplete(): bool
    {
        return strlen($this->headBytes) >= $this->realSize;
    }

    /**
     * Bytes that can still be appended from later segments of the same volume, up to $target in all.
     */
    public function continuationBytes(int $target): int
    {
        // Retains the native PHP path: five integer operations cost less than one FFI crossing
        // (0.47x mean / 0.50x p99 common, 0.41x / 0.50x uncommon).
        return $this->continuationBytesPhp($target);
    }

    public function continuationBytesPhp(int $target): int
    {
        // Only a head that runs to the end of the downloaded data can be continued.
        if ($this->rangeStart + strlen($this->headBytes) !== $this->volumeBytesDownloaded) {
            return 0;
        }

        $wanted = min($target, $this->realSize) - strlen($this->headBytes);
        $leftInVolume = $this->inVolumeEnd + 1 - $this->volumeBytesDownloaded;

        return max(0, min($wanted, $leftInVolume));
    }

    public function continuationBytesNative(int $target, bool &$supported = false): int
    {
        $supported = false;
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return $this->continuationBytesPhp($target);
        }
        try {
            // @phpstan-ignore method.notFound
            $bytes = $ffi->nntmux_media_continuation_bytes(
                $this->rangeStart,
                strlen($this->headBytes),
                $this->volumeBytesDownloaded,
                $this->inVolumeEnd,
                $this->realSize,
                $target,
            );
        } catch (Throwable) {
            return $this->continuationBytesPhp($target);
        }
        if (! is_int($bytes) || $bytes < 0) {
            return $this->continuationBytesPhp($target);
        }
        $supported = true;

        return $bytes;
    }

    /**
     * Toggle the Rust helper; readonly classes keep the state in a function static.
     */
    public static function useRustFfi(bool $use): void
    {
        self::rustFfi($use);
    }

    private static function rustFfi(?bool $use = null): ?FFI
    {
        static $enabled = true;
        static $resolved = false;
        static $ffi = null;
        if ($use !== null) {
            $enabled = $use;

            return null;
        }
        if (! $enabled) {
            return null;
        }
        if (! $resolved) {
            $resolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        }

        return $ffi;
    }
}
