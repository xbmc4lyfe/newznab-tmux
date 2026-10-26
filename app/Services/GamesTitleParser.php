<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * GamesTitleParser - Parse and clean game release titles.
 *
 * Extracts clean game titles from scene release names by removing
 * common scene group names, edition tags, version numbers, and other noise.
 */
class GamesTitleParser
{
    // Scene/release group patterns
    protected const array SCENE_GROUPS = [
        'CODEX', 'PLAZA', 'GOG', 'CPY', 'HOODLUM', 'EMPRESS', 'RUNE', 'TENOKE', 'FLT',
        'RELOADED', 'SKIDROW', 'PROPHET', 'RAZOR1911', 'CORE', 'REFLEX', 'P2P', 'GOLDBERG',
        'DARKSIDERS', 'TINYISO', 'DOGE', 'ANOMALY', 'ELAMIGOS', 'FITGIRL', 'DODI', 'XATAB',
        'GOG-GAMES', 'BLG', 'RARGB', 'CHRONOS', 'FCKDRM', 'I_KnoW', 'STEAM', 'PLAZA',
        'SPTGAMES', 'DARKSiDERS', 'TiNYiSO', 'KaOs', 'SiMPLEX', 'ElAmigos', 'FitGirl',
        'PROPHET', 'ALI213', 'FLTDOX', '3DMGAME', 'POSTMORTEM', 'VACE', 'ROGUE', 'OUTLAWS',
    ];

    // Legacy regex pattern for fallback
    protected const string LEGACY_TITLE_REGEX =
        '#(?P<title>[\w\s\.]+)(-(?P<relgrp>FLT|RELOADED|Elamigos|SKIDROW|PROPHET|RAZOR1911|CORE|REFLEX))?\s?(\s*(\?('.
        '(?P<reltype>PROPER|MULTI\d|RETAIL|CRACK(FIX)?|ISO|(RE)?(RIP|PACK))|(?P<year>(19|20)\d{2})|V\s?'.
        '(?P<version>(\d+\.)+\d+)|(-\s)?(?P=relgrp))\)?)\s?)*\s?(\.\w{2,4})?#i';

    /**
     * Parse a release title and extract the clean game title.
     *
     * @return array{title: string, release: string}|false
     */
    public function parse(string $releaseName): array|false
    {
        $name = $this->cleanReleaseName($releaseName);

        if ($name === '') {
            return $this->fallbackParse($releaseName);
        }

        return [
            'title' => $name,
            'release' => $releaseName,
        ];
    }

    /**
     * Clean release name by removing scene artifacts.
     */
    protected function cleanReleaseName(string $name): string
    {
        $fast = RustPipelineLibrary::gamesCleanReleaseName($name);
        if ($fast !== null) {
            return $fast;
        }

        return $this->cleanReleaseNamePhp($name);
    }

    public function cleanReleaseNamePhp(string $name): string
    {
        // Remove simple file extensions at the end
        $name = (string) preg_replace('/\.(zip|rar|7z|iso|nfo|sfv|exe|mkv|mp4|avi)$/i', '', $name);
        $name = str_replace('%20', ' ', $name);

        // Remove leading bracketed tag like [GROUP] or [PC]
        $name = (string) preg_replace('/^\[[^]]+\]\s*/', '', $name);

        // Remove "PC ISO) (" artifact
        $name = (string) preg_replace('/^(PC\s*ISO\)\s*\()/i', '', $name);

        // Remove common bracketed/parenthesized tags (languages, qualities, platforms, versions)
        $name = (string) preg_replace('/\[[^]]{1,80}\]|\([^)]{1,80}\)/', ' ', $name);

        // Remove edition and extra tags
        $name = (string) preg_replace(
            '/\b(Game\s+of\s+the\s+Year|GOTY|Definitive Edition|Deluxe Edition|Ultimate Edition|Complete Edition|Remastered|HD Remaster|Directors? Cut|Anniversary Edition)\b/i',
            ' ',
            $name
        );

        // Remove update/patch followed by version numbers
        $name = (string) preg_replace('/\b(Update|Patch|Hotfix)\b[\s._-]*v?\d+(?:\.\d+){1,}\b/i', ' ', $name);

        // Remove MULTI tokens
        $name = (string) preg_replace('/\bMULTI\d+|MULTi\d+\b/i', ' ', $name);

        // Remove version tokens
        $name = (string) preg_replace('/(?:^|[\s._-])v\d+(?:[\s._-]\d+){1,}(?=$|[\s._-])/i', ' ', $name);
        $name = (string) preg_replace('/(?:^|[\s._-])\d+(?:[\s._-]\d+){2,}(?=$|[\s._-])/', ' ', $name);

        // Remove other common release tags
        $name = (string) preg_replace('/\b(Incl(?:uding)?\s+DLCs?|DLCs?|PROPER|REPACK|RIP|ISO|CRACK(?:FIX)?|BETA|ALPHA)\b/i', ' ', $name);
        $name = (string) preg_replace('/\b(Update|Patch|Hotfix)\b/i', ' ', $name);

        // Remove scene group suffix
        $groupAlternation = implode('|', array_map('preg_quote', self::SCENE_GROUPS));
        $name = (string) preg_replace('/\s*-\s*(?:'.$groupAlternation.'|[A-Z0-9]{2,})\s*$/i', '', $name);

        // Replace separators with spaces
        $name = (string) preg_replace('/[._+]+/', ' ', $name);

        // Second pass: remove edition tokens now that separators are normalized
        $name = (string) preg_replace(
            '/\b(Game\s+of\s+the\s+Year|GOTY|Definitive Edition|Deluxe Edition|Ultimate Edition|Complete Edition|Remastered|HD Remaster|Directors? Cut|Anniversary Edition)\b/i',
            ' ',
            $name
        );
        $name = (string) preg_replace('/\b(Incl|Including|DLC|DLCs)\b/i', ' ', $name);

        // Token-based cleanup for version sequences
        $name = $this->cleanVersionTokens($name);

        // Remove spaced-out version sequences
        $name = (string) preg_replace('/(?:^|\s)v\d+(?:\s+\d+){1,}(?=$|\s)/i', ' ', $name);
        $name = (string) preg_replace('/(?:^|\s)\d+(?:\s+\d+){2,}(?=$|\s)/', ' ', $name);

        // Collapse multiple spaces and trim
        $name = (string) preg_replace('/\s{2,}/', ' ', $name);
        $name = trim($name, " \t\n\r\0\x0B-_");

        // Special fix from previous implementation
        $name = str_replace(' RF ', ' ', $name);

        // Final aggressive cleanup
        for ($i = 0; $i < 2; $i++) {
            $name = (string) preg_replace('/(?:^|\s)v\d+(?:\s+\d+){1,}(?=$|\s)/i', ' ', $name);
            $name = (string) preg_replace('/(?:^|\s)\d+(?:\s+\d+){2,}(?=$|\s)/', ' ', $name);
            $name = (string) preg_replace('/\b[vV]\d+(?:[ ._-]\d+){1,}\b/', ' ', $name);
            $name = (string) preg_replace('/\b\d+(?:[ ._-]\d+){2,}\b/', ' ', $name);
            $name = (string) preg_replace('/\s{2,}/', ' ', $name);
            $name = trim($name, " \t\n\r\0\x0B-_");
        }

        return $name;
    }

    /**
     * Clean version token sequences from name.
     */
    protected function cleanVersionTokens(string $name): string
    {
        return $this->cleanVersionTokensPhp($name);
    }

    public function cleanVersionTokensPhp(string $name): string
    {
        $tokens = preg_split('/\s+/', trim($name)) ?: [];
        $filtered = [];
        $i = 0;
        $tcount = count($tokens);

        while ($i < $tcount) {
            $tok = $tokens[$i];
            if (preg_match('/^v?\d+$/i', $tok)) {
                $j = $i + 1;
                $numRun = 0;
                while ($j < $tcount && preg_match('/^\d+$/', $tokens[$j])) {
                    $numRun++;
                    $j++;
                }
                $startsWithV = preg_match('/^v\d+$/i', $tok) === 1;
                if (($startsWithV && $numRun >= 1) || (! $startsWithV && $numRun >= 2)) {
                    // Skip this version run
                    $i = $j;

                    continue;
                }
                // Not a version run: keep the single number token
                $filtered[] = $tok;
                $i++;

                continue;
            }
            $filtered[] = $tok;
            $i++;
        }

        return implode(' ', $filtered);
    }

    /**
     * Fallback to legacy regex parsing.
     *
     * @return array{title: string, release: string}|false
     */
    protected function fallbackParse(string $releaseName): array|false
    {
        return $this->fallbackParsePhp($releaseName);
    }

    /** @return array{title: string, release: string}|false */
    public function fallbackParsePhp(string $releaseName): array|false
    {
        $cleanedName = preg_replace('/\sMulti\d?\s/i', '', $releaseName);
        if (preg_match(self::LEGACY_TITLE_REGEX, $cleanedName, $hits)) {
            $result = [];
            $result['title'] = str_replace(' RF ', ' ', preg_replace('/(?:[-:._]|%20|[\[\]])/', ' ', $hits['title']));
            $result['title'] = preg_replace('/(brazilian|chinese|croatian|danish|deutsch|dutch|english|estonian|flemish|finnish|french|german|greek|hebrew|icelandic|italian|latin|nordic|norwegian|polish|portuguese|japenese|japanese|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish)$/i', '', $result['title']);
            $result['title'] = preg_replace('/^(PC\sISO\)\s\()/i', '', $result['title']);
            $result['title'] = trim(preg_replace('/\s{2,}/', ' ', $result['title']));

            if (empty($result['title'])) {
                return false;
            }

            $result['release'] = $releaseName;

            return array_map('trim', $result);
        }

        return false;
    }

    /**
     * Normalize a title for comparison/matching.
     */
    public function normalizeForMatch(string $title): string
    {
        $fast = RustPipelineLibrary::gamesNormalizeForMatch($title);
        if ($fast !== null) {
            return $fast;
        }

        return $this->normalizeForMatchPhp($title);
    }

    public function normalizeForMatchPhp(string $title): string
    {
        $t = mb_strtolower($title);

        // Strip scene groups at end
        $groupAlternation = implode('|', array_map('preg_quote', self::SCENE_GROUPS));
        $t = (string) preg_replace('/\s*-\s*(?:'.$groupAlternation.'|[A-Z0-9]{2,})\s*$/i', '', $t);

        // Remove edition tokens and common noise
        $t = (string) preg_replace('/\b(game of the year|goty|definitive edition|deluxe edition|ultimate edition|complete edition|remastered|hd remaster|directors? cut|anniversary edition|update|patch|hotfix|incl(?:uding)? dlcs?|dlcs?|repack|rip|iso|crack(?:fix)?|beta|alpha)\b/i', ' ', $t);

        // Remove languages/platform tokens
        $t = (string) preg_replace('/\b(pc|gog|steam|x64|x86|win64|win32|mult[iy]?\d*|eng|english|fr|french|de|german|es|spanish|it|italian|pt|ptbr|portuguese|ru|russian|pl|polish|tr|turkish|nl|dutch|se|swedish|no|norwegian|da|danish|fi|finnish|jp|japanese|cn|chs|cht|ko|korean)\b/i', ' ', $t);

        // Remove punctuation
        $t = (string) preg_replace('/[^a-z0-9]+/i', ' ', $t);
        $t = trim(preg_replace('/\s{2,}/', ' ', $t));

        return $t;
    }

    /**
     * Compute similarity between two strings.
     */
    public function computeSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }

        return self::computeSimilarityNative($a, $b) ?? $this->computeSimilarityPhp($a, $b);
    }

    public function computeSimilarityPhp(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }

        $percent = 0.0;
        similar_text($a, $b, $percent);

        // Levenshtein-based score
        $levScore = 0.0;
        $len = max(strlen($a), strlen($b));
        if ($len > 0) {
            $dist = levenshtein($a, $b);
            if ($dist >= 0) {
                $levScore = (1 - ($dist / $len)) * 100.0;
            }
        }

        return max($percent, $levScore);
    }

    private static ?FFI $titleMatchingFfi = null;

    private static bool $titleMatchingResolved = false;

    private static function titleMatchingFfi(): ?FFI
    {
        if (! self::$titleMatchingResolved) {
            self::$titleMatchingResolved = true;
            self::$titleMatchingFfi = RustPipelineLibrary::resolveDomain('games_title_matching', <<<'C'
        ssize_t nntmux_games_clean_version_tokens(const char *, size_t, char *, size_t);
        ssize_t nntmux_games_fallback_parse(const char *, size_t, char *, size_t);
        double nntmux_games_compute_similarity(const char *, size_t, const char *, size_t);
        C);
        }

        return self::$titleMatchingFfi;
    }

    /** Null means unavailable/declined; negative byte counts request an exact-size retry. */
    private static function titleMatchingStringNative(string $symbol, string $input): ?string
    {
        $ffi = self::$titleMatchingFfi ?? self::titleMatchingFfi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) + 32), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            if (! is_int($written) || $written === PHP_INT_MIN) {
                return null;
            }
            if ($written < 0) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public static function cleanVersionTokensNative(string $name): ?string
    {
        return self::titleMatchingStringNative('nntmux_games_clean_version_tokens', $name);
    }

    /** @return array{title: string, release: string}|false|null */
    public static function fallbackParseNative(string $releaseName): array|false|null
    {
        $title = self::titleMatchingStringNative('nntmux_games_fallback_parse', $releaseName);
        if ($title === null) {
            return null;
        }

        return $title === '' ? false : ['title' => $title, 'release' => trim($releaseName)];
    }

    public static function computeSimilarityNative(string $a, string $b): ?float
    {
        $ffi = self::$titleMatchingFfi ?? self::titleMatchingFfi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $score = $ffi->nntmux_games_compute_similarity($a, strlen($a), $b, strlen($b));

            return is_float($score) && $score >= 0.0 && $score <= 100.0 ? $score : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Enabled: five paired runs cleared 1.5x on every gate (min common mean 4.06x, p99 2.94x; uncommon mean 5.17x, p99 4.30x; see the W43 parity test). */
    public const bool W434_RUST_FFI_DEFAULT = true;

    public const string W434_FFI_CDEF = <<<'C'
        ssize_t nntmux_w434_game_release_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W434_SUMMARY_KEYS = ['total_releases', 'empty_title_count', 'scene_group_count', 'edition_tag_count', 'version_tag_count', 'update_patch_count', 'release_tag_count', 'multi_language_count', 'dlc_count', 'bracket_tag_count'];

    private static ?bool $w434UseRustFfi = null;

    private static FFI|false|null $w434Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW434RustFfi(?bool $enable = true): void
    {
        self::$w434UseRustFfi = $enable;
    }

    public static function usesW434RustFfi(): bool
    {
        return self::$w434UseRustFfi ?? self::W434_RUST_FFI_DEFAULT;
    }

    public static function resetW434RustFfi(): void
    {
        self::$w434UseRustFfi = null;
        self::$w434Ffi = null;
    }

    /**
     * Summarize game release names: a trailing "-GROUP" naming an alphanumeric
     * SCENE_GROUPS member, and ASCII alphanumeric tokens for editions, v\d+
     * versions, update/patch/hotfix, repack/rip/iso/crack/proper, MULTI\d+ and
     * DLC (all case-insensitive), plus bracket or parenthesis tags. Entries come
     * from strings, integers, or the searchname, name or title array field;
     * other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGameReleaseBatch(array $entries): array
    {
        return self::usesW434RustFfi()
            ? self::summarizeGameReleaseBatchNative($entries)
            : self::summarizeGameReleaseBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGameReleaseBatchNative(array $entries): array
    {
        return self::w434Call(self::w434Entries($entries)) ?? self::summarizeGameReleaseBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGameReleaseBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W434_SUMMARY_KEYS, 0);
        $groups = self::w434SceneGroups();
        foreach (self::w434Entries($entries) as $entry) {
            $summary['total_releases']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $flags = 0;
            foreach (preg_split('/[^A-Za-z0-9]+/', $entry, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (($token[0] === 'v' || $token[0] === 'V') && ctype_digit(substr($token, 1))) {
                    $flags |= 16;
                } elseif (strncasecmp($token, 'multi', 5) === 0 && ctype_digit(substr($token, 5))) {
                    $flags |= 32;
                } else {
                    $flags |= self::W434_GAME_KEYWORDS[strtoupper($token)] ?? 0;
                }
            }
            $summary['scene_group_count'] += (int) (preg_match('/-[ ]*([A-Za-z0-9]+)\z/', $entry, $m) === 1 && isset($groups[strtoupper($m[1])]));
            $summary['edition_tag_count'] += (int) (($flags & 1) !== 0);
            $summary['version_tag_count'] += (int) (($flags & 16) !== 0);
            $summary['update_patch_count'] += (int) (($flags & 2) !== 0);
            $summary['release_tag_count'] += (int) (($flags & 4) !== 0);
            $summary['multi_language_count'] += (int) (($flags & 32) !== 0);
            $summary['dlc_count'] += (int) (($flags & 8) !== 0);
            $summary['bracket_tag_count'] += (int) (strpbrk($entry, '[(') !== false);
        }

        return $summary;
    }

    /** Uppercase keyword => flag (1 edition, 2 update, 4 release, 8 DLC). */
    private const array W434_GAME_KEYWORDS = [
        'GOTY' => 1, 'REMASTERED' => 1, 'DEFINITIVE' => 1, 'DELUXE' => 1, 'ULTIMATE' => 1, 'COMPLETE' => 1, 'ANNIVERSARY' => 1,
        'UPDATE' => 2, 'PATCH' => 2, 'HOTFIX' => 2,
        'REPACK' => 4, 'RIP' => 4, 'ISO' => 4, 'CRACK' => 4, 'CRACKFIX' => 4, 'PROPER' => 4,
        'DLC' => 8, 'DLCS' => 8,
    ];

    /** @return array<string, true> Uppercased alphanumeric SCENE_GROUPS members. */
    private static function w434SceneGroups(): array
    {
        static $groups = null;
        if ($groups === null) {
            $groups = [];
            foreach (self::SCENE_GROUPS as $group) {
                if (ctype_alnum($group)) {
                    $groups[strtoupper($group)] = true;
                }
            }
        }

        return $groups;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w434Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['searchname'] ?? $item['name'] ?? $item['title'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w434Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w434Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w434', self::W434_FFI_CDEF) ?? false;
            $ffi = self::$w434Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
            $written = $ffi->nntmux_w434_game_release_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
                $written = $ffi->nntmux_w434_game_release_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W434_SUMMARY_KEYS || $result['total_releases'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W434_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---- Wave 61.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.46x/4.41x, uncommon 4.50x/4.06x); see RustCategorizersTvXxxConsoleGamesServiceTitleParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_GAMES_TITLE_PARSER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w612_game_title_parse_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W61_GAMES_TITLE_PARSER_TAIL = 32;

    /** Lowercase SCENE_GROUPS that a 1-20 byte `[A-Za-z0-9_]` group suffix can name. */
    private const array W61_SCENE_GROUPS = [
        'codex' => true, 'plaza' => true, 'gog' => true, 'cpy' => true, 'hoodlum' => true, 'empress' => true, 'rune' => true, 'tenoke' => true,
        'flt' => true, 'reloaded' => true, 'skidrow' => true, 'prophet' => true, 'razor1911' => true, 'core' => true, 'reflex' => true, 'p2p' => true,
        'goldberg' => true, 'darksiders' => true, 'tinyiso' => true, 'doge' => true, 'anomaly' => true, 'elamigos' => true, 'fitgirl' => true,
        'dodi' => true, 'xatab' => true, 'blg' => true, 'rargb' => true, 'chronos' => true, 'fckdrm' => true, 'i_know' => true, 'steam' => true,
        'sptgames' => true, 'kaos' => true, 'simplex' => true, 'ali213' => true, 'fltdox' => true, '3dmgame' => true, 'postmortem' => true,
        'vace' => true, 'rogue' => true, 'outlaws' => true,
    ];

    /** Lowercase extension => w61GameTitleParsePlanBatch() extension index (cleanReleaseNamePhp()'s stripped extensions). */
    private const array W61_TITLE_EXTENSIONS = ['zip' => 1, 'rar' => 2, '7z' => 3, 'iso' => 4, 'nfo' => 5, 'sfv' => 6, 'exe' => 7, 'mkv' => 8, 'mp4' => 9,
        'avi' => 10];

    /** Lowercase dropped token => flag (128 repack, 512 crack, 2048 noise or scene group, 4096 DLC). */
    private const array W61_TITLE_TOKENS = [
        'repack' => 128, 'proper' => 128, 'rip' => 128, 'rerip' => 128, 'crack' => 512, 'crackfix' => 512, 'crackonly' => 512, 'cracked' => 512,
        'dlc' => 4096, 'dlcs' => 4096,
        'iso' => 2048, 'x64' => 2048, 'x86' => 2048, 'win' => 2048, 'win64' => 2048, 'win32' => 2048, 'pc' => 2048, 'incl' => 2048, 'readnfo' => 2048,
        'nfofix' => 2048, 'retail' => 2048,
        'codex' => 2048, 'plaza' => 2048, 'gog' => 2048, 'cpy' => 2048, 'hoodlum' => 2048, 'empress' => 2048, 'rune' => 2048, 'tenoke' => 2048,
        'flt' => 2048, 'reloaded' => 2048, 'skidrow' => 2048, 'prophet' => 2048, 'razor1911' => 2048, 'core' => 2048, 'reflex' => 2048, 'p2p' => 2048,
        'goldberg' => 2048, 'darksiders' => 2048, 'tinyiso' => 2048, 'doge' => 2048, 'anomaly' => 2048, 'elamigos' => 2048, 'fitgirl' => 2048,
        'dodi' => 2048, 'xatab' => 2048, 'blg' => 2048, 'rargb' => 2048, 'chronos' => 2048, 'fckdrm' => 2048, 'steam' => 2048, 'sptgames' => 2048,
        'kaos' => 2048, 'simplex' => 2048, 'ali213' => 2048, 'fltdox' => 2048, '3dmgame' => 2048, 'postmortem' => 2048, 'vace' => 2048,
        'rogue' => 2048, 'outlaws' => 2048,
    ];

    /**
     * Wave 61 routing, binding and output buffer, kept in function-local statics (no static
     * properties). Operations: null reads the routing override, 'set' replaces it (returning the
     * previous one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]`
     * with room for at least $val words (null without the library).
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w61', self::W61_GAMES_TITLE_PARSER_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w61RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w61GameTitleParsePlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61GameTitleParsePlanBatch(array $rows): array
    {
        return self::usesW61RustFfi() ? self::w61GameTitleParsePlanBatchNative($rows) : self::w61GameTitleParsePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61GameTitleParsePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W61_GAMES_TITLE_PARSER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w61RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w61GameTitleParsePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W61_GAMES_TITLE_PARSER_FFI_CDEF.)
            $written = $ffi->nntmux_w612_game_title_parse_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w61GameTitleParsePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w61Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** $min to $max ASCII digits as an integer, otherwise null. */
    private static function w61Digits(string $value, int $min, int $max): ?int
    {
        $length = \strlen($value);

        return $length >= $min && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /**
     * The maximal `[a-z0-9]` runs of the ASCII-lowercased value.
     *
     * @return list<string>
     */
    private static function w61Tokens(string $value): array
    {
        $tokens = $value === '' ? false : preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * Wave 61.2 game title cleaning and similarity plan. Per `release\tcompare` row `extension |
     * flags << 4 | state << 17 | exact << 19 | prefix << 20 | dice << 21 | min(kept, 63) << 28 |
     * min(len(clean), 255) << 34 | (crc32(clean) & 0x1FFFFF) << 42`. The release loses a final
     * W61_TITLE_EXTENSIONS extension (after its last `.`, any case; flag 1), a leading `[...]`
     * tag (flag 2) and a `-` group suffix (1-20 bytes of `[A-Za-z0-9_]` after the last `-`; flag
     * 4, plus 8 for a W61_SCENE_GROUPS entry). Its w61Tokens() then drop W61_TITLE_TOKENS
     * entries, versions (`v` + 1-9 digits; 16), builds (`build` or `build` + 1-9 digits; 32),
     * updates (`update`, `patch`, `hotfix` or `update` + 1-9 digits; 64) together with the
     * all-digit tokens right after any of those three, `multi` + 0-2 digits (256) and 1970-2099
     * years (1024). Clean is the kept tokens joined by single spaces. Against the compare
     * w61Tokens(): exact when clean is non-empty and equals them joined, prefix when both first
     * tokens match, dice 200 * shared / (distinct kept + distinct compare tokens). State: 0 empty
     * clean, 1 no compare tokens, 3 exact or dice >= 70, else 2. Then eleven extension, thirteen
     * flag and four state counts, the exact rows, the distinct clean titles of rows in states 1-3
     * (by crc32), the dice sum and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61GameTitleParsePlanBatchPhp(array $rows): array
    {
        $out = $cleans = [];
        $extensions = array_fill(0, 11, 0);
        $flagCounts = array_fill(0, 13, 0);
        $states = [0, 0, 0, 0];
        $exactRows = $diceSum = 0;
        foreach ($rows as $row) {
            [$release, $compare] = explode("\t", (string) $row, 2) + ['', ''];
            $value = $release;
            $extension = $flags = 0;
            $dot = strrpos($value, '.');
            if ($dot !== false) {
                $extension = self::W61_TITLE_EXTENSIONS[strtolower(substr($value, $dot + 1))] ?? 0;
                if ($extension !== 0) {
                    $value = substr($value, 0, $dot);
                    $flags |= 1;
                }
            }
            if ($value !== '' && $value[0] === '[' && ($close = strpos($value, ']', 1)) !== false) {
                $value = substr($value, $close + 1);
                $flags |= 2;
            }
            $dash = strrpos($value, '-');
            if ($dash !== false) {
                $suffix = substr($value, $dash + 1);
                $length = \strlen($suffix);
                if ($length >= 1 && $length <= 20 && strspn($suffix, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_') === $length) {
                    $flags |= isset(self::W61_SCENE_GROUPS[strtolower($suffix)]) ? 12 : 4;
                    $value = substr($value, 0, $dash);
                }
            }
            $kept = [];
            $chain = false;
            foreach (self::w61Tokens($value) as $token) {
                $length = \strlen($token);
                if ($chain && strspn($token, '0123456789') === $length) {
                    continue;
                }
                $chain = false;
                $f = self::W61_TITLE_TOKENS[$token] ?? 0;
                if ($token[0] === 'v' && self::w61Digits(substr($token, 1), 1, 9) !== null) {
                    $f |= 16;
                    $chain = true;
                } elseif ($token === 'build' || (str_starts_with($token, 'build') && self::w61Digits(substr($token, 5), 1, 9) !== null)) {
                    $f |= 32;
                    $chain = true;
                } elseif ($token === 'update' || $token === 'patch' || $token === 'hotfix'
                    || (str_starts_with($token, 'update') && self::w61Digits(substr($token, 6), 1, 9) !== null)) {
                    $f |= 64;
                    $chain = true;
                } elseif (str_starts_with($token, 'multi') && ($length === 5 || self::w61Digits(substr($token, 5), 1, 2) !== null)) {
                    $f |= 256;
                } elseif ($length === 4 && ($year = self::w61Digits($token, 4, 4)) !== null && $year >= 1970 && $year <= 2099) {
                    $f |= 1024;
                }
                $flags |= $f;
                if ($f === 0) {
                    $kept[] = $token;
                }
            }
            $clean = implode(' ', $kept);
            $compareTokens = self::w61Tokens($compare);
            $exact = $clean !== '' && $clean === implode(' ', $compareTokens);
            $prefix = $kept !== [] && $compareTokens !== [] && $kept[0] === $compareTokens[0];
            $keptSet = array_fill_keys($kept, true);
            $compareSet = array_fill_keys($compareTokens, true);
            $sum = \count($keptSet) + \count($compareSet);
            $dice = $sum > 0 ? intdiv(200 * \count(array_intersect_key($keptSet, $compareSet)), $sum) : 0;
            $state = match (true) {
                $clean === '' => 0,
                $compareSet === [] => 1,
                $exact || $dice >= 70 => 3,
                default => 2,
            };
            $crc = crc32($clean);
            $extensions[$extension]++;
            for ($k = 0; $k < 13; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            $states[$state]++;
            $exactRows += (int) $exact;
            $diceSum += $dice;
            if ($state >= 1) {
                $cleans[$crc] = true;
            }
            $out[] = $extension | $flags << 4 | $state << 17 | (int) $exact << 19 | (int) $prefix << 20 | $dice << 21 | min(\count($kept), 63) << 28
                | min(\strlen($clean), 255) << 34 | ($crc & 0x1FFFFF) << 42;
        }
        array_push($out, ...$extensions, ...$flagCounts, ...$states);
        array_push($out, $exactRows, \count($cleans), $diceSum);
        $out[] = self::w61Digest($out);

        return $out;
    }
}
