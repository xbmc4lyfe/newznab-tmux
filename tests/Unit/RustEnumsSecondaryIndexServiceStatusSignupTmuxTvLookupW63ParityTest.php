<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SecondarySearchIndex;
use App\Enums\ServiceStatusEnum;
use App\Enums\SignupError;
use App\Enums\TmuxMode;
use App\Enums\TvLookupStatus;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustEnumsSecondaryIndexServiceStatusSignupTmuxTvLookupW63ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        SecondarySearchIndex::class => 'w63SecondaryIndexPlanBatch',
        ServiceStatusEnum::class => 'w63ServiceStatusPlanBatch',
        SignupError::class => 'w63SignupErrorPlanBatch',
        TmuxMode::class => 'w63TmuxModePlanBatch',
        TvLookupStatus::class => 'w63TvLookupStatusPlanBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        SecondarySearchIndex::class => SecondarySearchIndex::W63_INDEX_HEADER,
        ServiceStatusEnum::class => ServiceStatusEnum::W63_SERVICE_HEADER,
        SignupError::class => SignupError::W63_SIGNUP_HEADER,
        TmuxMode::class => TmuxMode::W63_TMUX_HEADER,
        TvLookupStatus::class => TvLookupStatus::W63_TV_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 36a48f7d5 plus this
     * change, seed 636, 50 warmups and 1,000 pairs/workload, four runs (w636-benchmark-*.json in system tmp).
     * The per-metric minimum over the runs of common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x. All five enums clear 1.5x on every metric; the
     * uncommon p99 (NUL-holding, Unicode and oversized tokens dominated by marshaling) has the narrowest margin.
     */
    private const QUALIFICATION = [
        SecondarySearchIndex::class => [4.465, 3.956, 2.880, 1.908],
        ServiceStatusEnum::class => [5.751, 3.406, 3.960, 3.043],
        SignupError::class => [5.530, 4.319, 3.075, 2.343],
        TmuxMode::class => [5.093, 3.265, 3.884, 2.968],
        TvLookupStatus::class => [4.831, 4.261, 3.936, 2.806],
    ];

    private const INDEX_ODD = ['Music', 'Books', 'Games', 'Console', 'Steam', 'Anime', ' MUSIC_RT ', 'audio', 'MP3', 'book', 'Ebooks', 'game', 'pc_games',
        'consoles', 'steam_apps', 'AniDB', 'anime_titles', 'movies', 'tv', '', ' ', 'Müsic', '音楽', "anime\0", 'music ', '0'];

    private const FIELD_ODD = ['title', 'artist', 'author', 'year', 'publishdate', 'genres_id', 'cover', 'releasedate_ts', 'platform', 'name', 'appid',
        'anidbid', 'anilist_id', 'mal_id', 'lang', 'title_type', 'media_type', 'status', 'Title', ' title', 'id', '', 'tïtle', "title\0", '0'];

    private const SERVICE_ODD = ['Operational', 'Degraded', 'PartialOutage', 'Partial outage', 'MajorOutage', 'Major outage', 'Maintenance', ' OK ', 'up',
        'Healthy', 'green', 'online', 'slow', 'YELLOW', 'degraded-performance', 'partial', 'ORANGE', 'down', 'outage', 'red', 'offline', 'maint',
        'Scheduled', 'under maintenance', 'unknown', '', ' ', 'dégradé', "down\0", '0', 'major outage '];

    private const SIGNUP_ODD = ['-1', '-2', '-3', '-4', '-5', '-6', '1', '0', '-7', '+1', '01', 'BAD_USERNAME', 'BAD_INVITE_CODE', 'SUCCESS', 'success',
        'Invalid username provided.', 'Registration successful.', 'registration successful.', ' Username ', 'weak_password', 'EMAIL', 'username_taken',
        'Duplicate_Email', 'invite_expired', 'ok', 'Created', 'nope', '', ' ', 'ünïcode', "-1\0", 'SUCCESS '];

    private const TMUX_ODD = ['0', '1', '2', '3', '-1', '00', ' 1', 'Full', 'Basic', 'Stripped', 'full', 'COMPLETE', 'all', 'everything', 'Standard',
        'default', 'normal', 'minimal', 'Sequential', 'lite', 'simple', 'turbo', '', ' ', 'fûll', "2\0", 'basic '];

    private const TV_ODD = ['0', '-10', '-11', '-12', '-100', '-1', '-2', '-3', '-4', '-5', '-6', '-7', '-99', '1', '42', '9223372036854775807',
        '-9223372036854775808', '9223372036854775808', '-0', '00', '+5', ' 5', '5 ', '1e3', '0x1A', 'Pending', 'ShowNotFound', 'EpisodeMissing',
        'SeasonPack', 'ParseFailed', 'pending', ' QUEUED ', 'waiting', 'not_found', 'NotFound', 'episode-missing', 'no_episode', 'Pack', 'season_pack',
        'unparseable', 'Parse-Failed', 'matched', '', ' ', 'épisode', "-11\0", "0\0"];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isEnum(), $class);
            $public = ['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', 'usesW63RustFfi', 'withW63RustFfi', $method, $method.'Php', $method.'Native'];
            foreach ($public as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w63RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(['op', 'val'], array_map(static fn ($p): string => $p->getName(), $state->getParameters()), $class);
            self::assertSame('mixed', (string) $state->getReturnType(), $class);
            self::assertTrue($reflection->getReflectionConstant('W63_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W63_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W63_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w636_', (string) $cdef->getValue(), $class);
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertContains($property->getName(), ['name', 'value'], $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $class::resetW63RustFfi();
            $expected = (bool) (new ReflectionClass($class))->getConstant('W63_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW63RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW63RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW63RustFfi(! $enabled, static fn (): bool => $class::usesW63RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W63_RUST_FFI_DEFAULT'), $other::usesW63RustFfi());
                        }
                    }

                    return $class::usesW63RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW63RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW63RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW63RustFfi(), $class);
            $class::enableW63RustFfi();
            self::assertTrue($class::usesW63RustFfi(), $class);
            $class::disableW63RustFfi();
            self::assertFalse($class::usesW63RustFfi(), $class);
            $class::resetW63RustFfi();
            self::assertSame($expected, $class::usesW63RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        self::assertSame(['artist', 'title'], SecondarySearchIndex::Music->fulltextFields());
        self::assertSame(['type' => 'bigint'], SecondarySearchIndex::Console->manticoreColumns()['releasedate_ts']);
        self::assertSame((int) sprintf('%u', crc32('7|main|en|Title')), SecondarySearchIndex::animeTitleDocumentIdPhp(7, 'main', 'en', 'Title'));
        self::assertSame(['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'], ServiceStatusEnum::valuesPhp());
        self::assertSame('Partial outage', ServiceStatusEnum::PartialOutage->labelPhp());
        self::assertSame(2, ServiceStatusEnum::Maintenance->severityPhp());
        self::assertSame('Invalid or expired invite code.', SignupError::BAD_INVITE_CODE->messagePhp());
        self::assertSame(SignupError::EMAIL_IN_USE, SignupError::tryFromMessagePhp('Email address is already in use.'));
        self::assertTrue(SignupError::BAD_EMAIL->isErrorPhp());
        self::assertSame(['main', 'fixnames', 'amazon', 'scraper'], array_keys(TmuxMode::Stripped->tasksPhp()));
        self::assertSame(TmuxMode::Basic, TmuxMode::from(1));
        self::assertSame('Season Pack', TvLookupStatus::SeasonPack->labelPhp());
        self::assertSame(TvLookupStatus::ShowNotFound, TvLookupStatus::normalizeLegacyStatusPhp(-6));
        self::assertSame([0, -11, -1, -2, -3], TvLookupStatus::reprocessableValuesPhp());
    }

    public function test_plan_tables_mirror_enum_methods(): void
    {
        $index = new ReflectionClass(SecondarySearchIndex::class);
        $kinds = $index->getConstant('W63_FIELD_KINDS');
        foreach (SecondarySearchIndex::cases() as $case) {
            foreach ($case->manticoreColumns() as $definition) {
                self::assertArrayHasKey($definition['type'], $kinds);
            }
            foreach ($case->fulltextFields() as $field) {
                self::assertArrayHasKey($field, $case->manticoreColumns());
            }
        }
        $severity = (new ReflectionClass(ServiceStatusEnum::class))->getConstant('W63_SEVERITY');
        foreach (ServiceStatusEnum::cases() as $at => $case) {
            self::assertSame($case->severityPhp(), $severity[$at]);
        }
        $tokens = (new ReflectionClass(SignupError::class))->getConstant('W63_SIGNUP_TOKENS');
        foreach (SignupError::cases() as $at => $case) {
            self::assertSame($at, $tokens[$case->value]);
            self::assertSame($at | 1 << 3, $tokens[$case->name]);
            self::assertSame($at | 2 << 3, $tokens[$case->messagePhp()]);
        }
        $panes = (new ReflectionClass(TmuxMode::class))->getConstant('W63_PANES');
        foreach (TmuxMode::cases() as $case) {
            self::assertSame(\count($case->tasksPhp()), $panes[$case->value]);
        }
        $flags = (new ReflectionClass(TvLookupStatus::class))->getConstant('W63_TV_FLAGS');
        foreach ([0, -10, -11, -12, -100, 5, -1, -4, -50] as $at => $value) {
            self::assertSame(TvLookupStatus::classifyValuePhp($value), $flags[$at], (string) $value);
        }
        foreach (TvLookupStatus::cases() as $at => $case) {
            self::assertSame($at, (new ReflectionClass(TvLookupStatus::class))->getConstant('W63_TV_NAMES')[$case->name]);
        }
    }

    public function test_secondary_index_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(SecondarySearchIndex::class, [[], [], []]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[27], $empty[28]]);
        $out = $this->assertParity(SecondarySearchIndex::class, [['music', 'Console', ' ANIME_RT ', 'nope', 'steam'], ['artist', 'platform', 'anidbid', '', 'bogus'],
            [7, 0, 1 << 33, 5, -1]]);
        self::assertSame([1, 0, 0, 1, 1, 1, 1], \array_slice($out, 1, 7));
        self::assertSame([2, 1, 1, 0, 1], \array_slice($out, 18, 5));
        self::assertSame([2, 2, 1, 0b111001, 3, 20], \array_slice($out, 23, 6));
        self::assertSame(1 << 5 | 1 << 14 | 5 << 16 | 6 << 24 | 7 << 32, $out[SecondarySearchIndex::W63_INDEX_HEADER]);
        self::assertSame(3 | 1 << 3 | 2 << 5 | 1 << 11 | 7 << 16 | 8 << 24, $out[SecondarySearchIndex::W63_INDEX_HEADER + 1]);
        $docs = [PHP_INT_MIN, -1, 0, 1, 2_147_483_647, 2_147_483_648, PHP_INT_MAX];
        $rows = [];
        foreach (['music', 'books', 'games', 'console', 'steam', 'anime', ...self::INDEX_ODD] as $at => $index) {
            foreach (self::FIELD_ODD as $i => $field) {
                $rows[] = [$index, $field, $docs[($i + $at) % \count($docs)]];
            }
        }
        $this->assertParity(SecondarySearchIndex::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2)]);
        $this->assertParity(SecondarySearchIndex::class, [['music', str_repeat('m', 300), "a\0b\0", 0], ['title', str_repeat('t', 300)], [9]]);
        $this->assertParity(SecondarySearchIndex::class, [['k' => 'books', 'j' => 'games'], [5 => 'author', 2 => 'title', 9 => 'x'], ['x' => 3]]);
    }

    public function test_service_status_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ServiceStatusEnum::class, [[], [], 0]);
        self::assertSame([0, -1, -1, -1], [$empty[0], $empty[11], $empty[12], $empty[16]]);
        $out = $this->assertParity(ServiceStatusEnum::class, [['operational', 'Major outage', ' down ', 'degraded', '??'], [10, 100, 200, 1_801, 5], 1_000]);
        self::assertSame([1, 1, 0, 2, 0, 1], \array_slice($out, 1, 6));
        self::assertSame([4, 1, 3, 1, 300, 925_000, 1], \array_slice($out, 11, 7));
        self::assertSame(3 | 1 << 3 | 4 << 5 | 1 << 8 | 900_000 << 10 | 100 << 30 | 12 << 54, $out[ServiceStatusEnum::W63_SERVICE_HEADER + 1]);
        $durations = [PHP_INT_MIN, -1, 0, 1, 1_800, 1_801, 86_400, 86_401, 16_777_215, 16_777_216, PHP_INT_MAX];
        $rows = [];
        foreach ([...ServiceStatusEnum::valuesPhp(), ...self::SERVICE_ODD] as $at => $status) {
            foreach ($durations as $i => $duration) {
                $rows[] = [$status, $durations[($i + $at) % \count($durations)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 1_800, 86_400, 16_777_215, PHP_INT_MAX] as $window) {
            $this->assertParity(ServiceStatusEnum::class, [array_column($rows, 0), array_column($rows, 1), $window]);
        }
        $this->assertParity(ServiceStatusEnum::class, [['k' => 'degraded', 'j' => 'down', 3 => str_repeat('d', 300)], [7 => 4_000], 3_600]);
    }

    public function test_signup_error_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(SignupError::class, [[], [], 0]);
        self::assertSame([0, -1, -1, 0], [$empty[0], $empty[23], $empty[24], $empty[25]]);
        $out = $this->assertParity(SignupError::class, [[1, 'BAD_EMAIL', 'Invalid or expired invite code.', ' Email_Taken ', 'nope'], [0, 4, 0, 1, 0], 0]);
        self::assertSame([1, 2, 1, 1], \array_slice($out, 14, 4));
        self::assertSame([1, 1, 2, 1, 3, 1, 4, 500], \array_slice($out, 18, 8));
        self::assertSame(2 | 1 << 3 | 422 << 6 | 3 << 16 | 2 << 19 | 2 << 21 | 4 << 23 | 9 << 43, $out[SignupError::W63_SIGNUP_HEADER + 1]);
        $codes = [-1, -2, -3, -4, -5, -6, 1, 0, -7, ...self::SIGNUP_ODD];
        $attempts = [PHP_INT_MIN, -1, 0, 1, 3, 4, 5, 6, 1_048_575, 1_048_576, PHP_INT_MAX];
        $rows = [];
        foreach ($codes as $at => $code) {
            foreach ($attempts as $i => $tries) {
                $rows[] = [$code, $attempts[($i + $at) % \count($attempts)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 5, 1_048_575, PHP_INT_MAX] as $limit) {
            $this->assertParity(SignupError::class, [array_column($rows, 0), array_column($rows, 1), $limit]);
        }
        $this->assertParity(SignupError::class, [['k' => 'BAD_PASSWORD', 'j' => -6, 4 => str_repeat('s', 300)], [9 => 2], 3]);
    }

    public function test_tmux_mode_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(TmuxMode::class, [[], [], [], 0]);
        self::assertSame([0, -1, -1, -1], [$empty[0], $empty[17], $empty[18], $empty[19]]);
        $args = [[0, 'Basic', 'lite', 'x', '0'], [0, 99, 0, 3, 20], [1, 0, 0, 0, 16]];
        $out = $this->assertParity(TmuxMode::class, [...$args, 7]);
        self::assertSame([1, 1, 1, 1, 1], \array_slice($out, 9, 5));
        self::assertSame([32, 29, 7, 0, 1, 3], \array_slice($out, 14, 6));
        self::assertSame(1 | 1 << 2 | 8 << 4 | 8 << 8 | 1 << 13 | 1 << 15 | 4 << 18 | 5 << 39, $out[TmuxMode::W63_TMUX_HEADER + 1]);
        $unlimited = $this->assertParity(TmuxMode::class, [...$args, 0]);
        self::assertSame([12, -1], \array_slice($unlimited, 16, 2));
        $values = [PHP_INT_MIN, -1, 0, 1, 2, 4, 8, 9, 16, 17, 65_535, 65_536, PHP_INT_MAX];
        $rows = [];
        foreach ([0, 1, 2, ...self::TMUX_ODD] as $at => $mode) {
            foreach ($values as $i => $request) {
                $rows[] = [$mode, $request, $values[($i * 5 + $at) % \count($values)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 3, 50, 1 << 40, (1 << 40) + 1, PHP_INT_MAX] as $capacity) {
            $this->assertParity(TmuxMode::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2), $capacity]);
        }
        $this->assertParity(TmuxMode::class, [['k' => 'Full', 'j' => 2], [3 => 40], ['x' => 1, 'y' => 2, 'z' => 3], 5]);
    }

    public function test_tv_lookup_status_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(TvLookupStatus::class, [[], [], [], 0]);
        self::assertSame([0, -1, -1, 0], [$empty[0], $empty[21], $empty[22], $empty[23]]);
        $statuses = [0, '-11', -2, '-6', 42, 'ParseFailed', ' queued ', '-0', '-9223372036854775808'];
        $out = $this->assertParity(TvLookupStatus::class, [$statuses, [0, 2, 9, 0, 0, 0, 3], [0, 0, 0, 100], 9]);
        self::assertSame([1, 1, 2, 1, 2, 2], \array_slice($out, 14, 6));
        self::assertSame([2, 3_600, 7, 14], \array_slice($out, 20, 4));
        self::assertSame(2 | 11 << 6 | 2 << 13 | 2 << 16 | 43_200 << 21 | 2 << 41 | 3 << 57, $out[TvLookupStatus::W63_TV_HEADER + 1]);
        $attempts = [PHP_INT_MIN, -1, 0, 1, 2, 7, 8, 9, 10, 11, 65_535, 65_536, PHP_INT_MAX];
        $elapsed = [PHP_INT_MIN, -1, 0, 899, 900, 1_800, 21_600, 43_199, 604_800, 1 << 40, PHP_INT_MAX];
        $rows = [];
        foreach ([0, -10, -11, -12, -100, -1, -4, -6, 7, PHP_INT_MAX, PHP_INT_MIN, ...self::TV_ODD] as $at => $status) {
            foreach ($attempts as $i => $tries) {
                $rows[] = [$status, $tries, $elapsed[($i * 3 + $at) % \count($elapsed)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 3, 10, 65_535, PHP_INT_MAX] as $limit) {
            $this->assertParity(TvLookupStatus::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2), $limit]);
        }
        $this->assertParity(TvLookupStatus::class, [['k' => '-11', 'j' => 'Pending', 7 => str_repeat('9', 100)], [5 => 1], ['x' => 10, 'y' => 0], 4]);
    }

    public function test_native_rejects_malformed_buffers(): void
    {
        $this->requireBindings();
        $ffi = RustPipelineLibrary::resolveDomain('additional_w636_test', <<<'C'
            int32_t nntmux_w636_secondary_index_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
            int32_t nntmux_w636_tv_lookup_status_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t max_attempts, int64_t *out, size_t out_cap);
            C);
        self::assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[64]');
        $out[0] = 77;
        $doc = pack('q', 9);
        $call = static fn (string $symbol, mixed ...$args): mixed => $ffi->$symbol(...$args);
        $index = static fn (mixed ...$args): mixed => $call('nntmux_w636_secondary_index_batch', ...$args);
        $tv = static fn (mixed ...$args): mixed => $call('nntmux_w636_tv_lookup_status_batch', ...$args);
        // Short integer column, mismatched twin, count above the separators, a too-small or null output.
        self::assertSame(-1, $index("music\0title", 11, '', 0, 'abcd', 4, 1, $out, 64));
        self::assertSame(-1, $index("music\0title", 11, "music\x02title", 11, $doc, 8, 1, $out, 64));
        self::assertSame(-1, $index("music\0title", 11, '', 0, $doc.$doc, 16, 2, $out, 64));
        self::assertSame(-2, $index("music\0title", 11, '', 0, $doc, 8, 1, $out, 3));
        self::assertSame(-2, $index("music\0title", 11, '', 0, $doc, 8, 1, null, 64));
        self::assertSame(77, $out[0]);
        self::assertSame(-1, $tv('0', 1, '', 0, $doc, 8, 1, 0, $out, 64));
        self::assertSame(1, $tv('0', 1, '', 0, $doc.$doc, 16, 1, 0, $out, 64));
        self::assertSame(1, $out[0]);
    }

    public function test_nul_unicode_and_mismatched_columns_parity(): void
    {
        $this->requireBindings();
        $nul = ["\0", "music\0", "\0\0", "a\x01b", "\x01"];
        $this->assertParity(SecondarySearchIndex::class, [[...$nul, 'Müsic', '音楽'], ["title\0", "\x01", 'tïtle'], [1, 2]]);
        $this->assertParity(ServiceStatusEnum::class, [[...$nul, 'dégradé', 'DOWN'], [], 60]);
        $this->assertParity(SignupError::class, [[...$nul, 'ünïcode', '-1'], [1, 2, 3, 4, 5, 6, 7, 8, 9], 2]);
        $this->assertParity(TmuxMode::class, [[...$nul, 'fûll', '2'], [1], [], 0]);
        $this->assertParity(TvLookupStatus::class, [[...$nul, 'épisode', '-11'], [], [1, 2, 3], 0]);
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
            yield $short.' common' => [$class, 320, false, 636];
            yield $short.' uncommon' => [$class, 384, true, 637];
            yield $short.' large common' => [$class, 4_096, false, 638];
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
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_630 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set RUN_RUST_FFI_BENCHMARKS=1): seed 636, 8 fixture variants, 50 warmups and
     * NNTMUX_W636_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 aliased, padded, unknown,
     * Unicode, NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired Wave 63.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W636_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(636));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 636 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW63RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 636, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w636-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW63.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW63RustFfi()) {
                self::assertSame($eligible, $class::usesW63RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
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
            SecondarySearchIndex::class => $this->indexBatch($random, $count, $uncommon),
            ServiceStatusEnum::class => $this->serviceBatch($random, $count, $uncommon),
            SignupError::class => $this->signupBatch($random, $count, $uncommon),
            TmuxMode::class => $this->tmuxBatch($random, $count, $uncommon),
            default => $this->tvBatch($random, $count, $uncommon),
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
     * Index lookups with requested fields and document ids; uncommon ones are aliases, unknown, Unicode,
     * NUL-holding or oversized tokens and fields and extreme ids.
     *
     * @return list<mixed>
     */
    private function indexBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $indexes = $fields = $docs = [];
        for ($i = 0; $i < $count; $i++) {
            $case = $this->pick($random, [SecondarySearchIndex::Music, SecondarySearchIndex::Music, SecondarySearchIndex::Games, SecondarySearchIndex::Console,
                SecondarySearchIndex::Anime, SecondarySearchIndex::Anime, SecondarySearchIndex::Books, SecondarySearchIndex::Steam]);
            $index = $case->value;
            $columns = array_keys($case->manticoreColumns());
            $field = $random->getInt(0, 3) === 0 ? $this->pick($random, $case->fulltextFields()) : $this->pick($random, $columns);
            $doc = $random->getInt(1, 5_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1 => $index = $this->oddToken($random, self::INDEX_ODD),
                    2, 3 => $field = $this->oddToken($random, self::FIELD_ODD),
                    4 => $doc = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $indexes[] = $index;
            $fields[] = $field;
            $docs[] = $doc;
        }

        return [$indexes, $fields, $docs];
    }

    /**
     * Stored service statuses with seconds in status and an uptime window; uncommon ones are labels, aliases,
     * unknown, Unicode, NUL-holding or oversized tokens and extreme durations and windows.
     *
     * @return list<mixed>
     */
    private function serviceBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = $durations = [];
        for ($i = 0; $i < $count; $i++) {
            $status = $this->pick($random, ['operational', 'operational', 'operational', 'operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance']);
            $duration = $random->getInt(0, 172_800);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $status = $this->oddToken($random, self::SERVICE_ODD),
                    3 => $duration = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $statuses[] = $status;
            $durations[] = $duration;
        }
        $window = $uncommon ? $this->pick($random, [PHP_INT_MIN, 0, 1, 1 << 30, PHP_INT_MAX, 600]) : $this->pick($random, [3_600, 86_400, 604_800]);

        return [$statuses, $durations, $window];
    }

    /**
     * Signup outcome codes with attempts so far and an attempt limit; uncommon ones are case names, messages,
     * aliases, unknown, Unicode, NUL-holding or oversized tokens and extreme attempts and limits.
     *
     * @return list<mixed>
     */
    private function signupBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $codes = $attempts = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $this->pick($random, [1, 1, 1, 1, -1, -2, -3, -4, -5, -6]);
            $tries = $random->getInt(0, 6);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $code = $this->oddToken($random, self::SIGNUP_ODD),
                    3 => $tries = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $codes[] = $code;
            $attempts[] = $tries;
        }
        $limit = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 30, PHP_INT_MAX]) : $this->pick($random, [3, 5, 10]);

        return [$codes, $attempts, $limit];
    }

    /**
     * Stored tmux modes with requested and active runners and a shared capacity; uncommon ones are case names,
     * aliases, unknown, Unicode, NUL-holding or oversized tokens and extreme requests and capacities.
     *
     * @return list<mixed>
     */
    private function tmuxBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $modes = $requested = $active = [];
        for ($i = 0; $i < $count; $i++) {
            $mode = $this->pick($random, [0, 0, 1, 1, 1, 2]);
            $request = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 12);
            $running = $random->getInt(0, 4);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $mode = $this->oddToken($random, self::TMUX_ODD),
                    3 => $request = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $running = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $modes[] = $mode;
            $requested[] = $request;
            $active[] = $running;
        }
        $capacity = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 41, PHP_INT_MAX]) : $this->pick($random, [0, 200, 1_000]);

        return [$modes, $requested, $active, $capacity];
    }

    /**
     * Stored `releases.tv_episodes_id` values with attempts and seconds since the last attempt; uncommon ones
     * are case names, aliases, legacy and non-canonical numbers, unknown, Unicode, NUL-holding or oversized
     * tokens and extreme counters and limits.
     *
     * @return list<mixed>
     */
    private function tvBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = $attempts = $elapsed = [];
        for ($i = 0; $i < $count; $i++) {
            $status = $this->pick($random, [0, 0, 0, -11, -11, -10, -12, -100, $random->getInt(1, 9_000_000)]);
            $tries = $random->getInt(0, 12);
            $since = $random->getInt(0, 1_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $status = $this->oddToken($random, self::TV_ODD),
                    3 => $tries = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $since = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $statuses[] = $status;
            $attempts[] = $tries;
            $elapsed[] = $since;
        }
        $limit = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 30, PHP_INT_MAX]) : $this->pick($random, [5, 10, 20]);

        return [$statuses, $attempts, $elapsed, $limit];
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
        $php = $class::withW63RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW63RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (SecondarySearchIndex::w63SecondaryIndexPlanBatchNative([], [], []) === null
            || ServiceStatusEnum::w63ServiceStatusPlanBatchNative([], []) === null
            || SignupError::w63SignupErrorPlanBatchNative([], []) === null
            || TmuxMode::w63TmuxModePlanBatchNative([], [], []) === null
            || TvLookupStatus::w63TvLookupStatusPlanBatchNative([], [], []) === null
        ) {
            self::markTestSkipped('The Wave 63.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
