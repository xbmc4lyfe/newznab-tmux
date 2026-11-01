<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;

/**
 * Appends consecutive articles of one NZB file to a local file.
 *
 * Every article's yEnc header must say it starts where the previous one ended,
 * so a missing article or out-of-order NZB never produces a file with a hole in it.
 */
class MediaArticleFetcher
{
    public function __construct(private readonly UsenetDownloadService $downloadService) {}

    /**
     * @param  list<string>  $segments  the NZB file's message IDs, in order
     * @param  int|null  $expectedOffset  file offset the first article must start at, or null to accept any
     * @param  float  $deadline  hrtime seconds after which no article is requested
     * @return int bytes appended to $path
     */
    public function append(
        ReleaseProcessingContext $context,
        array $segments,
        int $fromSegment,
        ?int $expectedOffset,
        int $maxBytes,
        string $path,
        MediaByteBudget $budget,
        float $deadline,
        int $articleEstimate,
    ): int {
        $written = 0;

        for ($index = max(0, $fromSegment); $index < count($segments) && $written < $maxBytes; $index++) {

            if ($context->groupUnavailable || $budget->exhausted() || hrtime(true) / 1e9 >= $deadline || ! $budget->allows($articleEstimate)) {
                break;
            }

            $result = $this->downloadService->downloadArticle(
                DownloadKind::MediaSource,
                (string) $segments[$index],
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;

                break;
            }
            if (! $result['success'] || ! is_string($result['data'])) {
                break;
            }

            $budget->spend(strlen($result['data']));
            // A cached copy has no yEnc header; only a file's first article is placed without one.
            $offset = $result['part']?->offset() ?? ($index === 0 && $expectedOffset === 0 ? 0 : null);
            if ($offset === null || ($expectedOffset !== null && $offset !== $expectedOffset)) {
                break;
            }

            $chunk = substr($result['data'], 0, $maxBytes - $written);
            if (file_put_contents($path, $chunk, FILE_APPEND) === false) {
                break;
            }
            $part = $result['part'];
            if ($index === 0 && $expectedOffset === 0 && $part !== null && $part->offset() === 0
                && ($part->part === null || $part->part === 1) && $part->size > 0 && strlen($result['data']) <= $part->size
                && $context->nzbSourceDigest !== null && $part->name !== '' && strlen($part->name) <= 4096
                && mb_check_encoding($part->name, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $part->name) !== 1
                && basename($part->name) === $part->name && ! str_contains($part->name, '\\')
                && ! in_array($part->name[0], ['.', '-'], true)
                && ! ArchiveExtractionService::hasArchiveSignature($result['data'])
                && ($container = VideoContainer::detect($result['data'])) !== null
                && $container === VideoContainer::fromFileName('video.'.pathinfo($part->name, PATHINFO_EXTENSION))) {
                $context->directVideoWitnesses[hash('sha256', (string) $segments[$index])] = [
                    'name' => $part->name, 'size' => $part->size, 'digest' => $context->nzbSourceDigest,
                ];
            }
            $written += strlen($chunk);
            $expectedOffset = $offset + strlen($result['data']);
        }

        return $written;
    }
}
