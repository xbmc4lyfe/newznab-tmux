<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

final class NzbSplitUnwrapper
{
    private const string WRAPPER_PATTERN = '/__NZBSPLIT__([a-f0-9]{8,})__NZBSPLIT__(.+)$/i';

    /**
     * @var list<string>
     */
    private const array TRAILING_ARCHIVE_PATTERNS = [
        '/\.part\d+\.rar$/i',
        '/\.r\d{2,4}$/i',
        '/\.7z\.\d{2,4}$/i',
        '/\.7z$/i',
        '/\.rar$/i',
        '/\.par2?$/i',
        '/\.vol\d+[+\-]\d+\.par2?$/i',
        '/\.\d{3}$/',
    ];

    /**
     * @var list<string>
     */
    private const array TRAILING_SUBJECT_PATTERNS = [
        '/"\s*yEnc(?:\s*\([^)]*\))?$/i',
        '/\s+yEnc(?:\s*\([^)]*\))?$/i',
        '/"\s*yEnc.*$/i',
        '/\s+yEnc.*$/i',
    ];

    /** Decode a single wrapper's literal payload; the caller owns suffix handling. */
    public function literalPayload(string $value): ?string
    {
        $first = stripos($value, '__NZBSPLIT__');
        if ($first === false) {
            return null;
        }
        $second = stripos($value, '__NZBSPLIT__', $first + 1);
        if ($second === false || stripos($value, '__NZBSPLIT__', $second + 1) !== false
            || preg_match('/\A__NZBSPLIT__(?:[a-f0-9]{8,})__NZBSPLIT__(.+)\z/isD', substr($value, $first), $matches) !== 1) {
            return null;
        }

        // Separator encoding is defined by the wrapper. Preserve every other payload byte.
        return str_replace(['_', '+'], ['.', ' '], $matches[1]);
    }

    public function unwrap(string $value): ?string
    {
        if (! preg_match(self::WRAPPER_PATTERN, $value, $matches)) {
            return null;
        }

        $title = $matches[2];
        $didStrip = true;

        while ($didStrip) {
            $didStrip = false;

            foreach (self::TRAILING_SUBJECT_PATTERNS as $pattern) {
                $updated = preg_replace($pattern, '', $title);

                if ($updated !== null && $updated !== $title) {
                    $title = rtrim($updated, " \t\n\r\0\x0B\"'");
                    $didStrip = true;
                }
            }

            foreach (self::TRAILING_ARCHIVE_PATTERNS as $pattern) {
                $updated = preg_replace($pattern, '', $title);

                if ($updated !== null && $updated !== $title) {
                    $title = $updated;
                    $didStrip = true;
                }
            }
        }

        $title = str_replace(['_', '+'], ['.', ' '], $title);
        $title = preg_replace('/\.{2,}/', '.', $title) ?? $title;
        $title = preg_replace('/\s{2,}/', ' ', $title) ?? $title;
        $title = preg_replace('/\.\s+/', '.', $title) ?? $title;
        $title = preg_replace('/\s+\./', '.', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B.-_");

        return $title !== '' ? $title : null;
    }
}
