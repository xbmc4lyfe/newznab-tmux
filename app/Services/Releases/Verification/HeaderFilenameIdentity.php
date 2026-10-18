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
    // A single-quoted name may contain apostrophes (O'Brien's): only a quote not followed by a word character closes it.
    private const string QUOTED = '/"([^"\r\n]+)"|(?<![\p{L}\p{N}_])\'([^\r\n]+?)\'(?![\p{L}\p{N}_])/u';

    private const string SUFFIX = '/\A(?:part\d+|vol\d+[+-]\d+|par2|rar|r\d{2,3}|\d{3}|7z|zip|tar|gz|tgz|xz|bz2|zst|mkv|avi|mp4|m4v|ts|nfo|sfv|nzb|epub|pdf|flac|mp3|iso|srt|txt|jpg|png)\z/i';

    private const string EXECUTABLE = '/\.(?:exe|scr|bat|cmd|com|msi|msix|msp|lnk|pif|cpl|hta|reg|scf|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|jar|apk|dll|sh|command|app|dmg|pkg|chm|msc|ps1xml|psd1|cab|appx|appxbundle|msixbundle|application|gadget|inf|url|xll|ws|wsc|sct|vb|ocx|sys|iso|img|vhd|vhdx|desktop|appimage|deb|rpm|snap|run|msu|py|pyw|pl|rb|php)\z/i';

    public static function matches(string $subject, string $actual): bool
    {
        $subject = trim($subject);
        // Article names are validated as UTF-8 before this check; anything else cannot be compared.
        if (! mb_check_encoding($actual, 'UTF-8')) {
            return false;
        }
        $count = preg_match_all(self::QUOTED, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            // Invalid UTF-8 cannot be parsed for a declared name, so it cannot be compared.
            return false;
        }
        if ($count === 0) {
            // An unquoted subject declares a file only by naming it before its single yEnc marker.
            if (preg_match_all('/(?<![\p{L}\p{N}_])yEnc(?![\p{L}\p{N}_])/iu', $subject) > 1) {
                return false;
            }
            $head = (string) preg_replace('/(?<![\p{L}\p{N}_])yEnc(?![\p{L}\p{N}_]).*\z/isu', '', $subject);

            // The subject must name the body itself, not a longer file such as body.exe.mkv.
            return preg_match('/(?<![\p{L}\p{N}_])'.preg_quote(self::trimmed($actual), '/').'(?![\p{L}\p{N}_]|[.\-]+[\p{L}\p{N}])/iu', $head) === 1;
        }
        $quoted = [];
        $declared = null;
        $afterCounter = null;
        foreach ($matches as $match) {
            $value = ($match[1][1] ?? -1) >= 0 ? $match[1][0] : $match[2][0];
            $quoted[] = $value;
            $end = $match[0][1] + strlen($match[0][0]);
            // The filename is the quoted value that "yEnc" follows (optionally after an (n/m) or [n/m]
            // counter), else the one after a [n/m] counter.
            if (preg_match('/\A\s*(?:(?:\(\d+\/\d+\)|\[\d+\/\d+\])\s*)?yEnc(?![\p{L}\p{N}_])/iu', substr($subject, $end)) === 1) {
                // Two quoted names each marked by yEnc leave the article's filename ambiguous.
                if ($declared !== null) {
                    return false;
                }
                $declared = $value;
            }
            if ($afterCounter === null && preg_match('/\[\d+\/\d+\]\s*+(?:-\s*+)?\z/', substr($subject, 0, $match[0][1])) === 1) {
                $afterCounter = $value;
            }
        }
        $declared ??= $afterCounter ?? $quoted[array_key_last($quoted)];
        // An executable body must be exactly the executable type its subject declares, even when
        // another quoted value names it.
        if (self::executable($actual) && (! self::executable($declared)
            || strtolower(self::extension(self::bare($actual))) !== strtolower(self::extension(self::bare($declared)))
            || strtolower(self::extension(self::trimmed($actual))) !== strtolower(self::extension(self::trimmed($declared))))) {
            return false;
        }
        // Parity and payload are not interchangeable: a declared PAR2 file is not payload evidence, and a
        // declared payload file is not satisfied by a PAR2 body, even when another quoted value names it.
        if (! self::sameParity([self::extension(self::trimmed($declared)), self::extension(self::bare($declared))], $actual)) {
            return false;
        }
        if (in_array($actual, $quoted, true)) {
            return true;
        }
        // A long, hexadecimal or densely mixed token is plainly random. A weaker one could be a misread title
        // (XFilesMovie), so it admits only a plainly random body or one that extends the declared token.
        if (self::opaque($declared)) {
            $token = self::token($declared);

            return self::random($declared) || self::random($actual)
                || str_starts_with(strtolower(self::bare($actual)), strtolower($token));
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

        // Only separators and case may differ: an added, removed or changed word (VI, Part A) is another file.
        return $expected === $body;
    }

    /** The stem without an unlisted final extension, such as .wmv. */
    private static function token(string $name): string
    {
        return (string) preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', self::stem($name));
    }

    /**
     * Whether a sampled article name is evidence of a source payload: a non-PAR2 name with an extension, or
     * a readable extensionless name such as README, but never an obfuscated extensionless token.
     */
    /**
     * Whether a body agrees with every declared extension on being parity or payload, in every filesystem form.
     *
     * @param  list<string>  $declaredExtensions
     */
    private static function sameParity(array $declaredExtensions, string $actual): bool
    {
        foreach ($declaredExtensions as $declaredExtension) {
            $declaredExtension = strtolower($declaredExtension);
            foreach ([self::trimmed($actual), self::bare($actual)] as $form) {
                $bodyExtension = strtolower(self::extension($form));
                if ($declaredExtension !== '' && $bodyExtension !== '' && ($declaredExtension === 'par2') !== ($bodyExtension === 'par2')) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function provesPayload(string $name): bool
    {
        if (preg_match('/\.par2\z/i', self::trimmed($name)) === 1 || preg_match('/\.par2\z/i', self::bare($name)) === 1) {
            return false;
        }

        return preg_match('/\.[A-Za-z0-9]{1,8}\z/', self::trimmed($name)) === 1 || preg_match('/\.[A-Za-z0-9]{1,8}\z/', self::bare($name)) === 1
            || (($bare = self::bare($name)) !== '' && ! str_contains($bare, '.') && ! self::opaque($bare) && preg_match('/\p{L}/u', $bare) === 1);
    }

    /** An opaque token that is long, hexadecimal or densely mixed, too random to be a misread title. */
    private static function random(string $name): bool
    {
        $token = self::token($name);

        // Random tokens switch between lowercase, uppercase and digits at nearly every other character.
        $switches = preg_match_all('/[a-z](?=[A-Z0-9])|[A-Z](?=[a-z0-9])|[0-9](?=[A-Za-z])/', $token);

        return self::opaque($name) && (strlen($token) >= 20 || preg_match('/\A[0-9a-f]{16,}\z/i', $token) === 1
            || $switches >= 0.4 * strlen($token));
    }

    /** A single random-looking token, such as an obfuscated posting name, makes no filename claim. */
    private static function opaque(string $name): bool
    {
        $stem = self::token($name);
        if (preg_match('/\A[A-Za-z0-9]+\z/', $stem) !== 1) {
            return false;
        }
        if (preg_match('/\A[0-9a-f]{16,}\z/i', $stem) === 1) {
            return true;
        }
        if (self::titled($stem)) {
            return false;
        }
        $classes = preg_match('/[a-z]/', $stem) + preg_match('/[A-Z]/', $stem) + preg_match('/[0-9]/', $stem);

        return strlen($stem) >= 10 && $classes >= 2;
    }

    /**
     * Whether a token reads as a run-together title (BBC2Documentary, 13GoingOn30, iPhoneMovie): it splits
     * into an optional leading lowercase word, capitalized words, numbers and at most one short acronym, and
     * every word has a vowel. Random tokens break one of these almost immediately.
     */
    private static function titled(string $stem): bool
    {
        preg_match_all('/[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+/', $stem, $matches);
        $acronyms = 0;
        foreach ($matches[0] as $index => $part) {
            if (ctype_digit($part)) {
                continue;
            }
            if (ctype_upper($part) && strlen($part) > 1) {
                if (++$acronyms > 1 || strlen($part) > 4) {
                    return false;
                }

                continue;
            }
            if ((ctype_lower($part) && $index > 0) || preg_match('/[aeiouy]/i', $part) !== 1) {
                return false;
            }
        }

        return $matches[0] !== [];
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
        return (string) preg_replace('/[^\p{L}\p{M}\p{N}]/u', '', self::folded($name));
    }

    /** The lowercased stem in canonical composition, so composed and decomposed accents compare equal. */
    private static function folded(string $name): string
    {
        $stem = self::stem($name);

        return mb_strtolower(\Normalizer::normalize($stem, \Normalizer::FORM_C) ?: $stem);
    }
}
