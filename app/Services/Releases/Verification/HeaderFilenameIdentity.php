<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * Whether a spot-checked article's yEnc filename agrees with the filename its subject declares.
 * Part, total, size, range and CRC checks already tie the article to its header, so this only
 * rejects a readable declared name that the body contradicts; an obfuscated subject declares none.
 */
final class HeaderFilenameIdentity
{
    private const string QUOTED = '/"([^"\r\n]+)"|(?<![\p{L}\p{N}_])\'([^\'\r\n]+)\'(?![\p{L}\p{N}_])/u';

    private const string SUFFIXES = '/(?:\.(?:part\d+|vol\d+[+-]\d+|par2|rar|r\d{2,3}|\d{3}|7z|zip|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png))+\z/i';

    private const string EXECUTABLE = '/\.(?:exe|scr|bat|cmd|com|msi|lnk|js|vbs|ps1|jar|apk|dll|pif)\z/i';

    public static function matches(string $subject, string $actual): bool
    {
        $subject = trim($subject);
        if (preg_match_all(self::QUOTED, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return true;
        }
        $quoted = [];
        $declared = null;
        $afterCounter = null;
        foreach ($matches as $match) {
            [$value, $offset] = ($match[1][1] ?? -1) >= 0 ? $match[1] : $match[2];
            $quoted[] = $value;
            $end = $match[0][1] + strlen($match[0][0]);
            // The filename is the quoted value that "yEnc" follows, else the one after the [n/m] counter.
            if ($declared === null && preg_match('/\A\s*(?:\(\d+\/\d+\)\s*)?yEnc/i', substr($subject, $end)) === 1) {
                $declared = $value;
            }
            if ($afterCounter === null && preg_match('/\[\d+\/\d+\]\s*-?\s*\z/', substr($subject, 0, $match[0][1])) === 1) {
                $afterCounter = $value;
            }
        }
        $declared ??= $afterCounter ?? $quoted[array_key_last($quoted)];
        if (in_array($actual, $quoted, true)) {
            return true;
        }
        if (preg_match(self::EXECUTABLE, $actual) === 1 && preg_match(self::EXECUTABLE, $declared) !== 1) {
            return false;
        }
        if (self::opaque($declared)) {
            return true;
        }
        $expected = self::normalized($declared);
        $body = self::normalized($actual);
        if ($expected === '' || $body === '') {
            return false;
        }

        return $expected === $body || (min(strlen($expected), strlen($body)) >= 8
            && (str_starts_with($body, $expected) || str_starts_with($expected, $body)));
    }

    /** A single random-looking token, such as an obfuscated posting name, makes no filename claim. */
    private static function opaque(string $name): bool
    {
        $stem = (string) preg_replace(self::SUFFIXES, '', $name);
        if (preg_match('/\A[A-Za-z0-9]+\z/', $stem) !== 1) {
            return false;
        }
        if (preg_match('/\A[0-9a-f]{16,}\z/i', $stem) === 1) {
            return true;
        }
        $classes = preg_match('/[a-z]/', $stem) + preg_match('/[A-Z]/', $stem) + preg_match('/[0-9]/', $stem);

        return strlen($stem) >= 20 || (strlen($stem) >= 10 && $classes >= 2);
    }

    private static function normalized(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) preg_replace(self::SUFFIXES, '', $name)));
    }
}
