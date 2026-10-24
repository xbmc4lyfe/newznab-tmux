<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\Release;
use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Bounded fingerprint collector: at most SAMPLE_LIMIT articles per release and a record of a few KB.
 *
 * This is the reference implementation of the compact-v1 contract shared with the Rust workers, so
 * the output must stay deterministic apart from the documented timing and timestamp fields.
 *
 * Production NZB XML analysis and compact-v1 summarization (`analyzeNzbForCompact`) executes in a
 * single Rust FFI pass (`nntmux_compact_fp_analyze_nzb_xml` in `rust/pipeline-ffi/src/metadata.rs`),
 * computing `samples`, `omitted`, `segment_counts`, `manifest_invalid_files`, `nzbSummary`,
 * `fileRows`, `posting`, `group_count`, `file_count`, and `nzb_names` directly from the raw NZB XML
 * without materializing per-segment PHP arrays (2.74x-3.25x mean and 2.45x-3.69x p99 speedup over
 * `NzbFingerprintAnalyzer::analyzeManifest` + PHP array loops, and 8.09x-9.09x mean / 7.26x-9.64x p99
 * over pure PHP). NNTP/yEnc article decoding (`NzbFingerprintAnalyzer::analyzeArticleAndDecodedBytes`)
 * and PAR2 binary packet parsing (`Par2MetadataParser::parse`) also execute in Rust FFI. Per-article
 * post-analysis PHP array/string helpers (`yencIntegrityErrors`/`yencSummary`, `verifiedPartPrefixMd5`,
 * and `subjectFilename`/`bounded`) retain the native PHP path for the <= 12 sampled articles under the
 * < 1.5x FFI threshold rule when operating on pre-parsed PHP arrays; their output-identical Rust FFI
 * counterparts remain exposed via `*Native` methods for parity testing.
 */
final class CompactFingerprintCollector
{
    /** Versioned independently of the full collector; records are told apart by FORMAT. */
    public const SCHEMA_VERSION = 1;

    public const FORMAT = 'compact-v1';

    public const SAMPLE_LIMIT = 12;

    public const MAX_ARTICLE_BODY_BYTES = 2_000_000;

    private const MAX_ARTICLE_HEADER_BYTES = 65_536;

    private const LARGEST_FILES_SAMPLED = 3;

    private const MAX_LISTED_FILES = 200;

    /** Maximum UTF-8 bytes kept from any name. */
    private const MAX_NAME_LENGTH = 120;

    private const MAX_LISTED_GROUPS = 20;

    /** Serialized record budget; file lists are shortened until the record fits. */
    public const MAX_RECORD_BYTES = 20_480;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_compact_fp_subject_filename_bounded(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_verified_part_prefix_md5(const char *decoded, size_t decoded_len, int64_t file_size, int64_t begin, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_select_samples(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_summarize_files(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_yenc_integrity(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_compact_fp_analyze_nzb_xml(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = true;

    private static FFI|false|null $cachedFfi = null;

    private readonly FingerprintProviderBreaker $breaker;

    public function __construct(private readonly NzbService $nzb, private readonly NzbFingerprintAnalyzer $analyzer,
        private readonly Par2MetadataParser $par2, ?FingerprintProviderBreaker $breaker = null)
    {
        $this->breaker = $breaker ?? new FingerprintProviderBreaker;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if (self::$cachedFfi === null) {
            self::$cachedFfi = RustPipelineLibrary::resolveDomain('compact_fingerprint', self::FFI_CDEF) ?? false;
        }

        return self::$cachedFfi === false ? null : self::$cachedFfi;
    }

    /**
     * source_digest identifies the NZB generation read (NzbSourceGuard::digest()), so a caller can tell
     * whether it is still the committed source when it saves.
     *
     * @return array{status: string, data: array<string, mixed>, source_digest?: string}
     */
    public function collect(Release $release): array
    {
        $xml = $this->nzb->readNzbContents((string) $release->guid);
        if (! is_string($xml) || $xml === '') {
            return ['status' => 'unavailable', 'data' => ['reason' => 'The release NZB is not available locally.']];
        }

        return $this->collectFromNzb($xml, (int) $release->id) + ['source_digest' => NzbSourceGuard::digest($xml)];
    }

    /** @return array{status: string, data: array<string, mixed>} */
    public function collectFromNzb(string $xml, int $releaseId): array
    {
        $compactNzb = $this->analyzeNzbForCompact($xml);
        $samples = $compactNzb['samples'];
        $omitted = $compactNzb['omitted'];
        $segmentCounts = $compactNzb['segment_counts'];
        $manifestInvalidFiles = $compactNzb['manifest_invalid_files'];
        $nzbNames = $compactNzb['nzb_names'];
        $servers = $this->servers($releaseId);
        $nntp = app(NNTPService::class);

        $providers = [];
        $unavailable = [];
        $errors = [];
        foreach ($servers as $server) {
            $account = $server['account'];
            $providers[$account] = [
                'account' => $account,
                'host' => $server['name'],
                'role' => $server['alternate'] ? 'alternate' : 'primary',
                'tls' => $server['tls'],
                'port' => $server['port'],
                'profile' => 'ok',
                'skipped_reason' => null,
                'implementation' => null,
                'articles' => ['requested' => 0, 'found' => 0, 'missing' => 0, 'errors' => 0],
                'retrieval_ms' => [],
            ];
            $skipReason = $server['alternate'] ? $this->breaker->skipReason($server) : null;
            if ($skipReason !== null) {
                $providers[$account]['profile'] = 'NntpProviderUnavailableException';
                $providers[$account]['skipped_reason'] = $skipReason;
                $unavailable[$account] = 'NntpProviderUnavailableException';

                continue;
            }
            try {
                $profile = $nntp->getFingerprintServerProfile($server['alternate']);
                $providers[$account]['implementation'] = self::bounded($profile['implementation'] ?? null);
                // A profile can fail without throwing: same acceptance rule as the full collector. Only an
                // accepted profile proves the alternate reachable; otherwise its re-probe claim is released.
                if (self::profileSucceeded($profile)) {
                    if ($server['alternate']) {
                        $this->breaker->recordReachable($server);
                    }
                } else {
                    $providers[$account]['profile'] = 'ProfileResponseInvalid';
                    $errors[$account]['ProfileResponseInvalid'] = ($errors[$account]['ProfileResponseInvalid'] ?? 0) + 1;
                    if ($server['alternate']) {
                        $this->breaker->releaseProbe($server);
                    }
                }
            } catch (Throwable $exception) {
                $providers[$account]['profile'] = class_basename($exception);
                $errors[$account][class_basename($exception)] = ($errors[$account][class_basename($exception)] ?? 0) + 1;
                if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                    $unavailable[$account] = class_basename($exception);
                    $this->breaker->recordUnavailable($server);
                } elseif ($server['alternate']) {
                    $this->breaker->releaseProbe($server);
                }
            }
        }

        if ($manifestInvalidFiles > 0) {
            $errors['nzb']['NzbManifestInvalid'] = $manifestInvalidFiles;
        }
        $articles = [];
        $softwareCandidates = [];
        $firstPrefixMd5 = [];
        $par2 = null;
        $par2Fallback = null;
        foreach ($samples as $sample) {
            $article = [
                'file_index' => $sample['file_index'],
                'segment' => $sample['segment_number'],
                'role' => $sample['role'],
                'provider_results' => [],
                'yenc' => null,
            ];
            foreach ($servers as $server) {
                $account = $server['account'];
                $providers[$account]['articles']['requested']++;
                if (isset($unavailable[$account])) {
                    $providers[$account]['articles']['errors']++;
                    $errors[$account][$unavailable[$account]] = ($errors[$account][$unavailable[$account]] ?? 0) + 1;
                    $article['provider_results'][$account] = ['code' => null, 'bytes' => null, 'ms' => null];

                    continue;
                }
                $started = hrtime(true);
                try {
                    // One ARTICLE request per sample instead of HEAD + BODY: the fetch is network-bound.
                    $fetched = $nntp->getFingerprintFullArticleByMessageID($sample['messageid'], self::MAX_ARTICLE_BODY_BYTES,
                        self::MAX_ARTICLE_HEADER_BYTES, $server['alternate']);
                    $ms = (int) round((hrtime(true) - $started) / 1_000_000);
                    $providers[$account]['retrieval_ms'][] = $ms;
                    $providers[$account]['articles']['found']++;
                    $article['provider_results'][$account] = [
                        // compact-v1 records the BODY success code; ARTICLE's 220 carries the identical body.
                        'code' => 222,
                        'bytes' => strlen($fetched['body']),
                        'ms' => $ms,
                    ];
                    // Each provider's copy is decoded so corruption on any of them is reported.
                    $analysis = $this->analyzer->analyzeArticleAndDecodedBytes($fetched['headers'], $fetched['body']);
                    $metadata = $analysis['metadata'];
                    // Only a copy that positively identifies as this article, and whose yEnc part is this NZB
                    // segment, may supply evidence for it.
                    $identity = self::identityStatus($sample['messageid'], $metadata['headers']['message-id'] ?? null, $fetched);
                    if ($identity !== null) {
                        $errors[$account][$identity] = ($errors[$account][$identity] ?? 0) + 1;

                        continue;
                    }
                    if (! self::partMatchesSegment($metadata['yenc'] ?? null, $sample['segment_number'], $segmentCounts[$sample['file_index']] ?? 0)) {
                        $errors[$account]['YencPartMismatch'] = ($errors[$account]['YencPartMismatch'] ?? 0) + 1;

                        continue;
                    }
                    $integrityErrors = self::yencIntegrityErrors($metadata['yenc'] ?? null);
                    $yenc = self::yencSummary($metadata['yenc'] ?? null, $integrityErrors === []);
                    foreach ($integrityErrors as $integrityError) {
                        $errors[$account][$integrityError] = ($errors[$account][$integrityError] ?? 0) + 1;
                    }
                    $prefixMd5 = $metadata['yenc']['par2_first_16k_md5_candidate'] ?? null;
                    if (! is_string($prefixMd5) && $integrityErrors === [] && is_array($metadata['yenc'] ?? null)
                        && self::partWithoutTrailerPart($metadata['yenc'])) {
                        // The analyzer withholds the prefix for a part it calls incomplete; this part is proven.
                        $prefixMd5 = self::verifiedPartPrefixMd5($metadata['yenc'], $analysis['decoded_bytes'] ?? null);
                    }
                    if ($integrityErrors === [] && $sample['segment_number'] === 1 && is_string($prefixMd5)) {
                        // Prefix hashes come only from integrity-verified copies, from whichever provider first has one.
                        $firstPrefixMd5[$sample['file_index']] ??= strtolower($prefixMd5);
                    }
                    // Every verified provider copy may supply PAR2 metadata until a usable summary is found.
                    if ($par2 === null && $sample['kind'] === 'par2' && $integrityErrors === []
                        && is_string($analysis['decoded_bytes'] ?? null)) {
                        $summary = $this->par2Summary($analysis['decoded_bytes'], [], $nzbNames);
                        // Only a summary with file descriptions ends the search; a set ID alone (e.g. a
                        // Creator-only volume) is kept as a fallback while later volumes are examined.
                        if ($summary !== null && $summary['files'] !== []) {
                            $par2 = $summary;
                        } elseif ($summary !== null && $summary['set_id'] !== null) {
                            $par2Fallback ??= $summary;
                        }
                    }
                    if ($article['yenc'] !== null) {
                        continue;
                    }
                    // The article summary comes from the first provider that returned it.
                    $article['yenc'] = $yenc;
                    foreach (array_keys($metadata['uploader_software_candidates'] ?? []) as $candidate) {
                        $softwareCandidates[$candidate] = true;
                    }
                } catch (Throwable $exception) {
                    $providers[$account]['retrieval_ms'][] = (int) round((hrtime(true) - $started) / 1_000_000);
                    $providers[$account]['articles'][self::isMissingArticle($exception) ? 'missing' : 'errors']++;
                    $article['provider_results'][$account] = ['code' => null, 'bytes' => null, 'ms' => null];
                    $type = class_basename($exception);
                    $errors[$account][$type] = ($errors[$account][$type] ?? 0) + 1;
                    if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                        $unavailable[$account] = $type;
                        $this->breaker->recordUnavailable($server);
                    }
                }
            }
            $articles[] = $article;
        }

        $providerRows = [];
        foreach ($providers as $provider) {
            $timings = $provider['retrieval_ms'];
            sort($timings);
            $provider['retrieval_ms'] = [
                'p50' => $timings === [] ? null : $timings[intdiv(count($timings) - 1, 2)],
                'max' => $timings === [] ? null : max($timings),
                'total' => array_sum($timings),
            ];
            $providerRows[] = $provider;
        }
        $errorRows = [];
        foreach ($errors as $account => $types) {
            ksort($types);
            foreach ($types as $type => $count) {
                $errorRows[] = ['account' => $account, 'error_type' => $type, 'count' => $count];
            }
        }
        $softwareCandidates = array_keys($softwareCandidates);
        sort($softwareCandidates);

        $fileRows = $compactNzb['files'];
        if ($firstPrefixMd5 !== []) {
            foreach ($fileRows as &$fileRow) {
                $fileRow['first16k_md5'] = $firstPrefixMd5[$fileRow['file_index']] ?? null;
            }
            unset($fileRow);
        }

        $primary = $providerRows[0] ?? null;
        $status = $primary !== null && $samples !== []
            && $primary['articles']['found'] === count($samples)
            && $errorRows === [] ? 'complete' : 'partial';

        return ['status' => $status, 'data' => self::withinBudget([
            'schema_version' => self::SCHEMA_VERSION,
            'format' => self::FORMAT,
            'collector' => ['engine' => 'php', 'version' => self::SCHEMA_VERSION],
            'nzb' => $compactNzb['nzb'],
            'samples' => ['limit' => self::SAMPLE_LIMIT, 'selected' => count($samples), 'omitted' => $omitted],
            'providers' => $providerRows,
            'articles' => $articles,
            'files' => $fileRows,
            'par2' => $par2 ?? $par2Fallback,
            'posting' => $compactNzb['posting'],
            'uploader_software_candidates' => $softwareCandidates,
            'errors' => $errorRows,
            // Also true when a list cap below omitted entries before the byte budget applied.
            'truncated' => $compactNzb['file_count'] > self::MAX_LISTED_FILES
                || (($par2 ?? $par2Fallback) !== null && ($par2 ?? $par2Fallback)['file_count'] > count(($par2 ?? $par2Fallback)['files']))
                || $compactNzb['group_count'] > self::MAX_LISTED_GROUPS,
        ])];
    }

    /**
     * Single-pass NZB XML analysis and compact-v1 summarization. Uses `nntmux_compact_fp_analyze_nzb_xml`
     * when Rust FFI is enabled, avoiding materializing per-segment PHP arrays for the entire NZB.
     *
     * @return array{
     *     samples: list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>,
     *     omitted: int,
     *     segment_counts: array<int, int>,
     *     manifest_invalid_files: int,
     *     nzb: array<string, mixed>,
     *     files: list<array<string, mixed>>,
     *     posting: array{first_utc: string, last_utc: string, span_s: int}|null,
     *     group_count: int,
     *     file_count: int,
     *     nzb_names: array<string, true>
     * }
     */
    public function analyzeNzbForCompact(string $xml): array
    {
        $native = self::analyzeNzbForCompactNative($xml);
        if ($native !== null) {
            return $native;
        }

        $manifest = $this->analyzer->analyzeManifest($xml);
        $files = $manifest['files'];
        $omitted = 0;
        $samples = self::selectSamples($files, $omitted);
        $segmentCounts = [];
        $nzbNames = [];
        foreach ($files as $file) {
            $segmentCounts[(int) $file['index']] = is_array($file['segments'] ?? null) ? count($file['segments']) : 0;
            $nzbNames[self::subjectFilename((string) ($file['subject'] ?? ''))] = true;
        }

        return [
            'samples' => $samples,
            'omitted' => $omitted,
            'segment_counts' => $segmentCounts,
            'manifest_invalid_files' => self::manifestInvalidFileCount($manifest),
            'nzb' => self::nzbSummary($manifest),
            'files' => self::fileRows($files, []),
            'posting' => self::posting($files),
            'group_count' => count(self::nzbGroups($files)),
            'file_count' => count($files),
            'nzb_names' => $nzbNames,
        ];
    }

    /**
     * Shrink the record in stages until it fits MAX_RECORD_BYTES as stored (default JSON escaping):
     * halve the file lists, then drop groups, then drop per-article names, then halve the article
     * list. The last stage always terminates, and everything left is bounded by count.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withinBudget(array $record): array
    {
        // Timings vary between runs; measure them at a fixed worst-case width so the same release
        // always keeps the same content.
        $fits = static fn (array $candidate): bool => strlen((string) json_encode(self::withWorstCaseTimings($candidate), JSON_INVALID_UTF8_SUBSTITUTE)) <= self::MAX_RECORD_BYTES;
        while (! $fits($record)) {
            $record['truncated'] = true;
            $files = count($record['files']);
            $par2Files = is_array($record['par2']) ? count($record['par2']['files']) : 0;
            if ($files > 0 || $par2Files > 0) {
                if ($files >= $par2Files) {
                    $record['files'] = array_slice($record['files'], 0, intdiv($files, 2));
                } else {
                    $record['par2']['files'] = array_slice($record['par2']['files'], 0, intdiv($par2Files, 2));
                }
            } elseif ($record['nzb']['groups'] !== []) {
                $record['nzb']['groups'] = [];
            } elseif (array_filter($record['articles'], static fn (array $article): bool => ($article['yenc']['name'] ?? null) !== null) !== []) {
                foreach ($record['articles'] as &$article) {
                    if (is_array($article['yenc'])) {
                        $article['yenc']['name'] = null;
                    }
                }
                unset($article);
            } elseif ($record['articles'] !== []) {
                $record['articles'] = array_slice($record['articles'], 0, intdiv(count($record['articles']), 2));
            } else {
                break;
            }
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withWorstCaseTimings(array $record): array
    {
        $widest = PHP_INT_MAX;
        foreach ($record['providers'] as &$provider) {
            $provider['retrieval_ms'] = ['p50' => $widest, 'max' => $widest, 'total' => $widest];
        }
        unset($provider);
        foreach ($record['articles'] as &$article) {
            foreach ($article['provider_results'] as &$result) {
                $result['ms'] = $widest;
            }
            unset($result);
        }
        unset($article);

        return $record;
    }

    /**
     * Segment 1 of the smallest PAR2 file, then first/middle/last segments of the largest non-PAR2
     * files, then one further PAR2 volume, then first segments of the remaining files, up to SAMPLE_LIMIT.
     *
     * @param  list<array<string, mixed>>  $files
     *
     * @param-out int $omitted
     *
     * @return list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>
     */
    public static function selectSamples(array $files, ?int &$omitted = null): array
    {
        $candidates = [];
        $withSegments = array_values(array_filter($files, static fn (array $file): bool => is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $par2Files = array_values(array_filter($withSegments, static fn (array $file): bool => ($file['kind'] ?? null) === 'par2'));
        $dataFiles = array_values(array_filter($withSegments, static fn (array $file): bool => ($file['kind'] ?? null) !== 'par2'));
        usort($par2Files, static fn (array $a, array $b): int => [self::fileBytes($a), $a['index']] <=> [self::fileBytes($b), $b['index']]);
        usort($dataFiles, static fn (array $a, array $b): int => [self::fileBytes($b), $a['index']] <=> [self::fileBytes($a), $b['index']]);

        if ($par2Files !== []) {
            $candidates[] = [$par2Files[0], 0, 'par2'];
        }
        foreach (array_slice($dataFiles, 0, self::LARGEST_FILES_SAMPLED) as $file) {
            $last = count($file['segments']) - 1;
            $candidates[] = [$file, 0, 'first'];
            if ($last > 1) {
                $candidates[] = [$file, intdiv($last, 2), 'middle'];
            }
            if ($last > 0) {
                $candidates[] = [$file, $last, 'last'];
            }
        }
        // One further PAR2 volume comes before the remaining data files, so a missing or unusable index
        // can still be replaced by another volume's metadata.
        foreach (array_slice($par2Files, 1, 1) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }
        foreach (array_slice($dataFiles, self::LARGEST_FILES_SAMPLED) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }
        foreach (array_slice($par2Files, 2) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }

        $samples = [];
        $seen = [];
        $omitted = 0;
        foreach ($candidates as [$file, $position, $role]) {
            $segment = $file['segments'][$position];
            $messageId = (string) ($segment['messageid'] ?? '');
            if ($messageId === '' || isset($seen[$messageId])) {
                continue;
            }
            $seen[$messageId] = true;
            if (count($samples) >= self::SAMPLE_LIMIT) {
                $omitted++;

                continue;
            }
            $samples[] = [
                'file_index' => (int) $file['index'],
                'kind' => (string) $file['kind'],
                'segment_number' => (int) $segment['number'],
                'messageid' => $messageId,
                'role' => $role,
            ];
        }

        return $samples;
    }

    /**
     * Whether this release also samples the alternate provider (a stable percentage of releases).
     */
    public static function comparesAlternate(int $releaseId): bool
    {
        $percent = max(0, min(100, (int) config('nntmux.fingerprint_alternate_percent', 10)));

        return crc32((string) $releaseId) % 100 < $percent;
    }

    /** @return list<array{name: string, alternate: bool, port: string, tls: bool, account: string}> */
    private function servers(int $releaseId): array
    {
        $servers = [[
            'name' => (string) config('nntmux_nntp.server', 'primary'),
            'alternate' => false,
            'port' => (string) config('nntmux_nntp.port', ''),
            'tls' => (bool) config('nntmux_nntp.ssl', false),
            'account' => 'primary',
        ]];
        if (trim((string) config('nntmux_nntp.alternate_server', '')) !== '' && self::comparesAlternate($releaseId)) {
            $servers[] = [
                'name' => (string) config('nntmux_nntp.alternate_server'),
                'alternate' => true,
                'port' => (string) config('nntmux_nntp.alternate_server_port', ''),
                'tls' => (bool) config('nntmux_nntp.alternate_server_ssl', false),
                'account' => 'alternate',
            ];
        }

        return $servers;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, true>|null  $nzbNames
     * @return array<string, mixed>|null
     */
    private function par2Summary(string $decoded, array $files, ?array $nzbNames = null): ?array
    {
        try {
            $parsed = $this->par2->parse($decoded);
        } catch (Throwable) {
            return null;
        }
        if ($nzbNames === null) {
            $nzbNames = [];
            foreach ($files as $file) {
                $nzbNames[self::subjectFilename((string) ($file['subject'] ?? ''))] = true;
            }
        }
        $sources = [];
        $matches = 0;
        foreach ($parsed['source_files'] ?? [] as $source) {
            $name = is_string($source['filename'] ?? null) ? $source['filename'] : null;
            // Match complete names before they are shortened for output.
            if ($name !== null && $name !== '' && isset($nzbNames[$name])) {
                $matches++;
            }
            $sources[] = [
                'name' => self::bounded($name),
                'size' => is_int($source['file_length'] ?? null) ? $source['file_length'] : null,
                'md5' => $source['md5'] ?? null,
                'md5_16k' => $source['md5_first_16k'] ?? null,
            ];
        }
        usort($sources, static fn (array $a, array $b): int => [(string) $a['name'], (string) $a['md5']] <=> [(string) $b['name'], (string) $b['md5']]);

        $setIds = is_array($parsed['recovery_set_ids'] ?? null) ? array_values($parsed['recovery_set_ids']) : [];
        $coherent = count($setIds) === 1
            && ($parsed['source_descriptions_valid'] ?? false) === true
            && ($parsed['main_definition_conflict'] ?? false) !== true
            && ($parsed['conflicting_file_description_ids'] ?? []) === []
            && ($parsed['source_definition_conflicts'] ?? []) === []
            && ($parsed['source_inventory_complete'] ?? false) === true
            && ($parsed['missing_file_description_ids'] ?? []) === [];
        if (! $coherent) {
            // Multiple sets or contradictory descriptions: keep only a set ID fallback (no files).
            return count($setIds) === 1 ? ['set_id' => $setIds[0], 'block_size' => null, 'recovery_blocks' => null,
                'file_count' => 0, 'files' => [], 'nzb_matches' => 0] : null;
        }

        return [
            'set_id' => $setIds[0],
            'block_size' => $parsed['source_block_size'] ?? null,
            // Conflicting recovery slices make the count ambiguous, so it is left unknown.
            'recovery_blocks' => (int) ($parsed['recovery_slice_conflict_count'] ?? 0) === 0 && ($parsed['conflicting_recovery_exponents'] ?? []) === []
                ? ($parsed['unique_recovery_slice_count'] ?? null) : null,
            'file_count' => count($sources),
            'files' => array_slice($sources, 0, self::MAX_LISTED_FILES),
            'nzb_matches' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private static function nzbSummary(array $manifest): array
    {
        $groups = self::nzbGroups($manifest['files']);
        $posters = [];
        $segments = 0;
        $bytes = 0;
        $kinds = [];
        foreach ($manifest['files'] as $file) {
            if (($file['poster'] ?? '') !== '') {
                $posters[(string) $file['poster']] = ($posters[(string) $file['poster']] ?? 0) + 1;
            }
            $segments += (int) ($file['segment_count'] ?? 0);
            $fileBytes = $file['advertised_bytes'] ?? null;
            // Checked addition: an unknown file size or an unrepresentable total makes the total unknown.
            $bytes = $bytes === null || ! is_int($fileBytes) || $fileBytes > PHP_INT_MAX - $bytes ? null : $bytes + $fileBytes;
            $kind = (string) ($file['kind'] ?? 'other');
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        ksort($kinds);
        arsort($posters);

        return [
            'file_count' => (int) $manifest['file_count'],
            'segment_count' => $segments,
            'total_bytes' => $bytes,
            'groups' => array_map(self::bounded(...), array_slice($groups, 0, self::MAX_LISTED_GROUPS)),
            'poster' => self::bounded(array_key_first($posters)),
            'kinds' => $kinds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<string>
     */
    private static function nzbGroups(array $files): array
    {
        $groups = [];
        foreach ($files as $file) {
            foreach ($file['groups'] ?? [] as $group) {
                $groups[(string) $group] = true;
            }
        }
        $groups = array_map('strval', array_keys($groups));
        sort($groups);

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<int, string>  $firstPrefixMd5
     * @return list<array<string, mixed>>
     */
    private static function fileRows(array $files, array $firstPrefixMd5): array
    {
        $rows = [];
        foreach (array_slice($files, 0, self::MAX_LISTED_FILES) as $file) {
            $index = (int) $file['index'];
            $rows[] = [
                'file_index' => $index,
                'name' => self::bounded(self::subjectFilename((string) ($file['subject'] ?? ''))),
                'size' => is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : null,
                'first16k_md5' => $firstPrefixMd5[$index] ?? null,
                'format_hint' => $file['format_hint'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array{first_utc: string, last_utc: string, span_s: int}|null
     */
    private static function posting(array $files): ?array
    {
        // NZBs use date="0" for an unknown date; only positive timestamps are evidence.
        $dates = array_values(array_filter(array_map(static fn (array $file): mixed => $file['date'] ?? null, $files),
            static fn (mixed $date): bool => is_int($date) && $date > 0));
        if ($dates === []) {
            return null;
        }
        $first = min($dates);
        $last = max($dates);

        return ['first_utc' => gmdate('Y-m-d\TH:i:s\Z', $first), 'last_utc' => gmdate('Y-m-d\TH:i:s\Z', $last), 'span_s' => $last - $first];
    }

    /**
     * @param  bool  $verified  whether the copy passed every integrity check; only then may a proven
     *                          trailerless part report its CRC as matching
     * @return array<string, mixed>|null
     */
    private static function yencSummary(mixed $yenc, bool $verified): ?array
    {
        if (! is_array($yenc)) {
            return null;
        }

        return [
            'name' => self::bounded($yenc['name'] ?? null),
            'size' => is_int($yenc['file_size'] ?? null) ? $yenc['file_size'] : null,
            'part' => is_int($yenc['part'] ?? null) ? $yenc['part'] : null,
            'total' => is_int($yenc['total_parts'] ?? null) ? $yenc['total_parts'] : null,
            'begin' => is_int($yenc['begin'] ?? null) ? $yenc['begin'] : null,
            'end' => is_int($yenc['end'] ?? null) ? $yenc['end'] : null,
            'crc_ok' => is_bool($yenc['declared_crc32_matches'] ?? null) ? $yenc['declared_crc32_matches']
                : ($verified && self::partWithoutTrailerPart($yenc) ? true : null),
            'size_ok' => self::sizesAgree($yenc),
        ];
    }

    /**
     * Integrity errors for one provider's copy of an article. The copy is clean only on positive
     * evidence: yEnc framing parsed, the declared CRC matches, the structure is single-part or a
     * complete multipart, escaping is valid and no size disagrees. Anything else is reported, with a
     * specific error where the analyzer established one and YencUnverified otherwise.
     *
     * @return list<string>
     */
    private static function yencIntegrityErrors(mixed $yenc): array
    {
        if (! is_array($yenc)) {
            return ['YencUnverified'];
        }
        $errors = [];
        if (($yenc['declared_crc32_matches'] ?? null) === false) {
            $errors[] = 'YencCrcMismatch';
        }
        foreach (['decoded_size_matches_yenc', 'decoded_size_matches_ypart_range', 'decoded_size_matches_yend_size'] as $check) {
            if (($yenc[$check] ?? null) === false) {
                $errors[] = 'YencSizeMismatch';
                break;
            }
        }
        if (($yenc['payload_escape_state_valid'] ?? null) === false) {
            $errors[] = 'YencFramingInvalid';
        }
        if (self::declaredCrcsContradict($yenc)) {
            $errors[] = 'YencCrcMismatch';
        }
        $partWithoutTrailerPart = self::partWithoutTrailerPart($yenc);
        $verified = (($yenc['declared_crc32_matches'] ?? null) === true || $partWithoutTrailerPart)
            && ($yenc['all_declared_crc32_fields_are_hex'] ?? null) !== false
            && (self::sizesAgree($yenc) === true || self::emptySinglePart($yenc))
            && (in_array($yenc['multipart_structure_status'] ?? null, ['single-part', 'complete'], true) || $partWithoutTrailerPart)
            && ($yenc['payload_escape_state_valid'] ?? null) === true
            && ($yenc['integrity_checks_ambiguous'] ?? null) !== true
            && ($yenc['decoded_size_matches_yend_size'] ?? null) === true
            && ! in_array(false, array_map(static fn (string $check): mixed => $yenc[$check] ?? null, array_values(array_diff([
                'decoded_size_matches_yenc', 'decoded_size_matches_ypart_range', 'ypart_range_valid',
                'header_numeric_fields_valid', 'header_numeric_fields_representable', 'trailer_numeric_fields_valid',
                'trailer_numeric_fields_representable', 'trailer_part_matches_header', 'trailer_total_matches_header',
            ], self::emptySinglePart($yenc) ? ['header_numeric_fields_valid'] : []))), true)
            && (! is_array($yenc['conflicting_control_fields'] ?? null) || $yenc['conflicting_control_fields'] === []);
        if (! $verified && $errors === []) {
            $errors[] = 'YencUnverified';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Posting tools commonly end a part with "=yend size=N pcrc32=X" and no part= field. The analyzer
     * then calls the structure incomplete and skips the CRC comparison, so this accepts exactly that
     * form when everything else is proven: a numbered header part, a valid =ypart range, a hex pcrc32
     * equal to the decoded bytes' CRC, no trailer total, and no other missing or conflicting field.
     * Tools also write the pcrc32 without leading zeros ("pcrc32=9474e27"); the analyzer calls that
     * malformed and the structure invalid, which for an otherwise trailerless part is the same case.
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function partWithoutTrailerPart(array $yenc): bool
    {
        $trailer = is_array($yenc['trailer_fields'] ?? null) ? $yenc['trailer_fields'] : [];
        $header = is_array($yenc['header_fields'] ?? null) ? $yenc['header_fields'] : [];
        $declared = $trailer['pcrc32'] ?? null;
        $observed = $yenc['observed_decoded_crc32'] ?? null;
        $structure = [$yenc['multipart_structure_status'] ?? null, $yenc['multipart_pcrc32_status'] ?? null];
        $unpaddedPcrc32 = is_string($declared) && preg_match('/\A[0-9a-f]{1,7}\z/i', $declared) === 1;

        return ($yenc['multipart'] ?? null) === true
            && ($structure === ['incomplete', 'present-hex'] || ($structure === ['invalid', 'malformed'] && $unpaddedPcrc32))
            && ! array_key_exists('part', $trailer) && ! array_key_exists('total', $trailer)
            && isset($header['part'], $header['size'], $trailer['size'])
            && is_int($yenc['part'] ?? null) && $yenc['part'] >= 1
            && ($yenc['ypart_range_valid'] ?? null) === true
            && ($yenc['header_numeric_fields_valid'] ?? null) === true
            && ($yenc['trailer_numeric_fields_valid'] ?? null) === true
            && is_string($declared) && preg_match('/\A[0-9a-f]{1,8}\z/i', trim($declared)) === 1
            && is_string($observed) && hexdec(trim($declared)) === hexdec($observed);
    }

    /**
     * MD5 of a proven part's first min(file size, 16 KiB) bytes, when the part starts the file and
     * covers that whole prefix (the same basis the analyzer uses for its PAR2 prefix candidate).
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function verifiedPartPrefixMd5(array $yenc, mixed $decoded): ?string
    {
        $fileSize = $yenc['file_size'] ?? null;
        if (! is_string($decoded) || ! is_int($fileSize) || $fileSize < 1 || ($yenc['begin'] ?? null) !== 1) {
            return null;
        }
        $length = min($fileSize, 16_384);

        return strlen($decoded) >= $length ? md5(substr($decoded, 0, $length)) : null;
    }

    /**
     * True only when every size the article declares agrees with the decoded bytes, false when one
     * disagrees, and null when the evidence is missing or malformed.
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function sizesAgree(array $yenc): ?bool
    {
        $checks = ['decoded_size_matches_yend_size', ($yenc['multipart'] ?? false) === true ? 'decoded_size_matches_ypart_range' : 'decoded_size_matches_yenc'];
        $values = array_map(static fn (string $check): mixed => $yenc[$check] ?? null, $checks);
        if (in_array(false, $values, true)) {
            return false;
        }

        return $values === [true, true] ? true : null;
    }

    /**
     * Every CRC an article declares must agree with its decoded bytes: both crc32 and pcrc32 for a
     * single-part article, and pcrc32 for a part (whose crc32 covers the whole file).
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function declaredCrcsContradict(array $yenc): bool
    {
        $observed = strtolower((string) ($yenc['observed_decoded_crc32'] ?? ''));
        $trailer = is_array($yenc['trailer_fields'] ?? null) ? $yenc['trailer_fields'] : [];
        $fields = ($yenc['multipart'] ?? false) === true ? ['pcrc32'] : ['crc32', 'pcrc32'];
        foreach ($fields as $field) {
            $declared = $trailer[$field] ?? null;
            if (is_string($declared) && $observed !== '' && strtolower(str_pad(ltrim(trim($declared), '0'), 8, '0', STR_PAD_LEFT)) !== str_pad(ltrim($observed, '0'), 8, '0', STR_PAD_LEFT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A valid empty file: single part, declared size 0, nothing decoded, and every other numeric header
     * field valid. The analyzer treats size 0 as a non-positive numeric field, which is only wrong here.
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function emptySinglePart(array $yenc): bool
    {
        $header = is_array($yenc['header_fields'] ?? null) ? $yenc['header_fields'] : [];
        // Only size may be zero; every other numeric header field must still be a positive decimal.
        foreach (['line', 'part', 'total'] as $field) {
            if (array_key_exists($field, $header)
                && (! is_string($header[$field]) || preg_match('/\A[1-9][0-9]{0,18}\z/', $header[$field]) !== 1)) {
                return false;
            }
        }

        return ($yenc['multipart'] ?? null) === false
            && ($header['size'] ?? null) === '0'
            && ($yenc['decoded_segment_size'] ?? null) === 0
            && ($yenc['decoded_size_matches_yend_size'] ?? null) === true;
    }

    private static function subjectFilename(string $subject): string
    {
        return preg_match('/"([^"]+)"/', $subject, $match) === 1 ? $match[1] : $subject;
    }

    private static function bounded(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Cut on a UTF-8 boundary by bytes, so multibyte names cannot inflate the record.
        return mb_strcut($value, 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    /** @param array<string, mixed> $file */
    private static function fileBytes(array $file): int
    {
        return is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : 0;
    }

    /**
     * Files whose NZB records the analyzer found corrupt (or 1 when the release byte total is invalid
     * or not representable).
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function manifestInvalidFileCount(array $manifest): int
    {
        $invalid = 0;
        foreach ($manifest['files'] as $file) {
            if ((int) ($file['invalid_segment_record_count'] ?? 0) > 0
                || (int) ($file['invalid_segment_metadata_count'] ?? 0) > 0
                || (int) ($file['duplicate_message_id_count'] ?? 0) > 0
                || (int) ($file['duplicate_segment_number_count'] ?? 0) > 0
                || ($file['segment_number_sequence_valid'] ?? null) === false
                || ($file['advertised_bytes_overflow'] ?? false) === true) {
                $invalid++;
            }
        }

        $total = 0;
        foreach ($manifest['files'] as $file) {
            $fileBytes = $file['advertised_bytes'] ?? null;
            $total = $total === null || ! is_int($fileBytes) || $fileBytes > PHP_INT_MAX - $total ? null : $total + $fileBytes;
        }
        $totalInvalid = ($manifest['advertised_byte_totals_valid'] ?? true) === false || $total === null;

        return $invalid === 0 && $totalInvalid ? 1 : $invalid;
    }

    /**
     * Null when the copy positively identifies as the requested article: its Message-ID header is
     * present and exactly equal (Message-IDs are case-sensitive), and any Message-ID in the ARTICLE
     * status line is exactly equal too. Otherwise the error type to record.
     *
     * @param  array<string, mixed>  $fetched
     */
    private static function identityStatus(string $requested, mixed $headerMessageId, array $fetched): ?string
    {
        $want = '<'.trim($requested, '<>').'>';
        if (! is_string($headerMessageId) || trim($headerMessageId) === '') {
            return 'ArticleIdentityUnconfirmed';
        }
        if (trim($headerMessageId) !== $want) {
            return 'ArticleIdentityMismatch';
        }
        $text = $fetched['article_response']['response_text'] ?? null;
        if (is_string($text) && preg_match('/<[^>]+>/', $text, $match) === 1 && $match[0] !== $want) {
            return 'ArticleIdentityMismatch';
        }

        return null;
    }

    /**
     * A part's number and total must be this NZB segment and the file's segment count; a single-part
     * body is only consistent with a single-segment file. Non-yEnc bodies are judged by integrity checks.
     */
    private static function partMatchesSegment(mixed $yenc, int $segment, int $segmentCount): bool
    {
        if (! is_array($yenc)) {
            return true;
        }
        if (($yenc['multipart'] ?? null) !== true) {
            return $segmentCount === 1;
        }

        return ($yenc['part'] ?? null) === $segment
            && (($yenc['total_parts'] ?? null) === null || $yenc['total_parts'] === $segmentCount);
    }

    /** @param array<string, mixed> $profile */
    private static function profileSucceeded(array $profile): bool
    {
        return ! isset($profile['capabilities_error_type'])
            && ! isset($profile['overview_format_error_type'])
            && in_array($profile['capabilities_response_code'] ?? null, [101, 500, 501], true)
            && (($profile['overview_format_response_code'] ?? null) === null
                || in_array($profile['overview_format_response_code'], [215, 500, 501], true));
    }

    /** The provider said the article does not exist; protocol and transport failures are errors. */
    private static function isMissingArticle(Throwable $exception): bool
    {
        return $exception instanceof NntpArticleMissingException;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     *
     * @param-out int $omitted
     *
     * @return list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>|null
     */
    public static function selectSamplesNative(array $files, ?int &$omitted = null): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $omitted = 0;
        if ($files === []) {
            return [];
        }
        $payload = serialize($files);
        $payloadLen = strlen($payload);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(4096, $payloadLen), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_select_samples($payload, $payloadLen, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_compact_fp_select_samples($payload, $payloadLen, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }
        $decoded = unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        if (! is_array($decoded) || count($decoded) !== 2 || ! is_array($decoded[0]) || ! is_int($decoded[1])) {
            return null;
        }
        $omitted = $decoded[1];

        /** @var list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}> */
        return $decoded[0];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $firstPrefixMd5
     * @return array{nzb: array<string, mixed>, files: list<array<string, mixed>>, posting: array{first_utc: string, last_utc: string, span_s: int}|null, invalid_file_count: int, group_count: int}|null
     */
    public static function summarizeFilesNative(array $manifest, array $firstPrefixMd5 = []): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $payload = serialize([$manifest, $firstPrefixMd5]);
        $payloadLen = strlen($payload);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(8192, $payloadLen), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_summarize_files($payload, $payloadLen, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_compact_fp_summarize_files($payload, $payloadLen, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }
        $decoded = unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array{nzb: array<string, mixed>, files: list<array<string, mixed>>, posting: array{first_utc: string, last_utc: string, span_s: int}|null, invalid_file_count: int, group_count: int} */
        return $decoded;
    }

    /**
     * @return array{errors: list<string>, summary: array<string, mixed>|null, part_without_trailer_part: bool, declared_crcs_contradict: bool, empty_single_part: bool, sizes_agree: bool|null}|null
     */
    public static function yencIntegrityNative(mixed $yenc): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        if (is_object($yenc)) {
            return null;
        }
        $payload = serialize($yenc);
        $payloadLen = strlen($payload);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(2048, $payloadLen * 2), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_yenc_integrity($payload, $payloadLen, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_compact_fp_yenc_integrity($payload, $payloadLen, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }
        $decoded = unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array{errors: list<string>, summary: array<string, mixed>|null, part_without_trailer_part: bool, declared_crcs_contradict: bool, empty_single_part: bool, sizes_agree: bool|null} */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $yenc
     *
     * @param-out bool $supported
     */
    public static function verifiedPartPrefixMd5Native(array $yenc, mixed $decoded, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $fileSize = $yenc['file_size'] ?? null;
        $begin = $yenc['begin'] ?? null;
        if (! is_string($decoded) || ! is_int($fileSize) || ! is_int($begin)) {
            $supported = true;

            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_verified_part_prefix_md5($decoded, strlen($decoded), $fileSize, $begin, $scratch, $cap);
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 32 ? FFI::string($scratch, 32) : null;
    }

    public static function subjectFilenameNative(string $subject): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($subject);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 16), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_subject_filename_bounded($subject, $len, 0, $scratch, $cap);
        if (! is_int($written) || $written < 0) {
            return null;
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * @param-out bool $supported
     */
    public static function boundedNative(mixed $value, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            $supported = true;

            return null;
        }
        $str = (string) $value;
        $len = strlen($str);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_subject_filename_bounded($str, $len, 1, $scratch, $cap);
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    public static function subjectFilenameBoundedNative(string $subject): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($subject);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_subject_filename_bounded($subject, $len, 2, $scratch, $cap);
        if (! is_int($written) || $written < 0) {
            return null;
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<string>|null
     */
    public static function subjectFilenameBoundedBatchNative(array $subjects): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $count = count($subjects);
        if ($count === 0) {
            return [];
        }
        $packed = implode("\0", $subjects);
        if (substr_count($packed, "\0") !== $count - 1) {
            return null;
        }
        $len = strlen($packed);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $count * (self::MAX_NAME_LENGTH + 1) + 64), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_subject_filename_bounded($packed, $len, 3, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_compact_fp_subject_filename_bounded($packed, $len, 3, $scratch, $cap);
            if (! is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return array_fill(0, $count, '');
        }
        $parts = explode("\0", FFI::string($scratch, $written));

        return count($parts) === $count ? $parts : null;
    }

    /**
     * @return array{
     *     samples: list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>,
     *     omitted: int,
     *     segment_counts: array<int, int>,
     *     manifest_invalid_files: int,
     *     nzb: array<string, mixed>,
     *     files: list<array<string, mixed>>,
     *     posting: array{first_utc: string, last_utc: string, span_s: int}|null,
     *     group_count: int,
     *     file_count: int,
     *     nzb_names: array<string, true>
     * }|null
     */
    public static function analyzeNzbForCompactNative(string $xml): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $xmlLen = strlen($xml);
        if ($xmlLen === 0 || $xmlLen > 33_554_432) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(65_536, min($xmlLen, 131_072)), $cap);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_compact_fp_analyze_nzb_xml($xml, $xmlLen, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_compact_fp_analyze_nzb_xml($xml, $xmlLen, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }
        $decoded = unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array{samples: list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>, omitted: int, segment_counts: array<int, int>, manifest_invalid_files: int, nzb: array<string, mixed>, files: list<array<string, mixed>>, posting: array{first_utc: string, last_utc: string, span_s: int}|null, group_count: int, file_count: int, nzb_names: array<string, true>} */
        return $decoded;
    }
}
