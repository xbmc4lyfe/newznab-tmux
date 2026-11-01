<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            return response()->json(['error' => 'items must be a non-empty list.', 'max_items' => $limits['items']], 422);
        }
        if (count($items) > $limits['items']) {
            return response()->json(['error' => 'A batch holds at most '.$limits['items'].' items.', 'max_items' => $limits['items']], 422);
        }
        // The budget bounds when new items start; one started item may still run past it.
        // Only ever raised: a lower limit than the host allows could kill a transaction mid-publication.
        $limit = RemotePublicationService::raisedTimeLimit((int) ini_get('max_execution_time'), $limits['seconds']);
        if ($limit !== null && function_exists('set_time_limit')) {
            @set_time_limit($limit);
        }

        return response()->json(['protocol' => RemotePublicationService::BATCH_PROTOCOL,
            'results' => $service->publishBatch($items, $limits['seconds'])]);
    }
}
