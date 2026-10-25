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
    /**
     * Wave 42.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php measures at least 4.5x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W423 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W423_RUST_FFI_DEFAULT = true;

    public const string W423_FFI_CDEF = <<<'C'
        ssize_t nntmux_w423_group_name_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W423_GROUP_PREFIX = 'alt.binaries.';

    /**
     * Group topic marker substrings, in match order. The first topic with a substring of the
     * lowercased group name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W423_GROUP_TOPIC_MARKERS = [
        'anime' => ['anime'],
        'tv' => ['hdtv', 'tvseries', '.tv', 'teevee'],
        'movie' => ['movies', 'movie', 'dvd', 'bluray', 'x264'],
        'xxx' => ['erotica', 'xxx'],
        'music' => ['mp3', 'sounds', 'music', 'lossless'],
        'game' => ['games', 'console', 'psx', 'nintendo'],
        'pc' => ['warez', '0day', 'apps', 'software'],
        'book' => ['ebook', 'e-book', 'comics'],
    ];

    /** Exactly 12 0x1F-separated decimals of 1-19 digits. */
    private const string W423_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){11}\d{1,19}\z/';

    private static ?bool $w423RustFfiOverride = null;

    private static FFI|false|null $w423RustFfiHandle = null;

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
     * Group name summary for a batch of Usenet group names. Each lowercased name adds to
     * alt_binaries when it starts with W423_GROUP_PREFIX, to its first W423_GROUP_TOPIC_MARKERS
     * topic, else to other, and to empty when it is ''. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, alt_binaries: int, anime: int, tv: int, movie: int, xxx: int, music: int, game: int, pc: int, book: int, other: int, empty: int}
     */
    public static function summarizeGroupNameBatch(array $groupNames): array
    {
        return (self::usesW423RustFfi() ? self::summarizeGroupNameBatchNative($groupNames) : null)
            ?? self::summarizeGroupNameBatchPhp($groupNames);
    }

    /**
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, alt_binaries: int, anime: int, tv: int, movie: int, xxx: int, music: int, game: int, pc: int, book: int, other: int, empty: int}
     */
    public static function summarizeGroupNameBatchPhp(array $groupNames): array
    {
        $topics = ['anime' => 0, 'tv' => 0, 'movie' => 0, 'xxx' => 0, 'music' => 0, 'game' => 0, 'pc' => 0, 'book' => 0];
        $prefixed = 0;
        $other = 0;
        $empty = 0;
        foreach ($groupNames as $groupName) {
            $name = strtolower(\is_string($groupName) ? $groupName : '');
            if ($name === '') {
                $empty++;
            }
            if (str_starts_with($name, self::W423_GROUP_PREFIX)) {
                $prefixed++;
            }
            foreach (self::W423_GROUP_TOPIC_MARKERS as $topic => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $topics[$topic]++;

                        continue 3;
                    }
                }
            }
            $other++;
        }

        return [
            'total' => \count($groupNames),
            'alt_binaries' => $prefixed,
            'anime' => $topics['anime'],
            'tv' => $topics['tv'],
            'movie' => $topics['movie'],
            'xxx' => $topics['xxx'],
            'music' => $topics['music'],
            'game' => $topics['game'],
            'pc' => $topics['pc'],
            'book' => $topics['book'],
            'other' => $other,
            'empty' => $empty,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $groupNames
     * @return array{total: int, alt_binaries: int, anime: int, tv: int, movie: int, xxx: int, music: int, game: int, pc: int, book: int, other: int, empty: int}|null
     */
    public static function summarizeGroupNameBatchNative(array $groupNames): ?array
    {
        $lengths = [];
        foreach ($groupNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $groupNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW423Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'alt_binaries' => (int) $fields[1],
            'anime' => (int) $fields[2],
            'tv' => (int) $fields[3],
            'movie' => (int) $fields[4],
            'xxx' => (int) $fields[5],
            'music' => (int) $fields[6],
            'game' => (int) $fields[7],
            'pc' => (int) $fields[8],
            'book' => (int) $fields[9],
            'other' => (int) $fields[10],
            'empty' => (int) $fields[11],
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
            $written = $ffi->nntmux_w423_group_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w423_group_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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
