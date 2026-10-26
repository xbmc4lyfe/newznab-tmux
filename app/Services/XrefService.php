<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ========================================================================
    // WAVE 62.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 3.44x, p99 2.82x; uncommon mean 3.35x, p99 2.79x); see RustObfuscationRouterReleaseCreationExtraProcessingXrefW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_XREF_FFI_CDEF = <<<'C'
        ssize_t nntmux_w621_xref_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w62XrefPlanBatch(). */
    public const int W62_XREF_TRAILER = 17;

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the XrefService Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.1 `Xref:` header plans. Each item is a header value (trimmed; a case-insensitive `Xref:` prefix is
     * dropped with the spaces and tabs after it). Tokens are separated by runs of spaces and tabs; a first token
     * without `:` is the server. Every other token is `group:article` split at its last `:`, valid when the
     * article is 1-18 digits and the group starts with an ASCII alphanumeric and holds only alphanumerics and
     * `+_.-`; otherwise it is rejected. Groups are deduplicated case-insensitively (dupes = valid - distinct);
     * binary groups start with `alt.binaries.` or `a.b.`. The status is 0 empty, 3 any rejected token, 2 no valid
     * token, else 1. Flags: 1 non-ASCII, 2 tab separator, 4 article 0, 8 an uppercase group. Per item `status |
     * server << 2 | prefix << 3 | min(valid, 63) << 4 | min(distinct, 63) << 10 | min(dupes, 63) << 16 |
     * min(rejected, 63) << 22 | min(binary, 63) << 28 | bitLength(maxArticle) << 34 | min(longestGroup, 255) << 40
     * | min(length, 1023) << 48 | flags << 58`; then 4 status counts, the server and prefix counts, the valid,
     * distinct, dupe, rejected and binary sums, 4 flag counts, the distinct groups across the batch and the
     * largest per-header distinct count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62XrefPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62XrefPlanBatchNative($items) : self::w62XrefPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62XrefPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_XREF_TRAILER) ?? self::w62XrefPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62XrefPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W62_XREF_TRAILER, 0);
        $groups = [];
        foreach ($items as $item) {
            $s = trim((string) $item);
            $prefix = 0;
            if (strncasecmp($s, 'xref:', 5) === 0) {
                $prefix = 1;
                $s = ltrim(substr($s, 5), " \t");
            }
            $flags = (preg_match('/[\x80-\xff]/', $s) === 1 ? 1 : 0) | (str_contains($s, "\t") ? 2 : 0);
            $local = [];
            $server = $valid = $rejected = $binary = $maxArticle = $longest = 0;
            $first = true;
            foreach (preg_split('/[ \t]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $colon = strrpos($token, ':');
                if ($first) {
                    $first = false;
                    if ($colon === false) {
                        $server = 1;

                        continue;
                    }
                }
                if ($colon === false) {
                    $rejected++;

                    continue;
                }
                $group = substr($token, 0, $colon);
                $article = substr($token, $colon + 1);
                $articleLength = \strlen($article);
                $groupLength = \strlen($group);
                if ($articleLength === 0 || $articleLength > 18 || strspn($article, '0123456789') !== $articleLength || $groupLength === 0
                    || strspn($group, self::W62_ALNUM, 0, 1) !== 1 || strspn($group, self::W62_GROUP_CHARS) !== $groupLength) {
                    $rejected++;

                    continue;
                }
                $valid++;
                $value = (int) $article;
                $flags |= ($value === 0 ? 4 : 0) | (strpbrk($group, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false ? 8 : 0);
                $maxArticle = max($maxArticle, $value);
                $longest = max($longest, $groupLength);
                $lower = strtolower($group);
                if (str_starts_with($lower, 'alt.binaries.') || str_starts_with($lower, 'a.b.')) {
                    $binary++;
                }
                $local[$lower] = true;
                $groups[$lower] = true;
            }
            $distinct = \count($local);
            $dupes = $valid - $distinct;
            $status = match (true) {
                $s === '' => 0,
                $rejected > 0 => 3,
                $valid === 0 => 2,
                default => 1,
            };
            $bits = 0;
            for ($v = $maxArticle; $v > 0; $v >>= 1) {
                $bits++;
            }
            $tail[$status]++;
            $tail[4] += $server;
            $tail[5] += $prefix;
            $tail[6] += $valid;
            $tail[7] += $distinct;
            $tail[8] += $dupes;
            $tail[9] += $rejected;
            $tail[10] += $binary;
            for ($b = 0; $b < 4; $b++) {
                $tail[11 + $b] += $flags >> $b & 1;
            }
            $tail[16] = max($tail[16], $distinct);
            $out[] = $status | $server << 2 | $prefix << 3 | min($valid, 63) << 4 | min($distinct, 63) << 10 | min($dupes, 63) << 16
                | min($rejected, 63) << 22 | min($binary, 63) << 28 | $bits << 34 | min($longest, 255) << 40 | min(\strlen($s), 1023) << 48 | $flags << 58;
        }
        $tail[15] = \count($groups);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W62_ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const string W62_GROUP_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+_.-';

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('xref_w621', self::W62_XREF_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w62RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 62.1 XrefService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_XREF_FFI_CDEF.)
            $written = $ffi->nntmux_w621_xref_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
