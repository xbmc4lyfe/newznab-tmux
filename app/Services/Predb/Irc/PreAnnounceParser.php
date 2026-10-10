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
 * These channels only announce a section and a release name, so the PRE time is the time the
 * line was received. Messages must already have IRC colour and formatting codes stripped.
 *
 * Formats:
 *  - corruptnet  (irc.corrupt-net.org #pre):  `PRE: [FLAC] Release.Name-GRP`
 *  - zenet       (irc.zenet.org #pre):        `(PRE) (MP3-WEB) (Release.Name-GRP)`
 *  - predatabase (irc.predataba.se #pre):     `pre | MP3-WEB | Release.Name-GRP`
 *
 * The PRE formats were checked against live traffic. The corruptnet and zenet nuke patterns follow
 * the same layouts but have not been observed yet; lines that match neither are ignored.
 */
final class PreAnnounceParser
{
    public const FORMATS = ['corruptnet', 'zenet', 'predatabase'];

    /**
     * Announce type keyword => predb nuke status.
     *
     * @var array<string, int>
     */
    private const TYPES = [
        'PRE' => Predb::PRE_NONUKE,
        'NUKE' => Predb::PRE_NUKED,
        'UNNUKE' => Predb::PRE_UNNUKED,
        'MODNUKE' => Predb::PRE_MODNUKE,
        'RENUKE' => Predb::PRE_RENUKED,
        'OLDNUKE' => Predb::PRE_OLDNUKE,
    ];

    public function __construct(
        private readonly string $format,
        private readonly string $source,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown IRC pre announce format [{$format}]. Available: ".implode(', ', self::FORMATS));
        }
    }

    public function parse(string $message, ?CarbonImmutable $receivedAt = null): ?PredbFeedEntry
    {
        $message = trim($message);
        $parsed = match ($this->format) {
            'zenet' => $this->zenet($message),
            'predatabase' => $this->predatabase($message),
            default => $this->corruptNet($message),
        };

        if ($parsed === null) {
            return null;
        }

        [$type, $section, $title, $reason] = $parsed;
        $type = strtoupper($type);
        if (! isset(self::TYPES[$type]) || ! self::isReleaseName($title)) {
            return null;
        }

        $nuked = self::TYPES[$type];

        return new PredbFeedEntry(
            title: $title,
            source: $this->source,
            category: $section !== null && $section !== '' ? $section : null,
            // Only a PRE line carries the release time; a nuke can come years later.
            predate: $nuked === Predb::PRE_NONUKE ? ($receivedAt ?? CarbonImmutable::now('UTC')) : null,
            nuked: $nuked,
            nukeReason: $nuked === Predb::PRE_NONUKE || $reason === null || $reason === '' ? null : $reason,
        );
    }

    /**
     * @return array{0: string, 1: ?string, 2: string, 3: ?string}|null
     */
    private function corruptNet(string $message): ?array
    {
        // PRE: [SECTION] Release.Name-GRP
        if (preg_match('/^(?<type>[A-Z]+):\s*\[(?<section>[^\]]+)\]\s+(?<title>\S+)\s*$/', $message, $m)) {
            return [$m['type'], trim($m['section']), $m['title'], null];
        }

        // NUKE: Release.Name-GRP [reason] (optionally followed by [NUKENET] and other bracketed fields)
        if (preg_match('/^(?<type>[A-Z]+):\s*(?<title>\S+)\s+\[(?<reason>[^\]]*)\]/', $message, $m)) {
            return [$m['type'], null, $m['title'], trim($m['reason'])];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: string, 3: ?string}|null
     */
    private function zenet(string $message): ?array
    {
        // (PRE) (SECTION) (Release.Name-GRP)
        if (preg_match('/^\((?<type>PRE)\)\s*\((?<section>[^)]+)\)\s*\((?<title>\S+)\)$/i', $message, $m)) {
            return [$m['type'], trim($m['section']), $m['title'], null];
        }

        // (NUKE) (Release.Name-GRP) (reason) [optionally more fields]
        if (preg_match('/^\((?<type>(?!PRE\))[A-Z]+)\)\s*\((?<title>\S+?)\)\s*\((?<reason>[^)]*)\)/i', $message, $m)) {
            return [$m['type'], null, $m['title'], trim($m['reason'])];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: string, 3: ?string}|null
     */
    private function predatabase(string $message): ?array
    {
        // pre | SECTION | Release.Name-GRP
        if (preg_match('/^(?<type>pre)\s*\|\s*(?<section>[^|]+?)\s*\|\s*(?<title>\S+)$/i', $message, $m)) {
            return [$m['type'], $m['section'], $m['title'], null];
        }

        return null;
    }

    /**
     * Scene release names are a single token with a group suffix.
     */
    private static function isReleaseName(string $title): bool
    {
        return strlen($title) <= 255 && preg_match('/^[\w.()&\'+,-]+-[\w]+$/u', $title) === 1;
    }
}
