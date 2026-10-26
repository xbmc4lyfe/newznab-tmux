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
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 60.4 BATCH HELPER
    // ========================================================================

    /** Disabled: the minimum of four paired runs reached only 1.39x common p99, 1.36x uncommon mean and 1.43x uncommon p99 (common mean 1.61x), since the fixed-table lookups are cheap in PHP while validation, packing and decoding stay fixed; see RustGdprAuditInventoryErasureExportRetentionServiceW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = false;

    private const string W60_FFI_CDEF = 'ssize_t nntmux_w604_gdpr_erasure_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per erasurePlanRowBatch() record. */
    public const int W60_ROW_WORDS = 5;

    /** int64 summary words after the erasurePlanRowBatch() records (none for an empty batch). */
    public const int W60_TRAILER_WORDS = 8;

    /** Upper clamp for epoch-second timestamps. */
    private const int W60_TIME_MAX = 1 << 40;

    /** Upper clamp for row counts. */
    private const int W60_ROWS_MAX = 1 << 48;

    private const array W60_SCOPES = ['erase' => 1, 'anonymize' => 2, 'retain' => 3];

    /**
     * Erasure plan rows for [table, user column, requested scope, user id, row count, legal hold
     * until, now, flags (1 force delete, 2 user trashed)] items against the GdprDataInventory
     * tables (times are epoch seconds clamped to 0..2^40).
     *
     * Action: 6 invalid user id (<= 0), 4 legal hold (hold until > now), 1 delete (erasable
     * table with its user column), 2 anonymize (anonymized table with its user column), 5 user
     * column mismatch, 3 retain minimized (retained record table), else 0 skip. Cascade rank is
     * the erasable position + 1, the anonymized position + 100 or the retained position + 200
     * (0 for unknown tables). Scopes are 1 erase, 2 anonymize, 3 retain, else 0. Flags: 1 extra
     * conditions, 2 scope disagrees with the action, 4 unknown scope, 8 force delete, 16
     * trashed. Affected rows (clamped to 0..2^48) count only for actions 1-3.
     *
     * Rows [action | scope << 4 | flags << 8, cascade rank, affected rows, anonymous username
     * bytes | anonymous email bytes << 16 (0 without a user), legal hold seconds left], then the
     * trailer [delete, anonymize, retain, held, column mismatch, invalid, affected rows
     * (saturating), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function erasurePlanRowBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::erasurePlanRowBatchNative($items) : self::erasurePlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function erasurePlanRowBatchPhp(array $items): array
    {
        self::w60Items($items);
        if ($items === []) {
            return [];
        }
        $erasable = GdprDataInventory::erasableTablesPhp();
        $erasableRank = array_flip(array_keys($erasable));
        $anonymized = GdprDataInventory::anonymizedTablesPhp();
        $anonymizedRank = array_flip(array_keys($anonymized));
        $retainedRank = array_flip(array_column(GdprDataInventory::retainedRecordsPhp(), 'table'));
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$table, $column, $scope, $user, $rows, $hold, $now, $flags]) {
            $hold = max(0, min(self::W60_TIME_MAX, $hold));
            $now = max(0, min(self::W60_TIME_MAX, $now));
            $action = match (true) {
                $user <= 0 => 6,
                $hold > $now => 4,
                isset($erasable[$table]) => $erasable[$table]['user_column'] === $column ? 1 : 5,
                isset($anonymized[$table]) => $anonymized[$table]['user_column'] === $column ? 2 : 5,
                isset($retainedRank[$table]) => 3,
                default => 0,
            };
            $rank = isset($erasableRank[$table]) ? $erasableRank[$table] + 1
                : (isset($anonymizedRank[$table]) ? $anonymizedRank[$table] + 100 : (isset($retainedRank[$table]) ? $retainedRank[$table] + 200 : 0));
            $scope = self::W60_SCOPES[$scope] ?? 0;
            $applies = $action >= 1 && $action <= 3;
            $outFlags = (isset($erasable[$table]['extra']) ? 1 : 0) | ($scope !== 0 && $applies && $scope !== $action ? 2 : 0)
                | ($scope === 0 ? 4 : 0) | ($flags & 1) << 3 | ($flags & 2) << 3;
            $affected = $applies ? max(0, min(self::W60_ROWS_MAX, $rows)) : 0;
            $digits = \strlen((string) $user);
            $lengths = $user > 0 ? (13 + $digits) | (29 + $digits) << 16 : 0;
            $word = $action | $scope << 4 | $outFlags << 8;
            array_push($out, $word, $rank, $affected, $lengths, max(0, $hold - $now));
            if ($action >= 1) {
                $summary[$action - 1]++;
            }
            $summary[6] = $affected > PHP_INT_MAX - $summary[6] ? PHP_INT_MAX : $summary[6] + $affected;
            $value = $word ^ $rank << 12 ^ $affected;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function erasurePlanRowBatchNative(array $items): array
    {
        self::w60Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w60Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7));

        return self::w60Call($blob, $twin, $ints, $count, $count * self::W60_ROW_WORDS + self::W60_TRAILER_WORDS)
            ?? self::erasurePlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w60Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W60 erasure plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])
                || ! \is_int($item[7])) {
                throw new InvalidArgumentException('W60 erasure plan batch items must be [string table, string user column, string scope, int user id, int row count, int legal hold until, int now, int flags] lists.');
            }
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W60 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('enabled');
        self::w60RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w60Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w60Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w60RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w60', self::W60_FFI_CDEF) ?? false;
            self::w60RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w60RustFfiState('out', $out);
                self::w60RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W60_FFI_CDEF.)
            $written = $ffi->nntmux_w604_gdpr_erasure_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w60RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W60 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
