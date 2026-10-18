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

    private const string EXECUTABLE = '/\.(?:exe|scr|bat|cmd|com|msi|msix|msp|lnk|pif|cpl|hta|reg|scf|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|jar|apk|dll|sh|command|app|dmg|pkg|chm|msc|ps1xml|psd1|cab|appx|appxbundle|msixbundle|application|gadget|inf|url|xll|ws)\z/i';

    public static function matches(string $subject, string $actual): bool
    {
        $subject = trim($subject);
        $count = preg_match_all(self::QUOTED, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            // Invalid UTF-8 cannot be parsed for a declared name, so it cannot be compared.
            return false;
        }
        if ($count === 0) {
            return ! self::executable($actual) || stripos($subject, $actual) !== false;
        }
        $quoted = [];
        $declared = null;
        $afterCounter = null;
        foreach ($matches as $match) {
            $value = ($match[1][1] ?? -1) >= 0 ? $match[1][0] : $match[2][0];
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
        if (self::executable($actual) && ! self::executable($declared)) {
            return false;
        }
        if (self::opaque($declared)) {
            return true;
        }
        // A readable declared name must agree on its volume and extension chain, and on its final
        // extension when that chain does not cover it, as well as its stem.
        if (strtolower(self::suffix($declared)) !== strtolower(self::suffix($actual))
            || strtolower(self::extension($declared)) !== strtolower(self::extension($actual))) {
            return false;
        }
        $expected = self::normalized($declared);
        $body = self::normalized($actual);
        if ($expected === '' || $body === '') {
            return false;
        }
        if ($expected === $body) {
            return true;
        }
        [$shorter, $longer] = mb_strlen($expected) <= mb_strlen($body) ? [$expected, $body] : [$body, $expected];

        // A prefix may not extend a trailing number: Part 1 is not Part 10.
        return mb_strlen($shorter) >= 8 && str_starts_with($longer, $shorter)
            && ! (ctype_digit(substr($shorter, -1)) && ctype_digit(mb_substr($longer, mb_strlen($shorter), 1)));
    }

    /** A single random-looking token, such as an obfuscated posting name, makes no filename claim. */
    private static function opaque(string $name): bool
    {
        $stem = self::stem($name);
        if (preg_match('/\A[A-Za-z0-9]+\z/', $stem) !== 1) {
            return false;
        }
        if (preg_match('/\A[0-9a-f]{16,}\z/i', $stem) === 1) {
            return true;
        }
        // Random tokens switch between lowercase, uppercase and digits far more often than words do.
        $switches = preg_match_all('/[a-z](?=[A-Z0-9])|[A-Z](?=[a-z0-9])|[0-9](?=[A-Za-z])/', $stem);

        return strlen($stem) >= 20 || (strlen($stem) >= 10 && $switches >= 5);
    }

    private static function executable(string $name): bool
    {
        return preg_match(self::EXECUTABLE, rtrim($name, ". \t")) === 1;
    }

    private static function extension(string $name): string
    {
        return preg_match('/\.([A-Za-z0-9]{1,5})\z/', $name, $match) === 1 ? $match[1] : '';
    }

    private static function stem(string $name): string
    {
        return (string) preg_replace(self::SUFFIXES, '', $name);
    }

    private static function suffix(string $name): string
    {
        return substr($name, strlen(self::stem($name)));
    }

    private static function normalized(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(self::stem($name)));
    }
}
