<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use RuntimeException;
use SimpleXMLElement;

/**
 * Manual PC ISO 1998 dictionary from one complete public DATS.SITE metadata export.
 * Only independent literal MIA ROM names are eligible (237 of the captured 301 games).
 * The other 64 games contain archive-file metadata; decorated game names never supply titles.
 * Export/version dates and configured date prefixes never establish PRE or listing time.
 * Optional catalogue nuke annotations are validated but are not imported as current scene status.
 */
final class DatsPcIsoSource extends BoundedHttpFeedSource implements CompleteCatalogueSource
{
    /** @var list<PredbFeedEntry>|null */
    private ?array $entries = null;

    /** @var array<string, int> */
    private array $rejections = [];

    private int $acceptedCount = 0;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        int $maximumBodyBytes = 1048576,
        private readonly int $maximumRecords = 5000,
    ) {
        if ($maximumBodyBytes < 1 || $maximumBodyBytes > 8388608 || $maximumRecords < 1 || $maximumRecords > 5000) {
            throw new RuntimeException('DATS_PCISO_1998 limits must be positive and bounded.');
        }
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent, $maximumBodyBytes);
    }

    public function key(): string
    {
        return 'dats_pciso_1998';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('DATS_PCISO_1998 local pages start at one.');
        }
        $this->entries ??= $this->parseXml($this->downloadBody());
        if ($page > (int) ceil(count($this->entries) / $this->pageSize)) {
            return [];
        }

        return array_slice($this->entries, ($page - 1) * $this->pageSize, $this->pageSize);
    }

    public function acceptedCount(): int
    {
        return $this->acceptedCount;
    }

    /** @return array<string, int> */
    public function rejectionCounts(): array
    {
        return $this->rejections;
    }

    /** @return list<PredbFeedEntry> */
    public function parseXml(string $xml): array
    {
        $this->entries = null;
        $this->acceptedCount = 0;
        $this->rejections = [];
        if (strlen($xml) > $this->maximumBodyBytes || ! mb_check_encoding($xml, 'UTF-8')
            || str_contains($xml, "\0") || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new RuntimeException('DATS_PCISO_1998 returned an invalid, oversized or entity-declaring export.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false || $document->getName() !== 'datafile' || $document->getDocNamespaces(true) !== []
            || ! $this->hasAttributes($document, []) || trim((string) $document) !== ''
            || count($document->header) !== 1 || count($document->game) < 1 || count($document->game) > $this->maximumRecords
        ) {
            throw new RuntimeException('DATS_PCISO_1998 returned an invalid complete export schema.');
        }
        $this->validateHeader($document->header);
        $entries = [];
        $games = [];
        $titles = [];
        $unsupported = 0;
        foreach ($document->children() as $game) {
            if ($game->getName() === 'header') {
                continue;
            }
            if ($game->getName() !== 'game' || ! $this->hasAttributes($game, ['name'])
                || count($game->description) !== 1 || count($game->description->children()) !== 0
                || ! $this->hasAttributes($game->description, []) || count($game->rom) < 1
                || trim((string) $game) !== '') {
                throw new RuntimeException('DATS_PCISO_1998 returned an invalid game schema.');
            }
            if (count($game->nukedreason) > 1 || (count($game->nukedreason) === 1
                && (count($game->nukedreason->children()) !== 0 || ! $this->hasAttributes($game->nukedreason, [])
                    || (string) $game->nukedreason === ''))) {
                throw new RuntimeException('DATS_PCISO_1998 returned an ambiguous catalogue annotation.');
            }
            $gameName = (string) $game['name'];
            if ($gameName === '' || (string) $game->description !== $gameName || isset($games[mb_strtolower($gameName, 'UTF-8')])) {
                throw new RuntimeException('DATS_PCISO_1998 returned duplicate games or conflicting descriptions.');
            }
            $games[mb_strtolower($gameName, 'UTF-8')] = true;
            $romNames = [];
            $mia = [];
            foreach ($game->children() as $rom) {
                if (in_array($rom->getName(), ['description', 'nukedreason'], true)) {
                    continue;
                }
                $name = (string) $rom['name'];
                if ($rom->getName() !== 'rom' || count($rom->children()) !== 0 || (string) $rom !== ''
                    || $name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\p{C}]/u', $name) !== 0
                    || isset($romNames[mb_strtolower($name, 'UTF-8')])) {
                    throw new RuntimeException('DATS_PCISO_1998 returned invalid or duplicate ROM records.');
                }
                $romNames[mb_strtolower($name, 'UTF-8')] = true;
                if (isset($rom['mia'])) {
                    if (! $this->hasAttributes($rom, ['name', 'mia']) || (string) $rom['mia'] !== 'yes') {
                        throw new RuntimeException('DATS_PCISO_1998 returned an ambiguous MIA record.');
                    }
                    $mia[] = $name;
                } else {
                    if (! $this->hasAttributes($rom, ['name', 'size', 'crc', 'md5', 'sha1'])
                        || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $rom['size']) !== 1
                        || preg_match('/^[0-9a-f]{8}$/iD', (string) $rom['crc']) !== 1
                        || preg_match('/^[0-9a-f]{32}$/iD', (string) $rom['md5']) !== 1
                        || preg_match('/^[0-9a-f]{40}$/iD', (string) $rom['sha1']) !== 1) {
                        throw new RuntimeException('DATS_PCISO_1998 returned invalid archive-file metadata.');
                    }
                }
            }
            if ($mia === []) {
                $unsupported++;

                continue;
            }
            if (count($mia) !== 1 || count($game->rom) !== 1) {
                throw new RuntimeException('DATS_PCISO_1998 returned mixed or ambiguous MIA title records.');
            }
            $title = $mia[0];
            if (preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0 || str_contains($title, '/') || str_contains($title, '\\')
                || ! str_ends_with($gameName, '-'.$title) || isset($titles[mb_strtolower($title, 'UTF-8')])) {
                throw new RuntimeException('DATS_PCISO_1998 returned a nonliteral or conflicting MIA title association.');
            }
            $titles[mb_strtolower($title, 'UTF-8')] = true;
            $entries[] = new PredbFeedEntry(title: $title, source: 'dats.site');
        }
        $this->entries = $entries;
        $this->acceptedCount = count($entries);
        $this->rejections = $unsupported > 0 ? ['archive_file_metadata' => $unsupported] : [];

        return $entries;
    }

    private function validateHeader(SimpleXMLElement $header): void
    {
        if (! $this->hasAttributes($header, []) || trim((string) $header) !== '') {
            throw new RuntimeException('DATS_PCISO_1998 returned an invalid export header.');
        }
        $fields = [];
        foreach ($header->children() as $field) {
            $fields[] = $field->getName();
            if (count($field->children()) !== 0 || ! $this->hasAttributes($field, $field->getName() === 'clrmamepro' ? ['forcepacking'] : [])) {
                throw new RuntimeException('DATS_PCISO_1998 returned nested or unsupported header fields.');
            }
        }
        if ($fields !== ['name', 'description', 'category', 'version', 'date', 'clrmamepro']
            || (string) $header->name !== 'PC iSO Games Scene 1998' || (string) $header->description !== (string) $header->name
            || (string) $header->category !== 'Scene' || (string) $header->version === '' || (string) $header->date === ''
            || (string) $header->clrmamepro['forcepacking'] !== 'unzip' || (string) $header->clrmamepro !== '') {
            throw new RuntimeException('DATS_PCISO_1998 returned a different or unsupported metadata dataset.');
        }
    }

    /** @param list<string> $expected */
    private function hasAttributes(SimpleXMLElement $element, array $expected): bool
    {
        $actual = array_keys(iterator_to_array($element->attributes()));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
