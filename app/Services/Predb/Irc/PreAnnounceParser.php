<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\IrcChannelList;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

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

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all five runs (min 2.97x; see the W45 parity test). */
    public const bool W451_RUST_FFI_DEFAULT = true;

    public const string W451_FFI_CDEF = <<<'C'
        ssize_t nntmux_w451_pre_announce_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W451_SUMMARY_KEYS = ['total_messages', 'empty_message_count', 'entry_count', 'pre_count', 'info_count', 'nuke_count', 'section_count', 'reason_count'];

    private static ?bool $w451UseRustFfi = null;

    private static FFI|false|null $w451Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW451RustFfi(?bool $enable = true): void
    {
        self::$w451UseRustFfi = $enable;
    }

    public static function usesW451RustFfi(): bool
    {
        return self::$w451UseRustFfi ?? self::W451_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW451RustFfi(): void
    {
        self::$w451UseRustFfi = null;
        self::$w451Ffi = null;
    }

    /**
     * Summarizes messages in this parser's format with parsePhp(): entries, PRE/P2P lines (timed at
     * receipt or, on the relay, listed), INFO lines, nuke-family lines, entries with a section and
     * nukes with a reason.
     * Entries come from strings, integers, or the first non-null message, line array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public function summarizeAnnounceBatch(array $rows): array
    {
        return self::usesW451RustFfi() ? $this->summarizeAnnounceBatchNative($rows) : $this->summarizeAnnounceBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable, or Rust declines a
     * NUL byte or non-ASCII title.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public function summarizeAnnounceBatchNative(array $rows): array
    {
        $values = IrcChannelList::w451Invoke(self::$w451Ffi, self::W451_FFI_CDEF, 'nntmux_w451_pre_announce_batch', $rows, ['message', 'line'], \count(self::W451_SUMMARY_KEYS), pack('V', $this->nativeFormat));

        return $values === null ? $this->summarizeAnnounceBatchPhp($rows) : array_combine(self::W451_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public function summarizeAnnounceBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W451_SUMMARY_KEYS, 0);
        // A fixed receipt time keeps the clock out of the summary.
        $receivedAt = CarbonImmutable::createFromTimestampUTC(0);
        foreach (IrcChannelList::w451Entries($rows, ['message', 'line']) as $message) {
            $summary['total_messages']++;
            if ($message === '') {
                $summary['empty_message_count']++;

                continue;
            }
            $entry = $this->parsePhp($message, $receivedAt);
            if ($entry === null) {
                continue;
            }
            $summary['entry_count']++;
            $summary['pre_count'] += (int) ($entry->pagingTime() !== null);
            $summary['info_count'] += (int) $entry->enrichOnly;
            $summary['nuke_count'] += (int) ($entry->nuked !== Predb::PRE_NONUKE);
            $summary['section_count'] += (int) ($entry->category !== null);
            $summary['reason_count'] += (int) ($entry->nukeReason !== null);
        }

        return $summary;
    }

    // ========================================================================
    // WAVE 61.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.79x, p99 5.15x; uncommon mean 6.60x, p99 4.68x); see RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    public const string W61_PRE_ANNOUNCE_PARSER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w613_pre_announce_parser_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 61.3 batch. */
    public const int W61_PRE_ANNOUNCE_PARSER_TRAILER = 29;

    /** Announce keywords (any case) to their w61AnnounceParseBatch() type code. */
    public const array W61_TYPES = ['PRE' => 1, 'P2P' => 2, 'INFO' => 3, 'NUKE' => 4, 'UNNUKE' => 5, 'MODNUKE' => 6, 'RENUKE' => 7, 'OLDNUKE' => 8];

    /** mIRC colour (with optional foreground and background digits) and formatting codes. */
    private const string W61_CODES = '/\x03(?:[0-9]{1,2}(?:,[0-9]{1,2})?)?|[\x02\x0F\x11\x16\x1D\x1E\x1F]/';

    private const string W61_ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const string W61_SCENE_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.()&'+,-";

    private const string W61_WORD_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    public static function usesW61RustFfi(): bool
    {
        $override = self::w61RustFfiState();

        return \is_bool($override) ? $override : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PreAnnounceParser Wave 61 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 61.3 pre-announce parse plans. Each item is one raw IRC line: mIRC colour and formatting codes are
     * stripped (W61_CODES), the line trimmed of blanks and parsed by w61ParseLine(). Per item `format | type << 3 |
     * coded << 7 | min(strippedBytes, 255) << 8 | titled << 16 | min(sectionLength, 63) << 17 | hasFiles << 23 |
     * min(files, 65535) << 24 | hasSize << 40 | min(sizeMb, 0xFFFF) << 41 | hasReason << 57 | entry << 58 |
     * unterminated << 59`: the format is 0 none (also any line with CR or LF), 1 `TYPE:` (corruptnet), 2 `(TYPE)`
     * (zenet), 3 `TYPE |` (predatabase), 4 `.::: TYPE ::: SECTION ::: TITLE` (ngp) or 5 `[TYPE]` (rizon); the type
     * is the W61_TYPES code of the keyword (0 other); an entry has a known type and a release-name title, a PRE also
     * a section and an INFO both a file count and a size. Then the format and type counts, entries, stripped bytes,
     * coded lines, titled lines, sectioned lines, the file and size (hundredths of a MB) sums, reasons, nuke-family,
     * INFO and PRE/P2P entries, the distinct entry titles (case-insensitive), the longest stripped line and the
     * unterminated bracket count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61AnnounceParseBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::w61AnnounceParseBatchNative($items) : self::w61AnnounceParseBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61AnnounceParseBatchNative(array $items): array
    {
        return self::w61Invoke($items, \count($items) + self::W61_PRE_ANNOUNCE_PARSER_TRAILER) ?? self::w61AnnounceParseBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61AnnounceParseBatchPhp(array $items): array
    {
        $out = [];
        $formats = [0, 0, 0, 0, 0, 0];
        $types = array_fill(0, 9, 0);
        $entries = $strippedSum = $coded = $titled = $sectioned = $filesSum = $sizeSum = $reasons = $nukes = $infos = $pres = $maxLength = $unterminatedSum = 0;
        $titles = [];
        foreach ($items as $item) {
            $raw = (string) $item;
            $line = preg_replace(self::W61_CODES, '', $raw) ?? $raw;
            $stripped = \strlen($raw) - \strlen($line);
            $line = trim($line, " \t");
            [$format, $type, $title, $section, $files, $size, $reason, $unterminated] = self::w61ParseLine($line);
            $titleOk = $title !== '' ? 1 : 0;
            $entry = $type !== 0 && $titleOk === 1 && ($type !== 1 || $section > 0) && ($type !== 3 || ($files >= 0 && $size >= 0)) ? 1 : 0;
            $formats[$format]++;
            $types[$type]++;
            $entries += $entry;
            $strippedSum += $stripped;
            $coded += $stripped > 0 ? 1 : 0;
            $titled += $titleOk;
            $sectioned += $section > 0 ? 1 : 0;
            $filesSum += max($files, 0);
            $sizeSum += max($size, 0);
            $reasons += $reason > 0 ? 1 : 0;
            if ($entry === 1) {
                if ($type <= 2) {
                    $pres++;
                } elseif ($type === 3) {
                    $infos++;
                } else {
                    $nukes++;
                }
                $titles[strtolower($title)] = true;
            }
            $maxLength = max($maxLength, \strlen($line));
            $unterminatedSum += $unterminated;
            $out[] = $format | $type << 3 | ($stripped > 0 ? 1 : 0) << 7 | min($stripped, 255) << 8 | $titleOk << 16 | min($section, 63) << 17
                | ($files >= 0 ? 1 : 0) << 23 | min(max($files, 0), 65535) << 24 | ($size >= 0 ? 1 : 0) << 40
                | min(intdiv(max($size, 0), 100), 0xFFFF) << 41 | ($reason > 0 ? 1 : 0) << 57 | $entry << 58 | $unterminated << 59;
        }
        array_push($out, ...$formats, ...$types);
        array_push($out, $entries, $strippedSum, $coded, $titled, $sectioned, $filesSum, $sizeSum, $reasons, $nukes, $infos, $pres, \count($titles),
            $maxLength, $unterminatedSum);

        return $out;
    }

    /**
     * Parse one stripped, blank-trimmed line: [format, type, title ('' unless a release name), section length, files
     * (-1 absent), size in hundredths of a MB (-1 absent), reason length, unterminated bracket]. After the keyword,
     * segments (at most 16) are `[...]` or `(...)` groups (blank-trimmed) or bare tokens up to a blank or `|`
     * (trailing `,` and `!` removed); a PRE takes its section from the first segment and every type its title from
     * the first later release-name segment. INFO counts come from w61InfoCounts() after the title; a nuke reason is
     * the segment after the title.
     *
     * @return array{int, int, string, int, int, int, int, int}
     */
    private static function w61ParseLine(string $s): array
    {
        $none = [0, 0, '', 0, -1, -1, 0, 0];
        $length = \strlen($s);
        if ($length === 0 || strpbrk($s, "\r\n") !== false) {
            return $none;
        }
        if (str_starts_with($s, '.:::')) {
            $parts = explode(':::', substr($s, 4), 4);
            $title = trim($parts[2] ?? '', " \t");

            return [4, self::W61_TYPES[strtoupper(trim($parts[0], " \t"))] ?? 0, self::w61IsReleaseName($title) ? $title : '',
                \strlen(trim($parts[1] ?? '', " \t")), -1, -1, 0, 0];
        }
        $open = $s[0];
        if ($open === '(' || $open === '[') {
            $k = strspn($s, self::W61_ALNUM, 1);
            if ($k === 0 || $k + 1 >= $length || $s[$k + 1] !== ($open === '(' ? ')' : ']')) {
                return $none;
            }
            $format = $open === '(' ? 2 : 5;
            $keyword = substr($s, 1, $k);
            $offset = $k + 2;
        } else {
            $k = strspn($s, self::W61_ALNUM);
            if ($k === 0 || $k >= $length) {
                return $none;
            }
            if ($s[$k] === ':') {
                $format = 1;
                $offset = $k + 1;
            } else {
                $j = $k + strspn($s, " \t", $k);
                if ($j >= $length || $s[$j] !== '|') {
                    return $none;
                }
                $format = 3;
                $offset = $j + 1;
            }
            $keyword = substr($s, 0, $k);
        }
        $type = self::W61_TYPES[strtoupper($keyword)] ?? 0;
        $segments = $ends = [];
        $unterminated = 0;
        $i = $offset;
        while (\count($segments) < 16) {
            $i += strspn($s, " \t|", $i);
            if ($i >= $length) {
                break;
            }
            $c = $s[$i];
            if ($c === '[' || $c === '(') {
                $close = strpos($s, $c === '[' ? ']' : ')', $i + 1);
                if ($close === false) {
                    $segments[] = trim(substr($s, $i + 1), " \t");
                    $unterminated = 1;
                    $i = $length;
                } else {
                    $segments[] = trim(substr($s, $i + 1, $close - $i - 1), " \t");
                    $i = $close + 1;
                }
            } else {
                $j = $i + strcspn($s, " \t|", $i);
                $segments[] = rtrim(substr($s, $i, $j - $i), ',!');
                $i = $j;
            }
            $ends[] = $i;
        }
        $count = \count($segments);
        $section = $type === 1 && $count > 0 ? \strlen($segments[0]) : 0;
        $titleIndex = -1;
        for ($n = $type === 1 ? 1 : 0; $n < $count; $n++) {
            if (self::w61IsReleaseName($segments[$n])) {
                $titleIndex = $n;

                break;
            }
        }
        if ($titleIndex < 0) {
            return [$format, $type, '', $section, -1, -1, 0, $unterminated];
        }
        [$files, $size] = $type === 3 ? self::w61InfoCounts(substr($s, $ends[$titleIndex])) : [-1, -1];
        $reason = $type >= 4 && $titleIndex + 1 < $count ? \strlen($segments[$titleIndex + 1]) : 0;

        return [$format, $type, $segments[$titleIndex], $section, $files, $size, $reason, $unterminated];
    }

    /**
     * First file count (a number followed by `F`, `File` or `Files`) and first size (a number with an optional
     * fraction followed by `MB`, in hundredths), each -1 when absent. Numbers start after a byte that is neither
     * alphanumeric nor `.`, hold at most 9 integer digits and may be followed by spaces; suffixes match in any
     * case and must end at a non-alphanumeric byte.
     *
     * @return array{int, int}
     */
    private static function w61InfoCounts(string $x): array
    {
        $m = \strlen($x);
        $files = $size = -1;
        $i = 0;
        while ($i < $m) {
            $o = \ord($x[$i]);
            if ($o < 48 || $o > 57 || ($i > 0 && ($x[$i - 1] === '.' || strspn($x, self::W61_ALNUM, $i - 1, 1) === 1))) {
                $i++;

                continue;
            }
            $j = $i + strspn($x, '0123456789', $i);
            $int = substr($x, $i, $j - $i);
            $frac = '';
            if ($j + 1 < $m && $x[$j] === '.' && strspn($x, '0123456789', $j + 1, 1) === 1) {
                $k = $j + 1 + strspn($x, '0123456789', $j + 1);
                $frac = substr($x, $j + 1, $k - $j - 1);
                $j = $k;
            }
            if (\strlen($int) <= 9) {
                $at = $j + strspn($x, ' ', $j);
                if ($files < 0 && $frac === '' && (self::w61Suffix($x, $at, 'files') || self::w61Suffix($x, $at, 'file') || self::w61Suffix($x, $at, 'f'))) {
                    $files = (int) $int;
                } elseif ($size < 0 && self::w61Suffix($x, $at, 'mb')) {
                    $size = (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
                }
            }
            $i = $j;
        }

        return [$files, $size];
    }

    /** Whether lowercase $word starts at $at in $x, in any case, followed by the end or a non-alphanumeric byte. */
    private static function w61Suffix(string $x, int $at, string $word): bool
    {
        $end = $at + \strlen($word);

        return strcasecmp(substr($x, $at, \strlen($word)), $word) === 0 && ($end === \strlen($x) || strspn($x, self::W61_ALNUM, $end, 1) === 0);
    }

    /** ASCII scene release name: up to 255 W61_SCENE_CHARS ending in `-` and word bytes, with a byte before that `-`. */
    private static function w61IsReleaseName(string $title): bool
    {
        $length = \strlen($title);
        if ($length > 255 || strspn($title, self::W61_SCENE_CHARS) !== $length) {
            return false;
        }
        $dash = strrpos($title, '-');

        return $dash !== false && $dash >= 1 && $dash < $length - 1 && strspn($title, self::W61_WORD_CHARS, $dash + 1) === $length - $dash - 1;
    }

    /**
     * Wave 61 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('predb_w613', self::W61_PRE_ANNOUNCE_PARSER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w61RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w61RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 61.3 PreAnnounceParser export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w61Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w61RustFfiState('ffi');
            $out = self::w61RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w61RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W61_PRE_ANNOUNCE_PARSER_FFI_CDEF.)
            $written = $ffi->nntmux_w613_pre_announce_parser_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
