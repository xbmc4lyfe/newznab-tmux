<?php

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;

/** Immutable basename evidence; nomination cleaning must never establish identity. */
final readonly class OriginalFilenameEvidence
{
    public string $basename;

    public string $stem;

    public function __construct(string $filename)
    {
        $this->basename = preg_replace('~^.*[\\\\/]~s', '', $filename) ?? '';
        // Preserve the guard's existing support for multipart numbers longer than the shared helper allows.
        $longMultipartStem = preg_replace('/\.part[0-9]{5,}\.rar$/iD', '', $this->basename) ?? $this->basename;
        $this->stem = $longMultipartStem !== $this->basename
            ? $longMultipartStem
            : (new FileNameCleaner)->stripFileSuffixes($this->basename);
    }

    public function accepts(string $title, string $alias = ''): bool
    {
        if (! $this->valid($this->basename) || ! $this->valid($title)) {
            return false;
        }
        if ($this->same($title, $this->basename) || $this->same($title, $this->stem)) {
            return true;
        }
        if ($alias === '') {
            return false;
        }
        $other = new self($alias);

        return $this->valid($other->basename) && $this->valid($this->stem)
            && preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', $this->stem) === 0
            && $this->same($this->stem, $other->stem);
    }

    private function valid(string $value): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\p{C}\\\\\/|]/u', $value) === 0;
    }

    private function same(string $left, string $right): bool
    {
        return PredbSearchDocument::exactValue($left) === PredbSearchDocument::exactValue($right);
    }
}
