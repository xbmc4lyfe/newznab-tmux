<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class XrefService
{
    /**
     * Regex patterns to parse Xref tokens (e.g. alt.binaries.* optionally followed by :number).
     */
    private const XREF_PATTERN_WITH_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)(:\\d+)/';

    private const XREF_PATTERN_NO_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)/';

    public function __construct(private readonly bool $useNative = true) {}

    /**
     * Extracts valid Xref tokens from a space-separated Xref string.
     *
     * @return array<int|string, mixed>
     */
    public function extractTokens(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        if ($this->useNative) {
            $native = RustPipelineLibrary::xrefExtractTokens($xref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->extractTokensPhp($xref);
    }

    /**
     * Pure-PHP reference implementation of {@see extractTokens()}.
     *
     * @return list<string>
     */
    public function extractTokensPhp(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        $tokens = [];
        foreach (explode(' ', $xref) as $token) {
            if (preg_match(self::XREF_PATTERN_WITH_NUM, $token, $m) || preg_match(self::XREF_PATTERN_NO_NUM, $token, $m)) {
                $tokens[] = $m[0];
            }
        }

        return $tokens;
    }

    /**
     * Returns tokens that appear in $headerXref but not in $existingXref.
     *
     * @return list<string>
     */
    public function diffNewTokens(?string $existingXref, ?string $headerXref): array
    {
        if (empty($headerXref)) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $native = RustPipelineLibrary::xrefDiffTokens($existingXref ?? '', $headerXref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->diffNewTokensPhp($existingXref, $headerXref);
    }

    /**
     * Pure-PHP reference implementation of {@see diffNewTokens()}.
     *
     * @return list<string>
     */
    public function diffNewTokensPhp(?string $existingXref, ?string $headerXref): array
    {
        $existing = $this->extractTokensPhp($existingXref);
        $incoming = $this->extractTokensPhp($headerXref);

        return array_values(array_diff($incoming, $existing));
    }

    /** @return list<string> */
    public function extractGroupNames(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $native = RustPipelineLibrary::xrefExtractGroups($xref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->extractGroupNamesPhp($xref);
    }

    /**
     * Pure-PHP reference implementation of {@see extractGroupNames()}.
     *
     * @return list<string>
     */
    public function extractGroupNamesPhp(?string $xref): array
    {
        $groups = [];
        foreach ($this->extractTokensPhp($xref) as $token) {
            $group = preg_replace('/:\d+$/', '', (string) $token);
            if ($group !== null && $group !== '') {
                $groups[$group] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * Batch extract deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<list<string>>
     */
    public function extractGroupNamesBatch(array $xrefs): array
    {
        if ($xrefs === []) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $lines = RustPipelineLibrary::xrefExtractBatchLines($xrefs, 2);
            if ($lines !== null) {
                /** @var array<string, list<string>> $groupsCache */
                $groupsCache = ['' => []];
                $result = [];
                foreach ($lines as $line) {
                    $result[] = $groupsCache[$line] ??= explode(' ', $line);
                }

                return $result;
            }
        }

        $result = [];
        foreach ($xrefs as $xref) {
            $result[] = $this->extractGroupNames($xref);
        }

        return $result;
    }

    /**
     * Batch extract space-joined valid tokens and deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<array{xref: string, groups: list<string>}>
     */
    public function extractTokensAndGroupNamesBatch(array $xrefs): array
    {
        if ($xrefs === []) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $lines = RustPipelineLibrary::xrefExtractBatchLines($xrefs, 3);
            if ($lines !== null) {
                /** @var array<string, list<string>> $groupsCache */
                $groupsCache = ['' => []];
                $result = [];
                foreach ($lines as $line) {
                    $nulPos = strpos($line, "\0");
                    if ($nulPos === false) {
                        $result[] = ['xref' => $line, 'groups' => []];

                        continue;
                    }
                    $tokensStr = substr($line, 0, $nulPos);
                    $groupsStr = substr($line, $nulPos + 1);
                    $result[] = [
                        'xref' => $tokensStr,
                        'groups' => $groupsCache[$groupsStr] ??= explode(' ', $groupsStr),
                    ];
                }

                return $result;
            }
        }

        $result = [];
        foreach ($xrefs as $xref) {
            $tokens = $this->extractTokens($xref);
            $result[] = [
                'xref' => implode(' ', $tokens),
                'groups' => $this->extractGroupNames($xref),
            ];
        }

        return $result;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all nine runs (min 2.43x; see the W44 parity test). */
    public const bool W441_RUST_FFI_DEFAULT = true;

    public const string W441_FFI_CDEF = <<<'C'
        ssize_t nntmux_w441_xref_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Largest Wave 44.1 input frame passed to Rust. */
    public const int W441_MAX_INPUT = 16777216;

    private const array W441_SUMMARY_KEYS = ['total_xrefs', 'empty_xref_count', 'tokenized_count', 'numbered_token_count', 'multi_group_count', 'rejected_token_count'];

    private static ?bool $w441UseRustFfi = null;

    private static FFI|false|null $w441Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW441RustFfi(?bool $enable = true): void
    {
        self::$w441UseRustFfi = $enable;
    }

    public static function usesW441RustFfi(): bool
    {
        return self::$w441UseRustFfi ?? self::W441_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW441RustFfi(): void
    {
        self::$w441UseRustFfi = null;
        self::$w441Ffi = null;
    }

    /**
     * Wave 44.1 entries: strings and integers as-is, the first non-null `$fields` value of an array row,
     * otherwise ''. Objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function w441Entries(array $rows, array $fields): array
    {
        $entries = [];
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entries[] = \is_string($item) || \is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /**
     * Calls a Wave 44.1 export with the w441Entries() of `$rows` and decodes its `i64le` counts. Entries
     * are framed as `u32le count, u32le lengths, bytes`, assembled by one implode() so the entry bytes
     * are copied once and never scanned in PHP. `$ffi` is the caller's cached handle. Returns null (fall
     * back to PHP) when the frame is too large, the library or symbol is unavailable, Rust declines the
     * batch, or the output fails validation.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return array<int, int>|null
     */
    public static function w441Invoke(FFI|false|null &$ffi, string $cdef, string $symbol, array $rows, array $fields, int $keys): ?array
    {
        // Same extraction as w441Entries(), inlined to keep marshaling to one pass.
        $parts = [''];
        $lengths = [];
        $total = 0;
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entry = \is_string($item) || \is_int($item) ? (string) $item : '';
            $parts[] = $entry;
            $lengths[] = $length = \strlen($entry);
            $total += $length;
        }
        $count = \count($lengths);
        if ($total + 4 * $count + 4 > self::W441_MAX_INPUT) {
            return null;
        }
        $parts[0] = pack('V*', $count, ...$lengths);
        $input = implode('', $parts);
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w441', $cdef) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, $keys * 8, $capacity);
            if ($out === null) {
                return null;
            }
            $written = $ffi->{$symbol}($input, \strlen($input), $out, $capacity);
            if ($written !== $keys * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $written));
            if (! \is_array($values) || $values[1] !== $count || min($values) < 0 || max($values) > $count) {
                return null;
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Summarizes Xref headers with extractTokensPhp() and extractGroupNamesPhp(): headers with a valid
     * token, a token carrying an article number, two or more distinct groups, or a non-empty
     * space-separated piece that is not a valid token. `''` and `'0'` count as empty, as in extractTokens().
     * Entries come from strings, integers, or the first non-null xref, Xref array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeXrefBatch(array $rows): array
    {
        return self::usesW441RustFfi() ? self::summarizeXrefBatchNative($rows) : self::summarizeXrefBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeXrefBatchNative(array $rows): array
    {
        $values = self::w441Invoke(self::$w441Ffi, self::W441_FFI_CDEF, 'nntmux_w441_xref_batch', $rows, ['xref', 'Xref'], \count(self::W441_SUMMARY_KEYS));

        return $values === null ? self::summarizeXrefBatchPhp($rows) : array_combine(self::W441_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeXrefBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W441_SUMMARY_KEYS, 0);
        foreach (self::w441Entries($rows, ['xref', 'Xref']) as $entry) {
            $summary['total_xrefs']++;
            if ($entry === '' || $entry === '0') {
                $summary['empty_xref_count']++;

                continue;
            }
            // One pass with the extractTokensPhp() patterns and the extractGroupNamesPhp() suffix rule.
            $tokens = $numbered = $rejected = false;
            $groups = [];
            foreach (explode(' ', $entry) as $piece) {
                if (preg_match(self::XREF_PATTERN_WITH_NUM, $piece, $m) || preg_match(self::XREF_PATTERN_NO_NUM, $piece, $m)) {
                    $tokens = true;
                    $group = (string) preg_replace('/:\d+$/', '', $m[0]);
                    $numbered = $numbered || $group !== $m[0];
                    $groups[$group] = true;
                } elseif ($piece !== '') {
                    $rejected = true;
                }
            }
            $summary['tokenized_count'] += (int) $tokens;
            $summary['numbered_token_count'] += (int) $numbered;
            $summary['multi_group_count'] += (int) (\count($groups) > 1);
            $summary['rejected_token_count'] += (int) $rejected;
        }

        return $summary;
    }
}
