<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class RecategorizeReleases extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_cat_rgb_cmd_parse_ints(const char *csv_ptr, size_t csv_len, int64_t *out_vals, size_t out_cap);
        ssize_t nntmux_cat_rgb_cmd_parse_names(const char *csv_ptr, size_t csv_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_cat_rgb_cmd_reset_payload(char *out_buf, size_t out_cap);
        ssize_t nntmux_cat_rgb_cmd_category_display(uint8_t title_present, const char *title_ptr, size_t title_len, uint8_t parent_present, const char *parent_ptr, size_t parent_len, char *out_buf, size_t out_cap);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:recategorize-releases
    {--misc : Re-categorize all releases in misc categories}
    {--all : Re-categorize all releases}
    {--test : Test only, no updates}
    {--group= : Re-categorize all releases in a group}
    {--groups= : Re-categorize all releases in a list of groups}
    {--category= : Re-categorize all releases in a category}
    {--categories= : Re-categorize all releases in a list of categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-categorize releases based on their name and group.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $countQuery = Release::query();
        if ($this->option('misc')) {
            $countQuery->whereIn('categories_id', Category::OTHERS_GROUP);
        } elseif ($this->option('all')) {
            if ($this->confirm('This will reset categorization on all releases and re-categorize them all from scratch. Are you sure? (y/n)', false)) {
                Release::query()->where('iscategorized', 1)->update([
                    'iscategorized' => 0,
                ]);
                $countQuery->where('iscategorized', 0);
            } else {
                $this->info('Reset script stopped.');
                exit();
            }

        } elseif ($this->option('group')) {
            $countQuery->where('groups_id', $this->option('group'));
        } elseif ($this->option('groups')) {
            $countQuery->whereIn('groups_id', self::parseCommaSeparatedInts((string) $this->option('groups')));
        } elseif ($this->option('category')) {
            $countQuery->where('categories_id', $this->option('category'));
        } elseif ($this->option('categories')) {
            $countQuery->whereIn('categories_id', self::parseCommaSeparatedInts((string) $this->option('categories')));
        } elseif ($this->option('test')) {
            $countQuery->where('iscategorized', 0);
        } else {
            $this->error('You must specify at least one option. See: --help');
            exit();
        }

        $count = $countQuery->count();

        $cat = new CategorizationService;
        $results = $countQuery->select(['id', 'searchname', 'fromname', 'groups_id', 'categories_id'])->get();
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        foreach ($results as $result) {
            $bar->advance();
            $catId = $cat->determineCategory($result->groups_id, $result->searchname, $result->fromname);
            if ((int) $result->categories_id !== (int) $catId['categories_id']) {
                if ($this->option('test')) {
                    $this->info('Would have changed '.$result->searchname.' from '.$result->categories_id.' to '.$catId['categories_id']);
                } else {
                    Release::query()->where('id', $result->id)->update([
                        'iscategorized' => 1,
                        ...TvProcessingCandidateQuery::resetColumns(),
                        'imdbid' => null,
                        'musicinfo_id' => null,
                        'consoleinfo_id' => null,
                        'gamesinfo_id' => 0,
                        'bookinfo_id' => 0,
                        'anidbid' => null,
                        'categories_id' => $catId['categories_id'],
                    ]);

                    Search::updateRelease((int) $result->id);

                    /** @var Category|null $newCatName */
                    $newCatName = Category::query()->where('id', $catId['categories_id'])->first();

                    $this->line('');
                    $this->output->writeln('<fg=yellow>ID       :</> '.$result->id);
                    $this->output->writeln('<fg=green>Release  :</> '.$result->searchname);
                    $this->output->writeln('<fg=cyan>Group    :</> '.$result->group->name);
                    $oldCategoryTitle = self::formatCategoryDisplayName($result->category?->title, $result->category?->parent?->title);

                    $newCategoryTitle = self::formatCategoryDisplayName($newCatName?->title, $newCatName?->parent?->title);
                    $this->output->writeln('<fg=white>Category :</> '.$oldCategoryTitle.' <fg=yellow>→</> <fg=magenta>'.$newCategoryTitle.'</>');
                    $this->line('');
                }
            }
        }
        $bar->finish();
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * `--groups`/`--categories` IDs.
     *
     * @return list<int>
     */
    public static function parseCommaSeparatedInts(string $csv): array
    {
        return self::$useRustFfi ? self::parseCommaSeparatedIntsNative($csv) : self::parseCommaSeparatedIntsPhp($csv);
    }

    /**
     * @return list<int>
     */
    public static function parseCommaSeparatedIntsPhp(string $csv): array
    {
        return array_map('intval', explode(',', $csv));
    }

    /**
     * @return list<int>
     */
    public static function parseCommaSeparatedIntsNative(string $csv): array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::parseCommaSeparatedIntsPhp($csv);
        }

        try {
            $count = substr_count($csv, ',') + 1;
            $values = $ffi->new("int64_t[{$count}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cat_rgb_cmd_parse_ints($csv, \strlen($csv), $values, $count);
            if ($written !== $count) {
                return self::parseCommaSeparatedIntsPhp($csv);
            }
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $result[] = (int) $values[$i];
            }

            return $result;
        } catch (Throwable) {
            return self::parseCommaSeparatedIntsPhp($csv);
        }
    }

    /**
     * Comma-separated group names with PHP `trim()` whitespace removed.
     *
     * @return list<string>
     */
    public static function parseCommaSeparatedGroupNames(string $csv): array
    {
        return self::$useRustFfi ? self::parseCommaSeparatedGroupNamesNative($csv) : self::parseCommaSeparatedGroupNamesPhp($csv);
    }

    /**
     * @return list<string>
     */
    public static function parseCommaSeparatedGroupNamesPhp(string $csv): array
    {
        return array_map('trim', explode(',', $csv));
    }

    /**
     * @return list<string>
     */
    public static function parseCommaSeparatedGroupNamesNative(string $csv): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::parseCommaSeparatedGroupNamesPhp($csv);
        }

        $joined = self::callForString($ffi, 'nntmux_cat_rgb_cmd_parse_names', \strlen($csv), $csv, \strlen($csv));
        $names = $joined === null ? [] : explode(',', $joined);

        return \count($names) === substr_count($csv, ',') + 1 ? $names : self::parseCommaSeparatedGroupNamesPhp($csv);
    }

    /**
     * Columns reset before a release is categorized again from scratch.
     *
     * @return array<string, int|null>
     */
    public static function buildResetPayload(): array
    {
        return self::$useRustFfi ? self::buildResetPayloadNative() : self::buildResetPayloadPhp();
    }

    /**
     * @return array<string, int|null>
     */
    public static function buildResetPayloadPhp(): array
    {
        return [
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'imdbid' => null,
            'musicinfo_id' => null,
            'consoleinfo_id' => null,
            'gamesinfo_id' => 0,
            'bookinfo_id' => null,
            'anidbid' => null,
            'movieinfo_id' => null,
            'iscategorized' => 0,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public static function buildResetPayloadNative(): array
    {
        $ffi = self::ffi();
        $encoded = $ffi === null ? null : self::callForString($ffi, 'nntmux_cat_rgb_cmd_reset_payload', 256);
        if ($encoded === null) {
            return self::buildResetPayloadPhp();
        }

        $payload = [];
        foreach (explode("\n", $encoded) as $line) {
            $parts = explode('=', $line, 2);
            if (\count($parts) !== 2 || $parts[0] === '') {
                return self::buildResetPayloadPhp();
            }
            [$key, $value] = $parts;
            if ($value === 'n') {
                $payload[$key] = null;
            } elseif (preg_match('/^i(-?\d+)$/D', $value, $matches) === 1) {
                $payload[$key] = (int) $matches[1];
            } else {
                return self::buildResetPayloadPhp();
            }
        }

        return $payload;
    }

    /**
     * `Parent -> Child`, the bare title without a parent title, or `N/A` without a category.
     */
    public static function formatCategoryDisplayName(?string $title, ?string $parentTitle): string
    {
        return self::$useRustFfi ? self::formatCategoryDisplayNameNative($title, $parentTitle) : self::formatCategoryDisplayNamePhp($title, $parentTitle);
    }

    public static function formatCategoryDisplayNamePhp(?string $title, ?string $parentTitle): string
    {
        if ($title === null) {
            return 'N/A';
        }

        return $parentTitle !== null && $parentTitle !== '' ? "{$parentTitle} -> {$title}" : $title;
    }

    public static function formatCategoryDisplayNameNative(?string $title, ?string $parentTitle): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatCategoryDisplayNamePhp($title, $parentTitle);
        }

        $child = $title ?? '';
        $parent = $parentTitle ?? '';

        return self::callForString(
            $ffi,
            'nntmux_cat_rgb_cmd_category_display',
            \strlen($child) + \strlen($parent) + 8,
            $title === null ? 0 : 1, $child, \strlen($child), $parentTitle === null ? 0 : 1, $parent, \strlen($parent),
        ) ?? self::formatCategoryDisplayNamePhp($title, $parentTitle);
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w30-3-recategorize-releases', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
