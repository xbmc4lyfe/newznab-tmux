<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use FFI;
use FFI\CData;
use FFI\Exception as FfiException;
use RuntimeException;

/** Explicit experimental capability; never selected by the decoder factory. */
final class ExperimentalStrictArticleDecoder
{
    private const MAX_SOURCE_BYTES = 67_108_864;

    private const MAX_CACHED_BYTES = 65_536;

    /** @var array<string, array{library: FFI|null, reason: string|null}> */
    private static array $libraries = [];

    private readonly ?FFI $library;

    private readonly ?string $unavailabilityReason;

    private ?CData $output = null;

    private int $capacity = 0;

    private ?CData $result = null;

    private bool $borrowed = false;

    public function __construct(string $path)
    {
        $library = null;
        $reason = 'unsupported_runtime';
        if (PHP_SAPI === 'cli' && in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && extension_loaded('FFI')) {
            $resolved = realpath($path);
            $reason = 'library_absent';
            if ($resolved !== false && is_file($resolved)) {
                $key = getmypid().':'.$resolved;
                if (! array_key_exists($key, self::$libraries)) {
                    try {
                        $library = FFI::cdef(<<<'C'
                            typedef struct { uint64_t written; uint32_t crc32; uint32_t flags; } nntmux_strict_article_result_v1;
                            uint32_t nntmux_yenc_decode_strict_article_v1(const void*, size_t, void*, size_t, uint64_t, nntmux_strict_article_result_v1*);
                            C, $resolved);
                        $reason = null;
                        if (! self::qualify($library)) {
                            $library = null;
                            $reason = 'known_answer_failed';
                        }
                    } catch (FfiException) {
                        $library = null;
                        $reason = 'symbol_or_ffi_unavailable';
                    }
                    self::$libraries[$key] = ['library' => $library, 'reason' => $reason];
                }
                ['library' => $library, 'reason' => $reason] = self::$libraries[$key];
            }
        }
        $this->library = $library;
        $this->unavailabilityReason = $reason;
    }

    public function __clone(): void
    {
        $this->output = null;
        $this->capacity = 0;
        $this->result = null;
        $this->borrowed = false;
    }

    public function isAvailable(): bool
    {
        return $this->library !== null;
    }

    public function unavailabilityReason(): ?string
    {
        return $this->unavailabilityReason;
    }

    public function decode(string $article): ?string
    {
        if ($this->library === null) {
            return null;
        }
        $length = strlen($article);
        if ($length === 0 || $length > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $cache = ! $this->borrowed;
        if ($cache) {
            $this->borrowed = true;
        }
        try {
            if ($cache && $length <= self::MAX_CACHED_BYTES) {
                if ($this->capacity < $length) {
                    $capacity = max(256, $this->capacity);
                    while ($capacity < $length) {
                        $capacity *= 2;
                    }
                    $this->output = $this->library->new("char[{$capacity}]");
                    $this->capacity = $capacity;
                }
                $output = $this->output;
            } else {
                $output = $this->library->new("char[{$length}]");
            }
            $result = $cache
                ? ($this->result ??= $this->library->new('nntmux_strict_article_result_v1[1]'))
                : $this->library->new('nntmux_strict_article_result_v1[1]');
            /** @var int $status */
            $status = $this->library->nntmux_yenc_decode_strict_article_v1( // @phpstan-ignore method.notFound
                $article, $length, $output, $length, PHP_INT_MAX, $result,
            );
            // Unsupported grammar leaves the result untouched; do not read it.
            if ($status === 1) {
                return null;
            }
            if (! in_array($status, [0, 3, 4, 5, 6, 7], true)) {
                throw new RuntimeException('Experimental Rust yEnc contract failure.');
            }
            $written = $result[0]->written;
            $flags = $result[0]->flags;
            $crc = $result[0]->crc32;
            $allowed = match ($status) {
                0, 6 => [0, 2, 3],
                3 => [0, 1],
                4 => [1, 3],
                5 => [1],
                7 => [2, 3],
            };
            if (! is_int($written) || $written < 0 || $written > $length
                || ! in_array($flags, $allowed, true)
                || (($flags & 2) === 0 && $crc !== 0)
                || ($status === 3 && $written !== 0)) {
                throw new RuntimeException('Experimental Rust yEnc contract failure.');
            }
            if ($status !== 0) {
                throw new RuntimeException(match ($status) {
                    3 => 'Unmatched yEnc escape marker. The file is probably corrupt.',
                    4 => 'Invalid yEnc multipart range or part number.',
                    5 => 'Missing yEnc part checksum.',
                    6 => 'Declared and decoded yEnc sizes do not match. The file is probably corrupt.',
                    7 => 'CRC32 checksums do not match. The file is probably corrupt.',
                });
            }

            return $written === 0 ? '' : FFI::string($output, $written);
        } catch (FfiException $exception) {
            throw new RuntimeException('Experimental Rust yEnc call failed.', previous: $exception);
        } finally {
            if ($cache) {
                $this->borrowed = false;
            }
        }
    }

    /** Cold, guarded probes never borrow instance buffers or the incumbent loader. */
    private static function qualify(FFI $library): bool
    {
        if (FFI::sizeof($library->new('nntmux_strict_article_result_v1')) !== 16) {
            return false;
        }
        $single = "=ybegin line=128 size=3 name=x\nklm\n";
        $multi = "=ybegin part=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n";
        foreach ([
            [$single.'=yend size=3 crc32=a3830348', 0, 'ABC', 0xA3830348, 2, false],
            [$single.'=yend size=3', 0, 'ABC', 0, 0, false],
            ["=ybegin line= size=0 name=\n=yend size=0 crc32=0", 0, '', 0, 2, false],
            [$multi.'=yend size=3 pcrc32=a3830348', 0, 'ABC', 0xA3830348, 3, false],
            ["=ybegin line=128 size=4 name=x\nk=\r\n@lm\n=yend size=4 crc32=a672946b", 0, "A\xd6BC", 0xA672946B, 2, false],
            ["=ybegin line=128 size=3 name=x\nk=\n=yend size=3", 3, '', 0, 0, false],
            [str_replace('part=1', 'part=0', $multi).'=yend size=3', 4, 'ABC', 0, 1, false],
            [$multi.'=yend size=99', 5, 'ABC', 0, 1, false],
            [$single.'=yend size=99 crc32=a3830348', 6, 'ABC', 0xA3830348, 2, false],
            [$single.'=yend size=3 crc32=bad', 7, 'ABC', 0xA3830348, 2, false],
            [$single.'=yend size=3 name=ignored crc32=deadbeef', 1, '', 0, 0, false],
            [$single.'=yend size=3 crc32=a3830348', 2, '', 0, 0, true],
        ] as [$article, $expectedStatus, $decoded, $crc, $flags, $shortCapacity]) {
            $length = strlen($article);
            $storage = $library->new('char['.($length + 32).']');
            $record = $library->new('char[48]');
            FFI::memset($storage, 0xA5, $length + 32);
            FFI::memset($record, 0xA5, 48);
            $result = $library->cast('nntmux_strict_article_result_v1*', FFI::addr($record[16]));
            $status = $library->nntmux_yenc_decode_strict_article_v1( // @phpstan-ignore method.notFound
                $article, $length, FFI::addr($storage[16]), $length - (int) $shortCapacity, PHP_INT_MAX, $result,
            );
            if ($status !== $expectedStatus
                || FFI::string($storage, $length + 32) !== str_repeat("\xa5", 16).$decoded.str_repeat("\xa5", $length + 16 - strlen($decoded))) {
                return false;
            }
            if ($status === 1 || $status === 2) {
                if (FFI::string($record, 48) !== str_repeat("\xa5", 48)) {
                    return false;
                }
            } elseif ($result[0]->written !== strlen($decoded) || $result[0]->crc32 !== $crc || $result[0]->flags !== $flags
                || FFI::string($record, 16) !== str_repeat("\xa5", 16)
                || FFI::string(FFI::addr($record[32]), 16) !== str_repeat("\xa5", 16)) {
                return false;
            }
        }

        return true;
    }
}
