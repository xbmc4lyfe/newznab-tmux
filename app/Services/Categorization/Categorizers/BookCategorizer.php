<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class BookCategorizer extends AbstractCategorizer
{
    /**
     * Wave 42.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php measures at least 5.1x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W423 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W423_RUST_FFI_DEFAULT = true;

    public const string W423_FFI_CDEF = <<<'C'
        ssize_t nntmux_w423_book_format_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Book format marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W423_BOOK_FORMAT_MARKERS = [
        'comic' => ['.cbr', '.cbz', '.cb7', 'comic', 'manga'],
        'audiobook' => ['audiobook', '.m4b', 'audible', 'unabridged'],
        'magazine' => ['magazine', 'weekly', 'monthly', 'issue'],
        'ebook' => ['epub', 'mobi', 'azw3', '.pdf', 'ebook'],
    ];

    public const string W423_RETAIL_MARKER = 'retail';

    /** Exactly 7 0x1F-separated decimals of 1-19 digits. */
    private const string W423_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){6}\d{1,19}\z/';

    private static ?bool $w423RustFfiOverride = null;

    private static FFI|false|null $w423RustFfiHandle = null;

    protected int $priority = 45;

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_book(const char *name, size_t name_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'comic_format',
        1 => 'comic_publisher',
        2 => 'manga',
        3 => 'technical_publisher',
        4 => 'technical_subject',
        5 => 'magazine_frequency',
        6 => 'magazine_title',
        7 => 'ebook_format',
        8 => 'ebook_indicator',
        9 => 'ebook_pdf_format',
        10 => 'ebook_pdf_indicator',
        11 => 'ebook_platform',
        12 => 'ebook_book_signals',
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
            $packed = $ffi->nntmux_categorize_book($context->releaseName, \strlen($context->releaseName), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'Book';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_book($context->releaseName, \strlen($context->releaseName), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
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
        $name = $context->releaseName;

        if ($context->hasAdultMarkers()) {
            return true;
        }
        if (preg_match('/\.PS4-[A-Z0-9]+$/i', $name)) {
            return true;
        }
        if (preg_match('/\b(?:PS[1-5]|PlayStation|Xbox|Switch|Nintendo|Wii|3DS|GameCube)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(?:PPSA\d{4,6}|CUSA\d{5}|XCI|NSP|PKG)\b/i', $name)) {
            return true;
        }
        // Skip software and utility release naming patterns.
        if (preg_match('/\bv?\d+(?:\.\d+){1,4}\b.*\b(?:Multilingual|x64|x86|Portable|Setup|Patch|Install(?:er)?|Crack(?:ed)?|Keygen|Serial|Regged|Pre-?Activated)\b/i', $name)) {
            return true;
        }
        if ($this->hasSoftwareMarkers($name)) {
            return true;
        }
        // Skip music-style release names that are often misfiled as books.
        if (preg_match('/\b(?:WEB[\.\-_ ]?FLAC|FLAC|MP3|M4A|M4B|AAC|OGG|ALAC|WAV|LOSSLESS|320kbps|256kbps|192kbps|128kbps|VBR|CBR|discography|FALCON|OST|Soundtrack|Vinyl[._ -]?Rip|CD[._ -]?Rip)\b/i', $name)) {
            return true;
        }
        // Skip video course/training releases that often include "tutorial" language.
        if (preg_match('/\b(?:Udemy|Pluralsight|Lynda\.?com|Coursera|LinkedIn[._ -]?Learning|Skillshare|MasterClass|CBT[._ -]?Nuggets|Digital-?Tutors)\b/i', $name)) {
            return true;
        }
        // Skip video media signatures.
        if ($this->hasVideoReleaseMarkers($name)) {
            return true;
        }
        // Skip distro/iso images and generic media images.
        if (preg_match('/\b(?:Ubuntu|Debian|Fedora|Kali|Arch(?:Linux)?|CentOS|OpenSUSE|LinuxMint)\b.*\b(?:ISO|img|amd64|x86_64|installer)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(?:ISO|IMG|DMG|bootable)\b/i', $name)) {
            return true;
        }
        // Skip font packs and typeface archives.
        if (preg_match('/\b(?:Font(?:s)?|Typeface|OpenType|TrueType)\b/i', $name) ||
            preg_match('/\.(?:otf|ttf|woff2?)$/i', $name)) {
            return true;
        }
        // Skip TV shows (season patterns)
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(E\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay)/i', $name)) {
            return true;
        }
        // Skip movies (year + quality patterns)
        if (preg_match('/\b(19|20)\d{2}\b.*\b(1080p|720p|2160p|BluRay|WEB-DL|BDRip|DVDRip)\b/i', $name)) {
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
        if ($result = $this->checkComic($name)) {
            return $result;
        }
        if ($result = $this->checkTechnical($name)) {
            return $result;
        }
        if ($result = $this->checkMagazine($name)) {
            return $result;
        }
        if ($result = $this->checkEbook($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    protected function checkComic(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(?:CBR|CBZ|C2C)\b|\.(?:cbr|cbz)$/i', $name)) {
            return $this->matched(Category::BOOKS_COMICS, 0.9, 'comic_format');
        }
        if (preg_match('/\b(?:Marvel|DC[._ -]Comics|Image[._ -]Comics|Dark[._ -]Horse|IDW)\b/i', $name) &&
            preg_match('/\b(?:Comics?|Annual|Issue|Vol|TPB)\b/i', $name)) {
            return $this->matched(Category::BOOKS_COMICS, 0.85, 'comic_publisher');
        }
        if (preg_match('/\b(?:Manga|Manhwa|Manhua|Webtoon)\b/i', $name)) {
            return $this->matched(Category::BOOKS_COMICS, 0.85, 'manga');
        }

        return null;
    }

    protected function checkTechnical(string $name): ?CategorizationResult
    {
        if ($this->hasSoftwareMarkers($name) || $this->hasVideoCourseMarkers($name)) {
            return null;
        }

        $publishers = 'Apress|Addison[._ -]Wesley|Manning|No[._ -]Starch|O[\'’]?Reilly|Packt|Pragmatic|Wiley|Wrox';
        if (preg_match('/\b('.$publishers.')\b/i', $name)) {
            return $this->matched(Category::BOOKS_TECHNICAL, 0.9, 'technical_publisher');
        }
        $subjects = 'Programming|Python|JavaScript|Java|Database|Linux|DevOps|Machine[._ -]Learning|Data[._ -]Science';
        $bookSignals = '\b(?:Book|Guide|Tutorial|Learn|Handbook|Cookbook|Reference)\b';
        if (preg_match('/\b('.$subjects.')\b/i', $name) &&
            preg_match('/'.$bookSignals.'/i', $name) &&
            $this->hasBookCorroborator($name)) {
            return $this->matched(Category::BOOKS_TECHNICAL, 0.85, 'technical_subject');
        }

        return null;
    }

    protected function checkMagazine(string $name): ?CategorizationResult
    {
        $magazines = 'Forbes|Fortune|GQ|National[._ -]Geographic|Newsweek|Vogue|Wired|The[._ -]?Economist|New[._ -]?Yorker|Scientific[._ -]?American|Popular[._ -]?Mechanics|Cosmopolitan|Elle|Esquire|Vanity[._ -]?Fair|Rolling[._ -]?Stone|Entertainment[._ -]?Weekly|People|Playboy';
        $hasTitle = preg_match('/\b('.$magazines.')\b/i', $name) === 1;
        $hasIssueNumber = preg_match('/(?:^|[._ -])Issue[._ -]?\d{1,4}(?:$|[._ -,])/i', $name) === 1;
        $hasDateSignal = preg_match('/\b(?:19|20)\d{2}\b|(?:^|[._ -])(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[._ -]?(?:19|20)?\d{2}\b/i', $name) === 1;
        $hasFrequency = preg_match('/[._ -](Monthly|Weekly|Quarterly|Annual)[._ -]/i', $name) === 1;
        $hasIssueStyleTitle = preg_match('/\b([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*)\s*-\s*Issue\s+\d+/i', $name) === 1;
        $hasMcnMagazineSignal = preg_match('/\bMCN[._ -](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|January|February|March|April|June|July|August|September|October|November|December)[._ -]\d{1,2}[._ -](?:19|20)\d{2}\b/i', $name) === 1
            && preg_match('/\bMAGAZINE\b/i', $name) === 1;

        if (($hasFrequency && ($hasIssueNumber || $hasDateSignal || $hasTitle)) ||
            ($hasIssueNumber && ($hasDateSignal || $hasTitle || $hasIssueStyleTitle)) ||
            $hasMcnMagazineSignal) {
            return $this->matched(Category::BOOKS_MAGAZINES, 0.9, 'magazine_frequency');
        }
        if ($hasTitle) {
            return $this->matched(Category::BOOKS_MAGAZINES, 0.85, 'magazine_title');
        }

        return null;
    }

    protected function checkEbook(string $name): ?CategorizationResult
    {
        if ($this->hasSoftwareMarkers($name)) {
            return null;
        }

        $strictFormats = 'EPUB|MOBI|AZW\d?|FB2|DJVU|LIT';
        if (preg_match('/\.('.$strictFormats.')$/i', $name)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.9, 'ebook_format');
        }
        if (preg_match('/\b('.$strictFormats.')\b/i', $name)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.85, 'ebook_indicator');
        }
        if (preg_match('/\.PDF$/i', $name) && $this->hasBookCorroborator($name)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.75, 'ebook_pdf_format');
        }
        if (preg_match('/\bPDF\b/i', $name) && $this->hasBookCorroborator($name)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.65, 'ebook_pdf_indicator');
        }
        if (preg_match('/\b(E-?book|Kindle|Kobo|Nook)\b/i', $name)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.8, 'ebook_platform');
        }
        if ($this->hasBookCorroborator($name) && preg_match('/\b(19|20)\d{2}\b/', $name) === 1) {
            return $this->matched(Category::BOOKS_EBOOK, 0.6, 'ebook_book_signals');
        }

        return null;
    }

    protected function hasBookCorroborator(string $name): bool
    {
        return preg_match('/\b(?:ISBN(?:-1[03])?|Edition|Ed\.|Author|Novel|Volume|Vol\.|Chapter|Press|Paperback|Hardcover|E-?book|Kindle|Kobo)\b/i', $name) === 1
            || preg_match('/\b(?:Apress|Addison[._ -]Wesley|Manning|No[._ -]Starch|O[\'’]?Reilly|Packt|Pragmatic|Wiley|Wrox)\b/i', $name) === 1
            || preg_match('/\.(?:epub|mobi|azw\d?|fb2|djvu|lit|pdf)$/i', $name) === 1;
    }

    protected function hasSoftwareMarkers(string $name): bool
    {
        if (preg_match('/\b(?:Setup|Install(?:er)?|Portable|Crack(?:ed)?|Keygen|Serial|Regged|Pre-?Activated|Patch(?:ed)?|NFO|ReadNFO|Win(?:dows)?|macOS|Linux|x64|x86|amd64|AIO|Retail|Incl)\b/i', $name)) {
            return true;
        }

        if (preg_match('/\b(?:Adobe|Foxit|Autodesk|AutoCAD|Corel|Microsoft[._ -]Office|Office(?:365)?|Visual[._ -]Studio|IntelliJ|PyCharm|VMware|VirtualBox)\b/i', $name)) {
            return true;
        }

        return preg_match('/\.(?:exe|msi|dmg|pkg)$/i', $name) === 1;
    }

    protected function hasVideoCourseMarkers(string $name): bool
    {
        return preg_match('/\b(?:Udemy|Pluralsight|Lynda\.?com|Coursera|LinkedIn[._ -]?Learning|Skillshare|MasterClass|CBT[._ -]?Nuggets|Digital-?Tutors)\b/i', $name) === 1;
    }

    protected function hasVideoReleaseMarkers(string $name): bool
    {
        return preg_match('/\b(?:720p|1080p|2160p|4k)\b/i', $name) === 1
            && preg_match('/\b(?:WEB[._ -]?DL|WEBRip|HDTV|BluRay|BDRip|DVDRip|x264|x265|HEVC|HDR|Remux|AVC)\b/i', $name) === 1;
    }

    public static function useW423RustFfi(bool $enable = true): void
    {
        self::$w423RustFfiOverride = $enable;
    }

    public static function usesW423RustFfi(): bool
    {
        return self::$w423RustFfiOverride ?? self::W423_RUST_FFI_DEFAULT;
    }

    public static function resetW423RustFfi(): void
    {
        self::$w423RustFfiOverride = null;
        self::$w423RustFfiHandle = null;
    }

    /**
     * Book format summary for a batch of release names. Each lowercased name adds to its first
     * W423_BOOK_FORMAT_MARKERS class, else to unknown, and separately to retail when it holds
     * W423_RETAIL_MARKER. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, comic: int, audiobook: int, magazine: int, ebook: int, unknown: int, retail: int}
     */
    public static function summarizeBookFormatBatch(array $releaseNames): array
    {
        return (self::usesW423RustFfi() ? self::summarizeBookFormatBatchNative($releaseNames) : null)
            ?? self::summarizeBookFormatBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, comic: int, audiobook: int, magazine: int, ebook: int, unknown: int, retail: int}
     */
    public static function summarizeBookFormatBatchPhp(array $releaseNames): array
    {
        $classes = ['comic' => 0, 'audiobook' => 0, 'magazine' => 0, 'ebook' => 0];
        $unknown = 0;
        $retail = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            if (str_contains($name, self::W423_RETAIL_MARKER)) {
                $retail++;
            }
            foreach (self::W423_BOOK_FORMAT_MARKERS as $class => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $classes[$class]++;

                        continue 3;
                    }
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'comic' => $classes['comic'],
            'audiobook' => $classes['audiobook'],
            'magazine' => $classes['magazine'],
            'ebook' => $classes['ebook'],
            'unknown' => $unknown,
            'retail' => $retail,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, comic: int, audiobook: int, magazine: int, ebook: int, unknown: int, retail: int}|null
     */
    public static function summarizeBookFormatBatchNative(array $releaseNames): ?array
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
        $fields = self::callW423Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'comic' => (int) $fields[1],
            'audiobook' => (int) $fields[2],
            'magazine' => (int) $fields[3],
            'ebook' => (int) $fields[4],
            'unknown' => (int) $fields[5],
            'retail' => (int) $fields[6],
        ];
    }

    /**
     * Calls this class's Wave 42.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W423_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW423Native(string $blob, string $lens): ?array
    {
        $ffi = self::w423RustFfi();
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
            $written = $ffi->nntmux_w423_book_format_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w423_book_format_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W423_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w423RustFfi(): ?FFI
    {
        if (self::$w423RustFfiHandle === null) {
            self::$w423RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w423', self::W423_FFI_CDEF) ?? false;
        }

        return self::$w423RustFfiHandle === false ? null : self::$w423RustFfiHandle;
    }

    // ========================================================================
    // WAVE 60.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.29x, p99 6.20x; uncommon mean 6.30x, p99 5.06x); see RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    public const string W60_BOOK_CATEGORIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w603_book_token_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w60BookTokenBatch(). */
    public const int W60_BOOK_CATEGORIZER_TRAILER = 28;

    /**
     * Book token flags: 1 ebook, 2 audiobook, 4 comic, 8 magazine, 16 technical, 32 retail and 64 ISBN (128, a
     * 19xx/20xx year, and 13-digit 978/979 ISBNs are detected by shape).
     *
     * @var array<string, int>
     */
    public const array W60_BOOK_TOKEN_FLAGS = ['epub' => 1, 'mobi' => 1, 'azw' => 1, 'azw3' => 1, 'fb2' => 1, 'djvu' => 1, 'ebook' => 1,
        'kindle' => 1, 'kobo' => 1, 'nook' => 1, 'audiobook' => 2, 'audiobooks' => 2, 'unabridged' => 2, 'abridged' => 2, 'audible' => 2,
        'narrated' => 2, 'm4b' => 2, 'cbr' => 4, 'cbz' => 4, 'comic' => 4, 'comics' => 4, 'manga' => 4, 'manhwa' => 4, 'webtoon' => 4, 'c2c' => 4,
        'tpb' => 4, 'magazine' => 8, 'magazines' => 8, 'weekly' => 8, 'monthly' => 8, 'quarterly' => 8, 'issue' => 8, 'apress' => 16,
        'packt' => 16, 'oreilly' => 16, 'wiley' => 16, 'manning' => 16, 'wrox' => 16, 'programming' => 16, 'python' => 16, 'javascript' => 16,
        'cookbook' => 16, 'handbook' => 16, 'retail' => 32, 'isbn' => 64];

    /**
     * Extension classes: 1 ebook, 2 PDF, 3 comic archive and 4 audio (any other 1-5 alphanumeric extension is 5).
     *
     * @var array<string, int>
     */
    public const array W60_BOOK_EXTENSIONS = ['epub' => 1, 'mobi' => 1, 'azw' => 1, 'azw3' => 1, 'fb2' => 1, 'djvu' => 1, 'lit' => 1, 'prc' => 1,
        'pdf' => 2, 'cbr' => 3, 'cbz' => 3, 'cb7' => 3, 'cbt' => 3, 'm4b' => 4, 'mp3' => 4, 'm4a' => 4, 'aac' => 4, 'flac' => 4];

    public static function usesW60RustFfi(): bool
    {
        $override = self::w60RustFfiState();

        return \is_bool($override) ? $override : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the BookCategorizer Wave 60 Rust path forced on or off, then restores the previous setting.
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

    /**
     * Wave 60.3 book token classification plans. Each item is `releaseName|sizeBytes` (fields trimmed). Per item
     * `rule | extension << 3 | flags << 6 | categoryId << 14 | confidenceMilli << 28 | sizeState << 38 | sizeBand << 40
     * | min(tokens, 63) << 43 | min(nameLength, 255) << 49`: the extension (after the last `.`, 1-5 alphanumerics,
     * case-insensitive) is 0 none or its W60_BOOK_EXTENSIONS class (5 other); flags OR the W60_BOOK_TOKEN_FLAGS of
     * every `[^a-z0-9]+`-separated lowercase token, plus 128 for a four-digit 19xx/20xx token and 64 for a
     * 13-digit 978/979 token; the rule, in order, is 1 comic (comic extension or flag, 0.9 with the extension else
     * 0.85), 2 technical (0.85), 3 magazine (0.9 with a year else 0.75), 4 audiobook (0.9 with an audio extension
     * else 0.85), 5 ebook (ebook extension 0.9, ebook flag 0.85 or a PDF with an ISBN or year 0.75), else 0
     * unknown with category 0; size is w60BookSize(). Then six rule, six extension, eight flag and five size band
     * counts, the valid size sum, the largest token count and the distinct valid extensions.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60BookTokenBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::w60BookTokenBatchNative($items) : self::w60BookTokenBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60BookTokenBatchNative(array $items): array
    {
        return self::w60Invoke($items, \count($items) + self::W60_BOOK_CATEGORIZER_TRAILER) ?? self::w60BookTokenBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60BookTokenBatchPhp(array $items): array
    {
        $out = [];
        $rules = $extensions = [0, 0, 0, 0, 0, 0];
        $flagCounts = array_fill(0, 8, 0);
        $bands = [0, 0, 0, 0, 0];
        $sizeSum = $maxTokens = 0;
        $distinct = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 2) + ['', ''];
            $name = trim($f[0]);
            $dot = strrpos($name, '.');
            $ext = $dot === false ? '' : strtolower(substr($name, $dot + 1));
            $extLength = \strlen($ext);
            $extension = 0;
            if ($extLength >= 1 && $extLength <= 5 && strspn($ext, 'abcdefghijklmnopqrstuvwxyz0123456789') === $extLength) {
                $distinct[$ext] = true;
                $extension = self::W60_BOOK_EXTENSIONS[$ext] ?? 5;
            }
            $tokens = $name === '' ? [] : (preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $flags = 0;
            foreach ($tokens as $token) {
                $flags |= self::W60_BOOK_TOKEN_FLAGS[$token] ?? 0;
                $tokenLength = \strlen($token);
                if ($tokenLength === 4 && strspn($token, '0123456789') === 4 && ($token[0].$token[1] === '19' || $token[0].$token[1] === '20')) {
                    $flags |= 128;
                } elseif ($tokenLength === 13 && strspn($token, '0123456789') === 13 && (str_starts_with($token, '978') || str_starts_with($token, '979'))) {
                    $flags |= 64;
                }
            }
            [$rule, $category, $confidence] = match (true) {
                $extension === 3 || ($flags & 4) !== 0 => [1, Category::BOOKS_COMICS, $extension === 3 ? 900 : 850],
                ($flags & 16) !== 0 => [2, Category::BOOKS_TECHNICAL, 850],
                ($flags & 8) !== 0 => [3, Category::BOOKS_MAGAZINES, ($flags & 128) !== 0 ? 900 : 750],
                ($flags & 2) !== 0 => [4, Category::MUSIC_AUDIOBOOK, $extension === 4 ? 900 : 850],
                $extension === 1 => [5, Category::BOOKS_EBOOK, 900],
                ($flags & 1) !== 0 => [5, Category::BOOKS_EBOOK, 850],
                $extension === 2 && ($flags & (64 | 128)) !== 0 => [5, Category::BOOKS_EBOOK, 750],
                default => [0, 0, 0],
            };
            [$sizeState, $band, $bytes] = self::w60BookSize(trim($f[1]));
            $count = \count($tokens);
            $rules[$rule]++;
            $extensions[$extension]++;
            $bands[$band]++;
            for ($i = 0; $i < 8; $i++) {
                $flagCounts[$i] += $flags >> $i & 1;
            }
            $sizeSum += $bytes;
            $maxTokens = max($maxTokens, $count);
            $out[] = $rule | $extension << 3 | $flags << 6 | $category << 14 | $confidence << 28 | $sizeState << 38 | $band << 40
                | min($count, 63) << 43 | min(\strlen($name), 255) << 49;
        }
        array_push($out, ...$rules, ...$extensions, ...$flagCounts, ...$bands);
        array_push($out, $sizeSum, $maxTokens, \count($distinct));

        return $out;
    }

    /**
     * Size state 0 empty, 1 up to 12 digits and 2 invalid; the band is 0 without a valid size, then 1 below 1 MiB,
     * 2 below 50 MiB, 3 below 500 MiB and 4 larger; then the byte count (0 unless valid).
     *
     * @return array{int, int, int}
     */
    private static function w60BookSize(string $raw): array
    {
        if ($raw === '') {
            return [0, 0, 0];
        }
        $length = \strlen($raw);
        if ($length > 12 || strspn($raw, '0123456789') !== $length) {
            return [2, 0, 0];
        }
        $bytes = (int) $raw;

        return [1, $bytes < 1_048_576 ? 1 : ($bytes < 52_428_800 ? 2 : ($bytes < 524_288_000 ? 3 : 4)), $bytes];
    }

    /**
     * Wave 60 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w603', self::W60_BOOK_CATEGORIZER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w60RustFfiState('ffi');
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
                $handle = self::w60RustFfiState('ffi');
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
     * Calls the Wave 60.3 BookCategorizer export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w60Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w60RustFfiState('ffi');
            $out = self::w60RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w60RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W60_BOOK_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w603_book_token_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
