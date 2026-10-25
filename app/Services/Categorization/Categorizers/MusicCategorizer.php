<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Categorizer for Music content (MP3, Lossless, Video, Audiobook, Podcast).
 */
class MusicCategorizer extends AbstractCategorizer
{
    /**
     * Wave 43.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php measures at least 3.3x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W433 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W433_RUST_FFI_DEFAULT = true;

    public const string W433_FFI_CDEF = <<<'C'
        ssize_t nntmux_w433_music_content_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Music content marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W433_MUSIC_CONTENT_MARKERS = [
        'audiobook' => ['audiobook', 'audible', '.m4b', 'hoerbuch'],
        'podcast' => ['podcast', 'radio.show'],
        'video' => ['mvid', 'musicvideo', 'music.video', 'concert'],
        'lossless' => ['flac', 'alac', 'lossless', '24bit', 'hi-res'],
        'mp3' => ['mp3', '320kbps', '256kbps', 'vbr'],
    ];

    /** @var list<string> */
    public const array W433_VINYL_MARKERS = ['vinyl'];

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W433_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w433RustFfiOverride = null;

    private static FFI|false|null $w433RustFfiHandle = null;

    protected int $priority = 40;

    // Language patterns for foreign music
    protected const FOREIGN_LANGUAGES = 'arabic|brazilian|bulgarian|cantonese|chinese|croatian|czech|danish|deutsch|dutch|estonian|finnish|flemish|french|german|greek|hebrew|hungarian|icelandic|indian|iranian|italian|japanese|korean|latin|latvian|lithuanian|macedonian|mandarin|nordic|norwegian|persian|polish|portuguese|romanian|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish|ukrainian|vietnamese';

    protected const LANGUAGE_CODES = 'ar|bg|bl|cs|cz|da|de|dk|el|es|et|fi|fr|ger|gr|heb|hr|hu|hun|is|it|ita|jp|jap|ko|kor|lt|lv|mk|nl|no|pl|pt|ro|rs|ru|se|sk|sl|sr|sv|th|tr|ua|vi|zh';

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_music(const char *name, size_t name_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'audiobook',
        1 => 'audiobook_pattern',
        2 => 'audiobook_legacy',
        3 => 'podcast',
        4 => 'podcast_network',
        5 => 'podcast_simple',
        6 => 'music_video_foreign',
        7 => 'music_video',
        8 => 'music_video_artist',
        9 => 'lossless_foreign',
        10 => 'lossless',
        11 => 'flac_foreign',
        12 => 'flac',
        13 => 'lossless_format_foreign',
        14 => 'lossless_format',
        15 => 'mp3_foreign',
        16 => 'mp3',
        17 => 'mp3_scene_foreign',
        18 => 'mp3_scene',
        19 => 'mp3_bitrate_foreign',
        20 => 'mp3_bitrate',
        21 => 'music_other_foreign',
        22 => 'music_other',
        23 => 'music_album_foreign',
        24 => 'music_album',
        25 => 'music_dj_foreign',
        26 => 'music_dj',
    ];

    /** Returned by the library instead of a result when `shouldSkip` is true. */
    private const int NATIVE_SKIP = 1 << 62;

    /** Resolved library handle: null until first use, false when the library is unavailable. */
    private static FFI|false|null $nativeFfi = null;

    private static ?CategorizationResult $nativeNoMatch = null;

    /** Native result computed by `shouldSkip()` for the context that `categorize()` receives next. */
    private ?ReleaseContext $nativeContext = null;

    private int $nativePacked = 0;

    private static function resolveNative(): FFI|false
    {
        return self::$nativeFfi = RustPipelineLibrary::resolveDomain('categorizers', self::NATIVE_CDEF) ?? false;
    }

    /**
     * Run the native categorizer without the skip check; null when the library is unavailable or declines.
     */
    private function nativeCall(ReleaseContext $context): ?int
    {
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_categorize_music($context->releaseName, \strlen($context->releaseName), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'Music';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_music($context->releaseName, \strlen($context->releaseName), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
            } catch (Throwable) {
                $packed = -1;
            }
            if (\is_int($packed) && $packed >= 0) {
                if ($packed === self::NATIVE_SKIP) {
                    $this->nativeContext = null;

                    return true;
                }
                $this->nativeContext = $context;
                $this->nativePacked = $packed;

                return false;
            }
        }
        $this->nativeContext = null;

        return $this->shouldSkipPhp($context);
    }

    /**
     * Pure-PHP skip check (fallback when the native library is unavailable).
     */
    public function shouldSkipPhp(ReleaseContext $context): bool
    {
        if ($context->hasAdultMarkers()) {
            return true;
        }
        // Skip TV shows (season patterns)
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(E\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay)/i', $context->releaseName)) {
            return true;
        }

        return false;
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        if ($this->nativeContext === $context) {
            $packed = $this->nativePacked;
            $this->nativeContext = null;
        } else {
            $packed = $this->nativeCall($context);
        }
        if ($packed !== null) {
            if ($packed === 0) {
                return self::$nativeNoMatch ??= CategorizationResult::noMatch();
            }
            $rule = self::NATIVE_RULES[$packed >> 32] ?? null;
            if ($rule !== null) {
                return new CategorizationResult($packed & 0xFFFF, (($packed >> 16) & 0xFFFF) / 1000, $rule);
            }
        }

        return $this->categorizePhp($context);
    }

    /**
     * Pure-PHP categorization (fallback when the native library is unavailable).
     */
    public function categorizePhp(ReleaseContext $context): CategorizationResult
    {
        $name = $context->releaseName;

        // Try each music category
        if ($result = $this->checkAudiobook($name)) {
            return $result;
        }

        if ($result = $this->checkPodcast($name)) {
            return $result;
        }

        if ($result = $this->checkMusicVideo($name, $context->categorizeForeign)) {
            return $result;
        }

        if ($result = $this->checkLossless($name, $context->categorizeForeign)) {
            return $result;
        }

        if ($result = $this->checkMP3($name, $context->categorizeForeign)) {
            return $result;
        }

        if ($result = $this->checkOther($name, $context->categorizeForeign)) {
            return $result;
        }

        return $this->noMatch();
    }

    protected function checkForeign(string $name): bool
    {
        return (bool) preg_match('/(?:^|[\s\.\-_])(?:'.self::FOREIGN_LANGUAGES.'|'.self::LANGUAGE_CODES.')(?:$|[\s\.\-_])/i', $name);
    }

    protected function checkAudiobook(string $name): ?CategorizationResult
    {
        // Explicit audiobook indicators
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:Audiobook|Audio\s*Book|Talking\s*Book|ABEE|Audible)/i', $name)) {
            if (preg_match('/\b(?:Unabridged|Abridged|Narrated|Narrator|MP3|M4A|M4B|AAC|Read\s+By|Tantor|Blackstone|Brilliance|GraphicAudio|Penguin|Audible)\b/i', $name) ||
                preg_match('/\d+\s*CDs|\d+\s*Hours|Spoken\s+Word/i', $name) ||
                preg_match('/\.(mp3|m4a|m4b|aac|flac|ogg|wma)$/i', $name)) {
                return $this->matched(Category::MUSIC_AUDIOBOOK, 0.95, 'audiobook');
            }
        }

        // Audiobook patterns
        if (preg_match('/(?:[\(_\[])(?:Audiobook|AB|Unabridged)(?:[\)_\]])/i', $name) ||
            preg_match('/Read\s+By\s+[A-Z][a-z]+\s+[A-Z][a-z]+/i', $name)) {
            return $this->matched(Category::MUSIC_AUDIOBOOK, 0.9, 'audiobook_pattern');
        }

        // Legacy pattern
        if (preg_match('/(Audiobook|Audio.?Book)/i', $name)) {
            return $this->matched(Category::MUSIC_AUDIOBOOK, 0.85, 'audiobook_legacy');
        }

        return null;
    }

    protected function checkPodcast(string $name): ?CategorizationResult
    {
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:Podcast|Pod[._ -]?cast|Pod[._ -]Show)/i', $name)) {
            return $this->matched(Category::MUSIC_PODCAST, 0.9, 'podcast');
        }

        // Known podcast networks with episode indicators
        if (preg_match('/\b(?:NPR|BBC[._ -]Sounds|Gimlet|Wondery|Stitcher|iHeart[._ -]?Radio|Joe[._ -]Rogan|RadioLab|Serial)\b/i', $name) &&
            preg_match('/\b(?:Podcast|Episode|EP?[._ -]?\d+|Show)\b/i', $name)) {
            return $this->matched(Category::MUSIC_PODCAST, 0.85, 'podcast_network');
        }

        // Simple podcast match
        if (preg_match('/podcast/i', $name)) {
            return $this->matched(Category::MUSIC_PODCAST, 0.8, 'podcast_simple');
        }

        return null;
    }

    protected function checkMusicVideo(string $name, bool $categorizeForeign): ?CategorizationResult
    {
        // Music video indicators
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:Music\s*Video|Concert|Live\s*Show|Tour|Festival|MV|MTV)|\b(?:MVID|MVid)\b/i', $name)) {
            if (preg_match('/\b(?:720p|1080[pi]|2160p|BDRip|BluRay|DVDRip|HDTV|WebRip|WEB-DL|x264|x265)\b/i', $name) ||
                preg_match('/\b(?:Live|Unplugged|Acoustic|World\s*Tour|in\s*Concert|Official\s*Video|Bootleg|Remastered)\b/i', $name) ||
                preg_match('/\.(mkv|mp4|avi|ts|m2ts|mpg|mpeg|mov|wmv|vob|m4v)$/i', $name)) {

                if ($categorizeForeign && $this->checkForeign($name)) {
                    return $this->matched(Category::MUSIC_FOREIGN, 0.85, 'music_video_foreign');
                }

                return $this->matched(Category::MUSIC_VIDEO, 0.9, 'music_video');
            }
        }

        // Artist-title pattern with video format
        if (preg_match('/^[A-Z0-9][A-Za-z0-9\.\s\&\'\(\)\-]+\s+\-\s+[A-Z0-9][A-Za-z0-9\.\s\&\'\(\)\-]+.*?\b(720p|1080[pi]|2160p|Bluray|x264|x265)\b/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.8, 'music_video_foreign');
            }

            return $this->matched(Category::MUSIC_VIDEO, 0.8, 'music_video_artist');
        }

        return null;
    }

    protected function checkLossless(string $name, bool $categorizeForeign): ?CategorizationResult
    {
        // Lossless format indicators
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:FLAC|APE|WAV|ALAC|DSD|DSF|AIFF|PCM|Lossless)|\b(?:FLAC|APE|WAV|ALAC|DSD|DSF|AIFF|PCM)\b/i', $name)) {
            if (preg_match('/\b(?:24[Bb]it|96kHz|192kHz|Hi[- ]?Res|HD[- ]?Tracks|Vinyl[- ]?Rip|CD[- ]?Rip|WEB[- ]?Rip|HDtracks|Qobuz|Tidal|MQA|SACD)\b/i', $name) ||
                preg_match('/\.(flac|ape|wav|aiff|dsf|dff|m4a|tak)$/i', $name)) {

                if ($categorizeForeign && $this->checkForeign($name)) {
                    return $this->matched(Category::MUSIC_FOREIGN, 0.9, 'lossless_foreign');
                }

                return $this->matched(Category::MUSIC_LOSSLESS, 0.9, 'lossless');
            }
        }

        // FLAC patterns
        if (preg_match('/\[(19|20)\d\d\][._ -]\[FLAC\]|([\(\[])flac([\)\]])|FLAC\-(19|20)\d\d\-[a-z0-9]{1,12}|\.flac"|(19|20)\d\d\sFLAC|[._ -]FLAC.+(19|20)\d\d[._ -]| FLAC$/i', $name) ||
            preg_match('/\d{3,4}kbps[._ -]FLAC|\[FLAC\]|\(FLAC\)|FLACME|FLAC[._ -]\d{3,4}(kbps)?|WEB[._ -]FLAC/i', $name)) {

            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.85, 'flac_foreign');
            }

            return $this->matched(Category::MUSIC_LOSSLESS, 0.85, 'flac');
        }

        // Other lossless formats
        if (preg_match('/\b(?:APE|Monkey\'s[._ -]Audio|WavPack|WV|TAK|TTA|ALAC|Apple[._ -]Lossless)\b|\.(ape|wv|tak|tta)$/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.85, 'lossless_format_foreign');
            }

            return $this->matched(Category::MUSIC_LOSSLESS, 0.85, 'lossless_format');
        }

        return null;
    }

    protected function checkMP3(string $name, bool $categorizeForeign): ?CategorizationResult
    {
        // MP3 indicators
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:MP3|320kbps|256kbps|192kbps|128kbps|CBR|VBR)|\b(?:MP3)\b|[\._-](?:MP3)[\._-]|\.mp3$/i', $name)) {
            if (preg_match('/\b(?:320|256|192|128)[._-]?kbps|\b(?:320|256|192|128)[._-]?K|\((?:320|256|192|128)\)|\[(?:320|256|192|128)\]|V0|V2|VBR/i', $name) ||
                preg_match('/\b(?:CD[._-]?Rip|Web[._-]?Rip|WEB|iTunes|AmazonRip|Spotify[._-]?Rip|MP3\s*\-\s*\d{3}kbps)\b/i', $name) ||
                preg_match('/\.(m3u|mp3)"|rip(?:192|256|320)|[._-]FM[._-].+MP3/i', $name)) {

                if ($categorizeForeign && $this->checkForeign($name)) {
                    return $this->matched(Category::MUSIC_FOREIGN, 0.85, 'mp3_foreign');
                }

                return $this->matched(Category::MUSIC_MP3, 0.85, 'mp3');
            }
        }

        // MP3 scene patterns
        if (preg_match('/^[a-zA-Z0-9]{1,12}[._-](19|20)\d\d[._-][a-zA-Z0-9]{1,12}$|[a-z0-9]{1,12}\-(19|20)\d\d\-[a-z0-9]{1,12}/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.75, 'mp3_scene_foreign');
            }

            return $this->matched(Category::MUSIC_MP3, 0.75, 'mp3_scene');
        }

        // Bitrate patterns
        if (preg_match('/[\.\-\(\[_ ]\d{2,3}k[\.\-\)\]_ ]|\((192|256|320)\)|(320|cd|eac|vbr)[._-]+mp3|(cd|eac|mp3|vbr)[._-]+320/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.8, 'mp3_bitrate_foreign');
            }

            return $this->matched(Category::MUSIC_MP3, 0.8, 'mp3_bitrate');
        }

        return null;
    }

    protected function checkOther(string $name, bool $categorizeForeign): ?CategorizationResult
    {
        // Compilation and VA indicators
        if (preg_match('/(?:^|[^a-zA-Z0-9])(?:Compilation|Various[._ -]Artists|OST|Soundtrack|B-Sides|Greatest[._ -]Hits|Anthology)|\b(?:VA|V\.A|Bonus[._ -]Track|Discography|Box[._ -]Set)\b/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.8, 'music_other_foreign');
            }

            return $this->matched(Category::MUSIC_OTHER, 0.8, 'music_other');
        }

        // Album/CD patterns
        if (preg_match('/(?:\d)[._ -](?:CD|Albums|LP)[._ -](?:Set|Compilation)|CD[._ -](Collection|Box|SET)|(\d)-?CD[._ -]/i', $name) ||
            preg_match('/Vinyl[._ -](?:24[._ -]96|2496|Collection|RIP)|WEB[._ -](?:Single|Album)|EP[._ -]\d{4}|\bEP\b.+(?:19|20)\d\d|Live[._ -](?:at|At|@)/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.75, 'music_album_foreign');
            }

            return $this->matched(Category::MUSIC_OTHER, 0.75, 'music_album');
        }

        // DJ mixes and labels
        if (preg_match('/\b(?:Ministry[._ -]of[._ -]Sound|Hed[._ -]Kandi|Cream|Fabric[._ -]Live|Ultra[._ -]Music)\b/i', $name) ||
            preg_match('/\b(?:DJ[._ -]Mix|Mixed[._ -]By|Tiesto[._ -]Club|Radio[._ -]Show|Club[._ -]Hits)\b/i', $name)) {
            if ($categorizeForeign && $this->checkForeign($name)) {
                return $this->matched(Category::MUSIC_FOREIGN, 0.75, 'music_dj_foreign');
            }

            return $this->matched(Category::MUSIC_OTHER, 0.75, 'music_dj');
        }

        return null;
    }

    /**
     * Enables (true) or disables (false) the Wave 43.3 Rust helper; null restores
     * W433_RUST_FFI_DEFAULT.
     */
    public static function useW433RustFfi(?bool $enable = true): void
    {
        self::$w433RustFfiOverride = $enable;
    }

    public static function usesW433RustFfi(): bool
    {
        return self::$w433RustFfiOverride ?? self::W433_RUST_FFI_DEFAULT;
    }

    public static function resetW433RustFfi(): void
    {
        self::$w433RustFfiOverride = null;
        self::$w433RustFfiHandle = null;
    }

    /**
     * Music content summary for a batch of release names. Each lowercased name adds to its first
     * W433_MUSIC_CONTENT_MARKERS class, else to unknown, and separately to vinyl when it holds any
     * W433_VINYL_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, audiobook: int, podcast: int, video: int, lossless: int, mp3: int, unknown: int, vinyl: int}
     */
    public static function summarizeMusicContentBatch(array $releaseNames): array
    {
        return (self::usesW433RustFfi() ? self::summarizeMusicContentBatchNative($releaseNames) : null)
            ?? self::summarizeMusicContentBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, audiobook: int, podcast: int, video: int, lossless: int, mp3: int, unknown: int, vinyl: int}
     */
    public static function summarizeMusicContentBatchPhp(array $releaseNames): array
    {
        $audiobook = 0;
        $podcast = 0;
        $video = 0;
        $lossless = 0;
        $mp3 = 0;
        $unknown = 0;
        $vinylCount = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W433_VINYL_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $vinylCount++;

                    break;
                }
            }
            foreach (self::W433_MUSIC_CONTENT_MARKERS['audiobook'] as $needle) {
                if (str_contains($name, $needle)) {
                    $audiobook++;

                    continue 2;
                }
            }
            foreach (self::W433_MUSIC_CONTENT_MARKERS['podcast'] as $needle) {
                if (str_contains($name, $needle)) {
                    $podcast++;

                    continue 2;
                }
            }
            foreach (self::W433_MUSIC_CONTENT_MARKERS['video'] as $needle) {
                if (str_contains($name, $needle)) {
                    $video++;

                    continue 2;
                }
            }
            foreach (self::W433_MUSIC_CONTENT_MARKERS['lossless'] as $needle) {
                if (str_contains($name, $needle)) {
                    $lossless++;

                    continue 2;
                }
            }
            foreach (self::W433_MUSIC_CONTENT_MARKERS['mp3'] as $needle) {
                if (str_contains($name, $needle)) {
                    $mp3++;

                    continue 2;
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'audiobook' => $audiobook,
            'podcast' => $podcast,
            'video' => $video,
            'lossless' => $lossless,
            'mp3' => $mp3,
            'unknown' => $unknown,
            'vinyl' => $vinylCount,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, audiobook: int, podcast: int, video: int, lossless: int, mp3: int, unknown: int, vinyl: int}|null
     */
    public static function summarizeMusicContentBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW433Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'audiobook' => (int) $fields[1],
            'podcast' => (int) $fields[2],
            'video' => (int) $fields[3],
            'lossless' => (int) $fields[4],
            'mp3' => (int) $fields[5],
            'unknown' => (int) $fields[6],
            'vinyl' => (int) $fields[7],
        ];
    }

    /**
     * Calls this class's Wave 43.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W433_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW433Native(string $blob, string $lens): ?array
    {
        $ffi = self::w433RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w433_music_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w433_music_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W433_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w433RustFfi(): ?FFI
    {
        if (self::$w433RustFfiHandle === null) {
            self::$w433RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w433', self::W433_FFI_CDEF) ?? false;
        }

        return self::$w433RustFfiHandle === false ? null : self::$w433RustFfiHandle;
    }
}
