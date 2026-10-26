<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudioData;
use App\Models\ReleaseSubtitle;
use App\Models\VideoData;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Throwable;

/**
 * Service for managing release extra data (video, audio, and subtitles).
 */
class ReleaseExtraService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_codec_make_pretty(const char *ptr, size_t len);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    /**
     * Get video data for a release.
     *
     * @param  int  $id  Release ID
     * @return Model|null
     */
    public function get(int $id)
    {
        // hopefully nothing will use this soon and it can be deleted
        return VideoData::query()->where('releases_id', $id)->first();
    }

    /**
     * Get video data as array.
     *
     * @param  int  $id  Release ID
     * @return array<string, mixed>
     */
    public function getVideo(int $id): false|array
    {
        $result = VideoData::query()->where('releases_id', $id)->first();

        if ($result !== null) {
            return $result->toArray();
        }

        return false;
    }

    /**
     * Get audio data for a release.
     *
     * @param  int  $id  Release ID
     * @return array<string, mixed>
     */
    public function getAudio(int $id): false|array
    {
        $result = AudioData::query()->where('releases_id', $id)->orderBy('audioid')->get();

        if ($result !== null) {
            return $result->toArray();
        }

        return false;
    }

    /**
     * Get subtitles for a release.
     *
     * @param  int  $id  Release ID
     * @return Model|null
     */
    public function getSubs(int $id)
    {
        return ReleaseSubtitle::query()
            ->where('releases_id', $id)
            ->select([DB::raw("GROUP_CONCAT(subslanguage SEPARATOR ', ') AS subs")])
            ->orderBy('subsid')
            ->first();
    }

    /**
     * Get video data by release GUID.
     *
     * @param  string  $guid  Release GUID
     * @return Builder|Model|\Illuminate\Database\Query\Builder|null
     */
    public function getByGuid(string $guid) // @phpstan-ignore missingType.generics
    {
        return VideoData::query()
            ->where('r.guid', $guid)
            ->leftJoin('releases as r', 'r.id', '=', 'video_data.releases_id')
            ->first();
    }

    /**
     * Delete all extra data for a release.
     *
     * @param  int  $id  Release ID
     */
    public function delete(int $id): void
    {
        AudioData::query()->where('releases_id', $id)->delete();
        ReleaseSubtitle::query()->where('releases_id', $id)->delete();
        VideoData::query()->where('releases_id', $id)->delete();
    }

    /**
     * Add video, audio, and subtitle data from MediaInfo XML.
     *
     * @param  int  $releaseID  Release ID
     * @param  MediaInfoContainer  $arrXml  Parsed MediaInfo container
     */
    public function addFromXml(int $releaseID, MediaInfoContainer $arrXml): void
    {
        $containerFormat = '';
        $overallBitRate = '';
        $general = $arrXml->getGeneral();
        $audios = $arrXml->getAudios();
        $videos = $arrXml->getVideos();
        $subtitles = $arrXml->getSubtitles();

        if ($general !== null) {
            if ($general->get('format') !== null) {
                $containerFormat = $general->get('format')->getFullName();
            }

            $videoDuration = $videoFormat = $videoCodec = $videoWidth = $videoHeight = $videoAspect = $videoFrameRate = $videoLibrary = $videoBitRate = '';

            if (! empty($videos)) {
                foreach ($videos as $video) {
                    if ($video->get('duration') !== null) {
                        $videoDuration = $video->get('duration')->getMilliseconds();
                    }

                    if ($video->get('bit_rate') !== null) {
                        $overallBitRate = $video->get('bit_rate');
                    }

                    if ($video->get('format') !== null) {
                        $videoFormat = $video->get('format')->getFullName();
                    }

                    if ($video->get('codec_id') !== null) {
                        $videoCodec = $video->get('codec_id');
                    }

                    if ($video->get('width') !== null) {
                        $videoWidth = $video->get('width')->getAbsoluteValue();
                    }

                    if ($video->get('height') !== null) {
                        $videoHeight = $video->get('height')->getAbsoluteValue();
                    }

                    if ($video->get('display_aspect_ratio') !== null) {
                        $videoAspect = $video->get('display_aspect_ratio')->getTextValue();
                    }

                    if ($video->get('frame_rate') !== null) {
                        $videoFrameRate = $video->get('frame_rate')->getTextValue();
                    }

                    if ($video->get('encoded_library') !== null) {
                        $videoLibrary = $video->get('encoded_library');
                    }

                    if ($video->get('encoded_library_version') !== null) {
                        $videoLibrary .= $video->get('encoded_library_version');
                    }

                    if ($video->get('writing_library') !== null) {
                        $videoLibrary = $video->get('writing_library')->getFullName();
                    }

                    if ($video->get('nominal_bit_rate') !== null) {
                        $videoBitRate = $video->get('nominal_bit_rate')->getTextValue();
                    }

                    if (! empty($videoBitRate)) {
                        $overallBitRate = $videoBitRate;
                    }

                    $this->addVideo($releaseID, $containerFormat, $overallBitRate, $videoDuration, $videoFormat, $videoCodec, $videoWidth, $videoHeight, $videoAspect, $videoFrameRate, $videoLibrary);
                }
            }

            $audioID = 1;
            $audioFormat = $audioMode = $audioBitRateMode = $audioBitRate = $audioChannels = $audioSampleRate = $audioLibrary = $audioLanguage = $audioTitle = '';

            if (! empty($audios)) {
                foreach ($audios as $audio) {
                    if ($audio->get('id') !== null) {
                        $audioID = $audio->get('id')->getFullName();
                    }

                    if ($audio->get('format') !== null) {
                        $audioFormat = $audio->get('format')->getFullName();
                    }

                    if ($audio->get('format_settings_sbr') !== null) {
                        $audioMode = $audio->get('format_settings_sbr')->getFullName();
                    }

                    if ($audio->get('bit_rate_mode') !== null) {
                        $audioBitRateMode = $audio->get('bit_rate_mode')->getFullName();
                    }

                    if ($audio->get('bit_rate') !== null) {
                        $audioBitRate = $audio->get('bit_rate')->getTextValue();
                    }

                    if ($audio->get('channel_s') !== null) {
                        $audioChannels = $audio->get('channel_s')->getAbsoluteValue();
                    }

                    if ($audio->get('sampling_rate') !== null) {
                        $audioSampleRate = $audio->get('sampling_rate')->getTextValue();
                    }

                    if ($audio->get('encoded_library') !== null) {
                        $audioLibrary = $audio->get('encoded_library');
                    }

                    if ($audio->get('language') !== null) {
                        $audioLanguage = $audio->get('language');
                    }

                    if ($audio->get('title') !== null) {
                        $audioTitle = $audio->get('title');
                    }

                    $this->addAudio($releaseID, $audioID, $audioFormat, $audioMode, $audioBitRateMode, $audioBitRate, $audioChannels, $audioSampleRate, $audioLibrary, $audioLanguage, $audioTitle);
                }
            }

            if (! empty($subtitles)) {
                foreach ($subtitles as $subtitle) {
                    $subsID = 1;
                    $subsLanguage = 'Unknown';

                    if ($subtitle->get('id') !== null) {
                        $subsID = $subtitle->get('id')->getFullName();
                    }

                    if ($subtitle->get('language') !== null) {
                        $subsLanguage = $subtitle->get('language');
                    }

                    $this->addSubs($releaseID, $subsID, $subsLanguage);
                }
            }
        }

        DB::afterCommit(static fn () => ReleaseSearchIndexSync::forIds([$releaseID]));
    }

    /**
     * Add video data for a release.
     */
    public function addVideo(
        int $releaseID,
        string $containerFormat,
        mixed $overallBitRate,
        mixed $videoDuration,
        string $videoFormat,
        mixed $videoCodec,
        mixed $videoWidth,
        mixed $videoHeight,
        string $videoAspect,
        string $videoFrameRate,
        mixed $videoLibrary
    ): void {
        $ckid = VideoData::query()->where('releases_id', $releaseID)->first(['releases_id']);
        if ($ckid === null) {
            VideoData::insertOrIgnore([
                'releases_id' => $releaseID,
                'containerformat' => $containerFormat,
                'overallbitrate' => $overallBitRate,
                'videoduration' => is_numeric($videoDuration) ? realDuration($videoDuration) : $videoDuration,
                'videoformat' => $videoFormat,
                'videocodec' => $videoCodec,
                'videowidth' => $videoWidth,
                'videoheight' => $videoHeight,
                'videoaspect' => $videoAspect,
                'videoframerate' => $videoFrameRate,
                'videolibrary' => $videoLibrary,
            ]);
        }
    }

    /**
     * Add audio data for a release.
     */
    private function addAudio(
        int $releaseID,
        mixed $audioID,
        mixed $audioFormat,
        mixed $audioMode,
        mixed $audioBitRateMode,
        mixed $audioBitRate,
        mixed $audioChannels,
        mixed $audioSampleRate,
        mixed $audioLibrary,
        mixed $audioLanguage,
        mixed $audioTitle
    ): void {
        $ckid = AudioData::query()->where('releases_id', $releaseID)->where('audioid', '=', $audioID)->first(['releases_id']);
        if ($ckid === null) {
            AudioData::insertOrIgnore([
                'releases_id' => $releaseID,
                'audioid' => $audioID,
                'audioformat' => \is_array($audioFormat) ? implode($audioFormat) : $audioFormat,
                'audiomode' => \is_array($audioMode) ? implode($audioMode) : $audioMode,
                'audiobitratemode' => \is_array($audioBitRateMode) ? implode($audioBitRateMode) : $audioBitRateMode,
                'audiobitrate' => \is_array($audioBitRate) ? implode($audioBitRate) : $audioBitRate,
                'audiochannels' => \is_array($audioChannels) ? implode($audioChannels) : $audioChannels,
                'audiosamplerate' => \is_array($audioSampleRate) ? implode($audioSampleRate) : $audioSampleRate,
                'audiolibrary' => \is_array($audioLibrary) ? implode($audioLibrary) : $audioLibrary,
                'audiolanguage' => ! empty($audioLanguage) ? $audioLanguage[1] : '',
                'audiotitle' => \is_array($audioTitle) ? implode($audioTitle) : $audioTitle,
            ]);
        }
    }

    /**
     * Add subtitle data for a release.
     */
    private function addSubs(int $releaseID, mixed $subsID, mixed $subsLanguage): void
    {
        $ckid = ReleaseSubtitle::query()->where('releases_id', $releaseID)->where('subsid', '=', $subsID)->first(['releases_id']);
        $subs = '';
        if (! empty($subsLanguage)) {
            if (! empty($subsLanguage[1])) {
                $subs = $subsLanguage[1];
            } elseif (! empty($subsLanguage[0])) {
                $subs = $subsLanguage[0];
            }
        }
        if ($ckid === null) {
            ReleaseSubtitle::insertOrIgnore([
                'releases_id' => $releaseID,
                'subsid' => $subsID,
                'subslanguage' => $subs,
            ]);
        }
    }

    /**
     * Normalize raw video codec identifiers into human-readable labels.
     *
     * Retains the native PHP path in production (~1.15x mean / ~1.18x p99 in paired
     * benchmarks, below the 1.5x adoption threshold); see makeCodecPrettyNative().
     */
    public function makeCodecPretty(string $codec): string
    {
        return $this->makeCodecPrettyPhp($codec);
    }

    public function makeCodecPrettyPhp(string $codec): string
    {
        return self::CODEC_LABELS[self::codecLabelIndex($codec)] ?? $codec;
    }

    /** Labels in makeCodecPrettyPhp() match order; index 8 keeps the raw codec. */
    private const array CODEC_LABELS = ['HEVC', 'h.264', 'MPEG-4', 'MPEG-2', 'MPEG-1', 'DivX', 'XviD', 'wmv'];

    private static function codecLabelIndex(string $codec): int
    {
        return match (true) {
            preg_match('#(?:^36$|HEVC)#i', $codec) === 1 => 0,
            preg_match('#(?:^(?:7|27|H264)$|AVC)#i', $codec) === 1 => 1,
            preg_match('#(?:^(?:20|FMP4|MP42|MP43|MPG4)$|ASP)#i', $codec) === 1 => 2,
            preg_match('#^2$#i', $codec) === 1 => 3,
            $codec === 'MPEG' => 4,
            preg_match('#DX50|DIVX|DIV3#i', $codec) === 1 => 5,
            stripos($codec, 'XVID') !== false => 6,
            preg_match('#(?:wmv|WVC1)#i', $codec) === 1 => 7,
            default => 8,
        };
    }

    public function makeCodecPrettyNative(string $codec, bool &$supported = false, ?string $libraryPath = null): string
    {
        $supported = false;
        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_extra_service', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return $this->makeCodecPrettyPhp($codec);
        }

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_codec_make_pretty($codec, strlen($codec));
        } catch (Throwable) {
            return $this->makeCodecPrettyPhp($codec);
        }

        if (! is_int($code) || $code < 0) {
            return $this->makeCodecPrettyPhp($codec);
        }

        $supported = true;

        return match ($code) {
            1 => 'HEVC',
            2 => 'h.264',
            3 => 'MPEG-4',
            4 => 'MPEG-2',
            5 => 'MPEG-1',
            6 => 'DivX',
            7 => 'XviD',
            8 => 'wmv',
            default => $codec,
        };
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('release_extra_service', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all eight runs (min 1.78x; see the W43 parity test). */
    public const bool W431_RUST_FFI_DEFAULT = true;

    public const string W431_FFI_CDEF = <<<'C'
        ssize_t nntmux_w431_release_extra_codec_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W431_SUMMARY_KEYS = ['total_codecs', 'empty_codec_count', 'hevc_count', 'h264_count', 'mpeg4_count', 'mpeg2_count', 'mpeg1_count', 'divx_count', 'xvid_count', 'wmv_count', 'other_count'];

    private static ?bool $w431UseRustFfi = null;

    private static FFI|false|null $w431Ffi = null;

    public static function useW431RustFfi(bool $enable = true): void
    {
        self::$w431UseRustFfi = $enable;
    }

    public static function usesW431RustFfi(): bool
    {
        return self::$w431UseRustFfi ?? self::W431_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW431RustFfi(): void
    {
        self::$w431UseRustFfi = null;
        self::$w431Ffi = null;
    }

    /**
     * Counts untrimmed codec identifiers by the makeCodecPrettyPhp() label they map to; identifiers that keep
     * their raw value count as other.
     * Entries come from strings, integers, or the first non-null codec, format array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCodecBatch(array $rows): array
    {
        return self::usesW431RustFfi() ? self::summarizeCodecBatchNative($rows) : self::summarizeCodecBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCodecBatchNative(array $rows): array
    {
        $values = CollectionsCleaningService::w431Invoke(self::$w431Ffi, self::W431_FFI_CDEF, 'nntmux_w431_release_extra_codec_batch', $rows, ['codec', 'format'], \count(self::W431_SUMMARY_KEYS));

        return $values === null ? self::summarizeCodecBatchPhp($rows) : array_combine(self::W431_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCodecBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W431_SUMMARY_KEYS, 0);
        foreach (CollectionsCleaningService::w431Entries($rows, ['codec', 'format']) as $entry) {
            $summary['total_codecs']++;
            if ($entry === '') {
                $summary['empty_codec_count']++;

                continue;
            }
            $summary[self::W431_SUMMARY_KEYS[self::codecLabelIndex($entry) + 2]]++;
        }

        return $summary;
    }

    // ========================================================================
    // WAVE 62.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.85x, p99 6.01x; uncommon mean 6.01x, p99 4.78x); see RustObfuscationRouterReleaseCreationExtraProcessingXrefW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_RELEASE_EXTRA_FFI_CDEF = <<<'C'
        ssize_t nntmux_w621_release_extra_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w62ReleaseExtraPlanBatch(). */
    public const int W62_RELEASE_EXTRA_TRAILER = 36;

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ReleaseExtraService Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.1 release extra (MediaInfo) plans. Each item is `kind|format|codec|bitrate|duration|channels|language`,
     * every field trimmed. Kind is 1 video, 2 audio, 3 subs/subtitle/text, else 0 (case-insensitive); the format
     * is its W62_EXTRA_FORMATS class (0 empty, 12 other); the codec is codecLabelIndex() (makeCodecPretty()'s
     * label) or 9 when empty. The bit rate (spaces removed) is 1-9 digits, an optional `.` fraction (first three
     * digits kept) and a b/s, kb/s or Mb/s unit (`''`, `b/s`, `bps`; `k`, `kb/s`, `kbps`, `kbit/s`; `m`, `mb/s`,
     * `mbps`, `mbit/s`), normalized to integer kbps; the duration is 1-12 digits of milliseconds or `<1-9 digits>
     * <unit>` tokens (h, min/mn/m, s/sec, ms) summed and capped at 2^50 ms, normalized to seconds; channels are
     * the first 1-3 leading digits (max 15); the language class is 0 empty, 1 two letters, 2 three letters, 3
     * other letters only, 4 anything else. Flags: 1 unknown kind, 2 malformed bit rate, 4 malformed duration,
     * 8 empty codec on video/audio, 16 empty language on audio/subs, 32 non-ASCII item. Per item `kind | codec << 2 |
     * format << 6 | flags << 10 | min(kbps, 2^20 - 1) << 16 | min(seconds, 2^20 - 1) << 36 | channels << 56 |
     * language << 60`; then 4 kind, 10 codec, 13 format and 6 flag counts, the seconds sum, the largest kbps and
     * the distinct case-insensitive languages.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseExtraPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62ReleaseExtraPlanBatchNative($items) : self::w62ReleaseExtraPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseExtraPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_RELEASE_EXTRA_TRAILER) ?? self::w62ReleaseExtraPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseExtraPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W62_RELEASE_EXTRA_TRAILER, 0);
        $languages = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$kind, $format, $codec, $bitrate, $duration, $channels, $language] = explode('|', $item, 7) + ['', '', '', '', '', '', ''];
            $kind = match (strtolower(trim($kind))) {
                'video' => 1,
                'audio' => 2,
                'subs', 'subtitle', 'text' => 3,
                default => 0,
            };
            $format = self::W62_EXTRA_FORMATS[strtolower(trim($format))] ?? 12;
            $codec = trim($codec);
            $codec = $codec === '' ? 9 : self::codecLabelIndex($codec);
            $flags = 0;
            $bitrate = str_replace(' ', '', trim($bitrate));
            $kbps = $bitrate === '' ? 0 : self::w62Kbps($bitrate);
            if ($kbps === null) {
                $flags |= 2;
                $kbps = 0;
            }
            $duration = trim($duration);
            $ms = $duration === '' ? 0 : self::w62Milliseconds($duration);
            if ($ms === null) {
                $flags |= 4;
                $ms = 0;
            }
            $seconds = intdiv($ms, 1000);
            $channels = trim($channels);
            $digits = strspn($channels, '0123456789');
            $channels = $digits === 0 ? 0 : min(15, (int) substr($channels, 0, min($digits, 3)));
            $language = strtolower(trim($language));
            $length = \strlen($language);
            $lang = $length === 0 ? 0 : (strspn($language, 'abcdefghijklmnopqrstuvwxyz') === $length ? match ($length) {
                2 => 1,
                3 => 2,
                default => 3,
            } : 4);
            if ($length !== 0) {
                $languages[$language] = true;
            }
            $flags |= ($kind === 0 ? 1 : 0) | ($codec === 9 && ($kind === 1 || $kind === 2) ? 8 : 0) | ($lang === 0 && ($kind === 2 || $kind === 3) ? 16 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 32 : 0);
            $tail[$kind]++;
            $tail[4 + $codec]++;
            $tail[14 + $format]++;
            for ($b = 0; $b < 6; $b++) {
                $tail[27 + $b] += $flags >> $b & 1;
            }
            $tail[33] += $seconds;
            $tail[34] = max($tail[34], $kbps);
            $out[] = $kind | $codec << 2 | $format << 6 | $flags << 10 | min($kbps, (1 << 20) - 1) << 16 | min($seconds, (1 << 20) - 1) << 36
                | $channels << 56 | $lang << 60;
        }
        $tail[35] = \count($languages);
        array_push($out, ...$tail);

        return $out;
    }

    /**
     * Container/stream format classes: 1 Matroska, 2 MPEG-4, 3 AVI, 4 AAC, 5 AC-3, 6 DTS, 7 FLAC, 8 MPEG Audio,
     * 9 SubRip, 10 ASS/SSA and 11 PGS (empty is 0, any other 12).
     *
     * @var array<string, int>
     */
    public const array W62_EXTRA_FORMATS = ['' => 0, 'matroska' => 1, 'mpeg-4' => 2, 'mp4' => 2, 'avi' => 3, 'aac' => 4, 'ac-3' => 5, 'ac3' => 5,
        'e-ac-3' => 5, 'eac3' => 5, 'dts' => 6, 'flac' => 7, 'mpeg audio' => 8, 'mp3' => 8, 'subrip' => 9, 'srt' => 9, 'utf-8' => 9, 'ass' => 10,
        'ssa' => 10, 'pgs' => 11];

    /** A space-free bit rate as integer kbps, or null when malformed (see w62ReleaseExtraPlanBatch()). */
    private static function w62Kbps(string $rate): ?int
    {
        $digits = strspn($rate, '0123456789');
        if ($digits === 0 || $digits > 9) {
            return null;
        }
        $milli = (int) substr($rate, 0, $digits) * 1000;
        $at = $digits;
        if (($rate[$at] ?? '') === '.') {
            $fraction = strspn($rate, '0123456789', $at + 1);
            if ($fraction === 0) {
                return null;
            }
            $milli += (int) str_pad(substr($rate, $at + 1, min($fraction, 3)), 3, '0');
            $at += 1 + $fraction;
        }

        return match (strtolower(substr($rate, $at))) {
            '', 'b/s', 'bps' => intdiv($milli, 1_000_000),
            'k', 'kb/s', 'kbps', 'kbit/s' => intdiv($milli, 1000),
            'm', 'mb/s', 'mbps', 'mbit/s' => $milli,
            default => null,
        };
    }

    /** A non-empty trimmed duration as milliseconds, or null when malformed (see w62ReleaseExtraPlanBatch()). */
    private static function w62Milliseconds(string $duration): ?int
    {
        $length = \strlen($duration);
        if (strspn($duration, '0123456789') === $length) {
            return $length <= 12 ? (int) $duration : null;
        }
        $ms = 0;
        $at = 0;
        while (true) {
            $at += strspn($duration, ' ', $at);
            if ($at >= $length) {
                return $ms;
            }
            $digits = strspn($duration, '0123456789', $at);
            if ($digits === 0 || $digits > 9) {
                return null;
            }
            $value = (int) substr($duration, $at, $digits);
            $at += $digits;
            $at += strspn($duration, ' ', $at);
            $letters = strspn($duration, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $at);
            $scale = match (strtolower(substr($duration, $at, $letters))) {
                'h' => 3_600_000,
                'min', 'mn', 'm' => 60_000,
                's', 'sec' => 1000,
                'ms' => 1,
                default => 0,
            };
            if ($scale === 0) {
                return null;
            }
            $at += $letters;
            $ms = min(1 << 50, $ms + $value * $scale);
        }
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('release_extra_w621', self::W62_RELEASE_EXTRA_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.1 ReleaseExtraService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_RELEASE_EXTRA_FFI_CDEF.)
            $written = $ffi->nntmux_w621_release_extra_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
