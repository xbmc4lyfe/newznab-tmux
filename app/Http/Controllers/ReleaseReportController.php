<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;

class ReleaseReportController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_release_report_attributes(
        const char *reason,
        size_t reason_len,
        uint8_t has_description,
        const char *description,
        size_t description_len,
        size_t *out_lens,
        char *out,
        size_t cap
    );
    int32_t nntmux_release_report_response(uint32_t outcome, int32_t *out_meta, char *out, size_t cap);
    CDEF;

    public const int REPORT_NOT_FOUND = 0;

    public const int REPORT_DUPLICATE = 1;

    public const int REPORT_CREATED = 2;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustSearchContentWatchlistControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Submit a report for a release.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'release_id' => 'required|integer',
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $releaseId = (int) $validated['release_id'];
        $userId = $this->authenticatedUserId();

        // Check if release exists
        $release = Release::find($releaseId);
        if (! $release) {
            return self::reportJson(self::REPORT_NOT_FOUND);
        }

        // Check if user already reported this release
        if (ReleaseReport::hasUserReported($releaseId, $userId)) {
            return self::reportJson(self::REPORT_DUPLICATE);
        }

        // Create the report
        ReleaseReport::create(self::reportAttributes(
            $releaseId,
            $userId,
            (string) $validated['reason'],
            isset($validated['description']) ? (string) $validated['description'] : null,
        ));
        ReleaseBrowseService::bumpCacheVersion();

        return self::reportJson(self::REPORT_CREATED);
    }

    /**
     * Get report reasons for the form.
     */
    public function getReasons(): JsonResponse
    {
        return response()->json([
            'reasons' => ReleaseReport::REASONS,
        ]);
    }

    /**
     * Check if user has already reported a release.
     */
    public function checkReported(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'release_id' => 'required|integer',
        ]);

        $releaseId = (int) $validated['release_id'];
        $userId = $this->authenticatedUserId();

        $hasReported = ReleaseReport::hasUserReported($releaseId, $userId);

        return response()->json([
            'has_reported' => $hasReported,
        ]);
    }

    /**
     * Normalize the authenticated user identifier to an integer.
     */
    private function authenticatedUserId(): int
    {
        $userId = Auth::id();

        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && ctype_digit($userId)) {
            return (int) $userId;
        }

        abort(401, 'Authentication required.');
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Attributes for a new pending release report.
     *
     * @return array{releases_id: int, users_id: int, reason: string, description: ?string, status: string}
     */
    public static function reportAttributes(int $releaseId, int $userId, string $reason, ?string $description): array
    {
        return self::$useRustFfi
            ? self::reportAttributesNative($releaseId, $userId, $reason, $description)
            : self::reportAttributesPhp($releaseId, $userId, $reason, $description);
    }

    /**
     * @return array{releases_id: int, users_id: int, reason: string, description: ?string, status: string}
     */
    public static function reportAttributesPhp(int $releaseId, int $userId, string $reason, ?string $description): array
    {
        return [
            'releases_id' => $releaseId,
            'users_id' => $userId,
            'reason' => $reason,
            'description' => $description,
            'status' => 'pending',
        ];
    }

    /**
     * @return array{releases_id: int, users_id: int, reason: string, description: ?string, status: string}
     */
    public static function reportAttributesNative(int $releaseId, int $userId, string $reason, ?string $description): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::reportAttributesPhp($releaseId, $userId, $reason, $description);
        }

        try {
            $descriptionText = $description ?? '';
            $lens = $ffi->new('size_t[3]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, \strlen($reason) + \strlen($descriptionText) + 16, $capacity);
            if ($outBuf === null) {
                return self::reportAttributesPhp($releaseId, $userId, $reason, $description);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_release_report_attributes',
                $reason,
                \strlen($reason),
                $description === null ? 0 : 1,
                $descriptionText,
                \strlen($descriptionText),
                $lens,
                $outBuf,
                $capacity,
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::reportAttributesPhp($releaseId, $userId, $reason, $description);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $reasonLen = (int) $lens[0];
            $descriptionLen = (int) $lens[1];
            $statusLen = (int) $lens[2];
            if ($reasonLen + $descriptionLen + $statusLen !== $written) {
                return self::reportAttributesPhp($releaseId, $userId, $reason, $description);
            }

            return [
                'releases_id' => $releaseId,
                'users_id' => $userId,
                'reason' => (string) substr($payload, 0, $reasonLen),
                'description' => $description === null ? null : (string) substr($payload, $reasonLen, $descriptionLen),
                'status' => (string) substr($payload, $reasonLen + $descriptionLen, $statusLen),
            ];
        } catch (Throwable) {
            return self::reportAttributesPhp($releaseId, $userId, $reason, $description);
        }
    }

    /**
     * JSON payload and HTTP status for a report outcome (`REPORT_*`).
     *
     * @return array{status: int, payload: array{success: bool, message: string}}
     *
     * @throws InvalidArgumentException for an unknown outcome
     */
    public static function reportResponse(int $outcome): array
    {
        return self::$useRustFfi ? self::reportResponseNative($outcome) : self::reportResponsePhp($outcome);
    }

    /**
     * @return array{status: int, payload: array{success: bool, message: string}}
     *
     * @throws InvalidArgumentException for an unknown outcome
     */
    public static function reportResponsePhp(int $outcome): array
    {
        return match ($outcome) {
            self::REPORT_NOT_FOUND => ['status' => 404, 'payload' => ['success' => false, 'message' => 'Release not found.']],
            self::REPORT_DUPLICATE => ['status' => 409, 'payload' => ['success' => false, 'message' => 'You have already reported this release.']],
            self::REPORT_CREATED => ['status' => 200, 'payload' => ['success' => true, 'message' => 'Report submitted successfully. Thank you for helping improve our content.']],
            default => throw new InvalidArgumentException("Unknown release report outcome {$outcome}."),
        };
    }

    /**
     * @return array{status: int, payload: array{success: bool, message: string}}
     *
     * @throws InvalidArgumentException for an unknown outcome
     */
    public static function reportResponseNative(int $outcome): array
    {
        $ffi = self::ffi();
        if ($ffi === null || $outcome < self::REPORT_NOT_FOUND || $outcome > self::REPORT_CREATED) {
            return self::reportResponsePhp($outcome);
        }

        try {
            $meta = $ffi->new('int32_t[2]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($outBuf === null) {
                return self::reportResponsePhp($outcome);
            }

            $written = self::callFfi($ffi, 'nntmux_release_report_response', $outcome, $meta, $outBuf, $capacity);
            $success = (int) $meta[1];
            if (! \is_int($written) || $written <= 0 || $written > $capacity || ($success !== 0 && $success !== 1)) {
                return self::reportResponsePhp($outcome);
            }

            return [
                'status' => (int) $meta[0],
                'payload' => ['success' => $success === 1, 'message' => FFI::string($outBuf, $written)],
            ];
        } catch (Throwable) {
            return self::reportResponsePhp($outcome);
        }
    }

    private static function reportJson(int $outcome): JsonResponse
    {
        $response = self::reportResponse($outcome);

        return response()->json($response['payload'], $response['status']);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
