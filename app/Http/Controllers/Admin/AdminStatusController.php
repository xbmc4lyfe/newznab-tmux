<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Enums\ServiceStatusEnum;
use App\Facades\Search;
use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\StoreIncidentRequest;
use App\Http\Requests\Admin\UpdateIncidentRequest;
use App\Http\Requests\Admin\UpdateServiceHealthRequest;
use App\Models\ServiceIncident;
use App\Models\ServiceStatus;
use App\Services\SiteStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminStatusController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    /**
     * Rust stays opt-in for the Wave 38.5 payload check: the FFI round trip is slower than PHP
     * (< 1.5x); see tests/Unit/RustSearchFacadeControllerGuestLayoutParityTest.php.
     */
    public const bool ADMIN_STATUS_RUST_FFI_DEFAULT = false;

    private const int INCIDENT_ID_MAX_DIGITS = 18;

    private static ?bool $useAdminStatusRustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeIncident(array $data, string $now, bool $updating = false): array
    {
        return self::$useRustFfi ? self::normalizeIncidentNative($data, $now, $updating) : self::normalizeIncidentPhp($data, $now, $updating);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeIncidentPhp(array $data, string $now, bool $updating = false): array
    {
        if (($data['status'] ?? null) === IncidentStatusEnum::Resolved->value) {
            $data['resolved_at'] = $updating && empty($data['resolved_at']) ? $now : ($data['resolved_at'] ?? $now);
        } elseif ($updating) {
            $data['resolved_at'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeIncidentNative(array $data, string $now, bool $updating = false): array
    {
        $result = AdminPaymentController::transformNative(4, [$data, $now, $updating]);

        return is_array($result) ? $result : self::normalizeIncidentPhp($data, $now, $updating);
    }

    public static function useAdminStatusRustFfi(?bool $use = true): void
    {
        self::$useAdminStatusRustFfi = $use;
    }

    public static function usesAdminStatusRustFfi(): bool
    {
        return self::$useAdminStatusRustFfi ?? self::ADMIN_STATUS_RUST_FFI_DEFAULT;
    }

    public static function resetAdminStatusRustFfi(): void
    {
        self::$useAdminStatusRustFfi = null;
    }

    /**
     * Validates an incident form payload: status and impact are trimmed, ASCII lowercased and must be
     * `IncidentStatusEnum` / `IncidentImpactEnum` values (null otherwise). Service IDs must be 1-18
     * digits after trimming and positive; accepted IDs are deduplicated and sorted, the rest counted
     * as rejected. The payload is valid with a known status, a known impact and at least one service.
     *
     * @param  list<string>  $serviceIds
     * @return array{valid: bool, status: ?string, impact: ?string, resolved: bool, rejected: int, service_ids: list<int>}
     */
    public static function validateIncidentPayload(string $status, string $impact, array $serviceIds): array
    {
        return self::usesAdminStatusRustFfi()
            ? self::validateIncidentPayloadNative($status, $impact, $serviceIds)
            : self::validateIncidentPayloadPhp($status, $impact, $serviceIds);
    }

    /**
     * @param  list<string>  $serviceIds
     * @return array{valid: bool, status: ?string, impact: ?string, resolved: bool, rejected: int, service_ids: list<int>}
     */
    private static function validateIncidentPayloadNative(string $status, string $impact, array $serviceIds): array
    {
        $fields = Search::evaluateNative(5, [$status, $impact, ...$serviceIds]);
        if ($fields === null || \count($fields) < 7) {
            return self::validateIncidentPayloadPhp($status, $impact, $serviceIds);
        }

        return [
            'valid' => $fields[0] === '1',
            'status' => $fields[1] === '1' ? $fields[2] : null,
            'impact' => $fields[3] === '1' ? $fields[4] : null,
            'resolved' => $fields[5] === '1',
            'rejected' => (int) $fields[6],
            'service_ids' => array_map(intval(...), \array_slice($fields, 7)),
        ];
    }

    /**
     * @param  list<string>  $serviceIds
     * @return array{valid: bool, status: ?string, impact: ?string, resolved: bool, rejected: int, service_ids: list<int>}
     */
    private static function validateIncidentPayloadPhp(string $status, string $impact, array $serviceIds): array
    {
        $status = IncidentStatusEnum::tryFrom(strtolower(trim($status)))?->value;
        $impact = IncidentImpactEnum::tryFrom(strtolower(trim($impact)))?->value;
        $accepted = [];
        $rejected = 0;
        foreach ($serviceIds as $id) {
            $id = trim($id);
            $length = \strlen($id);
            if ($length > 0 && $length <= self::INCIDENT_ID_MAX_DIGITS && strspn($id, '0123456789') === $length && (int) $id > 0) {
                $accepted[(int) $id] = true;
            } else {
                $rejected++;
            }
        }
        $accepted = array_keys($accepted);
        sort($accepted);

        return [
            'valid' => $status !== null && $impact !== null && $accepted !== [],
            'status' => $status,
            'impact' => $impact,
            'resolved' => $status === IncidentStatusEnum::Resolved->value,
            'rejected' => $rejected,
            'service_ids' => $accepted,
        ];
    }

    public function __construct(
        protected SiteStatusService $siteStatusService
    ) {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $services = $this->siteStatusService->getAllServicesForAdmin();
        $incidents = ServiceIncident::query()
            ->with(['services', 'creator'])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        $this->viewData = array_merge($this->viewData, [
            'services' => $services,
            'incidents' => $incidents,
            'meta_title' => 'Site status',
            'title' => 'Site status',
        ]);

        return view('admin.status.index', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function create(): View
    {
        $this->setAdminPrefs();

        $services = $this->siteStatusService->getAllServicesForAdmin();

        $this->viewData = array_merge($this->viewData, [
            'services' => $services,
            'meta_title' => 'Create incident',
            'title' => 'Create incident',
        ]);

        return view('admin.status.create', $this->viewData);
    }

    public function store(StoreIncidentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = Auth::id();

        $data = self::normalizeIncident($data, now()->toDateTimeString());

        $this->siteStatusService->createIncident($data);

        return redirect()
            ->route('admin.status.index')
            ->with('success', 'Incident created successfully.');
    }

    /**
     * @throws \Exception
     */
    public function edit(ServiceIncident $incident): View
    {
        $this->setAdminPrefs();

        $services = $this->siteStatusService->getAllServicesForAdmin();

        $this->viewData = array_merge($this->viewData, [
            'incident' => $incident->load(['services', 'creator']),
            'services' => $services,
            'meta_title' => 'Edit incident',
            'title' => 'Edit incident',
        ]);

        return view('admin.status.edit', $this->viewData);
    }

    public function update(UpdateIncidentRequest $request, ServiceIncident $incident): RedirectResponse
    {
        $data = $request->validated();

        $data = self::normalizeIncident($data, now()->toDateTimeString(), true);

        $this->siteStatusService->updateIncident($incident, $data);

        return redirect()
            ->route('admin.status.index')
            ->with('success', 'Incident updated successfully.');
    }

    public function resolve(ServiceIncident $incident): RedirectResponse
    {
        if ($incident->status === IncidentStatusEnum::Resolved) {
            return redirect()
                ->route('admin.status.index')
                ->with('info', 'Incident was already resolved.');
        }

        $this->siteStatusService->resolveIncident($incident);

        return redirect()
            ->route('admin.status.index')
            ->with('success', 'Incident marked as resolved.');
    }

    public function destroy(ServiceIncident $incident): RedirectResponse
    {
        $serviceIds = $incident->services()->pluck('id')->all();
        $incident->delete();

        foreach ($serviceIds as $serviceId) {
            $service = ServiceStatus::query()->find($serviceId);
            if ($service instanceof ServiceStatus) {
                $this->siteStatusService->recomputeServiceHealth($service);
            }
        }

        return redirect()
            ->route('admin.status.index')
            ->with('success', 'Incident deleted.');
    }

    public function updateService(UpdateServiceHealthRequest $request, ServiceStatus $service): RedirectResponse
    {
        $status = ServiceStatusEnum::from($request->validated('status'));
        $this->siteStatusService->updateServiceStatus($service, $status);

        return redirect()
            ->route('admin.status.index')
            ->with('success', 'Service status updated.');
    }
}
