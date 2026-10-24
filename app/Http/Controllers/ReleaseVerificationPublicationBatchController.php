<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
