<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminReleaseReportBulkActionRequest;
use App\Http\Requests\Admin\AdminReleaseReportListRequest;
use App\Models\Release;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AdminReleaseReportController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_ctl_report_bulk_status(const char *action_ptr, size_t action_len);
    ssize_t nntmux_admin_ctl_report_action_label(
        const char *action_ptr,
        size_t action_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_ctl_report_bulk_message(
        int64_t count,
        const char *action_ptr,
        size_t action_len,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_ctl_report_can_revert(const char *status_ptr, size_t status_len);
    ssize_t nntmux_admin_ctl_report_trim_response(
        const char *response_ptr,
        size_t response_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const array BULK_STATUSES = [null, 'dismissed', 'resolved', 'reviewed'];

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Display a listing of release reports.
     */
    public function index(AdminReleaseReportListRequest $request): View
    {
        $this->setAdminPrefs();

        $status = $request->status();
        $reportsList = ReleaseReport::getReportsRange($status, 50);
        $statusCounts = ReleaseReport::getCountByStatus();

        $meta_title = $title = 'Release Reports';

        return view('admin.release-reports.index', compact(
            'reportsList',
            'statusCounts',
            'status',
            'title',
            'meta_title'
        ));
    }

    /**
     * Update report status.
     */
    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'status' => 'required|in:pending,reviewed,resolved,dismissed',
        ]);

        $report = ReleaseReport::findOrFail($id);
        $report->update([
            'status' => $request->input('status'),
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        ReleaseBrowseService::bumpCacheVersion();

        return redirect()->back()->with('success', 'Report status updated successfully.');
    }

    /**
     * Update the staff response for a report.
     */
    public function updateResponse(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'response' => 'nullable|string|max:2000',
            'response_is_public' => 'nullable|boolean',
        ]);

        $report = ReleaseReport::findOrFail($id);
        $state = self::responseState((string) $request->input('response', ''), $request->boolean('response_is_public'));

        if ($state['cleared']) {
            $report->update([
                'response' => $state['response'],
                'responded_by' => null,
                'responded_at' => null,
                'response_is_public' => $state['response_is_public'],
            ]);

            ReleaseBrowseService::bumpCacheVersion();

            return redirect()->back()->with('success', 'Report response cleared successfully.');
        }

        $report->update([
            'response' => $state['response'],
            'responded_by' => Auth::id(),
            'responded_at' => now(),
            'response_is_public' => $state['response_is_public'],
        ]);
        ReleaseBrowseService::bumpCacheVersion();

        return redirect()->back()->with('success', 'Report response saved successfully.');
    }

    /**
     * Delete the reported release and resolve the report.
     */
    public function deleteRelease(int $id): RedirectResponse
    {
        $report = ReleaseReport::with('release')->findOrFail($id);

        if ($report->release) {
            /** @var Release $release */
            $release = $report->release;
            $releaseName = $release->searchname;
            $releaseId = $report->releases_id;

            // Delete the release
            Release::where('id', $releaseId)->delete();

            // Update all reports for this release to resolved
            ReleaseReport::where('releases_id', $releaseId)
                ->update([
                    'status' => 'resolved',
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);
            ReleaseBrowseService::bumpCacheVersion();

            return redirect()->back()->with('success', "Release '{$releaseName}' deleted and all related reports resolved.");
        }

        return redirect()->back()->with('error', 'Release not found or already deleted.');
    }

    /**
     * Dismiss a report.
     */
    public function dismiss(int $id): RedirectResponse
    {
        $report = ReleaseReport::findOrFail($id);
        $report->update([
            'status' => 'dismissed',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        ReleaseBrowseService::bumpCacheVersion();

        return redirect()->back()->with('success', 'Report dismissed successfully.');
    }

    /**
     * Revert a resolved or dismissed report back to reviewed status.
     */
    public function revert(int $id): RedirectResponse
    {
        $report = ReleaseReport::findOrFail($id);

        if (! self::canRevert($report->status)) {
            return redirect()->back()->with('error', 'Only resolved or dismissed reports can be reverted.');
        }

        $report->update([
            'status' => 'reviewed',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        ReleaseBrowseService::bumpCacheVersion();

        return redirect()->back()->with('success', 'Report reverted to reviewed status successfully.');
    }

    /**
     * Bulk update report statuses.
     */
    public function bulkAction(AdminReleaseReportBulkActionRequest $request): RedirectResponse
    {
        $action = $request->actionName();
        $reportIds = $request->reportIds();
        $count = $action === 'delete'
            ? $this->bulkDeleteReportedReleases($reportIds)
            : $this->bulkUpdateReportStatuses($reportIds, $action);

        if ($count > 0) {
            ReleaseBrowseService::bumpCacheVersion();
        }

        return redirect()->back()->with('success', self::bulkActionMessage($count, $action));
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function bulkUpdateReportStatuses(array $reportIds, string $action): int
    {
        $status = self::bulkStatus($action);

        if ($status === null) {
            return 0;
        }

        $query = ReleaseReport::query()
            ->whereIn('id', $reportIds)
            ->when($action === 'revert', fn ($query) => $query->whereIn('status', ['resolved', 'dismissed']));

        $count = (clone $query)->count();

        $query->update([
            'status' => $status,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return $count;
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function bulkDeleteReportedReleases(array $reportIds): int
    {
        $reports = ReleaseReport::query()
            ->with('release:id')
            ->whereIn('id', $reportIds)
            ->get(['id', 'releases_id']);

        $count = 0;

        foreach ($reports as $report) {
            if (! $report->release) {
                continue;
            }

            $releaseId = $report->releases_id;
            Release::where('id', $releaseId)->delete();
            ReleaseReport::where('releases_id', $releaseId)
                ->update([
                    'status' => 'resolved',
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);
            $count++;
        }

        return $count;
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
     * Report status a bulk action applies; `null` when the action does not update statuses.
     */
    public static function bulkStatus(string $action): ?string
    {
        return self::$useRustFfi ? self::bulkStatusNative($action) : self::bulkStatusPhp($action);
    }

    public static function bulkStatusPhp(string $action): ?string
    {
        return match ($action) {
            'dismiss' => 'dismissed',
            'resolve' => 'resolved',
            'reviewed', 'revert' => 'reviewed',
            default => null,
        };
    }

    public static function bulkStatusNative(string $action): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::bulkStatusPhp($action);
        }

        try {
            $code = self::callFfi($ffi, 'nntmux_admin_ctl_report_bulk_status', $action, \strlen($action));

            return \is_int($code) && \array_key_exists($code, self::BULK_STATUSES)
                ? self::BULK_STATUSES[$code]
                : self::bulkStatusPhp($action);
        } catch (Throwable) {
            return self::bulkStatusPhp($action);
        }
    }

    public static function bulkActionLabel(string $action): string
    {
        return self::$useRustFfi ? self::bulkActionLabelNative($action) : self::bulkActionLabelPhp($action);
    }

    public static function bulkActionLabelPhp(string $action): string
    {
        return match ($action) {
            'delete' => 'deleted',
            'dismiss' => 'dismissed',
            'resolve' => 'resolved',
            'reviewed' => 'marked as reviewed',
            'revert' => 'reverted to reviewed',
            default => 'processed',
        };
    }

    public static function bulkActionLabelNative(string $action): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::bulkActionLabelPhp($action);
        }

        return self::callForString($ffi, 'nntmux_admin_ctl_report_action_label', 32, $action, \strlen($action))
            ?? self::bulkActionLabelPhp($action);
    }

    public static function bulkActionMessage(int $count, string $action): string
    {
        return self::$useRustFfi ? self::bulkActionMessageNative($count, $action) : self::bulkActionMessagePhp($count, $action);
    }

    public static function bulkActionMessagePhp(int $count, string $action): string
    {
        $actionLabel = self::bulkActionLabelPhp($action);

        return "{$count} report(s) {$actionLabel} successfully.";
    }

    public static function bulkActionMessageNative(int $count, string $action): string
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::bulkActionMessagePhp($count, $action);
        }

        return self::callForString($ffi, 'nntmux_admin_ctl_report_bulk_message', 96, $count, $action, \strlen($action))
            ?? self::bulkActionMessagePhp($count, $action);
    }

    /**
     * Only resolved or dismissed reports can be reverted (PHP loose `in_array`).
     */
    public static function canRevert(mixed $status): bool
    {
        return self::$useRustFfi ? self::canRevertNative($status) : self::canRevertPhp($status);
    }

    public static function canRevertPhp(mixed $status): bool
    {
        return in_array($status, ['resolved', 'dismissed']);
    }

    public static function canRevertNative(mixed $status): bool
    {
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($status)) {
            return self::canRevertPhp($status);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_admin_ctl_report_can_revert', $status, \strlen($status));

            return $result === 0 || $result === 1 ? $result === 1 : self::canRevertPhp($status);
        } catch (Throwable) {
            return self::canRevertPhp($status);
        }
    }

    /**
     * Trimmed staff response; an empty response clears it and makes the cleared state public.
     *
     * @return array{cleared: bool, response: ?string, response_is_public: bool}
     */
    public static function responseState(string $response, bool $isPublic): array
    {
        return self::$useRustFfi ? self::responseStateNative($response, $isPublic) : self::responseStatePhp($response, $isPublic);
    }

    /**
     * @return array{cleared: bool, response: ?string, response_is_public: bool}
     */
    public static function responseStatePhp(string $response, bool $isPublic): array
    {
        return self::stateForTrimmed(trim($response), $isPublic);
    }

    /**
     * @return array{cleared: bool, response: ?string, response_is_public: bool}
     */
    public static function responseStateNative(string $response, bool $isPublic): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::responseStatePhp($response, $isPublic);
        }

        $trimmed = self::callForString($ffi, 'nntmux_admin_ctl_report_trim_response', \strlen($response), $response, \strlen($response));

        return $trimmed === null ? self::responseStatePhp($response, $isPublic) : self::stateForTrimmed($trimmed, $isPublic);
    }

    /**
     * @return array{cleared: bool, response: ?string, response_is_public: bool}
     */
    private static function stateForTrimmed(string $trimmed, bool $isPublic): array
    {
        if ($trimmed === '') {
            return ['cleared' => true, 'response' => null, 'response_is_public' => true];
        }

        return ['cleared' => false, 'response' => $trimmed, 'response_is_public' => $isPublic];
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
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

        $ffi = RustPipelineLibrary::resolveDomain('w28-admin-category-release-content-controllers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
