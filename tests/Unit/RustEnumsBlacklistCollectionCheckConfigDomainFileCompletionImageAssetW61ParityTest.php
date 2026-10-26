<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BlacklistConstants;
use App\Enums\CollectionFileCheckStatus;
use App\Enums\ConfigurationDomain;
use App\Enums\FileCompletionStatus;
use App\Enums\ImageAssetProfile;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustEnumsBlacklistCollectionCheckConfigDomainFileCompletionImageAssetW61ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        BlacklistConstants::class => 'planBlacklistRuleBatch',
        CollectionFileCheckStatus::class => 'planFileCheckBatch',
        ConfigurationDomain::class => 'planConfigurationDomainBatch',
        FileCompletionStatus::class => 'planFileCompletionBatch',
        ImageAssetProfile::class => 'planImageAssetBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        BlacklistConstants::class => BlacklistConstants::W61_BLACKLIST_HEADER,
        CollectionFileCheckStatus::class => CollectionFileCheckStatus::W61_FILECHECK_HEADER,
        ConfigurationDomain::class => ConfigurationDomain::W61_DOMAIN_HEADER,
        FileCompletionStatus::class => FileCompletionStatus::W61_COMPLETION_HEADER,
        ImageAssetProfile::class => ImageAssetProfile::W61_IMAGE_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 569b6f3a2 plus this
     * change, seed 616, 50 warmups and 1,000 pairs/workload, four runs (w616-benchmark-*.json in system tmp).
     * The per-metric minimum over the runs of common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x. All five classes clear 1.5x on every metric;
     * BlacklistConstants' uncommon workload (oversized and NUL-holding patterns dominated by marshaling) has the
     * narrowest margin.
     */
    private const QUALIFICATION = [
        BlacklistConstants::class => [3.931, 3.116, 1.651, 1.579],
        CollectionFileCheckStatus::class => [5.945, 4.284, 4.919, 3.875],
        ConfigurationDomain::class => [5.186, 3.530, 3.612, 3.022],
        FileCompletionStatus::class => [5.409, 3.682, 4.600, 3.238],
        ImageAssetProfile::class => [5.556, 4.163, 4.075, 3.369],
    ];

    private const PATTERNS = ['^(?:alt\.binaries\.)?foo', '\.exe$', 'spam\d+', '(?i)password', '^\[\d+/\d+\]', 'yEnc.*\.scr', 'kidz?', '@spam\.example$',
        '<[a-f0-9]{32}@', 'Keygen', '\.(?:lnk|vbs)"', '^Re:'];

    private const OPTYPE_ALIASES = ['OPTYPE_BLACKLIST', 'OPTYPE_WHITELIST', ' Blacklist ', 'WHITE', 'deny', 'allow', 'block', "1\n", '2', 'grey', '', '0', '3',
        'optype_blacklist'];

    private const FIELD_ALIASES = ['BLACKLIST_FIELD_SUBJECT', 'BLACKLIST_FIELD_FROM', 'BLACKLIST_FIELD_MESSAGEID', 'Subject', ' from ', 'poster', 'Message-ID',
        'message_id', 'msgid', 'fromname', 'name', '4', '', 'body'];

    private const STATUS_TOKENS = ['Default', 'CompleteCollection', 'CompleteParts', 'Sized', 'Inserted', 'Delete', 'TempComplete', 'ZeroPart', ' sized ',
        'ZEROPART', "inserted\n", '03', '015', '0016', '6', '99', '-1', '', 'complete_parts', 'x'];

    private const DOMAIN_SETTINGS = [
        'site' => ['title', 'strapline', 'metatitle', 'home_link', 'dereferrer_link', 'tandc'],
        'registration' => ['registerstatus', 'invite_days', 'max_invites'],
        'ingestion' => ['maxmssgs', 'max_headers_iteration', 'partrepair', 'safebackfilldate', 'compressedheaders'],
        'post-processing' => ['maxsize_pp', 'lookupnfo', 'lookuppar2', 'maxnfoprocessed', 'ffmpeg_duration'],
        'metadata' => ['lookupimdb', 'lookuptv', 'tmdbkey', 'lookupmusic', 'lookupbooks'],
        'tmux' => ['run_sequential', 'monitor_delay', 'backfill_threads', 'releases_run', 'niceness'],
    ];

    private const DOMAIN_ODD = ['Site', 'PostProcessing', ' TMUX ', 'Post_Processing', 'configuration:', 'configuration:bogus', 'Configuration:site', 'site.',
        'tmux.Run Sequential', 'metadata.'.'x', 'unknown.key', '.site', 'site..x', "site.a\0b", 'registration', '', ' ', 'ingestion.ÄÖ'];

    private const COMPLETION_TOKENS = ['Incomplete', 'Complete', ' complete ', 'PARTIAL', 'missing', 'done', 'yes', 'no', 'true', 'false', 'full', '00', '2', '-1',
        '', "1\0", 'completed', 'maybe'];

    private const PROFILE_ALIASES = ['metadata_cover', 'Metadata-Cover', ' backdrop ', 'PREVIEW', 'cover', 'poster', 'fanart', 'thumb', 'thumbnail', 'screenshot',
        'banner', 'full', 'raw', 'still', 'samples', 'avatar', '', "Sample\0"];

    private const FORMATS = ['jpg', 'jpg', 'jpeg', 'png', 'image/jpeg', 'image/png', 'webp', 'gif'];

    private const ODD_FORMATS = ['.JPG', ' image/WEBP ', '..png', 'image/', 'tiff', 'svg', 'bmp', 'avif', 'pjpeg', 'jpe', '', "png\0", 'IMAGE/GIF', 'heic'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW61RustFfi', 'withW61RustFfi', 'w61RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w61RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW61RustFfi', 'withW61RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W61_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W61_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W61_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w616_', (string) $cdef->getValue(), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w61', $property->getName(), $class);
            }
            if ($reflection->isEnum()) {
                self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class);
            }
        }
        self::assertTrue((new ReflectionClass(BlacklistConstants::class))->isFinal());
        self::assertSame(['useRustFfi', 'ffi'], array_map(static fn ($p): string => $p->getName(), (new ReflectionClass(BlacklistConstants::class))->getProperties()));
        foreach ([CollectionFileCheckStatus::class, ConfigurationDomain::class, FileCompletionStatus::class, ImageAssetProfile::class] as $enum) {
            self::assertTrue((new ReflectionClass($enum))->isEnum(), $enum);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W61_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW61RustFfi(), $class);
            if (! $expected) {
                self::assertFalse($class::usesW61RustFfi(), $class.' retains PHP by default');
            }
            foreach ([true, false] as $enabled) {
                $seen = $class::withW61RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW61RustFfi(! $enabled, static fn (): bool => $class::usesW61RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W61_RUST_FFI_DEFAULT'), $other::usesW61RustFfi());
                        }
                    }

                    return $class::usesW61RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW61RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW61RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW61RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        self::assertSame(1, BlacklistConstants::OPTYPE_BLACKLIST);
        self::assertSame(2, BlacklistConstants::OPTYPE_WHITELIST);
        self::assertSame(1, BlacklistConstants::BLACKLIST_ENABLED);
        self::assertSame(3, BlacklistConstants::BLACKLIST_FIELD_MESSAGEID);
        self::assertSame(2, BlacklistConstants::resolveConstantPhp('BLACKLIST_FIELD_FROM'));
        self::assertNull(BlacklistConstants::resolveConstantPhp('nope'));
        self::assertFalse(BlacklistConstants::usesRustFfi());
        self::assertSame(8, \count(CollectionFileCheckStatus::cases()));
        self::assertSame(CollectionFileCheckStatus::ZeroPart, CollectionFileCheckStatus::from(16));
        self::assertSame('Size calculated', CollectionFileCheckStatus::Sized->descriptionPhp());
        self::assertTrue(CollectionFileCheckStatus::Delete->isProcessingCompletePhp());
        self::assertSame([CollectionFileCheckStatus::Sized], CollectionFileCheckStatus::readyForReleasePhp());
        self::assertCount(5, CollectionFileCheckStatus::inProgressPhp());
        self::assertSame(6, \count(ConfigurationDomain::cases()));
        self::assertSame('Post Processing', ConfigurationDomain::PostProcessing->labelPhp());
        self::assertSame('tmux_configurations', ConfigurationDomain::Tmux->tablePhp());
        self::assertSame(2, ConfigurationDomain::TEXT_CACHE_KEY);
        self::assertSame(2, \count(FileCompletionStatus::cases()));
        self::assertSame('Complete', FileCompletionStatus::Complete->descriptionPhp());
        self::assertTrue(FileCompletionStatus::Complete->isCompletePhp());
        self::assertNull(FileCompletionStatus::resolvePhp(7));
        self::assertSame(5, \count(ImageAssetProfile::cases()));
        self::assertSame(1920, ImageAssetProfile::Backdrop->maxWidthPhp());
        self::assertNull(ImageAssetProfile::Original->maxHeightPhp());
        self::assertSame(ImageAssetProfile::Preview, ImageAssetProfile::tryFromNamePhp('Preview'));
        self::assertNull(ImageAssetProfile::tryFromNamePhp('preview'));
    }

    public function test_blacklist_rule_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(BlacklistConstants::class, [[], [], [], []]);
        self::assertSame([0, -1], [$empty[0], $empty[16]]);
        $out = $this->assertParity(BlacklistConstants::class, [
            ['OPTYPE_BLACKLIST', ' Allow ', 'nope'], ['BLACKLIST_FIELD_SUBJECT', 'message-id', 'poster'], ['^foo.*bar$', 'x', ' .* '], [1, 0, 1],
        ]);
        self::assertSame([1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 2], \array_slice($out, 1, 11));
        self::assertSame([2, 1, 0, 1, 2, 15, 10], \array_slice($out, 12, 7));
        self::assertSame(1 | 1 << 2 | 1 << 15 | 1 << 16 | 10 << 17, $out[BlacklistConstants::W61_BLACKLIST_HEADER]);
        self::assertSame(65, $out[BlacklistConstants::W61_BLACKLIST_HEADER + 2] >> 8 & 0x7F);
        $this->assertParity(BlacklistConstants::class, [
            [...self::OPTYPE_ALIASES, 1, 2, 0],
            [...self::FIELD_ALIASES, 1, 2, 3, 4],
            ['', ' ', "\0", "a\0b", str_repeat('x', 2_000), str_repeat('y', 2_001), '.*', ' .+ ', '^.*$', '.*?', '^', '$', '^$', "\t\n", "\xff\xfe", 'ok'],
            [PHP_INT_MIN, -1, 0, 1, 2, PHP_INT_MAX, 1, 0],
        ]);
        $this->assertParity(BlacklistConstants::class, [['a' => 1, 'b' => 2], [9 => 1], ['z' => 'x', 'y' => 'y'], [3 => 1, 1 => 0]]);
    }

    public function test_collection_filecheck_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(CollectionFileCheckStatus::class, [[], [], [], [], [], 0]);
        self::assertSame([0, -1, -1], [$empty[0], $empty[22], $empty[23]]);
        $out = $this->assertParity(CollectionFileCheckStatus::class, [
            ['3', 'Sized', ' inserted ', '7', '016'], [4, 3, 3, 4, 9], [10, 9, 0, 0, 0], [10, 10, 0, 0, 0], [5 << 20, 5 << 20, 0, 0, 0], 1 << 20,
        ]);
        self::assertSame([2, 1, 1, 1], \array_slice($out, 10, 4));
        self::assertSame([1, 1, 1, 1, 1], \array_slice($out, 14, 5));
        self::assertSame([1, 1, 1, 0, 2, 5 << 20, 19, 20], \array_slice($out, 19, 8));
        self::assertSame(3 | 1 << 6 | 1 << 13 | 1_000 << 16 | 10 << 26 | 5 << 46, $out[CollectionFileCheckStatus::W61_FILECHECK_HEADER]);
        $values = array_map(static fn (CollectionFileCheckStatus $s): int => $s->value, CollectionFileCheckStatus::cases());
        $statuses = [...$values, ...self::STATUS_TOKENS];
        $targets = [];
        foreach ($values as $from) {
            foreach ([...$values, -1, 6, 17, PHP_INT_MAX, PHP_INT_MIN] as $to) {
                $targets[] = [$from, $to];
            }
        }
        $this->assertParity(CollectionFileCheckStatus::class, [array_column($targets, 0), array_column($targets, 1), [], [], [], 0]);
        foreach ([PHP_INT_MIN, 0, 1 << 20, 1 << 41, PHP_INT_MAX] as $minBytes) {
            $this->assertParity(CollectionFileCheckStatus::class, [
                $statuses,
                [-1, 4, 5, 3, 1, 0, 16, 15, 2, PHP_INT_MAX, 7, -5],
                [PHP_INT_MIN, -1, 0, 1, 9, 10, 1 << 20, (1 << 20) - 1, PHP_INT_MAX, 5, 11],
                [0, 10, 10, 1, 10, 9, 1 << 21, PHP_INT_MAX, PHP_INT_MIN, 5, 10],
                [PHP_INT_MIN, -1, 0, 1 << 20, 1 << 40, PHP_INT_MAX, 1 << 37, 1 << 38, 999_999, 5],
                $minBytes,
            ]);
        }
        $this->assertParity(CollectionFileCheckStatus::class, [['k' => 3, 'j' => 'Sized'], ['x' => 4], [5 => 1], [], ['q' => 9], 1]);
    }

    public function test_configuration_domain_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ConfigurationDomain::class, [[]]);
        self::assertSame([0, -1], [$empty[0], $empty[20]]);
        $out = $this->assertParity(ConfigurationDomain::class, [
            ['site', 'PostProcessing', 'configuration:tmux', ' POST_PROCESSING ', 'metadata.Max_Size', 'ingestion.', 'bogus'],
        ]);
        self::assertSame([1, 1, 1, 1, 2, 1], \array_slice($out, 8, 6));
        self::assertSame([3, 2, 1, 1], \array_slice($out, 14, 4));
        self::assertSame([1, 2, 5, 8], \array_slice($out, 18, 4));
        self::assertSame(4 | 4 << 3 | 1 << 6 | 1 << 12 | 8 << 13 | 17 << 29, $out[ConfigurationDomain::W61_DOMAIN_HEADER + 4]);
        $keys = [...array_map(static fn (ConfigurationDomain $d): string => $d->value, ConfigurationDomain::cases()),
            ...array_map(static fn (ConfigurationDomain $d): string => $d->name, ConfigurationDomain::cases()),
            ...array_map(static fn (ConfigurationDomain $d): string => 'configuration:'.$d->value, ConfigurationDomain::cases()),
            ...self::DOMAIN_ODD, 'site.'.str_repeat('a', 64), 'site.'.str_repeat('a', 65), 'tmux.'.str_repeat('b', 70_000), "\0site", 'site.a-b.c_9'];
        $this->assertParity(ConfigurationDomain::class, [$keys]);
        $this->assertParity(ConfigurationDomain::class, [['x' => 'site.title', 'y' => 'tmux']]);
    }

    public function test_file_completion_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(FileCompletionStatus::class, [[], [], [], 950]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[19], $empty[22]]);
        $out = $this->assertParity(FileCompletionStatus::class, [['0', 'Complete', ' yes ', '?'], [10, 5, 96, 3], [10, 10, 100, 2], 950]);
        self::assertSame([1, 2, 1, 1, 1, 1, 1], \array_slice($out, 1, 7));
        self::assertSame([0, 0, 1, 1, 1, 1], \array_slice($out, 8, 6));
        self::assertSame([0, 1, 2, 1, 2, 1, 114, 122, 926], \array_slice($out, 14, 9));
        self::assertSame(4 << 4 | 1 << 7 | 1 << 9 | 1_000 << 10 | 10 << 20 | 1 << 44, $out[FileCompletionStatus::W61_COMPLETION_HEADER]);
        foreach ([PHP_INT_MIN, -1, 0, 500, 950, 1_000, 1_001, PHP_INT_MAX] as $threshold) {
            $this->assertParity(FileCompletionStatus::class, [
                [0, 1, 2, -1, ...self::COMPLETION_TOKENS],
                [PHP_INT_MIN, -1, 0, 1, 99, 100, 101, 1 << 24, (1 << 24) - 1, PHP_INT_MAX, 50, 3],
                [0, 0, 100, 100, 100, 100, 100, 1 << 25, 1 << 24, PHP_INT_MIN, 51, PHP_INT_MAX],
                $threshold,
            ]);
        }
        $this->assertParity(FileCompletionStatus::class, [['a' => 1, 'b' => 'Incomplete'], [7 => 3], ['x' => 3, 'y' => 9, 'z' => 1]]);
    }

    public function test_image_asset_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ImageAssetProfile::class, [[], [], [], []]);
        self::assertSame([0, -1], [$empty[0], $empty[22]]);
        $out = $this->assertParity(ImageAssetProfile::class, [['Backdrop', 'cover', 'x'], ['image/JPEG', '.png', 'tiff'], [3_840, 500, 10], [2_160, 1_000, 10]]);
        self::assertSame([2, 1, 2, 1, 2], \array_slice($out, 18, 5));
        self::assertSame(2 | 1 << 5 | 1 << 8 | 8 << 11 | 1 << 18 | 80 << 19 | 1_820 << 26 | 1_024 << 42 | 8 << 58, $out[ImageAssetProfile::W61_IMAGE_HEADER]);
        self::assertSame(1 | 2 << 3 | 2 << 5 | 2 << 8 | 8 << 11 | 1 << 18 | 9 << 19 | 125 << 26 | 250 << 42 | 5 << 58, $out[ImageAssetProfile::W61_IMAGE_HEADER + 1]);
        $profiles = [...array_map(static fn (ImageAssetProfile $p): string => $p->name, ImageAssetProfile::cases()), ...self::PROFILE_ALIASES];
        $this->assertParity(ImageAssetProfile::class, [
            $profiles,
            [...self::FORMATS, ...self::ODD_FORMATS],
            [PHP_INT_MIN, -1, 0, 1, 31, 32, 250, 251, 1_920, 4_000, 65_535, 65_536, PHP_INT_MAX, 7_000, 800, 650],
            [0, 1, PHP_INT_MAX, 32, 31, 250, 249, 1_024, 2_160, 65_535, 7_000, 1, 600, 3_000, 801],
        ]);
        $this->assertParity(ImageAssetProfile::class, [['k' => 'Sample', 'j' => 'Preview'], [5 => 'png'], ['x' => 1_300], []]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 616];
            yield $short.' uncommon' => [$class, 384, true, 617];
            yield $short.' large common' => [$class, 2_048, false, 618];
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
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_610 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W616_BENCH=1): seed 616, 8 fixture variants, 50 warmups and
     * NNTMUX_W616_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 malformed, aliased, unknown,
     * NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99. The summary
     * and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W616_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W616_BENCH=1 to run the paired Wave 61.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W616_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(616));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 616 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW61RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 616, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w616-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW61.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW61RustFfi()) {
                self::assertSame($eligible, $class::usesW61RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
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
            BlacklistConstants::class => $this->blacklistBatch($random, $count, $uncommon),
            CollectionFileCheckStatus::class => $this->fileCheckBatch($random, $count, $uncommon),
            ConfigurationDomain::class => $this->domainBatch($random, $count, $uncommon),
            FileCompletionStatus::class => $this->completionBatch($random, $count, $uncommon),
            default => $this->imageBatch($random, $count, $uncommon),
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
     * Stored blacklist rows (integer operation types and columns, regex patterns, enabled flags); uncommon ones
     * are constant names, aliases, unknown tokens, blank, catch-all, NUL-holding, random or oversized patterns
     * and extreme statuses.
     *
     * @return list<mixed>
     */
    private function blacklistBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $types = $fields = $patterns = $statuses = [];
        for ($i = 0; $i < $count; $i++) {
            $type = $random->getInt(0, 4) === 0 ? 2 : 1;
            $field = $random->getInt(0, 5) === 0 ? 2 : ($random->getInt(0, 9) === 0 ? 3 : 1);
            $pattern = $this->pick($random, self::PATTERNS).($random->getInt(0, 2) === 0 ? '' : (string) $random->getInt(1, 999));
            $status = $random->getInt(0, 6) === 0 ? 0 : 1;
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0, 1 => $type = $this->pick($random, self::OPTYPE_ALIASES),
                    2, 3 => $field = $this->pick($random, self::FIELD_ALIASES),
                    4 => $pattern = $this->pick($random, ['', ' ', '.*', '^.*$', ' .+ ', '.*?', '$']),
                    5 => $pattern = $pattern."\0".$pattern,
                    6 => $pattern = substr($random->getBytes(48), 0, $random->getInt(0, 48)),
                    7 => $pattern = str_repeat($pattern, $random->getInt(150, 400)),
                    8 => $status = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $status = $random->getInt(-2, 3),
                    default => null,
                };
            }
            $types[] = $type;
            $fields[] = $field;
            $patterns[] = $pattern;
            $statuses[] = $status;
        }

        return [$types, $fields, $patterns, $statuses];
    }

    /**
     * Collections with stored filecheck values, requested next values and file and byte counts; uncommon ones
     * are case names, padded, unknown, zero-padded or random statuses with extreme targets and counts.
     *
     * @return list<mixed>
     */
    private function fileCheckBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $values = array_map(static fn (CollectionFileCheckStatus $s): int => $s->value, CollectionFileCheckStatus::cases());
        $names = array_map(static fn (CollectionFileCheckStatus $s): string => $s->name, CollectionFileCheckStatus::cases());
        $next = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 5, 15 => 3, 16 => 2];
        $statuses = $targets = $received = $expected = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            $status = $random->getInt(0, 2) === 0 ? 3 : $this->pick($random, $values);
            $target = match ($random->getInt(0, 3)) {
                0 => -1,
                1 => $this->pick($random, $values),
                default => $next[$status],
            };
            $want = $random->getInt(1, 200);
            $have = $random->getInt(0, 3) === 0 ? $random->getInt(0, $want) : $want;
            $size = $random->getInt(0, 9) === 0 ? $random->getInt(0, 900_000) : $random->getInt(10_000_000, 60_000_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $status = $this->pick($random, $names),
                    1, 2 => $status = $this->pick($random, self::STATUS_TOKENS),
                    3 => $status = substr($random->getBytes(12), 0, $random->getInt(0, 12)),
                    4 => $target = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    5 => $have = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $want = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $size = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $status = ' '.$status."\n",
                    default => null,
                };
            }
            $statuses[] = $status;
            $targets[] = $target;
            $received[] = $have;
            $expected[] = $want;
            $bytes[] = $size;
        }

        return [$statuses, $targets, $received, $expected, $bytes, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : 1 << 20];
    }

    /**
     * Setting keys (`domain.setting`), cache keys and domain values; uncommon ones are case names, padded or
     * upper-case, unknown domains, NUL-holding, random, invalid or oversized settings.
     *
     * @return list<mixed>
     */
    private function domainBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $domains = array_keys(self::DOMAIN_SETTINGS);
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $domain = $this->pick($random, $domains);
            $key = match ($random->getInt(0, 7)) {
                0 => $domain,
                1 => 'configuration:'.$domain,
                default => $domain.'.'.$this->pick($random, self::DOMAIN_SETTINGS[$domain]),
            };
            if ($uncommon) {
                match ($random->getInt(0, 9)) {
                    0, 1 => $key = $this->pick($random, self::DOMAIN_ODD),
                    2 => $key = ' '.strtoupper($key).' ',
                    3 => $key = $key."\0",
                    4 => $key = 'unknown'.$i.'.'.$key,
                    5 => $key = substr($random->getBytes(24), 0, $random->getInt(0, 24)),
                    6 => $key = $domain.'.'.str_repeat('x', $random->getInt(60, 300)),
                    7 => $key = str_replace('-', '_', $key).'.Bad Key!',
                    default => null,
                };
            }
            $keys[] = $key;
        }

        return [$keys];
    }

    /**
     * Binaries with stored completion flags and part counts; uncommon ones are case names, aliases, unknown or
     * NUL-holding tokens with extreme counts and thresholds.
     *
     * @return list<mixed>
     */
    private function completionBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = $received = $total = [];
        for ($i = 0; $i < $count; $i++) {
            $parts = $random->getInt(1, 400);
            $got = $random->getInt(0, 2) === 0 ? $random->getInt(0, $parts) : $parts;
            $status = $random->getInt(0, 9) === 0 ? 1 - ($got >= $parts ? 1 : 0) : ($got >= $parts ? 1 : 0);
            if ($uncommon) {
                match ($random->getInt(0, 9)) {
                    0, 1 => $status = $this->pick($random, self::COMPLETION_TOKENS),
                    2 => $status = $this->pick($random, ['Incomplete', 'Complete']),
                    3 => $got = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $parts = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    5 => $parts = 0,
                    6 => $got = $parts + $random->getInt(1, 10),
                    7 => $status = $random->getInt(-3, 5),
                    default => null,
                };
            }
            $statuses[] = $status;
            $received[] = $got;
            $total[] = $parts;
        }

        return [$statuses, $received, $total, $uncommon ? $random->getInt(-100, 1_200) : 950];
    }

    /**
     * Downloaded covers, backdrops, previews and samples with formats and dimensions; uncommon ones are
     * aliases, unknown profiles, odd formats, zero, tiny, huge or negative dimensions.
     *
     * @return list<mixed>
     */
    private function imageBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = array_map(static fn (ImageAssetProfile $p): string => $p->name, ImageAssetProfile::cases());
        $profiles = $formats = $widths = $heights = [];
        for ($i = 0; $i < $count; $i++) {
            $profile = $this->pick($random, $names);
            $format = $this->pick($random, self::FORMATS);
            $width = $random->getInt(200, 4_000);
            $height = intdiv($width * $random->getInt(40, 160), 100);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0, 1 => $profile = $this->pick($random, self::PROFILE_ALIASES),
                    2, 3 => $format = $this->pick($random, self::ODD_FORMATS),
                    4 => $width = 0,
                    5 => $height = $random->getInt(1, 31),
                    6 => [$width, $height] = [$random->getInt(7_000, 70_000), $random->getInt(7_000, 70_000)],
                    7 => $width = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $height = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $profile = substr($random->getBytes(16), 0, $random->getInt(0, 16)),
                    default => null,
                };
            }
            $profiles[] = $profile;
            $formats[] = $format;
            $widths[] = $width;
            $heights[] = $height;
        }

        return [$profiles, $formats, $widths, $heights];
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
        $php = $class::withW61RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW61RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (BlacklistConstants::planBlacklistRuleBatchNative([], [], [], []) === null
            || CollectionFileCheckStatus::planFileCheckBatchNative([], [], [], [], []) === null
            || ConfigurationDomain::planConfigurationDomainBatchNative([]) === null
            || FileCompletionStatus::planFileCompletionBatchNative([], [], []) === null
            || ImageAssetProfile::planImageAssetBatchNative([], [], [], []) === null
        ) {
            self::markTestSkipped('The Wave 61.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
