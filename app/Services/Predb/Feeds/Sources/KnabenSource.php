<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Public Knaben API v2 movie metadata, restricted to one latest browse page.
 * Titles and publisher category labels stay literal. Torrent dates, sizes, files and
 * payload links have no documented scene PRE meaning and are never imported.
 * API: https://knaben.org/api/v2/; this adapter does not implement API history paging.
 */
final class KnabenSource extends BoundedHttpFeedSource
{
    /**
     * Wave 45.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest.php measures at least
     * 2.2x (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable or a title holds a character Rust leaves to PCRE2.
     * useW453RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W453 so it stays distinct from the class's existing native paths.
     */
    public const bool W453_RUST_FFI_DEFAULT = true;

    public const string W453_FFI_CDEF = <<<'C'
        ssize_t nntmux_w453_knaben_title_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** mb_strlen() character limit for an accepted title. */
    public const int W453_MAX_TITLE_CHARS = 255;

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W453_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w453RustFfiOverride = null;

    private static FFI|false|null $w453RustFfiHandle = null;

    private const NATIVE_CDEF = <<<'C'
        int32_t nntmux_predb_knaben_validate_unique_keys(const char *json, size_t json_len);
        C;

    private static FFI|false|null $native = null;

    /** @var array<string, int> */
    private array $rejections = [];

    private int $acceptedCount = 0;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly bool $useNative = true,
    ) {
        $size = min(300, $pageSize);
        $query = http_build_query(['c' => 3000000, 'o' => 'date', 'd' => 'desc', 's' => $size, 'f' => 0]);
        parent::__construct($endpoint.'?'.$query, $size, $timeout, $userAgent);
    }

    public function key(): string
    {
        return 'knaben';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('KNABEN pages start at one.');
        }
        if ($page > 1) {
            return [];
        }

        return $this->parseJson($this->downloadBody());
    }

    /** @return array<string, int> */
    public function rejectionCounts(): array
    {
        return $this->rejections;
    }

    public function acceptedCount(): int
    {
        return $this->acceptedCount;
    }

    /** @return list<PredbFeedEntry> */
    public function parseJson(string $json): array
    {
        $this->rejections = [];
        $this->acceptedCount = 0;
        if (strlen($json) > $this->maximumBodyBytes) {
            throw $this->bodyLimitException();
        }

        try {
            $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            $unique = $this->useNative ? $this->nativeUniqueMembers($json) : null;
            if ($unique === null) {
                $offset = 0;
                $this->checkValueMembers($json, $offset);
            } elseif (! $unique) {
                throw new RuntimeException('KNABEN returned ambiguous duplicate JSON object members.');
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('KNABEN returned invalid JSON.', previous: $exception);
        }

        if (! $document instanceof stdClass || ! isset($document->hits, $document->total)
            || ! is_array($document->hits) || ! array_is_list($document->hits)
            || count($document->hits) > $this->pageSize || ! $document->total instanceof stdClass
            || ! isset($document->total->relation, $document->total->value)
            || ! in_array($document->total->relation, ['eq', 'gte'], true)
            || ! is_int($document->total->value) || $document->total->value < count($document->hits)
        ) {
            throw new RuntimeException('KNABEN returned an invalid browse envelope or exceeded the requested hit limit.');
        }

        $entries = [];
        $rejections = [];
        foreach ($document->hits as $hit) {
            if (! $hit instanceof stdClass || ! isset($hit->title, $hit->categoryId)
                || ! is_string($hit->title) || $hit->title === ''
                || ! is_array($hit->categoryId) || ! array_is_list($hit->categoryId) || $hit->categoryId === []
                || (isset($hit->category) && ! is_string($hit->category))
                || (isset($hit->date) && ! is_string($hit->date))
            ) {
                throw new RuntimeException('KNABEN returned malformed title or category metadata.');
            }
            foreach ($hit->categoryId as $categoryId) {
                if (! is_int($categoryId) || $categoryId < 1) {
                    throw new RuntimeException('KNABEN returned malformed category IDs.');
                }
            }

            $reason = $this->rejectionReason($hit);
            if ($reason !== null) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }
            $entries[] = new PredbFeedEntry(title: $hit->title, source: 'knaben.org', category: $hit->category ?? null);
        }

        $this->rejections = $rejections;
        $this->acceptedCount = count($entries);
        if ($rejections !== []) {
            Log::warning('KNABEN latest movie page omitted nonliteral or ineligible records.', [
                'total_records' => count($document->hits),
                'accepted_records' => count($entries),
                'rejected_records' => array_sum($rejections),
                'reasons' => $rejections,
            ]);
        }

        return $entries;
    }

    /**
     * Rust duplicate-member scan of an already decoded document: null when PHP must scan instead.
     */
    private function nativeUniqueMembers(string $json): ?bool
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_feeds', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_predb_knaben_validate_unique_keys($json, strlen($json));

        return match ($status) {
            1 => true,
            0 => false,
            default => null,
        };
    }

    private function rejectionReason(stdClass $hit): ?string
    {
        if (! in_array(3000000, $hit->categoryId, true)) {
            return 'non_movie_category';
        }
        if (str_contains($hit->title, '...') || str_contains($hit->title, '…')) {
            return 'truncated_title';
        }
        if (preg_match('/[\s\p{Z}\p{C}]/u', $hit->title) !== 0 || preg_match('/^[^.]+\.[^.]+(?:\.[^.]+)*$/uD', $hit->title) !== 1) {
            return 'nonliteral_title';
        }
        if (mb_strlen($hit->title, 'UTF-8') > 255) {
            return 'overlength_title';
        }

        return null;
    }

    /**
     * Traverse only already validated, bounded JSON tokens. Decode each object key
     * separately so escaped equivalents cannot silently overwrite identity fields.
     */
    private function checkValueMembers(string $json, int &$offset): void
    {
        $offset += strspn($json, " \t\r\n", $offset);
        $type = $json[$offset];
        if ($type === '{' || $type === '[') {
            $object = $type === '{';
            $end = $object ? '}' : ']';
            $offset++;
            $keys = [];
            while (true) {
                $offset += strspn($json, " \t\r\n", $offset);
                if ($json[$offset] === $end) {
                    $offset++;

                    return;
                }
                if ($object) {
                    $key = json_decode($this->readStringToken($json, $offset), false, 64, JSON_THROW_ON_ERROR);
                    $index = ':'.$key;
                    if (isset($keys[$index])) {
                        throw new RuntimeException('KNABEN returned ambiguous duplicate JSON object members.');
                    }
                    $keys[$index] = true;
                    $offset += strspn($json, " \t\r\n", $offset);
                    $offset++;
                }
                $this->checkValueMembers($json, $offset);
                $offset += strspn($json, " \t\r\n", $offset);
                if ($json[$offset] === ',') {
                    $offset++;
                }
            }
        }
        if ($type === '"') {
            $this->readStringToken($json, $offset);

            return;
        }
        $offset += strcspn($json, ",]} \t\r\n", $offset);
    }

    private function readStringToken(string $json, int &$offset): string
    {
        $start = $offset++;
        while ($json[$offset] !== '"') {
            if ($json[$offset] === '\\') {
                $offset++;
            }
            $offset++;
        }
        $offset++;

        return substr($json, $start, $offset - $start);
    }

    /**
     * Enables (true) or disables (false) the Wave 45.3 Rust helper; null restores
     * W453_RUST_FFI_DEFAULT.
     */
    public static function useW453RustFfi(?bool $enable = true): void
    {
        self::$w453RustFfiOverride = $enable;
    }

    public static function usesW453RustFfi(): bool
    {
        return self::$w453RustFfiOverride ?? self::W453_RUST_FFI_DEFAULT;
    }

    public static function resetW453RustFfi(): void
    {
        self::$w453RustFfiOverride = null;
        self::$w453RustFfiHandle = null;
    }

    /**
     * Title admission summary, using rejectionReason()'s title rules in order: an empty title is
     * empty, one holding `...` or `…` is truncated, one holding `\s`, `\p{Z}` or `\p{C}` (or
     * invalid UTF-8) or lacking a dotted shape is nonliteral, one over W453_MAX_TITLE_CHARS
     * characters is overlength, else accepted. Non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, accepted: int, empty: int, truncated: int, nonliteral: int, overlength: int}
     */
    public static function summarizeKnabenTitleBatch(array $items): array
    {
        return (self::usesW453RustFfi() ? self::summarizeKnabenTitleBatchNative($items) : null)
            ?? self::summarizeKnabenTitleBatchPhp($items);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, accepted: int, empty: int, truncated: int, nonliteral: int, overlength: int}
     */
    public static function summarizeKnabenTitleBatchPhp(array $items): array
    {
        $accepted = 0;
        $empty = 0;
        $truncated = 0;
        $nonliteral = 0;
        $overlength = 0;
        foreach ($items as $item) {
            $title = \is_string($item) ? $item : '';
            if ($title === '') {
                $empty++;
            } elseif (str_contains($title, '...') || str_contains($title, '…')) {
                $truncated++;
            } elseif (preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0 || preg_match('/^[^.]+\.[^.]+(?:\.[^.]+)*$/uD', $title) !== 1) {
                $nonliteral++;
            } elseif (mb_strlen($title, 'UTF-8') > self::W453_MAX_TITLE_CHARS) {
                $overlength++;
            } else {
                $accepted++;
            }
        }

        return [
            'total' => \count($items),
            'accepted' => $accepted,
            'empty' => $empty,
            'truncated' => $truncated,
            'nonliteral' => $nonliteral,
            'overlength' => $overlength,
        ];
    }

    /**
     * Declines (null) for non-string items, and whenever Rust leaves a character class to PCRE2,
     * so PHP counts them.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, accepted: int, empty: int, truncated: int, nonliteral: int, overlength: int}|null
     */
    public static function summarizeKnabenTitleBatchNative(array $items): ?array
    {
        $lengths = [];
        foreach ($items as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW453Native(implode('', $items), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'accepted' => (int) $fields[1],
            'empty' => (int) $fields[2],
            'truncated' => (int) $fields[3],
            'nonliteral' => (int) $fields[4],
            'overlength' => (int) $fields[5],
        ];
    }

    /**
     * Calls this class's Wave 45.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W453_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW453Native(string $blob, string $lens): ?array
    {
        $ffi = self::w453RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w453_knaben_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w453_knaben_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W453_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w453RustFfi(): ?FFI
    {
        if (self::$w453RustFfiHandle === null) {
            self::$w453RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w453', self::W453_FFI_CDEF) ?? false;
        }

        return self::$w453RustFfiHandle === false ? null : self::$w453RustFfiHandle;
    }
}
