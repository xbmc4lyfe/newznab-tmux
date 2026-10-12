<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Public Knaben API v2 movie metadata, restricted to one latest browse page.
 * Titles and publisher category labels stay literal. Torrent dates, sizes, files and
 * payload links have no documented scene PRE meaning and are never imported.
 * API: https://knaben.org/api/v2/; this adapter does not implement API history paging.
 */
final class KnabenSource extends BoundedHttpFeedSource
{
    /** @var array<string, int> */
    private array $rejections = [];

    private int $acceptedCount = 0;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
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
            $offset = 0;
            $this->checkValueMembers($json, $offset);
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
}
