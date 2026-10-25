<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\View\View;
use Throwable;

class PrivacyPolicyController extends BasePageController
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w364_privacy_policy_summary(
            uint32_t flags,
            const char *markdown_ptr,
            size_t markdown_len,
            const char *title_ptr,
            size_t title_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustTvForumRoutePrivacyTermsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string DEFAULT_SITE_TITLE = 'NNTmux';

    private const int EXCERPT_MAX = 160;

    private const int WORDS_PER_MINUTE = 200;

    private const int MAX_INPUT = 16_777_216;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Display the privacy policy page.
     */
    public function privacyPolicy(): View
    {
        $title = 'Privacy Policy';
        $meta_title = config('app.name').' - Privacy Policy';
        $meta_keywords = 'privacy,policy,data protection';
        $meta_description = 'Privacy Policy for '.config('app.name');
        $privacy_content = null;

        return view('privacy-policy', compact('title', 'meta_title', 'meta_keywords', 'meta_description', 'privacy_content'));
    }

    /**
     * Toggles only the helper below; BasePageController keeps its own toggle.
     */
    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Summarizes a privacy policy markdown body for page metadata: line endings are normalized,
     * lines starting with `#` count as headings, blank lines separate paragraphs and the first
     * paragraph (whitespace collapsed, cut to 160 bytes on a UTF-8 boundary) becomes the excerpt
     * and meta description.
     *
     * @return array{title: string, meta_title: string, meta_description: string, first_heading: ?string, excerpt: ?string, headings: int, paragraphs: int, words: int, bytes: int, reading_minutes: int, has_content: bool}
     */
    public static function summarizePolicyDocument(?string $rawMarkdown, ?string $siteTitle): array
    {
        return self::usesRustFfi()
            ? self::summarizePolicyDocumentNative($rawMarkdown, $siteTitle)
            : self::summarizePolicyDocumentPhp($rawMarkdown, $siteTitle);
    }

    /**
     * @return array{title: string, meta_title: string, meta_description: string, first_heading: ?string, excerpt: ?string, headings: int, paragraphs: int, words: int, bytes: int, reading_minutes: int, has_content: bool}
     */
    public static function summarizePolicyDocumentNative(?string $rawMarkdown, ?string $siteTitle): array
    {
        $markdown = $rawMarkdown ?? '';
        $title = $siteTitle ?? '';
        $fields = strlen($markdown) > self::MAX_INPUT || strlen($title) > self::MAX_INPUT
            ? null
            : self::callNative([($rawMarkdown === null ? 0 : 1) | ($siteTitle === null ? 0 : 2), $markdown, strlen($markdown), $title, strlen($title)]);
        if ($fields === null || count($fields) !== 11 || $fields[0] === null || $fields[1] === null || $fields[2] === null) {
            return self::summarizePolicyDocumentPhp($rawMarkdown, $siteTitle);
        }

        return [
            'title' => $fields[0],
            'meta_title' => $fields[1],
            'meta_description' => $fields[2],
            'first_heading' => $fields[3],
            'excerpt' => $fields[4],
            'headings' => (int) $fields[5],
            'paragraphs' => (int) $fields[6],
            'words' => (int) $fields[7],
            'bytes' => (int) $fields[8],
            'reading_minutes' => (int) $fields[9],
            'has_content' => $fields[10] === '1',
        ];
    }

    /**
     * @return array{title: string, meta_title: string, meta_description: string, first_heading: ?string, excerpt: ?string, headings: int, paragraphs: int, words: int, bytes: int, reading_minutes: int, has_content: bool}
     */
    public static function summarizePolicyDocumentPhp(?string $rawMarkdown, ?string $siteTitle): array
    {
        $title = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($siteTitle ?? '', self::WHITESPACE)) ?? '';
        if ($title === '') {
            $title = self::DEFAULT_SITE_TITLE;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $rawMarkdown ?? '');
        $headings = 0;
        $paragraphs = 0;
        $firstHeading = null;
        $firstParagraph = [];
        $inParagraph = false;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line, self::WHITESPACE);
            if ($line === '') {
                $inParagraph = false;
            } elseif ($line[0] === '#') {
                $headings++;
                $heading = trim(ltrim($line, '#'), self::WHITESPACE);
                if ($firstHeading === null && $heading !== '') {
                    $firstHeading = $heading;
                }
                $inParagraph = false;
            } else {
                if (! $inParagraph) {
                    $paragraphs++;
                    $inParagraph = true;
                }
                if ($paragraphs === 1) {
                    $firstParagraph[] = $line;
                }
            }
        }
        $body = trim($text, self::WHITESPACE);
        $words = count(preg_split('/[ \t\n\r\0\x0B\x0C]+/', $body, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $excerpt = $firstParagraph === []
            ? null
            : self::truncateExcerpt(preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', implode(' ', $firstParagraph)) ?? '');

        return [
            'title' => $title,
            'meta_title' => $title.' - Privacy Policy',
            'meta_description' => $excerpt ?? 'Privacy Policy for '.$title,
            'first_heading' => $firstHeading,
            'excerpt' => $excerpt,
            'headings' => $headings,
            'paragraphs' => $paragraphs,
            'words' => $words,
            'bytes' => strlen($body),
            'reading_minutes' => intdiv($words + self::WORDS_PER_MINUTE - 1, self::WORDS_PER_MINUTE),
            'has_content' => $body !== '',
        ];
    }

    private static function truncateExcerpt(string $text): string
    {
        if (strlen($text) <= self::EXCERPT_MAX) {
            return $text;
        }
        $cut = self::EXCERPT_MAX;
        while ($cut > 0 && (ord($text[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $snippet = substr($text, 0, $cut);
        $space = strrpos($snippet, ' ');
        if ($space !== false && $space > 0) {
            $snippet = substr($snippet, 0, $space);
        }

        return rtrim($snippet, ' ').'...';
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w364_privacy_policy_summary(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w364_privacy_policy_summary(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
