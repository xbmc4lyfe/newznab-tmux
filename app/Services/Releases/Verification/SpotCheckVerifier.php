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
        } catch (ArticleBudgetExceeded $error) {
            // The bounded fetch stops before an oversized article is returned; its size is fixed.
            return self::deterministic($error->getMessage());
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

        $byteLimit = max(0, min(1048576, (int) ($this->options['spot_check_bytes'] ?? 1048576)));
        $articleByteLimit = max(0, min($byteLimit, (int) ($this->options['spot_check_article_bytes'] ?? 1048576)));
        $sample = $this->sample($manifest);
        if ($sample === []) {
            return self::deterministic('No article fits the configured spot-check budget.');
        }
        $inferredTotals = [];
        foreach ($manifest['files'] as $file) {
            if (($file['totalparts_inferred'] ?? false) === true) {
                $inferredTotals[$file['key']] = false;
            }
        }
        if (array_diff_key($inferredTotals, array_fill_keys(array_column($sample, 'file_key'), true)) !== []) {
            return self::deterministic('Inferred article totals cannot be proved within the spot-check budget.');
        }

        if (($this->options['stat_all_articles'] ?? false) && $this->source instanceof NntpArticleSource) {
            $ids = [];
            foreach ($manifest['files'] as $file) {
                foreach ($file['segments'] as $segment) {
                    $ids[] = $segment['messageid'];
                    if (count($ids) === 16) {
                        $heartbeat();
                        $this->source->stat($ids, $heartbeat);
                        $ids = [];
                    }
                }
            }
            $heartbeat();
            $this->source->stat($ids, $heartbeat);
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
                return self::deterministic('Spot-check transfer budget is exhausted.');
            }
            $raw = $this->source instanceof NntpArticleSource
                ? $this->source->fetchBounded($segment['messageid'], $remaining, $heartbeat)
                : $this->source->fetch($segment['messageid']);
            // An empty response is a fetch problem, not a property of the article.
            if ($raw === '') {
                return new VerificationResult('unknown', 'Spot-check article was empty; retry verification later.');
            }
            if (strlen($raw) > $articleByteLimit || $bytes + strlen($raw) > $byteLimit) {
                return self::deterministic('Spot-check article exceeded the configured transfer budget.');
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
                return self::deterministic('Spot-check article identity differs from its header.');
            }
            $fileKey = $segment['file_key'];
            if ($segment['totalparts_inferred'] && ($article->totalParts !== null || $article->part === $segment['totalparts'])) {
                $inferredTotals[$fileKey] = true;
            }
            $fileIdentity = [$article->name, $article->size];
            if (isset($fileIdentities[$fileKey]) && $fileIdentities[$fileKey] !== $fileIdentity) {
                return self::deterministic('Spot-check articles have conflicting file identities.');
            }
            $fileIdentities[$fileKey] = $fileIdentity;
            $ranges[$fileKey][$article->part] = [$article->offset, $article->offset + strlen($article->bytes)];
            $identity = mb_strtolower(\Normalizer::normalize($article->name) ?: $article->name);
            if (isset($identities[$identity]) && $identities[$identity] !== $article->name) {
                return self::deterministic('Spot-check filenames collide on the storage filesystem.');
            }
            $identities[$identity] = $article->name;
            $bytes += strlen($raw);
            $names[$article->name] = true;
            $identityHints[] = ['name' => $article->name, 'source' => 'yenc-spot-check'];
        }
        if (in_array(false, $inferredTotals, true)) {
            return self::deterministic('Inferred article total has no body total or terminal endpoint proof.');
        }
        foreach ($ranges as $fileRanges) {
            ksort($fileRanges);
            $previousPart = null;
            $previousEnd = null;
            foreach ($fileRanges as $part => [$begin, $end]) {
                if ($previousEnd !== null && ($begin < $previousEnd
                    || ($part === $previousPart + 1 && $begin !== $previousEnd))) {
                    return self::deterministic('Spot-check articles expose a missing or overlapping byte range.');
                }
                $previousPart = $part;
                $previousEnd = $end;
            }
        }

        if ($inventory->bytes($manifest) < 1) {
            return self::deterministic('Candidate has no positive declared size.');
        }

        $sampled = self::withEvidence($manifest, ['articles' => count($sample), 'bytes' => $bytes,
            'sampled_files' => count(array_unique(array_column($sample, 'file_key'))),
            'verified_file_names' => array_keys($names), 'identity_hints' => array_column($identityHints, 'name'), 'partial' => $partial !== null]);
        if ($partial !== null) {
            return new VerificationResult('sampled', sprintf('Sampled article checks passed on an incomplete upload: %d of %d articles found.',
                $partial['articles_found'], $partial['articles_expected']), $sampled);
        }

        return new VerificationResult('sampled', 'Sampled article checks passed; the full payload was not downloaded.', $sampled);
    }

    /** @param array<string, mixed> $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public function sample(array $manifest): array
    {
        $articleLimit = max(0, min(16, (int) ($this->options['spot_check_articles'] ?? 1)));
        $byteLimit = max(0, min(1048576, (int) ($this->options['spot_check_bytes'] ?? 1048576)));
        $articleByteLimit = max(0, min($byteLimit, (int) ($this->options['spot_check_article_bytes'] ?? 1048576)));

        return ($this->planner ?? new SpotCheckPlanner)->select($manifest, $articleLimit, $byteLimit, $articleByteLimit);
    }

    /**
     * The manifest version, its deterministically planned sample and the configuration decide this
     * outcome, so an immediate retry would fetch the same article and fail the same way. Transport
     * and decoding errors are thrown instead and keep the ordinary retry interval.
     */
    private static function deterministic(string $reason): VerificationResult
    {
        return new VerificationResult('unknown', $reason, deterministic: true);
    }

    /**
     * The sampled article evidence a remote worker sends instead of the whole manifest.
     *
     * @param  array<string, mixed>  $manifest  A sampled result manifest.
     * @return array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>, partial: bool}
     */
    public static function evidence(array $manifest): array
    {
        if (($manifest['verification_mode'] ?? null) !== 'spot_check' || ! is_array($manifest['spot_check'] ?? null)) {
            throw new \RuntimeException('Sampled verdict has no spot-check evidence.');
        }

        return ['articles' => (int) $manifest['spot_check']['articles'], 'bytes' => (int) $manifest['spot_check']['bytes'],
            'sampled_files' => (int) $manifest['spot_check']['sampled_files'],
            'verified_file_names' => array_values(array_map('strval', (array) ($manifest['verified_file_names'] ?? []))),
            'identity_hints' => array_values(array_map(static fn (mixed $hint): string => (string) ($hint['name'] ?? ''),
                (array) ($manifest['identity_hints'] ?? []))), 'partial' => isset($manifest['partial'])];
    }

    /**
     * Derive every publication field from the stored manifest and its sampled evidence, so a
     * remote worker's result is rebuilt exactly as a local one.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>, partial?: bool}  $evidence
     * @return array<string, mixed>
     */
    public static function withEvidence(array $manifest, array $evidence): array
    {
        $declaredSize = (new CandidateHeaderInventory)->bytes($manifest);
        $manifestFileCount = count(array_filter($manifest['files'], static fn (mixed $file): bool => is_array($file)
            && is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $manifest['verification_mode'] = 'spot_check';
        $manifest['spot_check'] = ['articles' => $evidence['articles'], 'bytes' => $evidence['bytes'],
            'sampled_files' => $evidence['sampled_files'], 'method' => 'yenc-crc-and-identity'];
        $manifest['declared_size'] = $declaredSize;
        $manifest['publication_size'] = $declaredSize;
        $manifest['publication_file_count'] = $manifestFileCount;
        $manifest['verified_file_names'] = $evidence['verified_file_names'];
        $manifest['verified_file_count'] = count($evidence['verified_file_names']);
        $manifest['identity_hints'] = array_map(static fn (string $name): array => ['name' => $name, 'source' => 'yenc-spot-check'],
            $evidence['identity_hints']);
        if ($evidence['partial'] ?? false) {
            // Recounted from the stored manifest, never taken from the sender.
            $manifest['partial'] = (new CandidateHeaderInventory)->completeness($manifest);
        }

        return $manifest;
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

            return HeaderFilenameIdentity::matches((string) ($file['subject'] ?? ''), $actual);
        }

        return false;
    }
}
