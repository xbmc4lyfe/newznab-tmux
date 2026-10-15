<?php

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;

/** Immutable basename evidence; nomination cleaning must never establish identity. */
final readonly class OriginalFilenameEvidence
{
    public string $basename;

    public string $stem;

    private string $normalizedLiteral;

    private bool $literalIsAscii;

    public function __construct(string $filename)
    {
        $unwrapper = new NzbSplitUnwrapper;
        $source = $unwrapper->stripLiteralSubjectTrailer($filename);
        if (stripos($source, '__NZBSPLIT__') !== false) {
            // A wrapper's payload is a filename, never a path. Validate before basename extraction
            // can discard malformed transport text or an invalid wrapper before a slash.
            $payload = $unwrapper->literalPayload($source);
            if ($payload === null || strpbrk($payload, '/\\') !== false) {
                $this->basename = '';
                $this->stem = '';
                $this->normalizedLiteral = '';
                $this->literalIsAscii = false;

                return;
            }
        }
        $this->basename = preg_replace('~^.*[\\\\/]~s', '', $source) ?? '';
        $stem = (new FileNameCleaner)->stripFileSuffixes($this->basename);
        // Strip wrappers first, then decode only the protocol's separator encoding.
        $this->stem = (new NzbSplitUnwrapper)->literalPayload($stem) ?? $stem;
        $literal = $unwrapper->literalPayload($this->basename) ?? $this->basename;
        $this->literalIsAscii = preg_match('/\A[\x00-\x7f]*\z/D', $literal) === 1;
        $this->normalizedLiteral = $this->literalIsAscii ? strtolower($literal) : PredbSearchDocument::exactValue($literal);
    }

    public function accepts(string $title, string $alias = ''): bool
    {
        if (! $this->valid($this->basename) || ! $this->valid($title)) {
            return false;
        }
        if ($this->acceptsCanonical($title)) {
            return true;
        }
        if ($alias === '' || ! $this->valid($this->stem)
            || preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', $this->stem) !== 0) {
            return false;
        }
        $other = new self($alias);
        if (! $this->valid($other->basename)) {
            return false;
        }

        foreach ([$this->stem, $other->stem] as $identity) {
            if ($this->valid($identity)
                && preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', $identity) === 0
                && $this->acceptsCanonical($identity) && $other->acceptsCanonical($identity)) {
                return true;
            }
        }

        return false;
    }

    private function valid(string $value): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\p{C}\\\\\/|]/u', $value) === 0;
    }

    /** Validate only raw suffix bytes after the complete case-folded nominated title. */
    private function acceptsCanonical(string $title): bool
    {
        $identity = PredbSearchDocument::exactValue($title);
        $literal = $this->normalizedLiteral;
        // Lowercasing the complete filename can change contextual characters in its title
        // (Greek final sigma before an extension). Use it only to locate a candidate byte
        // boundary; identity is checked on the isolated raw prefix below.
        if ($identity === '' || strlen($literal) < strlen($identity)) {
            return false;
        }
        if (! str_starts_with($literal, $identity) && $this->literalIsAscii) {
            return false;
        }
        $foldedSuffix = substr($literal, strlen($identity));
        // Known suffix grammar is ASCII. Its byte count survives case folding and separator
        // decoding even when an earlier Unicode title character expands during lowercasing.
        if (preg_match('/\A[\x00-\x7f]*\z/D', $foldedSuffix) !== 1) {
            return false;
        }
        $suffixLength = strlen($foldedSuffix);
        $suffix = $suffixLength === 0 ? '' : substr($this->basename, -$suffixLength);
        if (preg_match('/\A[\x00-\x7f]*\z/D', $suffix) !== 1) {
            return false;
        }
        $prefix = $suffixLength === 0 ? $this->basename : substr($this->basename, 0, -$suffixLength);
        $prefix = (new NzbSplitUnwrapper)->literalPayload($prefix) ?? $prefix;
        if (PredbSearchDocument::exactValue($prefix) !== $identity) {
            return false;
        }

        return (new FileNameCleaner)->stripFileSuffixes('Title'.$suffix) === 'Title';
    }
}
