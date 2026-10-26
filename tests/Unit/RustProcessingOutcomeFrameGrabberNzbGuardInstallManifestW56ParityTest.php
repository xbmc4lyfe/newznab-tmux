<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Services\Nzb\NzbUploadManifestService;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustProcessingOutcomeFrameGrabberNzbGuardInstallManifestW56ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ProcessingOutcome::class => 'parseOutcomeTokenBatch',
        FfmpegFrameGrabber::class => 'classifyFrameBatch',
        NzbSourceGuard::class => 'validateAnchorBatch',
        NzbSourceInstallation::class => 'classifyPathPairBatch',
        NzbUploadManifestService::class => 'classifyManifestEntryBatch',
    ];

    /** Header words before the per-record rows. */
    private const HEADER = [
        ProcessingOutcome::class => ProcessingOutcome::W56_OUTCOME_HEADER,
        FfmpegFrameGrabber::class => FfmpegFrameGrabber::W56_FRAME_HEADER,
        NzbSourceGuard::class => NzbSourceGuard::W56_ANCHOR_HEADER,
        NzbSourceInstallation::class => NzbSourceInstallation::W56_PAIR_HEADER,
        NzbUploadManifestService::class => NzbUploadManifestService::W56_MANIFEST_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 78d554b26 plus
     * this change, seed 566, 50 warmups and 1,000 pairs/workload (w566-benchmark-c7765512781b632b.json in
     * system tmp). Common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust time); a default is
     * Rust only when all four reach 1.5x. All five classes exceed 1.5x on every metric.
     */
    private const QUALIFICATION = [
        ProcessingOutcome::class => [4.577, 3.600, 3.893, 2.993],
        FfmpegFrameGrabber::class => [5.536, 4.001, 3.646, 3.223],
        NzbSourceGuard::class => [2.898, 2.445, 1.884, 1.575],
        NzbSourceInstallation::class => [3.730, 3.218, 2.822, 2.494],
        NzbUploadManifestService::class => [3.544, 3.285, 2.406, 2.508],
    ];

    private const OUTCOMES = [
        'completed', 'no-useful-artifacts', 'passworded', 'group-unavailable', 'temporary-workspace-unavailable', 'timed-out',
        'deleted-after-timeout', 'deleted-broken-nzb', 'deleted-par2-only', 'not-found', 'awaiting-late-articles', 'failed',
    ];

    private const STATES = [
        'staged', 'nzb_imported', 'nzb_duplicate', 'nzb_failed', 'nzb_pending', 'metadata_review', 'metadata_deferred', 'nzb_rejected',
        'nfo_imported', 'nfo_failed',
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW56RustFfi', 'withW56RustFfi', 'w56RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w56RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW56RustFfi', 'withW56RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W56_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w566_', (string) $reflection->getConstant('W56_FFI_CDEF'), $class);
        }
        self::assertTrue((new ReflectionClass(ProcessingOutcome::class))->isEnum());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        $earlier = static fn (): array => [
            ProcessingOutcome::usesRustFfi(),
            ProcessingOutcome::usesW50RustFfi(),
            FfmpegFrameGrabber::usesRustFfi(),
            FfmpegFrameGrabber::usesW49RustFfi(),
            NzbSourceGuard::usesRustFfi(),
            NzbSourceGuard::usesW422RustFfi(),
            NzbSourceInstallation::usesRustFfi(),
            NzbSourceInstallation::usesW422RustFfi(),
            NzbUploadManifestService::usesRustFfi(),
            NzbUploadManifestService::usesW402RustFfi(),
        ];
        $before = $earlier();
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W56_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW56RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW56RustFfi($enabled, function () use ($class, $enabled, $earlier, $before): bool {
                    $inner = $class::withW56RustFfi(! $enabled, static fn (): bool => $class::usesW56RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W56_RUST_FFI_DEFAULT'), $other::usesW56RustFfi());
                        }
                    }
                    // The Wave 56 switches leave the earlier toggles alone.
                    self::assertSame($before, $earlier());

                    return $class::usesW56RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW56RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW56RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW56RustFfi(), $class);
        }
        self::assertSame($before, $earlier());
    }

    public function test_outcome_token_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ProcessingOutcome::class, [[]]);
        self::assertSame([0, 0], [$empty[0], $empty[21]]);
        self::assertSame([-1, -1], [$empty[19], $empty[20]]);
        $out = $this->assertParity(ProcessingOutcome::class, [['completed', "  Timed_Out\n", 'DELETED BROKEN NZB', 'bogus', 'failed', 'awaiting-late-articles']]);
        self::assertSame([6, 1, 0, 0, 0, 0, 1, 0, 1, 0, 0, 1, 1, 1, 1, 1, 2, 2, 2, 3, 1, 166_666], \array_slice($out, 0, 22));
        $edge = [
            ...self::OUTCOMES, '', ' ', "\0", "completed\0", "\0completed", "\x0Bfailed\r", "\x0Cfailed", 'COMPLETED', 'Not_Found', 'not found',
            'timed--out', 'timed out ', 'deleted_par2_only', 'Deleted-After-Timeout', str_repeat('x', 31), str_repeat('x', 32),
            str_repeat(' ', 40).'passworded', "passworded\xC3\xA9", "\xC3\x89", 'İfailed', 'failed.', '-failed', str_repeat('a', 70_000),
            'temporary_workspace_unavailable', 'TEMPORARY WORKSPACE UNAVAILABLE ', 'no-useful-artifacts ', "\tno_useful_artifacts\t",
        ];
        $out = $this->assertParity(ProcessingOutcome::class, [$edge]);
        foreach ($edge as $i => $token) {
            $row = $out[ProcessingOutcome::W56_OUTCOME_HEADER + $i];
            $case = ProcessingOutcome::resolvePhp(strtr(strtolower(trim($token)), '_ ', '--'));
            self::assertSame($case === null ? 12 : array_search($case, ProcessingOutcome::cases(), true), $row & 0xF, $token);
            self::assertSame($case?->isSuccessfulPhp() ?? false, ($row >> 4 & 1) === 1, $token);
            self::assertSame($case?->isDeletedPhp() ?? false, ($row >> 5 & 1) === 1, $token);
        }
        $this->assertParity(ProcessingOutcome::class, [['x' => 'failed', 'y' => ' FAILED']]);
    }

    public function test_frame_output_parity(): void
    {
        $this->requireBindings();
        $this->assertParity(FfmpegFrameGrabber::class, [[], [], 0, 0]);
        $paths = [
            '/tmp/x/frame_0a1b2c3d_01.jpg', '/tmp/x/frame_0a1b2c3d_03.jpg', '/tmp/x/frame_0a1b2c3d_02.jpg', '/tmp/x/frame_ffffffff_04.jpg',
            'frame_0a1b2c3d_05.jpg', '/tmp/x/frame_0A1B2C3D_06.jpg',
        ];
        $out = $this->assertParity(FfmpegFrameGrabber::class, [$paths, [10, 20, 30, 40, 0, 50], 1_500, 2_000]);
        self::assertSame([6, 2, 1, 1, 1, 1, 30, 1, 3, 1, 1_500, 2_000], \array_slice($out, 0, 12));
        $edge = [
            'frame_00000000_00.jpg', 'frame_00000000_123456789.jpg', 'frame_00000000_1234567890.jpg', 'frame_00000000_1.jpg', 'frame_0000000_01.jpg',
            'frame_000000000_01.jpg', 'frame_00000000_01.jpeg', 'frame_00000000_01.JPG', 'frame_00000000_01.jpg/', '/frame_00000000_02.jpg',
            '//frame_00000000_03.jpg', "frame_00000000_04.jpg\n", "frame_00000000_05.jpg\0", "\0/frame_00000000_06.jpg", 'Frame_00000000_07.jpg',
            'frame_00000000-08.jpg', 'frame_00000000_0x.jpg', 'frame_00000000_٠١.jpg', 'frame_g0000000_09.jpg', 'a/b/c/frame_00000000_10.jpg',
            'frame_00000000_10.jpg', 'frame_00000000_999999999.jpg', 'frame_00000000_11.jpg', '', '/', 'frame_.jpg', str_repeat('/', 20),
        ];
        $count = \count($edge);
        foreach ([[0, 0], [-5, -5], [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, 1], [1_000, 33]] as [$from, $spacing]) {
            $sizes = array_map(static fn (int $i): int => match ($i % 6) {
                0 => 0, 1 => -1, 2 => PHP_INT_MAX, 3 => PHP_INT_MIN, default => 1_000 * $i
            }, range(0, $count - 1));
            $this->assertParity(FfmpegFrameGrabber::class, [$edge, $sizes, $from, $spacing]);
            $this->assertParity(FfmpegFrameGrabber::class, [$edge, array_fill(0, $count, 5), $from, $spacing]);
        }
        $this->assertParity(FfmpegFrameGrabber::class, [['k' => 'frame_00000000_01.jpg', 'j' => 'frame_00000000_02.jpg'], [9 => 4], 0, 1_000]);
    }

    public function test_anchor_receipt_parity(): void
    {
        $this->requireBindings();
        $this->assertParity(NzbSourceGuard::class, [[], [], [], [], [], [], []]);
        $anchor = '/d/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor';
        $receipts = [
            [1, 'inode', 'abc', '/d/abc.nzb.gz', $anchor, '12', '34'],
            [1, 'absent', 'abc', '/e/abc.nzb', '', '', ''],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '', '12', '34'],
            [1, 'bogus', 'abc', '/d/abc.nzb', $anchor, '1', '1'],
            [1, 'inode', 'a/c', '/d/abc.nzb', $anchor, '1', '1'],
            [2, 'inode', 'abc', '/d/abc.nzb.gz', $anchor, '12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '/e/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor', '12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '/d/.archive-audit-abc-0123456789ABCDEF0123456789abcdef.anchor', '12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '/d/.archive-audit-abd-0123456789abcdef0123456789abcdef.anchor', '12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '/d/.archive-audit-abc-0123456789abcdef0123456789abcde.anchor', '12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', $anchor, '-12', '34'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', $anchor, '12', '3 4'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', $anchor, "1\0", '34'],
            [1, 'inode', "ab\0c", '/d/abc.nzb.gz', $anchor, '12', '34'],
            [1, 'absent', 'abc', 'abc.nzb.gz/', '', '', ''],
            [1, 'absent', 'abc', 'abc.nzb.gz', '', '', ''],
            [1, 'absent', 'abc', '/abc.nzb.gzip', '', '', ''],
            [1, 'absent', 'ABC_def-1', '/x/ABC_def-1.nzb', '', '', ''],
            [1, 'absent', str_repeat('a', 40), '/x/'.str_repeat('a', 40).'.nzb', '', '', ''],
            [1, 'absent', str_repeat('a', 41), '/x/'.str_repeat('a', 41).'.nzb', '', '', ''],
            [1, 'absent', 'a.b', '/x/a.b.nzb', '', '', ''],
            [1, 'inode', 'abc', 'abc.nzb.gz', '.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor', '0', '0'],
            [1, 'inode', 'abc', '/abc.nzb.gz', '/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor', '0', '0'],
            [1, 'inode', 'abc', '/d//abc.nzb.gz', '/d/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor', '0', '0'],
            [1, 'inode', 'abc', '/d/abc.nzb.gz', '/d/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor/', '0', '0'],
            [1, 'inode', 'abc', "/d\xFF/abc.nzb.gz", "/d\xFF/.archive-audit-abc-0123456789abcdef0123456789abcdef.anchor", '0', '0'],
            [1, 'INODE', 'abc', '/d/abc.nzb.gz', $anchor, '12', '34'],
            [0, 'absent', 'abc', '/d/abc.nzb', '', '', ''],
            [PHP_INT_MIN, 'absent', 'abc', '/d/abc.nzb', '', '', ''],
            [1, '', '', '', '', '', ''],
            [1, 'absent', '', '/d/.nzb', '', '', ''],
            [1, 'absent', 'abc', '', '', '', ''],
        ];
        $columns = array_map(null, ...$receipts);
        $out = $this->assertParity(NzbSourceGuard::class, $columns);
        self::assertSame([\count($receipts), 6, 5, 6, 5], \array_slice($out, 0, 5));
        foreach ($receipts as $i => [$version, $kind, $guid, $path, $anchorPath, $dev, $ino]) {
            try {
                $valid = NzbSourceGuard::validateAnchorPhp(['version' => $version, 'kind' => $kind, 'guid' => $guid, 'path' => $path,
                    'anchor' => $anchorPath, 'dev' => $dev, 'ino' => $ino]);
                $expected = $valid['kind'] === 'absent' ? 1 : 0;
            } catch (RuntimeException $e) {
                $expected = match (true) {
                    str_starts_with($e->getMessage(), 'Unsupported') => 2,
                    str_starts_with($e->getMessage(), 'Malformed') => 3,
                    default => 4,
                };
            }
            self::assertSame($expected, $out[NzbSourceGuard::W56_ANCHOR_HEADER + $i] & 7, (string) $i);
        }
        // Missing trailing columns are empty strings.
        $this->assertParity(NzbSourceGuard::class, [[1, 1], ['absent'], ['abc', 'abc'], ['/d/abc.nzb'], [], [], []]);
        $this->assertParity(NzbSourceGuard::class, [['a' => 1, 'b' => 1], ['x' => 'absent', 'y' => 'absent'], ['abc', 'abd'], ['/d/abc.nzb', '/d/abd.nzb.gz'], [], [], []]);
    }

    public function test_install_pair_parity(): void
    {
        $this->requireBindings();
        $this->assertParity(NzbSourceInstallation::class, [[], []]);
        $out = $this->assertParity(NzbSourceInstallation::class, [
            ['/n/abc.nzb.gz', '/n/abc.nzb.gz', '/n/abc.nzb.gz', '/n/abc.nzb.gz', ''],
            ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c42-0123456789abcdef.next', '/n/abd.nzb.gz.c42-0123456789abcdef.next', '/m/abc.nzb.gz', '/m/x'],
        ]);
        self::assertSame([5, 1, 1, 1, 1, 1, 4, 3, 4, 2], \array_slice($out, 0, 10));
        $hex = '0123456789abcdef';
        $pairs = [
            ['abc.nzb.gz', 'abc.nzb.gz.c1-'.$hex.'.next'], ['/abc.nzb.gz', '/abc.nzb.gz.c1-'.$hex.'.next'], ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c-'.$hex.'.next'],
            ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c1-'.strtoupper($hex).'.next'], ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c1-'.$hex.'.next.'], ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c1-'.$hex.'0.next'],
            ['/n/a.nzb.gz.c1.nzb.gz', '/n/a.nzb.gz.c1.nzb.gz.c2-'.$hex.'.next'], ['/n/a'."\n".'b.nzb.gz', '/n/a'."\n".'b.nzb.gz.c2-'.$hex.'.next'],
            ['/n/a'."\r".'b.nzb.gz', '/n/a'."\r".'b.nzb.gz.c2-'.$hex.'.next'], ['/n/.nzb.gz', '/n/.nzb.gz.c2-'.$hex.'.next'], ['/n/abc.nzb', '/n/abc.nzb.c2-'.$hex.'.next'],
            ['/n/x/abc.nzb.gz', '/n/y/abc.nzb.gz.c9-'.$hex.'.next'], ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c'.str_repeat('9', 300).'-'.$hex.'.next'],
            ["/n/\0abc.nzb.gz", "/n/\0abc.nzb.gz.c1-".$hex.'.next'], ['/', '/'], ['/', '//'], ['a', 'b'], ['', ''], ['', 'x'], ['x', ''],
            ['/n/abc.nzb.gz', 'abc.nzb.gz.c1-'.$hex.'.next'], ["/n\xFF/abc.nzb.gz", "/n\xFF/abc.nzb.gz.c7-".$hex.'.next'],
            ['/n/abc.NZB.GZ', '/n/abc.NZB.GZ.c7-'.$hex.'.next'], ['/n/abc.nzb.gz', '/n/abc.nzb.gz.c٣-'.$hex.'.next'],
        ];
        $out = $this->assertParity(NzbSourceInstallation::class, array_map(null, ...$pairs));
        foreach ($pairs as $i => [$source, $destination]) {
            $row = $out[NzbSourceInstallation::W56_PAIR_HEADER + $i];
            $normalized = NzbSourceInstallation::normalizeSourcePathPairPhp($source, $destination);
            self::assertSame($normalized['staged'], ($row >> 3 & 1) === 1, $destination);
            self::assertSame($normalized['sibling'], ($row >> 4 & 1) === 1, $destination);
            $guid = NzbSourceGuard::stagedGuidPhp(basename($destination));
            $generation = $guid !== null && $destination !== '' && str_ends_with($source, '/'.$guid.'.nzb.gz') || ($guid !== null && $source === $guid.'.nzb.gz');
            self::assertSame($generation && $normalized['sibling'], ($row >> 7 & 1) === 1 && $normalized['sibling'], $destination);
        }
        $this->assertParity(NzbSourceInstallation::class, [['k' => '/n/a.nzb.gz', 'j' => '/n/b'], [7 => '/n/a.nzb.gz.c1-'.$hex.'.next']]);
    }

    public function test_manifest_entry_parity(): void
    {
        $this->requireBindings();
        $this->assertParity(NzbUploadManifestService::class, [[], [], [], [], [], [], []]);
        $digest = str_repeat('a', 64);
        $identity = '0123456789abcdef0123456789abcdef01234567';
        $out = $this->assertParity(NzbUploadManifestService::class, [
            [2, 2, 2, 1], ['nzb_pending', 'metadata_review', 'staged', 'weird'], ['a.nzb', 'b.NZB', '../c.nzb', 'd.nzb'], ['', 'a.nfo', '', 'x.txt'],
            ['', $identity, '', ''], [$digest, '', '', ''], [7, 0, 0, 0],
        ]);
        self::assertSame([4, 2, 0, 1, 1, 0], \array_slice($out, 0, 6));
        self::assertSame([2, 2, 1, 7, 2], \array_slice($out, 17, 5));
        $names = [
            'a.nzb', 'A.NZB', '.nzb', ' .nzb', "\t.nzb", 'a .nzb', '..nzb', '...nzb', 'a.nzb.', 'a.b.nzb', 'dir/a.nzb', 'a.nzb/', '/a.nzb', "a\0.nzb",
            '.', '..', '', 'nzb', 'a.nzbx', 'a.nfo', 'Á.nzb', "\xFF.nzb", 'a.Nzb', "\0", 'a\\b.nzb', 'x.NFO', '.nfo', ' x .nfo ',
        ];
        $count = \count($names);
        $ints = static fn (array $values): array => array_map(static fn (int $i): int => $values[$i % \count($values)], range(0, $count - 1));
        $strings = static fn (array $values): array => array_map(static fn (int $i): string => $values[$i % \count($values)], range(0, $count - 1));
        $args = [
            $ints([2, 1, 0, 3, -1, PHP_INT_MAX, 2, 2]), $strings([...self::STATES, 'NZB_PENDING', '', 'staged ']), $names, array_reverse($names),
            $strings([$identity, strtoupper($identity), substr($identity, 1), $identity.'0', '', $identity."\n"]),
            $strings([$digest, '', strtoupper($digest), substr($digest, 1), $digest.'0', str_repeat('g', 64), "\0".substr($digest, 1)]),
            $ints([0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, 1 << 40]),
        ];
        $out = $this->assertParity(NzbUploadManifestService::class, $args);
        foreach ($names as $i => $name) {
            $row = $out[NzbUploadManifestService::W56_MANIFEST_HEADER + $i];
            $status = $row & 7;
            if ($status === 1) {
                continue;
            }
            self::assertSame($this->safe($name, 'nzb'), $status !== 2, $name);
            if ($status !== 2 && $args[3][$i] !== '') {
                self::assertSame($this->safe($args[3][$i], 'nfo'), $status !== 3, $args[3][$i]);
            }
            $reconcilable = NzbUploadManifestService::canReconcileCandidatePhp(['state' => $args[1][$i], 'candidate_identity' => $args[4][$i]]);
            self::assertSame($reconcilable, ($row >> 7 & 1) === 1, $args[1][$i]);
        }
        $this->assertParity(NzbUploadManifestService::class, [['a' => 2, 'b' => 2], ['staged'], ['k' => 'a.nzb', 'j' => 'b.nzb'], [], [], [9 => $digest], []]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 566];
            yield $short.' uncommon' => [$class, 384, true, 567];
            yield $short.' large common' => [$class, 2_048, false, 568];
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
        self::assertCount(self::HEADER[$class] + $count, $out);
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_000 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W566_BENCH=1): seed 566, 8 fixture variants, 50 warmups and
     * NNTMUX_W566_BENCH_PAIRS (default 1,000, at least 15) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 padded, mixed-case,
     * empty, invalid or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W566_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W566_BENCH=1 to run the paired Wave 56.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(15, (int) (getenv('NNTMUX_W566_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(566));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 566 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW56RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 566, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w566-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW56.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW56RustFfi()) {
                self::assertSame($eligible, $class::usesW56RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
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
            ProcessingOutcome::class => $this->outcomeBatch($random, $count, $uncommon),
            FfmpegFrameGrabber::class => $this->frameBatch($random, $count, $uncommon),
            NzbSourceGuard::class => $this->anchorBatch($random, $count, $uncommon),
            NzbSourceInstallation::class => $this->pairBatch($random, $count, $uncommon),
            default => $this->manifestBatch($random, $count, $uncommon),
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
     * Stored and logged outcome codes; uncommon ones are padded, mixed-case, underscored, unknown,
     * empty, NUL-holding or oversized.
     *
     * @return list<list<string>>
     */
    private function outcomeBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $token = self::OUTCOMES[$random->getInt(0, 3) === 0 ? $random->getInt(0, 11) : $random->getInt(0, 2)];
            if ($random->getInt(0, 4) === 0) {
                $token = strtoupper(str_replace('-', '_', $token));
            }
            if ($uncommon) {
                $token = match ($random->getInt(0, 11)) {
                    0 => '  '.$token."\n", 1 => ucwords(str_replace('-', ' ', $token)), 2 => 'unknown-'.$random->getInt(0, 99), 3 => '',
                    4 => $token."\0", 5 => str_repeat($token, 4), 6 => "\t".strtoupper($token)."\r", 7 => $token.'é', default => $token
                };
            }
            $tokens[] = $token;
        }

        return [$tokens];
    }

    /**
     * Frames of one grab() run; uncommon ones hold empty, foreign, renamed, reordered, NUL-holding or
     * extreme entries.
     *
     * @return list<mixed>
     */
    private function frameBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $directory = '/var/www/nntmux/resources/tmp/pp/'.bin2hex($random->getBytes(6)).'/';
        $token = bin2hex($random->getBytes(4));
        $paths = $sizes = [];
        $number = 0;
        for ($i = 0; $i < $count; $i++) {
            $number += $random->getInt(0, 5) === 0 ? 2 : 1;
            $path = $directory.'frame_'.$token.'_'.sprintf('%02d', $number).'.jpg';
            $size = $random->getInt(8_000, 400_000);
            if ($uncommon) {
                $roll = $random->getInt(0, 11);
                $path = match ($roll) {
                    0 => $directory.'frame_'.bin2hex($random->getBytes(4)).'_'.sprintf('%02d', $number).'.jpg',
                    1 => $directory.'frame_'.$token.'_'.sprintf('%02d', max(0, $number - 3)).'.jpg', 2 => $directory.'frame_'.$token.'_'.$number.'.png',
                    3 => $path."\0", 4 => strtoupper($path), 5 => 'frame_'.$token.'_'.sprintf('%09d', $number).'.jpg', 6 => '', default => $path
                };
                $size = match ($roll) {
                    7 => 0, 8 => -1, 9 => PHP_INT_MAX, default => $size
                };
            }
            $paths[] = $path;
            $sizes[] = $size;
        }

        return [$paths, $sizes, $uncommon ? $random->getInt(-1_000, 1_000_000_000) : $random->getInt(0, 600_000), $uncommon ? $random->getInt(-5, 1 << 31) : $random->getInt(1_000, 30_000)];
    }

    /**
     * Audit deletion receipts; uncommon ones carry other versions or kinds, empty or NUL fields,
     * foreign paths, uppercase or short anchors and non-decimal inode numbers.
     *
     * @return list<list<int|string>>
     */
    private function anchorBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill(0, 7, []);
        for ($i = 0; $i < $count; $i++) {
            $guid = bin2hex($random->getBytes(20));
            $directory = '/var/www/nntmux/resources/nzb/'.$guid[0].'/'.$guid[1].'/'.$guid[2];
            $absent = $random->getInt(0, 4) === 0;
            $receipt = [1, $absent ? 'absent' : 'inode', $guid, $directory.'/'.$guid.($random->getInt(0, 9) === 0 ? '.nzb' : '.nzb.gz'),
                $absent ? '' : $directory.'/.archive-audit-'.$guid.'-'.bin2hex($random->getBytes(16)).'.anchor',
                $absent ? '' : (string) $random->getInt(16_777_220, 16_777_240), $absent ? '' : (string) $random->getInt(1, 90_000_000)];
            if ($uncommon) {
                $roll = $random->getInt(0, 13);
                match ($roll) {
                    0 => $receipt[0] = 2, 1 => $receipt[1] = 'database', 2 => $receipt[2] = '', 3 => $receipt[3] .= "\0",
                    4 => $receipt[3] = '/elsewhere/'.$guid.'.nzb.gz', 5 => $receipt[4] = strtoupper($receipt[4]), 6 => $receipt[5] = '-'.$receipt[5],
                    7 => $receipt[6] = $receipt[6].'x', 8 => $receipt[2] = $guid.'.', 9 => $receipt[4] = '/tmp/'.basename($receipt[4]),
                    10 => $receipt[3] .= '/', default => null,
                };
            }
            foreach ($receipt as $c => $value) {
                $columns[$c][] = $value;
            }
        }

        return $columns;
    }

    /**
     * Installation source and destination paths; uncommon ones are empty, relative, NUL-holding,
     * misnamed or relocated.
     *
     * @return list<list<string>>
     */
    private function pairBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $sources = $destinations = [];
        for ($i = 0; $i < $count; $i++) {
            $guid = bin2hex($random->getBytes(20));
            $directory = '/var/www/nntmux/resources/nzb/'.$guid[0].'/'.$guid[1].'/'.$guid[2].'/';
            $source = $directory.$guid.'.nzb.gz';
            $generation = $source.'.c'.$random->getInt(1, 99_999_999).'-'.bin2hex($random->getBytes(8)).'.next';
            $destination = match ($random->getInt(0, 3)) {
                0 => $source, 3 => $directory.bin2hex($random->getBytes(20)).'.nzb.gz', default => $generation
            };
            if ($uncommon) {
                $roll = $random->getInt(0, 11);
                $source = match ($roll) {
                    0 => '', 1 => $guid.'.nzb.gz', 2 => $source."\0", default => $source
                };
                $destination = match ($roll) {
                    1 => basename($generation), 3 => '', 4 => strtoupper($generation), 5 => '/tmp/'.basename($generation), 6 => $generation.'.tmp',
                    7 => str_replace('.next', '0.next', $generation), default => $destination
                };
            }
            $sources[] = $source;
            $destinations[] = $destination;
        }

        return [$sources, $destinations];
    }

    /**
     * Upload manifest entries; uncommon ones carry other versions, unsafe or mixed-case names,
     * unknown states, malformed identities and captures, or extreme byte counts.
     *
     * @return list<list<int|string>>
     */
    private function manifestBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill(0, 7, []);
        for ($i = 0; $i < $count; $i++) {
            $stem = 'Some.Show.S0'.$random->getInt(1, 9).'E'.sprintf('%02d', $random->getInt(1, 24)).'.1080p.WEB.H264-GRP';
            $state = self::STATES[$random->getInt(0, 9)];
            $captured = $random->getInt(0, 2) === 0;
            $entry = [$random->getInt(0, 9) === 0 ? 1 : 2, $state, $stem.'.nzb', $random->getInt(0, 1) === 0 ? $stem.'.nfo' : '',
                \in_array($state, ['metadata_review', 'metadata_deferred'], true) ? bin2hex($random->getBytes(20)) : '',
                $captured ? bin2hex($random->getBytes(32)) : '', $captured ? $random->getInt(100, 64_000) : 0];
            if ($uncommon) {
                match ($random->getInt(0, 13)) {
                    0 => $entry[0] = 3, 1 => $entry[2] = '../'.$entry[2], 2 => $entry[2] = strtoupper($entry[2]), 3 => $entry[3] = $stem.'.txt',
                    4 => $entry[1] = 'state_'.$random->getInt(0, 9), 5 => $entry[4] = strtoupper(bin2hex($random->getBytes(20))),
                    6 => $entry[5] = strtoupper(bin2hex($random->getBytes(32))), 7 => $entry[6] = -1, 8 => $entry[6] = PHP_INT_MAX,
                    9 => $entry[2] = ' .nzb', 10 => $entry[2] .= "\0", 11 => $entry[5] = bin2hex($random->getBytes(31)), default => null,
                };
            }
            foreach ($entry as $c => $value) {
                $columns[$c][] = $value;
            }
        }

        return $columns;
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
        $php = $class::withW56RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW56RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function safe(string $filename, string $extension): bool
    {
        try {
            NzbUploadManifestService::assertSafeFilenamePhp($filename, $extension);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function requireBindings(): void
    {
        if (ProcessingOutcome::parseOutcomeTokenBatchNative([]) === null
            || FfmpegFrameGrabber::classifyFrameBatchNative([], [], 0, 0) === null
            || NzbSourceGuard::validateAnchorBatchNative([], [], [], [], [], [], []) === null
            || NzbSourceInstallation::classifyPathPairBatchNative([], []) === null
            || NzbUploadManifestService::classifyManifestEntryBatchNative([], [], [], [], [], [], []) === null
        ) {
            self::markTestSkipped('The Wave 56.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
