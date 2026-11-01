<?php

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;

/** Immutable basename evidence; nomination cleaning must never establish identity. */
final readonly class OriginalFilenameEvidence
{
    public const PREDB_IDENTITY_POLICY = 'fb5caa8fd67b7e9190dc8e471532b01c6c01364b06b6f317c0a0fe19eb71e91e';

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

    /** A bounded basename that no title or alias can satisfy under the literal acceptance rule. */
    public function isLocallyRejectedPipe(): bool
    {
        return strlen($this->basename) <= 8192 && substr_count($this->basename, '.') + 1 <= 128
            && str_contains($this->basename, '|') && mb_check_encoding($this->basename, 'UTF-8')
            && preg_match('/\p{C}/u', $this->basename) === 0;
    }

    /**
     * Exhaustive bounded nomination only; current rows still require acceptance and ambiguity checks.
     * A null result is UNKNOWN, never permission to use truncated evidence or rename fallback.
     *
     * @return list<string>|null
     */
    public function canonicalNominationKeys(): ?array
    {
        $raw = $this->basename;
        $length = strlen($raw);
        if (! $this->valid($raw) || $length > 8192 || substr_count($raw, '.') + 1 > 128) {
            return null;
        }
        $keys = [];
        $unwrapper = new NzbSplitUnwrapper;
        for ($offset = 1; $offset <= $length; $offset++) {
            // Every nonempty accepted transport suffix begins with a literal dot.
            // Encoded wrapper separators belong to the title and are not cut points.
            if ($offset !== $length && $raw[$offset] !== '.') {
                continue;
            }
            $prefix = substr($raw, 0, $offset);
            $prefix = $unwrapper->literalPayload($prefix) ?? $prefix;
            if ($this->accepts($prefix)) {
                $key = PredbSearchDocument::exactValue($prefix);
                if (strlen($key) > 8192) {
                    return null;
                }
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
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
