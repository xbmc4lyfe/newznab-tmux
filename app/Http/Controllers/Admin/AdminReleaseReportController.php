<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminReleaseReportBulkActionRequest;
use App\Http\Requests\Admin\AdminReleaseReportListRequest;
use App\Models\Release;
use App\Models\ReleaseReport;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminReleaseReportController extends BasePageController
{
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
        $response = trim((string) $request->input('response', ''));

        if ($response === '') {
            $report->update([
                'response' => null,
                'responded_by' => null,
                'responded_at' => null,
                'response_is_public' => true,
            ]);

            ReleaseBrowseService::bumpCacheVersion();

            return redirect()->back()->with('success', 'Report response cleared successfully.');
        }

        $report->update([
            'response' => $response,
            'responded_by' => Auth::id(),
            'responded_at' => now(),
            'response_is_public' => $request->boolean('response_is_public'),
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

        if (! in_array($report->status, ['resolved', 'dismissed'])) {
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

        $actionLabel = match ($action) {
            'delete' => 'deleted',
            'dismiss' => 'dismissed',
            'resolve' => 'resolved',
            'reviewed' => 'marked as reviewed',
            'revert' => 'reverted to reviewed',
            default => 'processed',
        };

        return redirect()->back()->with('success', "{$count} report(s) {$actionLabel} successfully.");
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function bulkUpdateReportStatuses(array $reportIds, string $action): int
    {
        $status = match ($action) {
            'dismiss' => 'dismissed',
            'resolve' => 'resolved',
            'reviewed', 'revert' => 'reviewed',
            default => null,
        };

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
}
