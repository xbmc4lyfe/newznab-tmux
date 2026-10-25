<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Jobs\UpdateUserApiAccess;
use App\Models\User;
use App\Models\UserRequest;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApiUsageService
{
    /** Rust helpers are opt-in; neither beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_audit_entry(const char *input, size_t len, const int64_t *lens, size_t count, char *out, size_t cap);
        int64_t nntmux_api_quota_reservation(int64_t used, int64_t max);
        C;

    /**
     * Rust stays opt-in for the Wave 45.5 audit entry summary: PHP's http_build_query() and mb_substr()
     * are already native, so framing caps the gain (min 1.38/1.29/1.24/1.13x); see
     * tests/Unit/RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest.php.
     */
    public const bool W455_RUST_FFI_DEFAULT = false;

    /**
     * Only these filter parameters are written to `user_requests.request`;
     * credentials and unknown fields never are (the column ends up in GDPR exports).
     */
    private const AUDITED_PARAMETERS = [
        't', 'q', 'id', 'cat', 'imdbid', 'tmdbid', 'traktid', 'vid', 'tvdbid', 'rid', 'tvmazeid',
        'anidbid', 'anilistid', 'season', 'ep', 'group', 'limit', 'offset', 'maxage', 'minsize',
        'sort', 'o', 'extended', 'num', 'dl', 'del',
    ];

    private const AUDIT_VALUE_MAX_LENGTH = 64;

    private const QUOTA_LOCK_SECONDS = 10;

    private const QUOTA_LOCK_WAIT_SECONDS = 5;

    /** Matches the `user_requests.request` varchar(255) column. */
    private const AUDIT_MAX_LENGTH = 255;

    private static ?bool $useW455RustFfi = null;

    private ApiInputCanonicalizer $inputCanonicalizer;

    public function __construct(?ApiInputCanonicalizer $inputCanonicalizer = null)
    {
        $this->inputCanonicalizer = $inputCanonicalizer ?? new ApiInputCanonicalizer;
    }

    public function statistics(int $userId): object
    {
        return Cache::remember('api_user_stats:'.$userId, 60, function () use ($userId): object {
            $oneDayAgo = now()->subDay()->toDateTimeString();

            return DB::selectOne('SELECT
                (SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?) as api_count,
                (SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?) as grab_count,
                (SELECT MIN(timestamp) FROM user_requests WHERE users_id = ? AND timestamp > ?) as api_time,
                (SELECT MIN(timestamp) FROM user_downloads WHERE users_id = ? AND timestamp > ?) as grab_time',
                [$userId, $oneDayAgo, $userId, $oneDayAgo, $userId, $oneDayAgo, $userId, $oneDayAgo]
            );
        });
    }

    /**
     * Atomically check the rolling 24-hour request quota and record the request.
     *
     * Enforcement counts `user_requests` directly instead of reading the cached
     * {@see statistics()}, and holds a per-user lock so concurrent requests
     * cannot all claim the last remaining slot.
     *
     * @return int|null Requests used in the window including this one, or null when the quota is exhausted (nothing recorded)
     *
     * @throws LockTimeoutException When another request for the same user holds the lock for too long
     */
    public function reserve(User $user, Request $request, int $maxRequests): ?int
    {
        $store = Cache::store(self::quotaLockStore())->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('The API quota lock store does not support locks.');
        }

        return $store->lock('api_quota:'.$user->id, self::QUOTA_LOCK_SECONDS)
            ->block(self::QUOTA_LOCK_WAIT_SECONDS, function () use ($user, $request, $maxRequests): ?int {
                $used = UserRequest::query()
                    ->where('users_id', $user->id)
                    ->where('timestamp', '>', now()->subDay()->toDateTimeString())
                    ->count();

                $reserved = self::reservedCount($used, $maxRequests);
                if ($reserved === null) {
                    return null;
                }

                $this->record($user, $request);

                return $reserved;
            });
    }

    /**
     * Requests used including this one, or null when the quota is exhausted.
     */
    public static function reservedCount(int $used, int $maxRequests, bool $useRustFfi = false): ?int
    {
        $supported = false;
        $native = $useRustFfi ? self::reservedCountNative($used, $maxRequests, $supported) : null;

        return $supported ? $native : self::reservedCountPhp($used, $maxRequests);
    }

    public static function reservedCountPhp(int $used, int $maxRequests): ?int
    {
        return $used >= $maxRequests ? null : $used + 1;
    }

    /**
     * @param-out bool $supported
     */
    public static function reservedCountNative(int $used, int $maxRequests, bool &$supported = false): ?int
    {
        $supported = false;
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $reserved = $ffi->nntmux_api_quota_reservation($used, $maxRequests);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($reserved)) {
            return null;
        }
        $supported = true;

        return $reserved === PHP_INT_MIN ? null : $reserved;
    }

    /**
     * Cache store for the quota lock: the configured one, else the default unless that is a failover chain.
     * A failover store can hand the same lock out on two backends during an outage, letting concurrent
     * requests both take the last quota slot, so it falls back to the database store instead.
     */
    public static function quotaLockStore(): ?string
    {
        $isFailover = static fn (string $store): bool => config("cache.stores.{$store}.driver") === 'failover';

        // A failover store is never used, even when configured explicitly: it can grant the lock twice.
        $configured = trim((string) config('nntmux.api.quota_lock_store', ''));
        if ($configured !== '') {
            return $isFailover($configured) ? 'database' : $configured;
        }

        return $isFailover((string) config('cache.default')) ? 'database' : null;
    }

    public function record(User $user, Request $request): void
    {
        $occurredAt = now()->toDateTimeString();

        // Request accounting drives quotas and admin statistics, so it must be
        // durable before the response returns even when no queue worker runs.
        UserRequest::recordApiRequest(
            $user->id,
            $this->auditEntry($request),
            $occurredAt,
        );

        $job = new UpdateUserApiAccess(
            $user->id,
            $request->ip(),
            $occurredAt,
        );

        if ((bool) config('nntmux.api.async_audit', true)) {
            try {
                dispatch($job);
            } catch (Throwable) {
                // Keep access metadata current when the queue backend is unavailable.
                $job->handle();
            }

            return;
        }

        $job->handle();
    }

    /**
     * Build the redacted audit entry from the path and allowlisted filters of
     * the effective input (URL and body), e.g. `/api/v2/search?cat=5030&id=test`.
     */
    public function auditEntry(Request $request, bool $useRustFfi = false): string
    {
        $parameters = $this->inputCanonicalizer->canonicalize(Arr::only($request->input(), self::AUDITED_PARAMETERS));

        return self::auditEntryFrom($request->path(), $parameters, $useRustFfi);
    }

    /**
     * @param  array<array-key, mixed>  $parameters  canonicalized allowlisted filters
     */
    public static function auditEntryFrom(string $path, array $parameters, bool $useRustFfi = false): string
    {
        return ($useRustFfi ? self::auditEntryFromNative($path, $parameters) : null) ?? self::auditEntryFromPhp($path, $parameters);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public static function auditEntryFromPhp(string $path, array $parameters): string
    {
        return mb_substr(self::untruncatedAuditEntry($path, $parameters), 0, self::AUDIT_MAX_LENGTH);
    }

    /**
     * The audit entry before the column-length cut.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    private static function untruncatedAuditEntry(string $path, array $parameters): string
    {
        $audited = [];
        foreach ($parameters as $key => $value) {
            if (is_string($value)) {
                $audited[$key] = mb_substr($value, 0, self::AUDIT_VALUE_MAX_LENGTH);
            } elseif ($value === null) {
                // Empty parameters (`?id=`) stay visible; http_build_query would drop null.
                $audited[$key] = '';
            }
            // Legacy array values (e.g. `o[]=json`) are skipped.
        }

        $entry = '/'.ltrim($path, '/');
        if ($audited !== []) {
            $entry .= '?'.http_build_query($audited, '', '&', PHP_QUERY_RFC3986);
        }

        return $entry;
    }

    /**
     * Rust truncation and RFC 3986 encoding; null (PHP decides) without FFI, outside
     * a UTF-8 mbstring encoding, or for invalid UTF-8 the PHP path substitutes.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public static function auditEntryFromNative(string $path, array $parameters): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false || mb_internal_encoding() !== 'UTF-8') {
            return null;
        }
        $fields = [$path];
        foreach ($parameters as $key => $value) {
            if (is_string($value) || $value === null) {
                $fields[] = (string) $key;
                $fields[] = (string) $value;
            }
        }
        $count = count($fields);
        static $lengths, $out, $capacity = 0, $outCapacity = 0;
        try {
            if ($capacity < $count) {
                $capacity = max(64, $count * 2);
                $lengths = $ffi->new("int64_t[{$capacity}]");
            }
            foreach ($fields as $i => $field) {
                $lengths[$i] = strlen($field);
            }
            $joined = implode('', $fields);
            // At most 255 code points of four bytes each.
            if ($outCapacity === 0) {
                $outCapacity = self::AUDIT_MAX_LENGTH * 4;
                $out = $ffi->new("char[{$outCapacity}]");
            }
            $written = $ffi->nntmux_api_audit_entry($joined, strlen($joined), $lengths, $count, $out, $outCapacity);
            if (! is_int($written) || $written < 0) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($out, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Pass null to restore {@see W455_RUST_FFI_DEFAULT}. */
    public static function useW455RustFfi(?bool $enable = true): void
    {
        self::$useW455RustFfi = $enable;
    }

    public static function usesW455RustFfi(): bool
    {
        return self::$useW455RustFfi ?? self::W455_RUST_FFI_DEFAULT;
    }

    public static function resetW455RustFfi(): void
    {
        self::$useW455RustFfi = null;
    }

    /**
     * Summarizes {@see auditEntryFromPhp()} over `['path' => string, 'parameters' => array]` entries:
     * audited string `values`, `null_values` kept as empty, `skipped_values`, `truncated_values` beyond
     * the per-value limit, `truncated_entries` beyond the column limit, `entry_bytes`, `max_entry_bytes`
     * and `distinct_entries`. Other entries are invalid. Rust needs a UTF-8 mbstring encoding and valid
     * UTF-8 paths and values; otherwise the batch runs in PHP.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, invalid: int, values: int, null_values: int, skipped_values: int, truncated_values: int, truncated_entries: int, entry_bytes: int, max_entry_bytes: int, distinct_entries: int}
     */
    public static function summarizeAuditEntryBatch(array $entries): array
    {
        return self::usesW455RustFfi() ? self::summarizeAuditEntryBatchNative($entries) : self::summarizeAuditEntryBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, invalid: int, values: int, null_values: int, skipped_values: int, truncated_values: int, truncated_entries: int, entry_bytes: int, max_entry_bytes: int, distinct_entries: int}
     */
    public static function summarizeAuditEntryBatchPhp(array $entries): array
    {
        $summary = ['entries' => count($entries), 'invalid' => 0, 'values' => 0, 'null_values' => 0, 'skipped_values' => 0, 'truncated_values' => 0, 'truncated_entries' => 0, 'entry_bytes' => 0, 'max_entry_bytes' => 0, 'distinct_entries' => 0];
        $distinct = [];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            $parameters = is_array($entry) ? ($entry['parameters'] ?? null) : null;
            if (! is_string($path) || ! is_array($parameters)) {
                $summary['invalid']++;

                continue;
            }
            foreach ($parameters as $value) {
                if (is_string($value)) {
                    $summary['values']++;
                    $summary['truncated_values'] += (int) (mb_strlen($value) > self::AUDIT_VALUE_MAX_LENGTH);
                } elseif ($value === null) {
                    $summary['null_values']++;
                } else {
                    $summary['skipped_values']++;
                }
            }
            $full = self::untruncatedAuditEntry($path, $parameters);
            $audit = mb_substr($full, 0, self::AUDIT_MAX_LENGTH);
            $summary['truncated_entries'] += (int) (mb_strlen($full) > self::AUDIT_MAX_LENGTH);
            $summary['entry_bytes'] += strlen($audit);
            $summary['max_entry_bytes'] = max($summary['max_entry_bytes'], strlen($audit));
            $distinct[$audit] = true;
        }
        $summary['distinct_entries'] = count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, invalid: int, values: int, null_values: int, skipped_values: int, truncated_values: int, truncated_entries: int, entry_bytes: int, max_entry_bytes: int, distinct_entries: int}
     */
    public static function summarizeAuditEntryBatchNative(array $entries): array
    {
        if (mb_internal_encoding() !== 'UTF-8') {
            return self::summarizeAuditEntryBatchPhp($entries);
        }
        $summary = ['entries' => count($entries), 'invalid' => 0, 'values' => 0, 'null_values' => 0, 'skipped_values' => 0, 'truncated_values' => 0, 'truncated_entries' => 0, 'entry_bytes' => 0, 'max_entry_bytes' => 0, 'distinct_entries' => 0];
        $parts = [];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            $parameters = is_array($entry) ? ($entry['parameters'] ?? null) : null;
            if (! is_string($path) || ! is_array($parameters)) {
                $summary['invalid']++;

                continue;
            }
            $strings = 0;
            $nulls = 0;
            foreach ($parameters as $value) {
                if (is_string($value)) {
                    $strings++;
                } elseif ($value === null) {
                    $nulls++;
                }
            }
            $summary['values'] += $strings;
            $summary['null_values'] += $nulls;
            $summary['skipped_values'] += count($parameters) - $strings - $nulls;
            $audited = $strings === count($parameters) ? $parameters : array_filter($parameters, static fn (mixed $value): bool => is_string($value) || $value === null);
            // Rust splits keys and values on NUL and declines the batch when one held a NUL.
            $parts[] = $path;
            $parts[] = (string) count($audited);
            $parts[] = implode("\0", array_keys($audited));
            $parts[] = implode("\0", $audited);
        }
        $fields = ApiInputCanonicalizer::evaluateW455Native(5, $parts, 5);
        if ($fields === null) {
            return self::summarizeAuditEntryBatchPhp($entries);
        }
        [$summary['truncated_values'], $summary['truncated_entries'], $summary['entry_bytes'], $summary['max_entry_bytes'], $summary['distinct_entries']] = $fields;

        return $summary;
    }

    public const string W53_FFI_CDEF = 'int64_t nntmux_w535_api_usage_audit_value_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W53.5 parity test's QUALIFICATION). */
    private const bool W53_RUST_FFI_DEFAULT = true;

    /**
     * Wave 53.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w53_5', self::W53_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W53_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW53RustFfi(): bool
    {
        return self::w53RustFfiState() === true;
    }

    /**
     * Runs $callback with auditValuePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 53.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w53Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w53RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w535_api_usage_audit_value_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch plan of audited string values as {@see auditEntryFromPhp()} cuts and encodes them. Three
     * words per value: `[mb_strlen(), bytes of the first AUDIT_VALUE_MAX_LENGTH characters, RFC 3986
     * encoded length of those bytes]`.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function auditValuePlanBatch(array $values): array
    {
        return self::usesW53RustFfi() ? self::auditValuePlanBatchNative($values) : self::auditValuePlanBatchPhp($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function auditValuePlanBatchPhp(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $kept = mb_substr($value, 0, self::AUDIT_VALUE_MAX_LENGTH);
            $out[] = mb_strlen($value);
            $out[] = strlen($kept);
            $out[] = strlen(rawurlencode($kept));
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library, outside a UTF-8
     * mbstring encoding, for invalid UTF-8 or when a value holds a NUL.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function auditValuePlanBatchNative(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return [];
        }
        if (mb_internal_encoding() !== 'UTF-8') {
            return self::auditValuePlanBatchPhp($values);
        }

        return self::w53Words($count, implode("\0", $values), '', 3 * $count) ?? self::auditValuePlanBatchPhp($values);
    }
}
