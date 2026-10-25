<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprRequest;
use App\Models\User;
use App\Services\AdminDashboardSnapshotService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GdprErasureService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_gdpr_erasure_anonymous_identity_nul(int64_t user_id, char *out_buf, size_t out_cap);
        ssize_t nntmux_gdpr_erasure_event_name(uint8_t force_delete, char *out_buf, size_t out_cap);
        ssize_t nntmux_gdpr_erasure_audit_subject_json(const char *username_ptr, size_t username_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_gdpr_erasure_filter_metadata_keys_nul(const char *keys_nul_ptr, size_t keys_nul_len, size_t key_count, uint8_t *out_keep_mask, size_t out_mask_cap, int32_t *out_has_gdpr_minimized);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $int32Slot = null;

    private static ?CData $maskBuf = null;

    private static int $maskCap = 0;

    private static ?FFI $slotOwner = null;

    public function __construct(
        private readonly GdprDataInventory $inventory = new GdprDataInventory,
        private readonly GdprAuditService $audit = new GdprAuditService,
        private readonly GdprNotificationService $notifications = new GdprNotificationService,
    ) {}

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function eraseForAccountDeletion(User $user, ?User $actor = null, ?GdprRequest $request = null, bool $forceDelete = false): void
    {
        DB::transaction(function () use ($user, $actor, $request, $forceDelete): void {
            $freshUser = User::withTrashed()->findOrFail($user->id);
            $original = [
                'id' => (int) $freshUser->id,
                'username' => (string) $freshUser->username,
                'email' => (string) $freshUser->email,
            ];
            $anonymous = self::anonymousIdentity((int) $freshUser->id);

            $this->deleteErasableRecords((int) $freshUser->id);
            $this->anonymizeCommunityRecords((int) $freshUser->id, $anonymous['username']);
            $this->minimizeInvitations((int) $freshUser->id, $original['email'], $anonymous['email']);
            $this->anonymizeRetainedPaymentRecords($original, $anonymous);
            $this->anonymizeRetainedAuditRecords($original, $anonymous);
            $this->anonymizeUser($freshUser, $anonymous);

            $this->audit->record(
                event: self::resolveErasureEventName($forceDelete),
                description: 'GDPR erasure workflow completed; retained payment and audit records were anonymized/minimized.',
                subject: $freshUser,
                actor: $actor,
                request: $request,
                metadata: [
                    'retained_records' => $this->inventory->retainedRecords(),
                    'force_delete' => $forceDelete,
                ]
            );

            if ($forceDelete) {
                $freshUser->forceDelete();
            } elseif (! $freshUser->trashed()) {
                $freshUser->delete();
            }

            if ($request !== null) {
                $request->update([
                    'status' => GdprRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'processed_by' => $actor?->id,
                    'response_payload' => [
                        'erasure' => 'completed',
                        'force_delete' => $forceDelete,
                        'retained_records' => $this->inventory->retainedRecords(),
                    ],
                ]);
            }
        }, 3);

        if ($request !== null) {
            $this->notifications->erasureCompleted($request->fresh() ?? $request);
        }

        Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);
    }

    public function forceDeleteWithErasure(User $user, ?User $actor = null, ?GdprRequest $request = null): void
    {
        $this->eraseForAccountDeletion($user, $actor, $request, true);
    }

    /**
     * @return array{username: string, email: string, name: string}
     */
    public static function anonymousIdentity(int $userId): array
    {
        return self::$useRustFfi
            ? self::anonymousIdentityNative($userId)
            : self::anonymousIdentityPhp($userId);
    }

    /**
     * @return array{username: string, email: string, name: string}
     */
    public static function anonymousIdentityPhp(int $userId): array
    {
        return [
            'username' => 'deleted_user_'.$userId,
            'email' => 'deleted-user-'.$userId.'@deleted.invalid',
            'name' => 'Deleted user '.$userId,
        ];
    }

    /**
     * @return array{username: string, email: string, name: string}
     */
    public static function anonymousIdentityNative(int $userId): array
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::anonymousIdentityPhp($userId);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return self::anonymousIdentityPhp($userId);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_erasure_anonymous_identity_nul($userId, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::anonymousIdentityPhp($userId);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_gdpr_erasure_anonymous_identity_nul($userId, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                $parts = explode("\0", FFI::string($scratch, $written));
                if (\count($parts) === 3) {
                    return [
                        'username' => $parts[0],
                        'email' => $parts[1],
                        'name' => $parts[2],
                    ];
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::anonymousIdentityPhp($userId);
    }

    public static function resolveErasureEventName(bool $forceDelete): string
    {
        return self::$useRustFfi
            ? self::resolveErasureEventNameNative($forceDelete)
            : self::resolveErasureEventNamePhp($forceDelete);
    }

    public static function resolveErasureEventNamePhp(bool $forceDelete): string
    {
        return $forceDelete ? 'erasure_force_delete' : 'erasure_soft_delete';
    }

    public static function resolveErasureEventNameNative(bool $forceDelete): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::resolveErasureEventNamePhp($forceDelete);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return self::resolveErasureEventNamePhp($forceDelete);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_erasure_event_name($forceDelete ? 1 : 0, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::resolveErasureEventNamePhp($forceDelete);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_gdpr_erasure_event_name($forceDelete ? 1 : 0, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolveErasureEventNamePhp($forceDelete);
    }

    public static function formatAuditSubjectMetadataJson(string $anonymousUsername): string
    {
        return self::$useRustFfi
            ? self::formatAuditSubjectMetadataJsonNative($anonymousUsername)
            : self::formatAuditSubjectMetadataJsonPhp($anonymousUsername);
    }

    public static function formatAuditSubjectMetadataJsonPhp(string $anonymousUsername): string
    {
        return (string) json_encode(['subject' => $anonymousUsername], JSON_UNESCAPED_SLASHES);
    }

    public static function formatAuditSubjectMetadataJsonNative(string $anonymousUsername): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::formatAuditSubjectMetadataJsonPhp($anonymousUsername);
        }

        $len = \strlen($anonymousUsername);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len * 2 + 24), $cap);
        if ($scratch === null) {
            return self::formatAuditSubjectMetadataJsonPhp($anonymousUsername);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_erasure_audit_subject_json($anonymousUsername, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatAuditSubjectMetadataJsonPhp($anonymousUsername);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_gdpr_erasure_audit_subject_json($anonymousUsername, $len, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatAuditSubjectMetadataJsonPhp($anonymousUsername);
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function sanitizeMetadata(mixed $metadata): array
    {
        return self::$useRustFfi
            ? self::sanitizeMetadataNative($metadata)
            : self::sanitizeMetadataPhp($metadata);
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function sanitizeMetadataPhp(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($metadata)) {
            $metadata = [];
        }

        unset($metadata['email'], $metadata['ip_address'], $metadata['host']);
        $metadata['gdpr_minimized'] = true;

        return $metadata;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function sanitizeMetadataNative(mixed $metadata): array
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::sanitizeMetadataPhp($metadata);
        }

        $parsed = $metadata;
        if (is_string($parsed)) {
            $decoded = json_decode($parsed, true);
            $parsed = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($parsed)) {
            $parsed = [];
        }

        $keyCount = \count($parsed);
        if (! self::ensureMaskSlots($ffi, max(8, $keyCount)) || self::$int32Slot === null || self::$maskBuf === null) {
            return self::sanitizeMetadataPhp($metadata);
        }

        $strKeys = [];
        $rawKeys = [];
        $values = [];
        foreach ($parsed as $k => $v) {
            $sk = (string) $k;
            if (str_contains($sk, "\0")) {
                return self::sanitizeMetadataPhp($metadata);
            }
            $strKeys[] = $sk;
            $rawKeys[] = $k;
            $values[] = $v;
        }

        $joined = implode("\0", $strKeys);

        try {
            // @phpstan-ignore method.notFound
            $kept = $ffi->nntmux_gdpr_erasure_filter_metadata_keys_nul(
                $joined,
                \strlen($joined),
                $keyCount,
                self::$maskBuf,
                self::$maskCap,
                self::$int32Slot,
            );
            if (! \is_int($kept) || $kept < 0) {
                return self::sanitizeMetadataPhp($metadata);
            }

            $result = [];
            for ($i = 0; $i < $keyCount; $i++) {
                /** @var int $code */
                $code = self::$maskBuf[$i];
                if ($code === 1) {
                    $result[$rawKeys[$i]] = $values[$i];
                } elseif ($code === 2) {
                    $result['gdpr_minimized'] = true;
                }
            }

            /** @var int $hasGdpr */
            $hasGdpr = self::$int32Slot[0];
            if ($hasGdpr === 0) {
                $result['gdpr_minimized'] = true;
            }

            return $result;
        } catch (Throwable) {
            return self::sanitizeMetadataPhp($metadata);
        }
    }

    /**
     * @param  array{username: string, email: string, name: string}  $anonymous
     */
    private function anonymizeUser(User $user, array $anonymous): void
    {
        $updates = [
            'username' => $anonymous['username'],
            'email' => $anonymous['email'],
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'session_token' => null,
            'api_token' => hash('sha256', 'deleted-'.$user->id.'-'.Str::random(32)),
            'resetguid' => null,
            'verification_token' => null,
            'firstname' => null,
            'lastname' => null,
            'name' => $anonymous['name'],
            'host' => '',
            'notes' => null,
            'saburl' => null,
            'sabapikey' => null,
            'nzbgeturl' => null,
            'nzbgetusername' => null,
            'nzbgetpassword' => null,
            'nzbvortex_api_key' => null,
            'nzbvortex_server_url' => null,
            'cp_url' => null,
            'cp_api' => null,
            'email_verified_at' => null,
            'verified' => false,
            'can_post' => false,
            'invites' => 0,
        ];

        $updates = $this->filterColumns('users', $updates);

        User::withoutEvents(function () use ($user, $updates): void {
            User::withTrashed()->where('id', $user->id)->update($updates);
        });

        $user->forceFill($updates);
    }

    private function deleteErasableRecords(int $userId): void
    {
        foreach ($this->inventory->erasableTables() as $table => $definition) {
            $userColumn = $definition['user_column'];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $userColumn)) {
                continue;
            }

            $query = DB::table($table)->where($userColumn, $userId);

            foreach (($definition['extra'] ?? []) as $column => $value) {
                if (Schema::hasColumn($table, (string) $column)) {
                    $query->where((string) $column, $value);
                }
            }

            $query->delete();
        }
    }

    private function anonymizeCommunityRecords(int $userId, string $anonymousUsername): void
    {
        foreach ($this->inventory->anonymizedTables() as $table => $definition) {
            $userColumn = $definition['user_column'];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $userColumn)) {
                continue;
            }

            $updates = $this->filterColumns($table, $definition['updates'] ?? []);
            if ($updates === []) {
                continue;
            }

            if (Schema::hasColumn($table, 'username')) {
                $updates['username'] = $anonymousUsername;
            }

            DB::table($table)->where($userColumn, $userId)->update($updates);
        }
    }

    /**
     * @param  array{id: int, username: string, email: string}  $original
     * @param  array{username: string, email: string, name: string}  $anonymous
     */
    private function anonymizeRetainedPaymentRecords(array $original, array $anonymous): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $updates = $this->filterColumns('payments', [
            'email' => $anonymous['email'],
            'username' => $anonymous['username'],
        ]);

        if ($updates === []) {
            return;
        }

        DB::table('payments')
            ->where(function ($query) use ($original): void {
                if (Schema::hasColumn('payments', 'email')) {
                    $query->orWhere('email', $original['email']);
                }

                if (Schema::hasColumn('payments', 'username')) {
                    $query->orWhere('username', $original['username']);
                }
            })
            ->update($updates);
    }

    private function minimizeInvitations(int $userId, string $originalEmail, string $anonymousEmail): void
    {
        if (! Schema::hasTable('invitations')) {
            return;
        }

        if (Schema::hasColumn('invitations', 'email')) {
            DB::table('invitations')
                ->where('email', $originalEmail)
                ->update(['email' => $anonymousEmail]);
        }

        if (Schema::hasColumn('invitations', 'used_by')) {
            DB::table('invitations')
                ->where('used_by', $userId)
                ->update(['used_by' => null]);
        }

        if (Schema::hasColumn('invitations', 'invited_by') && Schema::hasColumn('invitations', 'used_at')) {
            DB::table('invitations')
                ->where('invited_by', $userId)
                ->whereNull('used_at')
                ->delete();
        }
    }

    /**
     * @param  array{id: int, username: string, email: string}  $original
     * @param  array{username: string, email: string, name: string}  $anonymous
     */
    private function anonymizeRetainedAuditRecords(array $original, array $anonymous): void
    {
        if (Schema::hasTable('user_activities')) {
            DB::table('user_activities')
                ->where(function ($query) use ($original): void {
                    $query->where('user_id', $original['id'])
                        ->orWhere('username', $original['username']);
                })
                ->orderBy('id')
                ->chunkById(100, function ($activities) use ($anonymous): void {
                    foreach ($activities as $activity) {
                        $metadata = self::sanitizeMetadata($activity->metadata ?? null);
                        $updates = ['username' => $anonymous['username']];
                        if (Schema::hasColumn('user_activities', 'metadata')) {
                            $updates['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES);
                        }
                        DB::table('user_activities')->where('id', $activity->id)->update($updates);
                    }
                });
        }

        if (Schema::hasTable('gdpr_audit_logs') && Schema::hasColumn('gdpr_audit_logs', 'metadata')) {
            DB::table('gdpr_audit_logs')
                ->where('user_id', $original['id'])
                ->update(['metadata' => self::formatAuditSubjectMetadataJson($anonymous['username'])]);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $updates): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_filter(
            $updates,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private static function ensureMaskSlots(FFI $ffi, int $neededCap): bool
    {
        if (self::$slotOwner !== $ffi || self::$int32Slot === null || self::$maskBuf === null || self::$maskCap < $neededCap) {
            try {
                $allocCap = max(32, $neededCap);
                self::$int32Slot = $ffi->new('int32_t[1]');
                self::$maskBuf = $ffi->new("uint8_t[{$allocCap}]");
                self::$maskCap = $allocCap;
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W49 parity test). */
    private const W49_RUST_FFI_DEFAULT = true;

    private const string W49_FFI_CDEF = 'ssize_t nntmux_w49_gdpr_erasure_metadata_plan_batch(const char *metadata, size_t metadata_len, size_t count, int64_t *out, size_t out_cap);';

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 batch routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /** Keys sanitizeMetadataPhp() unsets, as bits 1-3 of a plan word. */
    public const array W49_REMOVED_KEYS = ['email', 'ip_address', 'host'];

    /**
     * Batch minimization plan for stored activity metadata, mirroring sanitizeMetadataPhp() on
     * each JSON value. One word per value: `isArray | removedKeys << 1 | hadGdprMinimized << 4 |
     * gdprMinimizedTrue << 5 | count << 8 | sanitizedCount << 24 | alreadyMinimized << 40 |
     * jsonLength << 44`; counts saturate at 0xFFFF and the length at 0x7FFFF.
     *
     * @param  list<?string>  $metadata
     * @return list<int>
     */
    public static function planMetadataMinimization(array $metadata): array
    {
        return self::usesW49RustFfi()
            ? self::planMetadataMinimizationNative($metadata)
            : self::planMetadataMinimizationPhp($metadata);
    }

    /**
     * @param  list<?string>  $metadata
     * @return list<int>
     */
    public static function planMetadataMinimizationPhp(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $json) {
            $json ??= '';
            $decoded = json_decode($json, true);
            $isArray = \is_array($decoded);
            if (! $isArray) {
                $decoded = [];
            }
            $mask = $removed = 0;
            foreach (self::W49_REMOVED_KEYS as $bit => $key) {
                if (\array_key_exists($key, $decoded)) {
                    $mask |= 1 << $bit;
                    $removed++;
                }
            }
            $flagged = \array_key_exists('gdpr_minimized', $decoded);
            $flaggedTrue = $flagged && $decoded['gdpr_minimized'] === true;
            $count = \count($decoded);
            $out[] = (int) $isArray | $mask << 1 | (int) $flagged << 4 | (int) $flaggedTrue << 5
                | min(0xFFFF, $count) << 8 | min(0xFFFF, $count - $removed + (int) ! $flagged) << 24
                | (int) ($removed === 0 && $flaggedTrue) << 40 | min(0x7FFFF, \strlen($json)) << 44;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a value holds a NUL byte.
     *
     * @param  list<?string>  $metadata
     * @return list<int>
     */
    public static function planMetadataMinimizationNative(array $metadata): array
    {
        $count = \count($metadata);
        $ffi = $count === 0 ? null : self::w49Ffi();
        $packed = implode("\0", $metadata);
        if ($ffi === null) {
            return self::planMetadataMinimizationPhp($metadata);
        }
        try {
            $words = self::w49Words($ffi, $count, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w49_gdpr_erasure_metadata_plan_batch( // @phpstan-ignore method.notFound
                $packed, \strlen($packed), $count, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::planMetadataMinimizationPhp($metadata);
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('gdpr_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /**
     * Copies `$words` output words out of a reusable `int64_t` buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w49Words(FFI $ffi, int $words, callable $call): ?array
    {
        $buffer = self::$w49Out;
        if ($buffer === null || self::$w49OutCap < $words) {
            $cap = max(512, $words * 2);
            $buffer = self::$w49Out = $ffi->new("int64_t[{$cap}]");
            self::$w49OutCap = $cap;
        }
        if ($call($buffer, self::$w49OutCap) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer, $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }
}
