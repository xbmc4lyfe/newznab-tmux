<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class AdditionalWorkPlan
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_workplan_set_key(const char *key, size_t key_len, int64_t source_index, char *out, size_t cap);
        int64_t nntmux_workplan_archive_set_count(const char *keys, size_t keys_len, const char *lens, size_t lens_len, const char *indexes, size_t indexes_len, size_t count);
        ssize_t nntmux_workplan_candidate_order(const char *flags, size_t count, uint32_t mode, char *out, size_t cap);
        C;

    // Rust qualified (seed 476, 2,000 pairs): 3.23x/2.04x common, 2.78x/2.38x uncommon mean/p99.
    public const bool W47_TASK_SCHEDULE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        int32_t nntmux_w47_workplan_schedule(const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, const char *sizes, size_t sizes_len, size_t count, int64_t budget, int64_t *out, size_t out_count);
        C;

    public const int W47_TASK_HEADER = 12;

    /** Priority base per task kind (first archive volume .. other). */
    private const array W47_TASK_BASE = [900, 300, 500, 600, 700, 800, 100, 400, 0];

    private const int W47_KIB_CAP = 1_048_575;

    private const int W47_MAX_ITEMS = 1_048_576;

    private const array W47_MEDIA_EXTENSIONS = [
        'mkv' => true, 'mp4' => true, 'avi' => true, 'm4v' => true, 'ts' => true, 'm2ts' => true, 'wmv' => true,
        'mov' => true, 'mpg' => true, 'mpeg' => true, 'webm' => true, 'mp3' => true, 'flac' => true, 'm4a' => true,
    ];

    private const array W47_IMAGE_EXTENSIONS = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true];

    /**
     * @param  list<string>  $sampleMessageIds
     * @param  list<string>  $jpgMessageIds
     * @param  list<ArchiveCandidate>  $archiveCandidates
     * @param  list<string>  $unsupportedReasons
     * @param  list<ArchiveCandidate>  $probeCandidates  unnamed files to sample, in order
     * @param  list<ArchiveCandidate>  $par2IndexCandidates  files that may be the PAR2 index, in order
     * @param  list<int>  $mainVideoSourceIndexes  source indexes of full-length video files, largest first
     * @param  list<ImageCandidate>  $imageCandidates  posted images, best first; the first one's IDs are $jpgMessageIds
     */
    public function __construct(
        public array $sampleMessageIds = [],
        public array $jpgMessageIds = [],
        public string $mediaInfoMessageId = '',
        public string $audioInfoMessageId = '',
        public string $audioInfoExtension = '',
        public array $archiveCandidates = [],
        public int $bookFileCount = 0,
        public bool $bookFlood = false,
        public int $duplicateMessageIdCount = 0,
        public array $unsupportedReasons = [],
        public array $probeCandidates = [],
        public bool $probeIsOnlyFile = false,
        public array $par2IndexCandidates = [],
        public array $mainVideoSourceIndexes = [],
        public array $imageCandidates = [],
        // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
        public bool $useRustFfi = false,
    ) {}

    /**
     * Fill what this plan lacks from a plan made after the PAR2 index revealed real file names.
     * Anything found under the posted names is kept.
     */
    public function withMappedNames(self $mapped): self
    {
        $takeArchives = $this->archiveCandidates === [] && $mapped->archiveCandidates !== [];
        $keepAudio = $this->audioInfoMessageId !== '';

        $merged = new self(
            sampleMessageIds: $this->sampleMessageIds !== [] ? $this->sampleMessageIds : $mapped->sampleMessageIds,
            jpgMessageIds: $this->jpgMessageIds !== [] ? $this->jpgMessageIds : $mapped->jpgMessageIds,
            mediaInfoMessageId: $this->mediaInfoMessageId !== '' ? $this->mediaInfoMessageId : $mapped->mediaInfoMessageId,
            audioInfoMessageId: $keepAudio ? $this->audioInfoMessageId : $mapped->audioInfoMessageId,
            audioInfoExtension: $keepAudio ? $this->audioInfoExtension : $mapped->audioInfoExtension,
            archiveCandidates: $takeArchives ? $mapped->archiveCandidates : $this->archiveCandidates,
            bookFileCount: $this->bookFileCount,
            bookFlood: $this->bookFlood,
            duplicateMessageIdCount: $this->duplicateMessageIdCount,
            unsupportedReasons: $this->unsupportedReasons,
            // Only files still unnamed are worth probing.
            probeCandidates: $this->archiveCandidates !== [] ? $this->probeCandidates : $mapped->probeCandidates,
            probeIsOnlyFile: $this->archiveCandidates !== [] ? $this->probeIsOnlyFile : $mapped->probeIsOnlyFile,
            par2IndexCandidates: $this->par2IndexCandidates,
            mainVideoSourceIndexes: $this->mainVideoSourceIndexes !== [] ? $this->mainVideoSourceIndexes : $mapped->mainVideoSourceIndexes,
            imageCandidates: $this->jpgMessageIds !== [] ? $this->imageCandidates : $mapped->imageCandidates,
            useRustFfi: $this->useRustFfi,
        );

        if (! $merged->hasSupportedCandidates()) {
            return $merged;
        }

        return new self(...[
            ...get_object_vars($merged),
            'unsupportedReasons' => array_values(array_diff($merged->unsupportedReasons, ['no-supported-candidates'])),
        ]);
    }

    public function hasSupportedCandidates(): bool
    {
        return $this->archiveCandidates !== []
            || $this->sampleMessageIds !== []
            || $this->jpgMessageIds !== []
            || $this->mediaInfoMessageId !== ''
            || $this->audioInfoMessageId !== ''
            || $this->probeCandidates !== [];
    }

    public function hasCompressedFile(): bool
    {
        return $this->archiveCandidates !== [];
    }

    /**
     * Distinct archive sets in the release, e.g. one per episode of a season pack.
     */
    public function archiveSetCount(): int
    {
        return ($this->useRustFfi ? $this->archiveSetCountNative() : null) ?? $this->archiveSetCountPhp();
    }

    public function archiveSetCountPhp(): int
    {
        return count(array_unique(array_map(fn (ArchiveCandidate $candidate): string => $this->setKeyOfPhp($candidate), $this->archiveCandidates)));
    }

    public function archiveSetCountNative(): ?int
    {
        if ($this->archiveCandidates === []) {
            return 0;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $keys = $lens = $indexes = [];
        foreach ($this->archiveCandidates as $candidate) {
            $keys[] = $candidate->setKey;
            $lens[] = \strlen($candidate->setKey);
            $indexes[] = $candidate->sourceIndex;
        }
        $keys = implode('', $keys);
        $lens = pack('V*', ...$lens);
        $indexes = pack('q*', ...$indexes);
        try {
            // @phpstan-ignore method.notFound
            $count = $ffi->nntmux_workplan_archive_set_count($keys, \strlen($keys), $lens, \strlen($lens), $indexes, \strlen($indexes), \count($this->archiveCandidates));
        } catch (Throwable) {
            return null;
        }

        return $count >= 0 ? $count : null;
    }

    public function setKeyOf(ArchiveCandidate $candidate): string
    {
        return ($this->useRustFfi ? $this->setKeyOfNative($candidate) : null) ?? $this->setKeyOfPhp($candidate);
    }

    public function setKeyOfPhp(ArchiveCandidate $candidate): string
    {
        return $candidate->setKey !== '' ? $candidate->setKey : 'src:'.$candidate->sourceIndex;
    }

    public function setKeyOfNative(ArchiveCandidate $candidate): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(32, \strlen($candidate->setKey)), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_workplan_set_key($candidate->setKey, \strlen($candidate->setKey), $candidate->sourceIndex, $scratch, $cap);

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<ArchiveCandidate>
     */
    public function orderedArchiveCandidates(bool $reverse = false): array
    {
        return ($this->useRustFfi ? $this->orderedArchiveCandidatesNative($reverse) : null) ?? $this->orderedArchiveCandidatesPhp($reverse);
    }

    /**
     * @return list<ArchiveCandidate>
     */
    public function orderedArchiveCandidatesPhp(bool $reverse = false): array
    {
        return $reverse ? array_reverse($this->archiveCandidates) : $this->archiveCandidates;
    }

    /**
     * @return list<ArchiveCandidate>|null
     */
    public function orderedArchiveCandidatesNative(bool $reverse = false): ?array
    {
        return $this->candidatesInNativeOrder($reverse ? 1 : 0);
    }

    /**
     * Return a stable priority view without changing the legacy processing order.
     *
     * @return list<ArchiveCandidate>
     */
    public function prioritizedArchiveCandidates(): array
    {
        return ($this->useRustFfi ? $this->prioritizedArchiveCandidatesNative() : null) ?? $this->prioritizedArchiveCandidatesPhp();
    }

    /**
     * @return list<ArchiveCandidate>
     */
    public function prioritizedArchiveCandidatesPhp(): array
    {
        $firstVolumes = [];
        $remaining = [];

        foreach ($this->archiveCandidates as $candidate) {
            if ($candidate->likelyFirstVolume) {
                $firstVolumes[] = $candidate;
            } else {
                $remaining[] = $candidate;
            }
        }

        return [...$firstVolumes, ...$remaining];
    }

    /**
     * @return list<ArchiveCandidate>|null
     */
    public function prioritizedArchiveCandidatesNative(): ?array
    {
        return $this->candidatesInNativeOrder(2);
    }

    /**
     * @return list<ArchiveCandidate>|null
     */
    private function candidatesInNativeOrder(int $mode): ?array
    {
        $count = \count($this->archiveCandidates);
        if ($count === 0) {
            return [];
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $flags = '';
        foreach ($this->archiveCandidates as $candidate) {
            $flags .= $candidate->likelyFirstVolume ? "\1" : "\0";
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $count * 4, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_workplan_candidate_order($flags, $count, $mode, $scratch, $cap);
            if ($written !== $count * 4) {
                return null;
            }
            $order = unpack('V*', FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
        if ($order === false) {
            return null;
        }
        $candidates = array_values($this->archiveCandidates);
        $ordered = [];
        foreach ($order as $index) {
            if (! isset($candidates[$index])) {
                return null;
            }
            $ordered[] = $candidates[$index];
        }

        return $ordered;
    }

    public static function useW47TaskScheduleRustFfi(?bool $enabled): void
    {
        self::w47Toggle(true, $enabled);
    }

    public static function usesW47TaskScheduleRustFfi(): bool
    {
        return self::w47Toggle() ?? self::W47_TASK_SCHEDULE_RUST_FFI_DEFAULT;
    }

    public static function resetW47TaskScheduleRustFfi(): void
    {
        self::w47Toggle(true);
        self::w47Binding(0, true);
    }

    /**
     * Classify and schedule a batch of NZB files under a byte budget. Kinds: 0 first archive
     * volume, 1 later archive volume, 2 media, 3 sample, 4 NFO, 5 PAR2 index, 6 PAR2 volume,
     * 7 image, 8 other. Scores are `base << 20` plus a KiB component (smaller first for samples,
     * NFOs, PAR2 indexes and images, larger first otherwise); tasks are taken greedily in
     * descending score, then input order, while they fit the remaining budget.
     *
     * Layout: `[0]` count, `[1..9]` kind counts, `[10]` selected, `[11]` selected bytes, then per
     * priority position `index | selected << 32 | kind << 33` and the score. Missing sizes are 0.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function scheduleTaskBatch(array $names, array $sizes, int $byteBudget): array
    {
        return (self::usesW47TaskScheduleRustFfi() ? self::scheduleTaskBatchNative($names, $sizes, $byteBudget) : null)
            ?? self::scheduleTaskBatchPhp($names, $sizes, $byteBudget);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function scheduleTaskBatchPhp(array $names, array $sizes, int $byteBudget): array
    {
        $names = array_values($names);
        $sizes = array_values($sizes);
        $count = \count($names);
        $out = array_fill(0, self::W47_TASK_HEADER, 0);
        $out[0] = $count;
        $kinds = $scores = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = self::w47TaskKind(strtolower($names[$i]));
            $out[1 + $kind]++;
            $kib = min(max($sizes[$i] ?? 0, 0) >> 10, self::W47_KIB_CAP);
            $kinds[] = $kind;
            $scores[] = self::W47_TASK_BASE[$kind] << 20 | ($kind === 3 || $kind === 4 || $kind === 5 || $kind === 7 ? self::W47_KIB_CAP - $kib : $kib);
        }
        // Sorting is stable, so equal scores keep input order.
        arsort($scores);
        $budget = max(0, $byteBudget);
        $remaining = $budget;
        foreach ($scores as $index => $score) {
            $size = max($sizes[$index] ?? 0, 0);
            $selected = $size <= $remaining;
            if ($selected) {
                $remaining -= $size;
                $out[10]++;
            }
            $out[] = $index | ($selected ? 1 << 32 : 0) | $kinds[$index] << 33;
            $out[] = $score;
        }
        $out[11] = $budget - $remaining;

        return $out;
    }

    /**
     * Rust schedule, or null when PHP must schedule (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function scheduleTaskBatchNative(array $names, array $sizes, int $byteBudget): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W47_TASK_HEADER + 2 * $count;
        $binding = $count <= self::W47_MAX_ITEMS ? self::w47Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode("\0", $names);
        $lens = '';
        if ($count > 0 && substr_count($joined, "\0") !== $count - 1) {
            $joined = implode('', $names);
            $lens = pack('V*', ...array_map(strlen(...), $names));
        }
        $sizes = pack('q*', ...(\count($sizes) === $count && array_is_list($sizes) ? $sizes : array_pad(\array_slice(array_values($sizes), 0, $count), $count, 0)));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w47_workplan_schedule($joined, \strlen($joined), $lens, \strlen($lens), $sizes, \strlen($sizes), $count, $byteBudget, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    private static function w47TaskKind(string $lower): int
    {
        $dot = strrpos($lower, '.');
        if ($dot === false) {
            return 8;
        }
        $extension = substr($lower, $dot + 1);
        if ($extension === 'par2') {
            return str_contains($lower, '.vol') ? 6 : 5;
        }
        if ($extension === 'nfo') {
            return 4;
        }
        if (isset(self::W47_IMAGE_EXTENSIONS[$extension])) {
            return 7;
        }
        if (isset(self::W47_MEDIA_EXTENSIONS[$extension])) {
            return str_contains($lower, 'sample') ? 3 : 2;
        }
        if ($extension === 'zip' || $extension === '7z') {
            return 0;
        }
        if ($extension === 'rar') {
            $part = strrpos($lower, '.part');
            if ($part === false) {
                return 0;
            }
            $digits = substr($lower, $part + 5, $dot - $part - 5);

            return ctype_digit($digits) && ltrim($digits, '0') !== '1' ? 1 : 0;
        }
        $length = \strlen($extension);
        if ($length >= 3 && $length <= 4 && $extension[0] === 'r' && ctype_digit(substr($extension, 1))) {
            return 1;
        }
        if ($length === 3 && ctype_digit($extension)) {
            return $extension === '001' ? 0 : 1;
        }

        return 8;
    }

    /** Readonly classes keep the nullable toggle in a function static. */
    private static function w47Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w47Binding(int $fields, bool $reset = false): ?array
    {
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($reset) {
            $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        $ffi ??= RustPipelineLibrary::resolveDomain('additional_w47', self::W47_FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        if ($buffer === null || $capacity < $fields) {
            $capacity = max(256, $fields);
            $buffer = $ffi->new('int64_t['.$capacity.']');
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
