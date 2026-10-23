<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Support\RustPipelineLibrary;

/**
 * Incremental parser for `7z l -slt -ba` output, fed in arbitrary chunks.
 *
 * Lines end only at LF, with one optional CR before it, and records end at blank lines.
 * Nothing else counts as a line break: the output is raw UTF-8 bytes, and Unicode-aware
 * line matching such as byte-mode PCRE `\R` would also break at the 0x85 byte inside
 * characters like "Å" (C3 85) or "久" (E4 B9 85).
 *
 * A record is rejected, and the listing marked unreliable, when it can't be read
 * unambiguously: a line that isn't `Key = value`, a property given twice, a Path with
 * control characters, or no Path or Size at all. Such output most likely comes from a
 * file name with a line break in it, so the record boundaries can't be trusted; callers
 * keep the other entries but must not treat the listing as complete.
 */
final class SevenZipListingParser
{
    /** @var list<array{name: string, size: int, date: int, pass: int, compressed: int, crc32?: string}> */
    public array $files = [];

    /** False once any record had to be rejected. */
    public bool $reliable = true;

    private string $partialLine = '';

    /** @var array<string, string> */
    private array $record = [];

    private bool $recordMalformed = false;

    /** @var array<string, int> */
    private array $modifiedCache = [];

    public function __construct(private readonly ?string $libraryPath = null) {}

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        if ($this->record === [] && ! $this->recordMalformed && RustPipelineLibrary::available($this->libraryPath)) {
            $this->partialLine .= $chunk;
            if (! str_contains($chunk, "\n") || ! self::hasPotentialBlankLine($this->partialLine)) {
                return;
            }
            $raw = RustPipelineLibrary::sevenZipParseListing($this->partialLine, false, $this->libraryPath);
            if ($raw !== null) {
                if ($raw !== '') {
                    $this->applyRustListing($raw);
                }

                return;
            }
            $pending = $this->partialLine;
            $this->partialLine = '';
            $this->feedPhp($pending);

            return;
        }

        $this->feedPhp($chunk);
    }

    public function feedPhp(string $chunk): void
    {
        $start = 0;
        while (($end = strpos($chunk, "\n", $start)) !== false) {
            $this->line($this->partialLine.substr($chunk, $start, $end - $start));
            $this->partialLine = '';
            $start = $end + 1;
        }
        $this->partialLine .= substr($chunk, $start);
    }

    /**
     * End of output: the last line and record count even without a trailing line break.
     */
    public function finish(): void
    {
        if ($this->record === [] && ! $this->recordMalformed && RustPipelineLibrary::available($this->libraryPath)) {
            if ($this->partialLine === '') {
                return;
            }
            $raw = RustPipelineLibrary::sevenZipParseListing($this->partialLine, true, $this->libraryPath);
            if ($raw !== null) {
                if ($raw !== '') {
                    $this->applyRustListing($raw);
                }
                $this->partialLine = '';

                return;
            }
            $pending = $this->partialLine;
            $this->partialLine = '';
            $this->feedPhp($pending);
            $this->finishPhp();

            return;
        }

        $this->finishPhp();
    }

    public function finishPhp(): void
    {
        if ($this->partialLine !== '') {
            $this->line($this->partialLine);
            $this->partialLine = '';
        }
        $this->endRecord();
    }

    private static function hasPotentialBlankLine(string $buf): bool
    {
        if ($buf === '') {
            return false;
        }
        $first = $buf[0];
        if ($first === "\n" || $first === "\r" || $first === ' ' || $first === "\t") {
            return true;
        }

        return str_contains($buf, "\n\n")
            || str_contains($buf, "\n\r\n")
            || str_contains($buf, "\n ")
            || str_contains($buf, "\n\t");
    }

    private function applyRustListing(string $raw): void
    {
        $tokens = explode("\0", $raw);
        $consumed = (int) $tokens[0];
        if (($tokens[1] ?? '1') === '0') {
            $this->reliable = false;
        }
        $this->partialLine = $consumed >= strlen($this->partialLine)
            ? ''
            : substr($this->partialLine, $consumed);

        $count = count($tokens) - 1;
        for ($i = 2; $i + 4 < $count; $i += 5) {
            $modified = $tokens[$i + 2];
            $date = $modified === '' ? 0 : ($this->modifiedCache[$modified] ??= (int) strtotime($modified));
            $flags = ord($tokens[$i + 3]) - 48;
            $file = [
                'name' => $tokens[$i],
                'size' => (int) $tokens[$i + 1],
                'date' => $date,
                'pass' => $flags & 1,
                'compressed' => ($flags >> 1) & 1,
            ];
            if ($tokens[$i + 4] !== '') {
                $file['crc32'] = $tokens[$i + 4];
            }
            $this->files[] = $file;
        }
    }

    private function line(string $line): void
    {
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }
        if (strspn($line, " \t") === strlen($line)) {
            $this->endRecord();

            return;
        }

        $separator = strpos($line, ' = ');
        if ($separator !== false) {
            $key = substr($line, 0, $separator);
            $value = substr($line, $separator + 3);
        } elseif (str_ends_with($line, ' =')) {
            $key = substr($line, 0, -2);
            $value = '';
        } else {
            $this->recordMalformed = true;

            return;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9 ()._-]*$/', $key) !== 1 || array_key_exists($key, $this->record)) {
            $this->recordMalformed = true;

            return;
        }
        $this->record[$key] = $value;
    }

    private function endRecord(): void
    {
        $entry = $this->record;
        $malformed = $this->recordMalformed;
        $this->record = [];
        $this->recordMalformed = false;
        if ($entry === [] && ! $malformed) {
            return;
        }

        $path = $entry['Path'] ?? '';
        // C0 controls, DEL, and C1 controls in their UTF-8 form (C2 80 - C2 9F).
        if ($malformed || $path === '' || ! array_key_exists('Size', $entry)
            || preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $path) === 1
        ) {
            $this->reliable = false;

            return;
        }

        if (($entry['Folder'] ?? '') === '+' || str_starts_with($entry['Attributes'] ?? '', 'D')) {
            return;
        }

        $file = [
            'name' => $path,
            'size' => (int) $entry['Size'],
            'date' => (int) strtotime(substr($entry['Modified'] ?? '', 0, 19)),
            'pass' => ($entry['Encrypted'] ?? '') === '+' ? 1 : 0,
            'compressed' => in_array($entry['Method'] ?? '', ['', 'Copy'], true) ? 0 : 1,
        ];
        if (preg_match('/^[0-9A-F]{1,8}$/i', $entry['CRC'] ?? '') === 1) {
            $file['crc32'] = dechex((int) hexdec($entry['CRC']));
        }

        $this->files[] = $file;
    }
}
