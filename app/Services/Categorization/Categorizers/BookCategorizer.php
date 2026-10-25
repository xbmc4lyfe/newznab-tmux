<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
