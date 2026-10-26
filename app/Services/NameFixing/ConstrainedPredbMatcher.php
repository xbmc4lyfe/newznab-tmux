<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Bounded identity suggestions; partial evidence never authorizes a PreDB link.
 *
 * @phpstan-type Parsed array{title: list<string>, markers: array<string, string>, suffix: list<string>}
 */
final class ConstrainedPredbMatcher
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_constrained_predb_parse(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_constrained_predb_suggest_batch(const char *query, size_t query_len, const char *hits_nul, size_t hits_len, size_t hit_count, double *out_f64);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $outF64 = null;

    private static bool $useRustFfi = true;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @return Parsed|null */
    public function parse(string $name): ?array
    {
        if ($this->useNative && self::$useRustFfi) {
            $supported = false;
            $native = $this->parseNative($name, $supported);
            if ($supported) {
                return $native;
            }
        }

        return $this->parsePhp($name);
    }

    /**
     * @param-out bool $supported
     *
     * @return Parsed|null
     */
    public function parseNative(string $name, bool &$supported = false): ?array
    {
        $supported = false;
        if (! $this->useNative || ! self::$useRustFfi) {
            return null;
        }
        $len = \strlen($name);
        if ($len === 0 || $len > 512) {
            $supported = true;

            return null;
        }
        $ffi = self::resolveFfi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 1024, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_constrained_predb_parse($name, $len, $scratch, $cap);
            if (! \is_int($written)) {
                return null;
            }
            if ($written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_constrained_predb_parse($name, $len, $scratch, $cap);
                if (! \is_int($written)) {
                    return null;
                }
            }
            if ($written === -2) {
                $supported = true;

                return null;
            }
            if ($written < 0) {
                return null;
            }
            $supported = true;
            $raw = $written === 0 ? '' : FFI::string($scratch, $written);
            $parts = \explode("\n", $raw, 3);
            $titleLine = $parts[0] ?? '';
            $markersLine = $parts[1] ?? '';
            $suffixLine = $parts[2] ?? '';

            $title = $titleLine === ''
                ? []
                : ($titleLine === "\x01" ? [''] : \explode(' ', $titleLine));
            $markers = [];
            if ($markersLine !== '') {
                foreach (\explode("\t", $markersLine) as $pair) {
                    $kv = \explode(':', $pair, 2);
                    if (isset($kv[1])) {
                        $markers[$kv[0]] = $kv[1];
                    }
                }
            }
            $suffix = $suffixLine === '' ? [] : \explode(' ', $suffixLine);

            return ['title' => $title, 'markers' => $markers, 'suffix' => $suffix];
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    /** @return Parsed|null */
    public function parsePhp(string $name): ?array
    {
        if (strlen($name) > 512 || ! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = PredbIdentityKey::normalize($name);
        if ($name === null) {
            return null;
        }
        $name = preg_replace('/\.(?:mkv|mp4|avi|rar|nfo|par2)$/', '', $name);
        $markers = [];
        if (preg_match('/-([a-z0-9]{2,20})$/', $name, $m)) {
            $markers['group'] = $m[1];
            $name = substr($name, 0, -strlen($m[0]));
        }
        $name = preg_replace('/[^\pL\pN]+/u', ' ', $name);
        $tokens = preg_split('/\s+/', trim($name));
        if (count($tokens) > 64) {
            return null;
        }
        $title = [];
        $suffix = [];
        $afterBoundary = false;
        foreach ($tokens as $token) {
            $key = null;
            if (preg_match('/^(?:19|20)\d{2}$/', $token)) {
                $key = 'year';
            } elseif (preg_match('/^s\d{1,2}(?:e\d{1,3})+$/', $token)) {
                $key = 'episode';
                $token = preg_replace_callback('/\d+/', static fn (array $m): string => (string) (int) $m[0], $token);
            } elseif (preg_match('/^s\d{1,2}$/', $token)) {
                $key = 'season';
            } elseif (preg_match('/^(?:480|576|720|1080|2160)[pi]$/', $token)) {
                $key = 'resolution';
            } elseif (in_array($token, ['english', 'german', 'french', 'finnish', 'japanese', 'dutch', 'spanish', 'italian', 'multi', 'dual'])) {
                $key = 'language';
            } elseif (in_array($token, ['extended', 'unrated', 'directors', 'theatrical', 'remastered', 'repack', 'proper'])) {
                $key = 'edition';
            } elseif ($token === 'xxx') {
                $key = 'adult';
            }
            if ($key !== null) {
                $markers[$key] = isset($markers[$key]) ? $markers[$key].' '.$token : $token;
                $afterBoundary = true;

                continue;
            }
            if (in_array($token, ['bluray', 'bdrip', 'web', 'webdl', 'webrip', 'hdtv', 'x264', 'x265', 'h264', 'h265', 'hevc', 'avc', 'ddp', 'dts', 'hdr', 'dovi', 'aac', 'mp4'])) {
                $afterBoundary = true;
            }
            if ($afterBoundary) {
                $suffix[] = $token;
            }
            if (! $afterBoundary && ! in_array($token, ['the', 'a', 'an', 'and', 'of'])) {
                $title[] = $token;
            }
        }

        return ['title' => $title, 'markers' => $markers, 'suffix' => $suffix];
    }

    /** @param list<array{id: int, title: string}> $hits
     * @return array<string, mixed>
     */
    public function suggest(string $query, array $hits): array
    {
        if (\count($hits) > 50) {
            return ['id' => null, 'reason' => 'candidate_overflow'];
        }
        if ($this->useNative && self::$useRustFfi) {
            $native = $this->suggestNative($query, $hits);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->suggestPhp($query, $hits);
    }

    /**
     * @param  list<array{id: int, title: string}>  $hits
     * @return array<string, mixed>|null
     */
    public function suggestNative(string $query, array $hits): ?array
    {
        return $this->evaluateBatchNative($query, $hits, requireCompleteEvidence: false);
    }

    /** @param list<array{id: int, title: string}> $hits
     * @return array<string, mixed>
     */
    public function suggestPhp(string $query, array $hits): array
    {
        if (count($hits) > 50) {
            return ['id' => null, 'reason' => 'candidate_overflow'];
        }
        $q = $this->parsePhp($query);
        if ($q === null || count($q['title']) < 2 || strlen(implode('', $q['title'])) < 9) {
            return ['id' => null, 'reason' => 'insufficient_title'];
        }
        if (! isset($q['markers']['year']) && ! isset($q['markers']['episode'])) {
            return ['id' => null, 'reason' => 'missing_identity_anchor'];
        }
        $ranked = [];
        foreach ($hits as $hit) {
            if ((int) ($hit['id'] ?? 0) <= 0 || ! is_string($hit['title'] ?? null)) {
                continue;
            }
            $c = $this->parsePhp($hit['title']);
            if ($c === null || count($c['title']) !== count($q['title'])) {
                continue;
            }
            foreach ($q['markers'] as $key => $value) {
                if (($c['markers'][$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            // Missing cut/language/adult metadata must not silently select a specific variant.
            if ($q['suffix'] !== [] && $q['suffix'] !== $c['suffix']) {
                continue;
            }
            foreach (['year', 'edition', 'language', 'adult'] as $key) {
                if (($c['markers'][$key] ?? null) !== ($q['markers'][$key] ?? null)) {
                    continue 2;
                }
            }
            $sum = 0.0;
            $exact = 0;
            $edits = 0;
            foreach ($q['title'] as $i => $token) {
                $candidateToken = $c['title'][$i];
                $distance = levenshtein($token, $candidateToken);
                if ($distance === 0) {
                    $exact++;
                    $sum += 1;

                    continue;
                }
                // Numeric sequel identity and short words never receive edit tolerance.
                $length = max(strlen($token), strlen($candidateToken));
                if (ctype_digit($token) || ctype_digit($candidateToken) || min(strlen($token), strlen($candidateToken)) < 5 || $distance > ($length >= 10 ? 2 : 1)) {
                    continue 2;
                }
                $edits += $distance;
                $sum += 1 - $distance / $length;
            }
            $score = 100 * $sum / count($q['title']);
            if ($exact < 1 || $edits > 2 || $score < 90) {
                continue;
            }
            // Keep different row IDs distinct: normalized spelling does not prove a globally unique release.
            $ranked[(int) $hit['id']] = ['id' => (int) $hit['id'], 'score' => round($score, 3), 'title' => $hit['title']];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        if ($ranked === []) {
            return ['id' => null, 'reason' => 'constraints_or_score'];
        }
        if (isset($ranked[1]) && $ranked[0]['score'] - $ranked[1]['score'] < 8) {
            return ['id' => null, 'reason' => 'ambiguous', 'ranked' => array_slice($ranked, 0, 2)];
        }

        return $ranked[0] + ['reason' => 'constrained_match'];
    }

    /** Complete marker/suffix agreement is still only a suggestion, never global identity proof.
     * @param  list<array{id: int, title: string}>  $hits
     * @return array<string, mixed>
     */
    public function completeEvidence(string $query, array $hits): array
    {
        if (\count($hits) > 50) {
            return ['id' => null, 'reason' => 'candidate_overflow'];
        }
        if ($this->useNative && self::$useRustFfi) {
            $native = $this->completeEvidenceNative($query, $hits);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->completeEvidencePhp($query, $hits);
    }

    /**
     * @param  list<array{id: int, title: string}>  $hits
     * @return array<string, mixed>|null
     */
    public function completeEvidenceNative(string $query, array $hits): ?array
    {
        return $this->evaluateBatchNative($query, $hits, requireCompleteEvidence: true);
    }

    /**
     * @param  list<array{id: int, title: string}>  $hits
     * @return array<string, mixed>
     */
    public function completeEvidencePhp(string $query, array $hits): array
    {
        $result = $this->suggestPhp($query, $hits);
        if ($result['id'] === null) {
            return $result;
        }
        $q = $this->parsePhp($query);
        $c = $this->parsePhp($result['title']);
        if ($q === null || $c === null) {
            return ['id' => null, 'reason' => 'invalid_identity'];
        }
        ksort($q['markers']);
        ksort($c['markers']);
        if ($q['markers'] !== $c['markers'] || $q['suffix'] !== $c['suffix']) {
            return ['id' => null, 'reason' => 'unresolved_release_variant', 'suggestion' => $result];
        }

        return $result;
    }

    /**
     * @param  list<array{id: int, title: string}>  $hits
     * @return array<string, mixed>|null
     */
    private function evaluateBatchNative(string $query, array $hits, bool $requireCompleteEvidence): ?array
    {
        if (! $this->useNative || ! self::$useRustFfi) {
            return null;
        }
        if (\count($hits) > 50) {
            return ['id' => null, 'reason' => 'candidate_overflow'];
        }
        $qLen = \strlen($query);
        if ($qLen === 0 || $qLen > 512) {
            return ['id' => null, 'reason' => 'insufficient_title'];
        }
        $ffi = self::resolveFfi();
        $out = self::$outF64;
        if ($ffi === null || $out === null) {
            return null;
        }

        $validHits = [];
        $parts = [];
        foreach ($hits as $hit) {
            if (! \is_array($hit)) {
                continue;
            }
            $id = (int) ($hit['id'] ?? 0);
            $title = $hit['title'] ?? null;
            if ($id <= 0 || ! \is_string($title)) {
                continue;
            }
            $validHits[] = ['id' => $id, 'title' => $title];
            $parts[] = $id."\0".$title;
        }

        $validCount = \count($validHits);
        $packed = $validCount === 0 ? '' : \implode("\0", $parts);

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_constrained_predb_suggest_batch(
                $query,
                $qLen,
                $packed,
                \strlen($packed),
                $validCount,
                $out,
            );
            if (! \is_int($status) || $status < 0) {
                return null;
            }
            if ($status === 0) {
                return ['id' => null, 'reason' => 'candidate_overflow'];
            }
            if ($status === 1) {
                return ['id' => null, 'reason' => 'insufficient_title'];
            }
            if ($status === 2) {
                return ['id' => null, 'reason' => 'missing_identity_anchor'];
            }
            if ($status === 3) {
                return ['id' => null, 'reason' => 'constraints_or_score'];
            }
            if ($status === 4) {
                $i0 = (int) $out[0];
                $i1 = (int) $out[1];
                if (! isset($validHits[$i0], $validHits[$i1])) {
                    return null;
                }

                return [
                    'id' => null,
                    'reason' => 'ambiguous',
                    'ranked' => [
                        [
                            'id' => $validHits[$i0]['id'],
                            'score' => round((float) $out[2], 3),
                            'title' => $validHits[$i0]['title'],
                        ],
                        [
                            'id' => $validHits[$i1]['id'],
                            'score' => round((float) $out[3], 3),
                            'title' => $validHits[$i1]['title'],
                        ],
                    ],
                ];
            }
            if ($status === 5 || $status === 6) {
                $i0 = (int) $out[0];
                if (! isset($validHits[$i0])) {
                    return null;
                }
                $match = [
                    'id' => $validHits[$i0]['id'],
                    'score' => round((float) $out[2], 3),
                    'title' => $validHits[$i0]['title'],
                    'reason' => 'constrained_match',
                ];
                if ($requireCompleteEvidence && $status === 6) {
                    return [
                        'id' => null,
                        'reason' => 'unresolved_release_variant',
                        'suggestion' => $match,
                    ];
                }

                return $match;
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('constrained_predb_matcher', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outF64 = $ffi->new('double[4]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    // ---- Wave 58.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.88x/5.15x, uncommon 2.96x/3.09x); see RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest. */
    private const bool W58_RUST_FFI_DEFAULT = true;

    private const string W58_FFI_CDEF = <<<'C'
        intptr_t nntmux_w58_predb_candidate_rank_batch(const char *query, size_t query_len, int64_t query_size, int64_t query_files, const char *titles, size_t titles_len, const char *sizes, size_t sizes_len, const char *files, size_t files_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 58 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w58', self::W58_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w58RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with rankCandidateBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w58Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w58Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** Sizes outside 1..2^47 never fall in the window. */
    private const int W58_SIZE_LIMIT = 1 << 47;

    /** Set bits of a 4-bit flag field. */
    private const array W58_POPCOUNT = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

    /**
     * Batch constraint evaluation and ranking of PreDB candidates for one query. Per candidate
     * `flags | min(overlap, 255) << 8 | score << 16 | rank << 24`. Flags: 1 both sizes in
     * 1..2^47 within 10% of the larger, 2 a positive query file count equal to the candidate's,
     * 4 equal release groups (after the last `-`, 2-20 ASCII alphanumerics, case-insensitive),
     * 8 equal first `(19|20)YY` tokens, 16 eligible (score >= 50, size window, and file count
     * when the query has one). Score: Dice similarity over distinct lowercase `[a-z0-9]` tokens,
     * `intdiv(200 * overlap, |Q| + |C|)`. Rank: stable order by eligibility, score and set-flag
     * count, all descending. Then the eligible count, the best eligible index (or -1), its score
     * (or 0), |Q| and the XXH64 digest of all earlier words.
     *
     * @param  list<string>  $titles
     * @param  list<int>  $sizes
     * @param  list<int>  $files
     * @return list<int>
     */
    public static function rankCandidateBatch(string $query, int $querySize, int $queryFiles, array $titles, array $sizes, array $files): array
    {
        return self::usesW58RustFfi()
            ? self::rankCandidateBatchNative($query, $querySize, $queryFiles, $titles, $sizes, $files)
            : self::rankCandidateBatchPhp($query, $querySize, $queryFiles, $titles, $sizes, $files);
    }

    /**
     * @param  list<string>  $titles
     * @param  list<int>  $sizes
     * @param  list<int>  $files
     * @return list<int>
     */
    public static function rankCandidateBatchPhp(string $query, int $querySize, int $queryFiles, array $titles, array $sizes, array $files): array
    {
        [$queryTokens, $queryYear] = self::w58TitleTokens($query);
        $queryGroup = self::w58Group($query);
        $querySizeOk = $querySize > 0 && $querySize <= self::W58_SIZE_LIMIT;
        $out = $keys = [];
        $i = 0;
        foreach ($titles as $title) {
            $title = (string) $title;
            [$tokens, $year] = self::w58TitleTokens($title);
            $overlap = \count(array_intersect_key($queryTokens, $tokens));
            $total = \count($queryTokens) + \count($tokens);
            $score = $total === 0 ? 0 : intdiv(200 * $overlap, $total);
            $size = (int) ($sizes[$i] ?? 0);
            $flags = 0;
            if ($querySizeOk && $size > 0 && $size <= self::W58_SIZE_LIMIT && abs($querySize - $size) * 10 <= max($querySize, $size)) {
                $flags |= 1;
            }
            if ($queryFiles > 0 && (int) ($files[$i] ?? 0) === $queryFiles) {
                $flags |= 2;
            }
            if ($queryGroup !== null && $queryGroup === self::w58Group($title)) {
                $flags |= 4;
            }
            if ($queryYear !== null && $queryYear === $year) {
                $flags |= 8;
            }
            $eligible = $score >= 50 && ($flags & 1) !== 0 && ($queryFiles <= 0 || ($flags & 2) !== 0);
            if ($eligible) {
                $flags |= 16;
            }
            $keys[] = [$eligible, $score, self::W58_POPCOUNT[$flags & 15]];
            $out[] = $flags | min($overlap, 255) << 8 | $score << 16;
            $i++;
        }
        $order = array_keys($keys);
        usort($order, static fn (int $a, int $b): int => ($keys[$b] <=> $keys[$a]) ?: $a <=> $b);
        foreach ($order as $rank => $index) {
            $out[$index] |= $rank << 24;
        }
        $best = isset($order[0]) && $keys[$order[0]][0] ? $order[0] : -1;
        array_push($out, \count(array_filter(array_column($keys, 0))), $best, $best >= 0 ? $keys[$best][1] : 0, \count($queryTokens));
        $out[] = self::w58Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a title holds a NUL byte or
     * the columns are not lists of one length.
     *
     * @param  list<string>  $titles
     * @param  list<int>  $sizes
     * @param  list<int>  $files
     * @return list<int>
     */
    public static function rankCandidateBatchNative(string $query, int $querySize, int $queryFiles, array $titles, array $sizes, array $files): array
    {
        $count = \count($titles);
        $words = $count + 5;
        /** @var array{FFI, CData, int}|null $state */
        $state = self::w58RustFfiState('out', $words);
        if ($state === null || ! array_is_list($titles) || ! array_is_list($sizes) || ! array_is_list($files)
            || \count($sizes) !== $count || \count($files) !== $count) {
            return self::rankCandidateBatchPhp($query, $querySize, $queryFiles, $titles, $sizes, $files);
        }
        [$ffi, $out, $cap] = $state;
        try {
            $blob = implode("\0", $titles);
            $packedSizes = pack('P*', ...$sizes);
            $packedFiles = pack('P*', ...$files);
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_predb_candidate_rank_batch($query, \strlen($query), $querySize, $queryFiles, $blob, \strlen($blob),
                $packedSizes, \strlen($packedSizes), $packedFiles, \strlen($packedFiles), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w58Words($written, $out, $words) ?? self::rankCandidateBatchPhp($query, $querySize, $queryFiles, $titles, $sizes, $files);
    }

    /**
     * Distinct lowercase `[a-z0-9]` tokens (as keys) and the first `(19|20)YY` token.
     *
     * @return array{array<array-key, int>, ?string}
     */
    private static function w58TitleTokens(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $year = null;
        foreach ($tokens as $token) {
            if (\strlen($token) === 4 && preg_match('/\A(?:19|20)[0-9]{2}\z/', $token) === 1) {
                $year = $token;
                break;
            }
        }

        return [array_flip($tokens), $year];
    }

    /** The lowercased text after the last `-` when it is 2-20 ASCII alphanumerics. */
    private static function w58Group(string $value): ?string
    {
        $p = strrpos($value, '-');
        if ($p === false) {
            return null;
        }
        $group = strtolower(substr($value, $p + 1));
        $length = \strlen($group);

        return $length >= 2 && $length <= 20 && strspn($group, '0123456789abcdefghijklmnopqrstuvwxyz') === $length ? $group : null;
    }
}
