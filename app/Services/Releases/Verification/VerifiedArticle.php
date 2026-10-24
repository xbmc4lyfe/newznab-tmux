<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use RuntimeException;

final readonly class VerifiedArticle
{
    private function __construct(
        public string $name,
        public int $size,
        public int $offset,
        public int $part,
        public ?int $totalParts,
        public string $bytes,
        public ?string $fileChecksum,
    ) {}

    public static function decode(string $raw, ?YencService $decoder = null): self
    {
        return self::decodePhp($raw, $decoder);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        static $useRustFfi = true;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function decodeNative(string $raw, ?YencService $decoder = null): ?self
    {
        if (! self::useRustFfi() || PreparedManifest::nativeLibrary() === null) {
            return null;
        }

        return self::decodeFrame($raw, $decoder, true);
    }

    public static function decodePhp(string $raw, ?YencService $decoder = null): self
    {
        return self::decodeFrame($raw, $decoder, false);
    }

    private static function decodeFrame(string $raw, ?YencService $decoder, bool $native): self
    {
        $frame = ArticleFrame::parse($raw);
        if ($frame === null) {
            throw new RuntimeException('Unsupported article encoding.');
        }
        $name = $frame->header['name'];
        $reason = $native ? self::framingRejectionNative($raw) : self::framingRejectionPhp($frame);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        $totalParts = isset($frame->header['total']) ? (int) $frame->header['total'] : null;
        try {
            $decoded = ($decoder ?? new YencService)->decode($raw);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'CRC32 checksums do not match. The file is probably corrupt.') {
                throw new ArticleIntegrityFailure($error->getMessage(), previous: $error);
            }
            throw $error;
        }
        if ($decoded === false) {
            throw new RuntimeException('Article decoding failed.');
        }

        return new self($name, (int) $frame->header['size'], (int) ($frame->part['begin'] ?? 1) - 1,
            (int) ($frame->header['part'] ?? 1), $totalParts, $decoded, isset($frame->trailer['crc32']) ? strtolower(str_pad($frame->trailer['crc32'], 8, '0', STR_PAD_LEFT)) : null);
    }

    public static function framingRejectionPhp(ArticleFrame $frame): ?string
    {
        $name = $frame->header['name'];
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || strlen($name) > 200 || $name[0] === '.' || $name[0] === '-'
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)) {
            return 'Unsafe article filename.';
        }
        $numbers = [$frame->header['size'], $frame->header['part'] ?? '1', $frame->part['begin'] ?? '1'];
        if (isset($frame->header['total'])) {
            $numbers[] = $frame->header['total'];
        }
        foreach ($numbers as $number) {
            if (! ctype_digit($number) || strlen($number) > 18 || (int) $number < 1) {
                return 'Invalid article dimensions.';
            }
        }
        $totalParts = isset($frame->header['total']) ? (int) $frame->header['total'] : null;
        if ($totalParts !== null && (int) ($frame->header['part'] ?? 1) > $totalParts) {
            return 'Article part number exceeds its declared total.';
        }
        if (! isset($frame->trailer[$frame->part === null ? 'crc32' : 'pcrc32'])) {
            return 'Article checksum is required.';
        }

        return null;
    }

    public static function framingRejectionNative(string $raw): ?string
    {
        $ffi = PreparedManifest::nativeLibrary();
        if (! self::useRustFfi() || $ffi === null) {
            return self::framingRejectionPhp(ArticleFrame::parse($raw) ?? throw new RuntimeException('Unsupported article encoding.'));
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_article_framing($raw, strlen($raw));

        return match ($status) {
            0 => null,
            1 => 'Unsafe article filename.',
            2 => 'Invalid article dimensions.',
            3 => 'Article part number exceeds its declared total.',
            4 => 'Article checksum is required.',
            default => throw new RuntimeException('Unsupported article encoding.'),
        };
    }
}
