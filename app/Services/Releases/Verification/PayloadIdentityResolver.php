<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\NameFixing\PredbIdentityKey;
use App\Services\Par2PacketAccumulator;
use App\Services\ReleaseCleaningService;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class PayloadIdentityResolver
{
    /**
     * Default for the Wave 44.2 toggle only (summarizeIdentityHints); false because the paired benchmark in
     * tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php is below 1.5x (common mean 0.83-0.94x).
     */
    public const bool W442_RUST_FFI_DEFAULT = false;

    /** resolvePhp() skips filenames naming samples, proofs, thumbnails or subtitles. */
    public const string W442_NOISE_PATTERN = '/(?:^|[._\-\s])(?:sample|proof|thumbs?|subs?|subtitles)(?:[._\-\s]|$)/i';

    private static ?bool $useW442RustFfi = null;

    public function __construct(private readonly ReleaseCleaningService $cleaning) {}

    /** Force the Wave 44.2 helper onto Rust (true) or PHP (false); null restores W442_RUST_FFI_DEFAULT. */
    public static function useW442RustFfi(?bool $enable = true): void
    {
        self::$useW442RustFfi = $enable;
    }

    public static function usesW442RustFfi(): bool
    {
        return self::$useW442RustFfi ?? self::W442_RUST_FFI_DEFAULT;
    }

    public static function resetW442RustFfi(): void
    {
        self::$useW442RustFfi = null;
    }

    /** @param array<string, mixed> $manifest */
    public function resolve(array $manifest): ?string
    {
        $hints = $manifest['identity_hints'] ?? [];
        if (! is_array($hints) || count($hints) > 4096) {
            return null;
        }
        $filenames = [];
        foreach ($hints as $hint) {
            if (! is_array($hint) || ! in_array($hint['source'] ?? null, ['yenc', 'par2'], true) || ! is_string($hint['name'] ?? null)) {
                continue;
            }
            $filenames[] = $hint['name'];
        }
        if ($filenames === []) {
            return null;
        }

        $supported = false;
        $ffiResult = RustPipelineLibrary::payloadIdentityResolve($filenames, supported: $supported);
        if ($supported) {
            return $ffiResult;
        }

        return $this->resolvePhp($manifest);
    }

    /** @param array<string, mixed> $manifest */
    public function resolvePhp(array $manifest): ?string
    {
        $hints = $manifest['identity_hints'] ?? [];
        if (! is_array($hints) || count($hints) > 4096) {
            return null;
        }
        $names = [];
        foreach ($hints as $hint) {
            if (! is_array($hint) || ! in_array($hint['source'] ?? null, ['yenc', 'par2'], true) || ! is_string($hint['name'] ?? null)) {
                continue;
            }
            $filename = $hint['name'];
            if (preg_match(self::W442_NOISE_PATTERN, $filename)) {
                continue;
            }
            $name = $this->cleaning->releaseNameFromFilePhp(str_replace('_', ' ', $filename));
            if ($name === null || preg_match('/\b(?:s\d{1,3}e\d{1,3}|(?:19|20)\d{2}|720p|1080p|2160p|flac|mp3|\d{2,3}kbps|v\d+\.\d+)\b/i', $name) !== 1) {
                continue;
            }
            $key = PredbIdentityKey::normalize($name);
            if ($key === null) {
                continue;
            }
            $names[$key] ??= $name;
            if (count($names) > 1) {
                return null;
            }
        }

        return count($names) === 1 ? reset($names) : null;
    }

    /**
     * Tally manifests as resolve() and resolvePhp() admit them before cleaning: non-array manifests are invalid, a
     * hint list that is not an array or exceeds 4096 is oversized, and the rest are named (some yEnc or PAR2 hint has a
     * string filename) or unnamed. Hints of admitted manifests are invalid, yEnc or PAR2; eligible filenames are noise
     * (W442_NOISE_PATTERN) or candidates, with summed candidate bytes.
     *
     * @param  array<array-key, mixed>  $manifests
     * @return array{manifests: int, invalid: int, oversized: int, named: int, unnamed: int, hints: int, invalid_hints: int, yenc: int, par2: int, noise: int, candidates: int, candidate_bytes: int}
     */
    public static function summarizeIdentityHints(array $manifests): array
    {
        return (self::usesW442RustFfi() ? self::summarizeIdentityHintsNative($manifests) : null)
            ?? self::summarizeIdentityHintsPhp($manifests);
    }

    /**
     * @param  array<array-key, mixed>  $manifests
     * @return array{manifests: int, invalid: int, oversized: int, named: int, unnamed: int, hints: int, invalid_hints: int, yenc: int, par2: int, noise: int, candidates: int, candidate_bytes: int}
     */
    public static function summarizeIdentityHintsPhp(array $manifests): array
    {
        $summary = ['manifests' => \count($manifests), 'invalid' => 0, 'oversized' => 0, 'named' => 0, 'unnamed' => 0, 'hints' => 0, 'invalid_hints' => 0,
            'yenc' => 0, 'par2' => 0, 'noise' => 0, 'candidates' => 0, 'candidate_bytes' => 0];
        foreach ($manifests as $manifest) {
            if (! \is_array($manifest)) {
                $summary['invalid']++;

                continue;
            }
            $hints = $manifest['identity_hints'] ?? [];
            if (! \is_array($hints) || \count($hints) > 4096) {
                $summary['oversized']++;

                continue;
            }
            $named = false;
            foreach ($hints as $hint) {
                $summary['hints']++;
                $source = \is_array($hint) ? ($hint['source'] ?? null) : null;
                if (! \in_array($source, ['yenc', 'par2'], true) || ! \is_string($hint['name'] ?? null)) {
                    $summary['invalid_hints']++;

                    continue;
                }
                $named = true;
                $summary[$source]++;
                if (preg_match(self::W442_NOISE_PATTERN, $hint['name'])) {
                    $summary['noise']++;
                } else {
                    $summary['candidates']++;
                    $summary['candidate_bytes'] += \strlen($hint['name']);
                }
            }
            $summary[$named ? 'named' : 'unnamed']++;
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $manifests
     * @return array{manifests: int, invalid: int, oversized: int, named: int, unnamed: int, hints: int, invalid_hints: int, yenc: int, par2: int, noise: int, candidates: int, candidate_bytes: int}|null
     */
    public static function summarizeIdentityHintsNative(array $manifests): ?array
    {
        $slots = [];
        $tail = '';
        foreach ($manifests as $manifest) {
            if (! \is_array($manifest)) {
                array_push($slots, -1, 11);

                continue;
            }
            $hints = $manifest['identity_hints'] ?? [];
            if (! \is_array($hints) || \count($hints) > 4096) {
                array_push($slots, -1, 12);

                continue;
            }
            array_push($slots, -1, 10);
            foreach ($hints as $hint) {
                $source = \is_array($hint) ? ($hint['source'] ?? null) : null;
                $name = \is_array($hint) ? ($hint['name'] ?? null) : null;
                if (($source !== 'yenc' && $source !== 'par2') || ! \is_string($name)) {
                    array_push($slots, -1, 0);

                    continue;
                }
                array_push($slots, \strlen($name), $source === 'yenc' ? 1 : 2);
                $tail .= $name;
            }
        }
        $values = Par2PacketAccumulator::evaluateW442Native(3, intdiv(\count($slots), 2), $slots, $tail, 12);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $oversized, $named, $unnamed, $hints, $invalidHints, $yenc, $par2, $noise, $candidates, $bytes] = $values;
        if ($count !== \count($manifests) || min($values) < 0 || $invalid + $oversized + $named + $unnamed !== $count
            || $invalidHints + $yenc + $par2 !== $hints || $noise + $candidates !== $yenc + $par2 || $named > $yenc + $par2) {
            return null;
        }

        return ['manifests' => $count, 'invalid' => $invalid, 'oversized' => $oversized, 'named' => $named, 'unnamed' => $unnamed, 'hints' => $hints,
            'invalid_hints' => $invalidHints, 'yenc' => $yenc, 'par2' => $par2, 'noise' => $noise, 'candidates' => $candidates, 'candidate_bytes' => $bytes];
    }

    /** C signature of the Wave 63.5 {@see self::w63PayloadIdentityPlanBatch()} helper. */
    private const string W63_PAYLOAD_IDENTITY_PLAN_FFI_CDEF = 'int64_t nntmux_w635_payload_identity_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 63.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 2.98/3.08/4.23/3.85x); see tests/Unit/RustVerificationPolicyPar2ProofPayloadUpdateResetW63ParityTest.php.
     */
    public const bool W63_RUST_FFI_DEFAULT = true;

    /**
     * Wave 63.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w63_5', self::W63_PAYLOAD_IDENTITY_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W63_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 63.5 plan batch to Rust until disabled or reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Route the Wave 63.5 plan batch to PHP until enabled or reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restore W63_RUST_FFI_DEFAULT routing and drop the cached Wave 63.5 handle and buffer. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('set', null);
        self::w63RustFfiState('reset', null);
    }

    public static function usesW63RustFfi(): bool
    {
        return self::w63RustFfiState() === true;
    }

    /**
     * Runs $callback with w63PayloadIdentityPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 63.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w63Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w63RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w635_payload_identity_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch payload identity plan over tab-separated `source|name` hints (an empty record holds none). Ten words per
     * record: `[hints, invalid, noise, gibberish, candidates, best_authority, best_index, distinct_keys, resolution,
     * flags]`. Over 4096 hints yields `[hints, 0, 0, 0, 0, 0, -1, 0, 0, 1]`. The source before the first `|` is trimmed
     * and ranked (ASCII case-insensitive) subject 1, nfo 2, yenc 3, par2 4; other sources and empty names are invalid.
     * Names matching W442_NOISE_PATTERN are noise. The stem drops a final `.` plus 1-5 ASCII alphanumerics (not a
     * leading dot); its key keeps ASCII alphanumerics (lowercased) and bytes 0x80-0xff. An empty key, or a stem of 16+
     * ASCII alphanumerics that is all hex or switches between digits and letters 4+ times, is gibberish. Remaining
     * names are candidates; the first highest-authority one gives best_index, and distinct_keys counts keys at that
     * authority. Resolution: 0 no candidate, 1 one key, 2 ambiguous. Flags: 1 too many hints, 2 invalid source, 4
     * empty name, 8 non-ASCII name, 16 empty key.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PayloadIdentityPlanBatch(array $records): array
    {
        return self::usesW63RustFfi() ? self::w63PayloadIdentityPlanBatchNative($records) : self::w63PayloadIdentityPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PayloadIdentityPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w63Words($count, implode("\0", $records), 10 * $count) ?? self::w63PayloadIdentityPlanBatchPhp($records);
    }

    /** Hint source authority: subject 1, NFO 2, yEnc 3, PAR2 4. */
    private const array W63_AUTHORITY = ['subject' => 1, 'nfo' => 2, 'yenc' => 3, 'par2' => 4];

    private const string W63_ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63PayloadIdentityPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $hints = $record === '' ? [] : explode("\t", $record);
            $count = \count($hints);
            if ($count > 4096) {
                array_push($out, $count, 0, 0, 0, 0, 0, -1, 0, 0, 1);

                continue;
            }
            $invalid = $noise = $gibberish = $candidates = $best = $flags = 0;
            $bestIndex = -1;
            $keys = [];
            foreach ($hints as $index => $hint) {
                $bar = strpos($hint, '|');
                $authority = $bar === false ? 0 : (self::W63_AUTHORITY[strtolower(trim(substr($hint, 0, $bar)))] ?? 0);
                if ($authority === 0) {
                    $invalid++;
                    $flags |= 2;

                    continue;
                }
                $name = substr($hint, $bar + 1);
                if ($name === '') {
                    $invalid++;
                    $flags |= 4;

                    continue;
                }
                if (preg_match('/[\x80-\xff]/', $name) === 1) {
                    $flags |= 8;
                }
                if (preg_match(self::W442_NOISE_PATTERN, $name) === 1) {
                    $noise++;

                    continue;
                }
                $stem = $name;
                $dot = strrpos($name, '.');
                if ($dot !== false && $dot > 0 && \strlen($name) - $dot - 1 >= 1 && \strlen($name) - $dot - 1 <= 5
                    && strspn($name, self::W63_ALNUM, $dot + 1) === \strlen($name) - $dot - 1) {
                    $stem = substr($name, 0, $dot);
                }
                $key = strtolower((string) preg_replace('/[^A-Za-z0-9\x80-\xff]+/', '', $stem));
                if ($key === '') {
                    $gibberish++;
                    $flags |= 16;

                    continue;
                }
                $length = \strlen($stem);
                if ($length >= 16 && strspn($stem, self::W63_ALNUM) === $length
                    && (strspn($stem, '0123456789abcdefABCDEF') === $length || preg_match_all('/(?<=[0-9])[A-Za-z]|(?<=[A-Za-z])[0-9]/', $stem) >= 4)) {
                    $gibberish++;

                    continue;
                }
                $candidates++;
                if ($authority > $best) {
                    [$best, $bestIndex, $keys] = [$authority, $index, [$key => true]];
                } elseif ($authority === $best) {
                    $keys[$key] = true;
                }
            }
            $distinct = \count($keys);
            array_push($out, $count, $invalid, $noise, $gibberish, $candidates, $best, $bestIndex, $distinct, $candidates === 0 ? 0 : ($distinct === 1 ? 1 : 2), $flags);
        }

        return $out;
    }
}
