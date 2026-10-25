<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use RuntimeException;
use Throwable;

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

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustReleaseModelDetailsFeedSourceParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /**
     * Wave 46.2 production default for summarizeDirnameBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbFeedsSrrdb3dsdbXrelNswdbImporterW46ParityTest.php measures 2.73-2.85x mean and 2.36-2.86x
     * p99 on common pages and 2.95-3.07x mean and 2.27-2.42x p99 on uncommon batches. PHP stays the fallback when the
     * library is unavailable.
     */
    public const bool W462_RUST_FFI_DEFAULT = true;

    private const string XREL_PAGE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w374_xrel_page_plan(
            int64_t page_size,
            int64_t page,
            int64_t total_pages,
            uint32_t p2p,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const string XREL_FIELD_SEPARATOR = "\x1F";

    private static ?bool $xrelUseRustFfi = null;

    private static FFI|false|null $xrelFfi = null;

    private static ?bool $w462RustFfiOverride = null;

    private static FFI|false|null $w462RustFfiHandle = null;

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

        $native = PredatabaseSource::nativeJsonEntries($response->body(), $this->p2p ? 3 : 2, $this->p2p ? 'xrel-p2p' : 'xrel');
        if ($native !== null) {
            $totalPages = (int) $native[0][0];

            return $totalPages > 0 && $page > $totalPages ? [] : $native[1];
        }

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

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$xrelUseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$xrelUseRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$xrelUseRustFfi = null;
        self::$xrelFfi = null;
    }

    /**
     * Plans fetch() for a page: the clamped per_page and page query values, whether the page lies
     * past a known total, the native JSON field count and the source key and entry source.
     *
     * @return array{per_page: int, page: int, past_end: bool, json_fields: int, key: string, source: string}
     */
    public static function planXrelPage(int $pageSize, int $page, int $totalPages, bool $p2p): array
    {
        return self::usesRustFfi()
            ? self::planXrelPageNative($pageSize, $page, $totalPages, $p2p)
            : self::planXrelPagePhp($pageSize, $page, $totalPages, $p2p);
    }

    /**
     * @return array{per_page: int, page: int, past_end: bool, json_fields: int, key: string, source: string}
     */
    private static function planXrelPageNative(int $pageSize, int $page, int $totalPages, bool $p2p): array
    {
        $output = self::callXrelNative([$pageSize, $page, $totalPages, $p2p ? 1 : 0], 128);
        $fields = $output === null ? [] : explode(self::XREL_FIELD_SEPARATOR, $output);
        if (count($fields) !== 6) {
            return self::planXrelPagePhp($pageSize, $page, $totalPages, $p2p);
        }

        return [
            'per_page' => (int) $fields[0],
            'page' => (int) $fields[1],
            'past_end' => $fields[2] === '1',
            'json_fields' => (int) $fields[3],
            'key' => $fields[4],
            'source' => $fields[5],
        ];
    }

    /**
     * @return array{per_page: int, page: int, past_end: bool, json_fields: int, key: string, source: string}
     */
    private static function planXrelPagePhp(int $pageSize, int $page, int $totalPages, bool $p2p): array
    {
        return [
            'per_page' => max(self::MIN_PAGE_SIZE, min($pageSize, self::MAX_PAGE_SIZE)),
            'page' => max(1, $page),
            'past_end' => $totalPages > 0 && $page > $totalPages,
            'json_fields' => $p2p ? 3 : 2,
            'key' => $p2p ? 'xrel_p2p' : 'xrel',
            'source' => $p2p ? 'xrel-p2p' : 'xrel',
        ];
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callXrelNative(array $args, int $hint): ?string
    {
        self::$xrelFfi ??= RustPipelineLibrary::resolveDomain('metadata_w374', self::XREL_PAGE_FFI_CDEF) ?? false;
        if (self::$xrelFfi === false) {
            return null;
        }
        $ffi = self::$xrelFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w374_xrel_page_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w374_xrel_page_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Force the Wave 46.2 helper onto Rust (true) or PHP (false); null restores W462_RUST_FFI_DEFAULT. */
    public static function useW462RustFfi(?bool $enable = true): void
    {
        self::$w462RustFfiOverride = $enable;
    }

    public static function usesW462RustFfi(): bool
    {
        return self::$w462RustFfiOverride ?? self::W462_RUST_FFI_DEFAULT;
    }

    public static function resetW462RustFfi(): void
    {
        self::$w462RustFfiOverride = null;
        self::$w462RustFfiHandle = null;
    }

    /**
     * Summarize dirnames as parseRows() admits them: non-strings are invalid and dirnames that trim() to '' are
     * empty; accepted dirnames count those trim() changed, those ending in a -GROUP suffix, those holding a space
     * and the distinct strtolower() dirnames.
     *
     * @param  array<array-key, mixed>  $dirnames
     * @return array{total: int, invalid: int, empty: int, accepted: int, padded: int, grouped: int, spaced: int, distinct: int}
     */
    public static function summarizeDirnameBatch(array $dirnames): array
    {
        return (self::usesW462RustFfi() ? self::summarizeDirnameBatchNative($dirnames) : null)
            ?? self::summarizeDirnameBatchPhp($dirnames);
    }

    /**
     * @param  array<array-key, mixed>  $dirnames
     * @return array{total: int, invalid: int, empty: int, accepted: int, padded: int, grouped: int, spaced: int, distinct: int}
     */
    public static function summarizeDirnameBatchPhp(array $dirnames): array
    {
        $summary = ['total' => \count($dirnames), 'invalid' => 0, 'empty' => 0, 'accepted' => 0, 'padded' => 0, 'grouped' => 0, 'spaced' => 0,
            'distinct' => 0];
        $seen = [];
        foreach ($dirnames as $dirname) {
            if (! \is_string($dirname)) {
                $summary['invalid']++;

                continue;
            }
            $title = trim($dirname);
            if ($title === '') {
                $summary['empty']++;

                continue;
            }
            $summary['accepted']++;
            if ($title !== $dirname) {
                $summary['padded']++;
            }
            if (preg_match('/-[A-Za-z0-9_]+$/D', $title) === 1) {
                $summary['grouped']++;
            }
            if (str_contains($title, ' ')) {
                $summary['spaced']++;
            }
            $seen[strtolower($title)] = true;
        }
        $summary['distinct'] = \count($seen);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $dirnames
     * @return array{total: int, invalid: int, empty: int, accepted: int, padded: int, grouped: int, spaced: int, distinct: int}|null
     */
    public static function summarizeDirnameBatchNative(array $dirnames): ?array
    {
        $values = PredbFeedImporter::w462Summary(self::w462RustFfi(), 2, $dirnames, 8);
        if ($values === null) {
            return null;
        }
        [$total, $invalid, $empty, $accepted, $padded, $grouped, $spaced, $distinct] = $values;
        if ($total !== \count($dirnames) || min($values) < 0 || $invalid + $empty + $accepted !== $total
            || max($padded, $grouped, $spaced, $distinct) > $accepted || ($distinct > 0) !== ($accepted > 0)) {
            return null;
        }

        return ['total' => $total, 'invalid' => $invalid, 'empty' => $empty, 'accepted' => $accepted, 'padded' => $padded, 'grouped' => $grouped,
            'spaced' => $spaced, 'distinct' => $distinct];
    }

    private static function w462RustFfi(): ?FFI
    {
        self::$w462RustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedImporter::W462_FFI_DOMAIN, PredbFeedImporter::W462_FFI_CDEF) ?? false;

        return self::$w462RustFfiHandle === false ? null : self::$w462RustFfiHandle;
    }
}
