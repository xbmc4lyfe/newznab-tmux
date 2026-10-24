<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

/**
 * Envelope metadata members carry an `envelopeMeta`/`ENVELOPE_META_` prefix: tools using this trait
 * declare their own `$useRustFfi`, `FFI_CDEF` and FFI toggles, which would otherwise collide. Those
 * tools' `usesRustFfi()`/`setUseRustFfi()` shadow the trait versions; use the prefixed toggles there.
 */
trait HasVersionedStructuredOutput
{
    private const string ENVELOPE_META_FFI_CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyReindexProxyMcpParityTest.php.
     */
    private const bool ENVELOPE_META_RUST_FFI_DEFAULT = false;

    private const array ENVELOPE_META_REQUIRED_KEYS = ['schema_version', 'summary', 'warnings', 'truncated'];

    private const array ENVELOPE_META_KNOWN_KEYS = ['schema_version', 'summary', 'evidence', 'results', 'warnings', 'truncated'];

    private static ?bool $envelopeMetaUseRustFfi = null;

    private static FFI|false|null $envelopeMetaFfi = null;

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->description('Stable response schema version.')->required(),
            'summary' => $schema->string()->description('Concise result summary.')->required(),
            'evidence' => $schema->union(['object', 'array'])->description('Collected inspection evidence when applicable.'),
            'results' => $schema->union(['object', 'array'])->description('Planned, executed, or probed results when applicable.'),
            'warnings' => $schema->array()->items($schema->string())->description('Non-fatal caveats and partial-result notices.')->required(),
            'truncated' => $schema->boolean()->description('Whether any bounded content was truncated.')->required(),
        ];
    }

    protected function structuredError(string $message): ResponseFactory
    {
        $content = [
            'schema_version' => 0,
            'summary' => 'The request was rejected.',
            'warnings' => [$message],
            'truncated' => false,
        ];
        $version = config('nntmux-mcp.schema_version', 1);
        $content['schema_version'] = is_string($version) || is_int($version)
            ? self::normalizeEnvelopeMeta((string) $version, array_keys($content))['schema_version']
            : (int) $version;

        return Response::make(Response::error($message))->withStructuredContent($content);
    }

    public static function usesRustFfi(): bool
    {
        return self::envelopeMetaUsesRustFfi();
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::setEnvelopeMetaUseRustFfi($use);
    }

    public static function envelopeMetaUsesRustFfi(): bool
    {
        return self::$envelopeMetaUseRustFfi ?? self::ENVELOPE_META_RUST_FFI_DEFAULT;
    }

    public static function setEnvelopeMetaUseRustFfi(?bool $use): void
    {
        self::$envelopeMetaUseRustFfi = $use;
    }

    /**
     * `schema_version` follows PHP `(int)` casting; integer keys count as their decimal string and
     * other non-string keys are ignored. Unknown keys are de-duplicated in first-seen order.
     *
     * @param  array<array-key, mixed>  $payloadKeys
     * @return array{schema_version: int, complete: bool, missing_required: list<string>, unknown_keys: list<string>}
     */
    public static function normalizeEnvelopeMeta(string $schemaVersion, array $payloadKeys): array
    {
        return self::envelopeMetaUsesRustFfi()
            ? self::normalizeEnvelopeMetaNative($schemaVersion, $payloadKeys)
            : self::normalizeEnvelopeMetaPhp($schemaVersion, $payloadKeys);
    }

    /**
     * @param  array<array-key, mixed>  $payloadKeys
     * @return array{schema_version: int, complete: bool, missing_required: list<string>, unknown_keys: list<string>}
     */
    public static function normalizeEnvelopeMetaPhp(string $schemaVersion, array $payloadKeys): array
    {
        $keys = self::envelopeMetaKeyList($payloadKeys);
        $present = array_flip($keys);

        $missing = [];
        foreach (self::ENVELOPE_META_REQUIRED_KEYS as $required) {
            if (! isset($present[$required])) {
                $missing[] = $required;
            }
        }

        $unknown = [];
        $seen = [];
        foreach ($keys as $key) {
            if (in_array($key, self::ENVELOPE_META_KNOWN_KEYS, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unknown[] = $key;
        }

        return [
            'schema_version' => (int) $schemaVersion,
            'complete' => $missing === [],
            'missing_required' => $missing,
            'unknown_keys' => $unknown,
        ];
    }

    /**
     * Rust handles `-?[0-9]{1,18}` versions; any other spelling falls back to PHP casting.
     *
     * @param  array<array-key, mixed>  $payloadKeys
     * @return array{schema_version: int, complete: bool, missing_required: list<string>, unknown_keys: list<string>}
     */
    public static function normalizeEnvelopeMetaNative(string $schemaVersion, array $payloadKeys): array
    {
        $result = self::envelopeMetaNativePacket(5, [$schemaVersion, ...self::envelopeMetaKeyList($payloadKeys)]);
        $missingCount = $result !== null && count($result) >= 3 ? (int) $result[2] : -1;
        if ($result === null || $missingCount < 0 || count($result) < 3 + $missingCount) {
            return self::normalizeEnvelopeMetaPhp($schemaVersion, $payloadKeys);
        }

        return [
            'schema_version' => (int) $result[0],
            'complete' => $result[1] === '1',
            'missing_required' => array_slice($result, 3, $missingCount),
            'unknown_keys' => array_slice($result, 3 + $missingCount),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payloadKeys
     * @return list<string>
     */
    private static function envelopeMetaKeyList(array $payloadKeys): array
    {
        $keys = [];
        foreach ($payloadKeys as $key) {
            if (is_int($key)) {
                $key = (string) $key;
            }
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function envelopeMetaNativePacket(int $mode, array $items): ?array
    {
        $ffi = self::envelopeMetaFfi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w32_passkey_reindex_proxy_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackEnvelopeMetaPacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackEnvelopeMetaPacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function envelopeMetaFfi(): ?FFI
    {
        if (self::$envelopeMetaFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$envelopeMetaFfi = RustPipelineLibrary::resolveDomain(
                'search',
                self::ENVELOPE_META_FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$envelopeMetaFfi instanceof FFI ? self::$envelopeMetaFfi : null;
    }
}
