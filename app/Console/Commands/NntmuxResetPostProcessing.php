<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxResetPostProcessing extends Command
{
    public const string W381_FFI_CDEF = <<<'C'
        ssize_t nntmux_w381_obfuscation_gate_router_admin_names(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php is below 1.5x.
     */
    public const bool W381_RUST_FFI_DEFAULT = false;

    private const int W381_RUST_MAX_INPUT = 16_777_216;

    private static ?bool $w381UseRustFfi = null;

    private static FFI|false|null $w381Ffi = null;

    /** `planCategoryReset()` actions by Wave 38.1 action code. */
    private const array W381_ACTIONS = [1 => 'refuse_environment', 2 => 'invalid', 3 => 'full', 4 => 'categories'];

    /** Categories whose reset also refreshes search documents. */
    private const array W381_REINDEXING = ['movie', 'tv', 'misc'];

    /** Null selects the benchmark-qualified default for each helper. */
    public ?bool $useRustFfi = null;

    /**
     * @var array<string, mixed>
     */
    private static $allowedCategories = [ // @phpstan-ignore property.defaultValue
        'music',
        'console',
        'movie',
        'game',
        'tv',
        'adult',
        'book',
        'misc',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:resetpp {--c|category=* : Reset all, multiple or single category (music, console, movie, game, tv, adult, book, misc). Supports comma-separated and repeated options}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset all, multiple or single release category postprocessing';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {

        // Allow resetting categories only if environment is local and category is 'misc'
        if (app()->environment() !== 'local' && ((isset($this->option('category')['0']) && $this->option('category')[0] !== 'misc') || ! isset($this->option('category')['0']))) {
            $this->error('This command can only be run in local environment');

            return;
        }

        $raw = (array) $this->option('category');
        if (empty($raw)) {
            $qry = Release::query()->select(['id'])->get();
            $total = \count($qry);
            if ($total > 0) {
                $bar = $this->output->createProgressBar($total);
                $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
                $bar->start();
                $this->info('Resetting all postprocessing');
                foreach ($qry as $releases) {
                    Release::query()->where('id', $releases->id)->update(
                        [
                            'consoleinfo_id' => null,
                            'gamesinfo_id' => null,
                            'imdbid' => null,
                            'movieinfo_id' => null,
                            'musicinfo_id' => null,
                            'bookinfo_id' => null,
                            'book_lookup_attempts' => 0,
                            'book_lookup_retry_at' => null,
                            'book_name_normalized_at' => null,
                            ...TvProcessingCandidateQuery::resetColumns(),
                            'passwordstatus' => -1,
                            'haspreview' => -1,
                            'archive_retry_at' => null,
                            'jpgstatus' => 0,
                            'videostatus' => 0,
                            'nfostatus' => -1,
                        ]
                    );
                    Search::updateRelease((int) $releases->id);
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            } else {
                $this->info('No releases to reset');
            }
        } else {
            $normalized = $this->normalizeCategories($raw);

            // Validate
            $invalid = $this->invalidCategories($normalized);
            if (! empty($invalid)) {
                $this->error('Unknown category option(s): '.implode(', ', $invalid));
                $this->line('Allowed: '.implode(', ', self::$allowedCategories).' (or omit --category to reset all).');

                return;
            }

            // If user explicitly passed 'all', treat as full reset
            if (in_array('all', $normalized, true) || empty($normalized)) {
                $this->call('nntmux:resetpp'); // fall back to full reset

                return;
            }

            foreach ($normalized as $adjusted) {
                // skip 'all' since handled above
                if ($adjusted === 'all') { // @phpstan-ignore identical.alwaysFalse
                    continue;
                }
                $this->info('Resetting postprocessing for '.$adjusted.' category');
                switch ($adjusted) {
                    case 'console':
                        $this->resetConsole();
                        break;
                    case 'movie':
                        $this->resetMovies();
                        break;
                    case 'game':
                        $this->resetGames();
                        break;
                    case 'book':
                        $this->resetBooks();
                        break;
                    case 'music':
                        $this->resetMusic();
                        break;
                    case 'adult':
                        break;
                    case 'tv':
                        $this->resetTv();
                        break;
                    case 'misc':
                        $this->resetMisc();
                        break;
                }
            }
        }
    }

    /**
     * Normalize raw category options into a unique, lowercased list.
     * Handles comma-separated values, repeated options, casing, simple plurals,
     * and values provided as key=value (e.g. category=tv or the single-dash typo -category=tv).
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeCategories(array $raw): array
    {
        return ($this->useRustFfi ?? true) ? $this->normalizeCategoriesNative($raw) : $this->normalizeCategoriesPhp($raw);
    }

    /** @param array<array-key, mixed> $raw
     * @return array<array-key, mixed>
     */
    public function normalizeCategoriesNative(array $raw): array
    {
        $input = '';
        foreach ($raw as $value) {
            if (is_array($value)) {
                // Let PHP perform coercion once, including errors for nested options.
                foreach ($value as $part) {
                    if (! is_string($part)) {
                        return $this->normalizeCategoriesPhp($raw);
                    }
                }
                $value = implode(',', $value);
            }
            if (! is_string($value)) {
                return $this->normalizeCategoriesPhp($raw);
            }
            $input .= pack('V', strlen($value)).$value;
        }
        $output = ReleaseNameDisplay::transformNative(6, $input);

        return $output === null ? $this->normalizeCategoriesPhp($raw) : ($output === '' ? [] : explode("\0", $output));
    }

    /** @param array<array-key, mixed> $raw
     * @return array<array-key, mixed>
     */
    public function normalizeCategoriesPhp(array $raw): array
    {
        $normalized = collect($raw)
            ->flatMap(function ($opt) {
                $opt = is_array($opt) ? implode(',', $opt) : (string) $opt;

                return preg_split('/[\s,]+/', $opt, -1, PREG_SPLIT_NO_EMPTY);
            })
            ->map(function ($opt) {
                $opt = trim((string) $opt);
                // If the token contains '=', take the substring after the last '='
                if (str_contains($opt, '=')) {
                    $parts = explode('=', $opt);
                    $opt = end($parts);
                }
                $opt = strtolower(trim($opt));
                // normalize common plurals
                $singular = rtrim($opt, 's');
                if (in_array($singular, self::$allowedCategories, true)) {
                    return $singular;
                }

                return $opt;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $normalized;
    }

    /**
     * Return invalid categories from a normalized list.
     * Keeps 'all' as a special allowed token.
     *
     * @param  array<array-key, mixed>  $normalized
     * @return array<int, string>
     */
    private function invalidCategories(array $normalized): array
    {
        return ($this->useRustFfi ?? false) ? $this->invalidCategoriesNative($normalized) : $this->invalidCategoriesPhp($normalized);
    }

    /** @param array<array-key, mixed> $normalized
     * @return array<array-key, mixed>
     */
    public function invalidCategoriesNative(array $normalized): array
    {
        $input = '';
        foreach ($normalized as $value) {
            if (! is_string($value)) {
                return $this->invalidCategoriesPhp($normalized);
            }
            $input .= pack('V', strlen($value)).$value;
        }
        $output = ReleaseNameDisplay::transformNative(7, $input);

        return $output === null ? $this->invalidCategoriesPhp($normalized) : ($output === '' ? [] : explode("\0", $output));
    }

    /** @param array<array-key, mixed> $normalized
     * @return array<array-key, mixed>
     */
    public function invalidCategoriesPhp(array $normalized): array
    {
        return collect($normalized)
            ->reject(function ($opt) {
                return in_array($opt, self::$allowedCategories, true) || $opt === 'all';
            })
            ->values()
            ->all();
    }

    private function resetConsole(): void
    {
        $qry = Release::query()->whereNotNull('consoleinfo_id')->whereBetween('categories_id', [Category::GAME_ROOT, Category::GAME_OTHER])->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'consoleinfo_id' => null,
                    ]);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' consoleinfo_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetMovies(): void
    {
        $qry = Release::query()->whereNotNull('movieinfo_id')->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'movieinfo_id' => null,
                        'imdbid' => null,
                    ]);
                Search::updateRelease((int) $releases->id);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' movieinfo_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetGames(): void
    {
        $qry = Release::query()->whereNotNull('gamesinfo_id')->where('categories_id', '=', Category::PC_GAMES)->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'gamesinfo_id' => null,
                    ]);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' gamesinfo_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetBooks(): void
    {
        $qry = Release::query()->whereNotNull('bookinfo_id')->whereBetween('categories_id', [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN])->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'bookinfo_id' => null,
                        'book_lookup_attempts' => 0,
                        'book_lookup_retry_at' => null,
                        'book_name_normalized_at' => null,
                    ]);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' bookinfo_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetMusic(): void
    {
        $qry = Release::query()->whereNotNull('musicinfo_id')->whereBetween('categories_id', [Category::MUSIC_ROOT, Category::MUSIC_OTHER])->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'musicinfo_id' => null,
                    ]);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' musicinfo_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetTv(): void
    {
        $qry = Release::query()
            ->whereBetween('categories_id', [Category::TV_ROOT, Category::TV_OTHER])
            ->where(fn ($query) => $query->where('videos_id', '!=', 0)->orWhere('tv_episodes_id', '!=', 0)->orWhere('tv_lookup_attempts', '>', 0))
            ->get(['id']);
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(TvProcessingCandidateQuery::resetColumns());
                Search::updateRelease((int) $releases->id);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' video_id\'s reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    private function resetMisc(): void
    {
        $qry = Release::query()->whereBetween('categories_id', [Category::OTHER_ROOT, Category::OTHER_HASHED])->get();
        $total = $qry->count();
        if ($total > 0) {
            $bar = $this->output->createProgressBar($total);
            $bar->setOverwrite(true); // Terminal needs to support ANSI Encoding for this?
            $bar->start();
            foreach ($qry as $releases) {
                Release::query()->where('id', $releases->id)->update(
                    [
                        'passwordstatus' => -1,
                        'haspreview' => -1,
                        'archive_retry_at' => null,
                        'jpgstatus' => 0,
                        'videostatus' => 0,
                        'nfostatus' => -1,
                    ]);
                Search::updateRelease((int) $releases->id);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info(number_format($total).' misc releases reset.');
        } else {
            $this->info('No releases to reset');
        }
    }

    /**
     * Plans `nntmux:resetpp` for already normalized categories, as {@see handle()} applies them:
     * outside the local environment only a leading `misc` option may run; unknown categories
     * abort; `all` or an empty list resets everything; otherwise each category resets in order.
     * `reindexes_search` reports whether the run refreshes search documents.
     *
     * @param  array<array-key, mixed>  $normalized
     * @return array{action: string, invalid: list<mixed>, steps: list<string>, reindexes_search: bool}
     */
    public static function planCategoryReset(string $environment, ?string $firstOption, array $normalized): array
    {
        return (self::usesW381RustFfi() ? self::planCategoryResetNative($environment, $firstOption, $normalized) : null)
            ?? self::planCategoryResetPhp($environment, $firstOption, $normalized);
    }

    /**
     * @param  array<array-key, mixed>  $normalized
     * @return array{action: string, invalid: list<mixed>, steps: list<string>, reindexes_search: bool}
     */
    private static function planCategoryResetPhp(string $environment, ?string $firstOption, array $normalized): array
    {
        if ($environment !== 'local' && $firstOption !== 'misc') {
            return ['action' => 'refuse_environment', 'invalid' => [], 'steps' => [], 'reindexes_search' => false];
        }
        $invalid = [];
        $steps = [];
        foreach ($normalized as $category) {
            if (! in_array($category, self::$allowedCategories, true) && $category !== 'all') {
                $invalid[] = $category;
            } elseif (is_string($category) && $category !== 'all') {
                $steps[] = $category;
            }
        }
        if ($invalid !== []) {
            return ['action' => 'invalid', 'invalid' => $invalid, 'steps' => [], 'reindexes_search' => false];
        }
        if ($normalized === [] || in_array('all', $normalized, true)) {
            return ['action' => 'full', 'invalid' => [], 'steps' => [], 'reindexes_search' => true];
        }

        return ['action' => 'categories', 'invalid' => [], 'steps' => $steps, 'reindexes_search' => array_intersect($steps, self::W381_REINDEXING) !== []];
    }

    /**
     * @param  array<array-key, mixed>  $normalized
     * @return array{action: string, invalid: list<mixed>, steps: list<string>, reindexes_search: bool}|null
     */
    private static function planCategoryResetNative(string $environment, ?string $firstOption, array $normalized): ?array
    {
        $input = self::w381PackString($environment).self::w381PackString($firstOption ?? '').pack('q', count($normalized));
        foreach ($normalized as $category) {
            if (! is_string($category)) {
                return null;
            }
            $input .= self::w381PackString($category);
        }
        $output = self::w381CallRustFfi(4, $firstOption === null ? 0 : 1, $input);
        $header = self::w381UnpackInts($output === null ? null : substr($output, 0, 32), 4);
        if ($output === null || $header === null || ! isset(self::W381_ACTIONS[$header[0]]) || $header[1] & ~1 || $header[2] < 0 || $header[3] < 0) {
            return null;
        }
        $length = strlen($output);
        $offset = 32;
        $invalid = [];
        for ($i = 0; $i < $header[2]; $i++) {
            $size = $offset + 8 <= $length ? unpack('q', $output, $offset)[1] ?? -1 : -1;
            if (! is_int($size) || $size < 0 || $size > $length - $offset - 8) {
                return null;
            }
            $invalid[] = substr($output, $offset + 8, $size);
            $offset += 8 + $size;
        }
        $categories = array_values(self::$allowedCategories);
        $steps = [];
        for ($i = 0; $i < $header[3]; $i++) {
            $code = $offset + 8 <= $length ? unpack('q', $output, $offset)[1] ?? -1 : -1;
            if (! is_int($code) || ! is_string($categories[$code] ?? null)) {
                return null;
            }
            $steps[] = $categories[$code];
            $offset += 8;
        }
        if ($offset !== $length) {
            return null;
        }

        return ['action' => self::W381_ACTIONS[$header[0]], 'invalid' => $invalid, 'steps' => $steps, 'reindexes_search' => $header[1] === 1];
    }

    public static function useW381RustFfi(?bool $enabled): void
    {
        self::$w381UseRustFfi = $enabled;
    }

    public static function usesW381RustFfi(): bool
    {
        return self::$w381UseRustFfi ?? self::W381_RUST_FFI_DEFAULT;
    }

    public static function resetW381RustFfi(): void
    {
        self::$w381UseRustFfi = null;
        self::$w381Ffi = null;
    }

    private static function w381PackString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function w381UnpackInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 38.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function w381CallRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::W381_RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$w381Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w381', self::W381_FFI_CDEF) ?? false;
            if (self::$w381Ffi === false) {
                return null;
            }
            $ffi = self::$w381Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::W381_RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
