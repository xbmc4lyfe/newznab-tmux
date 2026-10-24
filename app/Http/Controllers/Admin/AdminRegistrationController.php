<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\RegistrationPeriodRequest;
use App\Http\Requests\Admin\UpdateRegistrationStatusRequest;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationStatusHistory;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\RegistrationFailureLogService;
use App\Services\RegistrationStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminRegistrationController extends BasePageController
{
    private static ?bool $useWave29RustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useWave29RustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useWave29RustFfi ?? false;
    }

    public function __construct(
        private readonly RegistrationStatusService $registrationStatusService,
        private readonly RegistrationFailureLogService $registrationFailureLogService
    ) {
        parent::__construct();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $now = now();

        $this->setAdminPrefs();
        $this->registrationStatusService->disableExpiredPeriods($now);

        $editingPeriod = null;
        if ($request->filled('edit_period')) {
            $editingPeriod = RegistrationPeriod::query()
                ->with(['createdByUser', 'updatedByUser'])
                ->find((int) $request->integer('edit_period'));

            if ($editingPeriod === null) {
                return redirect()
                    ->route('admin.registrations.index')
                    ->with('error', 'The selected registration period could not be found.');
            }
        }

        $status = $this->registrationStatusService->resolve($now);
        $periods = RegistrationPeriod::query()
            ->with(['createdByUser', 'updatedByUser'])
            ->get();
        $partition = self::partitionPeriods($periods->map(fn (RegistrationPeriod $period): array => [
            'ended' => $period->hasEndedAt($now),
            'starts_at' => $period->starts_at?->getTimestamp(),
            'ends_at' => $period->ends_at?->getTimestamp(),
        ])->all());
        $currentPeriods = new Collection(array_map(fn (int $offset): RegistrationPeriod => $periods[$offset], $partition['current']));
        $pastPeriods = new Collection(array_map(fn (int $offset): RegistrationPeriod => $periods[$offset], $partition['past']));

        $history = RegistrationStatusHistory::query()
            ->with(['changedByUser', 'registrationPeriod'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $recentSuccessfulRegistrations = UserActivity::query()
            ->where('activity_type', 'registered')
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->filter(fn (UserActivity $activity): bool => self::validActivityMetadata($activity->metadata))
            ->take(10)
            ->values();

        $recentFailedAttempts = collect($this->registrationFailureLogService->recentFailures(10))
            ->map(fn (array $entry): array => self::enrichFailedAttempt($entry))
            ->all();

        return view('admin.registrations.index', array_merge($this->viewData, [
            'title' => 'Registration Admin',
            'page_title' => 'Registration Admin',
            'meta_title' => 'Registration Admin',
            'meta_description' => 'Manage registration status, scheduled open periods, and registration activity.',
            'registrationStatus' => $status,
            'statusOptions' => $this->registrationStatusService->statusOptions(),
            'currentPeriods' => $currentPeriods,
            'pastPeriods' => $pastPeriods,
            'editingPeriod' => $editingPeriod,
            'history' => $history,
            'recentSuccessfulRegistrations' => $recentSuccessfulRegistrations,
            'recentFailedAttempts' => $recentFailedAttempts,
        ]));
    }

    public function updateStatus(UpdateRegistrationStatusRequest $request): RedirectResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $newStatus = (int) $request->integer('registerstatus');
        $oldStatus = $this->registrationStatusService->getManualStatus();

        $this->registrationStatusService->updateManualStatus(
            $newStatus,
            $admin,
            $request->input('note')
        );

        $message = self::manualStatusMessage($oldStatus, $newStatus);

        return redirect()
            ->route('admin.registrations.index')
            ->with('success', $message);
    }

    public function storePeriod(RegistrationPeriodRequest $request): RedirectResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $this->registrationStatusService->createPeriod(
            array_merge($request->validated(), [
                'is_enabled' => $request->boolean('is_enabled'),
            ]),
            $admin
        );

        return redirect()
            ->route('admin.registrations.index')
            ->with('success', 'Scheduled open-registration period created successfully.');
    }

    public function updatePeriod(RegistrationPeriodRequest $request, RegistrationPeriod $period): RedirectResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $this->registrationStatusService->updatePeriod(
            $period,
            array_merge($request->validated(), [
                'is_enabled' => $request->boolean('is_enabled'),
            ]),
            $admin
        );

        return redirect()
            ->route('admin.registrations.index')
            ->with('success', 'Scheduled open-registration period updated successfully.');
    }

    public function togglePeriod(Request $request, RegistrationPeriod $period): RedirectResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        if ($period->hasEndedAt()) {
            $this->registrationStatusService->disableExpiredPeriods();

            return redirect()
                ->route('admin.registrations.index')
                ->with('success', 'That scheduled open-registration period has already passed and is now marked as done.');
        }

        $this->registrationStatusService->togglePeriod(
            $period,
            $admin,
            $request->input('note')
        );

        return redirect()
            ->route('admin.registrations.index')
            ->with('success', 'Scheduled open-registration period status updated successfully.');
    }

    public function destroyPeriod(Request $request, RegistrationPeriod $period): RedirectResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $this->registrationStatusService->deletePeriod(
            $period,
            $admin,
            $request->input('note')
        );

        return redirect()
            ->route('admin.registrations.index')
            ->with('success', 'Scheduled open-registration period deleted successfully.');
    }

    /**
     * @param  list<array{ended: bool, starts_at: int|null, ends_at: int|null}>  $periods
     * @return array{current: list<int>, past: list<int>}
     */
    public static function partitionPeriods(array $periods): array
    {
        return self::usesRustFfi() ? self::partitionPeriodsNative($periods) : self::partitionPeriodsPhp($periods);
    }

    /**
     * @param  list<array{ended: bool, starts_at: int|null, ends_at: int|null}>  $periods
     * @return array{current: list<int>, past: list<int>}
     */
    public static function partitionPeriodsPhp(array $periods): array
    {
        $periods = array_values($periods);
        $current = [];
        $past = [];
        foreach ($periods as $offset => $period) {
            if ($period['ended']) {
                $past[] = $offset;
            } else {
                $current[] = $offset;
            }
        }
        usort($current, fn (int $a, int $b): int => ($periods[$a]['starts_at'] ?? PHP_INT_MAX) <=> ($periods[$b]['starts_at'] ?? PHP_INT_MAX));
        usort($past, fn (int $a, int $b): int => ($periods[$b]['ends_at'] ?? 0) <=> ($periods[$a]['ends_at'] ?? 0));

        return ['current' => $current, 'past' => $past];
    }

    /**
     * @param  list<array{ended: bool, starts_at: int|null, ends_at: int|null}>  $periods
     * @return array{current: list<int>, past: list<int>}
     */
    public static function partitionPeriodsNative(array $periods): array
    {
        return GdprRequestController::wave29Native(4, ['periods' => $periods]) ?? self::partitionPeriodsPhp($periods);
    }

    public static function validActivityMetadata(mixed $metadata): bool
    {
        return self::usesRustFfi() ? self::validActivityMetadataNative($metadata) : self::validActivityMetadataPhp($metadata);
    }

    public static function validActivityMetadataPhp(mixed $metadata): bool
    {
        return is_array($metadata) && isset($metadata['email'], $metadata['ip_address']);
    }

    public static function validActivityMetadataNative(mixed $metadata): bool
    {
        $result = GdprRequestController::wave29Native(5, ['metadata' => $metadata]);

        return is_bool($result['valid'] ?? null) ? $result['valid'] : self::validActivityMetadataPhp($metadata);
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @return array<int|string, mixed>
     */
    public static function enrichFailedAttempt(array $entry): array
    {
        return self::usesRustFfi() ? self::enrichFailedAttemptNative($entry) : self::enrichFailedAttemptPhp($entry);
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @return array<int|string, mixed>
     */
    public static function enrichFailedAttemptPhp(array $entry): array
    {
        foreach (['registration_status', 'manual_registration_status'] as $key) {
            $entry[$key.'_label'] = is_numeric($entry[$key] ?? null)
                ? RegistrationStatusService::labelFor((int) $entry[$key])
                : null;
        }

        return $entry;
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @return array<int|string, mixed>
     */
    public static function enrichFailedAttemptNative(array $entry): array
    {
        $statuses = array_map(fn (string $key): ?int => is_numeric($entry[$key] ?? null) ? (int) $entry[$key] : null, ['registration_status', 'manual_registration_status']);

        return GdprRequestController::wave29Native(6, ['entry' => $entry, 'statuses' => $statuses]) ?? self::enrichFailedAttemptPhp($entry);
    }

    public static function manualStatusMessage(int $oldStatus, int $newStatus): string
    {
        return self::usesRustFfi() ? self::manualStatusMessageNative($oldStatus, $newStatus) : self::manualStatusMessagePhp($oldStatus, $newStatus);
    }

    public static function manualStatusMessagePhp(int $oldStatus, int $newStatus): string
    {
        return $oldStatus === $newStatus
            ? 'Registration status note saved without changing the manual mode.'
            : 'Manual registration status updated successfully.';
    }

    public static function manualStatusMessageNative(int $oldStatus, int $newStatus): string
    {
        $result = GdprRequestController::wave29Native(7, ['old_status' => $oldStatus, 'new_status' => $newStatus]);

        return is_string($result['message'] ?? null) ? $result['message'] : self::manualStatusMessagePhp($oldStatus, $newStatus);
    }
}
