<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Console\Commands\NntmuxSearchReconcile;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\GetNzbController;
use App\Http\Requests\Admin\AdminLogFacetsRequest;
use App\Http\Requests\Admin\AdminLogFileRequest;
use App\Http\Requests\Admin\AdminLogSearchRequest;
use App\Http\Requests\Admin\AdminLogViewerRequest;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\LogViewer\Index\LogSearchCoordinator;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminLogViewerController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    /**
     * Rust stays opt-in for the Wave 39.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustAdminSettingsMonitoringLogsReconcileW39ParityTest.php.
     */
    public const bool W395_RUST_FFI_DEFAULT = false;

    private static ?bool $useW395RustFfi = null;

    /** PSR-3 log levels in ascending severity. */
    public const array LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private const array LOG_LEVEL_INDEX = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    public const string FFI_CDEF = GetNzbController::FFI_CDEF;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @return array{initialFile: string, delete_guard_minutes: int, max_results_per_file: int} */
    public static function logViewerState(string $selected, string $firstPath, int $guard, int $max): array
    {
        return self::$useRustFfi ? self::logViewerStateNative($selected, $firstPath, $guard, $max) : self::logViewerStatePhp($selected, $firstPath, $guard, $max);
    }

    /** @return array{initialFile: string, delete_guard_minutes: int, max_results_per_file: int} */
    public static function logViewerStatePhp(string $selected, string $firstPath, int $guard, int $max): array
    {
        return ['initialFile' => $selected !== '' ? $selected : $firstPath, 'delete_guard_minutes' => max(0, $guard), 'max_results_per_file' => max(1, $max)];
    }

    /** @return array{initialFile: string, delete_guard_minutes: int, max_results_per_file: int} */
    public static function logViewerStateNative(string $selected, string $firstPath, int $guard, int $max): array
    {
        $result = GetNzbController::transformNative(9, [$selected, $firstPath, $guard, $max]);

        return is_array($result) ? $result : self::logViewerStatePhp($selected, $firstPath, $guard, $max);
    }

    public static function deleteConflictMessage(int $guardMinutes): string
    {
        return self::$useRustFfi ? self::deleteConflictMessageNative($guardMinutes) : self::deleteConflictMessagePhp($guardMinutes);
    }

    public static function deleteConflictMessagePhp(int $guardMinutes): string
    {
        return "This log was written to in the last {$guardMinutes} minutes and is probably still open by a running process. Truncate it instead.";
    }

    public static function deleteConflictMessageNative(int $guardMinutes): string
    {
        $result = GetNzbController::transformNative(10, [$guardMinutes]);

        return is_string($result) ? $result : self::deleteConflictMessagePhp($guardMinutes);
    }

    public static function useW395RustFfi(?bool $use = true): void
    {
        self::$useW395RustFfi = $use;
    }

    public static function usesW395RustFfi(): bool
    {
        return self::$useW395RustFfi ?? self::W395_RUST_FFI_DEFAULT;
    }

    public static function resetW395RustFfi(): void
    {
        self::$useW395RustFfi = null;
    }

    /**
     * Counts PSR-3 levels after trim() and ASCII lowercasing; anything else (including non-strings)
     * is unknown. An unknown minimum severity falls back to `debug`. `highest_index` is -1 when no
     * known level was seen.
     *
     * @param  array<array-key, mixed>  $levels
     * @return array{total: int, counts: array<string, int>, unknown: int, highest: ?string, highest_index: int, min_severity: string, at_or_above: int}
     */
    public static function summarizeLogLevelDistribution(array $levels, string $minSeverity = 'debug'): array
    {
        return self::usesW395RustFfi()
            ? self::summarizeLogLevelDistributionNative($levels, $minSeverity)
            : self::summarizeLogLevelDistributionPhp($levels, $minSeverity);
    }

    /**
     * @param  array<array-key, mixed>  $levels
     * @return array{total: int, counts: array<string, int>, unknown: int, highest: ?string, highest_index: int, min_severity: string, at_or_above: int}
     */
    private static function summarizeLogLevelDistributionNative(array $levels, string $minSeverity): array
    {
        $parts = [$minSeverity];
        foreach ($levels as $level) {
            $parts[] = \is_string($level) ? $level : '';
        }
        $fields = NntmuxSearchReconcile::evaluateW395Native(3, $parts);
        if ($fields === null || \count($fields) !== 13) {
            return self::summarizeLogLevelDistributionPhp($levels, $minSeverity);
        }

        return self::levelSummary(
            (int) $fields[0],
            array_map(intval(...), \array_slice($fields, 1, 8)),
            (int) $fields[9],
            (int) $fields[10],
            (int) $fields[11],
            (int) $fields[12],
        );
    }

    /**
     * @param  array<array-key, mixed>  $levels
     * @return array{total: int, counts: array<string, int>, unknown: int, highest: ?string, highest_index: int, min_severity: string, at_or_above: int}
     */
    private static function summarizeLogLevelDistributionPhp(array $levels, string $minSeverity): array
    {
        $minIndex = self::LOG_LEVEL_INDEX[strtolower(trim($minSeverity))] ?? 0;
        $counts = array_fill(0, 8, 0);
        $unknown = $atOrAbove = 0;
        $highest = -1;
        foreach ($levels as $level) {
            $index = \is_string($level) ? (self::LOG_LEVEL_INDEX[strtolower(trim($level))] ?? null) : null;
            if ($index === null) {
                $unknown++;

                continue;
            }
            $counts[$index]++;
            $highest = max($highest, $index);
            if ($index >= $minIndex) {
                $atOrAbove++;
            }
        }

        return self::levelSummary(\count($levels), $counts, $unknown, $highest, $minIndex, $atOrAbove);
    }

    /**
     * @param  array<int, int>  $counts
     * @return array{total: int, counts: array<string, int>, unknown: int, highest: ?string, highest_index: int, min_severity: string, at_or_above: int}
     */
    private static function levelSummary(int $total, array $counts, int $unknown, int $highest, int $minIndex, int $atOrAbove): array
    {
        return [
            'total' => $total,
            'counts' => array_combine(self::LOG_LEVELS, array_values($counts)),
            'unknown' => $unknown,
            'highest' => self::LOG_LEVELS[$highest] ?? null,
            'highest_index' => $highest,
            'min_severity' => self::LOG_LEVELS[$minIndex] ?? 'debug',
            'at_or_above' => $atOrAbove,
        ];
    }

    private const int JSON_OPTIONS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function index(AdminLogViewerRequest $request, LogFileRepository $logs, LogSearchCoordinator $searcher): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $selectedPath = (string) ($request->validated('file') ?? '');

        if ($selectedPath !== '' && $logs->find($selectedPath) === null) {
            return redirect()
                ->route('admin.logs.index')
                ->with('error', 'Selected log file is not available.');
        }

        $files = $this->fileList($logs, $searcher);

        return view('admin.logs.index', [
            'files' => $files,
            'initialFile' => self::logViewerState($selectedPath, $files[0]['path'] ?? '', 0, 1)['initialFile'],
            'initialQuery' => (string) ($request->validated('q') ?? ''),
            'engine' => $searcher->engine(),
            'levels' => LogEntryParser::LEVELS,
            'limitOptions' => AdminLogFileRequest::LIMIT_OPTIONS,
            'defaultLimit' => AdminLogFileRequest::DEFAULT_LIMIT,
            'maxFilesPerSearch' => (int) config('nntmux.log_viewer.max_files_per_search', 25),
            'indexEnabled' => (bool) config('nntmux.log_viewer.index.enabled', true),
            'title' => 'Log Viewer',
            'page_title' => 'Log Viewer',
            'meta_title' => 'Log Viewer',
            'meta_description' => 'Browse and search application logs.',
        ]);
    }

    public function files(LogFileRepository $logs, LogSearchCoordinator $searcher): JsonResponse
    {
        return $this->json([
            'files' => $this->fileList($logs, $searcher),
            'engine' => $searcher->engine(),
        ]);
    }

    public function entries(AdminLogFileRequest $request, LogFileRepository $logs, LogReader $reader): JsonResponse
    {
        $file = $this->resolve($request, $logs);
        $before = $request->validated('before');

        try {
            $result = $reader->latest(
                $file,
                $before === null ? null : (int) $before,
                (int) ($request->validated('limit') ?? AdminLogFileRequest::DEFAULT_LIMIT),
                $request->levels(),
            );
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json([
            'file' => $file->toArray($this->guardMinutes()),
            'entries' => array_map(static fn (LogEntry $entry): array => $entry->toArray(), $result['entries']),
            'before' => $result['before'],
            'scanned_bytes' => $result['scanned_bytes'],
            'budget_exhausted' => $result['budget_exhausted'],
        ]);
    }

    public function entry(AdminLogFileRequest $request, LogFileRepository $logs, LogReader $reader): JsonResponse
    {
        $request->validate(['offset' => ['required', 'integer', 'min:0']]);
        $file = $this->resolve($request, $logs);

        try {
            $entry = $reader->entryAt($file, (int) $request->validated('offset'));
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        if ($entry === null) {
            return $this->json(['message' => 'The log file has changed; reload it to see this entry.'], 404);
        }

        return $this->json(['entry' => $entry->toArray()]);
    }

    public function search(AdminLogSearchRequest $request, LogFileRepository $logs, LogSearchCoordinator $searcher): JsonResponse
    {
        $before = $request->validated('before');

        return $this->json($searcher->search(
            $request->searchQuery(),
            $this->requestedFiles($request, $logs),
            $before === null ? null : (int) $before,
            self::logViewerState('', '', 0, (int) config('nntmux.log_viewer.max_results_per_file', 100))['max_results_per_file'],
        ));
    }

    public function facets(AdminLogFacetsRequest $request, LogFileRepository $logs, LogSearchCoordinator $searcher): JsonResponse
    {
        return $this->json($searcher->facets($request->searchQuery(), $this->requestedFiles($request, $logs)));
    }

    public function download(AdminLogFileRequest $request, LogFileRepository $logs): BinaryFileResponse
    {
        $file = $this->resolve($request, $logs);

        return response()->download($file->absolutePath, $file->name, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function truncate(AdminLogFileRequest $request, LogFileRepository $logs, LogIndexer $indexer): JsonResponse
    {
        $file = $this->resolve($request, $logs);
        $indexer->forget($file);

        try {
            $logs->truncate($file);
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit($request, 'Admin truncated log file', $file);

        return $this->json([
            'ok' => true,
            'file' => $logs->find($file->path)?->toArray($this->guardMinutes()),
        ]);
    }

    public function destroy(AdminLogFileRequest $request, LogFileRepository $logs, LogIndexer $indexer): JsonResponse
    {
        $file = $this->resolve($request, $logs);
        $guardMinutes = $this->guardMinutes();

        if ($file->isActive($guardMinutes)) {
            return $this->json([
                'message' => self::deleteConflictMessage($guardMinutes),
            ], 409);
        }

        $indexer->forget($file);

        try {
            $logs->delete($file);
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit($request, 'Admin deleted log file', $file);

        return $this->json(['ok' => true]);
    }

    private function resolve(AdminLogFileRequest $request, LogFileRepository $logs): LogFile
    {
        return $logs->find((string) $request->validated('file')) ?? abort(404, 'Log file not found.');
    }

    /**
     * @return list<LogFile>
     */
    private function requestedFiles(AdminLogSearchRequest $request, LogFileRepository $logs): array
    {
        $files = [];

        foreach ((array) $request->validated('files') as $path) {
            $files[] = $logs->find((string) $path) ?? abort(404, 'Log file not found.');
        }

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fileList(LogFileRepository $logs, LogSearchCoordinator $searcher): array
    {
        $guardMinutes = $this->guardMinutes();
        $files = $logs->all();
        $status = $searcher->fileStatus($files);

        return array_map(
            static fn (LogFile $file): array => [...$file->toArray($guardMinutes), 'index' => $status[$file->path] ?? null],
            $files,
        );
    }

    private function guardMinutes(): int
    {
        return self::logViewerState('', '', (int) config('nntmux.log_viewer.delete_guard_minutes', 10), 1)['delete_guard_minutes'];
    }

    private function audit(Request $request, string $message, LogFile $file): void
    {
        Log::channel('admin')->warning($message, [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'username' => $request->user()?->username,
            'file' => $file->path,
            'size_before' => $file->size,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], self::JSON_OPTIONS);
    }
}
