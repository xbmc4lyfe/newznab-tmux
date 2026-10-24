<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxPopulateAniDB;
use App\Console\Commands\NntmuxPopulateSteamApps;
use App\Console\Commands\NntmuxResetTvShowPostProcessing;
use App\Console\Commands\PostProcessTvPipeline;
use App\Console\Commands\UpdatePostProcess;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Paired randomized benchmark evidence (`NNTMUX_POSTPROCESS_TV_ANIDB_STEAM_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib, PHP 8.5):
 *
 * - Round 1: PHP median = 0.137 ms (max 0.167), Rust FFI median = 0.801 ms (max 1.168), 0.17x
 * - Round 2: PHP median = 0.144 ms (max 0.206), Rust FFI median = 0.843 ms (max 1.395), 0.17x
 * - Round 3: PHP median = 0.139 ms (max 0.161), Rust FFI median = 0.790 ms (max 1.265), 0.18x
 * - Per-call FFI marshaling (scratch buffers, CData out-params) dominates these sub-microsecond
 *   helpers, so PHP stays the production default (`usesRustFfi() === false`) for all five
 *   commands: the 1.5x threshold is not met on the median or the tail.
 */
class RustPostProcessTvAniDbSteamCommandsParityTest extends TestCase
{
    private const CLASSES = [
        UpdatePostProcess::class,
        PostProcessTvPipeline::class,
        NntmuxResetTvShowPostProcessing::class,
        NntmuxPopulateAniDB::class,
        NntmuxPopulateSteamApps::class,
    ];

    private const UPDATE_TYPES = [
        'additional' => false,
        'all' => true,
        'amazon' => false,
        'anime' => false,
        'book' => false,
        'console' => false,
        'games' => false,
        'movies' => false,
        'music' => false,
        'nfo' => true,
        'tv' => false,
    ];

    private const DIRECT_CDEF = <<<'CDEF'
    int32_t nntmux_update_postprocess_type_flags(const char *type_ptr, size_t type_len);
    ssize_t nntmux_update_postprocess_format_invalid_type(const char *type_ptr, size_t type_len, char *out_buf, size_t out_cap);
    int32_t nntmux_postprocess_tv_pipeline_is_valid_hex_char(const char *value_ptr, size_t value_len);
    ssize_t nntmux_postprocess_tv_pipeline_normalize_renamed(uint32_t has_renamed, const char *renamed_ptr, size_t renamed_len, char *out_buf, size_t out_cap);
    int32_t nntmux_postprocess_tv_pipeline_should_warn_mode(uint32_t mode_is_null);
    ssize_t nntmux_reset_tv_show_format_video_not_found(int64_t video_id, char *out_buf, size_t out_cap);
    ssize_t nntmux_reset_tv_show_format_dry_run(int64_t total, const char *title_ptr, size_t title_len, int64_t video_id, char *out_buf, size_t out_cap);
    ssize_t nntmux_reset_tv_show_format_resetting(const char *title_ptr, size_t title_len, int64_t video_id, char *out_buf, size_t out_cap);
    ssize_t nntmux_reset_tv_show_format_reset_complete(int64_t updated, char *out_buf, size_t out_cap);
    int32_t nntmux_populate_anidb_resolve_mode(uint32_t info, uint32_t kind, int64_t int_value, double float_value, const char *str_ptr, size_t str_len, uint32_t *out_has_id, int64_t *out_id);
    int32_t nntmux_populate_steam_apps_should_report_progress(int64_t processed);
    ssize_t nntmux_populate_steam_apps_format_progress(int64_t processed, int64_t total, char *out_buf, size_t out_cap);
    ssize_t nntmux_populate_steam_apps_format_summary(int64_t inserted, int64_t dupe, int64_t errors, char *out_buf, size_t out_cap);
    CDEF;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP implementation");

            $class::useRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function updateTypeProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::UPDATE_TYPES) as $type) {
            $cases[$type] = [$type];
        }

        return $cases + [
            'empty' => [''],
            'upper' => ['ALL'],
            'mixed_case' => ['Tv'],
            'leading_space' => [' nfo'],
            'trailing_nul' => ["nfo\0"],
            'embedded_nul' => ["n\0fo"],
            'zero' => ['0'],
            'one' => ['1'],
            'combining_accent' => ["tv\u{0301}"],
            'utf8' => ['фильмы 🎬'],
            'invalid_utf8' => ["\xff\xfe"],
            'unknown' => ['xxx'],
        ];
    }

    #[DataProvider('updateTypeProvider')]
    public function test_update_postprocess_type_helpers_parity(string $type): void
    {
        $this->requireMetadataFfi();

        $valid = array_key_exists($type, self::UPDATE_TYPES);
        $nntp = self::UPDATE_TYPES[$type] ?? false;
        $message = "Invalid type: {$type}";

        $this->assertSame($valid, UpdatePostProcess::isValidTypePhp($type));
        $this->assertSame($valid, UpdatePostProcess::isValidTypeNative($type));
        $this->assertSame($nntp, UpdatePostProcess::requiresNntpConnectionPhp($type));
        $this->assertSame($nntp, UpdatePostProcess::requiresNntpConnectionNative($type));
        $this->assertSame($message, UpdatePostProcess::formatInvalidTypeMessagePhp($type));
        $this->assertSame($message, UpdatePostProcess::formatInvalidTypeMessageNative($type));

        foreach ([false, true] as $useRust) {
            UpdatePostProcess::setUseRustFfi($useRust);
            $this->assertSame($valid, UpdatePostProcess::isValidType($type));
            $this->assertSame($nntp, UpdatePostProcess::requiresNntpConnection($type));
            $this->assertSame($message, UpdatePostProcess::formatInvalidTypeMessage($type));
        }
    }

    public function test_update_postprocess_requires_nntp_only_for_all_and_nfo(): void
    {
        foreach (array_keys(self::UPDATE_TYPES) as $type) {
            $this->assertSame(\in_array($type, ['all', 'nfo'], true), UpdatePostProcess::requiresNntpConnectionPhp($type), $type);
        }
    }

    public function test_post_process_tv_pipeline_hex_char_parity_over_every_byte(): void
    {
        $this->requireMetadataFfi();

        $values = ['', 'ab', 'a ', ' a', "a\0", 'A', 'F', 'g', '00', 'á', '０', '🎬'];
        for ($byte = 0; $byte < 256; $byte++) {
            $values[] = \chr($byte);
        }

        $hex = ['a', 'b', 'c', 'd', 'e', 'f', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        foreach ($values as $value) {
            $expected = \in_array($value, $hex, true);
            $this->assertSame($expected, PostProcessTvPipeline::isValidHexCharPhp($value), bin2hex($value));
            $this->assertSame($expected, PostProcessTvPipeline::isValidHexCharNative($value), bin2hex($value));
            foreach ([false, true] as $useRust) {
                PostProcessTvPipeline::setUseRustFfi($useRust);
                $this->assertSame($expected, PostProcessTvPipeline::isValidHexChar($value), bin2hex($value));
            }
        }
    }

    public function test_post_process_tv_pipeline_renamed_and_mode_parity(): void
    {
        $this->requireMetadataFfi();

        foreach ([null, '', '0', 'renamed', "nul\0byte", 'Amélie 🎬', "\xff", str_repeat('x', 300000)] as $renamed) {
            $expected = $renamed ?? '';
            $this->assertSame($expected, PostProcessTvPipeline::normalizeRenamedArgPhp($renamed));
            $this->assertSame($expected, PostProcessTvPipeline::normalizeRenamedArgNative($renamed));
            foreach ([false, true] as $useRust) {
                PostProcessTvPipeline::setUseRustFfi($useRust);
                $this->assertSame($expected, PostProcessTvPipeline::normalizeRenamedArg($renamed));
            }
        }

        // Every non-null --mode value (including "pipeline") keeps the deprecation warning.
        foreach ([null, '', '0', 'pipeline', 'legacy', false, true, 0, 1, 0.0, [], new stdClass] as $mode) {
            $expected = $mode !== null;
            $this->assertSame($expected, PostProcessTvPipeline::shouldWarnDeprecatedModePhp($mode));
            $this->assertSame($expected, PostProcessTvPipeline::shouldWarnDeprecatedModeNative($mode));
            foreach ([false, true] as $useRust) {
                PostProcessTvPipeline::setUseRustFfi($useRust);
                $this->assertSame($expected, PostProcessTvPipeline::shouldWarnDeprecatedMode($mode));
            }
        }
    }

    public function test_reset_tv_show_messages_parity(): void
    {
        $this->requireMetadataFfi();

        $ids = [0, 1, -1, 42, 999, 1000, 1234567, PHP_INT_MAX, PHP_INT_MIN];
        $titles = ['', 'The Office', 'Amélie 🎬', "Nul\0Title", "Line\nBreak", "\xff\xfe", '%d %s {$x}', str_repeat('T', 300000)];

        foreach ($ids as $id) {
            $notFound = "videos_id {$id} not found or is not a TV show (type=0)";
            $complete = number_format($id).' release(s) reset. They will be considered for TV postprocessing again.';
            $this->assertSame($notFound, NntmuxResetTvShowPostProcessing::formatVideoNotFoundMessagePhp($id));
            $this->assertSame($notFound, NntmuxResetTvShowPostProcessing::formatVideoNotFoundMessageNative($id));
            $this->assertSame($complete, NntmuxResetTvShowPostProcessing::formatResetCompleteMessagePhp($id));
            $this->assertSame($complete, NntmuxResetTvShowPostProcessing::formatResetCompleteMessageNative($id));

            foreach ($titles as $title) {
                $dryRun = "[Dry Run] Would reset {$id} release(s) for TV show: {$title} (videos_id={$id})";
                $resetting = "Resetting postprocessing for TV show: {$title} (videos_id={$id})";
                $this->assertSame($dryRun, NntmuxResetTvShowPostProcessing::formatDryRunSummaryPhp($id, $title, $id));
                $this->assertSame($dryRun, NntmuxResetTvShowPostProcessing::formatDryRunSummaryNative($id, $title, $id));
                $this->assertSame($resetting, NntmuxResetTvShowPostProcessing::formatResettingMessagePhp($title, $id));
                $this->assertSame($resetting, NntmuxResetTvShowPostProcessing::formatResettingMessageNative($title, $id));
            }

            foreach ([false, true] as $useRust) {
                NntmuxResetTvShowPostProcessing::setUseRustFfi($useRust);
                $this->assertSame($notFound, NntmuxResetTvShowPostProcessing::formatVideoNotFoundMessage($id));
                $this->assertSame($complete, NntmuxResetTvShowPostProcessing::formatResetCompleteMessage($id));
                $this->assertSame(
                    "[Dry Run] Would reset 7 release(s) for TV show: Show (videos_id={$id})",
                    NntmuxResetTvShowPostProcessing::formatDryRunSummary(7, 'Show', $id)
                );
                $this->assertSame(
                    "Resetting postprocessing for TV show: Show (videos_id={$id})",
                    NntmuxResetTvShowPostProcessing::formatResettingMessage('Show', $id)
                );
            }
        }

        $this->assertSame(
            '1,234,567 release(s) reset. They will be considered for TV postprocessing again.',
            NntmuxResetTvShowPostProcessing::formatResetCompleteMessageNative(1234567)
        );
    }

    /**
     * @return array<string, array{0: bool, 1: mixed, 2: string, 3: ?int}>
     */
    public static function populateModeProvider(): array
    {
        return [
            'flag_only' => [true, false, 'all', null],
            'flag_true_is_not_numeric' => [true, true, 'all', null],
            'missing_info' => [false, false, 'missing_info', null],
            'missing_info_with_id' => [false, '123', 'missing_info', null],
            'missing_info_with_nan' => [false, NAN, 'missing_info', null],
            'null' => [true, null, 'all', null],
            'int' => [true, 21, 'single', 21],
            'negative_int' => [true, -5, 'single', -5],
            'int_max' => [true, PHP_INT_MAX, 'single', PHP_INT_MAX],
            'int_min' => [true, PHP_INT_MIN, 'single', PHP_INT_MIN],
            'float_truncates' => [true, 12.9, 'single', 12],
            'negative_float_truncates' => [true, -3.7, 'single', -3],
            'float_min_exact' => [true, -9223372036854775808.0, 'single', PHP_INT_MIN],
            'numeric_string' => [true, '123', 'single', 123],
            'padded_string' => [true, " \t\n\r\x0b\x0c7 \n", 'single', 7],
            'fraction_string' => [true, '1.9', 'single', 1],
            'leading_dot' => [true, '.5', 'single', 0],
            'trailing_dot' => [true, '1.', 'single', 1],
            'exponent' => [true, '1e3', 'single', 1000],
            'signed' => [true, '+5', 'single', 5],
            'negative_zero' => [true, '-0', 'single', 0],
            'float_overflow_caps' => [true, '1e100', 'single', PHP_INT_MAX],
            'negative_overflow_caps' => [true, '-1e100', 'single', PHP_INT_MIN],
            'integer_overflow_caps' => [true, '99999999999999999999', 'single', PHP_INT_MAX],
            'infinite_string_is_zero' => [true, '1e400', 'single', 0],
            'empty' => [true, '', 'all', null],
            'space' => [true, ' ', 'all', null],
            'dot' => [true, '.', 'all', null],
            'hex' => [true, '0x1A', 'all', null],
            'underscore' => [true, '1_000', 'all', null],
            'trailing_nul' => [true, "5\0", 'all', null],
            'fullwidth' => [true, '１２３', 'all', null],
            'invalid_utf8' => [true, "\xff5", 'all', null],
            'array' => [true, [1], 'all', null],
            'object' => [true, new stdClass, 'all', null],
        ];
    }

    #[DataProvider('populateModeProvider')]
    public function test_populate_anidb_mode_parity(bool $info, mixed $anilistId, string $mode, ?int $id): void
    {
        $this->requireMetadataFfi();

        $expected = ['mode' => $mode, 'anilist_id' => $id];
        $this->assertSame($expected, NntmuxPopulateAniDB::resolvePopulateModePhp($info, $anilistId));
        $this->assertSame($expected, NntmuxPopulateAniDB::resolvePopulateModeNative($info, $anilistId));
        foreach ([false, true] as $useRust) {
            NntmuxPopulateAniDB::setUseRustFfi($useRust);
            $this->assertSame($expected, NntmuxPopulateAniDB::resolvePopulateMode($info, $anilistId));
        }
    }

    public function test_populate_anidb_mode_matches_original_branching(): void
    {
        $samples = [null, false, true, 0, 7, 1.5, '', '12', ' 12 ', 'abc', '0x1A', [], new stdClass];
        foreach ([false, true] as $info) {
            foreach ($samples as $anilistId) {
                $original = $info && is_numeric($anilistId) ? 'single' : ($info ? 'all' : 'missing_info');
                $this->assertSame($original, NntmuxPopulateAniDB::resolvePopulateModePhp($info, $anilistId)['mode']);
            }
        }
    }

    public function test_populate_anidb_out_of_range_floats_decline_to_php(): void
    {
        $this->requireMetadataFfi();

        foreach ([NAN, INF, -INF, 9223372036854775808.0, -1e19] as $float) {
            $php = @NntmuxPopulateAniDB::resolvePopulateModePhp(true, $float);
            $native = @NntmuxPopulateAniDB::resolvePopulateModeNative(true, $float);
            $this->assertSame($php, $native);
            $this->assertSame('single', $native['mode']);
        }
    }

    public function test_populate_steam_apps_helpers_parity(): void
    {
        $this->requireMetadataFfi();

        foreach ([0, 1, 999, 1000, 1001, 2000, 150000, -1000, -1, PHP_INT_MAX, PHP_INT_MIN, 9223372036854775000] as $processed) {
            $expected = $processed > 0 && $processed % 1000 === 0;
            $this->assertSame($expected, NntmuxPopulateSteamApps::shouldReportProgressPhp($processed), (string) $processed);
            $this->assertSame($expected, NntmuxPopulateSteamApps::shouldReportProgressNative($processed), (string) $processed);
            foreach ([false, true] as $useRust) {
                NntmuxPopulateSteamApps::setUseRustFfi($useRust);
                $this->assertSame($expected, NntmuxPopulateSteamApps::shouldReportProgress($processed));
            }
        }

        $numbers = [0, 1, -1, 1000, 123456, PHP_INT_MAX, PHP_INT_MIN];
        foreach ($numbers as $a) {
            foreach ($numbers as $b) {
                $progress = "Processed {$a} of {$b} apps";
                $summary = sprintf('Added %d new steam app(s), %d skipped, %d errors', $a, $b, $a);
                $this->assertSame($progress, NntmuxPopulateSteamApps::formatProgressLinePhp($a, $b));
                $this->assertSame($progress, NntmuxPopulateSteamApps::formatProgressLineNative($a, $b));
                $this->assertSame($summary, NntmuxPopulateSteamApps::formatFinalSummaryLinePhp($a, $b, $a));
                $this->assertSame($summary, NntmuxPopulateSteamApps::formatFinalSummaryLineNative($a, $b, $a));
                foreach ([false, true] as $useRust) {
                    NntmuxPopulateSteamApps::setUseRustFfi($useRust);
                    $this->assertSame($progress, NntmuxPopulateSteamApps::formatProgressLine($a, $b));
                    $this->assertSame($summary, NntmuxPopulateSteamApps::formatFinalSummaryLine($a, $b, $a));
                }
            }
        }
    }

    public function test_randomized_string_helpers_parity(): void
    {
        $this->requireMetadataFfi();

        mt_srand(314);
        for ($i = 0; $i < 300; $i++) {
            $length = mt_rand(0, 24);
            $value = $length === 0 ? '' : random_bytes($length);
            $id = mt_rand(PHP_INT_MIN, PHP_INT_MAX);
            $this->assertSame(UpdatePostProcess::isValidTypePhp($value), UpdatePostProcess::isValidTypeNative($value), bin2hex($value));
            $this->assertSame(UpdatePostProcess::formatInvalidTypeMessagePhp($value), UpdatePostProcess::formatInvalidTypeMessageNative($value));
            $this->assertSame(PostProcessTvPipeline::isValidHexCharPhp($value), PostProcessTvPipeline::isValidHexCharNative($value), bin2hex($value));
            $this->assertSame(PostProcessTvPipeline::normalizeRenamedArgPhp($value), PostProcessTvPipeline::normalizeRenamedArgNative($value));
            $this->assertSame(
                NntmuxResetTvShowPostProcessing::formatDryRunSummaryPhp($id, $value, $id),
                NntmuxResetTvShowPostProcessing::formatDryRunSummaryNative($id, $value, $id)
            );
            $this->assertSame(
                NntmuxResetTvShowPostProcessing::formatResetCompleteMessagePhp($id),
                NntmuxResetTvShowPostProcessing::formatResetCompleteMessageNative($id)
            );
            $this->assertSame(NntmuxPopulateAniDB::resolvePopulateModePhp(true, $value), NntmuxPopulateAniDB::resolvePopulateModeNative(true, $value), bin2hex($value));
            $numeric = (string) mt_rand(-100000, 100000).(mt_rand(0, 1) === 1 ? '.'.mt_rand(0, 99) : '').(mt_rand(0, 1) === 1 ? 'e'.mt_rand(-3, 3) : '');
            $this->assertSame(NntmuxPopulateAniDB::resolvePopulateModePhp(true, $numeric), NntmuxPopulateAniDB::resolvePopulateModeNative(true, $numeric), $numeric);
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::useRustFfi();
        }

        $this->assertTrue(UpdatePostProcess::isValidType('nfo'));
        $this->assertFalse(UpdatePostProcess::isValidType('bogus'));
        $this->assertTrue(UpdatePostProcess::requiresNntpConnection('all'));
        $this->assertFalse(UpdatePostProcess::requiresNntpConnection('tv'));
        $this->assertSame('Invalid type: bogus', UpdatePostProcess::formatInvalidTypeMessage('bogus'));
        $this->assertTrue(PostProcessTvPipeline::isValidHexChar('f'));
        $this->assertFalse(PostProcessTvPipeline::isValidHexChar('g'));
        $this->assertSame('', PostProcessTvPipeline::normalizeRenamedArg(null));
        $this->assertTrue(PostProcessTvPipeline::shouldWarnDeprecatedMode('pipeline'));
        $this->assertFalse(PostProcessTvPipeline::shouldWarnDeprecatedMode(null));
        $this->assertSame('videos_id 5 not found or is not a TV show (type=0)', NntmuxResetTvShowPostProcessing::formatVideoNotFoundMessage(5));
        $this->assertSame('[Dry Run] Would reset 2 release(s) for TV show: X (videos_id=5)', NntmuxResetTvShowPostProcessing::formatDryRunSummary(2, 'X', 5));
        $this->assertSame('Resetting postprocessing for TV show: X (videos_id=5)', NntmuxResetTvShowPostProcessing::formatResettingMessage('X', 5));
        $this->assertSame(
            '1,000 release(s) reset. They will be considered for TV postprocessing again.',
            NntmuxResetTvShowPostProcessing::formatResetCompleteMessage(1000)
        );
        $this->assertSame(['mode' => 'single', 'anilist_id' => 9], NntmuxPopulateAniDB::resolvePopulateMode(true, '9'));
        $this->assertSame(['mode' => 'missing_info', 'anilist_id' => null], NntmuxPopulateAniDB::resolvePopulateMode(false, '9'));
        $this->assertTrue(NntmuxPopulateSteamApps::shouldReportProgress(3000));
        $this->assertFalse(NntmuxPopulateSteamApps::shouldReportProgress(0));
        $this->assertSame('Processed 1000 of 5 apps', NntmuxPopulateSteamApps::formatProgressLine(1000, 5));
        $this->assertSame('Added 1 new steam app(s), 2 skipped, 3 errors', NntmuxPopulateSteamApps::formatFinalSummaryLine(1, 2, 3));
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[64]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedU32 = $ffi->cast('uint32_t *', FFI::addr($raw[1]));
        $hasId = $ffi->new('uint32_t[1]');
        $id = $ffi->new('int64_t[1]');
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $small[$i] = 'Z';
        }

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_postprocess_type_flags', null, 0));
        $this->assertSame(3, self::callFfi($ffi, 'nntmux_update_postprocess_type_flags', 'all', 3));
        $this->assertSame(1, self::callFfi($ffi, 'nntmux_update_postprocess_type_flags', 'tv', 2));
        $this->assertSame(0, self::callFfi($ffi, 'nntmux_update_postprocess_type_flags', 'tvx', 3));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_postprocess_format_invalid_type', 'x', 1, null, 256));
        $this->assertSame(-15, self::callFfi($ffi, 'nntmux_update_postprocess_format_invalid_type', 'x', 1, $small, 4));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_postprocess_tv_pipeline_is_valid_hex_char', null, 1));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_postprocess_tv_pipeline_normalize_renamed', 2, '', 0, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_postprocess_tv_pipeline_normalize_renamed', 1, null, 0, $buf, 256));
        $this->assertSame(-5, self::callFfi($ffi, 'nntmux_postprocess_tv_pipeline_normalize_renamed', 1, 'abcde', 5, $small, 4));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_postprocess_tv_pipeline_should_warn_mode', 2));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reset_tv_show_format_video_not_found', 1, null, 256));
        $this->assertSame(-50, self::callFfi($ffi, 'nntmux_reset_tv_show_format_video_not_found', 1, $small, 4));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reset_tv_show_format_dry_run', 1, null, 0, 1, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reset_tv_show_format_resetting', null, 0, 1, $buf, 256));
        $this->assertLessThan(-4, self::callFfi($ffi, 'nntmux_reset_tv_show_format_reset_complete', 1000, $small, 4));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 2, 1, 1, 0.0, '', 0, $hasId, $id));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 9, 1, 0.0, '', 0, $hasId, $id));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 1, 1, 0.0, '', 0, $unalignedU32, $id));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 1, 1, 0.0, '', 0, $hasId, $unalignedInt64));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 1, 1, 0.0, null, 0, $hasId, $id));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 2, 0, NAN, '', 0, $hasId, $id));
        $this->assertSame(0, self::callFfi($ffi, 'nntmux_populate_anidb_resolve_mode', 1, 1, 77, 0.0, '', 0, $hasId, $id));
        $this->assertSame([1, 77], [(int) $hasId[0], (int) $id[0]]);

        $this->assertSame(1, self::callFfi($ffi, 'nntmux_populate_steam_apps_should_report_progress', 5000));
        $this->assertSame(0, self::callFfi($ffi, 'nntmux_populate_steam_apps_should_report_progress', 0));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_steam_apps_format_progress', 1, 2, null, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_populate_steam_apps_format_summary', 1, 2, 3, null, 256));
        $this->assertSame(-45, self::callFfi($ffi, 'nntmux_populate_steam_apps_format_summary', 1, 2, 3, $small, 4));

        $this->assertSame('ZZZZ', FFI::string($small, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_POSTPROCESS_TV_ANIDB_STEAM_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_POSTPROCESS_TV_ANIDB_STEAM_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $runBatch = static function (int $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                UpdatePostProcess::isValidType('additional');
                UpdatePostProcess::requiresNntpConnection('nfo');
                UpdatePostProcess::formatInvalidTypeMessage('bogus');
                PostProcessTvPipeline::isValidHexChar('c');
                PostProcessTvPipeline::normalizeRenamedArg(null);
                PostProcessTvPipeline::shouldWarnDeprecatedMode(null);
                NntmuxResetTvShowPostProcessing::formatVideoNotFoundMessage(12345);
                NntmuxResetTvShowPostProcessing::formatDryRunSummary(120, 'The Office (US)', 12345);
                NntmuxResetTvShowPostProcessing::formatResettingMessage('The Office (US)', 12345);
                NntmuxResetTvShowPostProcessing::formatResetCompleteMessage(1234567);
                NntmuxPopulateAniDB::resolvePopulateMode(true, '21');
                NntmuxPopulateSteamApps::shouldReportProgress(150000);
                NntmuxPopulateSteamApps::formatProgressLine(150000, 182345);
                NntmuxPopulateSteamApps::formatFinalSummaryLine(1200, 180000, 3);
            }
        };

        $setAllModes = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
        };

        for ($w = 0; $w < 8; $w++) {
            $setAllModes(false);
            $runBatch(10);
            $setAllModes(true);
            $runBatch(10);
        }

        $rounds = 3;
        $samples = 41;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];

            for ($s = 0; $s < $samples; $s++) {
                $order = (($round + $s) % 2) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAllModes($rust);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $elapsed = (hrtime(true) - $t0) / 1_000_000;
                    if ($rust) {
                        $rustTimes[] = $elapsed;
                    } else {
                        $phpTimes[] = $elapsed;
                    }
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms max=%.3f ms, Rust median=%.3f ms max=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $phpTimes[$samples - 1],
                $rustMedian,
                $rustTimes[$samples - 1],
                $phpMedian / max(0.0001, $rustMedian)
            );
        }

        fwrite(STDERR, "\n[Wave 31.4 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): int
    {
        return (int) $ffi->$symbol(...$args);
    }

    private function requireMetadataFfi(): FFI
    {
        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::DIRECT_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        if ($ffi === null) {
            $this->markTestSkipped('Rust metadata FFI library is not built or available.');
        }

        return $ffi;
    }
}
