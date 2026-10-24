<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Par2FileList;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use Throwable;

/**
 * Reads the release's PAR2 index to learn the real names behind obfuscated NZB files.
 */
class MediaSourceResolver
{
    private const string PAR2_SIGNATURE = "PAR2\0PKT";

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_media_source_rank_wanted_files_nul(const char *names, size_t names_len, const char *sizes, size_t sizes_len, const char *video_regex, size_t video_regex_len, size_t count, char *out_buf, size_t out_cap);
        C;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly UsenetDownloadService $downloadService,
        private readonly Par2FileMapper $mapper = new Par2FileMapper,
        private readonly ?string $libraryPath = null,
    ) {}

    public function resolve(ReleaseProcessingContext $context): ?MediaSourcePlan
    {
        $workPlan = $context->workPlan;
        if (! $this->config->par2MediaMapping || $workPlan === null || $workPlan->par2IndexCandidates === []) {
            return null;
        }

        $budget = $context->mediaBudget ??= new MediaByteBudget($this->config->mediaMaxBytes);
        $files = $this->readIndex($context, $workPlan->par2IndexCandidates, $budget);
        if ($files === []) {
            return null;
        }

        [$verified, $articlePartSize] = $this->verifyAmbiguousFiles($context, $files, $budget);

        $mapped = $this->mapper->map($context->nzbContents, $files, $articlePartSize, $verified);

        return $mapped === [] ? null : new MediaSourcePlan($mapped, $articlePartSize);
    }

    /**
     * @param  list<ArchiveCandidate>  $candidates
     * @return list<Par2FileEntry>
     */
    private function readIndex(ReleaseProcessingContext $context, array $candidates, MediaByteBudget $budget): array
    {
        foreach ($candidates as $candidate) {

            if ($context->groupUnavailable || $budget->exhausted() || ! $budget->allows($this->estimatedBytes($context, $candidate->sourceIndex, count($candidate->messageIds)))) {
                return [];
            }

            $result = $this->downloadService->download(
                DownloadKind::Par2Index,
                $candidate->messageIds,
                $context->releaseGroupName,
                $context->release->id,
                $candidate->title,
                cache: false,
            );
            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;

                return [];
            }
            if (! $result['success'] || ! is_string($result['data'])) {
                continue;
            }

            $budget->spend(strlen($result['data']));
            if (! str_starts_with($result['data'], self::PAR2_SIGNATURE)) {
                continue;
            }

            $par2Info = new Par2Info;
            $par2Info->setData($result['data']);
            if ($par2Info->error) {
                continue;
            }

            $files = Par2FileEntry::listFromPar2Info((new Par2FileList)->getFiles($par2Info));
            if ($files !== []) {
                return $files;
            }
        }

        return [];
    }

    /**
     * Download the first article of NZB files that more than one wanted PAR2 entry could be,
     * and identify them by the PAR2 hash of their first 16 KiB or their yEnc name.
     *
     * @param  list<Par2FileEntry>  $files
     * @return array{0: array<int, Par2FileEntry>, 1: int|null}
     */
    private function verifyAmbiguousFiles(ReleaseProcessingContext $context, array $files, MediaByteBudget $budget): array
    {
        $verified = [];
        $articlePartSize = null;
        $attempts = 0;
        $sizeMatched = $this->mapper->map($context->nzbContents, $files);
        $sizeMatchedFiles = array_map(static fn ($mapped): Par2FileEntry => $mapped->file, $sizeMatched);

        foreach ($this->wantedFiles($files) as $wanted) {
            if (in_array($wanted, $sizeMatchedFiles, true) || in_array($wanted, $verified, true)) {
                continue;
            }

            foreach ($this->mapper->candidateIndexes($context->nzbContents, $wanted, $articlePartSize) as $sourceIndex) {
                if ($attempts >= $this->config->par2VerifyFiles || $context->groupUnavailable) {
                    break 2;
                }
                if (isset($verified[$sourceIndex])) {
                    continue;
                }

                $messageId = (string) ($context->nzbContents[$sourceIndex]['segments'][0] ?? '');

                if ($messageId === '' || $budget->exhausted() || ! $budget->allows($this->estimatedBytes($context, $sourceIndex, 1))) {
                    break 2;
                }

                $attempts++;
                try {
                    $result = $this->downloadService->downloadMappingArticle(
                        $messageId,
                        $context->releaseGroupName,
                        $context->release->id,
                    );
                    if ($result['groupUnavailable']) {
                        $context->groupUnavailable = true;

                        break 2;
                    }
                    if (! $result['success'] || ! is_string($result['data'])) {
                        continue;
                    }
                    $budget->spend(strlen($result['data']));

                    $part = $result['part'];
                    if ($part !== null && $part->length() !== null && $part->end !== null && $part->end < $part->size) {
                        $articlePartSize ??= $part->length();
                    }

                    foreach ($files as $file) {
                        if (in_array($file, $verified, true)) {
                            continue;
                        }
                        if ($file->matchesStartOf($result['data'])
                            || ($part !== null && $part->name === $file->name && $part->size === $file->size)
                        ) {
                            $verified[$sourceIndex] = $file;
                            $this->downloadService->retainMappedSourceArticle($file);

                            break;
                        }
                    }

                    if (in_array($wanted, $verified, true)) {
                        break;
                    }
                } finally {
                    $this->downloadService->discardPendingMappingArticle();
                }
            }
        }

        return [$verified, $articlePartSize];
    }

    /**
     * PAR2 entries worth identifying for previews and archive inspection, most useful first:
     * first archive volumes, then samples, then other videos (largest first), then images.
     *
     * @param  list<Par2FileEntry>  $files
     * @return list<Par2FileEntry>
     */
    public function wantedFiles(array $files): array
    {
        return $this->wantedFilesNative($files);
    }

    /**
     * Rank and filter wanted PAR2 entries via the Rust FFI accelerator, falling back to PHP.
     *
     * @param  list<Par2FileEntry>  $files
     * @return list<Par2FileEntry>
     */
    public function wantedFilesNative(array $files, bool &$supported = false): array
    {
        $supported = false;
        $count = count($files);
        $ffi = RustPipelineLibrary::resolveDomain('media_source_resolver', self::FFI_CDEF, $this->libraryPath);
        if ($ffi === null) {
            return $this->wantedFilesPhp($files);
        }
        if ($count === 0) {
            $supported = true;

            return [];
        }

        $values = array_values($files);
        $names = [];
        $sizes = [];
        foreach ($values as $file) {
            $names[] = $file->name;
            $sizes[] = $file->size;
        }

        $joinedNames = implode("\0", $names);
        if (substr_count($joinedNames, "\0") !== $count - 1) {
            return $this->wantedFilesPhp($files);
        }

        $packedSizes = pack('q*', ...$sizes);
        $videoRegex = $this->config->videoFileRegex;
        $needed = $count * 4 + 16;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            return $this->wantedFilesPhp($files);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_media_source_rank_wanted_files_nul(
                $joinedNames,
                strlen($joinedNames),
                $packedSizes,
                strlen($packedSizes),
                $videoRegex,
                strlen($videoRegex),
                $count,
                $scratch,
                $cap,
            );
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->wantedFilesPhp($files);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_media_source_rank_wanted_files_nul(
                    $joinedNames,
                    strlen($joinedNames),
                    $packedSizes,
                    strlen($packedSizes),
                    $videoRegex,
                    strlen($videoRegex),
                    $count,
                    $scratch,
                    $cap,
                );
            }
        } catch (Throwable) {
            return $this->wantedFilesPhp($files);
        }

        if (! is_int($written) || $written < 0) {
            return $this->wantedFilesPhp($files);
        }
        if ($written === 0) {
            $supported = true;

            return [];
        }
        if (($written & 3) !== 0) {
            return $this->wantedFilesPhp($files);
        }

        /** @var array<int, int>|false $order */
        $order = unpack('V*', FFI::string($scratch, $written));
        if ($order === false) {
            return $this->wantedFilesPhp($files);
        }

        $supported = true;
        $wanted = [];
        foreach ($order as $idx) {
            $wanted[] = $values[$idx];
        }

        return $wanted;
    }

    /**
     * Pure PHP reference implementation of `wantedFiles()`.
     *
     * @param  list<Par2FileEntry>  $files
     * @return list<Par2FileEntry>
     */
    public function wantedFilesPhp(array $files): array
    {
        $ranked = [];
        foreach ($files as $file) {
            $rank = match (true) {
                preg_match('/\.(?:part0*1\.rar|rar|7z(?:\.0*1)?|zip|0*1)$/i', $file->name) === 1
                    && preg_match('/\.part(?!0*1\.)\d+\.rar$/i', $file->name) !== 1 => 0,
                preg_match('/'.$this->config->videoFileRegex.'$/i', $file->name) === 1
                    && preg_match('/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i', $file->name) === 1 => 1,
                preg_match('/'.$this->config->videoFileRegex.'$/i', $file->name) === 1 => 2,
                preg_match('/\.(?:jpe?g|png|webp)$/i', $file->name) === 1 => 3,
                default => null,
            };
            if ($rank !== null) {
                $ranked[] = [$rank, -$file->size, $file];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($ranked, 2);
    }

    private function estimatedBytes(ReleaseProcessingContext $context, int $sourceIndex, int $articles): int
    {
        $entry = $context->nzbContents[$sourceIndex] ?? [];
        $segments = is_array($entry['segments'] ?? null) ? max(1, count($entry['segments'])) : 1;

        return (int) ceil((int) ($entry['size'] ?? 0) / $segments * min($articles, $segments));
    }
}
