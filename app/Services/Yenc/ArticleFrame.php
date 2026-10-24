<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class ArticleFrame
{
    /**
     * @param  array<string, string>  $header
     * @param  array<string, string>|null  $part
     * @param  array<string, string>  $trailer
     */
    private function __construct(
        public array $header,
        public ?array $part,
        public array $trailer,
        public int $payloadOffset,
        public int $payloadLength,
    ) {}

    /** Payload-only callers can omit trailer fields; metadata and strict callers retain them. */
    public static function parse(string $text, bool $parseTrailerFields = true): ?self
    {
        if (strlen($text) >= 256) {
            static $ffi = null;
            static $buffer = null;
            static $spans = null;
            static $capacity = 4096;
            $ffi ??= RustPipelineLibrary::resolveDomain('yenc_frame', <<<'C'
                int32_t nntmux_yenc_frame_spans(const char *input, size_t input_len, size_t *spans);
                ssize_t nntmux_yenc_frame_record(const char *input, size_t input_len, uint8_t parse_trailer_fields, char *out, size_t out_cap);
                C) ?? false;
            if ($ffi !== false) {
                try {
                    // Canonical single-part lines are cheaper to split in PHP;
                    // richer metadata is materialized by Rust in one record.
                    if (str_starts_with($text, '=ybegin line=')) {
                        $spans ??= $ffi->new('size_t[6]');
                        $status = $ffi->nntmux_yenc_frame_spans($text, strlen($text), $spans);
                        if ($status === 0) {
                            return null;
                        }
                        if ($status === 1) {
                            $begin = (int) $spans[0];
                            $headerEnd = (int) $spans[1];
                            $partEnd = (int) $spans[2];
                            $payloadStart = (int) $spans[3];
                            $end = (int) $spans[4];
                            $trailerEnd = (int) $spans[5];

                            return new self(
                                self::nativeFields(substr($text, $begin + 7, $headerEnd - $begin - 7), true),
                                $partEnd === -1 ? null : self::nativeFields(substr($text, $headerEnd + 7, $partEnd - $headerEnd - 7)),
                                $parseTrailerFields ? self::nativeFields(substr($text, $end + 5, $trailerEnd - $end - 5)) : [],
                                $payloadStart,
                                $end - $payloadStart,
                            );
                        }
                    }
                    $buffer ??= $ffi->new("char[{$capacity}]");
                    // Only metadata and source offsets cross FFI, never the payload.
                    $written = $ffi->nntmux_yenc_frame_record($text, strlen($text), (int) $parseTrailerFields, $buffer, $capacity);
                    if (is_int($written) && $written < -1) {
                        $required = -$written;
                        $grown = $ffi->new("char[{$required}]");
                        $buffer = $grown;
                        $capacity = $required;
                        $written = $ffi->nntmux_yenc_frame_record($text, strlen($text), (int) $parseTrailerFields, $buffer, $capacity);
                    }
                    if ($written === 0) {
                        return null;
                    }
                    if (is_int($written) && $written > 0 && $written <= $capacity) {
                        // Rust emits length-delimited strings; input cannot inject structure.
                        $frame = unserialize(FFI::string($buffer, $written), ['allowed_classes' => [self::class]]);
                        if ($frame instanceof self) {
                            return $frame;
                        }
                    }
                } catch (Throwable) {
                    // Resolve failures and older libraries use the same PHP parser.
                }
            }
        }

        return self::parsePhp($text, $parseTrailerFields);
    }

    private static function parsePhp(string $text, bool $parseTrailerFields = true): ?self
    {
        $offset = 0;
        while (($begin = self::findControl($text, '=ybegin', $offset)) !== false) {
            $lineEnd = strpos($text, "\n", $begin);
            if ($lineEnd === false) {
                return null;
            }
            $header = self::fields(substr($text, $begin + 7, $lineEnd - $begin - 7), true);
            $offset = $lineEnd + 1;
            if (! isset($header['line'], $header['size'], $header['name'])) {
                continue;
            }

            $part = null;
            if (strncasecmp(substr($text, $offset, 7), '=ypart ', 7) === 0 || strncasecmp(substr($text, $offset, 7), "=ypart\t", 7) === 0) {
                $partEnd = strpos($text, "\n", $offset);
                if ($partEnd === false) {
                    return null;
                }
                $part = self::fields(substr($text, $offset + 6, $partEnd - $offset - 6));
                $offset = $partEnd + 1;
            }

            $end = self::findControl($text, '=yend', $offset);
            if ($end === false) {
                return null;
            }
            $nextBegin = self::findControl($text, '=ybegin', $offset);
            if ($nextBegin !== false && $nextBegin < $end) {
                $offset = $nextBegin;

                continue;
            }
            $trailer = [];
            if ($parseTrailerFields) {
                $trailerEnd = strpos($text, "\n", $end);
                $trailer = self::fields(substr($text, $end + 5, ($trailerEnd === false ? strlen($text) : $trailerEnd) - $end - 5));
            }

            return new self(
                $header,
                $part,
                $trailer,
                $offset,
                $end - $offset,
            );
        }

        return null;
    }

    private static function findControl(string $text, string $control, int $offset): int|false
    {
        if ($offset === 0 && strncasecmp($text, $control, strlen($control)) === 0
            && (! isset($text[strlen($control)]) || str_contains(" \t\r\n", $text[strlen($control)]))) {
            return 0;
        }
        $offset = max(0, $offset - 1);
        while (($newline = stripos($text, "\n".$control, $offset)) !== false) {
            $position = $newline + 1;
            $after = $position + strlen($control);
            if (! isset($text[$after]) || str_contains(" \t\r\n", $text[$after])) {
                return $position;
            }
            $offset = $after;
        }

        return false;
    }

    /**
     * Avoid regex match arrays for the canonical encoder's control lines. Other
     * orders, separators, duplicate keys and extra metadata use the full grammar.
     *
     * @return array<string, string>
     */
    private static function nativeFields(string $line, bool $withName = false): array
    {
        $line = rtrim($line, "\r");
        if ($withName && str_starts_with($line, ' line=')) {
            $parts = explode(' ', $line, 4);
            if (count($parts) === 4 && str_starts_with($parts[2], 'size=') && str_starts_with($parts[3], 'name=')
                && ! str_contains($parts[1], "\t") && ! str_contains($parts[2], "\t")) {
                return ['line' => substr($parts[1], 5), 'size' => substr($parts[2], 5), 'name' => substr($parts[3], 5)];
            }
        } elseif ($withName && str_starts_with($line, ' part=')) {
            if (preg_match('/\A part=([^ \t]*) total=([^ \t]*) line=([^ \t]*) size=([^ \t]*) name=(.*)\z/s', $line, $match) === 1) {
                return ['part' => $match[1], 'total' => $match[2], 'line' => $match[3], 'size' => $match[4], 'name' => $match[5]];
            }
        } elseif (! $withName && str_starts_with($line, ' begin=')) {
            $parts = explode(' ', $line, 3);
            if (count($parts) === 3 && str_starts_with($parts[2], 'end=')
                && ! str_contains($parts[1], "\t") && strpbrk($parts[2], " \t") === false) {
                return ['begin' => substr($parts[1], 6), 'end' => substr($parts[2], 4)];
            }
        } elseif (! $withName && str_starts_with($line, ' size=')) {
            $parts = explode(' ', $line, 4);
            if (! str_contains($parts[1], "\t")) {
                if (count($parts) === 2) {
                    return ['size' => substr($parts[1], 5)];
                }
                if (count($parts) === 3 && ! str_contains($parts[2], "\t")) {
                    if (str_starts_with($parts[2], 'crc32=')) {
                        return ['size' => substr($parts[1], 5), 'crc32' => substr($parts[2], 6)];
                    }
                    if (str_starts_with($parts[2], 'pcrc32=')) {
                        return ['size' => substr($parts[1], 5), 'pcrc32' => substr($parts[2], 7)];
                    }
                }
            }
        }

        return self::fields($line, $withName);
    }

    /** @return array<string, string> */
    private static function fields(string $line, bool $withName = false): array
    {
        $line = rtrim($line, "\r");
        $fields = [];
        $pattern = $withName
            ? '/(?:^|[ \t])(?|(name)=(.*)|([a-z][a-z0-9_-]*)=([^ \t]*))/is'
            : '/(?:^|[ \t])([a-z][a-z0-9_-]*)=([^ \t]*)/i';
        preg_match_all($pattern, $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $fields[$key] = $match[2];
            if ($withName && $key === 'name') {
                break;
            }
        }

        return $fields;
    }
}
