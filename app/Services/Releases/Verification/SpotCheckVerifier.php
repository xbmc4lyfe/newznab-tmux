<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class SpotCheckVerifier
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_verification_sample_plan(const char *data, size_t length, const char *identity, size_t identity_length, int64_t maximum_articles, int64_t maximum_header_bytes, int64_t article_limit, char *out_buf, size_t out_cap);
        int nntmux_spot_article_dimensions(int64_t part, int64_t number, int64_t size, int64_t offset, int64_t length, int64_t header_bytes, int64_t total);
        C;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

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
        $parityFileKeys = [];
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
            $isPar2Packet = $article->offset === 0 && str_starts_with($article->bytes, "PAR2\0PKT");
            if (! (self::$useRustFfi ? self::articleDimensionsMatchNative($article, $segment)
                : self::articleDimensionsMatchPhp($article, $segment))
                || ! $this->matchesHeaderFilename($manifest, $segment['file_key'], $article->name, $isPar2Packet)
                || ($article->totalParts !== null && $article->totalParts !== $segment['totalparts'])) {
                return self::deterministic('Spot-check article identity differs from its header.');
            }
            $fileKey = $segment['file_key'];
            if ($isPar2Packet || HeaderFilenameIdentity::isPar2Filename($article->name)) {
                $parityFileKeys[$fileKey] = true;
            }
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

        $evidence = ['articles' => count($sample), 'bytes' => $bytes,
            'sampled_files' => count(array_unique(array_column($sample, 'file_key'))),
            'verified_file_names' => array_keys($names), 'identity_hints' => array_column($identityHints, 'name'),
            'partial' => $partial !== null];
        if ($parityFileKeys !== []) {
            $evidence['parity_file_keys'] = array_keys($parityFileKeys);
        }
        $sampled = self::withEvidence($manifest, $evidence);
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
        if (self::$useRustFfi && $this->planner === null) {
            return $this->sampleNative($manifest);
        }

        return $this->samplePhp($manifest);
    }

    /** @param array<string, mixed> $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public function samplePhp(array $manifest): array
    {
        $articleLimit = max(0, min(16, (int) ($this->options['spot_check_articles'] ?? 1)));
        $byteLimit = max(0, min(1048576, (int) ($this->options['spot_check_bytes'] ?? 1048576)));
        $articleByteLimit = max(0, min($byteLimit, (int) ($this->options['spot_check_article_bytes'] ?? 1048576)));

        return ($this->planner ?? new SpotCheckPlanner)->select($manifest, $articleLimit, $byteLimit, $articleByteLimit);
    }

    /** @param array<string, mixed> $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public function sampleNative(array $manifest): array
    {
        // An injected planner retains ownership of selection, including its hooks.
        if ($this->planner !== null || ! is_array($manifest['files'] ?? null) || ! is_string($manifest['identity'] ?? '')) {
            return $this->samplePhp($manifest);
        }
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return $this->samplePhp($manifest);
        }
        $articleLimit = max(0, min(16, (int) ($this->options['spot_check_articles'] ?? 1)));
        $byteLimit = max(0, min(1048576, (int) ($this->options['spot_check_bytes'] ?? 1048576)));
        $articleByteLimit = max(0, min($byteLimit, (int) ($this->options['spot_check_article_bytes'] ?? 1048576)));
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || ! is_int($file['totalparts'] ?? 0)
                || ! is_bool($file['totalparts_inferred'] ?? false) || ! is_array($file['segments'] ?? null)) {
                return $this->samplePhp($manifest);
            }
            $segments = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_string($segment['messageid'] ?? null)
                    || ! is_int($segment['number'] ?? null) || ! is_int($segment['bytes'] ?? null)) {
                    return $this->samplePhp($manifest);
                }
                $segments[] = ['messageid' => $segment['messageid'], 'number' => $segment['number'], 'bytes' => $segment['bytes']];
            }
            $files[] = ['key' => $file['key'], 'totalparts' => $file['totalparts'] ?? 0,
                'totalparts_inferred' => $file['totalparts_inferred'] ?? false, 'segments' => $segments];
        }
        $input = serialize($files);
        $identity = $manifest['identity'] ?? '';
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
        if ($out === null) {
            return $this->samplePhp($manifest);
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_sample_plan($input, strlen($input), $identity, strlen($identity),
            $articleLimit, $byteLimit, $articleByteLimit, $out, $capacity);
        if (! is_int($written) || $written < 0 || $written % 64 !== 0) {
            return $this->samplePhp($manifest);
        }
        if ($written === 0) {
            return [];
        }
        $cells = unpack('q*', FFI::string($out, $written));
        if ($cells === false) {
            return $this->samplePhp($manifest);
        }
        $sample = [];
        for ($cell = 1, $end = count($cells); $cell < $end; $cell += 8) {
            $sample[] = ['file_key' => substr($input, $cells[$cell], $cells[$cell + 1]), 'totalparts' => $cells[$cell + 2],
                'totalparts_inferred' => $cells[$cell + 3] === 1, 'number' => $cells[$cell + 4],
                'messageid' => substr($input, $cells[$cell + 5], $cells[$cell + 6]), 'bytes' => $cells[$cell + 7]];
        }

        return $sample;
    }

    /** @param array{number:int,bytes:int,totalparts:int} $segment */
    public static function articleDimensionsMatchPhp(VerifiedArticle $article, array $segment): bool
    {
        // OVER octets include headers and encoding; they must cover decoded bytes.
        return $article->part === $segment['number'] && $article->size >= 1
            && strlen($article->bytes) <= $segment['bytes']
            && $article->offset + strlen($article->bytes) <= $article->size
            && ($article->part !== 1 || $article->offset === 0)
            && ($article->part !== $segment['totalparts'] || $article->offset + strlen($article->bytes) === $article->size);
    }

    /** @param array{number:int,bytes:int,totalparts:int} $segment */
    public static function articleDimensionsMatchNative(VerifiedArticle $article, array $segment): bool
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null || $article->offset > PHP_INT_MAX - strlen($article->bytes)) {
            return self::articleDimensionsMatchPhp($article, $segment);
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_spot_article_dimensions($article->part, $segment['number'], $article->size, $article->offset,
            strlen($article->bytes), $segment['bytes'], $segment['totalparts']);

        return match ($status) {
            0 => false,
            1 => true,
            default => self::articleDimensionsMatchPhp($article, $segment),
        };
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
     * @return array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>, partial: bool, parity_file_keys?: list<string>}
     */
    public static function evidence(array $manifest): array
    {
        if (($manifest['verification_mode'] ?? null) !== 'spot_check' || ! is_array($manifest['spot_check'] ?? null)) {
            throw new \RuntimeException('Sampled verdict has no spot-check evidence.');
        }

        $evidence = ['articles' => (int) $manifest['spot_check']['articles'], 'bytes' => (int) $manifest['spot_check']['bytes'],
            'sampled_files' => (int) $manifest['spot_check']['sampled_files'],
            'verified_file_names' => array_values(array_map('strval', (array) ($manifest['verified_file_names'] ?? []))),
            'identity_hints' => array_values(array_map(static fn (mixed $hint): string => (string) ($hint['name'] ?? ''),
                (array) ($manifest['identity_hints'] ?? []))), 'partial' => isset($manifest['partial'])];
        $parityKeys = array_values(array_filter(array_map('strval', (array) ($manifest['spot_check']['parity_file_keys'] ?? [])),
            static fn (string $key): bool => $key !== ''));
        if ($parityKeys !== []) {
            $evidence['parity_file_keys'] = $parityKeys;
        }

        return $evidence;
    }

    /**
     * Derive every publication field from the stored manifest and its sampled evidence, so a
     * remote worker's result is rebuilt exactly as a local one.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>, partial?: bool, parity_file_keys?: list<string>}  $evidence
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
        $parityKeys = array_values(array_filter(array_map('strval', (array) ($evidence['parity_file_keys'] ?? [])),
            static fn (string $key): bool => $key !== ''));
        if ($parityKeys !== []) {
            $manifest['spot_check']['parity_file_keys'] = $parityKeys;
        }
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
    private function matchesHeaderFilename(array $manifest, string $key, string $actual, bool $isPar2Packet = false): bool
    {
        foreach ($manifest['files'] ?? [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            if (($file['key'] ?? null) !== $key) {
                continue;
            }

            return HeaderFilenameIdentity::matches((string) ($file['subject'] ?? ''), $actual, $isPar2Packet);
        }

        return false;
    }

    // Rust is the default only while the paired W58.6 benchmark clears 1.5x on all four metrics.
    private const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        int32_t nntmux_w586_spot_response_batch(const char *responses, size_t responses_len, const char *response_twin, size_t response_twin_len, const char *ints, size_t ints_len, size_t count, int64_t threshold, int64_t *out, size_t out_cap);
        C;

    private const int W58_MAX_ITEMS = 1_048_576;

    public const int W58_SPOT_HEADER = 14;

    /**
     * Classify spot-check NNTP responses to ARTICLE (0, expects 220), HEAD (1, 221), BODY (2, 222) or
     * STAT (3, 223); any other command is 4. A status code is three ASCII digits ending the line or
     * followed by a space, tab, CR or LF (-1 otherwise). Class 0 present, 1 another 22x success, 2
     * missing (423/430), 3 transient (other 4xx), 4 error (5xx), 5 malformed or unexpected.
     *
     * Layout: `[0]` count, `[1..6]` per class, `[7]` found (class 0-1), `[8]` missing, `[9]` first
     * missing and `[10]` first transient or error index (-1 when none), `[11]` missing ppm, `[12]`
     * verdict (0 pass, 1 missing above `$missingThresholdPpm`, 2 inconclusive, 3 empty), `[13]` FNV-1a
     * digest; then per response `class | command << 3 | has_message_id << 6 | crlf << 7 | (code + 1)
     * << 8 | min(bytes, 65535) << 18`.
     *
     * @param  array<array-key, string>  $responses
     * @param  array<array-key, int>  $commands
     * @return list<int>
     */
    public static function classifyResponseBatch(array $responses, array $commands, int $missingThresholdPpm = 0): array
    {
        return (self::usesW58RustFfi() ? self::classifyResponseBatchNative($responses, $commands, $missingThresholdPpm) : null)
            ?? self::classifyResponseBatchPhp($responses, $commands, $missingThresholdPpm);
    }

    /**
     * @param  array<array-key, string>  $responses
     * @param  array<array-key, int>  $commands
     * @return list<int>
     */
    public static function classifyResponseBatchPhp(array $responses, array $commands, int $missingThresholdPpm = 0): array
    {
        $responses = array_values($responses);
        $commands = array_values($commands);
        $count = \count($responses);
        $out = array_fill(0, self::W58_SPOT_HEADER, 0);
        $out[0] = $count;
        $out[9] = $out[10] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($responses as $at => $response) {
            $response = (string) $response;
            $command = (int) ($commands[$at] ?? 0);
            if ($command < 0 || $command > 3) {
                $command = 4;
            }
            $length = \strlen($response);
            $code = -1;
            if ($length >= 3 && ctype_digit(substr($response, 0, 3)) && ($length === 3 || str_contains(" \t\r\n", $response[3]))) {
                $code = (int) substr($response, 0, 3);
            }
            $class = $command < 4 && $code === 220 + $command ? 0 : match (true) {
                $code >= 220 && $code <= 223 => 1,
                $code === 423 || $code === 430 => 2,
                $code >= 400 && $code <= 499 => 3,
                $code >= 500 && $code <= 599 => 4,
                default => 5,
            };
            $hasId = ($lt = strpos($response, '<')) !== false && strpos($response, '>', $lt) !== false;
            $out[1 + $class]++;
            $out[7] += (int) ($class <= 1);
            if ($class === 2) {
                $out[8]++;
                if ($out[9] < 0) {
                    $out[9] = $at;
                }
            }
            if (($class === 3 || $class === 4) && $out[10] < 0) {
                $out[10] = $at;
            }
            $row = $class | $command << 3 | (int) $hasId << 6 | (int) str_ends_with($response, "\r\n") << 7 | ($code + 1) << 8
                | min($length, 65_535) << 18;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[11] = intdiv($out[8] * 1_000_000, $count);
        }
        $out[12] = match (true) {
            $count === 0 => 3,
            $out[11] > $missingThresholdPpm => 1,
            $out[2] + $out[4] + $out[5] + $out[6] > 0 => 2,
            default => 0,
        };
        $out[13] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $responses
     * @param  array<array-key, int>  $commands
     * @return list<int>|null
     */
    public static function classifyResponseBatchNative(array $responses, array $commands, int $missingThresholdPpm = 0): ?array
    {
        $count = \count($responses);
        if ($count > self::W58_MAX_ITEMS || \count($commands) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w58Strings(array_values($responses));
        $ints = pack('q*', ...array_values($commands));
        $fields = self::W58_SPOT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w58Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w586_spot_response_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $missingThresholdPpm, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 58.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $forced = self::w58RustFfiState();

        return \is_bool($forced) ? $forced : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 58.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w58` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w58', self::W58_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 58.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w58Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w58RustFfiState('binding', $fields);
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
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w58Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
