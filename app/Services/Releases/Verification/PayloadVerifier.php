<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class PayloadVerifier
{
    private const string FFI_CDEF = <<<'C'
        int nntmux_payload_article_identity(int64_t part, int64_t number, int64_t total, int64_t observed, uint8_t inferred, uint8_t has_article_total, int64_t article_total, uint8_t has_body_total, int64_t body_total, uint8_t identity_matches);
        ssize_t nntmux_payload_subject_name(const char *input, size_t length, char *out, size_t capacity);
        int nntmux_payload_par2_filename(const char *input, size_t length);
        C;

    // Single-call candidates remain opt-in until paired mean and p99 gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private int $initialScratchBytes = 0;

    private int $materializedCacheBytes = 0;

    /**
     * @param  array<string, mixed>  $options
     * @param  (\Closure(string): (float|false))|null  $diskFreeSpace
     */
    public function __construct(private readonly ArticleSource $source, private readonly array $options,
        private readonly ?\Closure $diskFreeSpace = null, private readonly ?YencService $decoder = null,
        private readonly ?ScratchBudget $scratchBudget = null) {}

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest, string $directory, callable $heartbeat): VerificationResult
    {
        if (($this->options['mode'] ?? 'full') === 'spot_check') {
            return (new SpotCheckVerifier($this->source, $this->options, $this->decoder))->verify($manifest, $heartbeat);
        }

        try {
            return $this->downloadAndVerify($manifest, $directory, $heartbeat);
        } catch (Throwable $error) {
            return new VerificationResult('unknown', $error->getMessage());
        }
    }

    private function validateArticleIdentity(VerifiedArticle $article, int $number, int $totalParts, int $observedParts,
        bool $inferredParts, ?int $bodyTotalParts, ?VerifiedArticle $identity): void
    {
        $reason = self::$useRustFfi
            ? self::articleIdentityRejectionNative($article, $number, $totalParts, $observedParts, $inferredParts, $bodyTotalParts, $identity)
            : self::articleIdentityRejectionPhp($article, $number, $totalParts, $observedParts, $inferredParts, $bodyTotalParts, $identity);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    public static function articleIdentityRejectionPhp(VerifiedArticle $article, int $number, int $totalParts, int $observedParts,
        bool $inferredParts, ?int $bodyTotalParts, ?VerifiedArticle $identity): ?string
    {
        if ($article->part !== $number) {
            return 'Article part number differs from the manifest.';
        }
        if ($article->totalParts !== null) {
            if ($bodyTotalParts !== null && $article->totalParts !== $bodyTotalParts) {
                return 'Conflicting article part totals.';
            }
            if (! $inferredParts && $article->totalParts !== $totalParts) {
                return 'Article part total differs from declared manifest inventory.';
            }
            if ($inferredParts && $article->totalParts < $observedParts) {
                return 'Article part total contradicts observed segment inventory.';
            }
            $totalParts = $article->totalParts;
        }
        if ($article->part > $totalParts) {
            return 'Article part number exceeds manifest inventory.';
        }
        if ($identity !== null && [$article->name, $article->size] !== [$identity->name, $identity->size]) {
            return 'Conflicting file identities.';
        }

        return null;
    }

    public static function articleIdentityRejectionNative(VerifiedArticle $article, int $number, int $totalParts, int $observedParts,
        bool $inferredParts, ?int $bodyTotalParts, ?VerifiedArticle $identity): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return self::articleIdentityRejectionPhp($article, $number, $totalParts, $observedParts, $inferredParts, $bodyTotalParts, $identity);
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_payload_article_identity($article->part, $number, $totalParts, $observedParts, (int) $inferredParts,
            (int) ($article->totalParts !== null), $article->totalParts ?? 0, (int) ($bodyTotalParts !== null), $bodyTotalParts ?? 0,
            (int) ($identity === null || [$article->name, $article->size] === [$identity->name, $identity->size]));

        return match ($status) {
            0 => null,
            1 => 'Article part number differs from the manifest.',
            2 => 'Conflicting article part totals.',
            3 => 'Article part total differs from declared manifest inventory.',
            4 => 'Article part total contradicts observed segment inventory.',
            5 => 'Article part number exceeds manifest inventory.',
            6 => 'Conflicting file identities.',
            default => self::articleIdentityRejectionPhp($article, $number, $totalParts, $observedParts, $inferredParts, $bodyTotalParts, $identity),
        };
    }

    public static function requiredNamePhp(string $subject): ?string
    {
        $name = preg_match('/"([^"\r\n]+)"/', $subject, $match) ? $match[1] : $subject;
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || $name[0] === '.'
            || $name[0] === '-' || preg_match('/[\x00-\x1f\x7f]/', $name)
            || preg_match('/[\[\]]|yEnc/i', $name)) {
            return null;
        }

        return $name;
    }

    public static function requiredNameNative(string $subject): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return self::requiredNamePhp($subject);
        }
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, max(1, strlen($subject)), $capacity);
        if ($out === null) {
            return self::requiredNamePhp($subject);
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_payload_subject_name($subject, strlen($subject), $out, $capacity);
        if (! is_int($written) || $written < 0) {
            return self::requiredNamePhp($subject);
        }

        return $written === 0 ? null : FFI::string($out, $written);
    }

    public static function validPar2FilenamePhp(string $filename): bool
    {
        return $filename !== '' && strlen($filename) <= 200 && mb_check_encoding($filename, 'UTF-8')
            && ! preg_match('/[\x00-\x1f\x7f]/', $filename) && basename($filename) === $filename
            && ! str_contains($filename, '\\') && $filename[0] !== '.' && $filename[0] !== '-';
    }

    public static function validPar2FilenameNative(string $filename): bool
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi !== null) {
            // @phpstan-ignore method.notFound
            $valid = $ffi->nntmux_payload_par2_filename($filename, strlen($filename));
            if ($valid === 0 || $valid === 1) {
                return $valid === 1;
            }
        }

        return self::validPar2FilenamePhp($filename);
    }

    /** @param array<string, mixed> $manifest */
    private function downloadAndVerify(array $manifest, string $directory, callable $heartbeat): VerificationResult
    {
        if ($manifest['conflict'] ?? false) {
            return new VerificationResult('unknown', 'Conflicting header identities require resolution.');
        }
        if (is_link(rtrim($directory, '/\\'))) {
            throw new RuntimeException('Scratch candidate directory cannot be a symlink.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Scratch directory is unavailable.');
        }
        $cache = $directory.'/articles';
        $data = $directory.'/data';
        if (is_link($cache)) {
            throw new RuntimeException('Article cache directory cannot be a symlink.');
        }
        foreach ([$cache, $data] as $path) {
            if (! is_dir($path) && ! mkdir($path, 0700)) {
                throw new RuntimeException('Cannot allocate scratch storage.');
            }
        }
        $this->resetData($data, $heartbeat);
        $files = [];
        $filenameIdentities = [];
        $available = $manifest;
        $scratchRoot = $this->scratchBudget === null ? dirname($directory) : $directory;
        $this->initialScratchBytes = $this->directoryBytes($scratchRoot, $heartbeat);
        $allocated = $this->initialScratchBytes;
        $this->materializedCacheBytes = 0;
        $networkFailure = false;
        $requiredNames = [];
        $fileChecksums = [];
        foreach ($manifest['files'] as $index => $file) {
            $ranges = [];
            $partNumbers = [];
            $validatedParts = [];
            $identity = null;
            $bodyTotalParts = null;
            $totalParts = (int) $file['totalparts'];
            $inferredParts = ($file['totalparts_inferred'] ?? false) === true;
            $available['files'][$index]['segments'] = [];
            $batchSize = $this->source instanceof NntpArticleSource ? $this->source->batchSize() : 1;
            $batchStarted = 0.0;
            $batchBytes = 0;
            foreach ($file['segments'] as $segmentIndex => $segment) {
                $heartbeat();
                if ($batchSize > 1 && $this->source instanceof NntpArticleSource && $segmentIndex % $batchSize === 0) {
                    $ids = [];
                    foreach (array_slice($file['segments'], $segmentIndex, $batchSize) as $pendingSegment) {
                        $pendingId = (string) $pendingSegment['messageid'];
                        if (! is_file($cache.'/'.hash('sha256', $pendingId))) {
                            $ids[] = $pendingId;
                        }
                    }
                    $batchStarted = microtime(true);
                    $batchBytes = 0;
                    $this->source->prefetch($ids, $heartbeat);
                }
                $number = (int) $segment['number'];
                if ($number < 1 || isset($partNumbers[$number])) {
                    throw new RuntimeException('Invalid or duplicate article part number.');
                }
                $partNumbers[$number] = true;
                $id = (string) $segment['messageid'];
                $path = $cache.'/'.hash('sha256', $id);
                $article = null;
                $validate = fn (VerifiedArticle $decoded) => $this->validateArticleIdentity($decoded, $number, $totalParts,
                    (int) $file['totalparts'], $inferredParts, $bodyTotalParts, $identity);
                if (is_file($path)) {
                    $raw = file_get_contents($path, false, null, 0, (int) $this->options['article_bytes'] + 1);
                    if ($raw !== false && strlen($raw) <= (int) $this->options['article_bytes']) {
                        try {
                            $decoded = VerifiedArticle::decode($raw, $this->decoder);
                            $validate($decoded);
                            $article = $decoded;
                        } catch (RuntimeException) {
                            $this->discardCheckpoint($path, $allocated);
                        }
                    } else {
                        $this->discardCheckpoint($path, $allocated);
                    }
                }
                if ($article === null) {
                    try {
                        $start = microtime(true);
                        if ($this->source instanceof NntpArticleSource) {
                            $fetched = $this->source->fetchVerified($id, $validate);
                            $raw = $fetched->raw;
                            $article = $fetched->article;
                        } else {
                            $raw = $this->source->fetch($id);
                        }
                    } catch (RuntimeException) {
                        $networkFailure = true;

                        continue;
                    }
                    if (strlen($raw) > (int) $this->options['article_bytes']) {
                        throw new RuntimeException('Article exceeds memory budget.');
                    }
                    try {
                        $article ??= VerifiedArticle::decode($raw, $this->decoder);
                    } catch (ArticleIntegrityFailure) {
                        $networkFailure = true;

                        continue;
                    }
                    try {
                        $validate($article);
                    } catch (RuntimeException) {
                        $networkFailure = true;

                        continue;
                    }
                    $this->discardCheckpoint($path.'.tmp', $allocated);
                    $this->reserve($directory, $allocated + strlen($raw));
                    if (file_put_contents($path.'.tmp', $raw, LOCK_EX) !== strlen($raw) || ! rename($path.'.tmp', $path)) {
                        throw new RuntimeException('Cannot checkpoint downloaded article.');
                    }
                    $allocated += strlen($raw);
                    $this->materializedCacheBytes += strlen($raw);
                    $rate = (int) $this->options['bytes_per_second'];
                    if ($rate > 0) {
                        $batchBytes += strlen($raw);
                        $wait = $batchSize > 1 ? $batchBytes / $rate - (microtime(true) - $batchStarted)
                            : strlen($raw) / $rate - (microtime(true) - $start);
                        while ($wait > 0) {
                            $heartbeat();
                            $pause = min(1.0, $wait);
                            usleep((int) ($pause * 1000000));
                            $wait -= $pause;
                        }
                    }
                }
                $this->reserve($directory, $allocated);
                if ($article->totalParts !== null) {
                    $bodyTotalParts = $article->totalParts;
                    $totalParts = $bodyTotalParts;
                    $available['files'][$index]['totalparts'] = $totalParts;
                }
                if ($identity === null) {
                    $normalizedName = mb_strtolower(\Normalizer::normalize($article->name) ?: $article->name);
                    if (isset($filenameIdentities[$normalizedName])) {
                        throw new RuntimeException('Filename aliases collide on the storage filesystem.');
                    }
                    $filenameIdentities[$normalizedName] = $article->name;
                    if (isset($files[$article->name])) {
                        throw new RuntimeException('Ambiguous duplicate filename.');
                    }
                    $identity = $article;
                    $this->reserve($directory, $allocated + 3 * $article->size);
                    $handle = fopen($data.'/'.$article->name, 'w+b');
                    if ($handle === false || ! ftruncate($handle, $article->size)) {
                        throw new RuntimeException('Cannot allocate decoded file.');
                    }
                    fclose($handle);
                    $allocated += 3 * $article->size;
                }
                if ($article->fileChecksum !== null) {
                    if (isset($fileChecksums[$article->name]) && $fileChecksums[$article->name] !== $article->fileChecksum) {
                        throw new RuntimeException('Conflicting whole-file checksums.');
                    }
                    $fileChecksums[$article->name] = $article->fileChecksum;
                }
                $end = $article->offset + strlen($article->bytes);
                foreach ($ranges as [$begin, $finish]) {
                    if ($article->offset < $finish && $end > $begin) {
                        throw new RuntimeException('Overlapping article ranges.');
                    }
                }
                $handle = fopen($data.'/'.$article->name, 'r+b');
                if ($handle === false || fseek($handle, $article->offset) !== 0
                    || fwrite($handle, $article->bytes) !== strlen($article->bytes)) {
                    throw new RuntimeException('Cannot assemble decoded file.');
                }
                fclose($handle);
                $ranges[] = [$article->offset, $end];
                $validatedParts[$number] = true;
                $available['files'][$index]['segments'][] = $segment;
                $available['files'][$index]['decoded_size'] = $article->size;
            }
            if ($identity !== null) {
                sort($ranges);
                $cursor = 0;
                foreach ($ranges as [$begin, $end]) {
                    if ($begin !== $cursor) {
                        break;
                    }
                    $cursor = $end;
                }
                $files[$identity->name] = ['size' => $identity->size,
                    'available_bytes' => array_sum(array_map(static fn (array $range): int => $range[1] - $range[0], $ranges)),
                    'complete' => $cursor === $identity->size && count($validatedParts) === $totalParts
                        && isset($validatedParts[1]) && max(array_keys($validatedParts)) === $totalParts];
                $requiredNames[] = $identity->name;
            } else {
                $subject = (string) $file['subject'];
                $name = self::$useRustFfi ? self::requiredNameNative($subject) : self::requiredNamePhp($subject);
                if ($name === null) {
                    return new VerificationResult('unknown', 'Required missing file identity is ambiguous.');
                }
                $requiredNames[] = $name;
            }
        }
        if ($files === []) {
            return new VerificationResult('unknown', 'No payload could be downloaded.');
        }
        $expected = (int) ($manifest['expected_files'] ?? 0);
        $parity = array_filter(array_keys($files), static fn (string $name): bool => preg_match('/\.par2\z/i', $name) === 1);
        $protected = [];
        $repaired = false;
        $sourceHashes = [];
        $reservedSources = [];
        $verifiedSetIds = [];
        $sourceProofs = [];
        foreach ($parity as $name) {
            $heartbeat();
            if (! $files[$name]['complete']) {
                continue;
            }
            $par2 = new Par2Info;
            if (! $par2->open($data.'/'.$name) || $par2->error !== '') {
                throw new RuntimeException('Invalid PAR2 metadata.');
            }
            $descriptors = $par2->getFileList();
            if ($descriptors === []) {
                continue;
            }
            foreach ($descriptors as $descriptor) {
                $filename = (string) ($descriptor['name'] ?? '');
                if (! (self::$useRustFfi ? self::validPar2FilenameNative($filename) : self::validPar2FilenamePhp($filename))) {
                    throw new RuntimeException('Unsafe PAR2 filename.');
                }
                $normalizedName = mb_strtolower(\Normalizer::normalize($filename) ?: $filename);
                if (isset($filenameIdentities[$normalizedName]) && $filenameIdentities[$normalizedName] !== $filename) {
                    throw new RuntimeException('PAR2 filename aliases collide on the storage filesystem.');
                }
                $filenameIdentities[$normalizedName] = $filename;
                if (isset($sourceHashes[$filename]) && $sourceHashes[$filename] !== $descriptor['hash']) {
                    throw new RuntimeException('Conflicting PAR2 sets protect different content under one filename.');
                }
                $sourceHashes[$filename] = $descriptor['hash'];
                $size = (int) $descriptor['size'];
                if (! isset($files[$filename]) && ! isset($reservedSources[$filename])) {
                    $allocated += 3 * $size;
                    $reservedSources[$filename] = true;
                }
                $this->reserve($directory, $allocated);
            }
            $packets = $par2->getPackets(true) ?: [];
            $proofKey = Par2ProofIdentity::fromPackets($packets);
            if ($proofKey === null || ! isset($sourceProofs[$proofKey])) {
                $exit = $this->par2('v', $name, $data, $heartbeat);
                if ($exit === 2 && ! $networkFailure) {
                    return new VerificationResult('unrepairable', 'Downloaded payload has insufficient validated PAR2 recovery data.');
                }
                if ($exit !== 0) {
                    // Repair may modify sources shared with any earlier set.
                    $sourceProofs = [];
                    if ($exit !== 1 || $this->par2('r', $name, $data, $heartbeat) !== 0
                        || $this->par2('v', $name, $data, $heartbeat) !== 0) {
                        return new VerificationResult('unknown', 'PAR2 has not proved this candidate repairable.');
                    }
                    $repaired = true;
                }
                foreach ($descriptors as $descriptor) {
                    $filename = (string) $descriptor['name'];
                    if (! is_file($data.'/'.$filename) || filesize($data.'/'.$filename) !== (int) $descriptor['size']
                        || $this->checksum($data.'/'.$filename, 'md5', $heartbeat) !== $descriptor['hash']) {
                        throw new RuntimeException('PAR2 source-file integrity verification failed.');
                    }
                }
                if ($proofKey !== null) {
                    $sourceProofs[$proofKey] = true;
                }
            }
            foreach ($descriptors as $descriptor) {
                $protected[(string) $descriptor['name']] = true;
            }
            $mainSets = [];
            $descriptorSets = [];
            foreach ($packets as $packet) {
                if ($packet['head_type'] === Par2Info::PACKET_MAIN) {
                    $mainSets[(string) $packet['head_set_id']] = true;
                } elseif ($packet['head_type'] === Par2Info::PACKET_FILEDESC) {
                    $descriptorSets[(string) $packet['head_set_id']] = true;
                }
            }
            if (count($mainSets) === 1 && count($descriptorSets) === 1 && array_keys($mainSets) === array_keys($descriptorSets)) {
                $verifiedSetIds += $mainSets;
            }
        }
        $partialRecovery = [];
        foreach ($parity as $name) {
            if ($files[$name]['complete'] || isset($protected[$name])
                || preg_match('/\.vol\d+\+\d+\.par2\z/i', $name) !== 1) {
                continue;
            }
            $partial = new Par2Info;
            $partial->open($data.'/'.$name);
            foreach ($partial->getPackets(true) ?: [] as $packet) {
                if ($packet['head_type'] === Par2Info::PACKET_RECOVERY && isset($verifiedSetIds[(string) $packet['head_set_id']])) {
                    $partialRecovery[$name] = true;
                    break;
                }
            }
        }
        foreach ($requiredNames as $name) {
            if (! isset($partialRecovery[$name]) && ! isset($files[$name]) && ! isset($protected[$name])) {
                return new VerificationResult('unknown', 'Required missing file is not protected by validated PAR2.');
            }
        }
        foreach ($fileChecksums as $name => $checksum) {
            if (! isset($partialRecovery[$name]) && $this->checksum($data.'/'.$name, 'crc32b', $heartbeat) !== $checksum) {
                return new VerificationResult('unknown', 'Whole-file checksum verification failed.');
            }
        }
        foreach ($sourceHashes as $name => $hash) {
            if ($this->checksum($data.'/'.$name, 'md5', $heartbeat) !== $hash) {
                return new VerificationResult('unknown', 'Final PAR2 integrity check failed.');
            }
        }
        foreach ($files as $name => $info) {
            if (! $info['complete'] && ! isset($protected[$name]) && ! isset($partialRecovery[$name])) {
                return new VerificationResult('unknown', 'Required file is incomplete and unverified by PAR2.');
            }
        }
        $knownFiles = count(array_unique(array_merge(array_keys($files), array_keys($protected))));
        if (($expected < 1 && $protected === []) || ($expected > 0 && $knownFiles < $expected)) {
            return new VerificationResult('unknown', 'Declared file inventory cannot be proved complete.');
        }
        if ($networkFailure && $protected === []) {
            return new VerificationResult('unknown', 'Missing articles require a successful PAR2 proof.');
        }

        $available['expected_files'] = $expected;
        $verifiedNames = array_values(array_unique(array_map('strval', array_merge(array_keys(array_diff_key($files, $partialRecovery)), array_keys($protected)))));
        sort($verifiedNames, SORT_STRING);
        $available['verified_file_names'] = $verifiedNames;
        $available['verified_file_count'] = count($verifiedNames);

        $available['identity_hints'] = [];
        foreach (array_keys($files) as $name) {
            if (isset($partialRecovery[$name])) {
                continue;
            }
            $available['identity_hints'][] = ['name' => $name, 'source' => 'yenc'];
        }
        foreach (array_keys($protected) as $name) {
            $available['identity_hints'][] = ['name' => $name, 'source' => 'par2'];
        }
        $available['verified_bytes'] = 0;
        foreach ($files as $name => $info) {
            $available['verified_bytes'] += isset($partialRecovery[$name]) ? $info['available_bytes'] : $info['size'];
        }
        foreach (array_keys($protected) as $name) {
            if (! isset($files[$name])) {
                $available['verified_bytes'] += filesize($data.'/'.$name) ?: 0;
            }
        }

        return new VerificationResult($repaired ? 'repaired' : 'complete', 'Downloaded payload verified.', $available);
    }

    private function resetData(string $directory, callable $heartbeat): void
    {
        $heartbeat();
        if (is_link($directory)) {
            throw new RuntimeException('Derived payload directory cannot be a symlink.');
        }
        $entries = 0;
        $lastHeartbeat = microtime(true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if (++$entries >= 256 || microtime(true) - $lastHeartbeat >= 1.0) {
                $heartbeat();
                $entries = 0;
                $lastHeartbeat = microtime(true);
            }
            $removed = $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (! $removed) {
                throw new RuntimeException('Cannot rebuild derived payload storage.');
            }
        }
        $heartbeat();
    }

    private function checksum(string $path, string $algorithm, callable $heartbeat): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open file for integrity verification.');
        }
        $context = hash_init($algorithm);
        try {
            while (! feof($handle)) {
                $heartbeat();
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read file for integrity verification.');
                }
                hash_update($context, $chunk);
            }
            $heartbeat();

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    private function directoryBytes(string $directory, callable $heartbeat): int
    {
        $heartbeat();
        if (! is_dir($directory)) {
            return 0;
        }
        $bytes = 0;
        $entries = 0;
        $lastHeartbeat = microtime(true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
            if (++$entries >= 256 || microtime(true) - $lastHeartbeat >= 1.0) {
                $heartbeat();
                $entries = 0;
                $lastHeartbeat = microtime(true);
            }
        }
        $heartbeat();

        return $bytes;
    }

    private function discardCheckpoint(string $path, int &$allocated): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $bytes = filesize($path);
        if ($bytes === false || ! unlink($path)) {
            throw new RuntimeException('Cannot discard invalid article checkpoint.');
        }
        clearstatcache(true, $path);
        $allocated -= $bytes;
        $this->initialScratchBytes -= $bytes;
    }

    private function reserve(string $directory, int $bytes): void
    {
        if ($this->scratchBudget !== null) {
            $this->scratchBudget->reserveOwned($bytes);

            return;
        }
        $free = $this->diskFreeSpace === null ? disk_free_space($directory) : ($this->diskFreeSpace)($directory);
        if ($bytes > (int) $this->options['scratch_bytes'] || $free === false
            || $free < (int) $this->options['free_bytes'] + max(0, $bytes - $this->initialScratchBytes - $this->materializedCacheBytes)) {
            throw new RuntimeException('Verification is waiting for scratch capacity.');
        }
    }

    private function par2(string $operation, string $name, string $directory, callable $heartbeat): int
    {
        $process = new Process([(string) $this->options['par2_binary'], $operation, '-q', '-t1', '-T1', '-m256', '--', $name], $directory);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();
        while ($process->isRunning()) {
            $heartbeat();
            usleep(200000);
        }

        return $process->getExitCode() ?? -1;
    }

    /** Wave 51.2 paired benchmarks decide this default; see RustVerificationManifestNntpReaderDeferralsPayloadW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        intptr_t nntmux_w512_payload_required_name_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 51 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w51', self::W51_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w51RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planRequiredNames() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Batch requiredNamePhp() over file subjects. Two words per subject: `flags | min(strlen,
     * 0xFFFF) << 8 | crc32(name) << 32` over the required name, or just the flags when it is
     * unsafe (flags: 1 quoted name, 2 safe required name, 4 also validPar2FilenamePhp(),
     * 8 repeated required name, 16 `.par2` suffix in any case), then the name's byte offset in
     * the subject (-1 when unsafe). Safe, PAR2-safe, repeated and `.par2` counts and the XXH64
     * digest of all earlier words follow.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planRequiredNames(array $subjects): array
    {
        return self::usesW51RustFfi() ? self::planRequiredNamesNative($subjects) : self::planRequiredNamesPhp($subjects);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planRequiredNamesPhp(array $subjects): array
    {
        $out = $seen = [];
        $valid = $par2 = $repeated = $suffixed = 0;
        foreach ($subjects as $subject) {
            $quoted = preg_match('/"([^"\r\n]+)"/', $subject, $match, PREG_OFFSET_CAPTURE) === 1;
            [$name, $offset] = $quoted ? $match[1] : [$subject, 0];
            $flags = (int) $quoted;
            if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || $name[0] === '.'
                || $name[0] === '-' || preg_match('/[\x00-\x1f\x7f]/', $name)
                || preg_match('/[\[\]]|yEnc/i', $name)) {
                $out[] = $flags;
                $out[] = -1;

                continue;
            }
            $flags |= 2;
            $valid++;
            if (self::validPar2FilenamePhp($name)) {
                $flags |= 4;
                $par2++;
            }
            if (isset($seen[$name])) {
                $flags |= 8;
                $repeated++;
            } else {
                $seen[$name] = true;
            }
            if (\strlen($name) >= 5 && strcasecmp(substr($name, -5), '.par2') === 0) {
                $flags |= 16;
                $suffixed++;
            }
            $out[] = $flags | min(\strlen($name), 0xFFFF) << 8 | crc32($name) << 32;
            $out[] = $offset;
        }
        $out[] = $valid;
        $out[] = $par2;
        $out[] = $repeated;
        $out[] = $suffixed;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the subjects are not a list or
     * a subject holds a NUL byte.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planRequiredNamesNative(array $subjects): array
    {
        $count = \count($subjects);
        $ffi = $count === 0 ? null : self::w51RustFfiState('ffi');
        if (! $ffi instanceof FFI || ! array_is_list($subjects)) {
            return self::planRequiredNamesPhp($subjects);
        }
        $joined = implode("\0", $subjects);
        $words = 2 * $count + 5;
        $values = false;
        try {
            $out = self::w51RustFfiState('out', $words);
            if ($out instanceof CData) {
                // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
                $written = $ffi->nntmux_w512_payload_required_name_batch($joined, \strlen($joined), $count, $out, self::w51RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planRequiredNamesPhp($subjects);
    }
}
