<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * xREL API v2: scene releases (`/v2/release/latest.json`) or P2P releases (`/v2/p2p/releases.json`).
 *
 * Scene row: {dirname, time (unix), size: {number, unit}, ext_info: {type}, flags: {nuke_rls}}
 * P2P row:   {dirname, pub_time (unix), size_mb, category: {meta_cat, sub_cat}}
 * Rate limit: 900 requests per hour (X-RateLimit-* headers).
 */
final class XrelSource extends HttpFeedSource
{
    /** xREL list endpoints accept per_page between 5 and 100. */
    private const int MIN_PAGE_SIZE = 5;

    private const int MAX_PAGE_SIZE = 100;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly bool $p2p = false,
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return $this->p2p ? 'xrel_p2p' : 'xrel';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'per_page' => max(self::MIN_PAGE_SIZE, min($this->pageSize, self::MAX_PAGE_SIZE)),
            'page' => max(1, $page),
        ])->throw();

        $totalPages = (int) $response->json('pagination.total_pages', -1);
        if ($totalPages > 0 && $page > $totalPages) {
            return [];
        }

        $rows = $response->json('list');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('xREL returned an unexpected response (no list).');
        }

        return $this->parseRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['dirname'] ?? null) || trim($row['dirname']) === '') {
                continue;
            }

            $entries[] = $this->p2p ? $this->p2pEntry($row) : $this->sceneEntry($row);
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sceneEntry(array $row): PredbFeedEntry
    {
        $type = is_array($row['ext_info'] ?? null) && is_string($row['ext_info']['type'] ?? null) ? $row['ext_info']['type'] : '';

        return new PredbFeedEntry(
            title: trim((string) $row['dirname']),
            source: 'xrel',
            category: $type !== '' ? strtoupper($type) : null,
            size: $this->sceneSize($row['size'] ?? null),
            predate: is_numeric($row['time'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['time']) : null,
            nuked: ! empty($row['flags']['nuke_rls']) ? Predb::PRE_NUKED : Predb::PRE_NONUKE,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function p2pEntry(array $row): PredbFeedEntry
    {
        $category = is_array($row['category'] ?? null) ? $row['category'] : [];
        $meta = is_string($category['meta_cat'] ?? null) ? strtoupper($category['meta_cat']) : '';
        $sub = is_string($category['sub_cat'] ?? null) ? $category['sub_cat'] : '';

        return new PredbFeedEntry(
            title: trim((string) $row['dirname']),
            source: 'xrel-p2p',
            category: $meta === '' ? null : ($sub === '' ? $meta : $meta.'-'.$sub),
            size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size_mb'] ?? null) ? (float) $row['size_mb'] : null),
            predate: is_numeric($row['pub_time'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['pub_time']) : null,
        );
    }

    private function sceneSize(mixed $size): ?string
    {
        if (! is_array($size) || ! is_numeric($size['number'] ?? null)) {
            return null;
        }

        // Symbolic units such as RAR or NFO are not byte sizes.
        $megabytes = match (strtoupper((string) ($size['unit'] ?? ''))) {
            'KB' => (float) $size['number'] / 1024,
            'MB' => (float) $size['number'],
            'GB' => (float) $size['number'] * 1024,
            'TB' => (float) $size['number'] * 1048576,
            default => null,
        };

        return PredbFeedEntry::sizeFromMegabytes($megabytes);
    }
}
