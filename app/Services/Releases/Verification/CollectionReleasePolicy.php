<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CollectionReleasePolicy
{
    private static bool $useRustFfi = true;

    public const string W396_FFI_CDEF = 'ssize_t nntmux_w396_verification_store_prelock_manifest_reset(uint8_t operation, uint8_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    /** Wave 39.6 paired benchmarks decide this default; see RustVerificationStorePrelockManifestResetW39ParityTest. */
    public const bool W396_RUST_FFI_DEFAULT = false;

    private static ?bool $w396UseRustFfi = null;

    private static FFI|false|null $w396Ffi = null;

    private const int W396_RUST_MAX_INPUT = 16_777_216;

    private const array W396_AGE_STATES = ['too_young', 'eligible', 'expired'];

    private const array W396_ACTIONS = ['wait', 'release', 'discard'];

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private const VERIFIED_OVERSIZE = 'Verified payload exceeds the configured maximum size.';

    private const DECLARED_OVERSIZE = 'Declared collection size exceeds the configured maximum.';

    /** A rejection more articles cannot cure: a collection's size only grows as they arrive. */
    public static function irrecoverable(string $reason): bool
    {
        return $reason === self::VERIFIED_OVERSIZE || $reason === self::DECLARED_OVERSIZE;
    }

    /** @param array<string, mixed> $manifest */
    public function verifiedRejection(array $manifest): ?string
    {
        if (($manifest['metadata_source'] ?? null) === 'import') {
            return null;
        }
        if (($manifest['verification_mode'] ?? null) === 'spot_check') {
            // The publisher has already validated a partial block against the locked candidate.
            $inventory = new CandidateHeaderInventory;
            if (($rejection = isset($manifest['partial']) ? $inventory->partialRejection($manifest) : $inventory->rejection($manifest)) !== null) {
                throw new RuntimeException($rejection);
            }

            return $this->spotCheckRejection($manifest);
        }
        $bytes = $manifest['verified_bytes'] ?? null;
        $names = $manifest['verified_file_names'] ?? null;
        if (! is_int($bytes) || $bytes < 0 || ! is_array($names) || ! array_is_list($names) || $names === []) {
            throw new RuntimeException('Authoritative verified size and inventory are required for collection publication.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Authoritative verified inventory contains an invalid filename.');
            }
        }
        $names = array_values(array_unique($names));
        $limits = $this->limits((int) $manifest['groups_id']);
        if ($bytes < $limits['min_size']) {
            return 'Verified payload is below the configured minimum size.';
        }
        if ($limits['max_size'] > 0 && $bytes > $limits['max_size']) {
            return self::VERIFIED_OVERSIZE;
        }
        if (count($names) < $limits['min_files']) {
            return 'Verified inventory is below the configured minimum file count.';
        }
        if (self::onlyPar2($names)) {
            return 'Verified inventory contains only PAR2 files without a proved source payload.';
        }

        return null;
    }

    /** @param array<string, mixed> $manifest */
    private function spotCheckRejection(array $manifest): ?string
    {
        $sample = $manifest['spot_check'] ?? null;
        if (! is_array($sample) || (int) ($sample['articles'] ?? 0) < 1
            || (int) ($sample['bytes'] ?? PHP_INT_MAX) > (int) config('release_verification.spot_check_bytes', 1048576)) {
            return 'A bounded article spot check did not complete.';
        }
        $bytes = (new CandidateHeaderInventory)->bytes($manifest);
        // Only files with articles are written to the NZB; a partial upload can declare files it never posted.
        $emitted = array_values(array_filter($manifest['files'] ?? [], static fn (mixed $file): bool => is_array($file)
            && is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $fileCount = count($emitted);
        $limits = $this->limits((int) $manifest['groups_id']);
        if ($bytes < $limits['min_size']) {
            return 'Declared collection size is below the configured minimum.';
        }
        if ($limits['max_size'] > 0 && $bytes > $limits['max_size']) {
            return self::DECLARED_OVERSIZE;
        }
        if ($fileCount < $limits['min_files']) {
            return 'Declared file count is below the configured minimum.';
        }

        $parityKeys = array_fill_keys(array_map('strval', (array) ($sample['parity_file_keys'] ?? [])), true);
        $nonParityEmitted = array_values(array_filter($emitted,
            static fn (array $file): bool => ! isset($parityKeys[(string) ($file['key'] ?? '')])));
        $verifiedNames = array_values(array_filter($manifest['verified_file_names'] ?? [], 'is_string'));
        $allSampledArePar2 = $verifiedNames !== []
            && count($emitted) <= (int) ($sample['sampled_files'] ?? 0)
            && array_filter($verifiedNames, static fn (string $name): bool => ! HeaderFilenameIdentity::isPar2Filename($name)) === [];
        if ($allSampledArePar2) {
            $nonParityEmitted = [];
        }
        $subjects = array_map(static fn (array $file): string => (string) ($file['subject'] ?? ''), $nonParityEmitted);
        $hasPayload = self::subjectsHavePayload($subjects);
        // An obfuscated, extensionless body name is not evidence of a source payload.
        $sampledNames = $nonParityEmitted === [] ? [] : array_filter($verifiedNames,
            static fn (string $name): bool => HeaderFilenameIdentity::provesPayload($name));
        if (! $hasPayload && $sampledNames === []) {
            return 'Header inventory contains only PAR2 files without a declared source payload.';
        }

        return null;
    }

    /** @param list<string> $names */
    public static function onlyPar2(array $names): bool
    {
        return self::onlyPar2Php($names);
    }

    /** @param list<string> $names */
    public static function onlyPar2Php(array $names): bool
    {
        return array_filter($names, static fn (string $name): bool => preg_match('/\.par2\z/i', $name) !== 1) === [];
    }

    /** @param list<string> $subjects */
    public static function subjectsHavePayload(array $subjects): bool
    {
        return self::subjectsHavePayloadPhp($subjects);
    }

    /** @param list<string> $subjects */
    public static function subjectsHavePayloadPhp(array $subjects): bool
    {
        return array_filter($subjects, static fn (string $subject): bool => preg_match('/\.par2(?:["\'\s]|$)/i', $subject) !== 1) !== [];
    }

    /** @param list<string> $names */
    public static function onlyPar2Native(array $names): ?bool
    {
        return self::inspectNative($names, 0);
    }

    /** @param list<string> $subjects */
    public static function subjectsHavePayloadNative(array $subjects): ?bool
    {
        return self::inspectNative($subjects, 1);
    }

    /** @param list<string> $values */
    private static function inspectNative(array $values, int $mode): ?bool
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        $packed = '';
        foreach ($values as $value) {
            $packed .= pack('V', strlen($value)).$value;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_collection_inspect($packed, strlen($packed), $mode);

        return $status >= 0 ? $status === 1 : null;
    }

    /** @return array{min_size:int,max_size:int,min_files:int} */
    private function limits(int $groupId): array
    {
        $settings = app(ConfigurationProvider::class)->ingestion();
        $group = DB::table('usenet_groups')->where('id', $groupId)->first();

        return ['min_size' => max(0, (int) ($group->minsizetoformrelease ?? 0), $settings->minSizeToFormRelease),
            'max_size' => max(0, $settings->maxSizeToFormRelease),
            'min_files' => max(0, (int) ($group->minfilestoformrelease ?? 0), $settings->minFilesToFormRelease)];
    }

    public static function useW396RustFfi(?bool $enabled): void
    {
        self::$w396UseRustFfi = $enabled;
    }

    public static function usesW396RustFfi(): bool
    {
        return self::$w396UseRustFfi ?? self::W396_RUST_FFI_DEFAULT;
    }

    public static function resetW396RustFfi(): void
    {
        self::$w396UseRustFfi = null;
        self::$w396Ffi = null;
    }

    /**
     * Whether a collection may form a release yet. Parts are clamped to 0 <= available <= total; the age window
     * is too_young below max(0, $minAgeSeconds), expired above a positive $maxAgeSeconds, else eligible. An empty
     * collection never meets the clamped 0–1000 completion threshold. Too-young or incomplete eligible collections
     * wait, complete ones release (also when expired), and an expired incomplete collection is discarded.
     *
     * @return array{total_parts: int, available_parts: int, completion_permille: int, age_state: string, completion_met: bool, action: string}
     */
    public static function summarizeEligibilityWindow(int $totalParts, int $availableParts, int $ageSeconds, int $minAgeSeconds, int $maxAgeSeconds, int $minCompletionPermille = 950): array
    {
        return (self::usesW396RustFfi() ? self::summarizeEligibilityWindowNative($totalParts, $availableParts, $ageSeconds, $minAgeSeconds, $maxAgeSeconds, $minCompletionPermille) : null)
            ?? self::summarizeEligibilityWindowPhp($totalParts, $availableParts, $ageSeconds, $minAgeSeconds, $maxAgeSeconds, $minCompletionPermille);
    }

    /** @return array{total_parts: int, available_parts: int, completion_permille: int, age_state: string, completion_met: bool, action: string} */
    public static function summarizeEligibilityWindowPhp(int $totalParts, int $availableParts, int $ageSeconds, int $minAgeSeconds, int $maxAgeSeconds, int $minCompletionPermille = 950): array
    {
        $total = max(0, $totalParts);
        $available = min(max(0, $availableParts), $total);
        $permille = self::w396Permille($available, $total);
        $state = $ageSeconds < max(0, $minAgeSeconds) ? 'too_young' : ($maxAgeSeconds > 0 && $ageSeconds > $maxAgeSeconds ? 'expired' : 'eligible');
        $met = $total > 0 && $permille >= min(max(0, $minCompletionPermille), 1000);

        return [
            'total_parts' => $total,
            'available_parts' => $available,
            'completion_permille' => $permille,
            'age_state' => $state,
            'completion_met' => $met,
            'action' => $state === 'too_young' ? 'wait' : ($met ? 'release' : ($state === 'eligible' ? 'wait' : 'discard')),
        ];
    }

    /** @return array{total_parts: int, available_parts: int, completion_permille: int, age_state: string, completion_met: bool, action: string}|null */
    public static function summarizeEligibilityWindowNative(int $totalParts, int $availableParts, int $ageSeconds, int $minAgeSeconds, int $maxAgeSeconds, int $minCompletionPermille = 950): ?array
    {
        $slots = self::w396Slots(1, 0, pack('q6', $totalParts, $availableParts, $ageSeconds, $minAgeSeconds, $maxAgeSeconds, $minCompletionPermille), 6);
        if ($slots === null || $slots[0] < 0 || $slots[1] < 0 || $slots[1] > $slots[0] || $slots[2] < 0 || $slots[2] > 1000
            || ! isset(self::W396_AGE_STATES[$slots[3]], self::W396_ACTIONS[$slots[5]]) || ($slots[4] & ~1) !== 0) {
            return null;
        }

        return [
            'total_parts' => $slots[0],
            'available_parts' => $slots[1],
            'completion_permille' => $slots[2],
            'age_state' => self::W396_AGE_STATES[$slots[3]],
            'completion_met' => $slots[4] === 1,
            'action' => self::W396_ACTIONS[$slots[5]],
        ];
    }

    /** floor($part * 1000 / $whole) for 0 <= $part <= $whole, exact even where $part * 1000 would overflow. */
    private static function w396Permille(int $part, int $whole): int
    {
        if ($whole <= 0) {
            return 0;
        }
        if ($whole <= intdiv(PHP_INT_MAX, 1000)) {
            return intdiv($part * 1000, $whole);
        }
        // Shift-and-add over 1000 = 0b1111101000, keeping quotient * whole + remainder = part * multiplier.
        $quotient = 0;
        $remainder = 0;
        foreach ([1, 1, 1, 1, 1, 0, 1, 0, 0, 0] as $bit) {
            $quotient *= 2;
            if ($remainder >= $whole - $remainder) {
                $remainder -= $whole - $remainder;
                $quotient++;
            } else {
                $remainder *= 2;
            }
            if ($bit === 1) {
                if ($remainder >= $whole - $part) {
                    $remainder -= $whole - $part;
                    $quotient++;
                } else {
                    $remainder += $part;
                }
            }
        }

        return $quotient;
    }

    /**
     * Calls the Wave 39.6 export shared by the verification helpers and decodes its fixed i64 slots.
     * Every output fits the initial scratch buffer, so any negative or unexpected size falls back to PHP.
     *
     * @internal
     *
     * @return list<int>|null
     */
    public static function w396Slots(int $operation, int $flags, string $input, int $slots): ?array
    {
        if (\strlen($input) > self::W396_RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$w396Ffi ??= RustPipelineLibrary::resolveDomain('verification_w396', self::W396_FFI_CDEF) ?? false;
            if (self::$w396Ffi === false) {
                return null;
            }
            $ffi = self::$w396Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w396_verification_store_prelock_manifest_reset($operation, $flags, $input, \strlen($input), $buffer, min($capacity, self::W396_RUST_MAX_INPUT));
            if ($written !== $slots * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
