<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Search\DTO\SearchCursor;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use Throwable;

final readonly class SearchCursorCodec
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_cursor_encode_payload(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_search_cursor_decode_payload(const char *input, size_t len, int64_t now, char *out, size_t cap);
        C;

    /**
     * The Rust payload codec stays opt-in: it is slower than json_encode/json_decode
     * in the paired benchmark (see RustSearchCursorAndLogIndexParityTest).
     */
    public function __construct(private Encrypter $encrypter, private bool $useRustFfi = false) {}

    public function encode(SearchCursor $cursor): string
    {
        return $this->encrypter->encrypt($this->encodePayload($cursor), false);
    }

    public function decode(string $token): SearchCursor
    {
        try {
            $json = $this->encrypter->decrypt($token, false);
        } catch (DecryptException $e) {
            throw new InvalidArgumentException('Invalid search cursor.', previous: $e);
        }

        return $this->decodePayload($json);
    }

    public function encodePayload(SearchCursor $cursor): string
    {
        return ($this->useRustFfi ? self::encodePayloadNative($cursor) : null) ?? self::encodePayloadPhp($cursor);
    }

    public function decodePayload(mixed $json): SearchCursor
    {
        return ($this->useRustFfi && is_string($json) ? self::decodePayloadNative($json, time()) : null)
            ?? self::decodePayloadPhp($json);
    }

    public static function encodePayloadPhp(SearchCursor $cursor): string
    {
        return json_encode([
            'sort' => $cursor->sortValues,
            'total' => $cursor->total,
            'query' => $cursor->queryHash,
            'driver' => $cursor->driver,
            'generation' => $cursor->indexGeneration,
            'expires' => $cursor->expiresAt,
        ], JSON_THROW_ON_ERROR);
    }

    public static function decodePayloadPhp(mixed $json): SearchCursor
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid search cursor.', previous: $e);
        }

        if (! is_array($payload) || ! is_array($payload['sort'] ?? null) || (int) ($payload['expires'] ?? 0) < time()) {
            throw new InvalidArgumentException('Invalid or expired search cursor.');
        }

        return new SearchCursor(
            sortValues: array_values($payload['sort']),
            total: (int) ($payload['total'] ?? 0),
            queryHash: (string) ($payload['query'] ?? ''),
            driver: (string) ($payload['driver'] ?? ''),
            indexGeneration: (string) ($payload['generation'] ?? ''),
            expiresAt: (int) $payload['expires'],
        );
    }

    /**
     * Rust payload encoding for integer/string sort values; null defers to PHP (floats,
     * non-list sort values, invalid UTF-8 and an unavailable library).
     */
    public static function encodePayloadNative(SearchCursor $cursor): ?string
    {
        if (! array_is_list($cursor->sortValues)) {
            return null;
        }
        $packed = $cursor->total."\n".$cursor->expiresAt."\n"
            .strlen($cursor->queryHash)."\n".$cursor->queryHash
            .strlen($cursor->driver)."\n".$cursor->driver
            .strlen($cursor->indexGeneration)."\n".$cursor->indexGeneration;
        foreach ($cursor->sortValues as $value) {
            if (is_int($value)) {
                $packed .= 'i'.$value."\n";
            } elseif (is_string($value)) {
                $packed .= 's'.strlen($value)."\n".$value;
            } else {
                return null;
            }
        }

        return self::call('nntmux_search_cursor_encode_payload', $packed, null, strlen($packed) * 6 + 128);
    }

    /**
     * Rust payload validation/decoding; throws for invalid or expired payloads and
     * returns null when PHP must decide (malformed JSON, floats, bools, nested values).
     */
    public static function decodePayloadNative(string $json, int $now): ?SearchCursor
    {
        $record = self::call('nntmux_search_cursor_decode_payload', $json, $now, strlen($json) + 128);
        if ($record === null) {
            return null;
        }
        $p = 0;
        $total = (int) self::line($record, $p);
        $expires = (int) self::line($record, $p);
        $strings = [];
        for ($i = 0; $i < 3; $i++) {
            $length = (int) self::line($record, $p);
            $strings[] = substr($record, $p, $length);
            $p += $length;
        }
        $sort = [];
        $end = strlen($record);
        while ($p < $end) {
            $tag = $record[$p++];
            if ($tag === 'i') {
                $sort[] = (int) self::line($record, $p);
            } else {
                $length = (int) self::line($record, $p);
                $sort[] = substr($record, $p, $length);
                $p += $length;
            }
        }

        return new SearchCursor($sort, $total, $strings[0], $strings[1], $strings[2], $expires);
    }

    private static function line(string $record, int &$p): string
    {
        $at = (int) strpos($record, "\n", $p);
        $line = substr($record, $p, $at - $p);
        $p = $at + 1;

        return $line;
    }

    private static function call(string $function, string $input, ?int $now, int $minBytes): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $cap);
                if ($scratch === null) {
                    return null;
                }
                $written = $now === null
                    ? $ffi->{$function}($input, strlen($input), $scratch, $cap)
                    : $ffi->{$function}($input, strlen($input), $now, $scratch, $cap);
                if (! is_int($written)) {
                    return null;
                }
                if ($written === -3) {
                    throw new InvalidArgumentException('Invalid or expired search cursor.');
                }
                if ($written >= 0 && $written <= $cap) {
                    return FFI::string($scratch, $written);
                }
                if ($written > -3) {
                    return null;
                }
                $minBytes = -$written - 3;
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
