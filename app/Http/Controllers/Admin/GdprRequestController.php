<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GdprRequest;
use App\Models\User;
use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprErasureService;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprNotificationService;
use App\Services\Gdpr\GdprRetentionService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class GdprRequestController extends BasePageController
{
    public const string FFI_CDEF = 'int32_t nntmux_w29_admin_gdpr_reg_promo_invite_history(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Keep PHP until each paired common/p99 screen clears 1.5x (see parity test).
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $wave29Ffi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    /**
     * Shared, dependency-free byte-framed bridge for this controller slice.
     * Unsupported PHP values or any native/decoding error decline to the caller.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function wave29Native(int $operation, array $input): ?array
    {
        try {
            $wire = self::encodeWave29Node($input);
            self::$wave29Ffi ??= RustPipelineLibrary::resolveDomain('wave29-admin-gdpr-reg-promo-invite-history', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
            if (self::$wave29Ffi === false) {
                return null;
            }
            $ffi = self::$wave29Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w29_admin_gdpr_reg_promo_invite_history($operation, $wire, strlen($wire), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w29_admin_gdpr_reg_promo_invite_history($operation, $wire, strlen($wire), $buffer, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }
            $output = FFI::string($buffer, $written);
            $offset = 0;
            $result = self::decodeWave29Node($output, $offset);
            if (! is_array($result) || $offset !== strlen($output)) {
                return null;
            }
            foreach (array_keys($result) as $key) {
                if (! is_string($key)) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private static function encodeWave29Node(mixed $value, int $depth = 0): string
    {
        if ($depth > 64) {
            throw new \LengthException('Wave 29 nesting limit');
        }
        $node = match (true) {
            $value === null => 'n',
            is_bool($value) => $value ? 'b1' : 'b0',
            is_int($value) => 'i'.$value,
            is_float($value) => 'f'.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            is_string($value) => 's'.$value,
            is_array($value) => 'a'.count($value),
            default => throw new \UnexpectedValueException('Unsupported Wave 29 value'),
        };
        if (strlen($node) > 67_108_860) {
            throw new \LengthException('Wave 29 payload limit');
        }
        $wire = pack('V', strlen($node)).$node;
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $wire .= self::encodeWave29Node($key, $depth + 1).self::encodeWave29Node($item, $depth + 1);
                if (strlen($wire) > 67_108_864) {
                    throw new \LengthException('Wave 29 payload limit');
                }
            }
        }

        return $wire;
    }

    private static function decodeWave29Node(string $wire, int &$offset, int $depth = 0): mixed
    {
        if ($depth > 64 || strlen($wire) - $offset < 4) {
            throw new \UnexpectedValueException('Invalid Wave 29 frame');
        }
        $header = unpack('Vlength', $wire, $offset);
        if ($header === false || ! is_int($header['length']) || $header['length'] < 1 || $header['length'] > strlen($wire) - $offset - 4) {
            throw new \UnexpectedValueException('Invalid Wave 29 frame length');
        }
        $node = substr($wire, $offset + 4, $header['length']);
        $offset += 4 + $header['length'];
        $payload = substr($node, 1);
        switch ($node[0]) {
            case 'n':
                if ($node === 'n') {
                    return null;
                }
                break;
            case 'b':
                if ($node === 'b0' || $node === 'b1') {
                    return $node === 'b1';
                }
                break;
            case 's':
                return $payload;
            case 'i':
                $integer = filter_var($payload, FILTER_VALIDATE_INT);
                if ($integer !== false && (string) $integer === $payload) {
                    return $integer;
                }
                break;
            case 'f':
                if (is_numeric($payload) && is_finite((float) $payload)) {
                    return (float) $payload;
                }
                break;
            case 'a':
                $count = filter_var($payload, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count === false || (string) $count !== $payload || $count > intdiv(strlen($wire) - $offset, 10)) {
                    break;
                }
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = self::decodeWave29Node($wire, $offset, $depth + 1);
                    if ((! is_int($key) && ! is_string($key)) || array_key_exists($key, $result)) {
                        throw new \UnexpectedValueException('Invalid Wave 29 key');
                    }
                    $result[$key] = self::decodeWave29Node($wire, $offset, $depth + 1);
                }

                return $result;
        }

        throw new \UnexpectedValueException('Invalid Wave 29 node');
    }

    /**
     * @var list<string>
     */
    private const TYPES = [
        GdprRequest::TYPE_EXPORT,
        GdprRequest::TYPE_ERASURE,
        GdprRequest::TYPE_RECTIFICATION,
        GdprRequest::TYPE_RESTRICTION,
    ];

    /**
     * @var list<string>
     */
    private const STATUSES = [
        GdprRequest::STATUS_PENDING,
        GdprRequest::STATUS_PROCESSING,
        GdprRequest::STATUS_COMPLETED,
        GdprRequest::STATUS_REJECTED,
        GdprRequest::STATUS_CANCELLED,
    ];

    public function index(Request $request, GdprRetentionService $retentionService): View
    {
        $this->setAdminPrefs();

        $filters = self::normalizeFilters($request->string('type')->trim()->value(), $request->string('status')->trim()->value());
        $type = $filters['type'];
        $status = $filters['status'];

        $requests = GdprRequest::query()
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 WHEN 'completed' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->paginate((int) config('nntmux.items_per_page', 25))
            ->withQueryString();

        $this->viewData = array_merge($this->viewData, [
            'requests' => $requests,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'type' => $type,
            'status' => $status,
            'retentionPolicy' => $retentionService->policy(),
            'title' => 'GDPR Requests',
            'meta_title' => 'GDPR Requests',
            'meta_keywords' => 'gdpr,privacy,requests,admin',
            'meta_description' => 'Manage GDPR data subject requests',
        ]);

        return view('admin.gdpr.index', $this->viewData);
    }

    public function show(GdprRequest $gdprRequest): View
    {
        $this->setAdminPrefs();

        $gdprRequest->load(['auditLogs', 'processor']);
        $subject = $this->subjectFor($gdprRequest);

        $this->viewData = array_merge($this->viewData, [
            'gdprRequest' => $gdprRequest,
            'subject' => $subject,
            'title' => 'GDPR Request #'.$gdprRequest->id,
            'meta_title' => 'GDPR Request #'.$gdprRequest->id,
            'meta_keywords' => 'gdpr,privacy,request,admin',
            'meta_description' => 'Review GDPR data subject request',
        ]);

        return view('admin.gdpr.show', $this->viewData);
    }

    public function generateExport(GdprRequest $gdprRequest, GdprExportService $exportService): RedirectResponse
    {
        if (($error = self::eligibilityError($gdprRequest->type, $gdprRequest->status, 'export', true, false)) !== null) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', $error);
        }

        $subject = $this->subjectFor($gdprRequest);
        if (! $subject instanceof User) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', self::eligibilityError($gdprRequest->type, $gdprRequest->status, 'export', false, false));
        }

        $actor = $this->actor();

        $gdprRequest->update([
            'status' => GdprRequest::STATUS_PROCESSING,
            'processed_by' => $actor?->id,
        ]);

        $exportService->generate($subject, $gdprRequest, $actor);

        return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('success', 'GDPR export generated successfully.');
    }

    public function completeErasure(GdprRequest $gdprRequest, GdprErasureService $erasureService): RedirectResponse
    {
        if (($error = self::eligibilityError($gdprRequest->type, $gdprRequest->status, 'erasure', true, false)) !== null) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', $error);
        }

        $subject = $this->subjectFor($gdprRequest);
        if (! $subject instanceof User) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', self::eligibilityError($gdprRequest->type, $gdprRequest->status, 'erasure', false, false));
        }

        if (($error = self::eligibilityError($gdprRequest->type, $gdprRequest->status, 'erasure', true, $subject->hasRole('Admin'))) !== null) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', $error);
        }

        $actor = $this->actor();

        $gdprRequest->update([
            'status' => GdprRequest::STATUS_PROCESSING,
            'processed_by' => $actor?->id,
        ]);

        $erasureService->eraseForAccountDeletion($subject, $actor, $gdprRequest);

        return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('success', 'Account erasure completed. Retained payment and audit records were anonymized or minimized where practical.');
    }

    public function reject(Request $request, GdprRequest $gdprRequest, GdprAuditService $auditService, GdprNotificationService $notificationService): RedirectResponse
    {
        if (! $this->isOpen($gdprRequest)) {
            return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('error', 'This request is already closed.');
        }

        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'max:4000'],
        ]);

        $actor = $this->actor();
        $subject = $this->subjectFor($gdprRequest);

        $gdprRequest->update([
            'status' => GdprRequest::STATUS_REJECTED,
            'admin_notes' => $validated['admin_notes'],
            'processed_by' => $actor?->id,
            'completed_at' => now(),
            'response_payload' => self::rejectionPayload($validated['admin_notes']),
        ]);

        $auditService->record(
            event: 'request_rejected',
            description: 'GDPR request rejected by an administrator.',
            subject: $subject,
            actor: $actor,
            request: $gdprRequest,
            metadata: ['type' => $gdprRequest->type]
        );

        $notificationService->requestRejected($gdprRequest->fresh() ?? $gdprRequest);

        return redirect()->route('admin.gdpr-requests.show', $gdprRequest)->with('success', 'GDPR request rejected.');
    }

    private function subjectFor(GdprRequest $gdprRequest): ?User
    {
        if ($gdprRequest->user_id === null) {
            return null;
        }

        return User::withTrashed()->find($gdprRequest->user_id);
    }

    private function actor(): ?User
    {
        $actor = Auth::user();

        return $actor instanceof User ? $actor : null;
    }

    private function isOpen(GdprRequest $gdprRequest): bool
    {
        return self::openRequest($gdprRequest->status);
    }

    /** @return array{type: string, status: string} */
    public static function normalizeFilters(string $type, string $status): array
    {
        return self::usesRustFfi() ? self::normalizeFiltersNative($type, $status) : self::normalizeFiltersPhp($type, $status);
    }

    /** @return array{type: string, status: string} */
    public static function normalizeFiltersPhp(string $type, string $status): array
    {
        $type = trim($type);
        $status = trim($status);

        return ['type' => in_array($type, self::TYPES, true) ? $type : '', 'status' => in_array($status, self::STATUSES, true) ? $status : ''];
    }

    /** @return array{type: string, status: string} */
    public static function normalizeFiltersNative(string $type, string $status): array
    {
        $result = self::wave29Native(0, ['type' => $type, 'status' => $status]);

        return is_string($result['type'] ?? null) && is_string($result['status'] ?? null) ? ['type' => $result['type'], 'status' => $result['status']] : self::normalizeFiltersPhp($type, $status);
    }

    public static function openRequest(string $status): bool
    {
        return self::usesRustFfi() ? self::openRequestNative($status) : self::openRequestPhp($status);
    }

    public static function openRequestPhp(string $status): bool
    {
        return in_array($status, [GdprRequest::STATUS_PENDING, GdprRequest::STATUS_PROCESSING], true);
    }

    public static function openRequestNative(string $status): bool
    {
        $result = self::wave29Native(1, ['status' => $status]);

        return is_bool($result['open'] ?? null) ? $result['open'] : self::openRequestPhp($status);
    }

    public static function eligibilityError(string $type, string $status, string $action, bool $subjectExists, bool $isAdmin): ?string
    {
        return self::usesRustFfi() ? self::eligibilityErrorNative($type, $status, $action, $subjectExists, $isAdmin) : self::eligibilityErrorPhp($type, $status, $action, $subjectExists, $isAdmin);
    }

    public static function eligibilityErrorPhp(string $type, string $status, string $action, bool $subjectExists, bool $isAdmin): ?string
    {
        if (! in_array($action, ['export', 'erasure'], true)) {
            throw new \InvalidArgumentException('Unknown GDPR action');
        }
        if ($type !== $action || ! self::openRequestPhp($status)) {
            return $action === 'export' ? 'This request cannot be exported.' : 'This erasure request cannot be completed.';
        }
        if (! $subjectExists) {
            return 'The subject user could not be found.';
        }

        return $action === 'erasure' && $isAdmin ? 'Admin accounts cannot be erased from the GDPR request queue.' : null;
    }

    public static function eligibilityErrorNative(string $type, string $status, string $action, bool $subjectExists, bool $isAdmin): ?string
    {
        $result = self::wave29Native(2, ['type' => $type, 'status' => $status, 'action' => $action, 'subject_exists' => $subjectExists, 'is_admin' => $isAdmin]);

        return $result !== null && array_key_exists('error', $result) && ($result['error'] === null || is_string($result['error'])) ? $result['error'] : self::eligibilityErrorPhp($type, $status, $action, $subjectExists, $isAdmin);
    }

    /** @return array{rejected: true, reason: mixed} */
    public static function rejectionPayload(mixed $reason): array
    {
        return self::usesRustFfi() ? self::rejectionPayloadNative($reason) : self::rejectionPayloadPhp($reason);
    }

    /** @return array{rejected: true, reason: mixed} */
    public static function rejectionPayloadPhp(mixed $reason): array
    {
        return ['rejected' => true, 'reason' => $reason];
    }

    /** @return array{rejected: true, reason: mixed} */
    public static function rejectionPayloadNative(mixed $reason): array
    {
        $result = self::wave29Native(3, ['reason' => $reason]);

        return ($result['rejected'] ?? null) === true && array_key_exists('reason', $result) ? ['rejected' => true, 'reason' => $result['reason']] : self::rejectionPayloadPhp($reason);
    }
}
