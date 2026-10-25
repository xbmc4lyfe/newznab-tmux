<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\PublicationTiming;
use App\Services\Releases\Verification\PublicationWorkerGate;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Publishes a remote worker's spot-checked candidate on the host holding NZB storage. The
 * worker's lease token must still own the candidate; the publisher revalidates it under lock.
 * The batch endpoint publishes several the same way; both delegate to RemotePublicationService.
 */
class ReleaseVerificationPublicationController extends Controller
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
        ssize_t nntmux_w393_publication_response_code(
            int64_t outcome_code,
            uint32_t flags,
            int64_t retry_after,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Publication outcome codes, by position; the metric code of an idempotent replay adds 16. */
    public const array PUBLICATION_OUTCOME_CODES = [
        PublicationOutcome::PUBLISHED,
        PublicationOutcome::FILTERED,
        PublicationOutcome::METADATA_REVIEW,
        PublicationOutcome::DEFERRED,
        PublicationOutcome::STALE,
        PublicationOutcome::PAUSED,
        PublicationOutcome::RETRY,
        PublicationOutcome::INVALID,
        PublicationOutcome::ERROR,
        PublicationOutcome::UNRESOLVED,
    ];

    /** HTTP status and whether a Retry-After applies, by outcome (as classifySinglePublicationResponse answers). */
    private const array PUBLICATION_RESPONSE_RULES = [
        PublicationOutcome::PUBLISHED => [200, false],
        PublicationOutcome::FILTERED => [200, false],
        PublicationOutcome::METADATA_REVIEW => [200, false],
        PublicationOutcome::DEFERRED => [409, true],
        PublicationOutcome::STALE => [409, false],
        PublicationOutcome::PAUSED => [409, true],
        PublicationOutcome::RETRY => [409, true],
        PublicationOutcome::INVALID => [409, false],
        PublicationOutcome::ERROR => [409, false],
        PublicationOutcome::UNRESOLVED => [200, false],
    ];

    public const int MAX_RETRY_AFTER_SECONDS = 3600;

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
     * The HTTP status and JSON body answering one publication outcome ($errorType is its status).
     *
     * @return array{status: int, body: array<string, int|string|null>}
     */
    public static function classifySinglePublicationResponse(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        return self::usesRustFfi()
            ? self::classifySinglePublicationResponseNative($releaseId, $errorType, $errorMessage)
            : self::classifySinglePublicationResponsePhp($releaseId, $errorType, $errorMessage);
    }

    /** @return array{status: int, body: array<string, int|string|null>} */
    public static function classifySinglePublicationResponsePhp(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        return match ($errorType) {
            PublicationOutcome::PUBLISHED => ['status' => 200, 'body' => ['release_id' => $releaseId]],
            // The sending worker returns the partial to waiting for one more hold window.
            PublicationOutcome::DEFERRED => ['status' => 409, 'body' => ['deferred' => $errorMessage]],
            // The worker reads the candidate row to learn why no release exists.
            PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::UNRESOLVED => ['status' => 200, 'body' => ['release_id' => null]],
            default => ['status' => 409, 'body' => ['error' => $errorMessage]],
        };
    }

    /** @return array{status: int, body: array<string, int|string|null>} */
    public static function classifySinglePublicationResponseNative(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        if (DirectorySyncBridge::transformNative(5, [$releaseId, $errorType, $errorMessage], $result) && \is_array($result)
            && array_keys($result) === ['status', 'body'] && \is_int($result['status']) && \is_array($result['body'])) {
            $body = $result['body'];
            $key = array_key_first($body);
            if (\count($body) === 1 && \in_array($key, ['release_id', 'deferred', 'error'], true)
                && ($body[$key] === null || ($key === 'release_id' ? \is_int($body[$key]) : \is_string($body[$key])))) {
                /** @var array{status: int, body: array<string, int|string|null>} $result */
                return $result;
            }
        }

        return self::classifySinglePublicationResponsePhp($releaseId, $errorType, $errorMessage);
    }

    public function __invoke(Request $request, RemotePublicationService $service): JsonResponse
    {
        // One sample decision per request: this timing is handed to publish(), which logs it as a
        // single line whose request_phases_ns hold the work done here before the publication.
        $timing = PublicationTiming::sample();
        $data = $request->validate(RemotePublicationService::itemRules());
        $timing?->requestPhase('validation');
        $outcome = $service->publishItem($data, $timing, new PublicationWorkerGate);

        $response = self::classifySinglePublicationResponse($outcome->releaseId, $outcome->status, $outcome->error);

        return response()->json($response['body'], $response['status']);
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
     * Maps a PUBLICATION_OUTCOME_CODES code to its HTTP status, Retry-After seconds (clamped to
     * 1..MAX_RETRY_AFTER_SECONDS for deferred, paused and retry; otherwise 0), whether a replay is
     * idempotent (a replayed 200), and its metric label and code. Unknown codes answer 500 `unknown`.
     *
     * @return array{status: int, retry_after: int, idempotent: bool, metric_code: int, known: bool, metric: string}
     */
    public static function classifyPublicationResponseCode(int $outcomeCode, bool $idempotentReplay, int $retryAfterSeconds = 0): array
    {
        return (self::usesW393RustFfi() ? self::classifyPublicationResponseCodeNative($outcomeCode, $idempotentReplay, $retryAfterSeconds) : null)
            ?? self::classifyPublicationResponseCodePhp($outcomeCode, $idempotentReplay, $retryAfterSeconds);
    }

    /**
     * @return array{status: int, retry_after: int, idempotent: bool, metric_code: int, known: bool, metric: string}
     */
    public static function classifyPublicationResponseCodePhp(int $outcomeCode, bool $idempotentReplay, int $retryAfterSeconds = 0): array
    {
        $label = self::PUBLICATION_OUTCOME_CODES[$outcomeCode] ?? null;
        if ($label === null) {
            return ['status' => 500, 'retry_after' => 0, 'idempotent' => false, 'metric_code' => 255, 'known' => false, 'metric' => 'unknown'];
        }
        [$status, $retryable] = self::PUBLICATION_RESPONSE_RULES[$label];
        $idempotent = $idempotentReplay && $status === 200;

        return [
            'status' => $status,
            'retry_after' => $retryable ? max(1, min(self::MAX_RETRY_AFTER_SECONDS, $retryAfterSeconds)) : 0,
            'idempotent' => $idempotent,
            'metric_code' => $outcomeCode + ($idempotent ? 16 : 0),
            'known' => true,
            'metric' => $idempotent ? $label.'_replay' : $label,
        ];
    }

    /**
     * @return array{status: int, retry_after: int, idempotent: bool, metric_code: int, known: bool, metric: string}|null
     */
    public static function classifyPublicationResponseCodeNative(int $outcomeCode, bool $idempotentReplay, int $retryAfterSeconds = 0): ?array
    {
        $fields = self::callW393Native('nntmux_w393_publication_response_code', [$outcomeCode, $idempotentReplay ? 1 : 0, $retryAfterSeconds], 64, 6, 5);
        if ($fields === null || $fields[5] === '') {
            return null;
        }

        return [
            'status' => (int) $fields[0],
            'retry_after' => (int) $fields[1],
            'idempotent' => $fields[2] === '1',
            'metric_code' => (int) $fields[3],
            'known' => $fields[4] === '1',
            'metric' => $fields[5],
        ];
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
