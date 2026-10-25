<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Manual PC ISO 1998 dictionary from one complete public DATS.SITE metadata export.
 * Only independent literal MIA ROM names are eligible (237 of the captured 301 games).
 * The other 64 games contain archive-file metadata; decorated game names never supply titles.
 * Export/version dates and configured date prefixes never establish PRE or listing time.
 * Optional catalogue nuke annotations are validated but are not imported as current scene status.
 */
final class DatsPcIsoSource extends BoundedHttpFeedSource implements CompleteCatalogueSource
{
    /**
     * Wave 44.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php measures at
     * least 1.9x (mean and p99, common and uncommon batches). PHP stays the fallback when the
     * library is unavailable.
     * Named W443 so it stays distinct from the inherited feed toggles.
     */
    public const bool W443_RUST_FFI_DEFAULT = true;

    public const string W443_FFI_CDEF = <<<'C'
        ssize_t nntmux_w443_dats_pciso_rom_name_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Byte limit for a literal ROM name. */
    public const int W443_MAX_TITLE_BYTES = 255;

    /** @var list<string> */
    public const array W443_ARCHIVE_MARKERS = ['.zip', '.rar', '.7z'];

    /** Exactly 7 0x1F-separated decimals of 1-19 digits. */
    private const string W443_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){6}\d{1,19}\z/';

    private static ?bool $w443RustFfiOverride = null;

    private static FFI|false|null $w443RustFfiHandle = null;

    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_predb_xml_dats_pciso(const char *xml, size_t len, size_t max_records, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $native = null;

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
        $output = strlen($xml) <= $this->maximumBodyBytes ? $this->nativeExport($xml) : null;
        if ($output !== null) {
            $parts = explode("\0", substr($output, 1));
            $unsupported = (int) array_shift($parts);
            $entries = [];
            foreach ($parts as $title) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'dats.site');
            }

            return $this->publish($entries, $unsupported);
        }
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

        return $this->publish($entries, $unsupported);
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @return list<PredbFeedEntry>
     */
    private function publish(array $entries, int $unsupported): array
    {
        $this->entries = $entries;
        $this->acceptedCount = count($entries);
        $this->rejections = $unsupported > 0 ? ['archive_file_metadata' => $unsupported] : [];

        return $entries;
    }

    /**
     * Rust export output, or null when PHP must parse the export (library missing, or any export
     * outside the reproduced XML subset, including every invalid one).
     */
    private function nativeExport(string $xml): ?string
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_xml_catalogues', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $length = strlen($xml);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_xml_dats_pciso($xml, $length, $this->maximumRecords, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_xml_dats_pciso($xml, $length, $this->maximumRecords, $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
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

    /**
     * Enables (true) or disables (false) the Wave 44.3 Rust helper; null restores
     * W443_RUST_FFI_DEFAULT.
     */
    public static function useW443RustFfi(?bool $enable = true): void
    {
        self::$w443RustFfiOverride = $enable;
    }

    public static function usesW443RustFfi(): bool
    {
        return self::$w443RustFfiOverride ?? self::W443_RUST_FFI_DEFAULT;
    }

    public static function resetW443RustFfi(): void
    {
        self::$w443RustFfiOverride = null;
        self::$w443RustFfiHandle = null;
    }

    /**
     * ROM name summary for a batch of untrimmed DATS ROM names. Each name is empty, else
     * overlength above W443_MAX_TITLE_BYTES bytes, else control when it holds an ASCII control
     * byte, else nonliteral when it holds a space, slash or backslash, else literal; any lowercased
     * name holding a W443_ARCHIVE_MARKERS entry also counts as archive. Non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $romNames
     * @return array{total: int, literal: int, empty: int, overlength: int, control: int, nonliteral: int, archive: int}
     */
    public static function summarizeRomNameBatch(array $romNames): array
    {
        return (self::usesW443RustFfi() ? self::summarizeRomNameBatchNative($romNames) : null)
            ?? self::summarizeRomNameBatchPhp($romNames);
    }

    /**
     * @param  array<array-key, mixed>  $romNames
     * @return array{total: int, literal: int, empty: int, overlength: int, control: int, nonliteral: int, archive: int}
     */
    public static function summarizeRomNameBatchPhp(array $romNames): array
    {
        $literal = 0;
        $empty = 0;
        $overlength = 0;
        $control = 0;
        $nonliteral = 0;
        $archive = 0;
        foreach ($romNames as $item) {
            $name = \is_string($item) ? $item : '';
            $lower = strtolower($name);
            foreach (self::W443_ARCHIVE_MARKERS as $needle) {
                if (str_contains($lower, $needle)) {
                    $archive++;

                    break;
                }
            }
            if ($name === '') {
                $empty++;
            } elseif (\strlen($name) > self::W443_MAX_TITLE_BYTES) {
                $overlength++;
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                $control++;
            } elseif (strpbrk($name, ' /\\') !== false) {
                $nonliteral++;
            } else {
                $literal++;
            }
        }

        return [
            'total' => \count($romNames),
            'literal' => $literal,
            'empty' => $empty,
            'overlength' => $overlength,
            'control' => $control,
            'nonliteral' => $nonliteral,
            'archive' => $archive,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $romNames
     * @return array{total: int, literal: int, empty: int, overlength: int, control: int, nonliteral: int, archive: int}|null
     */
    public static function summarizeRomNameBatchNative(array $romNames): ?array
    {
        $lengths = [];
        foreach ($romNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW443Native(implode('', $romNames), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'literal' => (int) $fields[1],
            'empty' => (int) $fields[2],
            'overlength' => (int) $fields[3],
            'control' => (int) $fields[4],
            'nonliteral' => (int) $fields[5],
            'archive' => (int) $fields[6],
        ];
    }

    /**
     * Calls this class's Wave 44.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W443_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW443Native(string $blob, string $lens): ?array
    {
        $ffi = self::w443RustFfi();
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
            $written = $ffi->nntmux_w443_dats_pciso_rom_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w443_dats_pciso_rom_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W443_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w443RustFfi(): ?FFI
    {
        if (self::$w443RustFfiHandle === null) {
            self::$w443RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w443', self::W443_FFI_CDEF) ?? false;
        }

        return self::$w443RustFfiHandle === false ? null : self::$w443RustFfiHandle;
    }
}
