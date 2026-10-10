<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
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
 *  - rizon       (Rizon #pre):                    `[PRE]  [TV-HD]  Name-GRP`
 */
final class PreAnnounceParser
{
    public const FORMATS = ['corruptnet', 'zenet', 'predatabase', 'ngp', 'rizon'];

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
            '/^(?<type>PRE):\s*\[(?<section>[^\]]+)\]\s+(?<title>\S+)$/',
            '/^(?<type>INFO):\s*(?<title>\S+)\s+\[(?<files>\d+)F\s+(?<size>[\d.]+)MB\]/',
            '/^(?<type>[A-Z]+):\s*(?<title>\S+)\s+\[(?<reason>[^\]]*)\]/',
        ],
        'zenet' => [
            '/^\((?<type>PRE)\)\s*\((?<section>[^)]+)\)\s*\((?<title>\S+)\)$/i',
            '/^\((?<type>INFO)\)\s*\((?<title>\S+?)\)\s*\((?<files>\d+) Files?\)\s*\((?<size>[\d.]+) MB\)/i',
            '/^\((?<type>(?!PRE\)|INFO\)|GENRE\)|URL\)|MUSIC-INFO\))[A-Z]+)\)\s*\((?<title>\S+?)\)\s*\((?<reason>[^)]*)\)/i',
        ],
        'predatabase' => [
            '/^(?<type>pre)\s*\|\s*(?<section>[^|]+?)\s*\|\s*(?<title>\S+)$/i',
            '/^(?<type>info)\s*\|\s*(?<title>\S+)\s+-\s+(?<files>\d+)\s+F\s+&\s+(?<size>[\d.]+)\s+MB$/i',
            // Only the first site to get a P2P release announces "#1 ... WIN!"; later lines repeat it.
            '/^(?<type>p2p)\s*\|\s*#1\s*\|\s*\S+\s*\|\s*(?<title>\S+?),\s*WIN!$/i',
        ],
        'ngp' => [
            '/^\.:::\s*(?<type>[A-Z]+)\s*:::\s*(?<section>\S+)\s*:::\s*(?<title>\S+)\s*:::\.$/',
            '/^\[(?<type>INFO)\]\s*FOR\s+(?<title>\S+)\s+with\s+(?<files>\d+)F\s+(?<size>[\d.]+)MB added\.?$/',
        ],
        'rizon' => [
            '/^\[(?<type>PRE)\]\s*\[(?<section>[^\]]+)\]\s*(?<title>\S+)$/',
            '/^\[(?<type>[A-Z]+)\]\s+(?<title>\S+)\s+\[(?<reason>[^\]]*)\]/',
        ],
    ];

    public function __construct(
        private readonly string $format,
        private readonly string $source,
    ) {
        if (! isset(self::PATTERNS[$format])) {
            throw new InvalidArgumentException("Unknown IRC pre announce format [{$format}]. Available: ".implode(', ', self::FORMATS));
        }
    }

    public function parse(string $message, ?CarbonImmutable $receivedAt = null): ?PredbFeedEntry
    {
        $message = trim($message);

        foreach (self::PATTERNS[$this->format] as $pattern) {
            if (preg_match($pattern, $message, $m) === 1) {
                return $this->entry($m, $receivedAt);
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, string>  $m
     */
    private function entry(array $m, ?CarbonImmutable $receivedAt): ?PredbFeedEntry
    {
        $type = strtoupper($m['type']);
        $title = rtrim($m['title'], ',');

        if (! isset(self::TYPES[$type]) || ! self::isReleaseName($title)) {
            return null;
        }

        $nuked = self::TYPES[$type];
        $reason = trim($m['reason'] ?? '');
        $section = trim($m['section'] ?? '');
        $info = $type === 'INFO';

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
        return strlen($title) <= 255 && preg_match('/^[\w.()&\'+,-]+-[\w]+$/u', $title) === 1;
    }
}
