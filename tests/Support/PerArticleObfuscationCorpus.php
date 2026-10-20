<?php

declare(strict_types=1);

namespace Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded header ranges for comparing HeaderParser::markPerArticleObfuscated() with its Rust
 * candidate. Shapes follow redacted production samples (2026-10-19): kenpsx
 * `[40/62] "<32 random>" yEnc (n/687)` and boneless `[<2 random>] - <40 random> yEnc (n/1250)`,
 * interleaved across files, plus readable and per-file-hashed real uploads.
 */
final class PerArticleObfuscationCorpus
{
    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** @return array<string, array{category: string, description: string, size: int}> */
    public static function strata(): array
    {
        return [
            'real-uploads' => ['category' => 'common', 'description' => 'readable and per-file-hashed real uploads, parts in order', 'size' => 20000],
            'kenpsx' => ['category' => 'common', 'description' => 'per-article random quoted names, 4 interleaved files per signature', 'size' => 20000],
            'boneless' => ['category' => 'common', 'description' => 'per-article random bracketed names, files posted in turn', 'size' => 20000],
            'mixed' => ['category' => 'common', 'description' => 'half real uploads, half obfuscated, interleaved', 'size' => 20000],
            'readable-yenc' => ['category' => 'routed', 'description' => 'readable yEnc uploads without 16+ alphanumeric tokens (predicted 1.65x, stays on PHP)', 'size' => 20000],
            'bare-random' => ['category' => 'routed', 'description' => 'random 32-character subjects without part counts (stays on PHP)', 'size' => 20000],
            'single-part-hashed' => ['category' => 'routed', 'description' => 'hash-named single-part posts, 15% in a readable range (predicted under 2x, stays on PHP)', 'size' => 20000],
            'text-heavy-obfuscated' => ['category' => 'common', 'description' => 'text posts with 85% obfuscated articles (past the part-count cutoff, goes to Rust)', 'size' => 20000],
            'readable-40-obfuscated' => ['category' => 'common', 'description' => 'readable yEnc with 40% obfuscated articles (goes to Rust)', 'size' => 20000],
            'readable-25-obfuscated' => ['category' => 'common', 'description' => 'readable yEnc with 25% obfuscated articles (goes to Rust)', 'size' => 20000],
            'readable-tenth-obfuscated' => ['category' => 'routed', 'description' => 'readable yEnc with 10% obfuscated articles (predicted 1.88x, stays on PHP)', 'size' => 20000],
            'text-half-obfuscated' => ['category' => 'common', 'description' => 'text posts with 50% obfuscated articles (goes to Rust)', 'size' => 20000],
            'small-text' => ['category' => 'routed', 'description' => 'text posts, 16000 headers (smallest sampled range, stays on PHP)', 'size' => 16000],
            'small-readable' => ['category' => 'routed', 'description' => 'readable yEnc, 16000 headers (smallest sampled range, stays on PHP)', 'size' => 16000],
            'text-third-obfuscated' => ['category' => 'routed', 'description' => 'text posts with 20% obfuscated articles (predicted 1.6x, stays on PHP)', 'size' => 20000],
            'long-text-obfuscated' => ['category' => 'routed', 'description' => 'text posts of about 450 bytes with 40% obfuscated articles (stays on PHP)', 'size' => 20000],
            'long-token-obfuscated' => ['category' => 'common', 'description' => 'per-article random names of about 400 characters (goes to Rust)', 'size' => 20000],
            'text-with-uploads' => ['category' => 'routed', 'description' => 'text posts with 8% obfuscated articles and 10% counter-prefixed single-part images (stays on PHP)', 'size' => 20000],
            'non-yenc' => ['category' => 'routed', 'description' => 'text posts without yEnc part counts (stays on PHP)', 'size' => 20000],
            'large-range' => ['category' => 'uncommon', 'description' => 'mixed range of 100k headers', 'size' => 100000],
            'small-range' => ['category' => 'routed', 'description' => 'mixed range of 200 headers (under MIN_HEADERS, stays on PHP)', 'size' => 200],
            'long-subjects' => ['category' => 'uncommon', 'description' => 'mixed range with 300-byte subject prefixes', 'size' => 20000],
            'adversarial' => ['category' => 'uncommon', 'description' => 'random bytes, newlines, odd counters and invalid UTF-8', 'size' => 20000],
            'crafted-whitespace' => ['category' => 'routed', 'description' => 'mixed range with 10% whitespace-padded subjects of 256-512 bytes built to make the subject pattern backtrack (under MIN_HEADERS, stays on PHP)', 'size' => 2000],
        ];
    }

    /** @return list<array<string, string>> */
    public static function stratum(string $name, int $seed, ?int $size = null): array
    {
        $random = new Randomizer(new Mt19937($seed ^ crc32($name)));
        $size ??= self::strata()[$name]['size'];
        $subjects = match ($name) {
            'real-uploads' => self::real($random, $size),
            'readable-yenc' => self::real($random, $size, false),
            'non-yenc' => self::text($random, $size),
            'bare-random' => array_map(static fn (): string => self::token($random, 32), range(1, $size)),
            'single-part-hashed' => self::singlePartHashed($random, $size),
            'text-with-uploads' => self::textWithUploads($random, $size),
            'text-heavy-obfuscated' => self::textObfuscated($random, $size, 85),
            'text-half-obfuscated' => self::textObfuscated($random, $size, 50),
            'text-third-obfuscated' => self::textObfuscated($random, $size, 20),
            'long-text-obfuscated' => self::longTextObfuscated($random, $size),
            'long-token-obfuscated' => self::longTokens($random, $size),
            'readable-40-obfuscated' => self::readableObfuscated($random, $size, 40),
            'readable-25-obfuscated' => self::readableObfuscated($random, $size, 25),
            'readable-tenth-obfuscated' => self::readableObfuscated($random, $size, 10),
            'small-text' => self::text($random, $size),
            'small-readable' => self::real($random, $size, false),
            'kenpsx' => self::kenpsx($random, $size),
            'boneless' => self::boneless($random, $size),
            'mixed', 'large-range', 'small-range' => self::mixed($random, $size, ''),
            'long-subjects' => self::mixed($random, $size, str_repeat('Long.Prefix.Words ', 17)),
            'adversarial' => self::adversarial($random, $size),
            'crafted-whitespace' => self::craftedWhitespace($random, $size),
            default => throw new \InvalidArgumentException('Unknown stratum '.$name),
        };
        // Shaped like NNTPService::getXOVER() rows: every field is a string.
        $headers = [];
        foreach ($subjects as $index => $subject) {
            $number = (string) (5_000_000_000 + $index);
            $headers[] = ['Number' => $number, 'Subject' => $subject, 'From' => 'poster@example.invalid',
                'Date' => 'Mon, 19 Oct 2026 12:00:00 +0000', 'Message-ID' => '<'.$number.'@example.invalid>', 'References' => '',
                'Bytes' => '768000', 'Lines' => '5900', 'Xref' => 'news.example.invalid alt.binaries.test:'.$number];
        }

        return $headers;
    }

    private static function token(Randomizer $random, int $length): string
    {
        return $random->getBytesFromString(self::ALNUM, $length);
    }

    /** @return list<string> */
    private static function real(Randomizer $random, int $size, bool $allowHashed = true): array
    {
        $subjects = [];
        while (count($subjects) < $size) {
            $files = $random->getInt(1, 40);
            $parts = $random->getInt(2, 300);
            $hashed = $allowHashed && $random->getInt(0, 2) === 0 ? md5($random->getBytes(8)) : null;
            for ($file = 1; $file <= $files && count($subjects) < $size; $file++) {
                $name = $hashed !== null ? $hashed.sprintf('.part%02d.rar', $file) : sprintf('Some.Show.S01E%02d.1080p.part%02d.rar', $files, $file);
                for ($part = 1; $part <= $parts && count($subjects) < $size; $part++) {
                    $subjects[] = sprintf('[%02d/%02d] - "%s" yEnc (%d/%d)', $file, $files, $name, $part, $parts);
                }
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function singlePartHashed(Randomizer $random, int $size): array
    {
        $subjects = self::real($random, $size, false);
        foreach ($subjects as $index => $subject) {
            if ($random->getInt(0, 99) < 15) {
                $subjects[$index] = sprintf('"%s.jpg" yEnc (1/1)', md5($random->getBytes(8)));
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function textWithUploads(Randomizer $random, int $size): array
    {
        $subjects = self::text($random, $size);
        $obfuscated = self::boneless($random, $size);
        foreach ($subjects as $index => $subject) {
            $roll = $random->getInt(0, 99);
            if ($roll < 8) {
                $subjects[$index] = $obfuscated[$index];
            } elseif ($roll < 18) {
                $subjects[$index] = sprintf('(%02d/10) "%s.jpg" yEnc (1/1)', $random->getInt(1, 10), md5($random->getBytes(8)));
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function textObfuscated(Randomizer $random, int $size, int $percent): array
    {
        $subjects = self::text($random, $size);
        $obfuscated = self::boneless($random, $size);
        foreach (array_keys($subjects) as $index) {
            if ($random->getInt(0, 99) < $percent) {
                $subjects[$index] = $obfuscated[$index];
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function readableObfuscated(Randomizer $random, int $size, int $percent): array
    {
        $subjects = self::real($random, $size, false);
        $obfuscated = self::boneless($random, $size);
        foreach (array_keys($subjects) as $index) {
            if ($random->getInt(0, 99) < $percent) {
                $subjects[$index] = $obfuscated[$index];
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function longTextObfuscated(Randomizer $random, int $size): array
    {
        $subjects = self::textObfuscated($random, $size, 40);
        foreach ($subjects as $index => $subject) {
            if (! str_contains($subject, 'yEnc')) {
                $subjects[$index] = substr(str_repeat($subject.' ', intdiv(450, strlen($subject) + 1) + 1), 0, 450);
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function longTokens(Randomizer $random, int $size): array
    {
        $subjects = [];
        while (count($subjects) < $size) {
            $total = $random->getInt(900, 1300);
            for ($part = 1; $part <= $total && count($subjects) < $size; $part++) {
                $subjects[] = sprintf('[%s] - %s yEnc (%d/%d)', self::token($random, 2), self::token($random, 400), $part, $total);
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function text(Randomizer $random, int $size): array
    {
        $words = ['Re:', 'question', 'about', 'the', 'new', 'release', 'help', 'needed', 'with', 'setup', 'thanks', 'update', 'Fwd:', 'weekly', 'discussion'];
        $subjects = [];
        for ($i = 0; $i < $size; $i++) {
            $subject = [];
            for ($word = $random->getInt(2, 9); $word > 0; $word--) {
                $subject[] = $words[$random->getInt(0, count($words) - 1)];
            }
            $subjects[] = implode(' ', $subject).($random->getInt(0, 3) === 0 ? ' #'.$random->getInt(1, 999) : '');
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function kenpsx(Randomizer $random, int $size): array
    {
        $subjects = [];
        $file = 1;
        while (count($subjects) < $size) {
            $totals = [$random->getInt(300, 700), $random->getInt(300, 700), $random->getInt(300, 700), $random->getInt(300, 700)];
            $total = $totals[0];
            for ($part = 1; $part <= $total && count($subjects) < $size; $part++) {
                for ($stream = 0; $stream < 4 && count($subjects) < $size; $stream++) {
                    $subjects[] = sprintf('[%d/62] "%s" yEnc (%d/%d)', $file, self::token($random, 32), $part, $total);
                }
            }
            $file = $file % 62 + 1;
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function boneless(Randomizer $random, int $size): array
    {
        $subjects = [];
        while (count($subjects) < $size) {
            $total = $random->getInt(900, 1300);
            for ($part = 1; $part <= $total && count($subjects) < $size; $part++) {
                $subjects[] = sprintf('[%s] - %s yEnc (%d/%d)', self::token($random, 2), self::token($random, 40), $part, $total);
            }
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function mixed(Randomizer $random, int $size, string $prefix): array
    {
        $real = self::real($random, intdiv($size + 1, 2));
        $obfuscated = $random->getInt(0, 1) === 0 ? self::kenpsx($random, intdiv($size, 2)) : self::boneless($random, intdiv($size, 2));
        $subjects = [];
        while ($real !== [] || $obfuscated !== []) {
            $from = $obfuscated === [] || ($real !== [] && $random->getInt(0, 1) === 0) ? 'real' : 'obfuscated';
            $subjects[] = $prefix.array_shift(${$from});
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function craftedWhitespace(Randomizer $random, int $size): array
    {
        $subjects = self::mixed($random, $size, '');
        $spaces = [' ', "\t", "\x0b", "\x0c", "\r"];
        for ($i = 0; $i < intdiv($size, 10); $i++) {
            $length = $random->getInt(256, 512);
            $padding = '';
            while (strlen($padding) < $length) {
                $padding .= $spaces[$random->getInt(0, 4)];
            }
            $half = intdiv($length, 2);
            $subject = match ($random->getInt(0, 4)) {
                0 => substr($padding, 0, $length - 1).')',
                1 => substr($padding, 0, $half).'x'.substr($padding, $half, $length - $half - 2).')',
                2 => substr($padding, 0, $half).str_repeat('x (1/', intdiv($length - $half, 5)),
                3 => substr($padding, 0, $length - 40).self::token($random, 32).' (2/687)',
                default => substr($padding, 0, $half)."\n".substr($padding, $half, $length - $half - 7).' (2/9)',
            };
            $subjects[$random->getInt(0, $size - 1)] = substr($subject, 0, 512);
        }

        return $subjects;
    }

    /** @return list<string> */
    private static function adversarial(Randomizer $random, int $size): array
    {
        $pieces = [' ', '  ', "\t", "\n", "\x0b", '_', '-', '/', '(', ')', '[', ']', '$', ':', '"', ' of ', '_OF_', "\xff", "\xc3\xa9",
            'Usenet Index Post', '0', '00', '7', '62', '123456', 'yEnc', '.part07.rar'];
        $subjects = [];
        for ($i = 0; $i < $size; $i++) {
            $subject = '';
            for ($piece = $random->getInt(1, 10); $piece > 0; $piece--) {
                $subject .= match ($random->getInt(0, 3)) {
                    0 => self::token($random, $random->getInt(1, 40)),
                    1 => $random->getBytes($random->getInt(1, 4)),
                    default => $pieces[$random->getInt(0, count($pieces) - 1)],
                };
            }
            $total = ['687', '0687', '1', '2', '00000000000000000000003', (string) $random->getInt(2, 99)][$random->getInt(0, 5)];
            $subjects[] = $subject.$pieces[$random->getInt(0, 4)].'('.$random->getInt(0, 700).'/'.$total.')'.($random->getInt(0, 1) ? ' yEnc' : '');
        }

        return $subjects;
    }
}
