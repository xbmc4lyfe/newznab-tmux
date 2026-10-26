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
use FFI\CData;
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

    // ---- Wave 63.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.81x/8.88x, uncommon 6.15x/6.11x); see RustFingerprintCollectorYencDecoderPartServiceLogFileW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_YENC_SERVICE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w632_yenc_service_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W63_YENC_SERVICE_TAIL = 19;

    /**
     * Wave 63 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w63', self::W63_YENC_SERVICE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w63RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w63YencServicePlanBatch() to Rust until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Routes w63YencServicePlanBatch() to PHP until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w63YencServicePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencServicePlanBatch(array $rows): array
    {
        return self::usesW63RustFfi() ? self::w63YencServicePlanBatchNative($rows) : self::w63YencServicePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencServicePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W63_YENC_SERVICE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w63RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w63YencServicePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W63_YENC_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w632_yenc_service_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w63YencServicePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w63Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w63Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Source bytes encodePhp() escapes (those landing on NUL, tab, LF, CR, space, dot or `=`). */
    private const array W63_ESCAPED = ["\xD6" => 1, "\xDF" => 1, "\xE0" => 1, "\xE3" => 1, "\xF6" => 1, "\x04" => 1, "\x13" => 1];

    /**
     * Wave 63.2 framing plan. Per `op\tline_length\tsize\tcrc32\tpayload` row (the payload keeps any
     * further tabs) `op | status << 2 | min(lines, 65535) << 5 | min(max_line, 1023) << 21 |
     * min(escape_permille, 1023) << 31 | (crc32 & 0xFFFF) << 41 | flags << 57`. Ops (trimmed,
     * case-insensitive): 0 unknown (the word is 0), 1 `encode`, 2 `decode`. Encoding follows
     * encodePhp() with a w63Int() line length (3 digits; empty means 128, larger values clamp to
     * 254): status 1, or 2 for a length below one (no further fields); lines and the longest line
     * come from its column rule and the checksum is the payload's crc32. Decoding drops CR and LF,
     * maps `=x` to x - 106 and other bytes to b - 42 (mod 256); statuses: 6 malformed size (w63Int(),
     * 12 digits), 4 a dangling escape, 2 a declared size other than the decoded length, 3 a declared
     * checksum that is not 1-8 hex digits or differs from the decoded crc32, 5 neither declared, else
     * 1; lines split the raw payload on LF. Escape permille is escapes * 1000 / max(1, payload bytes).
     * Flags: 1 an escape marker directly before CR or LF, 2 a checksum declared, 4 a size declared.
     * Then three op and seven status counts, encoded input and output bytes, decoded input and output
     * bytes, total escapes, the longest line, the split-escape rows, the distinct checksums and the
     * XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencServicePlanBatchPhp(array $rows): array
    {
        $out = $crcs = [];
        $ops = [0, 0, 0];
        $statuses = array_fill(0, 7, 0);
        $encIn = $encOut = $decIn = $decOut = $escapesTotal = $maxLineTotal = $splitRows = 0;
        foreach ($rows as $row) {
            [$op, $line, $size, $crc, $payload] = explode("\t", (string) $row, 5) + array_fill(0, 5, '');
            $code = match (strtolower(trim($op))) {
                'encode' => 1,
                'decode' => 2,
                default => 0,
            };
            if ($code === 0) {
                $ops[0]++;
                $statuses[0]++;
                $out[] = 0;

                continue;
            }
            $crcField = trim($crc);
            $declared = (int) ($crcField !== '') << 1 | (int) (trim($size) !== '') << 2;
            $n = \strlen($payload);
            $split = false;
            if ($code === 1) {
                $length = self::w63Int($line, 3);
                $line = $length === null ? 128 : (\is_int($length) && $length >= 1 ? min($length, 254) : 0);
                if ($line === 0) {
                    $ops[1]++;
                    $statuses[2]++;
                    $out[] = 1 | 2 << 2 | $declared << 57;

                    continue;
                }
                $column = $breaks = $escapes = $maxLine = 0;
                for ($i = 0; $i < $n; $i++) {
                    $escape = isset(self::W63_ESCAPED[$payload[$i]]) ? 1 : 0;
                    if ($column >= $line) {
                        $breaks++;
                        $column = 0;
                    }
                    $column += 1 + $escape;
                    $escapes += $escape;
                    if ($column > $maxLine) {
                        $maxLine = $column;
                    }
                }
                $checksum = crc32($payload);
                $encIn += $n;
                $encOut += $n + $escapes + 2 * $breaks;
                $lines = $n > 0 ? $breaks + 1 : 0;
                $status = 1;
            } else {
                [$decoded, $escapes, $dangling] = self::w63DecodeStripped(str_replace(["\r", "\n"], '', $payload));
                $checksum = crc32($decoded);
                $length = \strlen($decoded);
                $sizeValue = self::w63Int($size, 12);
                $crcLength = \strlen($crcField);
                $crcOk = $crcLength <= 8 && strspn($crcField, '0123456789abcdefABCDEF') === $crcLength && hexdec($crcField) === $checksum;
                $status = match (true) {
                    $sizeValue === false => 6,
                    $dangling => 4,
                    \is_int($sizeValue) && $sizeValue !== $length => 2,
                    $crcField !== '' && ! $crcOk => 3,
                    $sizeValue === null && $crcField === '' => 5,
                    default => 1,
                };
                $lines = $n === 0 ? 0 : substr_count($payload, "\n") + 1;
                $maxLine = max(array_map(strlen(...), explode("\n", $payload)));
                $split = str_contains($payload, "=\r") || str_contains($payload, "=\n");
                $decIn += $n;
                $decOut += $length;
            }
            $crcs[$checksum] = true;
            $ops[$code]++;
            $statuses[$status]++;
            $escapesTotal += $escapes;
            $maxLineTotal = max($maxLineTotal, $maxLine);
            $splitRows += (int) $split;
            $out[] = $code | $status << 2 | min($lines, 0xFFFF) << 5 | min($maxLine, 1023) << 21 | min(intdiv($escapes * 1000, max(1, $n)), 1023) << 31
                | ($checksum & 0xFFFF) << 41 | ((int) $split | $declared) << 57;
        }
        array_push($out, ...$ops, ...$statuses);
        array_push($out, $encIn, $encOut, $decIn, $decOut, $escapesTotal, $maxLineTotal, $splitRows, \count($crcs));
        $out[] = self::w63Digest($out);

        return $out;
    }

    /**
     * yEnc-decodes a payload without line endings: `[decoded, escapes, dangling escape]`.
     *
     * @return array{string, int, bool}
     */
    private static function w63DecodeStripped(string $payload): array
    {
        static $from = null;
        static $to = null;
        if ($from === null || $to === null) {
            [$from, $to] = ['', ''];
            for ($b = 0; $b < 256; $b++) {
                $from .= \chr($b);
                $to .= \chr(($b - 42) & 255);
            }
        }
        $length = \strlen($payload);
        $decoded = '';
        $escapes = $offset = 0;
        while (($marker = strpos($payload, '=', $offset)) !== false) {
            $decoded .= strtr(substr($payload, $offset, $marker - $offset), $from, $to);
            if ($marker + 1 >= $length) {
                return [$decoded, $escapes, true];
            }
            $decoded .= \chr((\ord($payload[$marker + 1]) - 106) & 255);
            $escapes++;
            $offset = $marker + 2;
        }

        return [$decoded.strtr(substr($payload, $offset), $from, $to), $escapes, false];
    }
}
