<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GdprRequest;
use App\Models\User;
use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprNotificationService;
use App\Services\Gdpr\GdprRetentionService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PrivacyCenterController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_privacy_center_request_payload(
        uint32_t kind,
        uint8_t ip_recorded,
        const char *confirmation,
        size_t confirmation_len,
        size_t *out_lens,
        char *out,
        size_t cap
    );
    int32_t nntmux_privacy_center_can_download(int64_t owner_id, int64_t user_id, uint8_t downloadable);
    int32_t nntmux_privacy_center_export_filename(int64_t user_id, char *out, size_t cap);
    CDEF;

    private const int GDPR_EXPORT = 0;

    private const int GDPR_ERASURE = 1;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function index(GdprRetentionService $retentionService): View
    {
        /** @var User $user */
        $user = Auth::user();

        $requests = GdprRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        $this->viewData = array_merge($this->viewData, [
            'user' => $user,
            'gdprRequests' => $requests,
            'retentionPolicy' => $retentionService->policy(),
            'meta_title' => 'Privacy Center',
            'meta_keywords' => 'privacy,gdpr,data export,erasure',
            'meta_description' => 'Manage your privacy and GDPR data requests',
        ]);

        return view('privacy-center.index', $this->viewData);
    }

    public function requestExport(Request $request, GdprExportService $exportService, GdprAuditService $auditService): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Block regeneration while a previously generated export is still downloadable.
        // This bounds the heavy, synchronous export work and avoids accumulating PII files.
        $activeExport = GdprRequest::query()
            ->where('user_id', $user->id)
            ->where('type', GdprRequest::TYPE_EXPORT)
            ->where('status', GdprRequest::STATUS_COMPLETED)
            ->whereNotNull('export_path')
            ->where(function ($query): void {
                $query->whereNull('export_expires_at')
                    ->orWhere('export_expires_at', '>', now());
            })
            ->exists();

        if ($activeExport) {
            return redirect()
                ->route('privacy-center.index')
                ->with('error', 'You already have an active data export available for download. Please use or wait for it to expire before requesting a new one.');
        }

        $gdprRequest = GdprRequest::create([
            'user_id' => $user->id,
            'requester_username' => $user->username,
            'requester_email' => $user->email,
            'type' => GdprRequest::TYPE_EXPORT,
            'status' => GdprRequest::STATUS_PROCESSING,
            'request_payload' => self::exportRequestPayload((bool) config('nntmux_settings.store_user_ip')),
            'notes' => $validated['notes'] ?? null,
        ]);

        $auditService->record(
            event: 'request_submitted',
            description: 'Self-service GDPR export request submitted.',
            subject: $user,
            actor: $user,
            request: $gdprRequest,
            metadata: ['type' => GdprRequest::TYPE_EXPORT]
        );

        $exportService->generate($user, $gdprRequest, $user);

        return redirect()
            ->route('privacy-center.index')
            ->with('success', 'Your data export has been generated and is available for 7 days.');
    }

    public function requestErasure(Request $request, GdprAuditService $auditService, GdprNotificationService $notificationService): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasRole('Admin')) {
            return redirect()
                ->route('privacy-center.index')
                ->with('error', 'Admin accounts cannot submit self-service erasure requests.');
        }

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:ERASE'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $openRequestExists = GdprRequest::query()
            ->where('user_id', $user->id)
            ->where('type', GdprRequest::TYPE_ERASURE)
            ->open()
            ->exists();

        if ($openRequestExists) {
            return redirect()
                ->route('privacy-center.index')
                ->with('error', 'You already have an open account erasure request.');
        }

        $gdprRequest = GdprRequest::create([
            'user_id' => $user->id,
            'requester_username' => $user->username,
            'requester_email' => $user->email,
            'type' => GdprRequest::TYPE_ERASURE,
            'status' => GdprRequest::STATUS_PENDING,
            'request_payload' => self::erasureRequestPayload($validated['confirmation']),
            'notes' => $validated['notes'] ?? null,
        ]);

        $auditService->record(
            event: 'request_submitted',
            description: 'Self-service GDPR erasure request submitted.',
            subject: $user,
            actor: $user,
            request: $gdprRequest,
            metadata: ['type' => GdprRequest::TYPE_ERASURE]
        );

        $notificationService->requestSubmitted($gdprRequest);

        return redirect()
            ->route('privacy-center.index')
            ->with('success', 'Your account erasure request has been submitted for administrator review.');
    }

    public function downloadExport(GdprRequest $gdprRequest): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! self::canDownloadExport((int) $gdprRequest->user_id, (int) $user->id, $gdprRequest->isDownloadableExport())) {
            return redirect()->route('privacy-center.index')->with('error', 'The requested export is not available.');
        }

        $disk = $gdprRequest->export_disk ?: 'local';
        if (! Storage::disk($disk)->exists($gdprRequest->export_path)) {
            return redirect()->route('privacy-center.index')->with('error', 'The requested export file has expired or is missing.');
        }

        return Storage::disk($disk)->download($gdprRequest->export_path, self::exportDownloadFilename((int) $user->id));
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
     * Self-service GDPR export request payload.
     *
     * @return array{requested_by: string, ip_recorded: bool}
     */
    public static function exportRequestPayload(bool $ipRecorded): array
    {
        return self::$useRustFfi
            ? self::exportRequestPayloadNative($ipRecorded)
            : self::exportRequestPayloadPhp($ipRecorded);
    }

    /**
     * @return array{requested_by: string, ip_recorded: bool}
     */
    public static function exportRequestPayloadPhp(bool $ipRecorded): array
    {
        return [
            'requested_by' => 'self_service',
            'ip_recorded' => $ipRecorded,
        ];
    }

    /**
     * @return array{requested_by: string, ip_recorded: bool}
     */
    public static function exportRequestPayloadNative(bool $ipRecorded): array
    {
        $parts = self::requestPayloadParts(self::GDPR_EXPORT, $ipRecorded, '');
        if ($parts === null || $parts[1] !== '') {
            return self::exportRequestPayloadPhp($ipRecorded);
        }

        return [
            'requested_by' => $parts[0],
            'ip_recorded' => $ipRecorded,
        ];
    }

    /**
     * Self-service GDPR erasure request payload.
     *
     * @return array{requested_by: string, confirmation: string}
     */
    public static function erasureRequestPayload(string $confirmation): array
    {
        return self::$useRustFfi
            ? self::erasureRequestPayloadNative($confirmation)
            : self::erasureRequestPayloadPhp($confirmation);
    }

    /**
     * @return array{requested_by: string, confirmation: string}
     */
    public static function erasureRequestPayloadPhp(string $confirmation): array
    {
        return [
            'requested_by' => 'self_service',
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @return array{requested_by: string, confirmation: string}
     */
    public static function erasureRequestPayloadNative(string $confirmation): array
    {
        $parts = self::requestPayloadParts(self::GDPR_ERASURE, false, $confirmation);
        if ($parts === null) {
            return self::erasureRequestPayloadPhp($confirmation);
        }

        return [
            'requested_by' => $parts[0],
            'confirmation' => $parts[1],
        ];
    }

    /**
     * Only the owner may download a still-downloadable export.
     */
    public static function canDownloadExport(int $ownerId, int $userId, bool $downloadable): bool
    {
        return self::$useRustFfi
            ? self::canDownloadExportNative($ownerId, $userId, $downloadable)
            : self::canDownloadExportPhp($ownerId, $userId, $downloadable);
    }

    public static function canDownloadExportPhp(int $ownerId, int $userId, bool $downloadable): bool
    {
        return $ownerId === $userId && $downloadable;
    }

    public static function canDownloadExportNative(int $ownerId, int $userId, bool $downloadable): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::canDownloadExportPhp($ownerId, $userId, $downloadable);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_privacy_center_can_download', $ownerId, $userId, $downloadable ? 1 : 0);

            return match ($result) {
                0 => false,
                1 => true,
                default => self::canDownloadExportPhp($ownerId, $userId, $downloadable),
            };
        } catch (Throwable) {
            return self::canDownloadExportPhp($ownerId, $userId, $downloadable);
        }
    }

    /**
     * `gdpr-export-{id}.json` download filename.
     */
    public static function exportDownloadFilename(int $userId): string
    {
        return self::$useRustFfi
            ? self::exportDownloadFilenameNative($userId)
            : self::exportDownloadFilenamePhp($userId);
    }

    public static function exportDownloadFilenamePhp(int $userId): string
    {
        return 'gdpr-export-'.$userId.'.json';
    }

    public static function exportDownloadFilenameNative(int $userId): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::exportDownloadFilenamePhp($userId);
        }

        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
            if ($out === null) {
                return self::exportDownloadFilenamePhp($userId);
            }
            $written = self::callFfi($ffi, 'nntmux_privacy_center_export_filename', $userId, $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::exportDownloadFilenamePhp($userId);
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::exportDownloadFilenamePhp($userId);
        }
    }

    /**
     * `requested_by` and the erasure confirmation from the native payload export, or null to fall back.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function requestPayloadParts(int $kind, bool $ipRecorded, string $confirmation): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $lens = $ffi->new('size_t[2]');
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, \strlen($confirmation) + 64, $capacity);
            if ($out === null) {
                return null;
            }
            $written = self::callFfi($ffi, 'nntmux_privacy_center_request_payload', $kind, $ipRecorded ? 1 : 0, $confirmation, \strlen($confirmation), $lens, $out, $capacity);
            $requestedByLength = (int) $lens[0];
            if (! \is_int($written) || $written <= 0 || $written > $capacity || $requestedByLength + (int) $lens[1] !== $written) {
                return null;
            }
            $payload = FFI::string($out, $written);

            return [substr($payload, 0, $requestedByLength), substr($payload, $requestedByLength)];
        } catch (Throwable) {
            return null;
        }
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
