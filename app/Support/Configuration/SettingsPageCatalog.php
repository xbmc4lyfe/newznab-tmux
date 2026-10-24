<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Enums\ConfigurationDomain;
use App\Enums\LookupMode;
use App\Enums\RegistrationStatus;
use App\Models\IngestionConfiguration;
use App\Models\MetadataConfiguration;
use App\Models\PostProcessingConfiguration;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SettingsPageCatalog
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_settings_catalog_writable_columns_nul(const char *domain, size_t domain_len, int64_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_settings_catalog_cleanup_rules_nul(int64_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_settings_catalog_unit(const char *column, size_t column_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_settings_catalog_options_nul(const char *column, size_t column_len, int64_t *out_count, char *out_buf, size_t out_cap);
        int32_t nntmux_settings_catalog_is_integer(const char *domain, size_t domain_len, const char *column, size_t column_len);
        ssize_t nntmux_settings_catalog_help(const char *column, size_t column_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_settings_catalog_field_nul(const char *domain, size_t domain_len, const char *column, size_t column_len, int64_t *out_rules_count, int64_t *out_options_count, int32_t *out_options_int_keys, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /** @var list<string> */
    public const array MOVIE_PROCESSING_COLUMNS = ['movie_lookup', 'max_movies_processed'];

    /** @var list<string> */
    public const array VIDEO_PANE_COLUMNS = ['post_non_mode'];

    /** @var array<string, list<string>> */
    private const array COLUMNS = [
        'site' => ['title', 'home_link', 'site_logo', 'strapline', 'meta_title', 'meta_description', 'meta_keywords', 'footer', 'dereferrer_link', 'terms', 'trailers_display', 'trailers_size_x', 'trailers_size_y'],
        'registration' => ['status'],
        'ingestion' => ['binary_threads', 'backfill_threads', 'release_threads', 'collection_delay_hours', 'collection_timeout_hours', 'cross_post_hours', 'completion_percent', 'grab_status', 'max_headers_per_iteration', 'max_messages', 'max_releases_created', 'nntp_retries', 'nzb_split_level', 'part_retention_hours', 'release_retention_days', 'misc_other_retention_hours', 'misc_hashed_retention_hours', 'min_files_to_form_release', 'min_size_to_form_release', 'max_size_to_form_release', 'new_group_scan_method', 'new_group_days_to_scan', 'new_group_messages_to_scan', 'safe_backfill_date', 'disable_backfill_group', 'part_repair', 'safe_part_repair', 'max_part_repair', 'part_repair_max_tries', 'categorize_foreign', 'categorize_web_dl', 'show_passworded_releases', 'delete_passworded_releases', 'backfill_days_mode', 'backfill_order', 'backfill_quantity'],
        'post-processing' => ['post_threads', 'post_threads_amazon', 'post_threads_non', 'nfo_threads', 'fix_name_threads', 'timeout_seconds', 'release_timeout_seconds', 'max_timeout_count', 'max_additional_processed', 'max_parts_processed', 'password_check_attempts', 'fix_names_per_run', 'max_nested_levels', 'extract_using_rar_info', 'segments_to_download', 'ffmpeg_duration', 'inner_file_blacklist', 'process_jpg', 'process_thumbnails', 'process_videos', 'save_audio_preview', 'min_size_to_post_process', 'max_size_to_post_process', 'min_size_to_process_nfo', 'max_size_to_process_nfo', 'max_nfo_processed', 'max_nfo_retries', 'lookup_nfo', 'lookup_par2'],
        'metadata' => ['anime_lookup', 'book_lookup', 'game_lookup', 'movie_lookup', 'music_lookup', 'tv_lookup', 'movie_language', 'imdb_alternate_url', 'max_anime_processed', 'max_books_processed', 'max_games_processed', 'max_movies_processed', 'max_music_processed', 'max_tv_processed', 'amazon_sleep_milliseconds'],
        'tmux' => ['session_name', 'monitor_delay', 'niceness', 'sequential_mode', 'sequential_timer', 'binaries_enabled', 'binaries_timer', 'binaries_kill_timer', 'backfill_mode', 'backfill_groups', 'backfill_timer', 'progressive_backfill', 'releases_enabled', 'release_timer', 'post_mode', 'post_timer', 'post_kill_timer', 'post_amazon_mode', 'post_amazon_timer', 'post_non_mode', 'post_non_timer', 'fix_names_enabled', 'fix_timer', 'cleanup_mode', 'cleanup_timer', 'run_irc_scraper', 'console_enabled', 'htop_enabled', 'mytop_enabled', 'nmon_enabled', 'vnstat_enabled', 'vnstat_args', 'tcp_track_enabled', 'tcp_track_args', 'bwmng_enabled', 'redis_enabled', 'redis_args', 'write_logs', 'collections_kill_threshold', 'post_process_kill_threshold', 'colors_start', 'colors_end', 'cleanup_rules', 'color_exclusions'],
    ];

    /** @var list<string> */
    private const array BOOLEANS = ['trailers_display', 'grab_status', 'disable_backfill_group', 'part_repair', 'safe_part_repair', 'categorize_foreign', 'categorize_web_dl', 'show_passworded_releases', 'delete_passworded_releases', 'extract_using_rar_info', 'process_jpg', 'process_thumbnails', 'process_videos', 'save_audio_preview', 'lookup_nfo', 'lookup_par2', 'imdb_alternate_url', 'binaries_enabled', 'progressive_backfill', 'releases_enabled', 'fix_names_enabled', 'run_irc_scraper', 'console_enabled', 'htop_enabled', 'mytop_enabled', 'nmon_enabled', 'vnstat_enabled', 'tcp_track_enabled', 'bwmng_enabled', 'redis_enabled', 'write_logs'];

    /** @var list<string> */
    private const array BYTES = ['min_size_to_form_release', 'max_size_to_form_release', 'min_size_to_post_process', 'max_size_to_post_process', 'min_size_to_process_nfo', 'max_size_to_process_nfo'];

    /** @var list<string> */
    private const array TEXTAREAS = ['meta_description', 'meta_keywords', 'footer', 'terms'];

    /** @var list<string> */
    private const array NULLABLE = ['site_logo', 'vnstat_args', 'tcp_track_args', 'redis_args'];

    /** @var list<string> */
    private const array POSITIVE_INTEGERS = [
        'trailers_size_x', 'trailers_size_y', 'binary_threads', 'backfill_threads', 'release_threads',
        'collection_timeout_hours', 'max_headers_per_iteration', 'max_messages', 'max_releases_created',
        'backfill_quantity', 'post_threads', 'post_threads_amazon', 'post_threads_non', 'nfo_threads',
        'fix_name_threads', 'release_timeout_seconds', 'max_timeout_count', 'max_additional_processed',
        'max_parts_processed', 'password_check_attempts', 'fix_names_per_run', 'max_nested_levels',
        'segments_to_download', 'ffmpeg_duration', 'max_nfo_processed', 'max_anime_processed',
        'max_books_processed', 'max_games_processed', 'max_movies_processed', 'max_music_processed',
        'max_tv_processed', 'monitor_delay', 'sequential_timer', 'binaries_timer', 'backfill_groups',
        'backfill_timer', 'release_timer', 'post_timer', 'post_amazon_timer', 'post_non_timer',
        'fix_timer', 'cleanup_timer',
    ];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /** @return list<ConfigurationField> */
    public function fields(ConfigurationDomain $domain): array
    {
        return array_map(fn (string $column): ConfigurationField => $this->field($domain, $column), self::COLUMNS[$domain->value]);
    }

    /** @return list<ConfigurationField> */
    public function fieldsForPage(ConfigurationDomain $domain): array
    {
        $fields = $this->fields($domain);

        if ($domain === ConfigurationDomain::PostProcessing) {
            return [
                ...array_map(fn (string $column): ConfigurationField => $this->field(ConfigurationDomain::Tmux, $column), self::VIDEO_PANE_COLUMNS),
                ...array_map(fn (string $column): ConfigurationField => $this->field(ConfigurationDomain::Metadata, $column), self::MOVIE_PROCESSING_COLUMNS),
                ...$fields,
            ];
        }

        return $fields;
    }

    /** @return list<string> */
    public function writableColumns(ConfigurationDomain $domain): array
    {
        return self::$useRustFfi
            ? $this->writableColumnsNative($domain)
            : $this->writableColumnsPhp($domain);
    }

    /** @return list<string> */
    public function writableColumnsPhp(ConfigurationDomain $domain): array
    {
        return array_values(array_filter(
            self::COLUMNS[$domain->value],
            static fn (string $column): bool => ! in_array($column, ['cleanup_rules', 'color_exclusions'], true),
        ));
    }

    /** @return list<string> */
    public function writableColumnsNative(ConfigurationDomain $domain): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->writableColumnsPhp($domain);
        }

        $domStr = $domain->value;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 2048, $cap);
        if ($scratch === null) {
            return $this->writableColumnsPhp($domain);
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_writable_columns_nul(
            $domStr,
            \strlen($domStr),
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->writableColumnsPhp($domain);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_settings_catalog_writable_columns_nul(
                $domStr,
                \strlen($domStr),
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return $this->writableColumnsPhp($domain);
        }

        /** @var int $expectedCount */
        $expectedCount = $outCount[0];
        $cols = explode("\0", FFI::string($scratch, $written));

        return \count($cols) === $expectedCount ? $cols : $this->writableColumnsPhp($domain);
    }

    /** @return class-string<Model> */
    public function modelClass(ConfigurationDomain $domain): string
    {
        return match ($domain) {
            ConfigurationDomain::Site => SiteConfiguration::class,
            ConfigurationDomain::Registration => RegistrationConfiguration::class,
            ConfigurationDomain::Ingestion => IngestionConfiguration::class,
            ConfigurationDomain::PostProcessing => PostProcessingConfiguration::class,
            ConfigurationDomain::Metadata => MetadataConfiguration::class,
            ConfigurationDomain::Tmux => TmuxConfiguration::class,
        };
    }

    public function field(ConfigurationDomain $domain, string $column): ConfigurationField
    {
        return self::$useRustFfi
            ? $this->fieldNative($domain, $column)
            : $this->fieldPhp($domain, $column);
    }

    public function fieldPhp(ConfigurationDomain $domain, string $column): ConfigurationField
    {
        $label = match ($column) {
            'movie_lookup' => 'Process Movies',
            'post_non_mode' => 'TV, Anime and Movie Panes',
            'post_amazon_mode' => 'Book, Music, Console and Game Panes',
            'extract_using_rar_info' => 'List Archives Without Extracting (Disables Media Extraction)',
            default => Str::headline($column),
        };
        $guidance = match ($domain) {
            ConfigurationDomain::Site, ConfigurationDomain::Registration, ConfigurationDomain::Metadata => 'Takes effect immediately.',
            ConfigurationDomain::Tmux => 'The monitor reloads this value on its next cycle; layout changes require a tmux restart.',
            default => 'Restart active workers after changing this value.',
        };

        if ($column === 'site_logo') {
            return new ConfigurationField($column, 'Site Logo', 'PNG, JPEG, or WebP image, up to 2 MB.', 'upload', ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'], guidance: $guidance);
        }

        if ($column === 'cleanup_rules') {
            $options = array_combine(self::cleanupRulesPhp(), array_map(Str::headline(...), self::cleanupRulesPhp()));

            return new ConfigurationField($column, 'Cleanup Rules', 'Select release cleanup checks to run.', 'multiselect', ['nullable', 'array'], $options, guidance: $guidance);
        }

        if ($column === 'color_exclusions') {
            return new ConfigurationField($column, 'Color Exclusions', 'Comma-separated tmux color indexes from 0 through 255.', 'number-list', ['nullable', 'string'], guidance: $guidance);
        }

        $options = $this->optionsPhp($column);
        if ($options !== []) {
            return new ConfigurationField($column, $label, $this->helpPhp($column), 'enum', ['required', 'integer', 'in:'.implode(',', array_keys($options))], $options, guidance: $guidance);
        }

        if (in_array($column, self::BOOLEANS, true)) {
            return new ConfigurationField($column, $label, $this->helpPhp($column), 'boolean', ['required', 'boolean'], [1 => 'Enabled', 0 => 'Disabled'], guidance: $guidance);
        }

        if (in_array($column, self::BYTES, true)) {
            return new ConfigurationField($column, $label, $this->helpPhp($column), 'bytes', ['required', 'numeric', 'min:0'], unit: 'MB/GB', guidance: $guidance);
        }

        if ($column === 'safe_backfill_date') {
            return new ConfigurationField($column, $label, 'Oldest date allowed for safe backfill.', 'date', ['required', 'date_format:Y-m-d'], guidance: $guidance);
        }

        if ($column === 'inner_file_blacklist') {
            return new ConfigurationField($column, $label, 'Regular expression used to reject unsafe inner filenames.', 'regex', ['required', 'string', 'max:2000'], guidance: $guidance);
        }

        if (in_array($column, self::TEXTAREAS, true)) {
            $rules = $column === 'terms' ? ['nullable', 'string', 'max:65000'] : ['required', 'string', 'max:65000'];

            return new ConfigurationField($column, $label, $this->helpPhp($column), $column === 'terms' ? 'rich-text' : 'textarea', $rules, guidance: $guidance);
        }

        if ($this->isIntegerPhp($domain, $column)) {
            $minimum = $column === 'niceness' ? -20 : (in_array($column, self::POSITIVE_INTEGERS, true) ? 1 : 0);
            $maximum = match ($column) {
                'completion_percent' => 100,
                'niceness' => 19,
                'colors_start', 'colors_end' => 255,
                default => null,
            };
            $rules = ['required', 'integer', 'min:'.$minimum];
            if ($maximum !== null) {
                $rules[] = 'max:'.$maximum;
            }

            return new ConfigurationField($column, $label, $this->helpPhp($column), 'integer', $rules, unit: $this->unitPhp($column), guidance: $guidance);
        }

        $rules = in_array($column, [...self::NULLABLE, 'dereferrer_link'], true) ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'];
        $control = $column === 'dereferrer_link' ? 'url' : 'text';

        return new ConfigurationField($column, $label, $this->helpPhp($column), $control, $rules, guidance: $guidance);
    }

    public function fieldNative(ConfigurationDomain $domain, string $column): ConfigurationField
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->fieldPhp($domain, $column);
        }

        $domStr = $domain->value;
        $domLen = \strlen($domStr);
        $colLen = \strlen($column);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(1024, $colLen * 4 + 512), $cap);
        if ($scratch === null) {
            return $this->fieldPhp($domain, $column);
        }

        $outRulesCount = $ffi->new('int64_t[1]');
        $outOptsCount = $ffi->new('int64_t[1]');
        $outIntKeys = $ffi->new('int32_t[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_field_nul(
            $domStr,
            $domLen,
            $column,
            $colLen,
            $outRulesCount,
            $outOptsCount,
            $outIntKeys,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->fieldPhp($domain, $column);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_settings_catalog_field_nul(
                $domStr,
                $domLen,
                $column,
                $colLen,
                $outRulesCount,
                $outOptsCount,
                $outIntKeys,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return $this->fieldPhp($domain, $column);
        }

        /** @var int $rulesCount */
        $rulesCount = $outRulesCount[0];
        /** @var int $optsCount */
        $optsCount = $outOptsCount[0];
        /** @var int $intKeys */
        $intKeys = $outIntKeys[0];

        $segs = explode("\0", FFI::string($scratch, $written));
        if (\count($segs) !== 5 + $rulesCount + $optsCount * 2) {
            return $this->fieldPhp($domain, $column);
        }

        /** @var list<string> $rules */
        $rules = array_slice($segs, 5, $rulesCount);
        $options = [];
        $optBase = 5 + $rulesCount;
        for ($i = 0; $i < $optsCount; $i++) {
            $k = $segs[$optBase + $i * 2];
            $v = $segs[$optBase + $i * 2 + 1];
            if ($intKeys === 1) {
                $options[(int) $k] = $v;
            } else {
                $options[$k] = $v;
            }
        }

        $unit = $segs[3] === '' ? null : $segs[3];

        return new ConfigurationField(
            $column,
            $segs[0],
            $segs[1],
            $segs[2],
            $rules,
            $options,
            unit: $unit,
            guidance: $segs[4],
        );
    }

    /** @return array<int, string> */
    public function options(string $column): array
    {
        return self::$useRustFfi
            ? $this->optionsNative($column)
            : $this->optionsPhp($column);
    }

    /** @return array<int, string> */
    public function optionsPhp(string $column): array
    {
        if ($column === 'status') {
            return array_column(array_map(static fn (RegistrationStatus $status): array => ['value' => $status->value, 'label' => $status->label()], RegistrationStatus::cases()), 'label', 'value');
        }

        if (str_ends_with($column, '_lookup')) {
            return array_column(array_map(static fn (LookupMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()], LookupMode::cases()), 'label', 'value');
        }

        return match ($column) {
            'new_group_scan_method' => [0 => 'Posts', 1 => 'Days'],
            'backfill_days_mode' => [1 => 'Days per group', 2 => 'Safe backfill date'],
            'backfill_order' => [1 => 'Newest', 2 => 'Oldest', 3 => 'Alphabetical', 4 => 'Alphabetical reverse', 5 => 'Most posts', 6 => 'Fewest posts'],
            'sequential_mode' => [0 => 'Full', 1 => 'Basic', 2 => 'Stripped'],
            'backfill_mode' => [0 => 'Disabled', 1 => 'All', 4 => 'Safe'],
            'post_non_mode', 'post_amazon_mode' => [0 => 'Disabled', 1 => 'Enabled'],
            'post_mode' => [0 => 'Disabled', 1 => 'Additional', 2 => 'NFO', 3 => 'All'],
            default => [],
        };
    }

    /** @return array<int, string> */
    public function optionsNative(string $column): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->optionsPhp($column);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 512, $cap);
        if ($scratch === null) {
            return $this->optionsPhp($column);
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_options_nul(
            $column,
            \strlen($column),
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->optionsPhp($column);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_settings_catalog_options_nul(
                $column,
                \strlen($column),
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written < 0) {
            return $this->optionsPhp($column);
        }
        if ($written === 0) {
            return [];
        }

        /** @var int $pairCount */
        $pairCount = $outCount[0];
        $segs = explode("\0", FFI::string($scratch, $written));
        if (\count($segs) !== $pairCount * 2) {
            return $this->optionsPhp($column);
        }

        $out = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $out[(int) $segs[$i * 2]] = $segs[$i * 2 + 1];
        }

        return $out;
    }

    public function isInteger(ConfigurationDomain $domain, string $column): bool
    {
        return self::$useRustFfi
            ? $this->isIntegerNative($domain, $column)
            : $this->isIntegerPhp($domain, $column);
    }

    public function isIntegerPhp(ConfigurationDomain $domain, string $column): bool
    {
        return ! in_array($column, [...self::BOOLEANS, ...self::BYTES, ...self::TEXTAREAS, ...self::NULLABLE, 'safe_backfill_date', 'inner_file_blacklist', 'title', 'home_link', 'strapline', 'meta_title', 'dereferrer_link', 'movie_language', 'session_name', 'cleanup_mode'], true)
            && $this->optionsPhp($column) === [];
    }

    public function isIntegerNative(ConfigurationDomain $domain, string $column): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isIntegerPhp($domain, $column);
        }

        $domStr = $domain->value;
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_settings_catalog_is_integer(
            $domStr,
            \strlen($domStr),
            $column,
            \strlen($column),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->isIntegerPhp($domain, $column);
        }

        return $res === 1;
    }

    public function help(string $column): string
    {
        return self::$useRustFfi
            ? $this->helpNative($column)
            : $this->helpPhp($column);
    }

    public function helpPhp(string $column): string
    {
        return match ($column) {
            'home_link' => 'Home path or absolute URL used by site navigation.',
            'dereferrer_link' => 'Optional URL prefix placed before outbound metadata links.',
            'completion_percent' => 'Minimum completion percentage retained during release processing.',
            'monitor_delay' => 'Seconds between tmux monitor refresh cycles.',
            'movie_language' => 'ISO language code used for movie metadata lookup.',
            'movie_lookup' => 'Choose which releases receive movie metadata processing.',
            'max_movies_processed' => 'Maximum movies processed per run.',
            'post_non_mode' => 'Enable the tmux TV, anime and movie panes. Movie Lookup must also be enabled for the Movies pane to process releases.',
            'post_amazon_mode' => 'Enable the tmux book, music, console and game panes. Each metadata lookup must also be enabled.',
            'extract_using_rar_info' => 'Use the built-in PHP RAR/ZIP header parser only. Lists inner files without running unrar/7z, which disables media extraction (mediainfo, samples, and previews).',
            default => 'Controls '.Str::lower(Str::headline($column)).'.',
        };
    }

    public function helpNative(string $column): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->helpPhp($column);
        }

        $len = \strlen($column);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 64), $cap);
        if ($scratch === null) {
            return $this->helpPhp($column);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_help($column, $len, $scratch, $cap);
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->helpPhp($column);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_settings_catalog_help($column, $len, $scratch, $cap);
        }
        if (! \is_int($written) || $written <= 0) {
            return $this->helpPhp($column);
        }

        return FFI::string($scratch, $written);
    }

    public function unit(string $column): ?string
    {
        return self::$useRustFfi
            ? $this->unitNative($column)
            : $this->unitPhp($column);
    }

    public function unitPhp(string $column): ?string
    {
        return match (true) {
            str_ends_with($column, '_seconds') => 'seconds',
            str_ends_with($column, '_hours') => 'hours',
            str_ends_with($column, '_days') => 'days',
            str_ends_with($column, '_milliseconds') => 'milliseconds',
            str_ends_with($column, '_timer'), $column === 'monitor_delay' => 'seconds',
            default => null,
        };
    }

    public function unitNative(string $column): ?string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->unitPhp($column);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return $this->unitPhp($column);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_unit($column, \strlen($column), $scratch, $cap);
        if (! \is_int($written) || $written < 0) {
            return $this->unitPhp($column);
        }

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /** @return list<string> */
    public static function cleanupRules(): array
    {
        return self::$useRustFfi
            ? self::cleanupRulesNative()
            : self::cleanupRulesPhp();
    }

    /** @return list<string> */
    public static function cleanupRulesPhp(): array
    {
        return ['blacklist', 'blfiles', 'executable', 'gibberish', 'hashed', 'installbin', 'passworded', 'passwordurl', 'sample', 'scr', 'short', 'size', 'nzb', 'codec'];
    }

    /** @return list<string> */
    public static function cleanupRulesNative(): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::cleanupRulesPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::cleanupRulesPhp();
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_settings_catalog_cleanup_rules_nul(
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::cleanupRulesPhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_settings_catalog_cleanup_rules_nul(
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return self::cleanupRulesPhp();
        }

        /** @var int $expectedCount */
        $expectedCount = $outCount[0];
        $rules = explode("\0", FFI::string($scratch, $written));

        return \count($rules) === $expectedCount ? $rules : self::cleanupRulesPhp();
    }
}
