<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConfigurationDomain;
use App\Enums\RegistrationStatus;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationStatusHistory;
use App\Models\User;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\DomainConfigurationRepository;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class RegistrationStatusService
{
    /** Scheduled period audit events for {@see periodDescription()}. */
    public const PERIOD_CREATED = 0;

    public const PERIOD_UPDATED = 1;

    public const PERIOD_ENABLED = 2;

    public const PERIOD_DISABLED = 3;

    public const PERIOD_COMPLETED = 4;

    public const PERIOD_DELETED = 5;

    /**
     * {@see decide()} reasons in Rust index order.
     *
     * @var list<string>
     */
    public const REASONS = ['scheduled_open_period', 'manual_open', 'manual_invite', 'manual_closed'];

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_regstatus_decide(int64_t manual_status, int32_t has_active_period, int64_t *out);
        int64_t nntmux_regstatus_normalize_manual(int64_t status);
        ssize_t nntmux_regstatus_text(int32_t kind, int64_t a, int64_t b, const char *first, size_t first_len, const char *second, size_t second_len, char *out, size_t cap);
        C;

    private const TEXT_LABEL = 0;

    private const TEXT_MESSAGE = 1;

    private const TEXT_MANUAL_CHANGE = 2;

    private const TEXT_PERIOD = 3;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(
        private readonly ConfigurationProvider $configuration,
        private readonly DomainConfigurationRepository $repository,
    ) {}

    /**
     * @return array<int, string>
     */
    public function statusOptions(): array
    {
        return [
            RegistrationStatus::Open->value => RegistrationStatus::Open->label(),
            RegistrationStatus::Invite->value => RegistrationStatus::Invite->label(),
            RegistrationStatus::Closed->value => RegistrationStatus::Closed->label(),
        ];
    }

    public function statusLabel(int $status): string
    {
        return self::labelFor($status);
    }

    public function getManualStatus(): int
    {
        return self::normalizeManualStatus($this->configuration->registration()->status->value);
    }

    public function getActivePeriod(?CarbonInterface $at = null): ?RegistrationPeriod
    {
        $at ??= now();

        return RegistrationPeriod::query()
            ->activeAt($at)
            ->orderBy('starts_at')
            ->first();
    }

    public function getNextUpcomingPeriod(?CarbonInterface $at = null): ?RegistrationPeriod
    {
        $at ??= now();

        return RegistrationPeriod::query()
            ->enabled()
            ->upcoming($at)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return array{
     *     manual_status: int,
     *     manual_status_label: string,
     *     effective_status: int,
     *     effective_status_label: string,
     *     active_period: RegistrationPeriod|null,
     *     scheduled_override_active: bool,
     *     reason: string,
     *     message: string,
     *     available: bool,
     *     is_open: bool,
     *     is_invite_only: bool,
     *     is_closed: bool
     * }
     */
    public function resolve(?CarbonInterface $at = null): array
    {
        $at ??= now();

        $manualStatus = $this->getManualStatus();
        $activePeriod = $this->getActivePeriod($at);
        $decision = self::decide($manualStatus, $activePeriod !== null);
        $effectiveStatus = $decision['effective_status'];
        $scheduledOverrideActive = $decision['scheduled_override_active'];

        return [
            'manual_status' => $manualStatus,
            'manual_status_label' => $this->statusLabel($manualStatus),
            'effective_status' => $effectiveStatus,
            'effective_status_label' => $this->statusLabel($effectiveStatus),
            'active_period' => $activePeriod,
            'scheduled_override_active' => $scheduledOverrideActive,
            'reason' => $decision['reason'],
            'message' => $this->buildMessage($effectiveStatus, $activePeriod, $scheduledOverrideActive),
            'available' => $decision['available'],
            'is_open' => $decision['is_open'],
            'is_invite_only' => $decision['is_invite_only'],
            'is_closed' => $decision['is_closed'],
        ];
    }

    public function updateManualStatus(int $newStatus, ?User $changedBy = null, ?string $note = null): void
    {
        $oldStatus = $this->getManualStatus();

        $this->repository->update(ConfigurationDomain::Registration, ['status' => $newStatus]);

        $this->forgetDashboardSnapshot();

        if ($oldStatus === $newStatus && blank($note)) {
            return;
        }

        $description = self::manualStatusDescription($oldStatus, $newStatus);

        RegistrationStatusHistory::record(
            RegistrationStatusHistory::ACTION_MANUAL_STATUS_CHANGED,
            $description,
            $changedBy?->id,
            $oldStatus,
            $newStatus,
            null,
            [
                'note' => $note,
            ]
        );
    }

    /**
     * @param  array{name: string, starts_at: mixed, ends_at: mixed, is_enabled: bool, notes?: string|null}  $data
     */
    public function createPeriod(array $data, ?User $changedBy = null): RegistrationPeriod
    {
        $period = RegistrationPeriod::query()->create([
            'name' => $data['name'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_enabled' => $data['is_enabled'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $changedBy?->id,
            'updated_by' => $changedBy?->id,
        ]);

        RegistrationStatusHistory::record(
            RegistrationStatusHistory::ACTION_PERIOD_CREATED,
            self::periodDescription(self::PERIOD_CREATED, (string) $period->name),
            $changedBy?->id,
            null,
            null,
            $period->id,
            [
                'period' => $this->serializePeriod($period),
            ]
        );

        $this->forgetDashboardSnapshot();

        return $period;
    }

    /**
     * @param  array{name: string, starts_at: mixed, ends_at: mixed, is_enabled: bool, notes?: string|null}  $data
     */
    public function updatePeriod(RegistrationPeriod $period, array $data, ?User $changedBy = null): RegistrationPeriod
    {
        $before = $this->serializePeriod($period);

        $period->fill([
            'name' => $data['name'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_enabled' => $data['is_enabled'],
            'notes' => $data['notes'] ?? null,
            'updated_by' => $changedBy?->id,
        ]);
        $period->save();

        RegistrationStatusHistory::record(
            RegistrationStatusHistory::ACTION_PERIOD_UPDATED,
            self::periodDescription(self::PERIOD_UPDATED, (string) $period->name),
            $changedBy?->id,
            null,
            null,
            $period->id,
            [
                'before' => $before,
                'after' => $this->serializePeriod($period),
            ]
        );

        $this->forgetDashboardSnapshot();

        return $period;
    }

    public function togglePeriod(RegistrationPeriod $period, ?User $changedBy = null, ?string $note = null): RegistrationPeriod
    {
        $period->forceFill([
            'is_enabled' => ! $period->is_enabled,
            'updated_by' => $changedBy?->id,
        ])->save();

        RegistrationStatusHistory::record(
            RegistrationStatusHistory::ACTION_PERIOD_TOGGLED,
            self::periodDescription($period->is_enabled ? self::PERIOD_ENABLED : self::PERIOD_DISABLED, (string) $period->name),
            $changedBy?->id,
            null,
            null,
            $period->id,
            [
                'note' => $note,
                'period' => $this->serializePeriod($period),
            ]
        );

        $this->forgetDashboardSnapshot();

        return $period;
    }

    /**
     * @return Collection<int, RegistrationPeriod>
     */
    public function disableExpiredPeriods(?CarbonInterface $at = null): Collection
    {
        $at ??= now();

        $expiredPeriods = RegistrationPeriod::query()
            ->where('is_enabled', true)
            ->where('ends_at', '<', $at)
            ->get();

        foreach ($expiredPeriods as $period) {
            $period->forceFill([
                'is_enabled' => false,
                'updated_by' => null,
            ])->save();

            RegistrationStatusHistory::record(
                RegistrationStatusHistory::ACTION_PERIOD_COMPLETED,
                self::periodDescription(self::PERIOD_COMPLETED, (string) $period->name),
                null,
                null,
                null,
                $period->id,
                [
                    'completed_at' => $at->toDateTimeString(),
                    'period' => $this->serializePeriod($period),
                ]
            );
        }

        if ($expiredPeriods->isNotEmpty()) {
            $this->forgetDashboardSnapshot();
        }

        return $expiredPeriods;
    }

    public function deletePeriod(RegistrationPeriod $period, ?User $changedBy = null, ?string $note = null): void
    {
        $snapshot = $this->serializePeriod($period);
        $periodName = $period->name;

        $period->delete();

        RegistrationStatusHistory::record(
            RegistrationStatusHistory::ACTION_PERIOD_DELETED,
            self::periodDescription(self::PERIOD_DELETED, (string) $periodName),
            $changedBy?->id,
            null,
            null,
            null,
            [
                'note' => $note,
                'period' => $snapshot,
            ]
        );

        $this->forgetDashboardSnapshot();
    }

    private function buildMessage(
        int $effectiveStatus,
        ?RegistrationPeriod $activePeriod,
        bool $scheduledOverrideActive
    ): string {
        if ($scheduledOverrideActive && $activePeriod !== null) {
            return self::statusMessage($effectiveStatus, true, $activePeriod->ends_at->format('Y-m-d H:i'), (string) $activePeriod->name);
        }

        return self::statusMessage($effectiveStatus, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePeriod(RegistrationPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'starts_at' => $period->starts_at?->toDateTimeString(),
            'ends_at' => $period->ends_at?->toDateTimeString(),
            'is_enabled' => $period->is_enabled,
            'notes' => $period->notes,
            'created_by' => $period->created_by,
            'updated_by' => $period->updated_by,
        ];
    }

    /**
     * Drop the cached admin dashboard snapshot so the per-minute warmer (or
     * the next admin request) rebuilds with fresh registration data. Avoids
     * an inline rebuild here to keep write-path latency low.
     */
    private function forgetDashboardSnapshot(): void
    {
        Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);
        Cache::forget('api_registration_status');
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Effective registration status for a manual status and whether a scheduled open period
     * is active: the period forces Open and is an override unless the manual status is Open.
     *
     * @return array{effective_status: int, scheduled_override_active: bool, reason: string, available: bool, is_open: bool, is_invite_only: bool, is_closed: bool}
     */
    public static function decide(int $manualStatus, bool $hasActivePeriod): array
    {
        return self::usesRustFfi()
            ? self::decideNative($manualStatus, $hasActivePeriod)
            : self::decidePhp($manualStatus, $hasActivePeriod);
    }

    /**
     * @return array{effective_status: int, scheduled_override_active: bool, reason: string, available: bool, is_open: bool, is_invite_only: bool, is_closed: bool}
     */
    public static function decidePhp(int $manualStatus, bool $hasActivePeriod): array
    {
        $scheduledOverrideActive = $hasActivePeriod && $manualStatus !== RegistrationStatus::Open->value;
        $effectiveStatus = $hasActivePeriod ? RegistrationStatus::Open->value : $manualStatus;
        $reason = match (true) {
            $scheduledOverrideActive => 'scheduled_open_period',
            $effectiveStatus === RegistrationStatus::Open->value => 'manual_open',
            $effectiveStatus === RegistrationStatus::Invite->value => 'manual_invite',
            default => 'manual_closed',
        };

        return [
            'effective_status' => $effectiveStatus,
            'scheduled_override_active' => $scheduledOverrideActive,
            'reason' => $reason,
            'available' => $effectiveStatus !== RegistrationStatus::Closed->value,
            'is_open' => $effectiveStatus === RegistrationStatus::Open->value,
            'is_invite_only' => $effectiveStatus === RegistrationStatus::Invite->value,
            'is_closed' => $effectiveStatus === RegistrationStatus::Closed->value,
        ];
    }

    /**
     * @return array{effective_status: int, scheduled_override_active: bool, reason: string, available: bool, is_open: bool, is_invite_only: bool, is_closed: bool}
     */
    public static function decideNative(int $manualStatus, bool $hasActivePeriod): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::decidePhp($manualStatus, $hasActivePeriod);
        }
        try {
            $out = $ffi->new('int64_t[3]');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_regstatus_decide($manualStatus, $hasActivePeriod ? 1 : 0, $out);
            [$effectiveStatus, $flags, $reason] = [$out[0], $out[1], $out[2]];
        } catch (Throwable) {
            return self::decidePhp($manualStatus, $hasActivePeriod);
        }
        if ($code !== 0 || ! \is_int($effectiveStatus) || ! \is_int($flags) || ! \is_int($reason) || ! isset(self::REASONS[$reason])) {
            return self::decidePhp($manualStatus, $hasActivePeriod);
        }

        return [
            'effective_status' => $effectiveStatus,
            'scheduled_override_active' => ($flags & 1) !== 0,
            'reason' => self::REASONS[$reason],
            'available' => ($flags & 2) !== 0,
            'is_open' => ($flags & 4) !== 0,
            'is_invite_only' => ($flags & 8) !== 0,
            'is_closed' => ($flags & 16) !== 0,
        ];
    }

    /**
     * A known manual status, otherwise Open.
     */
    public static function normalizeManualStatus(int $status): int
    {
        return self::usesRustFfi() ? self::normalizeManualStatusNative($status) : self::normalizeManualStatusPhp($status);
    }

    public static function normalizeManualStatusPhp(int $status): int
    {
        return RegistrationStatus::tryFrom($status) === null ? RegistrationStatus::Open->value : $status;
    }

    public static function normalizeManualStatusNative(int $status): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeManualStatusPhp($status);
        }
        try {
            // @phpstan-ignore method.notFound
            $normalized = $ffi->nntmux_regstatus_normalize_manual($status);
        } catch (Throwable) {
            return self::normalizeManualStatusPhp($status);
        }

        return \is_int($normalized) && RegistrationStatus::tryFrom($normalized) !== null
            ? $normalized
            : self::normalizeManualStatusPhp($status);
    }

    /**
     * Status label, or `Unknown` for a value outside {@see RegistrationStatus}.
     */
    public static function labelFor(int $status): string
    {
        return self::usesRustFfi() ? self::labelForNative($status) : self::labelForPhp($status);
    }

    public static function labelForPhp(int $status): string
    {
        return RegistrationStatus::tryFrom($status)?->label() ?? 'Unknown';
    }

    public static function labelForNative(int $status): string
    {
        return self::textNative(self::TEXT_LABEL, $status, 0) ?? self::labelForPhp($status);
    }

    /**
     * Public registration message; a scheduled override names the period and its end time.
     */
    public static function statusMessage(int $effectiveStatus, bool $scheduledOverrideActive, string $periodEndsAt = '', string $periodName = ''): string
    {
        return self::usesRustFfi()
            ? self::statusMessageNative($effectiveStatus, $scheduledOverrideActive, $periodEndsAt, $periodName)
            : self::statusMessagePhp($effectiveStatus, $scheduledOverrideActive, $periodEndsAt, $periodName);
    }

    public static function statusMessagePhp(int $effectiveStatus, bool $scheduledOverrideActive, string $periodEndsAt = '', string $periodName = ''): string
    {
        if ($scheduledOverrideActive) {
            return sprintf(
                'Registrations are temporarily open until %s because of the scheduled period "%s".',
                $periodEndsAt,
                $periodName
            );
        }

        return match ($effectiveStatus) {
            RegistrationStatus::Open->value => 'Registrations are currently open.',
            RegistrationStatus::Invite->value => 'Registrations are currently invite only.',
            default => 'Registrations are currently closed.',
        };
    }

    public static function statusMessageNative(int $effectiveStatus, bool $scheduledOverrideActive, string $periodEndsAt = '', string $periodName = ''): string
    {
        return self::textNative(self::TEXT_MESSAGE, $effectiveStatus, $scheduledOverrideActive ? 1 : 0, $periodEndsAt, $periodName)
            ?? self::statusMessagePhp($effectiveStatus, $scheduledOverrideActive, $periodEndsAt, $periodName);
    }

    /**
     * Audit description for a manual status update (a note when the status is unchanged).
     */
    public static function manualStatusDescription(int $oldStatus, int $newStatus): string
    {
        return self::usesRustFfi()
            ? self::manualStatusDescriptionNative($oldStatus, $newStatus)
            : self::manualStatusDescriptionPhp($oldStatus, $newStatus);
    }

    public static function manualStatusDescriptionPhp(int $oldStatus, int $newStatus): string
    {
        return $oldStatus === $newStatus
            ? sprintf('Manual registration status note added while status remained %s.', self::labelForPhp($newStatus))
            : sprintf(
                'Manual registration status changed from %s to %s.',
                self::labelForPhp($oldStatus),
                self::labelForPhp($newStatus)
            );
    }

    public static function manualStatusDescriptionNative(int $oldStatus, int $newStatus): string
    {
        return self::textNative(self::TEXT_MANUAL_CHANGE, $oldStatus, $newStatus)
            ?? self::manualStatusDescriptionPhp($oldStatus, $newStatus);
    }

    /**
     * Audit description for a scheduled period event (one of the PERIOD_* constants).
     */
    public static function periodDescription(int $event, string $name): string
    {
        return self::usesRustFfi() ? self::periodDescriptionNative($event, $name) : self::periodDescriptionPhp($event, $name);
    }

    public static function periodDescriptionPhp(int $event, string $name): string
    {
        $suffix = match ($event) {
            self::PERIOD_CREATED => 'created',
            self::PERIOD_UPDATED => 'updated',
            self::PERIOD_ENABLED => 'enabled',
            self::PERIOD_DISABLED => 'disabled',
            self::PERIOD_COMPLETED => 'completed after reaching its end time',
            self::PERIOD_DELETED => 'deleted',
            default => throw new InvalidArgumentException("Unknown scheduled period event [{$event}]."),
        };

        return sprintf('Scheduled open period "%s" %s.', $name, $suffix);
    }

    public static function periodDescriptionNative(int $event, string $name): string
    {
        return self::textNative(self::TEXT_PERIOD, $event, 0, $name) ?? self::periodDescriptionPhp($event, $name);
    }

    private static function textNative(int $kind, int $a, int $b, string $first = '', string $second = ''): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, \strlen($first) + \strlen($second) + 128, $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_regstatus_text($kind, $a, $b, $first, \strlen($first), $second, \strlen($second), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
