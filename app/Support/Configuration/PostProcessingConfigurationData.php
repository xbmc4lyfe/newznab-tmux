<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Models\PostProcessingConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class PostProcessingConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_ppcd_defaults(int64_t *out, size_t out_count, char *blacklist, size_t cap);
        int32_t nntmux_ppcd_normalize(const int64_t *raw, size_t count, int64_t *out, size_t out_count);
        C;

    /** Property names in declaration order (the `toArray()` keys). */
    public const array FIELDS = [
        'postThreads', 'postThreadsAmazon', 'postThreadsNon', 'nfoThreads', 'fixNameThreads', 'timeoutSeconds',
        'releaseTimeoutSeconds', 'maxTimeoutCount', 'maxAdditionalProcessed', 'maxPartsProcessed',
        'passwordCheckAttempts', 'fixNamesPerRun', 'maxNestedLevels', 'extractUsingRarInfo', 'segmentsToDownload',
        'ffmpegDuration', 'innerFileBlacklist', 'processJpg', 'processThumbnails', 'processVideos',
        'saveAudioPreview', 'minSizeToPostProcess', 'maxSizeToPostProcess', 'minSizeToProcessNfo',
        'maxSizeToProcessNfo', 'maxNfoProcessed', 'maxNfoRetries', 'lookupNfo', 'lookupPar2',
    ];

    /** Position of the only string field; the other 28 cross the C ABI as `int64_t`. */
    private const int BLACKLIST_FIELD = 16;

    private const int NUMERIC_COUNT = 28;

    /** Boolean field positions within {@see FIELDS}. */
    private const array BOOL_FIELDS = [13 => true, 17 => true, 18 => true, 19 => true, 20 => true, 27 => true, 28 => true];

    public function __construct(
        public int $postThreads,
        public int $postThreadsAmazon,
        public int $postThreadsNon,
        public int $nfoThreads,
        public int $fixNameThreads,
        public int $timeoutSeconds,
        public int $releaseTimeoutSeconds,
        public int $maxTimeoutCount,
        public int $maxAdditionalProcessed,
        public int $maxPartsProcessed,
        public int $passwordCheckAttempts,
        public int $fixNamesPerRun,
        public int $maxNestedLevels,
        public bool $extractUsingRarInfo,
        public int $segmentsToDownload,
        public int $ffmpegDuration,
        public string $innerFileBlacklist,
        public bool $processJpg,
        public bool $processThumbnails,
        public bool $processVideos,
        public bool $saveAudioPreview,
        public int $minSizeToPostProcess,
        public int $maxSizeToPostProcess,
        public int $minSizeToProcessNfo,
        public int $maxSizeToProcessNfo,
        public int $maxNfoProcessed,
        public int $maxNfoRetries,
        public bool $lookupNfo,
        public bool $lookupPar2,
    ) {}

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public static function defaults(): self
    {
        return self::usesRustFfi() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self(1, 1, 1, 1, 1, 60, 120, 3, 25, 3, 1, 10, 3, false, 2, 5, '/setup.exe|password.url/i', false, false, false, false, 1048576, 107374182400, 1048576, 107374182400, 100, 5, true, false);
    }

    public static function fromModel(PostProcessingConfiguration $model): self
    {
        return new self(
            (int) $model->post_threads,
            (int) $model->post_threads_amazon,
            (int) $model->post_threads_non,
            (int) $model->nfo_threads,
            (int) $model->fix_name_threads,
            (int) $model->timeout_seconds,
            (int) $model->release_timeout_seconds,
            (int) $model->max_timeout_count,
            (int) $model->max_additional_processed,
            (int) $model->max_parts_processed,
            (int) $model->password_check_attempts,
            (int) $model->fix_names_per_run,
            (int) $model->max_nested_levels,
            (bool) $model->extract_using_rar_info,
            (int) $model->segments_to_download,
            (int) $model->ffmpeg_duration,
            (string) $model->inner_file_blacklist,
            (bool) $model->process_jpg,
            (bool) $model->process_thumbnails,
            (bool) $model->process_videos,
            (bool) $model->save_audio_preview,
            (int) $model->min_size_to_post_process,
            (int) $model->max_size_to_post_process,
            (int) $model->min_size_to_process_nfo,
            (int) $model->max_size_to_process_nfo,
            (int) $model->max_nfo_processed,
            (int) $model->max_nfo_retries,
            (bool) $model->lookup_nfo,
            (bool) $model->lookup_par2,
        );
    }

    public static function defaultsNative(): self
    {
        $ffi = self::ffi();
        $values = $ffi === null ? null : self::valuesBuffer($ffi);
        if ($ffi === null || $values === null) {
            return self::defaultsPhp();
        }
        $capacity = 0;
        $blacklist = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($blacklist === null) {
            return self::defaultsPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_ppcd_defaults($values, self::NUMERIC_COUNT, $blacklist, $capacity);
            if (\is_int($written) && $written < -1) {
                $blacklist = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($blacklist === null) {
                    return self::defaultsPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_ppcd_defaults($values, self::NUMERIC_COUNT, $blacklist, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::defaultsPhp();
            }
            $numeric = unpack('q'.self::NUMERIC_COUNT, FFI::string($values, self::NUMERIC_COUNT * 8));
            $blacklistString = FFI::string($blacklist, $written);
        } catch (Throwable) {
            return self::defaultsPhp();
        }
        if (! \is_array($numeric) || \count($numeric) !== self::NUMERIC_COUNT) {
            return self::defaultsPhp();
        }

        return new self(...self::decodeFields(array_values($numeric), $blacklistString));
    }

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return self::usesRustFfi() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /** @return array<string, int|bool|string> */
    public function toArrayPhp(): array
    {
        return get_object_vars($this);
    }

    /** @return array<string, int|bool|string> */
    public function toArrayNative(): array
    {
        $ffi = self::ffi();
        $values = $ffi === null ? null : self::valuesBuffer($ffi);
        if ($ffi === null || $values === null) {
            return $this->toArrayPhp();
        }

        $raw = [
            $this->postThreads, $this->postThreadsAmazon, $this->postThreadsNon, $this->nfoThreads,
            $this->fixNameThreads, $this->timeoutSeconds, $this->releaseTimeoutSeconds, $this->maxTimeoutCount,
            $this->maxAdditionalProcessed, $this->maxPartsProcessed, $this->passwordCheckAttempts,
            $this->fixNamesPerRun, $this->maxNestedLevels, (int) $this->extractUsingRarInfo,
            $this->segmentsToDownload, $this->ffmpegDuration, (int) $this->processJpg,
            (int) $this->processThumbnails, (int) $this->processVideos, (int) $this->saveAudioPreview,
            $this->minSizeToPostProcess, $this->maxSizeToPostProcess, $this->minSizeToProcessNfo,
            $this->maxSizeToProcessNfo, $this->maxNfoProcessed, $this->maxNfoRetries, (int) $this->lookupNfo,
            (int) $this->lookupPar2,
        ];

        try {
            FFI::memcpy($values, pack('q*', ...$raw), self::NUMERIC_COUNT * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_ppcd_normalize($values, self::NUMERIC_COUNT, $values, self::NUMERIC_COUNT);
            if ($written !== self::NUMERIC_COUNT) {
                return $this->toArrayPhp();
            }
            $numeric = unpack('q'.self::NUMERIC_COUNT, FFI::string($values, self::NUMERIC_COUNT * 8));
        } catch (Throwable) {
            return $this->toArrayPhp();
        }
        if (! \is_array($numeric) || \count($numeric) !== self::NUMERIC_COUNT) {
            return $this->toArrayPhp();
        }

        return array_combine(self::FIELDS, self::decodeFields(array_values($numeric), $this->innerFileBlacklist));
    }

    /**
     * Rebuild the 29 constructor arguments from the 28 numeric C ABI values and the blacklist.
     *
     * @param  list<mixed>  $numeric
     * @return list<int|bool|string>
     */
    private static function decodeFields(array $numeric, string $blacklist): array
    {
        $fields = [];
        $next = 0;
        for ($position = 0, $total = self::NUMERIC_COUNT + 1; $position < $total; $position++) {
            if ($position === self::BLACKLIST_FIELD) {
                $fields[] = $blacklist;

                continue;
            }
            $value = (int) $numeric[$next++];
            $fields[] = isset(self::BOOL_FIELDS[$position]) ? $value !== 0 : $value;
        }

        return $fields;
    }

    /** Reusable `int64_t[28]` value buffer for `$ffi`. */
    private static function valuesBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('int64_t['.self::NUMERIC_COUNT.']');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
