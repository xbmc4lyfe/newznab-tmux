<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Facades\Log;

final readonly class AdditionalWorkPlanner
{
    private const string DEFAULT_AUDIO_FILE_REGEX = '\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)';

    private const string DEFAULT_IGNORE_BOOK_REGEX = '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i';

    private const string DEFAULT_SUPPORT_FILE_REGEX = '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)';

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const string SEVEN_ZIP_PATTERN = '/([^"\s\/]+)\.7z(?:\.(\d{3}))?($|[ ")]|-)/i';

    /** A quoted "name.001", or a bare one ending the subject or followed by yEnc or a part count. */
    private const string NUMBERED_VOLUME_PATTERN = '/"([^"\r\n]+)\.(\d{3})"|(?:^|\s)([^"\s\/]+)\.(\d{3})(?=$|\s+(?:yEnc\b|\(\d+\/\d+\)))/i';

    private const string ARCHIVE_PATTERN = '/(\.(part\d+|[rz]\d+|rar|7z|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i';

    /** Posted images smaller than this are icons or banners. */
    private const int MIN_IMAGE_POSTED_BYTES = 10240;

    private const int MAX_IMAGE_CANDIDATES = 3;

    private const int TYPICAL_ARTICLE_BYTES = 716800;

    public function __construct(
        private ProcessingConfiguration $config,
        private ?string $libraryPath = null,
    ) {}

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function plan(array $nzbContents, string $groupName): AdditionalWorkPlan
    {
        if ($this->canUseRustClassifier()) {
            $rustPlan = $this->planRust($nzbContents, $groupName);
            if ($rustPlan !== null) {
                return $rustPlan;
            }
        }

        return $this->planPhp($nzbContents, $groupName);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function planPhp(array $nzbContents, string $groupName): AdditionalWorkPlan
    {
        $sampleMessageIds = [];
        $jpgMessageIds = [];
        $images = [];
        $mediaInfoMessageId = '';
        $audioInfoMessageId = '';
        $audioInfoExtension = '';
        $archiveCandidates = [];
        $bookFileCount = 0;
        $duplicateMessageIdCount = 0;
        $seenMessageIds = [];
        $sevenZipTails = [];

        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file)) {
                continue;
            }

            try {
                $title = (string) ($file['title'] ?? '');
                $segments = is_array($file['segments'] ?? null) ? $file['segments'] : [];

                if (preg_match($this->config->ignoreBookRegex, $title) === 1) {
                    $bookFileCount++;
                }

                // 7z keeps its file list at the end of the last volume.
                $volume = $this->splitVolume($title);
                if ($volume !== null && $segments !== []
                    && ($sevenZipTails[$volume[0]][0] ?? -1) < $volume[1]
                ) {
                    $sevenZipTails[$volume[0]] = [$volume[1], (string) $segments[array_key_last($segments)]];
                }

                // HJSplit-style pieces of a video (name.mkv.001) are usually plain media, but may
                // hide an archive: only the first article of the first piece is checked, by its data.
                $mediaSplit = $this->mediaSplitVolume($title);
                if ($mediaSplit !== null) {
                    $messageIds = $mediaSplit <= 1
                        ? $this->extractSegments($segments, 1, $seenMessageIds, $duplicateMessageIdCount)
                        : [];
                    if ($messageIds !== []) {
                        $archiveCandidates[] = new ArchiveCandidate(
                            title: $title,
                            messageIds: $messageIds,
                            likelyFirstVolume: true,
                            sourceIndex: $sourceIndex,
                            hasFirstSegment: ($file['firstsegment'] ?? true) !== false,
                            setKey: $this->archiveSetKey($title),
                        );
                    }
                } elseif (preg_match(self::ARCHIVE_PATTERN, $title) === 1) {
                    $archiveMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->maximumRarSegments,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                    if ($archiveMessageIds !== []) {
                        $archiveCandidates[] = new ArchiveCandidate(
                            title: $title,
                            messageIds: $archiveMessageIds,
                            likelyFirstVolume: $this->isLikelyFirstVolume($title),
                            sourceIndex: $sourceIndex,
                            hasFirstSegment: ($file['firstsegment'] ?? true) !== false,
                            setKey: $this->archiveSetKey($title),
                        );
                    }
                }

                if ($this->isSupportFile($title)) {
                    continue;
                }

                if ($this->config->processThumbnails && $sampleMessageIds === [] && $segments !== []
                    && stripos($title, 'sample') !== false
                    && preg_match('/\.(?:jpe?g|png|webp)$/i', $title) !== 1
                    && preg_match(self::ARCHIVE_PATTERN, $title) !== 1
                ) {
                    $sampleMessageIds = $this->extractSegments(
                        $segments,
                        $this->config->segmentsToDownload,
                        $seenMessageIds,
                        $duplicateMessageIdCount,
                    );
                }

                if ($this->config->processJPGSample && $segments !== []
                    && preg_match('/flac|lossless|mp3|music|inner-sanctum|sound/i', $groupName) !== 1
                    && preg_match('/\.(?:jpe?g|png|webp)(?:[. ")\]]|$)/i', $title) === 1
                ) {
                    $images[] = [$sourceIndex, $title, $segments, (int) ($file['size'] ?? 0)];
                }

                if ($this->config->processMediaInfo && $mediaInfoMessageId === '' && isset($segments[0])
                    && stripos($title, 'sample') !== false
                    && preg_match('/'.$this->config->videoFileRegex.'[. ")\]]/i', $title) === 1
                ) {
                    $mediaInfoMessageId = (string) $segments[0];
                    $this->recordMessageId($mediaInfoMessageId, $seenMessageIds, $duplicateMessageIdCount);
                }

                if ($this->config->processAudioInfo && $audioInfoMessageId === '' && isset($segments[0])
                    && preg_match('/'.$this->config->audioFileRegex.'[. ")\]]/i', $title, $type) === 1
                ) {
                    $audioInfoExtension = (string) ($type[1] ?? '');
                    $audioInfoMessageId = (string) $segments[0];
                    $this->recordMessageId($audioInfoMessageId, $seenMessageIds, $duplicateMessageIdCount);
                }
            } catch (\ErrorException $e) {
                Log::debug($e->getTraceAsString());
            }
        }

        foreach ($archiveCandidates as $index => $candidate) {
            $volume = $this->splitVolume($candidate->title);
            $tail = $volume === null ? null : ($sevenZipTails[$volume[0]][1] ?? null);
            if ($tail !== null && $candidate->likelyFirstVolume && ! in_array($tail, $candidate->messageIds, true)) {
                $archiveCandidates[$index] = new ArchiveCandidate(
                    title: $candidate->title,
                    messageIds: $candidate->messageIds,
                    likelyFirstVolume: true,
                    sourceIndex: $candidate->sourceIndex,
                    tailMessageIds: [$tail],
                    hasFirstSegment: $candidate->hasFirstSegment,
                    setKey: $candidate->setKey,
                );
            }
        }

        $archiveCandidates = $this->preferRarVolumesWithHeaders($archiveCandidates);

        // Files without an extension can't be classified by name; sample their first segments.
        // Each entry must stand for exactly one NZB file, not several merged by a stripped subject.
        $probeCandidates = [];
        $probeIsOnlyFile = false;
        if ($archiveCandidates === []) {
            $unnamed = [];
            foreach (array_values($nzbContents) as $sourceIndex => $file) {
                if (is_array($file) && isset($file['segments'][0])
                    && (int) ($file['filecount'] ?? 0) === 1
                    && ! $this->hasFileExtension((string) ($file['title'] ?? ''))
                ) {
                    $unnamed[$sourceIndex] = $file;
                }
            }
            $probeIsOnlyFile = $unnamed !== [] && count($nzbContents) === 1;

            // Archive volumes carry the payload, so the largest files are tried first.
            uasort($unnamed, static fn (array $a, array $b): int => (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0));
            foreach (array_slice($unnamed, 0, $probeIsOnlyFile ? 1 : $this->config->archiveProbeFiles, true) as $sourceIndex => $file) {
                $messageIds = $this->extractSegments(
                    $file['segments'],
                    max(1, $this->config->maximumRarSegments),
                    $seenMessageIds,
                    $duplicateMessageIdCount,
                );
                if ($messageIds === []) {
                    continue;
                }

                // A 7z found by the probe needs its last segment for the file list.
                $lastSegment = (string) $file['segments'][array_key_last($file['segments'])];
                $probeCandidates[] = new ArchiveCandidate(
                    title: (string) ($file['title'] ?? ''),
                    messageIds: $messageIds,
                    likelyFirstVolume: false,
                    sourceIndex: $sourceIndex,
                    tailMessageIds: $lastSegment !== $messageIds[0] ? [$lastSegment] : [],
                );
            }
        }

        $imageCandidates = $this->imageCandidates($images, $seenMessageIds, $duplicateMessageIdCount);
        $jpgMessageIds = $imageCandidates[0]->messageIds ?? [];

        $par2IndexCandidates = $this->config->par2MediaMapping
            ? $this->par2IndexCandidates($nzbContents)
            : [];

        $bookFlood = $bookFileCount > 80 && ($bookFileCount * 2) >= count($nzbContents);
        $unsupportedReasons = [];
        if ($bookFlood) {
            $unsupportedReasons[] = 'book-flood';
        }
        if ($archiveCandidates === []
            && $sampleMessageIds === []
            && $jpgMessageIds === []
            && $mediaInfoMessageId === ''
            && $audioInfoMessageId === ''
            && $probeCandidates === []
        ) {
            $unsupportedReasons[] = 'no-supported-candidates';
        }

        return new AdditionalWorkPlan(
            sampleMessageIds: $sampleMessageIds,
            jpgMessageIds: $jpgMessageIds,
            mediaInfoMessageId: $mediaInfoMessageId,
            audioInfoMessageId: $audioInfoMessageId,
            audioInfoExtension: $audioInfoExtension,
            archiveCandidates: $archiveCandidates,
            bookFileCount: $bookFileCount,
            bookFlood: $bookFlood,
            duplicateMessageIdCount: $duplicateMessageIdCount,
            unsupportedReasons: $unsupportedReasons,
            probeCandidates: $probeCandidates,
            probeIsOnlyFile: $probeIsOnlyFile,
            par2IndexCandidates: $par2IndexCandidates,
            mainVideoSourceIndexes: $this->mainVideoSourceIndexes($nzbContents),
            imageCandidates: $imageCandidates,
        );
    }

    private function canUseRustClassifier(): bool
    {
        return $this->config->audioFileRegex === self::DEFAULT_AUDIO_FILE_REGEX
            && $this->config->ignoreBookRegex === self::DEFAULT_IGNORE_BOOK_REGEX
            && $this->config->supportFileRegex === self::DEFAULT_SUPPORT_FILE_REGEX
            && $this->config->videoFileRegex === self::DEFAULT_VIDEO_FILE_REGEX;
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    private function planRust(array $nzbContents, string $groupName): ?AdditionalWorkPlan
    {
        if (str_contains($groupName, "\0")) {
            return null;
        }

        $filesList = [];
        $titles = [];
        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file)) {
                continue;
            }
            $title = (string) ($file['title'] ?? '');
            if (str_contains($title, "\0")) {
                return null;
            }
            $filesList[$sourceIndex] = $file;
            $titles[] = $title;
        }

        $count = count($titles);
        $raw = RustPipelineLibrary::additionalClassifyTitles(
            $groupName,
            implode("\0", $titles),
            $count,
            $this->libraryPath,
        );
        if ($raw === null) {
            return null;
        }

        $tokens = explode("\0", $raw);
        $cursor = 0;
        $isMusicGroup = ($tokens[$cursor++] ?? '0') === '1';

        $sampleMessageIds = [];
        $images = [];
        $mediaInfoMessageId = '';
        $audioInfoMessageId = '';
        $audioInfoExtension = '';
        $archiveCandidates = [];
        $candidateSplitSets = [];
        $anyLikelyFirstMissingHeader = false;
        $bookFileCount = 0;
        $duplicateMessageIdCount = 0;
        $seenMessageIds = [];
        $sevenZipTails = [];
        $unnamedForProbe = [];
        $par2Named = [];
        $par2Unnamed = [];
        $mainVideoSizes = [];
        $wantMainVideo = $this->config->mediaMainVideoFallback && $this->config->processThumbnails;
        $maxRarSegments = max($this->config->maximumRarSegments, 0);
        $prevSetKey = '';
        $titleIdx = 0;

        foreach ($filesList as $sourceIndex => $file) {
            $title = $titles[$titleIdx++];
            $flags = (int) ($tokens[$cursor++] ?? '0');
            $splitSet = ($flags & (1 << 1)) !== 0 ? ($tokens[$cursor++] ?? '') : null;
            if (($flags & (1 << 5)) !== 0) {
                if (($flags & (1 << 58)) !== 0) {
                    $setKey = $prevSetKey;
                } else {
                    $setKey = $tokens[$cursor++] ?? '';
                    $prevSetKey = $setKey;
                }
            } else {
                $setKey = '';
            }
            $audioExt = ($flags & (1 << 10)) !== 0 ? ($tokens[$cursor++] ?? '') : '';

            $segments = is_array($file['segments'] ?? null) ? $file['segments'] : [];
            $hasSegments = isset($segments[0]);
            $fileCountDefault0 = (int) ($file['filecount'] ?? 0);
            $fileCountDefault1 = (int) ($file['filecount'] ?? 1);
            $hasExt = ($flags & (1 << 11)) !== 0;

            if ($hasSegments && $fileCountDefault0 === 1 && ! $hasExt) {
                $unnamedForProbe[$sourceIndex] = $file;
            }
            if ($this->config->par2MediaMapping && $hasSegments && $fileCountDefault1 === 1) {
                if (($flags & (1 << 12)) !== 0) {
                    if (($flags & (1 << 13)) === 0) {
                        $par2Named[$sourceIndex] = $file;
                    }
                } elseif (! $hasExt) {
                    $par2Unnamed[$sourceIndex] = $file;
                }
            }
            if ($wantMainVideo && ($flags & (1 << 14)) !== 0 && $hasSegments && $fileCountDefault1 === 1) {
                $size = (int) ($file['size'] ?? 0);
                if ($size >= $this->config->mediaMainVideoMinBytes) {
                    $mainVideoSizes[$sourceIndex] = $size;
                }
            }

            if (($flags & (1 << 0)) !== 0) {
                $bookFileCount++;
            }

            if ($splitSet !== null && $segments !== []) {
                $splitVolNum = ($flags >> 17) & 0x7FF;
                if (($sevenZipTails[$splitSet][0] ?? -1) < $splitVolNum) {
                    $sevenZipTails[$splitSet] = [$splitVolNum, (string) $segments[array_key_last($segments)]];
                }
            }

            if (($flags & (1 << 2)) !== 0) {
                $mediaSplitVol = ($flags >> 28) & 0x7FF;
                $messageIds = $mediaSplitVol <= 1
                    ? $this->extractSegments($segments, 1, $seenMessageIds, $duplicateMessageIdCount)
                    : [];
                if ($messageIds !== []) {
                    $candIdx = count($archiveCandidates);
                    $hasFirstSeg = ($file['firstsegment'] ?? true) !== false;
                    if (! $hasFirstSeg) {
                        $anyLikelyFirstMissingHeader = true;
                    }
                    $archiveCandidates[] = new ArchiveCandidate(
                        title: $title,
                        messageIds: $messageIds,
                        likelyFirstVolume: true,
                        sourceIndex: $sourceIndex,
                        hasFirstSegment: $hasFirstSeg,
                        setKey: $setKey,
                    );
                    if ($splitSet !== null) {
                        $candidateSplitSets[$candIdx] = $splitSet;
                    }
                }
            } elseif (($flags & (1 << 3)) !== 0) {
                $archiveMessageIds = [];
                if ($maxRarSegments > 0 && $segments !== []) {
                    $reqSeen = [];
                    $taken = 0;
                    foreach ($segments as $seg) {
                        $mid = (string) $seg;
                        if ($mid === '' || isset($reqSeen[$mid])) {
                            if ($mid !== '') {
                                $duplicateMessageIdCount++;
                            }
                        } else {
                            $reqSeen[$mid] = true;
                            $archiveMessageIds[] = $mid;
                            if (isset($seenMessageIds[$mid])) {
                                $duplicateMessageIdCount++;
                            } else {
                                $seenMessageIds[$mid] = true;
                            }
                        }
                        if (++$taken >= $maxRarSegments) {
                            break;
                        }
                    }
                }
                if ($archiveMessageIds !== []) {
                    $candIdx = count($archiveCandidates);
                    $hasFirstSeg = ($file['firstsegment'] ?? true) !== false;
                    $likelyFirst = ($flags & (1 << 4)) !== 0;
                    if ($likelyFirst && ! $hasFirstSeg) {
                        $anyLikelyFirstMissingHeader = true;
                    }
                    $archiveCandidates[] = new ArchiveCandidate(
                        title: $title,
                        messageIds: $archiveMessageIds,
                        likelyFirstVolume: $likelyFirst,
                        sourceIndex: $sourceIndex,
                        hasFirstSegment: $hasFirstSeg,
                        setKey: $setKey,
                    );
                    if ($splitSet !== null) {
                        $candidateSplitSets[$candIdx] = $splitSet;
                    }
                }
            }

            if (($flags & (1 << 6)) !== 0) {
                continue;
            }

            if ($this->config->processThumbnails && $sampleMessageIds === [] && $segments !== []
                && ($flags & (1 << 7)) !== 0
            ) {
                $sampleMessageIds = $this->extractSegments(
                    $segments,
                    $this->config->segmentsToDownload,
                    $seenMessageIds,
                    $duplicateMessageIdCount,
                );
            }

            if ($this->config->processJPGSample && $segments !== []
                && ! $isMusicGroup
                && ($flags & (1 << 8)) !== 0
            ) {
                $images[] = [$sourceIndex, $title, $segments, (int) ($file['size'] ?? 0), ($flags >> 15) & 3];
            }

            if ($this->config->processMediaInfo && $mediaInfoMessageId === '' && $hasSegments
                && ($flags & (1 << 9)) !== 0
            ) {
                $mediaInfoMessageId = (string) $segments[0];
                $this->recordMessageId($mediaInfoMessageId, $seenMessageIds, $duplicateMessageIdCount);
            }

            if ($this->config->processAudioInfo && $audioInfoMessageId === '' && $hasSegments
                && ($flags & (1 << 10)) !== 0
            ) {
                $audioInfoExtension = $audioExt;
                $audioInfoMessageId = (string) $segments[0];
                $this->recordMessageId($audioInfoMessageId, $seenMessageIds, $duplicateMessageIdCount);
            }
        }

        foreach ($candidateSplitSets as $index => $splitSet) {
            $candidate = $archiveCandidates[$index];
            $tail = $sevenZipTails[$splitSet][1] ?? null;
            if ($tail !== null && $candidate->likelyFirstVolume && ! in_array($tail, $candidate->messageIds, true)) {
                $archiveCandidates[$index] = new ArchiveCandidate(
                    title: $candidate->title,
                    messageIds: $candidate->messageIds,
                    likelyFirstVolume: true,
                    sourceIndex: $candidate->sourceIndex,
                    tailMessageIds: [$tail],
                    hasFirstSegment: $candidate->hasFirstSegment,
                    setKey: $candidate->setKey,
                );
            }
        }

        if ($anyLikelyFirstMissingHeader) {
            $archiveCandidates = $this->preferRarVolumesWithHeaders($archiveCandidates);
        }

        $probeCandidates = [];
        $probeIsOnlyFile = false;
        if ($archiveCandidates === []) {
            $probeIsOnlyFile = $unnamedForProbe !== [] && count($nzbContents) === 1;
            uasort($unnamedForProbe, static fn (array $a, array $b): int => (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0));
            foreach (array_slice($unnamedForProbe, 0, $probeIsOnlyFile ? 1 : $this->config->archiveProbeFiles, true) as $sourceIndex => $file) {
                $messageIds = $this->extractSegments(
                    $file['segments'],
                    max(1, $this->config->maximumRarSegments),
                    $seenMessageIds,
                    $duplicateMessageIdCount,
                );
                if ($messageIds === []) {
                    continue;
                }
                $lastSegment = (string) $file['segments'][array_key_last($file['segments'])];
                $probeCandidates[] = new ArchiveCandidate(
                    title: (string) ($file['title'] ?? ''),
                    messageIds: $messageIds,
                    likelyFirstVolume: false,
                    sourceIndex: $sourceIndex,
                    tailMessageIds: $lastSegment !== $messageIds[0] ? [$lastSegment] : [],
                );
            }
        }

        usort($images, static fn (array $a, array $b): int => [$a[4], -$a[3]] <=> [$b[4], -$b[3]]);
        $imageCandidates = [];
        foreach ($images as [$sourceIndex, $title, $segments, $postedSize]) {
            if ($postedSize > 0 && ($postedSize < self::MIN_IMAGE_POSTED_BYTES || $postedSize > $this->config->mediaImageMaxBytes)) {
                continue;
            }
            $limit = $postedSize > 0
                ? count($segments)
                : max($this->config->segmentsToDownload, (int) ceil($this->config->mediaImageMaxBytes / self::TYPICAL_ARTICLE_BYTES));
            $messageIds = $this->extractSegments($segments, $limit, $seenMessageIds, $duplicateMessageIdCount);
            if ($messageIds === []) {
                continue;
            }
            $imageCandidates[] = new ImageCandidate($title, $messageIds, $sourceIndex, $postedSize);
            if (count($imageCandidates) >= self::MAX_IMAGE_CANDIDATES) {
                break;
            }
        }
        $jpgMessageIds = $imageCandidates[0]->messageIds ?? [];

        $par2IndexCandidates = [];
        if ($this->config->par2MediaMapping) {
            $bySize = static fn (array $a, array $b): int => (int) ($a['size'] ?? 0) <=> (int) ($b['size'] ?? 0);
            uasort($par2Named, $bySize);
            uasort($par2Unnamed, $bySize);
            $chosen = $par2Named !== []
                ? array_slice($par2Named, 0, 1, true)
                : (count($par2Unnamed) >= 3 ? array_slice($par2Unnamed, 0, 2, true) : []);
            foreach ($chosen as $sourceIndex => $file) {
                $par2IndexCandidates[] = new ArchiveCandidate(
                    title: (string) ($file['title'] ?? ''),
                    messageIds: array_values(array_unique(array_map(
                        strval(...),
                        array_slice($file['segments'], 0, $this->config->par2IndexMaxSegments),
                    ))),
                    likelyFirstVolume: false,
                    sourceIndex: $sourceIndex,
                );
            }
        }

        $mainVideoSourceIndexes = [];
        if ($mainVideoSizes !== []) {
            arsort($mainVideoSizes);
            $mainVideoSourceIndexes = array_slice(array_keys($mainVideoSizes), 0, 2);
        }

        $bookFlood = $bookFileCount > 80 && ($bookFileCount * 2) >= count($nzbContents);
        $unsupportedReasons = [];
        if ($bookFlood) {
            $unsupportedReasons[] = 'book-flood';
        }
        if ($archiveCandidates === []
            && $sampleMessageIds === []
            && $jpgMessageIds === []
            && $mediaInfoMessageId === ''
            && $audioInfoMessageId === ''
            && $probeCandidates === []
        ) {
            $unsupportedReasons[] = 'no-supported-candidates';
        }

        return new AdditionalWorkPlan(
            sampleMessageIds: $sampleMessageIds,
            jpgMessageIds: $jpgMessageIds,
            mediaInfoMessageId: $mediaInfoMessageId,
            audioInfoMessageId: $audioInfoMessageId,
            audioInfoExtension: $audioInfoExtension,
            archiveCandidates: $archiveCandidates,
            bookFileCount: $bookFileCount,
            bookFlood: $bookFlood,
            duplicateMessageIdCount: $duplicateMessageIdCount,
            unsupportedReasons: $unsupportedReasons,
            probeCandidates: $probeCandidates,
            probeIsOnlyFile: $probeIsOnlyFile,
            par2IndexCandidates: $par2IndexCandidates,
            mainVideoSourceIndexes: $mainVideoSourceIndexes,
            imageCandidates: $imageCandidates,
        );
    }

    /**
     * The best few posted images, each with every article it needs so it arrives whole.
     * Icons and images too large to download whole are skipped.
     *
     * @param  list<array{0: int, 1: string, 2: array<int|string, mixed>, 3: int}>  $images  [source index, subject, segments, posted size]
     * @param  array<string, true>  $seenMessageIds
     * @return list<ImageCandidate>
     */
    private function imageCandidates(array $images, array &$seenMessageIds, int &$duplicateMessageIdCount): array
    {
        $candidates = [];
        $ranked = ImageCandidateRanker::sort(
            $images,
            static fn (array $image): string => $image[1],
            static fn (array $image): int => $image[3],
        );

        foreach ($ranked as [$sourceIndex, $title, $segments, $postedSize]) {
            if ($postedSize > 0 && ($postedSize < self::MIN_IMAGE_POSTED_BYTES || $postedSize > $this->config->mediaImageMaxBytes)) {
                continue;
            }

            // Without a posted size, fetch as many articles as the size limit could fill.
            $limit = $postedSize > 0
                ? count($segments)
                : max($this->config->segmentsToDownload, (int) ceil($this->config->mediaImageMaxBytes / self::TYPICAL_ARTICLE_BYTES));
            $messageIds = $this->extractSegments($segments, $limit, $seenMessageIds, $duplicateMessageIdCount);
            if ($messageIds === []) {
                continue;
            }

            $candidates[] = new ImageCandidate($title, $messageIds, $sourceIndex, $postedSize);
            if (count($candidates) >= self::MAX_IMAGE_CANDIDATES) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * The PAR2 index lists every file's real name and size. It is the smallest .par2 that
     * is not a recovery volume or, in an obfuscated release, one of its smallest unnamed files.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<ArchiveCandidate>
     */
    private function par2IndexCandidates(array $nzbContents): array
    {
        $named = [];
        $unnamed = [];
        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file) || ! isset($file['segments'][0]) || (int) ($file['filecount'] ?? 1) !== 1) {
                continue;
            }

            $title = (string) ($file['title'] ?? '');
            if (preg_match('/\.par2\b/i', $title) === 1) {
                if (preg_match('/\.vol\d+[+-]\d+\.par2\b/i', $title) !== 1) {
                    $named[$sourceIndex] = $file;
                }
            } elseif (! $this->hasFileExtension($title)) {
                $unnamed[$sourceIndex] = $file;
            }
        }

        $bySize = static fn (array $a, array $b): int => (int) ($a['size'] ?? 0) <=> (int) ($b['size'] ?? 0);
        uasort($named, $bySize);
        uasort($unnamed, $bySize);
        $chosen = $named !== []
            ? array_slice($named, 0, 1, true)
            // A lone unnamed file is the release itself, not an index of it.
            : (count($unnamed) >= 3 ? array_slice($unnamed, 0, 2, true) : []);

        $candidates = [];
        foreach ($chosen as $sourceIndex => $file) {
            $candidates[] = new ArchiveCandidate(
                title: (string) ($file['title'] ?? ''),
                messageIds: array_values(array_unique(array_map(
                    strval(...),
                    array_slice($file['segments'], 0, $this->config->par2IndexMaxSegments),
                ))),
                likelyFirstVolume: false,
                sourceIndex: $sourceIndex,
            );
        }

        return $candidates;
    }

    /**
     * Full-length video files posted as they are, which can stand in for a missing sample.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int>
     */
    private function mainVideoSourceIndexes(array $nzbContents): array
    {
        if (! $this->config->mediaMainVideoFallback || ! $this->config->processThumbnails) {
            return [];
        }

        $sizes = [];
        foreach (array_values($nzbContents) as $sourceIndex => $file) {
            if (! is_array($file) || ! isset($file['segments'][0]) || (int) ($file['filecount'] ?? 1) !== 1) {
                continue;
            }

            $title = (string) ($file['title'] ?? '');
            if ((int) ($file['size'] ?? 0) >= $this->config->mediaMainVideoMinBytes
                && stripos($title, 'sample') === false
                && preg_match(self::ARCHIVE_PATTERN, $title) !== 1
                && preg_match('/'.$this->config->videoFileRegex.'(?:[. ")\]]|$)/i', $title) === 1
            ) {
                $sizes[$sourceIndex] = (int) $file['size'];
            }
        }

        arsort($sizes);

        return array_slice(array_keys($sizes), 0, 2);
    }

    /**
     * Every RAR volume starts with its own archive and file headers, but only in segment 1.
     * When the first volume lacks that segment, inspect the earliest volume that has it.
     *
     * @param  list<ArchiveCandidate>  $candidates
     * @return list<ArchiveCandidate>
     */
    private function preferRarVolumesWithHeaders(array $candidates): array
    {
        $volumes = [];
        foreach ($candidates as $index => $candidate) {
            $volume = $this->rarVolume($candidate->title);
            if ($volume !== null) {
                $volumes[$volume[0]][$index] = $volume[1];
            }
        }

        foreach ($volumes as $set) {
            asort($set);
            $first = array_key_first($set);
            if (! $candidates[$first]->likelyFirstVolume || $candidates[$first]->hasFirstSegment) {
                continue;
            }

            foreach (array_keys($set) as $index) {
                if ($candidates[$index]->hasFirstSegment) {
                    $candidates[$first] = $this->withLikelyFirstVolume($candidates[$first], false);
                    $candidates[$index] = $this->withLikelyFirstVolume($candidates[$index], true);
                    break;
                }
            }
        }

        return $candidates;
    }

    private function withLikelyFirstVolume(ArchiveCandidate $candidate, bool $likelyFirstVolume): ArchiveCandidate
    {
        return new ArchiveCandidate(
            title: $candidate->title,
            messageIds: $candidate->messageIds,
            likelyFirstVolume: $likelyFirstVolume,
            sourceIndex: $candidate->sourceIndex,
            tailMessageIds: $candidate->tailMessageIds,
            hasFirstSegment: $candidate->hasFirstSegment,
            setKey: $candidate->setKey,
        );
    }

    /**
     * Key shared by every volume of one archive set (Show.S01E01.part01.rar and
     * Show.S01E01.part02.rar), so a season pack packed per episode can be read set by set.
     */
    private function archiveSetKey(string $title): string
    {
        return ArchiveCandidate::volumeSetKey($title);
    }

    /**
     * @return array{0: string, 1: int}|null set name and volume order (.rar before .r00, part01 before part02)
     */
    private function rarVolume(string $title): ?array
    {
        [$name, $prefix] = preg_match('/"([^"\r\n]+)"/', $title, $quoted) === 1
            ? [$quoted[1], '^(.+)']
            : [$title, '([^"\s\/]+)'];

        if (preg_match('/'.$prefix.'\.part(\d+)\.rar(?:$|[ ")-])/i', $name, $match) === 1) {
            return [strtolower($match[1]), (int) $match[2]];
        }
        if (preg_match('/'.$prefix.'\.rar(?:$|[ ")-])/i', $name, $match) === 1) {
            return [strtolower($match[1]), 0];
        }
        if (preg_match('/'.$prefix.'\.r(\d{2,3})(?:$|[ ")-])/i', $name, $match) === 1) {
            return [strtolower($match[1]), (int) $match[2] + 1];
        }

        return null;
    }

    private function mediaSplitVolume(string $title): ?int
    {
        if (preg_match('/'.$this->config->videoFileRegex.'\.(?<volume>\d{3})$/i', ArchiveCandidate::fileName($title), $match) !== 1) {
            return null;
        }

        return (int) $match['volume'];
    }

    private function hasFileExtension(string $title): bool
    {
        $name = preg_match('/"([^"]+)"/', $title, $quoted) === 1 ? $quoted[1] : $title;

        return preg_match('/\.[a-z0-9]{2,4}$/i', trim($name)) === 1;
    }

    private function isSupportFile(string $title): bool
    {
        return preg_match(
            '/(?:'.$this->config->supportFileRegex.'|nfo\b|inf\b|ofn\b)($|[ ")]|-])(?!.{20,})/i',
            $title,
        ) === 1;
    }

    private function isLikelyFirstVolume(string $title): bool
    {
        if (preg_match('/\.part0*(\d+)/i', $title, $part) === 1) {
            return (int) $part[1] === 1;
        }

        if (preg_match('/"[a-f0-9]{32}\.[1-9]\d{1,2}".*\((\d+)\/\d{2,}\)$/i', $title, $position) === 1) {
            return (int) $position[1] === 1;
        }

        $sevenZip = $this->sevenZipVolume($title);
        if ($sevenZip !== null) {
            return $sevenZip[1] <= 1;
        }

        if (preg_match('/\.(rar|zip)($|[ ")]|-])/i', $title) === 1) {
            return true;
        }

        $numbered = $this->numberedVolume($title);

        return $numbered !== null && $numbered[1] <= 1;
    }

    /**
     * A 7z volume, or a plain numbered split volume (`name.001`) that may turn out to be 7z
     * once its first bytes are read, so the last volume's tail is kept for both.
     *
     * @return array{0: string, 1: int}|null set name and volume number (0 for a single .7z)
     */
    private function splitVolume(string $title): ?array
    {
        return $this->sevenZipVolume($title) ?? $this->numberedVolume($title);
    }

    /**
     * @return array{0: string, 1: int}|null set name (kept apart from 7z set names) and volume number
     */
    private function numberedVolume(string $title): ?array
    {
        if (preg_match(self::NUMBERED_VOLUME_PATTERN, $title, $match) !== 1) {
            return null;
        }

        $quoted = $match[1] !== '';

        return ['#'.strtolower($quoted ? $match[1] : $match[3]), (int) ($quoted ? $match[2] : $match[4])];
    }

    /**
     * @return array{0: string, 1: int}|null set name and volume number (0 for a single .7z)
     */
    private function sevenZipVolume(string $title): ?array
    {
        if (preg_match('/"([^"\r\n]+)\.7z(?:\.(\d{3}))?"/i', $title, $match) === 1) {
            return [strtolower($match[1]), (int) ($match[2] ?? 0)];
        }

        if (preg_match(self::SEVEN_ZIP_PATTERN, $title, $match) !== 1) {
            return null;
        }

        return [strtolower($match[1]), (int) $match[2]];
    }

    /**
     * @param  array<int|string, mixed>  $segments
     * @param  array<string, true>  $seenMessageIds
     * @return list<string>
     */
    private function extractSegments(
        array $segments,
        int $limit,
        array &$seenMessageIds,
        int &$duplicateMessageIdCount,
    ): array {
        $messageIds = [];
        $requestMessageIds = [];

        foreach (array_slice($segments, 0, max($limit, 0)) as $segment) {
            $messageId = (string) $segment;
            if ($messageId === '' || isset($requestMessageIds[$messageId])) {
                if ($messageId !== '') {
                    $duplicateMessageIdCount++;
                }

                continue;
            }

            $requestMessageIds[$messageId] = true;
            $messageIds[] = $messageId;
            $this->recordMessageId($messageId, $seenMessageIds, $duplicateMessageIdCount);
        }

        return $messageIds;
    }

    /**
     * @param  array<string, true>  $seenMessageIds
     */
    private function recordMessageId(string $messageId, array &$seenMessageIds, int &$duplicateMessageIdCount): void
    {
        if (isset($seenMessageIds[$messageId])) {
            $duplicateMessageIdCount++;

            return;
        }

        $seenMessageIds[$messageId] = true;
    }
}
