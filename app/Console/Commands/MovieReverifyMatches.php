<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\MovieService;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Console\Command;

class MovieReverifyMatches extends Command
{
    protected $signature = 'movie:reverify-matches
                            {--dry-run : Only report mismatches without changing anything}
                            {--limit= : Maximum number of releases to check}';

    protected $description = 'Re-verify movie release IMDb matches against the title/year parsed from their searchname';

    public function handle(MovieService $movieService): int
    {
        $movieService->echooutput = false;

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;

        $query = Release::query()
            ->select(['id', 'searchname', 'imdbid'])
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->whereNotNull('imdbid')
            ->where('imdbid', '<>', '')
            ->whereNotIn('imdbid', imdb_id_pending_values());

        $checked = 0;
        $skipped = 0;
        $mismatched = [];

        $process = function ($releases) use (&$checked, &$skipped, &$mismatched, $movieService, $limit): bool {
            foreach ($releases as $release) {
                if ($limit !== null && $checked >= $limit) {
                    return false;
                }

                $checked++;
                $result = $movieService->verifyReleaseMovieMatch($release->searchname, (string) $release->imdbid);

                if ($result === null) {
                    $skipped++;

                    continue;
                }

                if ($result === false) {
                    $mismatched[] = (int) $release->id;
                    $this->warn("Mismatch: release {$release->id} ({$release->searchname}) matched to tt{$release->imdbid}");
                }
            }

            return true;
        };

        if ($limit !== null) {
            $process($query->limit($limit)->get());
        } else {
            $query->chunkById(500, $process);
        }

        $this->info("Checked {$checked} movie releases, skipped {$skipped} unverifiable, found ".count($mismatched).' mismatches.');

        if ($dryRun) {
            if ($mismatched !== []) {
                $this->info('Dry run: no changes made.');
            }

            return self::SUCCESS;
        }

        foreach (array_chunk($mismatched, 100) as $chunk) {
            Release::query()->whereIn('id', $chunk)->update(['imdbid' => null, 'movieinfo_id' => null]);
            ReleaseSearchIndexSync::forIds($chunk);
        }

        if ($mismatched !== []) {
            $this->info('Reset imdbid/movieinfo_id for '.count($mismatched).' mismatched releases.');
        }

        return self::SUCCESS;
    }
}
