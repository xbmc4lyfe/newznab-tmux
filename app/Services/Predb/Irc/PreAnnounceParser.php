<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use InvalidArgumentException;

/**
 * Parses announce lines from public scene pre channels into feed entries.
 *
 * Pre channels announce a section and a release name, so a PRE line is timed at receipt. INFO lines
 * (files and size) only fill in rows that already exist. Messages must already have IRC colour and
 * formatting codes stripped.
 *
 * Formats (PRE and INFO layouts checked against live traffic; nuke layouts are not yet observed):
 *  - corruptnet  (Corrupt-Net #pre, EFnet #pre):  `PRE: [FLAC] Name-GRP`, `INFO: Name-GRP [14F 67MB]`
 *  - zenet       (ZEnet #pre, Abjects #MG-Pre):   `(PRE) (MP3-WEB) (Name-GRP)`, `(INFO) (Name-GRP) (2 Files) (38 MB)`
 *  - predatabase (predataba.se #pre, #p2ptrace):  `pre | MP3-WEB | Name-GRP`, `info | Name-GRP - 14 F & 67 MB`,
 *                                                 `p2p | #1 | BTN | Name-GRP, WIN!`
 *  - ngp         (ngp.re #ngpre):                 `.::: PRE ::: TV-X264-HD ::: Name-GRP :::.`,
 *                                                 `[INFO] FOR Name-GRP with 5F 158.3MB added.`
 *  - opentrackers (public #pre-all/#p2p-pre relay): mixed captured grammars, unknown PRE time.
 *  - rizon       (Rizon #pre):                    `[PRE]  [TV-HD]  Name-GRP`
 */
final class PreAnnounceParser
{
    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_irc_pre_announce_parse(const char *line, size_t line_len, int32_t format, char *out_buf, size_t out_cap);
        ssize_t nntmux_irc_pre_announce_parse_batch_nul(const char *lines, size_t lines_len, int32_t format, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $native = null;

    private static ?CData $nativeBuffer = null;

    private static int $nativeCapacity = 0;

    private readonly int $nativeFormat;

    public const FORMATS = ['corruptnet', 'zenet', 'predatabase', 'ngp', 'rizon', 'opentrackers'];

    private const RELAY_FORMATS = ['corruptnet', 'zenet', 'predatabase', 'rizon'];

    /**
     * Announce type keyword => predb nuke status.
     *
     * @var array<string, int>
     */
    private const TYPES = [
        'PRE' => Predb::PRE_NONUKE,
        'P2P' => Predb::PRE_NONUKE,
        'INFO' => Predb::PRE_NONUKE,
        'NUKE' => Predb::PRE_NUKED,
        'UNNUKE' => Predb::PRE_UNNUKED,
        'MODNUKE' => Predb::PRE_MODNUKE,
        'RENUKE' => Predb::PRE_RENUKED,
        'OLDNUKE' => Predb::PRE_OLDNUKE,
    ];

    /**
     * Ordered patterns per format. Named groups: type, section, title, reason, files, size (MB).
     *
     * @var array<string, list<string>>
     */
    private const PATTERNS = [
        'corruptnet' => [
            '/^(?<type>PRE):\s*\[(?<section>[^\]]+)\]\s+(?<title>\S+)\z/',
            '/^(?<type>INFO):\s*(?<title>\S+)\s+\[(?<files>\d+)F\s+(?<size>\d+(?:\.\d+)?)MB\]\z/',
            '/^(?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE):\s*(?<title>\S+)\s+\[(?<reason>[^\]]*)\](?:\s+\[NUKENET\])?\z/',
        ],
        'zenet' => [
            '/^\((?<type>PRE)\)\s*\((?<section>[^)]+)\)\s*\((?<title>\S+)\)\z/i',
            '/^\((?<type>INFO)\)\s*\((?<title>\S+?)\)\s*\((?<files>\d+) Files?\)\s*\((?<size>\d+(?:\.\d+)?) MB\)\z/i',
            '/^\((?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE)\)\s*\((?<title>\S+?)\)\s*\((?<reason>[^)]*)\)\z/i',
        ],
        'predatabase' => [
            '/^(?<type>pre)\s*\|\s*(?<section>[^|]+?)\s*\|\s*(?<title>\S+)\z/i',
            '/^(?<type>info)\s*\|\s*(?<title>\S+)\s+-\s+(?<files>\d+)\s+F\s+&\s+(?<size>\d+(?:\.\d+)?)\s+MB\z/i',
            // Only the first site to get a P2P release announces "#1 ... WIN!"; later lines repeat it.
            '/^(?<type>p2p)\s*\|\s*#1\s*\|\s*\S+\s*\|\s*(?<title>\S+?),\s*WIN!\z/i',
        ],
        'ngp' => [
            '/^\.:::\s*(?<type>[A-Z]+)\s*:::\s*(?<section>\S+)\s*:::\s*(?<title>\S+)\s*:::\.\z/',
            '/^\[(?<type>INFO)\]\s*FOR\s+(?<title>\S+)\s+with\s+(?<files>\d+)F\s+(?<size>\d+(?:\.\d+)?)MB added\.?\z/',
        ],
        'rizon' => [
            '/^\[(?<type>PRE)\]\s*\[(?<section>[^\]]+)\]\s*(?<title>\S+)\z/',
            '/^\[(?<type>NUKE|UNNUKE|MODNUKE|RENUKE|OLDNUKE)\]\s+(?<title>\S+)\s+\[(?<reason>[^\]]*)\](?:\s+\[NUKENET\])?\z/',
        ],
    ];

    public function __construct(
        private readonly string $format,
        private readonly string $source,
    ) {
        if ($format !== 'opentrackers' && ! isset(self::PATTERNS[$format])) {
            throw new InvalidArgumentException("Unknown IRC pre announce format [{$format}]. Available: ".implode(', ', self::FORMATS));
        }
        $this->nativeFormat = (int) array_search($format, self::FORMATS, true);
    }

    public function parse(string $message, ?CarbonImmutable $receivedAt = null): ?PredbFeedEntry
    {
        // Bounded ASCII relay PRE/nuke lines qualified; INFO conversion missed p99.
        if ($this->format === 'opentrackers' && strlen($message) <= 512 && strncasecmp(ltrim($message, " \t\v("), 'INFO', 4) !== 0 && mb_check_encoding($message, 'ASCII')
            && ! str_contains($message, "\0") && ! str_contains($message, "\r") && ! str_contains($message, "\n")) {
            $entry = $this->parseNative($message, $receivedAt);
            if ($entry !== false) {
                return $entry;
            }
        }

        return $this->parsePhp($message, $receivedAt);
    }

    private function parsePhp(string $message, ?CarbonImmutable $receivedAt = null): ?PredbFeedEntry
    {
        if (str_contains($message, "\r") || str_contains($message, "\n")) {
            return null;
        }

        if ($this->format === 'opentrackers') {
            return $this->parseRelay($message, $receivedAt);
        }

        $message = trim($message);

        foreach (self::PATTERNS[$this->format] as $pattern) {
            if (preg_match($pattern, $message, $m) === 1) {
                return $this->entry($m, $receivedAt);
            }
        }

        return null;
    }

    /**
     * Preserve one result per input, including unmatched lines. Embedded NUL bytes
     * use PHP rather than splitting a message into multiple transport records.
     *
     * @param  list<string>  $messages
     * @return list<PredbFeedEntry|null>
     */
    public function parseBatch(array $messages, ?CarbonImmutable $receivedAt = null): array
    {
        if ($messages === []) {
            return [];
        }
        // Small and single-grammar batches did not meet the 1.5x adoption gate.
        if ($this->format === 'opentrackers' && count($messages) >= 16
            && array_all($messages, static fn (string $message): bool => strlen($message) <= 512 && strncasecmp(ltrim($message, " \t\v("), 'INFO', 4) !== 0)) {
            $joined = implode("\0", $messages)."\0";
            if (mb_check_encoding($joined, 'ASCII') && substr_count($joined, "\0") === count($messages)) {
                $entries = $this->parseBatchNative($messages, $receivedAt);
                if ($entries !== null) {
                    return $entries;
                }
            }
        }

        return array_map(fn (string $message): ?PredbFeedEntry => $this->parsePhp($message, $receivedAt), $messages);
    }

    /**
     * @param  list<string>  $messages
     * @return list<PredbFeedEntry|null>|null Null means transport unavailable/unsupported.
     */
    private function parseBatchNative(array $messages, ?CarbonImmutable $receivedAt = null): ?array
    {
        if ($messages === []) {
            return [];
        }
        $joined = implode("\0", $messages)."\0";
        if (substr_count($joined, "\0") !== count($messages)) {
            return null;
        }
        $wire = $this->nativeWire($joined, true);
        if ($wire === null) {
            return null;
        }
        // Remove only the final transport delimiter, retaining empty result slots.
        $records = explode("\n", substr($wire, 0, -1));
        if (count($records) !== count($messages)) {
            return null;
        }
        $entries = [];
        foreach ($records as $i => $record) {
            $entries[] = $record === '?' ? $this->parsePhp($messages[$i], $receivedAt)
                : ($record === '' ? null : $this->nativeEntry($record, $receivedAt));
        }

        return $entries;
    }

    private function parseNative(string $message, ?CarbonImmutable $receivedAt = null): PredbFeedEntry|false|null
    {
        $wire = $this->nativeWire($message, false);
        if ($wire === null) {
            return false;
        }

        return $wire === '' ? null : $this->nativeEntry($wire, $receivedAt);
    }

    private function nativeWire(string $message, bool $batch): ?string
    {
        if (self::$native === null) {
            self::$native = RustPipelineLibrary::resolveDomain('irc_scene_announce', self::NATIVE_CDEF) ?? false;
        }
        $ffi = self::$native;
        if ($ffi === false) {
            return null;
        }
        // Six fields plus delimiters fit within twice the input plus a small header.
        $needed = strlen($message) * 2 + 64;
        if (self::$nativeBuffer === null || self::$nativeCapacity < $needed) {
            self::$nativeCapacity = max(4096, $needed);
            self::$nativeBuffer = $ffi->new('char['.self::$nativeCapacity.']');
        }
        if ($batch) {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_pre_announce_parse_batch_nul($message, strlen($message), $this->nativeFormat, self::$nativeBuffer, self::$nativeCapacity);
        } else {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_pre_announce_parse($message, strlen($message), $this->nativeFormat, self::$nativeBuffer, self::$nativeCapacity);
        }

        return is_int($written) && $written >= 0 ? ($written === 0 ? '' : FFI::string(self::$nativeBuffer, $written)) : null;
    }

    private function nativeEntry(string $wire, ?CarbonImmutable $receivedAt): PredbFeedEntry
    {
        [$type, $title, $section, $reason, $files, $size] = explode("\0", $wire);
        $info = $type === 'INFO';
        $pre = $type === 'PRE' || $type === 'P2P';
        $relay = $this->format === 'opentrackers';
        $nuked = self::TYPES[$type];

        return new PredbFeedEntry(
            title: $title,
            source: $relay ? 'opentrackers-relay' : $this->source,
            category: $section !== '' ? $section : null,
            size: $info ? PredbFeedEntry::sizeFromMegabytes((float) $size) : null,
            files: $info ? PredbFeedEntry::filesFromCount((int) $files) : null,
            predate: $pre && ! $relay ? ($receivedAt ?? CarbonImmutable::now('UTC')) : null,
            nuked: $nuked,
            nukeReason: $nuked === Predb::PRE_NONUKE || $reason === '' ? null : $reason,
            listedAt: $pre && $relay ? ($receivedAt ?? CarbonImmutable::now('UTC')) : null,
            enrichOnly: $info,
        );
    }

    /**
     * The public relay carries several existing grammars. Its receipt is an observation, not PRE time.
     */
    private function parseRelay(string $message, ?CarbonImmutable $receivedAt): ?PredbFeedEntry
    {
        if (str_contains($message, "\r") || str_contains($message, "\n")) {
            return null;
        }

        $message = trim($message);
        $matches = [];
        foreach (self::RELAY_FORMATS as $format) {
            foreach (self::PATTERNS[$format] as $pattern) {
                if (preg_match($pattern, $message, $groups) !== 1) {
                    continue;
                }
                if (strtoupper($groups['type']) === 'PRE' && ! isset($groups['section'])) {
                    continue;
                }
                $entry = $this->entry($groups, $receivedAt);
                if ($entry !== null) {
                    $matches[] = $entry;
                }
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $entry = $matches[0];

        return new PredbFeedEntry(
            title: $entry->title,
            source: 'opentrackers-relay',
            category: $entry->category,
            size: $entry->size,
            files: $entry->files,
            predate: null,
            nuked: $entry->nuked,
            nukeReason: $entry->nukeReason,
            listedAt: $entry->predate !== null ? ($receivedAt ?? CarbonImmutable::now('UTC')) : null,
            enrichOnly: $entry->enrichOnly,
        );
    }

    /**
     * @param  array<int|string, string>  $m
     */
    private function entry(array $m, ?CarbonImmutable $receivedAt): ?PredbFeedEntry
    {
        $type = strtoupper($m['type']);
        $title = $m['title'];

        if (! isset(self::TYPES[$type]) || ! self::isReleaseName($title)) {
            return null;
        }

        $nuked = self::TYPES[$type];
        $reason = trim($m['reason'] ?? '');
        $section = trim($m['section'] ?? '');
        $info = $type === 'INFO';

        if ($type === 'PRE' && $section === '') {
            return null;
        }

        // An INFO line only counts when it carries both the file count and the size.
        if ($info && (($m['files'] ?? '') === '' || ($m['size'] ?? '') === '')) {
            return null;
        }

        return new PredbFeedEntry(
            title: $title,
            source: $this->source,
            category: $section !== '' ? $section : null,
            size: $info ? PredbFeedEntry::sizeFromMegabytes((float) $m['size']) : null,
            files: $info ? PredbFeedEntry::filesFromCount((int) $m['files']) : null,
            // Only a PRE line carries the release time; INFO and nukes can come much later.
            predate: in_array($type, ['PRE', 'P2P'], true) ? ($receivedAt ?? CarbonImmutable::now('UTC')) : null,
            nuked: $nuked,
            nukeReason: $nuked === Predb::PRE_NONUKE || $reason === '' ? null : $reason,
            enrichOnly: $info,
        );
    }

    /**
     * Scene and P2P release names are a single token with a group suffix.
     */
    private static function isReleaseName(string $title): bool
    {
        return strlen($title) <= 255 && preg_match('/^[\w.()&\'+,-]+-[\w]+\z/u', $title) === 1;
    }
}
