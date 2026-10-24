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
}
