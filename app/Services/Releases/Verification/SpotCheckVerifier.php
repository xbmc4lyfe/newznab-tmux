<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\YencService;
use Throwable;

final class SpotCheckVerifier
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly ArticleSource $source, private readonly array $options,
        private readonly ?YencService $decoder = null, private readonly ?SpotCheckPlanner $planner = null) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  bool  $allowPartial  An upload whose missing files or articles stopped arriving may be
     *                              spot checked on the articles it has and published as partial.
     */
    public function verify(array $manifest, callable $heartbeat, bool $allowPartial = false): VerificationResult
    {
        try {
            return $this->verifySample($manifest, $heartbeat, $allowPartial);
        } catch (Throwable $error) {
            return new VerificationResult('unknown', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $manifest */
    private function verifySample(array $manifest, callable $heartbeat, bool $allowPartial): VerificationResult
    {
        $inventory = new CandidateHeaderInventory;
        $assessment = $inventory->assessment($manifest);
        $partial = null;
        if ($assessment['reason'] !== null) {
            if (! $allowPartial || $assessment['status'] !== 'waiting_upload') {
                return new VerificationResult('unknown', $assessment['reason'], waitingForHeaders: $assessment['status'] === 'waiting_upload',
                    headerRejected: $assessment['status'] === CandidateHeaderInventory::REJECTED);
            }
            $partial = $inventory->completeness($manifest);
        }

        $articleLimit = max(0, min(16, (int) ($this->options['spot_check_articles'] ?? 1)));
        $byteLimit = max(0, min(1048576, (int) ($this->options['spot_check_bytes'] ?? 1048576)));
        $articleByteLimit = max(0, min($byteLimit, (int) ($this->options['spot_check_article_bytes'] ?? 1048576)));
        $sample = ($this->planner ?? new SpotCheckPlanner)->select($manifest, $articleLimit, $byteLimit, $articleByteLimit);
        if ($sample === []) {
            return new VerificationResult('unknown', 'No article fits the configured spot-check budget.');
        }
        $inferredTotals = [];
        foreach ($manifest['files'] as $file) {
            if (($file['totalparts_inferred'] ?? false) === true) {
                $inferredTotals[$file['key']] = false;
            }
        }
        if (array_diff_key($inferredTotals, array_fill_keys(array_column($sample, 'file_key'), true)) !== []) {
            return new VerificationResult('unknown', 'Inferred article totals cannot be proved within the spot-check budget.');
        }

        $bytes = 0;
        $names = [];
        $identities = [];
        $fileIdentities = [];
        $ranges = [];
        $identityHints = [];
        foreach ($sample as $segment) {
            $heartbeat();
            $remaining = min($articleByteLimit, $byteLimit - $bytes);
            if ($remaining < 1) {
                return new VerificationResult('unknown', 'Spot-check transfer budget is exhausted.');
            }
            $raw = $this->source instanceof NntpArticleSource
                ? $this->source->fetchBounded($segment['messageid'], $remaining)
                : $this->source->fetch($segment['messageid']);
            if ($raw === '' || strlen($raw) > $articleByteLimit || $bytes + strlen($raw) > $byteLimit) {
                return new VerificationResult('unknown', 'Spot-check article exceeded the configured transfer budget.');
            }
            $article = VerifiedArticle::decode($raw, $this->decoder);
            if ($article->part !== $segment['number'] || $article->size < 1
                // OVER reports article octets, including encoding/headers, rather
                // than decoded payload length. It must at least cover the payload.
                || strlen($article->bytes) > $segment['bytes']
                || $article->offset + strlen($article->bytes) > $article->size
                || ($article->part === 1 && $article->offset !== 0)
                || ($article->part === $segment['totalparts'] && $article->offset + strlen($article->bytes) !== $article->size)
                || ! $this->matchesHeaderFilename($manifest, $segment['file_key'], $article->name)
                || ($article->totalParts !== null && $article->totalParts !== $segment['totalparts'])) {
                return new VerificationResult('unknown', 'Spot-check article identity differs from its header.');
            }
            $fileKey = $segment['file_key'];
            if ($segment['totalparts_inferred'] && ($article->totalParts !== null || $article->part === $segment['totalparts'])) {
                $inferredTotals[$fileKey] = true;
            }
            $fileIdentity = [$article->name, $article->size];
            if (isset($fileIdentities[$fileKey]) && $fileIdentities[$fileKey] !== $fileIdentity) {
                return new VerificationResult('unknown', 'Spot-check articles have conflicting file identities.');
            }
            $fileIdentities[$fileKey] = $fileIdentity;
            $ranges[$fileKey][$article->part] = [$article->offset, $article->offset + strlen($article->bytes)];
            $identity = mb_strtolower(\Normalizer::normalize($article->name) ?: $article->name);
            if (isset($identities[$identity]) && $identities[$identity] !== $article->name) {
                return new VerificationResult('unknown', 'Spot-check filenames collide on the storage filesystem.');
            }
            $identities[$identity] = $article->name;
            $bytes += strlen($raw);
            $names[$article->name] = true;
            $identityHints[] = ['name' => $article->name, 'source' => 'yenc-spot-check'];
        }
        if (in_array(false, $inferredTotals, true)) {
            return new VerificationResult('unknown', 'Inferred article total has no body total or terminal endpoint proof.');
        }
        foreach ($ranges as $fileRanges) {
            ksort($fileRanges);
            $previousPart = null;
            $previousEnd = null;
            foreach ($fileRanges as $part => [$begin, $end]) {
                if ($previousEnd !== null && ($begin < $previousEnd
                    || ($part === $previousPart + 1 && $begin !== $previousEnd))) {
                    return new VerificationResult('unknown', 'Spot-check articles expose a missing or overlapping byte range.');
                }
                $previousPart = $part;
                $previousEnd = $end;
            }
        }

        $declaredSize = $inventory->bytes($manifest);
        if ($declaredSize < 1) {
            return new VerificationResult('unknown', 'Candidate has no positive declared size.');
        }
        $manifestFileCount = count(array_filter($manifest['files'], static fn (mixed $file): bool => is_array($file)
            && is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $manifest['verification_mode'] = 'spot_check';
        $manifest['spot_check'] = ['articles' => count($sample), 'bytes' => $bytes,
            'sampled_files' => count(array_unique(array_column($sample, 'file_key'))),
            'method' => 'yenc-crc-and-identity'];
        $manifest['declared_size'] = $declaredSize;
        $manifest['publication_size'] = $declaredSize;
        $manifest['publication_file_count'] = $manifestFileCount;
        $manifest['verified_file_names'] = array_keys($names);
        $manifest['verified_file_count'] = count($names);
        $manifest['identity_hints'] = $identityHints;
        if ($partial !== null) {
            $manifest['partial'] = $partial;

            return new VerificationResult('sampled', sprintf('Sampled article checks passed on an incomplete upload: %d of %d articles found.',
                $partial['articles_found'], $partial['articles_expected']), $manifest);
        }

        return new VerificationResult('sampled', 'Sampled article checks passed; the full payload was not downloaded.', $manifest);
    }

    /** @param array<string, mixed> $manifest */
    private function matchesHeaderFilename(array $manifest, string $key, string $actual): bool
    {
        foreach ($manifest['files'] ?? [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            if (($file['key'] ?? null) !== $key) {
                continue;
            }
            $subject = trim((string) ($file['subject'] ?? ''));
            if (preg_match_all('/"([^"\r\n]+)"|\'([^\'\r\n]+)\'/u', $subject, $matches, PREG_SET_ORDER) > 0) {
                $match = $matches[0];
                $expected = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                // A sole opaque posting hash does not declare the payload filename.
                if (count($matches) === 1 && preg_match('/\A[0-9a-f]{32}\z/i', $expected) === 1) {
                    return true;
                }

                return $expected !== '' && hash_equals($expected, $actual);
            }

            return true;
        }

        return false;
    }
}
