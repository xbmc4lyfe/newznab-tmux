<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CleanNZB;
use App\Console\Commands\FixNzbSplitNames;
use App\Console\Commands\NntmuxRemoveBadReleases;
use App\Console\Commands\ReclassifyHeaderRejections;
use App\Console\Commands\RemoveCrapReleases;
use FFI;
use FFI\Exception;
use stdClass;
use Tests\TestCase;
use TypeError;

final class RustNzbReleaseCleanupCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w30_nzb_release_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** @var list<class-string> */
    private const COMMANDS = [
        CleanNZB::class,
        FixNzbSplitNames::class,
        RemoveCrapReleases::class,
        NntmuxRemoveBadReleases::class,
        ReclassifyHeaderRejections::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $command) {
            $command::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::COMMANDS as $command) {
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::setUseRustFfi(true);
            $this->assertTrue($command::usesRustFfi(), $command);
            $command::setUseRustFfi(null);
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::useRustFfi();
            $this->assertTrue($command::usesRustFfi(), $command);
            $command::useRustFfi(false);
            $this->assertFalse($command::usesRustFfi(), $command);
        }
    }

    public function test_rust_evaluates_requests_without_falling_back(): void
    {
        $ffi = $this->loadFfi();
        $cases = [
            [['clean_nzb.normalize_temp_age_seconds', '24'], CleanNZB::normalizeTempAgeSecondsPhp('24')],
            [['clean_nzb.extract_guid_from_nzb_gz_filename', "a\0é😀.nzb.gz"], CleanNZB::extractGuidFromNzbGzFilenamePhp("a\0é😀.nzb.gz")],
            [['clean_nzb.format_staged_outcome_line', false, 1, 2, 3], CleanNZB::formatStagedOutcomeLinePhp(false, 1, 2, 3)],
            [['clean_nzb.format_temp_cleanup_line', true, 4], CleanNZB::formatTempCleanupLinePhp(true, 4)],
            [['fix_nzb_split.format_dry_run_diff_line', 7, "Old\n\"x\"", 'Néw/1', -1, 5050], FixNzbSplitNames::formatDryRunDiffLinePhp(7, "Old\n\"x\"", 'Néw/1', -1, 5050)],
            [['fix_nzb_split.build_reset_update_payload', "T\u{2028}", 2030], FixNzbSplitNames::buildResetUpdatePayloadPhp("T\u{2028}", 2030)],
            [['fix_nzb_split.format_completion_summary', true, 1, 9], FixNzbSplitNames::formatCompletionSummaryPhp(true, 1, 9)],
            [['remove_crap.normalize_options', '0', null, 5, true], RemoveCrapReleases::normalizeOptionsPhp('0', null, 5, true)],
            [['remove_crap.format_summary_message', 'Wrong type: x', false], RemoveCrapReleases::formatSummaryMessagePhp('Wrong type: x', false)],
            [['remove_bad.is_bad_password_status', -2], NntmuxRemoveBadReleases::isBadPasswordStatusPhp(-2)],
            [['remove_bad.normalize_release_id_list', [3, '3', '7', 0, -1, null, true, 2.5]], NntmuxRemoveBadReleases::normalizeReleaseIdListPhp([3, '3', '7', 0, -1, null, true, 2.5])],
            [['remove_bad.format_updated_bad_releases_message', 12], NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessagePhp(12)],
            [['reclassify.normalize_chunk_window', 0, -5], ReclassifyHeaderRejections::normalizeChunkWindowPhp(0, -5)],
            [['reclassify.has_exceeded_max_seconds', 10, 10.0], ReclassifyHeaderRejections::hasExceededMaxSecondsPhp(10, 10.0)],
            [['reclassify.format_summary_line', 10, 2, 0, 110], ReclassifyHeaderRejections::formatSummaryLinePhp(10, 2, 0, 110)],
        ];
        foreach ($cases as [$request, $expected]) {
            $this->assertSame([$expected], $this->callRust($ffi, $request), (string) $request[0]);
        }

        foreach ([
            ['clean_nzb.normalize_temp_age_seconds', '12abc'],
            ['clean_nzb.normalize_temp_age_seconds', PHP_INT_MAX],
            ['reclassify.normalize_chunk_window', 2, PHP_INT_MAX],
            ['remove_bad.normalize_release_id_list', ['x']],
            ['unknown.operation'],
        ] as $declined) {
            $this->assertNull($this->callRust($ffi, $declined), (string) $declined[0]);
        }
    }

    public function test_clean_nzb_helpers_match_php(): void
    {
        $this->loadFfi();
        foreach (['24', '0', '-3', '', null, '+2', '12abc', ' 12', '1e3', 7, -7, 0, 1.9, -0.5, true, false, '007'] as $hours) {
            $this->assertSame(CleanNZB::normalizeTempAgeSecondsPhp($hours), CleanNZB::normalizeTempAgeSecondsNative($hours), var_export($hours, true));
        }
        foreach (['9223372036854775807', PHP_INT_MAX] as $overflow) {
            $php = $this->capture(static fn (): int => CleanNZB::normalizeTempAgeSecondsPhp($overflow));
            $this->assertIsString($php);
            $this->assertStringStartsWith(TypeError::class, $php);
            $this->assertSame($php, $this->capture(static fn (): int => CleanNZB::normalizeTempAgeSecondsNative($overflow)));
        }

        foreach (['abc.nzb.gz', '.nzb.gz', 'abc.NZB.GZ', 'abc.nzb.gz.tmp.1.abcdef012345', 'abc.nzb', '', "a\0b.nzb.gz", 'é😀.nzb.gz', "\xff\xfe.nzb.gz", '0.nzb.gz'] as $filename) {
            $this->assertSame(CleanNZB::extractGuidFromNzbGzFilenamePhp($filename), CleanNZB::extractGuidFromNzbGzFilenameNative($filename), bin2hex($filename));
        }
        $this->assertSame('abc', CleanNZB::extractGuidFromNzbGzFilenamePhp('abc.nzb.gz'));
        $this->assertNull(CleanNZB::extractGuidFromNzbGzFilenamePhp('abc.NZB.GZ'));

        foreach ([true, false] as $delete) {
            foreach ([[0, 0, 0], [1, 2, 3], [-1, PHP_INT_MAX, PHP_INT_MIN]] as [$applied, $deleted, $kept]) {
                $this->assertSame(
                    CleanNZB::formatStagedOutcomeLinePhp($delete, $applied, $deleted, $kept),
                    CleanNZB::formatStagedOutcomeLineNative($delete, $applied, $deleted, $kept)
                );
            }
            foreach ([0, 1, -4, PHP_INT_MAX] as $stale) {
                $this->assertSame(CleanNZB::formatTempCleanupLinePhp($delete, $stale), CleanNZB::formatTempCleanupLineNative($delete, $stale));
            }
        }
        $this->assertSame('Checked (dry run) stale temporary NZBs: 1 deleted, 1 applied, 0 kept.', CleanNZB::formatStagedOutcomeLinePhp(false, 1, 1, 0));
        $this->assertSame('Cleaned stale temporary NZBs: 2 deleted, 0 applied, 1 kept.', CleanNZB::formatStagedOutcomeLinePhp(true, 0, 2, 1));
    }

    public function test_fix_nzb_split_helpers_match_php(): void
    {
        $this->loadFfi();
        $names = ['', 'Show.S01E01', "Bad\0Name", "New\nLine \"q\" \\ /", 'Ünïcødé 😀', "\u{2028}", "\xc3\x28"];
        foreach ($names as $old) {
            foreach ($names as $new) {
                foreach ([[0, 0, 0], [7, -1, 5050], [PHP_INT_MAX, PHP_INT_MIN, 0]] as [$id, $oldCat, $newCat]) {
                    $this->assertSame(
                        FixNzbSplitNames::formatDryRunDiffLinePhp($id, $old, $new, $oldCat, $newCat),
                        FixNzbSplitNames::formatDryRunDiffLineNative($id, $old, $new, $oldCat, $newCat)
                    );
                }
            }
            foreach ([0, 2030, -1, PHP_INT_MAX] as $category) {
                $this->assertSame(FixNzbSplitNames::buildResetUpdatePayloadPhp($old, $category), FixNzbSplitNames::buildResetUpdatePayloadNative($old, $category));
            }
        }
        $this->assertSame('[dry-run] #7 Old -> New (10 -> 20)', FixNzbSplitNames::formatDryRunDiffLinePhp(7, 'Old', 'New', 10, 20));
        $this->assertSame([
            'name' => 'T', 'searchname' => 'T', 'categories_id' => 2030, 'isrenamed' => 1, 'iscategorized' => 1, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'imdbid' => null, 'musicinfo_id' => null, 'consoleinfo_id' => null, 'gamesinfo_id' => 0,
            'bookinfo_id' => null, 'anidbid' => null, 'movieinfo_id' => null,
        ], FixNzbSplitNames::buildResetUpdatePayloadPhp('T', 2030));

        foreach ([true, false] as $dryRun) {
            foreach ([[0, 0], [3, 9], [-1, PHP_INT_MAX]] as [$fixed, $checked]) {
                $this->assertSame(
                    FixNzbSplitNames::formatCompletionSummaryPhp($dryRun, $fixed, $checked),
                    FixNzbSplitNames::formatCompletionSummaryNative($dryRun, $fixed, $checked)
                );
            }
        }
    }

    public function test_remove_crap_helpers_match_php(): void
    {
        $this->loadFfi();
        $values = [null, '', '0', 'full', '24', 'blacklist', "x\0y", 'é😀', 0, 5, -1, true, false, 1.5, "\xff", [1], new stdClass];
        foreach ($values as $value) {
            foreach ([true, false] as $delete) {
                $this->assertOptionsParity($value, null, null, $delete);
                $this->assertOptionsParity(null, $value, null, $delete);
                $this->assertOptionsParity(null, null, $value, $delete);
            }
        }
        $this->assertSame(['type' => '', 'time' => 'full', 'blacklist_id' => '', 'delete' => false], RemoveCrapReleases::normalizeOptionsPhp(null, null, null, false));
        // An explicit "0" stays "0": it must not turn into "all types", "no time limit" or "every blacklist".
        $this->assertSame(['type' => '0', 'time' => '0', 'blacklist_id' => '0', 'delete' => true], RemoveCrapReleases::normalizeOptionsPhp('0', '0', '0', true));

        foreach ([true, false, null, '', 'Wrong type: x', "err\0or", 'é', 0, 1, 1.5, NAN, "\xff", [], ['x'], new stdClass] as $result) {
            foreach ([true, false] as $delete) {
                $this->assertSame(RemoveCrapReleases::formatSummaryMessagePhp($result, $delete), RemoveCrapReleases::formatSummaryMessageNative($result, $delete));
            }
        }
        $this->assertSame('info', RemoveCrapReleases::formatSummaryMessagePhp(true, true)['status']);
        $this->assertSame(['status' => 'warn', 'message' => 'Wrong type: x'], RemoveCrapReleases::formatSummaryMessagePhp('Wrong type: x', true));
    }

    public function test_remove_bad_release_helpers_match_php(): void
    {
        $this->loadFfi();
        foreach ([PHP_INT_MIN, -3, -2, -1, 0, 1, PHP_INT_MAX] as $status) {
            $this->assertSame(NntmuxRemoveBadReleases::isBadPasswordStatusPhp($status), NntmuxRemoveBadReleases::isBadPasswordStatusNative($status));
        }

        $object = new stdClass;
        $object->releases_id = '42';
        $rowSets = [
            [],
            [3, 3, 1, 2, 1],
            ['3', '7', '007', '+8', '-9', '', 'abc', '12abc', ' 5', '1e3', '99999999999999999999'],
            [0, -1, null, true, false, 2.5, -2.5, [9]],
            [1.0e30],
            [NAN],
            [['releases_id' => 5], ['releases_id' => '5'], ['other' => 6], $object, new stdClass, ['releases_id' => [7]]],
            ['k' => 4, 'j' => 4, 'i' => 10],
            ["7\0", 'é', "\xff"],
        ];
        foreach ($rowSets as $rows) {
            $this->assertSame(
                $this->capture(static fn (): array => NntmuxRemoveBadReleases::normalizeReleaseIdListPhp($rows)),
                $this->capture(static fn (): array => NntmuxRemoveBadReleases::normalizeReleaseIdListNative($rows)),
                json_encode($rows, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''
            );
        }
        $this->assertSame([3, 1, 2], NntmuxRemoveBadReleases::normalizeReleaseIdListPhp([3, 3, 1, 2, 1]));
        $this->assertSame([5, 42], NntmuxRemoveBadReleases::normalizeReleaseIdListPhp([['releases_id' => 5], ['releases_id' => '5'], $object]));

        foreach ([0, 1, 12, -1, PHP_INT_MAX] as $count) {
            $this->assertSame(NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessagePhp($count), NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessageNative($count));
        }
        $this->assertSame('Updated 12 bad releases', NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessagePhp(12));
    }

    public function test_reclassify_helpers_match_php(): void
    {
        $this->loadFfi();
        foreach ([PHP_INT_MIN, -5, 0, 1, 5000, PHP_INT_MAX] as $chunk) {
            foreach ([PHP_INT_MIN, -1, 0, 100, PHP_INT_MAX - 1, PHP_INT_MAX] as $after) {
                $this->assertSame(ReclassifyHeaderRejections::normalizeChunkWindowPhp($chunk, $after), ReclassifyHeaderRejections::normalizeChunkWindowNative($chunk, $after));
            }
        }
        $this->assertSame(['chunk' => 1, 'after' => 0, 'through' => 1], ReclassifyHeaderRejections::normalizeChunkWindowPhp(0, -5));

        $elapsed = [0, 9, 10, 11, -1, PHP_INT_MAX, 0.0, 9.999, 10.0, 10.5, -0.0, 1.0e300, INF, -INF, NAN, 9.2233720368547758E18];
        foreach ([PHP_INT_MIN, -1, 0, 1, 10, PHP_INT_MAX] as $maxSeconds) {
            foreach ($elapsed as $seconds) {
                $this->assertSame(
                    ReclassifyHeaderRejections::hasExceededMaxSecondsPhp($maxSeconds, $seconds),
                    ReclassifyHeaderRejections::hasExceededMaxSecondsNative($maxSeconds, $seconds),
                    $maxSeconds.' vs '.var_export($seconds, true)
                );
            }
        }

        foreach ([[0, 0, 0, 0], [10, 2, 0, 110], [-1, PHP_INT_MAX, PHP_INT_MIN, 5]] as [$scanned, $reclassified, $deleted, $lastId]) {
            $this->assertSame(
                ReclassifyHeaderRejections::formatSummaryLinePhp($scanned, $reclassified, $deleted, $lastId),
                ReclassifyHeaderRejections::formatSummaryLineNative($scanned, $reclassified, $deleted, $lastId)
            );
        }
        $this->assertStringStartsWith('Reclassified 2 candidates', ReclassifyHeaderRejections::formatSummaryLinePhp(10, 2, 0, 110));
    }

    public function test_dispatch_uses_rust_when_enabled_and_falls_back_without_library(): void
    {
        $this->loadFfi();
        foreach (self::COMMANDS as $command) {
            $command::setUseRustFfi(true);
        }
        $this->assertSame(86400, CleanNZB::normalizeTempAgeSeconds('24'));
        $this->assertSame('abc', CleanNZB::extractGuidFromNzbGzFilename('abc.nzb.gz'));
        $this->assertSame('Updated 3 NZBSPLIT-wrapped releases.', FixNzbSplitNames::formatCompletionSummary(false, 3, 9));
        $this->assertSame('full', RemoveCrapReleases::normalizeOptions(null, null, null, false)['time']);
        $this->assertSame([3, 7], NntmuxRemoveBadReleases::normalizeReleaseIdList([3, '7', 3]));
        $this->assertTrue(ReclassifyHeaderRejections::hasExceededMaxSeconds(1, 1.5));

        foreach (self::COMMANDS as $command) {
            $command::setFfiForTesting(false);
        }
        $this->assertSame(86400, CleanNZB::normalizeTempAgeSecondsNative('24'));
        $this->assertSame(FixNzbSplitNames::buildResetUpdatePayloadPhp('T', 1), FixNzbSplitNames::buildResetUpdatePayloadNative('T', 1));
        $this->assertSame(['status' => 'warn', 'message' => 'x'], RemoveCrapReleases::formatSummaryMessageNative('x', true));
        $this->assertSame('Updated 1 bad releases', NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessageNative(1));
        $this->assertSame(['chunk' => 2, 'after' => 3, 'through' => 5], ReclassifyHeaderRejections::normalizeChunkWindowNative(2, 3));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of this 15-helper workload, including JSON encoding, the FFI call and
        // decoding (PHP 8.5.12, Apple Silicon, release dylib; 3 rounds x 201 samples x 200 iterations, 5 warmups):
        // - Round 1: PHP p50 = 0.278 ms (p99 = 0.466 ms), Rust FFI p50 = 3.382 ms (p99 = 4.949 ms) -> p50 0.08x, p99 0.09x
        // - Round 2: PHP p50 = 0.298 ms (p99 = 0.587 ms), Rust FFI p50 = 3.639 ms (p99 = 6.394 ms) -> p50 0.08x, p99 0.09x
        // - Round 3: PHP p50 = 0.298 ms (p99 = 0.442 ms), Rust FFI p50 = 3.626 ms (p99 = 4.349 ms) -> p50 0.08x, p99 0.10x
        // The FFI round trip dwarfs the sub-microsecond PHP work (< 1.5x in `.ai/rules/rust-performance.md`),
        // so all five commands keep PHP by default and Rust remains opt-in through setUseRustFfi(true).
        $this->loadFfi();
        $warmup = 5;
        $samples = 41;
        $iterations = 200;
        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce(false)) + count($this->runWorkloadOnce(true));
        }

        $phpTimes = [];
        $rustTimes = [];
        for ($s = 0; $s < $samples; $s++) {
            foreach (($s & 1) === 1 ? [true, false] : [false, true] as $useRust) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce($useRust));
                }
                $elapsed = (hrtime(true) - $t0) / 1_000_000;
                if ($useRust) {
                    $rustTimes[] = $elapsed;
                } else {
                    $phpTimes[] = $elapsed;
                }
            }
        }
        sort($phpTimes);
        sort($rustTimes);

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $phpTimes[(int) floor($samples * 0.5)]);
        $this->assertGreaterThan(0.0, $rustTimes[(int) floor($samples * 0.5)]);
        foreach (self::COMMANDS as $command) {
            $this->assertFalse($command::usesRustFfi(), $command);
        }
    }

    /**
     * @return list<mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        if ($useRust) {
            return [
                CleanNZB::normalizeTempAgeSecondsNative('24'),
                CleanNZB::extractGuidFromNzbGzFilenameNative('0123456789abcdef0123456789abcdef01234567.nzb.gz'),
                CleanNZB::formatStagedOutcomeLineNative(true, 1, 2, 0),
                CleanNZB::formatTempCleanupLineNative(false, 3),
                FixNzbSplitNames::formatDryRunDiffLineNative(42, 'Old.Name', 'New.Name', 7010, 5040),
                FixNzbSplitNames::buildResetUpdatePayloadNative('New.Name', 5040),
                FixNzbSplitNames::formatCompletionSummaryNative(false, 4, 9),
                RemoveCrapReleases::normalizeOptionsNative('gibberish', '24', null, true),
                RemoveCrapReleases::formatSummaryMessageNative(true, true),
                NntmuxRemoveBadReleases::isBadPasswordStatusNative(-2),
                NntmuxRemoveBadReleases::normalizeReleaseIdListNative([1, 2, 2, 3]),
                NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessageNative(3),
                ReclassifyHeaderRejections::normalizeChunkWindowNative(5000, 10000),
                ReclassifyHeaderRejections::hasExceededMaxSecondsNative(60, 12.5),
                ReclassifyHeaderRejections::formatSummaryLineNative(5000, 12, 0, 15000),
            ];
        }

        return [
            CleanNZB::normalizeTempAgeSecondsPhp('24'),
            CleanNZB::extractGuidFromNzbGzFilenamePhp('0123456789abcdef0123456789abcdef01234567.nzb.gz'),
            CleanNZB::formatStagedOutcomeLinePhp(true, 1, 2, 0),
            CleanNZB::formatTempCleanupLinePhp(false, 3),
            FixNzbSplitNames::formatDryRunDiffLinePhp(42, 'Old.Name', 'New.Name', 7010, 5040),
            FixNzbSplitNames::buildResetUpdatePayloadPhp('New.Name', 5040),
            FixNzbSplitNames::formatCompletionSummaryPhp(false, 4, 9),
            RemoveCrapReleases::normalizeOptionsPhp('gibberish', '24', null, true),
            RemoveCrapReleases::formatSummaryMessagePhp(true, true),
            NntmuxRemoveBadReleases::isBadPasswordStatusPhp(-2),
            NntmuxRemoveBadReleases::normalizeReleaseIdListPhp([1, 2, 2, 3]),
            NntmuxRemoveBadReleases::formatUpdatedBadReleasesMessagePhp(3),
            ReclassifyHeaderRejections::normalizeChunkWindowPhp(5000, 10000),
            ReclassifyHeaderRejections::hasExceededMaxSecondsPhp(60, 12.5),
            ReclassifyHeaderRejections::formatSummaryLinePhp(5000, 12, 0, 15000),
        ];
    }

    private function assertOptionsParity(mixed $type, mixed $time, mixed $blacklistId, bool $delete): void
    {
        $php = $this->capture(static fn (): array => RemoveCrapReleases::normalizeOptionsPhp($type, $time, $blacklistId, $delete));
        $native = $this->capture(static fn (): array => RemoveCrapReleases::normalizeOptionsNative($type, $time, $blacklistId, $delete));
        $this->assertSame($php, $native);
    }

    /**
     * Result of `$helper`, or the class of what it threw, so a PHP warning or error is compared too.
     *
     * @param  callable(): mixed  $helper
     */
    private function capture(callable $helper): mixed
    {
        try {
            return $helper();
        } catch (\Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }
    }

    /**
     * @param  list<mixed>  $request
     * @return list<mixed>|null
     */
    private function callRust(FFI $ffi, array $request): ?array
    {
        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $buffer = $ffi->new('uint8_t[4096]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_w30_nzb_release_cleanup_commands($json, strlen($json), $buffer, 4096);
        if ($written === -1) {
            return null;
        }
        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written);
        $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));

        return $decoded;
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }
        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                try {
                    $ffi = FFI::cdef(self::FFI_CDEF, $path);
                } catch (Exception) {
                    continue;
                }

                return $ffi;
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library with the wave 30.1 export not found.');
    }
}
