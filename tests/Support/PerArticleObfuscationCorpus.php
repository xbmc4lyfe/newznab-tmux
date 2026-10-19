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
            'large-range' => ['category' => 'uncommon', 'description' => 'mixed range of 200k headers', 'size' => 200000],
            'small-range' => ['category' => 'uncommon', 'description' => 'mixed range of 200 headers', 'size' => 200],
            'long-subjects' => ['category' => 'uncommon', 'description' => 'mixed range with 300-byte subject prefixes', 'size' => 20000],
            'adversarial' => ['category' => 'uncommon', 'description' => 'random bytes, newlines, odd counters and invalid UTF-8', 'size' => 20000],
        ];
    }

    /** @return list<array{Number: int, Subject: string}> */
    public static function stratum(string $name, int $seed, ?int $size = null): array
    {
        $random = new Randomizer(new Mt19937($seed ^ crc32($name)));
        $size ??= self::strata()[$name]['size'];
        $subjects = match ($name) {
            'real-uploads' => self::real($random, $size),
            'kenpsx' => self::kenpsx($random, $size),
            'boneless' => self::boneless($random, $size),
            'mixed', 'large-range', 'small-range' => self::mixed($random, $size, ''),
            'long-subjects' => self::mixed($random, $size, str_repeat('Long.Prefix.Words ', 17)),
            'adversarial' => self::adversarial($random, $size),
            default => throw new \InvalidArgumentException('Unknown stratum '.$name),
        };
        $headers = [];
        foreach ($subjects as $index => $subject) {
            $headers[] = ['Number' => 5_000_000_000 + $index, 'Subject' => $subject];
        }

        return $headers;
    }

    private static function token(Randomizer $random, int $length): string
    {
        return $random->getBytesFromString(self::ALNUM, $length);
    }

    /** @return list<string> */
    private static function real(Randomizer $random, int $size): array
    {
        $subjects = [];
        while (count($subjects) < $size) {
            $files = $random->getInt(1, 40);
            $parts = $random->getInt(2, 300);
            $hashed = $random->getInt(0, 2) === 0 ? md5($random->getBytes(8)) : null;
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
