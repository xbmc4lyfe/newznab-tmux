<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Publishes several of a remote worker's spot-checked candidates in one request. Each item is
 * validated, fenced and committed exactly as the single endpoint would (its own transaction), so
 * the batch saves round trips and request overhead, never isolation. The response is HTTP 200
 * whenever the batch was processed; each item reports its own status, in request order.
 *
 * Every item is idempotent, so a worker may resend the whole batch after a lost response.
 */
class ReleaseVerificationPublicationBatchController extends Controller
{
    public const string FFI_CDEF = DirectorySyncBridge::FFI_CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * Wave 39.3 production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBarrierForwardPubControllersW39ParityTest.php is below 1.5x.
     * Named W393 so it stays distinct from this class's existing Rust FFI toggle.
     */
    public const bool W393_RUST_FFI_DEFAULT = false;

    public const string W393_FFI_CDEF = <<<'C'
        ssize_t nntmux_w393_batch_envelope_summary(
            const char *ids_ptr,
            size_t ids_len,
            int64_t max_batch_items,
            char *out_buf,
            size_t out_cap
        );
        C;

    private static ?bool $w393RustFfiOverride = null;

    private static FFI|false|null $w393RustFfiHandle = null;

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The HTTP status and envelope fields for a batch of $itemCount items. A processed batch is always 200;
     * $hasPausedOutcome only reports whether any item was refused by a pause.
     *
     * @return array{status: int, error: ?string, max_items: int, paused: bool}
     */
    public static function classifyBatchResponseStatus(bool $hasPausedOutcome, int $itemCount, int $maxBatchItems = 64): array
    {
        return self::usesRustFfi()
            ? self::classifyBatchResponseStatusNative($hasPausedOutcome, $itemCount, $maxBatchItems)
            : self::classifyBatchResponseStatusPhp($hasPausedOutcome, $itemCount, $maxBatchItems);
    }

    /** @return array{status: int, error: ?string, max_items: int, paused: bool} */
    public static function classifyBatchResponseStatusPhp(bool $hasPausedOutcome, int $itemCount, int $maxBatchItems = 64): array
    {
        if ($itemCount <= 0) {
            return ['status' => 422, 'error' => 'items must be a non-empty list.', 'max_items' => $maxBatchItems, 'paused' => false];
        }
        if ($itemCount > $maxBatchItems) {
            return ['status' => 422, 'error' => 'A batch holds at most '.$maxBatchItems.' items.', 'max_items' => $maxBatchItems, 'paused' => false];
        }

        return ['status' => 200, 'error' => null, 'max_items' => $maxBatchItems, 'paused' => $hasPausedOutcome];
    }

    /** @return array{status: int, error: ?string, max_items: int, paused: bool} */
    public static function classifyBatchResponseStatusNative(bool $hasPausedOutcome, int $itemCount, int $maxBatchItems = 64): array
    {
        if (DirectorySyncBridge::transformNative(4, [$hasPausedOutcome, $itemCount, $maxBatchItems], $result) && \is_array($result)
            && array_keys($result) === ['status', 'error', 'max_items', 'paused'] && \is_int($result['status'])
            && ($result['error'] === null || \is_string($result['error'])) && $result['max_items'] === $maxBatchItems && \is_bool($result['paused'])) {
            /** @var array{status: int, error: ?string, max_items: int, paused: bool} $result */
            return $result;
        }

        return self::classifyBatchResponseStatusPhp($hasPausedOutcome, $itemCount, $maxBatchItems);
    }

    public function __invoke(Request $request, RemotePublicationService $service): JsonResponse
    {
        $limits = RemotePublicationService::batchLimits();
        $length = $request->headers->get('Content-Length');
        if (($length !== null && ctype_digit($length) && (int) $length > $limits['bytes']) || strlen($request->getContent()) > $limits['bytes']) {
            return response()->json(['error' => 'Batch body exceeds '.$limits['bytes'].' bytes.', 'max_bytes' => $limits['bytes']], 413);
        }
        $protocol = $request->input('protocol');
        if ($protocol !== RemotePublicationService::BATCH_PROTOCOL) {
            return response()->json(['error' => 'Unsupported batch protocol.', 'supported_protocol' => RemotePublicationService::BATCH_PROTOCOL], 422);
        }
        $items = $request->input('items');
        // Anything but a list counts as no items.
        $count = is_array($items) && array_is_list($items) ? count($items) : 0;
        $classified = self::classifyBatchResponseStatus(false, $count, $limits['items']);
        if ($classified['status'] !== 200 || ! is_array($items)) {
            return response()->json(['error' => $classified['error'], 'max_items' => $classified['max_items']], $classified['status']);
        }
        // The budget bounds when new items start; one started item may still run past it.
        // Only ever raised: a lower limit than the host allows could kill a transaction mid-publication.
        $limit = RemotePublicationService::raisedTimeLimit((int) ini_get('max_execution_time'), $limits['seconds']);
        if ($limit !== null && function_exists('set_time_limit')) {
            @set_time_limit($limit);
        }

        $results = $service->publishBatch($items, $limits['seconds']);
        $classified = self::classifyBatchResponseStatus(in_array(PublicationOutcome::PAUSED, array_column($results, 'status'), true), count($items), $limits['items']);

        return response()->json(['protocol' => RemotePublicationService::BATCH_PROTOCOL, 'results' => $results], $classified['status']);
    }

    public static function useW393RustFfi(?bool $enabled): void
    {
        self::$w393RustFfiOverride = $enabled;
    }

    public static function usesW393RustFfi(): bool
    {
        return self::$w393RustFfiOverride ?? self::W393_RUST_FFI_DEFAULT;
    }

    public static function resetW393RustFfi(): void
    {
        self::$w393RustFfiOverride = null;
        self::$w393RustFfiHandle = null;
    }

    /**
     * Pre-validates a batch's item envelopes. An item is well formed when its `claim` holds a positive
     * integer `id`, a non-negative integer `version`, a 64-byte `manifest_hash` and a non-empty
     * `lease_token` of at most 64 bytes; anything else is malformed. Well-formed items repeating an
     * earlier claim ID are duplicates. A batch over max(1, $maxBatchItems) items accepts none.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, valid: int, duplicates: int, malformed: int, oversized: bool, accepted: int, max_items: int}
     */
    public static function summarizeBatchEnvelopeValidation(array $items, int $maxBatchItems = 250): array
    {
        return (self::usesW393RustFfi() ? self::summarizeBatchEnvelopeValidationNative($items, $maxBatchItems) : null)
            ?? self::summarizeBatchEnvelopeValidationPhp($items, $maxBatchItems);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, valid: int, duplicates: int, malformed: int, oversized: bool, accepted: int, max_items: int}
     */
    public static function summarizeBatchEnvelopeValidationPhp(array $items, int $maxBatchItems = 250): array
    {
        $max = max(1, $maxBatchItems);
        $seen = [];
        $malformed = 0;
        $duplicates = 0;
        foreach ($items as $item) {
            $id = self::envelopeClaimId($item);
            if ($id === 0) {
                $malformed++;
            } elseif (isset($seen[$id])) {
                $duplicates++;
            } else {
                $seen[$id] = true;
            }
        }
        $total = count($items);
        $valid = $total - $malformed;
        $oversized = $total > $max;

        return [
            'total' => $total,
            'valid' => $valid,
            'duplicates' => $duplicates,
            'malformed' => $malformed,
            'oversized' => $oversized,
            'accepted' => $oversized ? 0 : $valid - $duplicates,
            'max_items' => $max,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, valid: int, duplicates: int, malformed: int, oversized: bool, accepted: int, max_items: int}|null
     */
    public static function summarizeBatchEnvelopeValidationNative(array $items, int $maxBatchItems = 250): ?array
    {
        $ids = '';
        foreach ($items as $item) {
            $ids .= pack('P', self::envelopeClaimId($item));
        }
        $fields = self::callW393Native('nntmux_w393_batch_envelope_summary', [$ids, strlen($ids), $maxBatchItems], 128, 7, 7);
        if ($fields === null || ! in_array($fields[4], ['0', '1'], true)) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'valid' => (int) $fields[1],
            'duplicates' => (int) $fields[2],
            'malformed' => (int) $fields[3],
            'oversized' => $fields[4] === '1',
            'accepted' => (int) $fields[5],
            'max_items' => (int) $fields[6],
        ];
    }

    /** The claim ID of a well-formed item envelope, or 0 when it is malformed. */
    private static function envelopeClaimId(mixed $item): int
    {
        $claim = is_array($item) ? ($item['claim'] ?? null) : null;
        if (! is_array($claim)) {
            return 0;
        }
        $id = $claim['id'] ?? null;
        $version = $claim['version'] ?? null;
        $hash = $claim['manifest_hash'] ?? null;
        $token = $claim['lease_token'] ?? null;

        return is_int($id) && $id >= 1 && is_int($version) && $version >= 0 && is_string($hash) && strlen($hash) === 64
            && is_string($token) && $token !== '' && strlen($token) <= 64 ? $id : 0;
    }

    /**
     * Calls a Wave 39.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount and the first $digitFields are decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW393Native(string $symbol, array $args, int $minBytes, int $fieldCount, int $digitFields): ?array
    {
        $ffi = self::w393RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (count($fields) !== $fieldCount) {
                return null;
            }
            for ($i = 0; $i < $digitFields; $i++) {
                if (strlen($fields[$i]) > 19 || ! ctype_digit($fields[$i])) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w393RustFfi(): ?FFI
    {
        if (self::$w393RustFfiHandle === null) {
            self::$w393RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w393', self::W393_FFI_CDEF) ?? false;
        }

        return self::$w393RustFfiHandle === false ? null : self::$w393RustFfiHandle;
    }
}
