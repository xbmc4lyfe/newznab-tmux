<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Yenc\ArticleFrame;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\Yenc\PayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\Yenc\RawPayloadDecoder;
use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

class YencService
{
    public function __construct(private readonly PayloadDecoder $decoder = new PhpPayloadDecoder) {}

    /**
     * @param  bool  $ignore  Legacy unused argument retained for compatibility.
     *
     * @throws RuntimeException
     */
    public function decode(string &$text, bool $ignore = false): string|false
    {
        $frame = ArticleFrame::parse($text);
        if ($frame === null) {
            return false;
        }
        $isMultipart = isset($frame->header['part']) || isset($frame->trailer['part']) || $frame->part !== null;
        $payload = $this->decodeFramePayload($text, $frame, true, isset($frame->trailer[$isMultipart ? 'pcrc32' : 'crc32']));
        $decoded = $payload['decoded'];
        $size = $this->number($frame->header, 'size');
        $trailerSize = $this->number($frame->trailer, 'size');
        $checksum = $frame->trailer['crc32'] ?? null;
        if (isset($frame->header['part']) || isset($frame->trailer['part']) || $frame->part !== null) {
            if ($frame->part === null) {
                throw new RuntimeException('Missing yEnc multipart range.');
            }
            $part = $this->number($frame->header, 'part');
            $begin = $this->number($frame->part, 'begin');
            $end = $this->number($frame->part, 'end');
            if ($part < 1 || (isset($frame->trailer['part']) && $part !== $this->number($frame->trailer, 'part'))
                || $begin < 1 || $end < $begin || $end > $size
                || (isset($frame->header['total']) && $part > $this->number($frame->header, 'total'))
                || (isset($frame->trailer['total']) && $this->number($frame->trailer, 'total') !== $this->number($frame->header, 'total'))) {
                throw new RuntimeException('Invalid yEnc multipart range or part number.');
            }
            $size = $end - $begin + 1;
            $checksum = $frame->trailer['pcrc32'] ?? null;
            if ($checksum === null) {
                throw new RuntimeException('Missing yEnc part checksum.');
            }
        }
        if ($size !== $trailerSize || $size !== strlen($decoded)) {
            throw new RuntimeException('Declared and decoded yEnc sizes do not match. The file is probably corrupt.');
        }
        if ($checksum !== null && (! preg_match('/\A[0-9a-f]{1,8}\z/i', $checksum)
            || strcasecmp(str_pad($checksum, 8, '0', STR_PAD_LEFT), $payload['crc32'] ?? $this->crc32($decoded)) !== 0)) {
            throw new RuntimeException('CRC32 checksums do not match. The file is probably corrupt.');
        }

        return $decoded;
    }

    public function decodeIgnore(string &$text): string
    {
        $frame = ArticleFrame::parse($text, parseTrailerFields: false);
        if ($frame !== null) {
            $text = $this->decodeFramePayload($text, $frame)['decoded'];
        }

        return $text;
    }

    /**
     * Decode leniently like decodeIgnore(), also returning what the yEnc header says about the file.
     *
     * @return array{0: string, 1: YencPart|null}
     */
    public function decodeWithFrame(string $text): array
    {
        $frame = ArticleFrame::parse($text, parseTrailerFields: false);
        if ($frame === null) {
            return [$text, null];
        }

        $decoded = $this->decodeFramePayload($text, $frame)['decoded'];

        $part = new YencPart(
            name: trim($frame->header['name']),
            size: self::metadataNumber($frame->header['size']) ?? 0,
            part: isset($frame->header['part']) ? self::metadataNumber($frame->header['part']) : null,
            begin: isset($frame->part['begin']) ? self::metadataNumber($frame->part['begin']) : null,
            end: isset($frame->part['end']) ? self::metadataNumber($frame->part['end']) : null,
        );

        return [$decoded, $part];
    }

    /** Validate reuse eligibility without changing the lenient processing result. */
    public function hasValidatedIntegrity(string $text, string $decoded): bool
    {
        $frame = ArticleFrame::parse($text);
        if ($frame === null) {
            return false;
        }
        $multipart = isset($frame->header['part']) || isset($frame->trailer['part']) || $frame->part !== null;
        if (! isset($frame->trailer[$multipart ? 'pcrc32' : 'crc32'])) {
            return false;
        }
        try {
            return $this->decode($text) === $decoded;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function enabled(): bool
    {
        return true;
    }

    public function decoderName(): string
    {
        return $this->decoder instanceof NativePayloadDecoder ? 'RapidYenc' : 'PHP';
    }

    /** RapidYenc version when the native decoder is active, null otherwise. */
    public function decoderVersion(): ?string
    {
        return $this->decoder instanceof NativePayloadDecoder ? $this->decoder->version() : null;
    }

    public function isYencEncoded(string $text): bool
    {
        return ArticleFrame::parse($text) !== null;
    }

    public function encode(string $data, string $filename, int $lineLength = 128, bool $includeCrc32 = true): string
    {
        // Preserve PHP validation (including negative lengths) before crossing the unsigned ABI.
        if (strlen($data) >= 256 && $lineLength > 0 && strpbrk($filename, "\r\n") === false) {
            static $ffi = null;
            $ffi ??= RustPipelineLibrary::resolveDomain('yenc_encode', <<<'C'
                ssize_t nntmux_yenc_encode(const char *data, size_t data_len, const char *filename, size_t filename_len, size_t line_len, uint8_t crc32_flag, char *out, size_t out_cap);
                C) ?? false;
            if ($ffi !== false) {
                try {
                    $size = strlen($data);
                    // Each input byte takes at most two bytes plus a CRLF at line length one.
                    $capacity = 0;
                    $buffer = RustPipelineLibrary::obtainScratch($ffi, $size * 4 + strlen($filename) + 128, $capacity);
                    if ($buffer !== null) {
                        $written = $ffi->nntmux_yenc_encode($data, $size, $filename, strlen($filename), min($lineLength, 254), (int) $includeCrc32, $buffer, $capacity);
                        if (is_int($written) && $written >= 0 && $written <= $capacity) {
                            return FFI::string($buffer, $written);
                        }
                    }
                } catch (Throwable) {
                    // Older libraries or unavailable FFI retain the portable implementation.
                }
            }
        }

        return $this->encodePhp($data, $filename, $lineLength, $includeCrc32);
    }

    private function encodePhp(string $data, string $filename, int $lineLength = 128, bool $includeCrc32 = true): string
    {
        $lineLength = min($lineLength, 254);
        if ($lineLength < 1) {
            throw new RuntimeException("{$lineLength} is not a valid line length.");
        }
        if (strpbrk($filename, "\r\n") !== false) {
            throw new RuntimeException('A yEnc filename cannot contain line endings.');
        }

        $size = strlen($data);
        $result = '=ybegin line='.$lineLength.' size='.$size.' name='.trim($filename)."\r\n";
        $column = 0;
        for ($offset = 0; $offset < $size; $offset++) {
            $byte = (ord($data[$offset]) + 42) & 255;
            $encoded = match ($byte) {
                0, 9, 10, 13, 32, 46, 61 => '='.chr(($byte + 64) & 255),
                default => chr($byte),
            };
            if ($column >= $lineLength) {
                $result .= "\r\n";
                $column = 0;
            }
            $result .= $encoded;
            $column += strlen($encoded);
        }
        $result .= "\r\n=yend size=".$size;
        if ($includeCrc32) {
            $result .= ' crc32='.hash('crc32b', $data);
        }

        return $result;
    }

    /** @return array{name: string|null, size: int|null, line: int|null, crc32: string|null}|null */
    public function extractMetadata(string $text): ?array
    {
        $frame = ArticleFrame::parse($text);
        if ($frame === null) {
            return null;
        }

        return [
            'name' => $frame->header['name'],
            'size' => self::metadataNumber($frame->header['size']),
            'line' => self::metadataNumber($frame->header['line']),
            'crc32' => isset($frame->trailer['crc32']) ? strtoupper($frame->trailer['crc32']) : null,
        ];
    }

    private function payloadSlice(string $text, ArticleFrame $frame): string
    {
        return substr($text, $frame->payloadOffset, $frame->payloadLength);
    }

    /** @return array{decoded: string, crc32: string|null} */
    private function decodeFramePayload(string $text, ArticleFrame $frame, bool $strict = false, bool $withCrc = false): array
    {
        if (self::hasDanglingEscape($text, $frame->payloadOffset, $frame->payloadLength)) {
            if ($strict) {
                throw new RuntimeException('Unmatched yEnc escape marker. The file is probably corrupt.');
            }

            return [
                'decoded' => (new PhpPayloadDecoder)->decode($this->stripLineEndings($this->payloadSlice($text, $frame))),
                'crc32' => null,
            ];
        }
        if ($this->decoder instanceof NativePayloadDecoder) {
            $decoded = $this->decoder->decodeSpan($text, $frame->payloadOffset, $frame->payloadLength, $withCrc);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return ['decoded' => $this->decodePayload($this->payloadSlice($text, $frame)), 'crc32' => null];
    }

    private function decodePayload(string $slice): string
    {
        if ($this->decoder instanceof NativePayloadDecoder && $this->decoder->supportsSplitEscapes()) {
            return $this->decoder->decodeRaw($slice);
        }
        if ($this->decoder instanceof RawPayloadDecoder) {
            // Search line endings rather than every escape; split escapes need stripped recovery.
            $splitEscape = preg_match('/(?<==)[\r\n]/', $slice);
            if ($splitEscape === false) {
                throw new RuntimeException('Unable to inspect yEnc line endings: '.preg_last_error_msg());
            }
            if ($splitEscape === 0) {
                return $this->decoder->decodeRaw($slice);
            }
        }

        return $this->decoder->decode($this->stripLineEndings($slice));
    }

    private function stripLineEndings(string $payload): string
    {
        return str_replace(["\r", "\n"], '', $payload);
    }

    private function crc32(string $decoded): string
    {
        if ($this->decoder instanceof NativePayloadDecoder) {
            $native = $this->decoder->crc32($decoded);
            if ($native !== null) {
                return $native;
            }
        }

        return hash('crc32b', $decoded);
    }

    private static function hasDanglingEscape(string $source, int $payloadOffset, int $payloadLength): bool
    {
        $count = 0;
        for ($offset = $payloadOffset + $payloadLength - 1; $offset >= $payloadOffset; $offset--) {
            if ($source[$offset] === '=') {
                $count++;
            } elseif ($source[$offset] !== "\r" && $source[$offset] !== "\n") {
                break;
            }
        }

        return ($count & 1) === 1;
    }

    /** @param array<string, string> $fields */
    private function number(array $fields, string $key): int
    {
        $number = self::metadataNumber($fields[$key] ?? '');
        if ($number === null) {
            throw new RuntimeException("Invalid or missing yEnc {$key}.");
        }

        return $number;
    }

    private static function metadataNumber(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }
        $value = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all nine runs (min 3.53x; see the W44 parity test). */
    public const bool W441_RUST_FFI_DEFAULT = true;

    public const string W441_FFI_CDEF = <<<'C'
        ssize_t nntmux_w441_yenc_metadata_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W441_SUMMARY_KEYS = ['total_articles', 'empty_article_count', 'frame_count', 'valid_size_count', 'valid_line_count', 'multipart_count', 'valid_checksum_count', 'dangling_escape_count', 'split_escape_count'];

    private static ?bool $w441UseRustFfi = null;

    private static FFI|false|null $w441Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW441RustFfi(?bool $enable = true): void
    {
        self::$w441UseRustFfi = $enable;
    }

    public static function usesW441RustFfi(): bool
    {
        return self::$w441UseRustFfi ?? self::W441_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW441RustFfi(): void
    {
        self::$w441UseRustFfi = null;
        self::$w441Ffi = null;
    }

    /**
     * Summarizes the decode() pre-checks of article texts framed by ArticleFrame::parsePhp(): frames, a
     * metadataNumber() header size and line, multipart frames, a well-formed checksum of the kind decode()
     * selects (pcrc32 for multipart, crc32 otherwise), and payloads with a dangling escape or a line
     * ending split from its escape marker.
     * Entries come from strings, integers, or the first non-null body, text array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleMetadataBatch(array $rows): array
    {
        return self::usesW441RustFfi() ? self::summarizeArticleMetadataBatchNative($rows) : self::summarizeArticleMetadataBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleMetadataBatchNative(array $rows): array
    {
        $values = XrefService::w441Invoke(self::$w441Ffi, self::W441_FFI_CDEF, 'nntmux_w441_yenc_metadata_batch', $rows, ['body', 'text'], \count(self::W441_SUMMARY_KEYS));

        return $values === null ? self::summarizeArticleMetadataBatchPhp($rows) : array_combine(self::W441_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleMetadataBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W441_SUMMARY_KEYS, 0);
        foreach (XrefService::w441Entries($rows, ['body', 'text']) as $entry) {
            $summary['total_articles']++;
            if ($entry === '') {
                $summary['empty_article_count']++;

                continue;
            }
            $frame = ArticleFrame::parsePhp($entry);
            if ($frame === null) {
                continue;
            }
            $multipart = isset($frame->header['part']) || isset($frame->trailer['part']) || $frame->part !== null;
            $checksum = $frame->trailer[$multipart ? 'pcrc32' : 'crc32'] ?? null;
            $summary['frame_count']++;
            $summary['valid_size_count'] += (int) (self::metadataNumber($frame->header['size']) !== null);
            $summary['valid_line_count'] += (int) (self::metadataNumber($frame->header['line']) !== null);
            $summary['multipart_count'] += (int) $multipart;
            $summary['valid_checksum_count'] += (int) ($checksum !== null && preg_match('/\A[0-9a-f]{1,8}\z/i', $checksum) === 1);
            $summary['dangling_escape_count'] += (int) self::hasDanglingEscape($entry, $frame->payloadOffset, $frame->payloadLength);
            $summary['split_escape_count'] += (int) (preg_match('/(?<==)[\r\n]/', substr($entry, $frame->payloadOffset, $frame->payloadLength)) === 1);
        }

        return $summary;
    }
}
