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

    private const string SUFFIX = '/\A(?:part\d+|vol\d+[+-]\d+|par2|rar|r\d{2,3}|\d{3}|7z|zip|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png)\z/i';

    private const string EXECUTABLE = '/\.(?:exe|scr|bat|cmd|com|msi|msix|msp|lnk|pif|cpl|hta|reg|scf|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|jar|apk|dll|sh|command|app|dmg|pkg|chm|msc|ps1xml|psd1|cab|appx|appxbundle|msixbundle|application|gadget|inf|url|xll|ws|wsc|sct|vb|ocx|sys|iso|img|vhd|vhdx)\z/i';

    public static function matches(string $subject, string $actual): bool
    {
        $subject = trim($subject);
        $count = preg_match_all(self::QUOTED, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            // Invalid UTF-8 cannot be parsed for a declared name, so it cannot be compared.
            return false;
        }
        if ($count === 0) {
            return ! self::executable($actual)
                // The subject must name the body itself, not a longer file such as body.exe.mkv.
                || preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($actual, '/').'(?![\p{L}\p{N}_]|[.\-][\p{L}\p{N}])/iu', $subject) === 1;
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
            if ($afterCounter === null && preg_match('/\[\d+\/\d+\]\s*+(?:-\s*+)?\z/', substr($subject, 0, $match[0][1])) === 1) {
                $afterCounter = $value;
            }
        }
        $declared ??= $afterCounter ?? $quoted[array_key_last($quoted)];
        if (in_array($actual, $quoted, true)) {
            return true;
        }
        // An executable body must be exactly the executable type its subject declares.
        if (self::executable($actual) && (! self::executable($declared)
            || strtolower(self::extension(self::bare($actual))) !== strtolower(self::extension(self::bare($declared)))
            || strtolower(self::extension(self::trimmed($actual))) !== strtolower(self::extension(self::trimmed($declared))))) {
            return false;
        }
        if (self::opaque($declared)) {
            // An opaque recovery-file name still declares parity, which is not payload evidence.
            return ! (preg_match('/\.par2\z/i', $declared) === 1 && self::extension($actual) !== ''
                && strtolower(self::extension($actual)) !== 'par2');
        }
        // A readable declared name must agree on its volume and extension chain, and on its final
        // extension when that chain does not cover it, as well as its stem.
        if (strtolower(self::suffix($declared)) !== strtolower(self::suffix($actual))
            || strtolower(self::extension($declared)) !== strtolower(self::extension($actual))) {
            return false;
        }
        $expected = self::normalized($declared);
        $body = self::normalized($actual);
        // Removing separators must not merge different numbers: Show 1 10 is not Show 11 0.
        if ($expected === '' || $body === '' || self::numbers($declared) !== self::numbers($actual)) {
            return false;
        }
        if ($expected === $body) {
            return true;
        }
        [$shorter, $longer] = mb_strlen($expected) <= mb_strlen($body) ? [$expected, $body] : [$body, $expected];

        // A prefix may not add or extend a number: Part 1 is not Part 10, and Movie is not Movie 2.
        return mb_strlen($shorter) >= 8 && str_starts_with($longer, $shorter)
            && preg_match('/\p{N}\z/u', $shorter) !== 1 && preg_match('/\p{N}/u', mb_substr($longer, mb_strlen($shorter))) !== 1;
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
        // Capitalized words, or one run of single-case letters, with an optional trailing number read
        // as a run-together title rather than a random token.
        if (preg_match('/\A(?:(?:[A-Z][a-z]+)+|[a-z]+|[A-Z]+)[0-9]*\z/', $stem) === 1) {
            return false;
        }
        $classes = preg_match('/[a-z]/', $stem) + preg_match('/[A-Z]/', $stem) + preg_match('/[0-9]/', $stem);

        return strlen($stem) >= 10 && $classes >= 2;
    }

    private static function executable(string $name): bool
    {
        return preg_match(self::EXECUTABLE, self::trimmed($name)) === 1 || preg_match(self::EXECUTABLE, self::bare($name)) === 1;
    }

    /** The name a filesystem writes, without trailing dots and spaces. */
    private static function trimmed(string $name): string
    {
        return rtrim($name, ". \t");
    }

    /** The name an NTFS write keeps, without a stream suffix after the first colon. */
    private static function bare(string $name): string
    {
        return self::trimmed((string) preg_replace('/:.*\z/s', '', $name));
    }

    private static function extension(string $name): string
    {
        return preg_match('/\.([^.\s]+)\z/u', $name, $match) === 1 ? $match[1] : '';
    }

    /** The name without its trailing chain of known archive and media suffixes, stripped in linear time. */
    private static function stem(string $name): string
    {
        while (($dot = strrpos($name, '.')) !== false && preg_match(self::SUFFIX, substr($name, $dot + 1)) === 1) {
            $name = substr($name, 0, $dot);
        }

        return $name;
    }

    private static function suffix(string $name): string
    {
        return substr($name, strlen(self::stem($name)));
    }

    /** @return list<string> */
    private static function numbers(string $name): array
    {
        preg_match_all('/\p{N}+/u', self::stem($name), $matches);

        return $matches[0];
    }

    private static function normalized(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(self::stem($name)));
    }
}
