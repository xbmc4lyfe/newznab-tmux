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

    // Rust is the default only while the paired W54.6 benchmark clears 1.5x on all four metrics.
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        int32_t nntmux_w546_plan_summary_batch(const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W54_PLAN_HEADER = 15;

    private const int W54_MAX_ITEMS = 1_048_576;

    private const int W54_COUNT_CAP = 1_048_575;

    private const int W54_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Plan flag bits of `summarizePlanBatch()`. */
    public const int W54_FLAG_MEDIA_INFO = 1;

    public const int W54_FLAG_AUDIO_INFO = 2;

    public const int W54_FLAG_BOOK_FLOOD = 4;

    public const int W54_FLAG_PROBE_ONLY_FILE = 8;

    /**
     * Stage, tier and work-unit summary of many plans from their candidate counts.
     *
     * Counts clamp to [0, 2^20); missing values are 0. Flags use the `W54_FLAG_*` bits. A plan is supported
     * as `hasSupportedCandidates()` decides. Layout: `[0]` count, `[1]` supported plans, `[2]` unsupported,
     * `[3..10]` plans per stage (archive, sample, thumbnail, media info, audio, probe, PAR2, book), `[11]`
     * work units total, `[12]` first heaviest plan (-1 when none), `[13]` book floods, `[14]` FNV-1a
     * digest; then per plan `stages | tier << 8 | units << 10 | duplicate_ppm << 40`. Tier 3 has archives
     * and a main video, 2 either one, 1 other supported plans and 0 unsupported ones. Units weigh samples
     * 2, JPG IDs and images 1, archives 8, probes 4, PAR2 candidates 2, main videos 5, media info 3, audio
     * info 2 and book files 1 (none in a flood); the duplicate ppm is of samples, JPG IDs and duplicates.
     *
     * @param  array<array-key, int>  $samples
     * @param  array<array-key, int>  $jpgs
     * @param  array<array-key, int>  $archives
     * @param  array<array-key, int>  $probes
     * @param  array<array-key, int>  $par2Indexes
     * @param  array<array-key, int>  $mainVideos
     * @param  array<array-key, int>  $images
     * @param  array<array-key, int>  $bookFiles
     * @param  array<array-key, int>  $duplicates
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizePlanBatch(array $samples, array $jpgs, array $archives, array $probes, array $par2Indexes, array $mainVideos, array $images, array $bookFiles, array $duplicates, array $flags): array
    {
        return (self::usesW54RustFfi() ? self::summarizePlanBatchNative($samples, $jpgs, $archives, $probes, $par2Indexes, $mainVideos, $images, $bookFiles, $duplicates, $flags) : null)
            ?? self::summarizePlanBatchPhp($samples, $jpgs, $archives, $probes, $par2Indexes, $mainVideos, $images, $bookFiles, $duplicates, $flags);
    }

    /**
     * @param  array<array-key, int>  $samples
     * @param  array<array-key, int>  $jpgs
     * @param  array<array-key, int>  $archives
     * @param  array<array-key, int>  $probes
     * @param  array<array-key, int>  $par2Indexes
     * @param  array<array-key, int>  $mainVideos
     * @param  array<array-key, int>  $images
     * @param  array<array-key, int>  $bookFiles
     * @param  array<array-key, int>  $duplicates
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizePlanBatchPhp(array $samples, array $jpgs, array $archives, array $probes, array $par2Indexes, array $mainVideos, array $images, array $bookFiles, array $duplicates, array $flags): array
    {
        $samples = array_values($samples);
        $jpgs = array_values($jpgs);
        $archives = array_values($archives);
        $probes = array_values($probes);
        $par2Indexes = array_values($par2Indexes);
        $mainVideos = array_values($mainVideos);
        $images = array_values($images);
        $bookFiles = array_values($bookFiles);
        $duplicates = array_values($duplicates);
        $flags = array_values($flags);
        $count = \count($samples);
        $cap = self::W54_COUNT_CAP;
        $out = array_fill(0, self::W54_PLAN_HEADER, 0);
        $out[0] = $count;
        $heaviestAt = -1;
        $heaviest = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $sample = min(max($samples[$i], 0), $cap);
            $jpg = min(max($jpgs[$i] ?? 0, 0), $cap);
            $archive = min(max($archives[$i] ?? 0, 0), $cap);
            $probe = min(max($probes[$i] ?? 0, 0), $cap);
            $par2 = min(max($par2Indexes[$i] ?? 0, 0), $cap);
            $video = min(max($mainVideos[$i] ?? 0, 0), $cap);
            $image = min(max($images[$i] ?? 0, 0), $cap);
            $book = min(max($bookFiles[$i] ?? 0, 0), $cap);
            $duplicate = min(max($duplicates[$i] ?? 0, 0), $cap);
            $flag = $flags[$i] ?? 0;
            $media = ($flag & 1) !== 0;
            $audio = ($flag & 2) !== 0;
            $flood = ($flag & 4) !== 0;
            $supported = $archive > 0 || $sample > 0 || $jpg > 0 || $media || $audio || $probe > 0;
            $stages = ($archive > 0 ? 1 : 0) | ($sample > 0 ? 2 : 0) | ($jpg > 0 || $image > 0 ? 4 : 0) | ($media ? 8 : 0)
                | ($audio ? 16 : 0) | ($probe > 0 ? 32 : 0) | ($par2 > 0 ? 64 : 0) | ($book > 0 && ! $flood ? 128 : 0);
            $tier = $archive > 0 && $video > 0 ? 3 : ($archive > 0 || $video > 0 ? 2 : ($supported ? 1 : 0));
            $units = $sample * 2 + $jpg + $image + $archive * 8 + $probe * 4 + $par2 * 2 + $video * 5
                + ($media ? 3 : 0) + ($audio ? 2 : 0) + ($flood ? 0 : $book);
            $listed = $sample + $jpg + $duplicate;
            $duplicatePpm = $listed > 0 ? intdiv($duplicate * 1_000_000, $listed) : 0;
            $out[$supported ? 1 : 2]++;
            for ($stage = 0; $stage < 8; $stage++) {
                $out[3 + $stage] += ($stages >> $stage) & 1;
            }
            $out[11] = min($out[11] + $units, self::W54_TOTAL_CAP);
            if ($heaviestAt < 0 || $units > $heaviest) {
                $heaviestAt = $i;
                $heaviest = $units;
            }
            if ($flood) {
                $out[13]++;
            }
            $row = $stages | $tier << 8 | $units << 10 | $duplicatePpm << 40;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[12] = $heaviestAt;
        $out[14] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $samples
     * @param  array<array-key, int>  $jpgs
     * @param  array<array-key, int>  $archives
     * @param  array<array-key, int>  $probes
     * @param  array<array-key, int>  $par2Indexes
     * @param  array<array-key, int>  $mainVideos
     * @param  array<array-key, int>  $images
     * @param  array<array-key, int>  $bookFiles
     * @param  array<array-key, int>  $duplicates
     * @param  array<array-key, int>  $flags
     * @return list<int>|null
     */
    public static function summarizePlanBatchNative(array $samples, array $jpgs, array $archives, array $probes, array $par2Indexes, array $mainVideos, array $images, array $bookFiles, array $duplicates, array $flags): ?array
    {
        $count = \count($samples);
        if ($count > self::W54_MAX_ITEMS) {
            return null;
        }
        $ints = pack(
            'q*',
            ...self::w54Fit($samples, $count), ...self::w54Fit($jpgs, $count), ...self::w54Fit($archives, $count),
            ...self::w54Fit($probes, $count), ...self::w54Fit($par2Indexes, $count), ...self::w54Fit($mainVideos, $count),
            ...self::w54Fit($images, $count), ...self::w54Fit($bookFiles, $count), ...self::w54Fit($duplicates, $count),
            ...self::w54Fit($flags, $count),
        );
        $fields = self::W54_PLAN_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w54Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w546_plan_summary_batch($ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 54.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $forced = self::w54RustFfiState();

        return \is_bool($forced) ? $forced : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 54.6 routing and binding, kept in function statics because readonly classes cannot declare
     * static properties. No `$op` returns the forced routing (null for the default); `'set'` replaces it
     * with `$val` and returns the previous value; `'binding'` returns the cached `additional_w54` binding
     * and an output buffer of at least `$val` values, or null.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w54', self::W54_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 54.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w54Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w54RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$default`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w54Fit(array $values, int $count, int $default = 0): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }

    // ---- Wave 64.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 14.82x/13.31x, uncommon 14.94x/13.91x); see RustAdditionalConfigConsoleBatchResultWorkPlanArchiveCandidateW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_ADDITIONAL_WORK_PLAN_FFI_CDEF = <<<'C'
        intptr_t nntmux_w642_work_plan_eval_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W64_ADDITIONAL_WORK_PLAN_TAIL = 22;

    /**
     * Wave 64 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w64', self::W64_ADDITIONAL_WORK_PLAN_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w64RustFfiState('ffi');
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

    /** Routes w64WorkPlanEvalBatch() to Rust until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Routes w64WorkPlanEvalBatch() to PHP until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w64WorkPlanEvalBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64WorkPlanEvalBatch(array $rows): array
    {
        return self::usesW64RustFfi() ? self::w64WorkPlanEvalBatchNative($rows) : self::w64WorkPlanEvalBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64WorkPlanEvalBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W64_ADDITIONAL_WORK_PLAN_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w64RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w64WorkPlanEvalBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W64_ADDITIONAL_WORK_PLAN_FFI_CDEF.)
            $written = $ffi->nntmux_w642_work_plan_eval_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w64WorkPlanEvalBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w64Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w64Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Audio extensions the audio-info stage accepts. */
    private const array W64_AUDIO_EXTENSIONS = ['mp3' => true, 'flac' => true, 'ogg' => true, 'wav' => true, 'aac' => true, 'm4a' => true, 'wma' => true,
        'ape' => true, 'opus' => true];

    /** Flag letters: book flood, probe is the only file, mapped names, video disabled, audio disabled. */
    private const string W64_FLAG_LETTERS = 'bpmva';

    /** Bytes that cannot appear in a message id. */
    private const string W64_ID_INVALID = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f<>";

    /** Segments one evaluated plan may fetch before the rest is deferred. */
    public const int W64_SEGMENT_LIMIT = 1000;

    /** Message-id shape: 0 empty, 1 valid, 2 invalid (a byte <= 0x20, DEL, `<` or `>`). */
    private static function w64IdState(string $id): int
    {
        if ($id === '') {
            return 0;
        }

        return strcspn($id, self::W64_ID_INVALID) === \strlen($id) ? 1 : 2;
    }

    /**
     * Wave 64.2 work plan evaluation. Per `samples\tjpgs\tmedia_info_id\taudio_info_id\taudio_ext\t
     * archives\tprobes\tpar2\tbook_files\tsegment_budget\tflags` row `stages | min(needed, 2^20 - 1)
     * << 8 | budget << 28 | flags << 35 | malformed << 40 | over_limit << 41 | supported << 42 |
     * min(unknown_flags, 15) << 43 | audio_ext_known << 47 | media_id_state << 48 | audio_id_state
     * << 50`. Counts are w64Int() fields (6 digits; unparsable sets malformed and counts 0); the
     * budget has 9 digits, empty or 0 means 2 and it is capped at 100. Ids are trimmed (see
     * w64IdState()); the audio extension is trimmed, loses one leading dot and is matched
     * case-insensitively. Flag letters (W64_FLAG_LETTERS, case-insensitive; spaces and commas
     * skipped, anything else unknown) set bits 0-4. Stages: 0 sample (samples, video on), 1 jpg,
     * 2 media info (valid id, video on), 3 audio info (valid id, known extension, audio on), 4
     * archive (archives, unless a book flood with book files), 5 probe (probes without archives),
     * 6 PAR2 (par2 files, names not yet mapped), 7 NFO (archives or probes). Needed segments sum
     * the enabled stages: samples, jpgs, one per info stage, archives * budget, probes and par2
     * files; over_limit when they exceed W64_SEGMENT_LIMIT. Then eight stage and five flag counts,
     * the supported, malformed and over-limit rows, total needed and deferred segments, the most
     * needed, total archives, distinct valid media info ids (by crc32) and the XXH64 digest of all
     * earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64WorkPlanEvalBatchPhp(array $rows): array
    {
        $out = $mediaIds = [];
        $stageCounts = array_fill(0, 8, 0);
        $flagCounts = array_fill(0, 5, 0);
        $supportedRows = $malformedRows = $overRows = $totalNeeded = $totalDeferred = $maxNeeded = $totalArchives = 0;
        foreach ($rows as $row) {
            $fields = explode("\t", (string) $row, 11) + array_fill(0, 11, '');
            $malformed = false;
            $counts = [];
            foreach ([0, 1, 5, 6, 7, 8] as $k) {
                $value = self::w64Int($fields[$k], 6);
                if ($value === false) {
                    $malformed = true;
                }
                $counts[] = \is_int($value) ? $value : 0;
            }
            [$samples, $jpgs, $archives, $probes, $par2, $books] = $counts;
            $budget = self::w64Int($fields[9], 9);
            if ($budget === false) {
                $malformed = true;
            }
            $budget = \is_int($budget) && $budget > 0 ? min($budget, 100) : 2;
            $media = trim($fields[2]);
            $mediaState = self::w64IdState($media);
            $audioState = self::w64IdState(trim($fields[3]));
            $extension = trim($fields[4]);
            if (str_starts_with($extension, '.')) {
                $extension = substr($extension, 1);
            }
            $known = isset(self::W64_AUDIO_EXTENSIONS[strtolower($extension)]);
            $letters = $unknown = 0;
            $flags = strtolower($fields[10]);
            for ($i = 0, $n = \strlen($flags); $i < $n; $i++) {
                $char = $flags[$i];
                if ($char === ' ' || $char === ',') {
                    continue;
                }
                $bit = strpos(self::W64_FLAG_LETTERS, $char);
                if ($bit === false) {
                    $unknown++;
                } else {
                    $letters |= 1 << $bit;
                }
            }
            $noVideo = ($letters & 8) !== 0;
            $stages = (int) ($samples > 0 && ! $noVideo)
                | (int) ($jpgs > 0) << 1
                | (int) ($mediaState === 1 && ! $noVideo) << 2
                | (int) ($audioState === 1 && $known && ($letters & 16) === 0) << 3
                | (int) ($archives > 0 && ! (($letters & 1) !== 0 && $books > 0)) << 4
                | (int) ($probes > 0 && $archives === 0) << 5
                | (int) ($par2 > 0 && ($letters & 4) === 0) << 6
                | (int) ($archives > 0 || $probes > 0) << 7;
            $needed = (($stages & 1) !== 0 ? $samples : 0) + (($stages & 2) !== 0 ? $jpgs : 0) + ($stages >> 2 & 1) + ($stages >> 3 & 1)
                + (($stages & 16) !== 0 ? $archives * $budget : 0) + (($stages & 32) !== 0 ? $probes : 0) + (($stages & 64) !== 0 ? $par2 : 0);
            $over = $needed > self::W64_SEGMENT_LIMIT;
            $supported = $stages !== 0;
            for ($k = 0; $k < 8; $k++) {
                $stageCounts[$k] += $stages >> $k & 1;
            }
            for ($k = 0; $k < 5; $k++) {
                $flagCounts[$k] += $letters >> $k & 1;
            }
            $supportedRows += (int) $supported;
            $malformedRows += (int) $malformed;
            $overRows += (int) $over;
            $totalNeeded += $needed;
            $totalDeferred += max(0, $needed - self::W64_SEGMENT_LIMIT);
            $maxNeeded = max($maxNeeded, $needed);
            $totalArchives += $archives;
            if ($mediaState === 1) {
                $mediaIds[crc32($media)] = true;
            }
            $out[] = $stages | min($needed, 0xFFFFF) << 8 | $budget << 28 | $letters << 35 | (int) $malformed << 40 | (int) $over << 41 | (int) $supported << 42
                | min($unknown, 15) << 43 | (int) $known << 47 | $mediaState << 48 | $audioState << 50;
        }
        array_push($out, ...$stageCounts, ...$flagCounts);
        array_push($out, $supportedRows, $malformedRows, $overRows, $totalNeeded, $totalDeferred, $maxNeeded, $totalArchives, \count($mediaIds));
        $out[] = self::w64Digest($out);

        return $out;
    }
}
