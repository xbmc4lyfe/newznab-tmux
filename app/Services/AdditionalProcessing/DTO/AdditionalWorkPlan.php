<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class AdditionalWorkPlan
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_workplan_set_key(const char *key, size_t key_len, int64_t source_index, char *out, size_t cap);
        int64_t nntmux_workplan_archive_set_count(const char *keys, size_t keys_len, const char *lens, size_t lens_len, const char *indexes, size_t indexes_len, size_t count);
        ssize_t nntmux_workplan_candidate_order(const char *flags, size_t count, uint32_t mode, char *out, size_t cap);
        C;

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

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
