<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustEnumsUserRoleProcessingOutcomeStageSourcePositionVideoContainerW64ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        UserRole::class => 'w64UserRolePlanBatch',
        ProcessingOutcome::class => 'w64ProcessingOutcomePlanBatch',
        ProcessingStage::class => 'w64ProcessingStagePlanBatch',
        SourcePosition::class => 'w64SourcePositionPlanBatch',
        VideoContainer::class => 'w64VideoContainerPlanBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        UserRole::class => UserRole::W64_ROLE_HEADER,
        ProcessingOutcome::class => ProcessingOutcome::W64_OUTCOME_HEADER,
        ProcessingStage::class => ProcessingStage::W64_STAGE_HEADER,
        SourcePosition::class => SourcePosition::W64_POSITION_HEADER,
        VideoContainer::class => VideoContainer::W64_CONTAINER_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base c62638c98 plus this
     * change, seed 646, 50 warmups and 1,000 pairs/workload, four runs (w646-benchmark-*.json in system tmp).
     * The per-metric minimum over the runs of common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x. All five enums clear 1.5x on every metric; the
     * uncommon p99 (NUL-holding, Unicode and oversized tokens dominated by marshaling) has the narrowest margin.
     */
    private const QUALIFICATION = [
        UserRole::class => [5.123, 2.867, 2.973, 2.189],
        ProcessingOutcome::class => [4.104, 2.596, 2.256, 1.879],
        ProcessingStage::class => [4.077, 2.571, 2.035, 1.810],
        SourcePosition::class => [5.806, 3.538, 3.265, 2.409],
        VideoContainer::class => [3.325, 2.483, 4.834, 4.136],
    ];

    private const ROLE_ODD = ['1', '2', '3', '4', '0', '5', '-1', '01', ' 1', 'USER', 'ADMIN', 'DISABLED', 'MODERATOR', 'User', 'Admin', 'Disabled',
        'Moderator', 'user', ' ADMIN ', 'administrator', 'root', 'superuser', 'member', 'regular', 'banned', 'Suspended', 'inactive', 'locked',
        'mod', 'MODS', 'staff', 'moderators', 'guest', '', ' ', 'Ädmin', '管理者', "Admin\0", 'admin '];

    private const OUTCOME_ODD = ['completed', 'no-useful-artifacts', 'passworded', 'group-unavailable', 'temporary-workspace-unavailable', 'timed-out',
        'deleted-after-timeout', 'deleted-broken-nzb', 'deleted-par2-only', 'not-found', 'awaiting-late-articles', 'failed', 'Completed', 'TimedOut',
        'DeletedPar2Only', 'AwaitingLateArticles', 'Failed', 'NotFound', ' COMPLETED ', 'timed_out', 'Group Unavailable', 'deleted_broken_nzb',
        'NO USEFUL ARTIFACTS', 'not found', 'timedout', 'success', '', ' ', 'fäiled', "failed\0", 'failed '];

    private const STAGE_ODD = ['workspace-preparation', 'nzb-parsing', 'release-initialization', 'message-id-selection', 'media-source-planning',
        'direct-downloads', 'archive-downloads', 'extracted-files', 'media-sources', 'archive-fallbacks', 'timeout-handling', 'finalization',
        'workspace-cleanup', 'WorkspacePreparation', 'NzbParsing', 'Finalization', 'WorkspaceCleanup', 'NZB parsing', 'Message-ID selection',
        'Workspace cleanup', ' NZB_PARSING ', 'media_sources', 'Media Sources', 'archive fallbacks', 'cleanup', '', ' ', 'finalizätion',
        "nzb-parsing\0", 'nzb-parsing '];

    private const POSITION_ODD = ['complete', 'head', 'mid', 'Complete', 'Head', 'Mid', 'Complete file', 'File head', 'Mid-file', ' HEAD ', 'mid_file',
        'complete_file', 'full', 'Whole', 'entire', 'start', 'BEGIN', 'beginning', 'first', 'middle', 'center', 'centre', 'tail', 'END', 'last', '',
        ' ', 'mïd', "head\0", 'head ', 'Mid file'];

    private const NAME_ODD = ['movie.mkv', 'clip.MP4', 'show.avi', 'a.ts', 'b.m2ts', 'c.mpg', 'd.vob', 'e.wmv', 'f.ogv', 'g.webm', 'h.mov', 'i.qt',
        '"Movie.MKV"', 'name.mkv"', 'name.mkv" yEnc', 'x.mkv.par2', 'y.part01.rar', 'z.mkvx', 'noext', '.mkv', 'a.m', '', ' ', 'fïlm.mkv', "x\0.mkv",
        'archive.7z', 'tab.ts	', "multi.ts\x0B"];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isEnum(), $class);
            $public = ['enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', 'usesW64RustFfi', 'withW64RustFfi', $method, $method.'Php', $method.'Native'];
            foreach ($public as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w64RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(['op', 'val'], array_map(static fn ($p): string => $p->getName(), $state->getParameters()), $class);
            self::assertSame('mixed', (string) $state->getReturnType(), $class);
            self::assertTrue($reflection->getReflectionConstant('W64_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W64_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W64_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w646_', (string) $cdef->getValue(), $class);
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertContains($property->getName(), ['name', 'value'], $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $class::resetW64RustFfi();
            $expected = (bool) (new ReflectionClass($class))->getConstant('W64_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW64RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW64RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW64RustFfi(! $enabled, static fn (): bool => $class::usesW64RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W64_RUST_FFI_DEFAULT'), $other::usesW64RustFfi());
                        }
                    }

                    return $class::usesW64RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW64RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW64RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW64RustFfi(), $class);
            $class::enableW64RustFfi();
            self::assertTrue($class::usesW64RustFfi(), $class);
            $class::disableW64RustFfi();
            self::assertFalse($class::usesW64RustFfi(), $class);
            $class::resetW64RustFfi();
            self::assertSame($expected, $class::usesW64RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        self::assertSame('Moderator', UserRole::MODERATOR->labelPhp());
        self::assertSame(UserRole::ADMIN, UserRole::tryFromLabelPhp('Admin'));
        self::assertTrue(UserRole::MODERATOR->hasElevatedPrivilegesPhp());
        self::assertFalse(UserRole::usesRustFfi());
        self::assertTrue(ProcessingOutcome::Passworded->isSuccessfulPhp());
        self::assertTrue(ProcessingOutcome::DeletedPar2Only->isDeletedPhp());
        self::assertSame(ProcessingOutcome::TimedOut, ProcessingOutcome::resolvePhp('timed-out'));
        self::assertSame(12, ProcessingStage::WorkspaceCleanup->rankPhp());
        self::assertSame('Message-ID selection', ProcessingStage::MessageIdSelection->labelPhp());
        self::assertSame(ProcessingStage::NzbParsing, ProcessingStage::tryFromNormalizedPhp(' NZB_PARSING '));
        self::assertSame('Mid-file', SourcePosition::Mid->labelPhp());
        self::assertSame(2, SourcePosition::Complete->rankPhp());
        self::assertSame(VideoContainer::Matroska, VideoContainer::fromFileName('movie.mkv'));
        self::assertSame('wmv', VideoContainer::Asf->extension());
        self::assertFalse(VideoContainer::Mp4->canResumeMidStream());
        self::assertSame(3, VideoContainer::transportStreamOffsetPhp($this->transportStream(3, 188, 960)));
    }

    public function test_plan_tables_mirror_enum_methods(): void
    {
        $roles = new ReflectionClass(UserRole::class);
        $tokens = $roles->getConstant('W64_ROLE_TOKENS');
        $flags = $roles->getConstant('W64_ROLE_FLAGS');
        foreach (UserRole::cases() as $at => $case) {
            self::assertSame($at, $tokens[(string) $case->value]);
            self::assertSame($at | 1 << 3, $tokens[$case->name]);
            self::assertSame($at | 2 << 3, $tokens[$case->labelPhp()]);
            $expected = 1 | ($case->isAdminPhp() ? 2 : 0) | ($case->isModeratorPhp() ? 4 : 0) | ($case->isDisabledPhp() ? 8 : 0) | ($case->hasElevatedPrivilegesPhp() ? 16 : 0);
            self::assertSame($expected, $flags[$at], $case->name);
        }
        $outcome = new ReflectionClass(ProcessingOutcome::class);
        foreach (ProcessingOutcome::cases() as $at => $case) {
            self::assertSame($at, $outcome->getConstant('W64_OUTCOME_NAMES')[$case->name]);
            self::assertSame($at, $outcome->getConstant('W50_CASE_INDEX')[$case->value]);
        }
        $stage = (new ReflectionClass(ProcessingStage::class))->getConstant('W64_STAGE_TOKENS');
        foreach (ProcessingStage::cases() as $at => $case) {
            self::assertSame($at, $case->rankPhp());
            self::assertSame($at, $stage[$case->value]);
            self::assertSame($at | 1 << 4, $stage[$case->name]);
            self::assertSame($at | ($case->labelPhp() === $case->name ? 1 : 2) << 4, $stage[$case->labelPhp()]);
        }
        $position = (new ReflectionClass(SourcePosition::class))->getConstant('W64_POSITION_TOKENS');
        foreach (SourcePosition::cases() as $at => $case) {
            self::assertSame($at, $position[$case->value]);
            self::assertSame($at | 1 << 2, $position[$case->name]);
            self::assertSame($at | 2 << 2, $position[$case->labelPhp()]);
        }
    }

    public function test_user_role_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(UserRole::class, [[], [], 0]);
        self::assertSame([0, 100, -1, -1, 0], [$empty[0], $empty[16], $empty[18], $empty[19], $empty[20]]);
        $out = $this->assertParity(UserRole::class, [[1, 'ADMIN', 'Moderator', ' banned ', 4, 'nope', 'user'], [100, 9_999, 499, 0, 500, 3, -5], 0]);
        self::assertSame([2, 1, 1, 2, 1], \array_slice($out, 1, 5));
        self::assertSame([3, 2, 1, 1, 3, 100, 11_101, 0, 5, 0b1111], \array_slice($out, 11, 10));
        self::assertSame(1 | 1 << 3 | 19 << 6 | 5 << 13 | 9_999 << 21, $out[UserRole::W64_ROLE_HEADER + 1]);
        $requests = [PHP_INT_MIN, -1, 0, 1, 99, 100, 499, 500, 4_294_967_295, 4_294_967_296, PHP_INT_MAX];
        $rows = [];
        foreach ([1, 2, 3, 4, ...self::ROLE_ODD] as $at => $role) {
            foreach ($requests as $i => $value) {
                $rows[] = [$role, $requests[($i + $at) % \count($requests)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 100, 2_147_483_648, 2_147_483_649, PHP_INT_MAX] as $quota) {
            $this->assertParity(UserRole::class, [array_column($rows, 0), array_column($rows, 1), $quota]);
        }
        $this->assertParity(UserRole::class, [['k' => 'Admin', 'j' => 3, 9 => str_repeat('u', 300)], [7 => 1_000], 10]);
    }

    public function test_processing_outcome_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ProcessingOutcome::class, [[], [], 0]);
        self::assertSame([0, 0, -1, -1], [$empty[0], $empty[26], $empty[27], $empty[28]]);
        $out = $this->assertParity(ProcessingOutcome::class, [['completed', 'TimedOut', ' Group_Unavailable ', 'deleted par2 only', 'not-found', 'failed', '??'],
            [0, 2, 9, 0, 0, 30, 0], 0]);
        self::assertSame([3, 1, 2, 1], \array_slice($out, 14, 4));
        self::assertSame([1, 1, 1, 1, 2, 1, 1, 1, 3_600, 1, 6], \array_slice($out, 18, 11));
        self::assertSame(5 | 1 << 4 | 3 << 6 | 2 << 11 | 3_600 << 27 | 8 << 47, $out[ProcessingOutcome::W64_OUTCOME_HEADER + 1]);
        $attempts = [PHP_INT_MIN, -1, 0, 1, 4, 5, 6, 15, 16, 17, 65_535, 65_536, PHP_INT_MAX];
        $rows = [];
        foreach (self::OUTCOME_ODD as $at => $outcome) {
            foreach ($attempts as $i => $tries) {
                $rows[] = [$outcome, $attempts[($i + $at) % \count($attempts)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 5, 17, 65_535, PHP_INT_MAX] as $limit) {
            $this->assertParity(ProcessingOutcome::class, [array_column($rows, 0), array_column($rows, 1), $limit]);
        }
        $this->assertParity(ProcessingOutcome::class, [['k' => 'failed', 'j' => 'TimedOut', 3 => str_repeat('f', 300)], [5 => 2], 3]);
    }

    public function test_processing_stage_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ProcessingStage::class, [[], [], 0]);
        self::assertSame([0, 0, -1, -1, -1], [$empty[0], $empty[27], $empty[28], $empty[29], $empty[30]]);
        $out = $this->assertParity(ProcessingStage::class, [['nzb-parsing', 'NzbParsing', 'Workspace preparation', ' MEDIA_SOURCES ', 'Finalization', 'x'],
            [61, 5, 0, 1_201, 10, 100], 1_270]);
        self::assertSame([1, 2, 1, 1, 1], \array_slice($out, 15, 5));
        self::assertSame([3, 1, 1, 1, 3, 2, 2, 1_377, 11, 2, 0], \array_slice($out, 20, 11));
        self::assertSame(11 | 1 << 4 | 1 << 9 | 1 << 11 | 120 << 12 | 10 << 23 | 12 << 54, $out[ProcessingStage::W64_STAGE_HEADER + 4]);
        $elapsed = [PHP_INT_MIN, -1, 0, 29, 30, 31, 600, 1_801, 2_147_483_647, 2_147_483_648, PHP_INT_MAX];
        $rows = [];
        foreach (self::STAGE_ODD as $at => $stage) {
            foreach ($elapsed as $i => $seconds) {
                $rows[] = [$stage, $elapsed[($i * 3 + $at) % \count($elapsed)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 3_600, 1 << 50, (1 << 50) + 1, PHP_INT_MAX] as $budget) {
            $this->assertParity(ProcessingStage::class, [array_column($rows, 0), array_column($rows, 1), $budget]);
        }
        $this->assertParity(ProcessingStage::class, [['k' => 'finalization', 'j' => 'nzb-parsing', 4 => str_repeat('s', 300)], [3 => 9_999], 50]);
    }

    public function test_source_position_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(SourcePosition::class, [[], [], [], 0]);
        self::assertSame([0, -1, -1, -1], [$empty[0], $empty[14], $empty[15], $empty[16]]);
        $out = $this->assertParity(SourcePosition::class, [['complete', 'Head', 'Mid-file', ' tail ', 'middle', '???'], [1_000, 1_000, 5_000, 5_000],
            [0, 0, 4_500, 100, 9], 2_000]);
        self::assertSame([1, 1, 3, 1], \array_slice($out, 1, 4));
        self::assertSame([2, 1, 6_000, 12_000, 2, 5, 2], \array_slice($out, 10, 7));
        self::assertSame(2 | 3 << 2 | 1 << 5 | 400_000 << 9 | 2_000 << 29 | 6 << 60, $out[SourcePosition::W64_POSITION_HEADER + 3]);
        $values = [PHP_INT_MIN, -1, 0, 1, 1_048_575, 1_048_576, 1_048_577, 1 << 30, (1 << 40) - 1, 1 << 40, PHP_INT_MAX];
        $rows = [];
        foreach (self::POSITION_ODD as $at => $position) {
            foreach ($values as $i => $size) {
                $rows[] = [$position, $size, $values[($i * 5 + $at) % \count($values)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 4_096, 1 << 30, (1 << 30) + 1, PHP_INT_MAX] as $window) {
            $this->assertParity(SourcePosition::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2), $window]);
        }
        $this->assertParity(SourcePosition::class, [['k' => 'mid', 'j' => 'head', 2 => str_repeat('m', 300)], [5 => 10], ['x' => 3, 'y' => 4, 'z' => 5, 'w' => 6], 4]);
    }

    public function test_video_container_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(VideoContainer::class, [[], [], []]);
        self::assertSame([0, 0, -1, -1], [$empty[0], $empty[18], $empty[19], $empty[20]]);
        $ts = $this->transportStream(3, 188, 960);
        $out = $this->assertParity(VideoContainer::class, [['movie.mkv', 'clip.mp4', 'stream.avi', 'noext', 'x.mkv'],
            ["\x1A\x45\xDF\xA3 header bytes", '', $ts, '', "\x00\x00\x00\x18ftypisom"], [0, 4_096, 9, 7, 1]]);
        self::assertSame([1, 0, 1, 2, 1], \array_slice($out, 9, 5));
        self::assertSame([1, 1, 1, 2, 9, 2, 3], \array_slice($out, 14, 7));
        self::assertSame(4, $out[VideoContainer::W64_CONTAINER_HEADER + 2] >> 14 & 0xFF);
        $heads = $this->sampleHeads();
        $offsets = [PHP_INT_MIN, -1, 0, 1, 188, 4_194_303, 4_194_304, (1 << 40) - 1, 1 << 40, PHP_INT_MAX];
        $rows = [];
        foreach (self::NAME_ODD as $at => $name) {
            foreach ($heads as $i => $head) {
                $rows[] = [$name, $head, $offsets[($i + $at) % \count($offsets)]];
            }
        }
        $this->assertParity(VideoContainer::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2)]);
        $this->assertParity(VideoContainer::class, [['k' => 'a.mkv', 'j' => 'b.ts', 7 => str_repeat('v', 300).'.mp4'], [3 => $ts], ['x' => 5]]);
    }

    public function test_native_rejects_malformed_buffers(): void
    {
        $this->requireBindings();
        $ffi = RustPipelineLibrary::resolveDomain('additional_w646_test', <<<'C'
            int32_t nntmux_w646_user_role_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t quota, int64_t *out, size_t out_cap);
            int32_t nntmux_w646_video_container_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *head_records, size_t head_records_len, const char *head_lens, size_t head_lens_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
            C);
        self::assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[64]');
        $out[0] = 77;
        $value = pack('q', 9);
        $call = static fn (string $symbol, mixed ...$args): mixed => $ffi->$symbol(...$args);
        $role = static fn (mixed ...$args): mixed => $call('nntmux_w646_user_role_batch', ...$args);
        $container = static fn (mixed ...$args): mixed => $call('nntmux_w646_video_container_batch', ...$args);
        // Short integer column, mismatched twin, count above the separators, a too-small or null output.
        self::assertSame(-1, $role('Admin', 5, '', 0, 'abcd', 4, 1, 0, $out, 64));
        self::assertSame(-1, $role("Admin\0x", 7, "Admin\x02x", 7, $value.$value, 16, 2, 0, $out, 64));
        self::assertSame(-1, $role('Admin', 5, '', 0, $value.$value, 16, 2, 0, $out, 64));
        self::assertSame(-2, $role('Admin', 5, '', 0, $value, 8, 1, 0, $out, 3));
        self::assertSame(-2, $role('Admin', 5, '', 0, $value, 8, 1, 0, null, 64));
        self::assertSame(77, $out[0]);
        // Head records must be exactly one 960-byte record per file.
        self::assertSame(-1, $container('a.mkv', 5, '', 0, str_repeat("\0", 959), 959, pack('V', 0), 4, $value, 8, 1, $out, 64));
        self::assertSame(-1, $container('a.mkv', 5, '', 0, str_repeat("\0", 960), 960, '', 0, $value, 8, 1, $out, 64));
        self::assertSame(1, $container('a.mkv', 5, '', 0, str_repeat("\0", 960), 960, pack('V', 0), 4, $value, 8, 1, $out, 64));
        self::assertSame(1, $out[0]);
        self::assertSame(1, $role('Admin', 5, '', 0, $value, 8, 1, 0, $out, 64));
        self::assertSame(9, $out[UserRole::W64_ROLE_HEADER] >> 21);
    }

    public function test_nul_unicode_and_mismatched_columns_parity(): void
    {
        $this->requireBindings();
        $nul = ["\0", "head\0", "\0\0", "a\x01b", "\x01"];
        $this->assertParity(UserRole::class, [[...$nul, 'Ädmin', '管理者', 2], [1, 2], 5]);
        $this->assertParity(ProcessingOutcome::class, [[...$nul, 'fäiled', 'failed'], [], 2]);
        $this->assertParity(ProcessingStage::class, [[...$nul, 'finalizätion', 'nzb-parsing'], [1, 2, 3, 4, 5, 6, 7, 8, 9], 3]);
        $this->assertParity(SourcePosition::class, [[...$nul, 'mïd', 'mid'], [10], [], 0]);
        $this->assertParity(VideoContainer::class, [[...$nul, 'fïlm.mkv', "x\0.ts"], ["\0\0\x01\xBA\0\0\0\0\0\0\0\0", "\x01"], [1, 2, 3]]);
        foreach (array_keys(self::WAVE) as $class) {
            $out = $this->assertParity($class, $this->benchmarkArgs($class, 0, true, 1));
            self::assertSame(0, $out[0]);
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 646];
            yield $short.' uncommon' => [$class, 384, true, 647];
            yield $short.' large common' => [$class, 4_096, false, 648];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_640 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set RUN_RUST_FFI_BENCHMARKS=1): seed 646, 8 fixture variants, 50 warmups and
     * NNTMUX_W646_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 aliased, padded, unknown,
     * Unicode, NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired Wave 64.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W646_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(646));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 646 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW64RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 646, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w646-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW64.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW64RustFfi()) {
                self::assertSame($eligible, $class::usesW64RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            UserRole::class => $this->roleBatch($random, $count, $uncommon),
            ProcessingOutcome::class => $this->outcomeBatch($random, $count, $uncommon),
            ProcessingStage::class => $this->stageBatch($random, $count, $uncommon),
            SourcePosition::class => $this->positionBatch($random, $count, $uncommon),
            default => $this->containerBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /**
     * An odd token: listed aliases or unknowns, NUL-holding, random bytes or oversized repeats of one.
     *
     * @param  list<string>  $odd
     */
    private function oddToken(Randomizer $random, array $odd): string
    {
        $token = $this->pick($random, $odd);

        return match ($random->getInt(0, 7)) {
            0 => $token."\0".$token,
            1 => substr($random->getBytes(24), 0, $random->getInt(0, 24)),
            2 => str_repeat($token === '' ? 'x' : $token, $random->getInt(40, 120)),
            3 => ' '.strtoupper($token)."\t",
            default => $token,
        };
    }

    /**
     * Stored role ids with requests in the current window and a quota; uncommon ones are names, labels,
     * aliases, unknown, Unicode, NUL-holding or oversized tokens and extreme requests and quotas.
     *
     * @return list<mixed>
     */
    private function roleBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $roles = $requests = [];
        for ($i = 0; $i < $count; $i++) {
            $role = $this->pick($random, [1, 1, 1, 1, 1, 1, 2, 3, 4, 4]);
            $used = $random->getInt(0, 800);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $role = $this->oddToken($random, self::ROLE_ODD),
                    3 => $used = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $roles[] = $role;
            $requests[] = $used;
        }
        $quota = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 31, PHP_INT_MAX]) : $this->pick($random, [0, 100, 500]);

        return [$roles, $requests, $quota];
    }

    /**
     * Stored outcomes with attempts so far and an attempt limit; uncommon ones are case names, normalized
     * aliases, unknown, Unicode, NUL-holding or oversized tokens and extreme attempts and limits.
     *
     * @return list<mixed>
     */
    private function outcomeBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $outcomes = $attempts = [];
        $common = array_map(static fn (ProcessingOutcome $case): string => $case->value, ProcessingOutcome::cases());
        for ($i = 0; $i < $count; $i++) {
            $outcome = $random->getInt(0, 2) === 0 ? 'completed' : $this->pick($random, $common);
            $tries = $random->getInt(0, 7);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $outcome = $this->oddToken($random, self::OUTCOME_ODD),
                    3 => $tries = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $outcomes[] = $outcome;
            $attempts[] = $tries;
        }
        $limit = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 20, PHP_INT_MAX]) : $this->pick($random, [0, 3, 5]);

        return [$outcomes, $attempts, $limit];
    }

    /**
     * Stage traces (mostly in pipeline order with occasional repeats or skips) with elapsed seconds and a
     * budget; uncommon ones are names, labels, normalized aliases, unknown, Unicode, NUL-holding or oversized
     * tokens and extreme elapsed values and budgets.
     *
     * @return list<mixed>
     */
    private function stageBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $stages = $elapsed = [];
        $cases = ProcessingStage::cases();
        $rank = 0;
        for ($i = 0; $i < $count; $i++) {
            $rank = match ($random->getInt(0, 9)) {
                0 => 0,
                1 => $rank,
                2 => min($rank + 2, 12),
                default => ($rank + 1) % 13,
            };
            $stage = $cases[$rank]->value;
            $seconds = $random->getInt(0, 2_000);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $stage = $this->oddToken($random, self::STAGE_ODD),
                    3 => $seconds = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $stages[] = $stage;
            $elapsed[] = $seconds;
        }
        $budget = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 50, PHP_INT_MAX]) : $this->pick($random, [0, 3_600, 86_400]);

        return [$stages, $elapsed, $budget];
    }

    /**
     * Media source positions with file sizes, requested offsets and a read window; uncommon ones are names,
     * labels, aliases, unknown, Unicode, NUL-holding or oversized tokens and extreme sizes, offsets and windows.
     *
     * @return list<mixed>
     */
    private function positionBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $positions = $sizes = $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $position = $this->pick($random, ['complete', 'head', 'head', 'head', 'mid', 'mid']);
            $size = $random->getInt(0, 8_000_000_000);
            $offset = $random->getInt(0, $size + 1_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $position = $this->oddToken($random, self::POSITION_ODD),
                    3 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $offset = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $positions[] = $position;
            $sizes[] = $size;
            $offsets[] = $offset;
        }
        $window = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 31, PHP_INT_MAX]) : $this->pick($random, [0, 4_194_304, 16_777_216]);

        return [$positions, $sizes, $offsets, $window];
    }

    /**
     * Downloaded file names with their first bytes and chunk offsets; uncommon ones are quoted, multi-dot,
     * unknown, Unicode, NUL-holding or oversized names, conflicting, short or random heads and extreme offsets.
     *
     * @return list<mixed>
     */
    private function containerBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = $heads = $offsets = [];
        $samples = $this->sampleHeads();
        $common = ['mkv' => 0, 'avi' => 1, 'mpg' => 2, 'ts' => 3, 'mp4' => 4, 'wmv' => 5, 'ogv' => 6];
        for ($i = 0; $i < $count; $i++) {
            $extension = $this->pick($random, ['mkv', 'mkv', 'mkv', 'mp4', 'mp4', 'avi', 'ts', 'mpg', 'wmv', 'ogv']);
            $name = 'Some.Release.Name.'.$random->getInt(1, 9_999).'.'.$extension;
            $head = $samples[$common[$extension]].$random->getBytes($random->getInt(64, 1_200));
            $offset = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 50_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1 => $name = $this->oddToken($random, self::NAME_ODD),
                    2 => $head = $this->pick($random, $samples),
                    3 => $head = substr($random->getBytes(1_500), 0, $random->getInt(0, 1_500)),
                    4 => $offset = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $names[] = $name;
            $heads[] = $head;
            $offsets[] = $offset;
        }

        return [$names, $heads, $offsets];
    }

    /**
     * Magic-signature heads (Matroska, AVI, MPEG program, MPEG transport, MP4, ASF, Ogg), then a 192-byte
     * transport stream, a short, an empty and an unrecognised head.
     *
     * @return list<string>
     */
    private function sampleHeads(): array
    {
        return [
            "\x1A\x45\xDF\xA3".str_repeat("\x42", 28),
            'RIFF'."\x10\x00\x00\x00".'AVI LIST'.str_repeat("\0", 20),
            "\x00\x00\x01\xBA".str_repeat("\x44", 28),
            $this->transportStream(5, 188, 1_000),
            "\x00\x00\x00\x20ftypisom".str_repeat("\0", 20),
            "\x30\x26\xB2\x75\x8E\x66\xCF\x11".str_repeat("\xA6", 24),
            'OggS'.str_repeat("\x02", 28),
            $this->transportStream(17, 192, 980),
            "\x1A\x45\xDF",
            '',
            str_repeat("\x47", 11).str_repeat('?', 40),
        ];
    }

    private function transportStream(int $offset, int $stride, int $length): string
    {
        $data = str_repeat("\xFF", $length);
        for ($at = $offset; $at < $length; $at += $stride) {
            $data[$at] = "\x47";
        }

        return $data;
    }

    /**
     * PHP routing, Rust routing, the PHP and native methods agree; returns the PHP result.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW64RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW64RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (UserRole::w64UserRolePlanBatchNative([], []) === null
            || ProcessingOutcome::w64ProcessingOutcomePlanBatchNative([], []) === null
            || ProcessingStage::w64ProcessingStagePlanBatchNative([], []) === null
            || SourcePosition::w64SourcePositionPlanBatchNative([], [], []) === null
            || VideoContainer::w64VideoContainerPlanBatchNative([], [], []) === null
        ) {
            self::markTestSkipped('The Wave 64.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
