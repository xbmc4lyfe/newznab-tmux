<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class ReleaseReport extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    /**
     * The table associated with the model.
     */
    protected $table = 'release_reports';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'releases_id',
        'users_id',
        'reason',
        'description',
        'response',
        'status',
        'reviewed_by',
        'reviewed_at',
        'responded_by',
        'responded_at',
        'response_is_public',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'reviewed_at' => 'datetime',
        'responded_at' => 'datetime',
        'response_is_public' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Predefined report reasons
     */
    public const REASONS = [
        'duplicate' => 'Duplicate Release',
        'fake' => 'Fake/Malicious Content',
        'password' => 'Password Protected',
        'incomplete' => 'Incomplete/Corrupted',
        'wrong_category' => 'Wrong Category',
        'spam' => 'Spam/Advertisement',
        'other' => 'Other',
    ];

    /**
     * Convert a comma-separated list of reason keys to their labels.
     */
    public static function reasonKeysToLabels(?string $reasonKeys): string
    {
        if (self::$useRustFfi && ($native = self::reasonKeysToLabelsNative($reasonKeys)) !== null) {
            return $native;
        }

        if (empty($reasonKeys)) {
            return 'Unknown';
        }

        $keys = array_map('trim', explode(',', $reasonKeys));
        $labels = [];

        foreach ($keys as $key) {
            $labels[] = self::REASONS[$key] ?? ucfirst($key);
        }

        return implode(', ', array_unique($labels));
    }

    /**
     * Get the release that was reported.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * Get the user who reported the release.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Get the admin who reviewed the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the admin who responded to the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /**
     * Get a paginated list of reports with optional filters.
     */
    public static function getReportsRange(// @phpstan-ignore missingType.generics
        ?string $status = null,
        int $perPage = 50
    ): LengthAwarePaginator {
        $query = self::query()
            ->select([
                'id',
                'releases_id',
                'users_id',
                'reason',
                'description',
                'response',
                'status',
                'reviewed_by',
                'reviewed_at',
                'responded_by',
                'responded_at',
                'response_is_public',
                'created_at',
                'updated_at',
            ])
            ->with([
                'release:id,guid,searchname,size',
                'user:id,username',
                'reviewer:id,username',
                'responder:id,username',
            ])
            ->orderByDesc('created_at');

        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get report count by status.
     *
     * @return array<string, mixed>
     */
    public static function getCountByStatus(): array
    {
        $counts = self::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return self::statusCountSummary($counts);
    }

    /** @param array<string, mixed> $counts
     * @return array<string, mixed>
     */
    public static function statusCountSummary(array $counts): array
    {
        if (self::$useRustFfi && ($native = self::statusCountSummaryNative($counts)) !== null) {
            return $native;
        }

        return [
            'pending' => $counts['pending'] ?? 0,
            'reviewed' => $counts['reviewed'] ?? 0,
            'resolved' => $counts['resolved'] ?? 0,
            'dismissed' => $counts['dismissed'] ?? 0,
            'total' => array_sum($counts),
        ];
    }

    /**
     * Check if a user has already reported a specific release.
     */
    public static function hasUserReported(int $releaseId, int $userId): bool
    {
        return self::query()
            ->where('releases_id', $releaseId)
            ->where('users_id', $userId)
            ->exists();
    }

    /**
     * Get the human-readable reason label.
     */
    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public static function reasonKeysToLabelsNative(?string $reasonKeys): ?string
    {
        return self::nativeCall(0, $reasonKeys ?? '');
    }

    /** @param array<string, mixed> $counts
     * @return array<string, mixed>|null
     */
    public static function statusCountSummaryNative(array $counts): ?array
    {
        $fields = [];
        foreach ($counts as $status => $count) {
            // SQL counts can be strings: preserve the types of known status values.
            if ((! is_int($count) && ! is_string($count)) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $count)
                || (string) (int) $count !== (string) $count) {
                return null;
            }
            $fields[] = (string) $status;
            $fields[] = (string) $count;
        }
        $input = self::packFrames($fields);
        $result = $input === null ? null : self::unpackFrames(self::nativeCall(1, $input));
        if ($result === null || count($result) !== 5) {
            return null;
        }

        return [
            'pending' => is_string($counts['pending'] ?? null) ? $result[0] : (int) $result[0],
            'reviewed' => is_string($counts['reviewed'] ?? null) ? $result[1] : (int) $result[1],
            'resolved' => is_string($counts['resolved'] ?? null) ? $result[2] : (int) $result[2],
            'dismissed' => is_string($counts['dismissed'] ?? null) ? $result[3] : (int) $result[3],
            'total' => (int) $result[4],
        ];
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave20-report', 'intptr_t nntmux_wave20_report(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);');
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 32 + 256), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave20_report($mode, $input, strlen($input), $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function packFrames(array $values): ?string
    {
        $input = '';
        foreach ($values as $value) {
            if (strlen($value) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<string>|null */
    private static function unpackFrames(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $values = [];
        for ($offset = 0, $length = strlen($input); $offset < $length;) {
            if ($length - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($input, $offset, 4));
            if ($header === false || ! is_int($header['length']) || $header['length'] > $length - $offset - 4) {
                return null;
            }
            $offset += 4;
            $values[] = substr($input, $offset, $header['length']);
            $offset += $header['length'];
        }

        return $values;
    }

    // Single-call FFI must qualify on all four benchmark metrics before adoption.
    private static bool $useRustFfi = false;

    public static function useRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }
}
