<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\Release;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use Throwable;

/**
 * Bounded fingerprint collector: at most SAMPLE_LIMIT articles per release and a record of a few KB.
 *
 * This is the reference implementation of the compact-v1 contract shared with the Rust workers, so
 * the output must stay deterministic apart from the documented timing and timestamp fields.
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

    private const MAX_NAME_LENGTH = 120;

    private const MAX_LISTED_GROUPS = 20;

    /** Serialized record budget; file lists are shortened until the record fits. */
    public const MAX_RECORD_BYTES = 20_480;

    private readonly FingerprintProviderBreaker $breaker;

    public function __construct(private readonly NzbService $nzb, private readonly NzbFingerprintAnalyzer $analyzer,
        private readonly Par2MetadataParser $par2, ?FingerprintProviderBreaker $breaker = null)
    {
        $this->breaker = $breaker ?? new FingerprintProviderBreaker;
    }

    /** @return array{status: string, data: array<string, mixed>} */
    public function collect(Release $release): array
    {
        $xml = $this->nzb->readNzbContents((string) $release->guid);
        if (! is_string($xml) || $xml === '') {
            return ['status' => 'unavailable', 'data' => ['reason' => 'The release NZB is not available locally.']];
        }

        return $this->collectFromNzb($xml, (int) $release->id);
    }

    /** @return array{status: string, data: array<string, mixed>} */
    public function collectFromNzb(string $xml, int $releaseId): array
    {
        $manifest = $this->analyzer->analyzeManifest($xml);
        $files = $manifest['files'];
        $omitted = 0;
        $samples = self::selectSamples($files, $omitted);
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
                if ($server['alternate']) {
                    $this->breaker->recordReachable($server);
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

        $articles = [];
        $softwareCandidates = [];
        $firstPrefixMd5 = [];
        $par2 = null;
        foreach ($samples as $sample) {
            $article = [
                'file_index' => $sample['file_index'],
                'segment' => $sample['segment_number'],
                'role' => $sample['role'],
                'provider_results' => [],
                'yenc' => null,
                'date_utc' => null,
                'xref_group_count' => null,
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
                    $fetched = $nntp->getFingerprintArticleByMessageID($sample['messageid'], self::MAX_ARTICLE_BODY_BYTES,
                        self::MAX_ARTICLE_HEADER_BYTES, $server['alternate'], false);
                    $ms = (int) round((hrtime(true) - $started) / 1_000_000);
                    $providers[$account]['retrieval_ms'][] = $ms;
                    $providers[$account]['articles']['found']++;
                    $article['provider_results'][$account] = [
                        'code' => self::responseCode($fetched['body_response'] ?? null) ?? 222,
                        'bytes' => strlen($fetched['body']),
                        'ms' => $ms,
                    ];
                    // Each provider's copy is decoded so corruption on any of them is reported.
                    $analysis = $this->analyzer->analyzeArticleAndDecodedBytes($fetched['headers'], $fetched['body']);
                    $metadata = $analysis['metadata'];
                    $yenc = self::yencSummary($metadata['yenc'] ?? null);
                    if (($yenc['crc_ok'] ?? null) === false) {
                        $errors[$account]['YencCrcMismatch'] = ($errors[$account]['YencCrcMismatch'] ?? 0) + 1;
                    }
                    if ($article['yenc'] !== null) {
                        continue;
                    }
                    // The article summary comes from the first provider that returned it.
                    $article['yenc'] = $yenc;
                    $article['date_utc'] = self::dateUtc($metadata['headers']['date'] ?? null);
                    $article['xref_group_count'] = self::xrefGroupCount($metadata['headers']['xref'] ?? null);
                    foreach (array_keys($metadata['uploader_software_candidates'] ?? []) as $candidate) {
                        $softwareCandidates[$candidate] = true;
                    }
                    $prefixMd5 = $metadata['yenc']['par2_first_16k_md5_candidate'] ?? null;
                    if ($sample['segment_number'] === 1 && is_string($prefixMd5)) {
                        $firstPrefixMd5[$sample['file_index']] ??= strtolower($prefixMd5);
                    }
                    if ($par2 === null && $sample['kind'] === 'par2' && ($yenc['crc_ok'] ?? null) !== false
                        && is_string($analysis['decoded_bytes'] ?? null)) {
                        $par2 = $this->par2Summary($analysis['decoded_bytes'], $files);
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

        $primary = $providerRows[0] ?? null;
        $status = $primary !== null && $samples !== []
            && $primary['articles']['found'] === count($samples)
            && $errorRows === [] ? 'complete' : 'partial';

        return ['status' => $status, 'data' => self::withinBudget([
            'schema_version' => self::SCHEMA_VERSION,
            'format' => self::FORMAT,
            'collector' => ['engine' => 'php', 'version' => self::SCHEMA_VERSION],
            'nzb' => self::nzbSummary($manifest),
            'samples' => ['limit' => self::SAMPLE_LIMIT, 'selected' => count($samples), 'omitted' => $omitted],
            'providers' => $providerRows,
            'articles' => $articles,
            'files' => self::fileRows($files, $firstPrefixMd5),
            'par2' => $par2,
            'posting' => self::posting($files),
            'uploader_software_candidates' => $softwareCandidates,
            'errors' => $errorRows,
            'truncated' => false,
        ])];
    }

    /**
     * Halve the NZB and PAR2 file lists until the record fits MAX_RECORD_BYTES; every other field is
     * already bounded by sample, group and name limits.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withinBudget(array $record): array
    {
        while (strlen((string) json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_RECORD_BYTES) {
            $files = count($record['files']);
            $par2Files = is_array($record['par2']) ? count($record['par2']['files']) : 0;
            if ($files === 0 && $par2Files === 0) {
                break;
            }
            $record['truncated'] = true;
            if ($files >= $par2Files) {
                $record['files'] = array_slice($record['files'], 0, intdiv($files, 2));
            } else {
                $record['par2']['files'] = array_slice($record['par2']['files'], 0, intdiv($par2Files, 2));
            }
        }

        return $record;
    }

    /**
     * Segment 1 of the smallest PAR2 file, then first/middle/last segments of the largest non-PAR2
     * files, then first segments of the remaining files, up to SAMPLE_LIMIT.
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
        foreach (array_slice($dataFiles, self::LARGEST_FILES_SAMPLED) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }
        foreach (array_slice($par2Files, 1) as $file) {
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
     * @return array<string, mixed>|null
     */
    private function par2Summary(string $decoded, array $files): ?array
    {
        try {
            $parsed = $this->par2->parse($decoded);
        } catch (Throwable) {
            return null;
        }
        $sources = [];
        foreach ($parsed['source_files'] ?? [] as $source) {
            $sources[] = [
                'name' => self::bounded($source['filename'] ?? null),
                'size' => is_int($source['file_length'] ?? null) ? $source['file_length'] : null,
                'md5' => $source['md5'] ?? null,
                'md5_16k' => $source['md5_first_16k'] ?? null,
            ];
        }
        usort($sources, static fn (array $a, array $b): int => [(string) $a['name'], (string) $a['md5']] <=> [(string) $b['name'], (string) $b['md5']]);
        $matches = 0;
        foreach ($sources as $source) {
            if (! is_string($source['name']) || $source['name'] === '') {
                continue;
            }
            foreach ($files as $file) {
                if (str_contains((string) ($file['subject'] ?? ''), $source['name'])) {
                    $matches++;
                    break;
                }
            }
        }

        return [
            'set_id' => $parsed['recovery_set_ids'][0] ?? null,
            'block_size' => $parsed['source_block_size'] ?? null,
            'recovery_blocks' => $parsed['unique_recovery_slice_count'] ?? null,
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
        $groups = [];
        $posters = [];
        $segments = 0;
        $bytes = 0;
        $kinds = [];
        foreach ($manifest['files'] as $file) {
            foreach ($file['groups'] ?? [] as $group) {
                $groups[(string) $group] = true;
            }
            if (($file['poster'] ?? '') !== '') {
                $posters[(string) $file['poster']] = ($posters[(string) $file['poster']] ?? 0) + 1;
            }
            $segments += (int) ($file['segment_count'] ?? 0);
            $bytes += is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : 0;
            $kind = (string) ($file['kind'] ?? 'other');
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        $groups = array_keys($groups);
        sort($groups);
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
        $dates = array_values(array_filter(array_map(static fn (array $file): mixed => $file['date'] ?? null, $files), 'is_int'));
        if ($dates === []) {
            return null;
        }
        $first = min($dates);
        $last = max($dates);

        return ['first_utc' => gmdate('Y-m-d\TH:i:s\Z', $first), 'last_utc' => gmdate('Y-m-d\TH:i:s\Z', $last), 'span_s' => $last - $first];
    }

    /** @return array<string, mixed>|null */
    private static function yencSummary(mixed $yenc): ?array
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
            'crc_ok' => is_bool($yenc['declared_crc32_matches'] ?? null) ? $yenc['declared_crc32_matches'] : null,
        ];
    }

    private static function dateUtc(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        // strtotime() moves a date whose weekday disagrees to the next matching weekday; ignore the weekday.
        $timestamp = strtotime((string) preg_replace('/\A\s*[A-Za-z]{3},\s*/', '', $date));

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private static function xrefGroupCount(mixed $xref): ?int
    {
        if (! is_string($xref) || trim($xref) === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($xref)) ?: [];

        // The first token is the server name; each further token is group:number.
        return max(0, count($parts) - 1);
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

        return mb_substr($value, 0, self::MAX_NAME_LENGTH);
    }

    /** @param array<string, mixed> $file */
    private static function fileBytes(array $file): int
    {
        return is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : 0;
    }

    private static function responseCode(mixed $response): ?int
    {
        return is_array($response) && is_numeric($response['response_code'] ?? null) ? (int) $response['response_code'] : null;
    }

    /** HEAD or BODY answered without the article (normally 430); the NNTP layer reports no code. */
    private static function isMissingArticle(Throwable $exception): bool
    {
        return $exception instanceof \RuntimeException && in_array($exception->getMessage(),
            ['Article headers are unavailable.', 'Article is unavailable; retry verification later.'], true);
    }
}
