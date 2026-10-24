<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Appends consecutive articles of one NZB file to a local file.
 *
 * Every article's yEnc header must say it starts where the previous one ended,
 * so a missing article or out-of-order NZB never produces a file with a hole in it.
 */
class MediaArticleFetcher
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_fetcher_safe_witness_name(const char *ptr, size_t len);
        int64_t nntmux_fetcher_chunk_length(int64_t data_len, int64_t length);
        C;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly UsenetDownloadService $downloadService) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  list<string>  $segments  the NZB file's message IDs, in order
     * @param  int|null  $expectedOffset  file offset the first article must start at, or null to accept any
     * @param  float  $deadline  hrtime seconds after which no article is requested
     * @return int bytes appended to $path
     */
    public function append(
        ReleaseProcessingContext $context,
        array $segments,
        int $fromSegment,
        ?int $expectedOffset,
        int $maxBytes,
        string $path,
        MediaByteBudget $budget,
        float $deadline,
        int $articleEstimate,
    ): int {
        $written = 0;

        for ($index = max(0, $fromSegment); $index < count($segments) && $written < $maxBytes; $index++) {

            if ($context->groupUnavailable || $budget->exhausted() || hrtime(true) / 1e9 >= $deadline || ! $budget->allows($articleEstimate)) {
                break;
            }

            $result = $this->downloadService->downloadArticle(
                DownloadKind::MediaSource,
                (string) $segments[$index],
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;

                break;
            }
            if (! $result['success'] || ! is_string($result['data'])) {
                break;
            }

            $budget->spend(strlen($result['data']));
            // A cached copy has no yEnc header; only a file's first article is placed without one.
            $offset = $result['part']?->offset() ?? ($index === 0 && $expectedOffset === 0 ? 0 : null);
            if ($offset === null || ($expectedOffset !== null && $offset !== $expectedOffset)) {
                break;
            }

            $chunk = substr($result['data'], 0, self::chunkLength(strlen($result['data']), $maxBytes - $written));
            if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
                break;
            }
            $part = $result['part'];
            if ($index === 0 && $expectedOffset === 0 && $part !== null && $part->offset() === 0
                && ($part->part === null || $part->part === 1) && $part->size > 0 && strlen($result['data']) <= $part->size
                && $context->nzbSourceDigest !== null && self::isSafeWitnessName($part->name)
                && ! ArchiveExtractionService::hasArchiveSignature($result['data'])
                && ($container = VideoContainer::detect($result['data'])) !== null
                && $container === VideoContainer::fromFileName('video.'.pathinfo($part->name, PATHINFO_EXTENSION))) {
                $context->directVideoWitnesses[hash('sha256', (string) $segments[$index])] = [
                    'name' => $part->name, 'size' => $part->size, 'digest' => $context->nzbSourceDigest,
                ];
            }
            $written += strlen($chunk);
            $expectedOffset = $offset + strlen($result['data']);
        }

        return $written;
    }

    /**
     * A bare, printable UTF-8 file name that may vouch for a directly posted video.
     */
    public static function isSafeWitnessName(string $name): bool
    {
        return (self::$useRustFfi ? self::isSafeWitnessNameNative($name) : null) ?? self::isSafeWitnessNamePhp($name);
    }

    public static function isSafeWitnessNamePhp(string $name): bool
    {
        return $name !== '' && strlen($name) <= 4096
            && mb_check_encoding($name, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1
            && basename($name) === $name && ! str_contains($name, '\\')
            && ! in_array($name[0], ['.', '-'], true);
    }

    public static function isSafeWitnessNameNative(string $name): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_fetcher_safe_witness_name($name, strlen($name));
        } catch (Throwable) {
            return null;
        }

        return match ($code) {
            0 => false,
            1 => true,
            default => null,
        };
    }

    /**
     * Byte length of `substr($data, 0, $length)` for a `$dataLength`-byte string.
     */
    public static function chunkLength(int $dataLength, int $length): int
    {
        return (self::$useRustFfi ? self::chunkLengthNative($dataLength, $length) : null) ?? self::chunkLengthPhp($dataLength, $length);
    }

    public static function chunkLengthPhp(int $dataLength, int $length): int
    {
        if ($dataLength < 0) {
            return 0;
        }

        return $length >= 0 ? min($length, $dataLength) : max(0, $dataLength + $length);
    }

    public static function chunkLengthNative(int $dataLength, int $length): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $value = (int) $ffi->nntmux_fetcher_chunk_length($dataLength, $length);
        } catch (Throwable) {
            return null;
        }

        return $value >= 0 ? $value : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
