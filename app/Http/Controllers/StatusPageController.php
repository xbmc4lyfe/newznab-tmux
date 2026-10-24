<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SiteStatusService;
use Illuminate\View\View;

class StatusPageController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function incidentGroupDate(?string $formattedDate): string
    {
        return self::$useRustFfi ? self::incidentGroupDateNative($formattedDate) : self::incidentGroupDatePhp($formattedDate);
    }

    public static function incidentGroupDatePhp(?string $formattedDate): string
    {
        return $formattedDate ?? 'unknown';
    }

    public static function incidentGroupDateNative(?string $formattedDate): string
    {
        $result = BrowseController::transformNative(6, [$formattedDate]);

        return is_string($result) ? $result : self::incidentGroupDatePhp($formattedDate);
    }

    /** @return array{meta_title: string, meta_description: string, title: string} */
    public static function pageMeta(): array
    {
        return self::$useRustFfi ? self::pageMetaNative() : self::pageMetaPhp();
    }

    /** @return array{meta_title: string, meta_description: string, title: string} */
    public static function pageMetaPhp(): array
    {
        return [
            'meta_title' => 'Service status',
            'meta_description' => 'Current status of site services, incidents, and uptime.',
            'title' => 'Service status',
        ];
    }

    /** @return array{meta_title: string, meta_description: string, title: string} */
    public static function pageMetaNative(): array
    {
        $result = BrowseController::transformNative(7, []);
        if (is_array($result) && array_keys($result) === ['meta_title', 'meta_description', 'title'] && is_string($result['meta_title']) && is_string($result['meta_description']) && is_string($result['title'])) {
            return $result;
        }

        return self::pageMetaPhp();
    }

    public function __construct(
        protected SiteStatusService $siteStatusService
    ) {
        parent::__construct();
    }

    /**
     * Public site status page (no auth required; method name is in BasePageController auth except list).
     */
    public function showStatusPage(): View
    {
        $services = $this->siteStatusService->getPublicEnabledServices();
        $overall = $this->siteStatusService->getOverallPublicStatus();
        $activeIncidents = $this->siteStatusService->getActiveIncidentsForPublic();
        $recentResolved = $this->siteStatusService->getRecentResolvedIncidentsForPublic(30);

        $groupedResolved = $recentResolved->groupBy(function ($incident) {
            return self::incidentGroupDate($incident->resolved_at?->format('Y-m-d'));
        });

        $this->viewData = array_merge($this->viewData, self::pageMeta(), [
            'services' => $services,
            'overallStatus' => $overall,
            'activeIncidents' => $activeIncidents,
            'recentResolvedGrouped' => $groupedResolved,
        ]);

        return view('status.index', $this->viewData);
    }
}
