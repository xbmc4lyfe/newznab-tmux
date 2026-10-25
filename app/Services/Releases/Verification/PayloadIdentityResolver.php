<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\NameFixing\PredbIdentityKey;
use App\Services\Par2PacketAccumulator;
use App\Services\ReleaseCleaningService;
use App\Support\RustPipelineLibrary;

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
}
