<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class GroupNameCategorizer extends AbstractCategorizer
{
    protected int $priority = 5;

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_group_name(const char *group, size_t group_len);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'group_name_anime',
        1 => 'group_name_tv',
        2 => 'group_name_movie',
        3 => 'group_name_xxx',
        4 => 'group_name_music',
        5 => 'group_name_game',
        6 => 'group_name_pc',
        7 => 'group_name_book',
    ];

    /** Resolved library handle: null until first use, false when the library is unavailable. */
    private static FFI|false|null $nativeFfi = null;

    private static ?CategorizationResult $nativeNoMatch = null;

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
            $packed = $ffi->nntmux_categorize_group_name($context->groupName, \strlen($context->groupName));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'GroupName';
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        if (empty($context->groupName)) {
            return self::$nativeNoMatch ??= CategorizationResult::noMatch();
        }
        $packed = $this->nativeCall($context);
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
        $groupName = $context->groupName;
        if (empty($groupName)) {
            return $this->noMatch();
        }
        if (preg_match('/alt\.binaries\..*anime/i', $groupName)) {
            return $this->matched(Category::TV_ANIME, 0.8, 'group_name_anime');
        }
        if (preg_match('/alt\.binaries\..*?(tv|hdtv|tvseries)/i', $groupName)) {
            return $this->matched(Category::TV_OTHER, 0.6, 'group_name_tv');
        }
        if (preg_match('/alt\.binaries\..*?(movies?|dvd|bluray|x264)/i', $groupName)) {
            return $this->matched(Category::MOVIE_OTHER, 0.6, 'group_name_movie');
        }
        if (preg_match('/alt\.binaries\..*?(erotica|pictures\.erotica|xxx)/i', $groupName)) {
            return $this->matched(Category::XXX_OTHER, 0.7, 'group_name_xxx');
        }
        if (preg_match('/alt\.binaries\..*?(sounds?|mp3|music|lossless)/i', $groupName)) {
            return $this->matched(Category::MUSIC_OTHER, 0.6, 'group_name_music');
        }
        if (preg_match('/alt\.binaries\..*?(games?|console|psx|nintendo)/i', $groupName)) {
            return $this->matched(Category::GAME_OTHER, 0.6, 'group_name_game');
        }
        if (preg_match('/alt\.binaries\..*?(warez|0day|apps?|software)/i', $groupName)) {
            return $this->matched(Category::PC_0DAY, 0.6, 'group_name_pc');
        }
        if (preg_match('/alt\.binaries\..*?(e-?book|ebook|comics?)/i', $groupName)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.5, 'group_name_book');
        }

        return $this->noMatch();
    }
}
