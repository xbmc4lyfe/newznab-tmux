<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class AdditionalWorkPlanner
{
    /** Wave 40.6 paired benchmarks decide this default; see RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest. */
    public const bool W406_RUST_FFI_DEFAULT = false;

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

    public static function useW406RustFfi(?bool $enabled): void
    {
        self::w406Override(true, $enabled);
    }

    public static function usesW406RustFfi(): bool
    {
        return self::w406Override(false) ?? self::W406_RUST_FFI_DEFAULT;
    }

    public static function resetW406RustFfi(): void
    {
        self::w406Override(true, null);
    }

    /**
     * Summarize work-queue candidates. Non-array candidates only count toward the total and arrays whose
     * 'passworded' is exactly true are skipped. Every other candidate is eligible: a positive integer 'attempts'
     * makes it a retry, otherwise an integer 'age_hours' within 0..24 makes it high-priority fresh. The selected
     * batch is min(eligible, max(0, $maxBatchSize)).
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array{total_candidates: int, high_priority_fresh: int, retry_candidates: int, skipped_passworded: int, selected_batch: int}
     */
    public static function summarizeWorkQueuePriorityBatch(array $candidates, int $maxBatchSize = 100): array
    {
        return (self::usesW406RustFfi() ? self::summarizeWorkQueuePriorityBatchNative($candidates, $maxBatchSize) : null)
            ?? self::summarizeWorkQueuePriorityBatchPhp($candidates, $maxBatchSize);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{total_candidates: int, high_priority_fresh: int, retry_candidates: int, skipped_passworded: int, selected_batch: int}
     */
    public static function summarizeWorkQueuePriorityBatchPhp(array $candidates, int $maxBatchSize = 100): array
    {
        $fresh = 0;
        $retry = 0;
        $passworded = 0;
        $eligible = 0;
        foreach ($candidates as $candidate) {
            [$flags, $attempts, $age] = self::w406Candidate($candidate);
            if ($flags === 3) {
                $passworded++;
            } elseif ($flags === 1) {
                $eligible++;
                if ($attempts > 0) {
                    $retry++;
                } elseif ($age >= 0 && $age <= 24) {
                    $fresh++;
                }
            }
        }

        return [
            'total_candidates' => \count($candidates),
            'high_priority_fresh' => $fresh,
            'retry_candidates' => $retry,
            'skipped_passworded' => $passworded,
            'selected_batch' => min($eligible, max(0, $maxBatchSize)),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{total_candidates: int, high_priority_fresh: int, retry_candidates: int, skipped_passworded: int, selected_batch: int}|null
     */
    public static function summarizeWorkQueuePriorityBatchNative(array $candidates, int $maxBatchSize = 100): ?array
    {
        $total = \count($candidates);
        $frame = pack('qV', $maxBatchSize, $total);
        foreach ($candidates as $candidate) {
            [$flags, $attempts, $age] = self::w406Candidate($candidate);
            $frame .= pack('Cqq', $flags, $attempts, $age);
        }
        $slots = ArchiveExtractionService::w406Slots(5, $frame, 5);
        if ($slots === null || $slots[0] !== $total || $slots[1] < 0 || $slots[2] < 0 || $slots[3] < 0
            || $total < $slots[1] + $slots[2] + $slots[3] || $slots[4] < 0 || $slots[4] > $total - $slots[3]) {
            return null;
        }

        return [
            'total_candidates' => $total,
            'high_priority_fresh' => $slots[1],
            'retry_candidates' => $slots[2],
            'skipped_passworded' => $slots[3],
            'selected_batch' => $slots[4],
        ];
    }

    /** @return array{0: int, 1: int, 2: int} Flags (bit 0 array, bit 1 passworded), integer attempts and integer age in hours (-1 when absent). */
    private static function w406Candidate(mixed $candidate): array
    {
        if (! \is_array($candidate)) {
            return [0, 0, -1];
        }
        $attempts = $candidate['attempts'] ?? null;
        $age = $candidate['age_hours'] ?? null;

        return [
            ($candidate['passworded'] ?? null) === true ? 3 : 1,
            \is_int($attempts) ? $attempts : 0,
            \is_int($age) ? $age : -1,
        ];
    }

    /** Readonly classes cannot declare static properties, so the Wave 40.6 override lives in a function static. */
    private static function w406Override(bool $write, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $override */
        static $override = null;
        if ($write) {
            $override = $enabled;
        }

        return $override;
    }

    // ---------------------------------------------------------------------
    // Wave 52.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.23x, p99 2.04x; uncommon mean 2.13x, p99 2.01x); see RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w524_archive_volume_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** archiveVolumeBatch() flags. */
    public const int W52_VOLUME_RAR = 1;

    public const int W52_VOLUME_QUOTED = 2;

    public const int W52_VOLUME_EXTENSION = 4;

    /** rarVolume() patterns: [quoted name, bare subject] per volume family, in match order. */
    private const array W52_RAR_PATTERNS = [
        ['/^(.+)\.part(\d+)\.rar(?:$|[ ")-])/i', '/([^"\s\/]+)\.part(\d+)\.rar(?:$|[ ")-])/i'],
        ['/^(.+)\.rar(?:$|[ ")-])/i', '/([^"\s\/]+)\.rar(?:$|[ ")-])/i'],
        ['/^(.+)\.r(\d{2,3})(?:$|[ ")-])/i', '/([^"\s\/]+)\.r(\d{2,3})(?:$|[ ")-])/i'],
    ];

    /**
     * Classify NZB subjects for archive volume planning, four ints each: [flags, rarVolume()
     * order (.rar 0, .partN.rar N, .rNN NN + 1) or -1, rar set name offset << 32 | length in
     * the raw subject (rarVolume() lower-cases it) or -1, first `.part0*(\d+)` number or -1].
     * Flags: 1 rar volume, 2 quoted name without CR/LF, 4 hasFileExtension().
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function archiveVolumeBatch(array $titles): array
    {
        return self::usesW52RustFfi()
            ? self::archiveVolumeBatchNative($titles)
            : self::archiveVolumeBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function archiveVolumeBatchNative(array $titles): array
    {
        self::w52AssertList($titles);
        if ($titles === []) {
            return [];
        }

        [$text, $twin] = self::w52Text($titles);

        return self::w52Call($text, $twin, \count($titles))
            ?? self::archiveVolumeBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function archiveVolumeBatchPhp(array $titles): array
    {
        self::w52AssertList($titles);
        $out = [];
        foreach ($titles as $title) {
            $quoted = preg_match('/"([^"\r\n]+)"/', $title, $quote, PREG_OFFSET_CAPTURE) === 1;
            [$name, $base, $variant] = $quoted ? [$quote[1][0], $quote[1][1], 0] : [$title, 0, 1];
            $flags = $quoted ? self::W52_VOLUME_QUOTED : 0;
            $order = -1;
            $span = -1;
            foreach (self::W52_RAR_PATTERNS as $family => $patterns) {
                if (preg_match($patterns[$variant], $name, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $flags |= self::W52_VOLUME_RAR;
                    $order = match ($family) {
                        0 => (int) $match[2][0],
                        1 => 0,
                        default => (int) $match[2][0] + 1,
                    };
                    $span = (($base + $match[1][1]) << 32) | \strlen($match[1][0]);
                    break;
                }
            }
            $extensionName = preg_match('/"([^"]+)"/', $title, $any) === 1 ? $any[1] : $title;
            if (preg_match('/\.[a-z0-9]{2,4}$/i', trim($extensionName)) === 1) {
                $flags |= self::W52_VOLUME_EXTENSION;
            }
            $out[] = $flags;
            $out[] = $order;
            $out[] = $span;
            $out[] = preg_match('/\.part0*(\d+)/i', $title, $part) === 1 ? (int) $part[1] : -1;
        }

        return $out;
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W52 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('enabled');
        self::w52RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: encoded text and packed int64 columns in, 4 int64
     * words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w52Call(string $text, string $ints, int $count): ?array
    {
        try {
            $state = self::w52RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w52', self::W52_FFI_CDEF) ?? false;
                self::w52RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * 4;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w52RustFfiState('out', $out);
                self::w52RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w524_archive_volume_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Text column encoding: NUL-joined values and, when a value holds a NUL byte, the same
     * values joined with 0x01 so Rust finds the separators where the two buffers differ.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string}
     */
    private static function w52Text(array $values): array
    {
        $joined = implode("\0", $values);

        return [$joined, substr_count($joined, "\0") === \count($values) - 1 ? '' : implode("\x01", $values)];
    }

    /** @param array<array-key, mixed> $values */
    private static function w52AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W52 batch input must be a list.');
        }
    }

    /**
     * W52 routing override and cached binding. Readonly classes cannot declare static
     * properties, so they live in a function static: no arguments returns the state, one
     * argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ========================================================================
    // WAVE 64.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.38x, p99 4.96x; uncommon mean 4.54x, p99 3.73x); see RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_WORK_PLANNER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w641_work_planner_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w64WorkPlannerPlanBatch(). */
    public const int W64_WORK_PLANNER_TRAILER = 43;

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /** Forces the AdditionalWorkPlanner Wave 64 Rust path on until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Forces the AdditionalWorkPlanner Wave 64 PHP path until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Runs $callback with the AdditionalWorkPlanner Wave 64 Rust path forced on or off, then restores the previous setting.
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
     * Wave 64.1 work plans, one per NZB file `title|size|segments|filecount|group` (trimmed fields; numbers are 1-15
     * digits, empty 0). The name is the first non-empty `"..."` in the title, else the title; its lowercased last
     * extension of 1-5 `[a-z0-9]` bytes picks the kind: 0 empty title, 1 rar, 2 rar volume (`.partN.rar` or `.rNN`), 3
     * 7z (or `.7z.NNN`), 4 zip, 5 numbered split (`.NNN`), 6 par2, 7 nfo, diz or inf, 8 sfv, srs or nzb, 9 video (or a
     * `.video.NNN` split), 10 audio, 11 image, 12 book, 13 other, 14 no extension. Stages: 1 inspect archive, 2 likely
     * first volume, 4 PAR2 index, 8 NFO, 16 sample media info, 32 sample thumbnail, 64 JPG preview (10 KiB or more,
     * outside music groups), 128 audio info, 256 probe an unnamed single file, 512 book; each but the book needs segments.
     * Priority: 3 first volume, 2 archive, PAR2 index or NFO, 1 media, 0 none. Flags: 1 sample, 2 quoted name, 4 music
     * group, 8 media split, 16 PAR2 recovery volume, 32 non-ASCII, 64 archive set seen earlier in the batch, 128
     * malformed number. Per item `kind | stages << 4 | priority << 14 | flags << 16 | min(volume, 1023) << 24 |
     * min(segmentsNeeded, 3) << 34 | bitLength(size) << 36 | min(segments, 4095) << 42 | min(nameLength, 255) << 54`;
     * then 15 kind, 10 stage, 4 priority and 8 flag counts, the segments needed, the archive byte sum, the distinct
     * archive sets, the book-flood flag, the no-supported-candidates flag and the most segments.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64WorkPlannerPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64WorkPlannerPlanBatchNative($items) : self::w64WorkPlannerPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64WorkPlannerPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_WORK_PLANNER_TRAILER) ?? self::w64WorkPlannerPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64WorkPlannerPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W64_WORK_PLANNER_TRAILER, 0);
        $sets = [];
        $count = 0;
        $supported = false;
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawTitle, $rawSize, $rawSegments, $rawFiles, $rawGroup] = explode('|', $item, 5) + ['', '', '', '', ''];
            $count++;
            $title = trim($rawTitle);
            $name = $title;
            $flags = 0;
            $open = strpos($title, '"');
            if ($open !== false) {
                $close = strpos($title, '"', $open + 1);
                if ($close !== false && $close > $open + 1) {
                    $name = substr($title, $open + 1, $close - $open - 1);
                    $flags |= 2;
                }
            }
            $lower = strtolower($name);
            [$base, $extension] = self::w64SplitExtension($lower);
            $dot = strrpos($base, '.');
            [$base2, $inner] = $dot === false ? [$base, ''] : [substr($base, 0, $dot), substr($base, $dot + 1)];
            $volume = 0;
            $part = false;
            $set = $base;
            $extensionLength = \strlen($extension);
            $innerLength = \strlen($inner);
            if ($title === '') {
                $kind = 0;
            } elseif ($extension === '') {
                $kind = 14;
            } elseif ($extensionLength === 3 && strspn($extension, self::W64_DIGITS) === 3) {
                $volume = (int) $extension;
                if ($inner === '7z') {
                    $set = $base2;
                    $kind = 3;
                } elseif (isset(self::W64_VIDEO_EXTENSIONS[$inner])) {
                    $flags |= 8;
                    $kind = 9;
                } else {
                    $kind = 5;
                }
            } elseif ($extension === 'rar') {
                if ($innerLength > 4 && $innerLength <= 10 && str_starts_with($inner, 'part') && strspn($inner, self::W64_DIGITS, 4) === $innerLength - 4) {
                    $volume = (int) substr($inner, 4);
                    $part = true;
                    $set = $base2;
                    $kind = 2;
                } else {
                    $kind = 1;
                }
            } elseif ($extensionLength >= 3 && $extensionLength <= 4 && $extension[0] === 'r' && strspn($extension, self::W64_DIGITS, 1) === $extensionLength - 1) {
                $volume = (int) substr($extension, 1) + 1;
                $kind = 2;
            } elseif (isset(self::W64_NAMED_KINDS[$extension])) {
                $kind = self::W64_NAMED_KINDS[$extension];
                if ($kind === 6 && str_contains($lower, '.vol')) {
                    $flags |= 16;
                }
            } elseif (isset(self::W64_VIDEO_EXTENSIONS[$extension])) {
                $kind = 9;
            } elseif (isset(self::W64_AUDIO_EXTENSIONS[$extension])) {
                $kind = 10;
            } else {
                $kind = 13;
            }
            $size = self::w64Uint($rawSize, 0);
            $segments = self::w64Uint($rawSegments, 0);
            $files = self::w64Uint($rawFiles, 0);
            if ($size < 0 || $segments < 0 || $files < 0) {
                $flags |= 128;
            }
            $size = max(0, $size);
            $segments = max(0, $segments);
            $group = strtolower(trim($rawGroup));
            $music = false;
            foreach (self::W64_MUSIC_GROUP_NEEDLES as $needle) {
                if (str_contains($group, $needle)) {
                    $music = true;
                    break;
                }
            }
            $sample = str_contains($lower, 'sample');
            $flags |= ($sample ? 1 : 0) | ($music ? 4 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 32 : 0);
            $archive = $kind >= 1 && $kind <= 5;
            $first = match ($kind) {
                1, 4 => true,
                2 => $part && $volume === 1,
                3, 5 => $volume <= 1,
                default => false,
            };
            if ($archive) {
                if (isset($sets[$set])) {
                    $flags |= 64;
                } else {
                    $sets[$set] = true;
                }
            }
            $has = $segments > 0;
            $stages = ($archive && $has ? 1 | ($first ? 2 : 0) : 0) | ($kind === 6 && ($flags & 16) === 0 && $has ? 4 : 0) | ($kind === 7 && $has ? 8 : 0)
                | ($kind === 9 && $sample && $has ? 16 : 0) | ($sample && $has && ! ($archive || ($kind >= 6 && $kind <= 8) || $kind === 11) ? 32 : 0)
                | ($kind === 11 && $has && ! $music && $size >= 10240 ? 64 : 0) | ($kind === 10 && $has ? 128 : 0)
                | ($kind === 14 && $files === 1 && $has ? 256 : 0) | ($kind === 12 ? 512 : 0);
            $needed = ($stages & 1) !== 0 ? min($segments, 2) : (($stages & 0x1FC) !== 0 ? 1 : 0);
            $priority = match (true) {
                ($stages & 2) !== 0 => 3,
                ($stages & (1 | 4 | 8)) !== 0 => 2,
                ($stages & (16 | 32 | 64 | 128 | 256)) !== 0 => 1,
                default => 0,
            };
            if (($stages & (1 | 16 | 32 | 64 | 128 | 256)) !== 0) {
                $supported = true;
            }
            $tail[$kind]++;
            for ($b = 0; $b < 10; $b++) {
                $tail[15 + $b] += $stages >> $b & 1;
            }
            $tail[25 + $priority]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[29 + $b] += $flags >> $b & 1;
            }
            $tail[37] += $needed;
            if ($archive) {
                $tail[38] += min($size, 1 << 40);
            }
            $tail[42] = max($tail[42], $segments);
            $out[] = $kind | $stages << 4 | $priority << 14 | $flags << 16 | min($volume, 1023) << 24 | min($needed, 3) << 34
                | self::w64BitLength($size) << 36 | min($segments, 4095) << 42 | min(\strlen($name), 255) << 54;
        }
        $tail[39] = \count($sets);
        $tail[40] = $tail[12] > 80 && $count <= $tail[12] * 2 ? 1 : 0;
        $tail[41] = $count > 0 && ! $supported ? 1 : 0;
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, true> */
    private const array W64_VIDEO_EXTENSIONS = ['avi' => true, 'f4v' => true, 'ifo' => true, 'm1v' => true, 'm2v' => true, 'm4v' => true, 'mkv' => true,
        'mov' => true, 'mp4' => true, 'mpeg' => true, 'mpg' => true, 'mpgv' => true, 'mpv' => true, 'ogv' => true, 'qt' => true, 'rm' => true,
        'rmvb' => true, 'ts' => true, 'vob' => true, 'wmv' => true];

    /** @var array<string, true> */
    private const array W64_AUDIO_EXTENSIONS = ['aac' => true, 'aiff' => true, 'ape' => true, 'ac3' => true, 'asf' => true, 'dts' => true, 'flac' => true,
        'mka' => true, 'mks' => true, 'mp2' => true, 'mp3' => true, 'ra' => true, 'ogg' => true, 'ogm' => true, 'w64' => true, 'wav' => true, 'wma' => true];

    /** @var array<string, int> */
    private const array W64_NAMED_KINDS = ['7z' => 3, 'zip' => 4, 'zipx' => 4, 'par2' => 6, 'nfo' => 7, 'diz' => 7, 'inf' => 7, 'sfv' => 8, 'srs' => 8,
        'nzb' => 8, 'jpg' => 11, 'jpeg' => 11, 'png' => 11, 'webp' => 11, 'epub' => 12, 'mobi' => 12, 'pdf' => 12, 'lit' => 12];

    private const array W64_MUSIC_GROUP_NEEDLES = ['flac', 'lossless', 'mp3', 'music', 'sound'];

    private const string W64_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w64Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W64_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w64BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Splits a lowercased name at its last `.` when the extension is 1-5 `[a-z0-9]` bytes.
     *
     * @return array{0: string, 1: string}
     */
    private static function w64SplitExtension(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return [$name, ''];
        }
        $extension = substr($name, $dot + 1);
        $length = \strlen($extension);
        if ($length === 0 || $length > 5 || strspn($extension, 'abcdefghijklmnopqrstuvwxyz0123456789') !== $length) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), $extension];
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_work_planner_w641', self::W64_WORK_PLANNER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 64.1 AdditionalWorkPlanner export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_WORK_PLANNER_FFI_CDEF.)
            $written = $ffi->nntmux_w641_work_planner_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
