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
use FFI\CData;
use Throwable;

/**
 * Reads the release's PAR2 index to learn the real names behind obfuscated NZB files.
 */
class MediaSourceResolver
{
    public const bool W44_RUST_FFI_DEFAULT = true;

    private static ?bool $w44RustFfi = null;

    /** @var array<string, FFI|null> */
    private static array $w44Libraries = [];

    public static function useRustFfi(?bool $enabled): void
    {
        self::$w44RustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$w44RustFfi ?? self::W44_RUST_FFI_DEFAULT;
    }

    private function w44Ffi(): ?FFI
    {
        $key = $this->libraryPath === null ? 'default' : 'path:'.$this->libraryPath;
        if (! array_key_exists($key, self::$w44Libraries)) {
            self::$w44Libraries[$key] = RustPipelineLibrary::resolveDomain('w44_media_source_resolver', self::FFI_CDEF, $this->libraryPath);
        }

        return self::$w44Libraries[$key];
    }

    private const string PAR2_SIGNATURE = "PAR2\0PKT";

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w44_media_source_rank_wanted_files_nul(const char *names, size_t names_len, const char *sizes, size_t sizes_len, const char *video_regex, size_t video_regex_len, size_t count, char *out_buf, size_t out_cap);
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
        return self::usesRustFfi() ? $this->wantedFilesNative($files) : $this->wantedFilesPhp($files);
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
        $ffi = $this->w44Ffi();
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
            $written = $ffi->nntmux_w44_media_source_rank_wanted_files_nul(
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
                $written = $ffi->nntmux_w44_media_source_rank_wanted_files_nul(
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

    // Rust is the default only while the paired W60.6 benchmark clears 1.5x on all four metrics.
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_SOURCE_FFI_CDEF = <<<'C'
        int32_t nntmux_w606_media_source_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t min_bytes, int64_t max_bytes, int64_t *out, size_t out_cap);
        C;

    public const int W60_SOURCE_HEADER = 33;

    private const int W60_MAX_ITEMS = 1_048_576;

    private const int W60_VALUE_CAP = (1 << 40) - 1;

    private const int W60_TOTAL_CAP = 1 << 62;

    /** Video containers 1-8 by lowercase extension. */
    private const array W60_CONTAINERS = [
        'mkv' => 1, 'mp4' => 2, 'm4v' => 2, 'avi' => 3, 'ts' => 4, 'm2ts' => 4, 'mts' => 4, 'wmv' => 5, 'mov' => 6,
        'mpg' => 7, 'mpeg' => 7, 'vob' => 7, 'webm' => 8,
    ];

    /** Non-video source kinds by lowercase extension: 1 archive, 3 image. */
    private const array W60_KINDS = ['rar' => 1, 'zip' => 1, '7z' => 1, '001' => 1, 'jpg' => 3, 'jpeg' => 3, 'png' => 3, 'webp' => 3];

    /** Lowercase codec => 1 AVC, 2 HEVC, 3 AV1, 4 MPEG-4 ASP, 5 VP9 (others 6, unsupported). */
    private const array W60_CODECS = [
        'h264' => 1, 'avc' => 1, 'x264' => 1, 'avc1' => 1, 'hevc' => 2, 'h265' => 2, 'x265' => 2, 'hvc1' => 2,
        'av1' => 3, 'mpeg4' => 4, 'xvid' => 4, 'divx' => 4, 'vp9' => 5,
    ];

    /**
     * Evaluate media source candidates from path, size and codec columns. The base name (after the last `/`
     * or `\`) gives the kind: 2 video, 1 archive (not a later `.partN.rar` volume), 3 image, 0 other. A blank
     * codec (after `trim(" \t")`) is hinted from the name. Sizes clamp to 0..2^40-1. Reasons: 1 no usable
     * kind, 2 below `$minBytes`, 4 above `$maxBytes`, 8 unsupported codec, 16 sample, 32 unsafe path (empty
     * base name, NUL byte or `..` segment). Eligible sources are videos with no reason but sample; priority 3
     * eligible video, 2 eligible sample, 1 safe archive. The best source has the highest priority, then size,
     * then input position.
     *
     * Layout: `[0]` count, `[1..4]` per kind, `[5..11]` per codec, `[12..20]` per container, `[21]` eligible,
     * `[22]` samples, `[23]` too small, `[24]` too large, `[25]` unsupported, `[26]` unsafe, `[27]` best index
     * (-1), `[28]` its priority and `[29]` size, `[30]` eligible bytes (capped at 2^62), `[31]` first safe
     * archive (-1), `[32]` FNV-1a digest; then per candidate `kind | container << 2 | codec << 6 | reasons << 9
     * | eligible << 15 | priority << 16 | codec from name << 18 | min(MiB, 2^20-1) << 19 | min(path bytes,
     * 65535) << 39`.
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, string>  $codecs
     * @return list<int>
     */
    public static function resolveMediaSourceBatch(array $paths, array $sizes, array $codecs, int $minBytes = 1_048_576, int $maxBytes = PHP_INT_MAX): array
    {
        return (self::usesW60RustFfi() ? self::resolveMediaSourceBatchNative($paths, $sizes, $codecs, $minBytes, $maxBytes) : null)
            ?? self::resolveMediaSourceBatchPhp($paths, $sizes, $codecs, $minBytes, $maxBytes);
    }

    /**
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, string>  $codecs
     * @return list<int>
     */
    public static function resolveMediaSourceBatchPhp(array $paths, array $sizes, array $codecs, int $minBytes = 1_048_576, int $maxBytes = PHP_INT_MAX): array
    {
        $paths = array_values($paths);
        $sizes = array_values($sizes);
        $codecs = array_values($codecs);
        $count = \count($paths);
        $out = array_fill(0, self::W60_SOURCE_HEADER, 0);
        $out[0] = $count;
        $out[27] = $out[31] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($paths as $at => $path) {
            $path = (string) $path;
            $normalized = strtr($path, '\\', '/');
            $slash = strrpos($normalized, '/');
            $base = $slash === false ? $path : substr($path, $slash + 1);
            $lower = strtolower($base);
            $dot = strrpos($lower, '.');
            $ext = $dot === false ? '' : substr($lower, $dot + 1);
            $container = self::W60_CONTAINERS[$ext] ?? 0;
            $kind = $container > 0 ? 2 : self::W60_KINDS[$ext] ?? 0;
            if ($ext === 'rar') {
                $stem = substr($lower, 0, (int) $dot);
                $part = strrpos($stem, '.part');
                if ($part !== false) {
                    $digits = substr($stem, $part + 5);
                    if ($digits !== '' && ctype_digit($digits) && ltrim($digits, '0') !== '1') {
                        $kind = 0;
                    }
                }
            }
            $codecName = trim((string) ($codecs[$at] ?? ''), " \t");
            if ($codecName === '') {
                $codec = self::w60CodecHint($lower);
                $fromName = 1;
            } else {
                $codec = self::W60_CODECS[strtolower($codecName)] ?? 6;
                $fromName = 0;
            }
            $size = min(max((int) ($sizes[$at] ?? 0), 0), self::W60_VALUE_CAP);
            $sample = str_contains($lower, 'sample') ? 1 : 0;
            $unsafe = $base === '' || str_contains($path, "\0") || str_contains('/'.$normalized.'/', '/../') ? 1 : 0;
            $reasons = (int) ($kind === 0) | (int) ($size < $minBytes) << 1 | (int) ($size > $maxBytes) << 2 | (int) ($codec === 6) << 3
                | $sample << 4 | $unsafe << 5;
            $eligible = $kind === 2 && ($reasons & ~16) === 0 ? 1 : 0;
            $priority = $eligible === 1 ? ($sample === 1 ? 2 : 3) : ($kind === 1 && $unsafe === 0 ? 1 : 0);
            $out[1 + $kind]++;
            $out[5 + $codec]++;
            $out[12 + $container]++;
            $out[21] += $eligible;
            $out[22] += $sample;
            $out[23] += $reasons >> 1 & 1;
            $out[24] += $reasons >> 2 & 1;
            $out[25] += $reasons >> 3 & 1;
            $out[26] += $unsafe;
            if ($priority > 0 && ($out[27] < 0 || $priority > $out[28] || ($priority === $out[28] && $size > $out[29]))) {
                $out[27] = $at;
                $out[28] = $priority;
                $out[29] = $size;
            }
            if ($eligible === 1) {
                $out[30] = min($out[30] + $size, self::W60_TOTAL_CAP);
            }
            if ($kind === 1 && $unsafe === 0 && $out[31] < 0) {
                $out[31] = $at;
            }
            $row = $kind | $container << 2 | $codec << 6 | $reasons << 9 | $eligible << 15 | $priority << 16 | $fromName << 18
                | min($size >> 20, 1_048_575) << 19 | min(\strlen($path), 65_535) << 39;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[32] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust resolution, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, string>  $codecs
     * @return list<int>|null
     */
    public static function resolveMediaSourceBatchNative(array $paths, array $sizes, array $codecs, int $minBytes = 1_048_576, int $maxBytes = PHP_INT_MAX): ?array
    {
        $count = \count($paths);
        if ($count > self::W60_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w60Strings([...array_values($paths), ...self::w60Column($codecs, $count, '')]);
        $ints = pack('q*', ...self::w60Column($sizes, $count));
        $fields = self::W60_SOURCE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w60Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w606_media_source_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $minBytes, $maxBytes, $out, $fields));
    }

    /** 2 HEVC, 1 AVC, 3 AV1, 4 MPEG-4 ASP, 0 unknown. */
    private static function w60CodecHint(string $lower): int
    {
        if (str_contains($lower, 'x265') || str_contains($lower, 'h265') || str_contains($lower, 'hevc')) {
            return 2;
        }
        if (str_contains($lower, 'x264') || str_contains($lower, 'h264') || str_contains($lower, 'avc')) {
            return 1;
        }
        if (str_contains($lower, 'av1')) {
            return 3;
        }

        return str_contains($lower, 'xvid') || str_contains($lower, 'divx') ? 4 : 0;
    }

    /**
     * Run `$callback` with the Wave 60.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $forced = self::w60RustFfiState();

        return \is_bool($forced) ? $forced : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 60.6 routing and binding in function statics (enums and readonly classes cannot declare properties).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val` and returns
     * the previous value; `'binding'` returns the cached binding and an output buffer of at least `$val`
     * values, or null.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w606', self::W60_SOURCE_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 60.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w60Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w60RustFfiState('binding', $fields);
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
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w60Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w60Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
