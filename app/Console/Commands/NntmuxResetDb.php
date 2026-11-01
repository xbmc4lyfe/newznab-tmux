<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\UsenetGroup;
use App\Services\Releases\Verification\VerificationResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class NntmuxResetDb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:resetdb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will reset your database to blank state (will retain settings)';

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
     * @throws \Exception
     */
    public function handle(VerificationResetService $verification): int
    {
        // Allow database reset only if app environment is local
        if (app()->environment() !== 'local') {
            $this->error('This command can only be run in local environment');

            return self::SUCCESS;
        }
        if ($this->confirm('This command removes all releases, nzb files, samples, previews , nfos, truncates all article tables and resets all groups. Are you sure you want reset the DB?')) {
            try {
                $verification->run(fn () => $this->resetDatabase());
            } catch (Throwable $error) {
                $this->error('Database reset stopped: '.$error->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->info('Script execution stopped');
        }

        return self::SUCCESS;
    }

    private function resetDatabase(): void
    {
        $timestart = now();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        try {

            UsenetGroup::query()->update([
                'first_record' => 0,
                'first_record_postdate' => null,
                'last_record' => 0,
                'last_record_postdate' => null,
                'last_updated' => null,
            ]);
            $this->info('Reseting all groups completed.');

            $arr = [
                'binaries',
                'collections',
                'collection_groups',
                'parts',
                'missed_parts',
                'videos',
                'videos_aliases',
                'tv_episodes',
                'tv_info',
                'release_nfos',
                'nfo_cleanup_commits',
                'release_comments',
                'users_releases',
                'user_movies',
                'user_series',
                'movieinfo',
                'musicinfo',
                'bookinfo',
                'consoleinfo',
                'gamesinfo',
                'release_files',
                'audio_data',
                'release_subtitles',
                'video_data',
                'media_infos',
                'par_hashes',
                'par_file_hashes',
                'par_file_hash_inventories',
                'par2_descriptor_evidence',
                'par2_hash_retry',
                'par2_rename_candidate_progress',
                'par2_rename_progress',
                'release_regexes',
                'release_unique',
                'release_reports',
                'release_nzb_creation_failures',
                'release_nzb_passwords',
                'releases',
                'anidb_titles',
                'anidb_info',
                'releases_groups',
            ];
            foreach ($arr as &$value) {
                DB::statement("TRUNCATE TABLE $value");
                $this->info('Truncating '.$value.' completed.');
            }
            unset($value);

            Search::truncateIndex([
                'releases_rt', 'movies_rt', 'tvshows_rt',
                'music_rt', 'books_rt', 'games_rt', 'console_rt', 'steam_rt', 'anime_rt',
            ]);

            $this->info('Deleting nzbfiles subfolders.');
            $files = File::allFiles(config('nntmux_settings.path_to_nzbs'));
            File::delete($files);

            $this->info('Deleting all images, previews and samples that still remain.');

            $files = File::allFiles(storage_path('covers/'));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (basename($path) !== '.gitignore' && basename($path) !== 'no-cover.jpg' && basename($path) !== 'no-backdrop.jpg') {
                    File::delete($path);
                }
            }

            $this->call('cache:clear');

            $this->info('Deleted all releases, images, previews and samples. This script finished '.now()->diffForHumans($timestart).' start');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }
}
