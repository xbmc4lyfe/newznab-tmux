<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CandidateAdmission;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CandidateSampleBatch;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 63.4 batch helpers: CandidateAdmission admission plan rows (identity/hash/id/version
 * validation, duplicate ids and identities, completion permille, age, lease, attempt and due
 * gates), CandidateHeaderInventory inventory plan rows (message-ID pattern and uniqueness, part
 * number range and repeats, per-file declared counts, gaps, completeness and byte totals),
 * CandidatePreparationService preparation plan rows (assessment status, stored reason, manifest
 * path class, NZB/PAR2/sample readiness and the retry/failure disposition), CandidateRepository
 * claim plan rows (claimability, lease claim/renew/return, claim-window rank by next attempt and
 * id, retry windows) and CandidateSampleBatch sample plan rows (group/message-ID de-duplication,
 * per-claim selection limit and coverage). Each export takes a field-major blob plus packed int64
 * words; Rust writes fixed-width int64 rows and a batch trailer that PHP decodes with one
 * unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w63RustFfiState(); no class declares a new property.
 *
 * The paired benchmark (RUN_RUST_FFI_BENCHMARKS=1) times the PHP and native batch methods,
 * including validation, packing and decoding, over interleaved rounds of 320 records in random
 * order per round (fixed seed). Rounds per workload: W63_BENCH_ROUNDS (default 1000, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Decision: three runs at 36a48f7d5 + this change (Apple Silicon, PHP 8.5.12 CLI, release build,
 * 1000 rounds per workload after 10 warmups, load average 6.8-8.5), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): w63AdmissionPlanBatch 2.32/2.16, 1.73/1.69;
 * w63HeaderInventoryPlanBatch 2.26/2.32, 1.52/1.85; w63PreparationPlanBatch 1.54/1.61, 1.33/1.38;
 * w63CandidateRepoPlanBatch 1.47/1.55, 1.27/1.05; w63SampleBatchPlanBatch 2.38/2.29, 1.64/1.75.
 * Shorter 300-round runs on the same loaded host had isolated dips below 1.5x (admission uncommon
 * mean 1.42 and p99 1.15, header inventory uncommon p99 1.44), so rerun at 1000 rounds before
 * revisiting a default. Rust hashes four records per FNV-1a loop; the remaining native cost is
 * mostly PHP validation, column packing and decoding. Common batches hold realistic identities,
 * hashes, statuses, manifest paths, lease tokens, message IDs, groups and part runs; uncommon
 * batches mix mutated, truncated, random-byte (often NUL-bearing, so the 0x01 twin is sent) and
 * empty strings with extreme ints. Admission, header inventory and sample batch default to Rust;
 * preparation and repository retain PHP.
 */
final class RustVerificationAdmissionInventoryPrepRepoSampleBatchW63ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'admission' => [CandidateAdmission::class, 'w63AdmissionPlanBatch', 5, 10],
        'inventory' => [CandidateHeaderInventory::class, 'w63HeaderInventoryPlanBatch', 4, 9],
        'preparation' => [CandidatePreparationService::class, 'w63PreparationPlanBatch', 4, 12],
        'repository' => [CandidateRepository::class, 'w63CandidateRepoPlanBatch', 5, 9],
        'sample' => [CandidateSampleBatch::class, 'w63SampleBatchPlanBatch', 5, 7],
    ];

    /** W63_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        CandidateAdmission::class => true,
        CandidateHeaderInventory::class => true,
        CandidatePreparationService::class => false,
        CandidateRepository::class => false,
        CandidateSampleBatch::class => true,
    ];

    private const int SEED = 6342026;

    private const int NOW = 1_793_000_000;

    private const string UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', ':', '::', '.', '..', '/', '//', '<', '>', '<>', '@', '-', '_', 'x', 'A', '0', '9', 'f', 'g'];

    private const array STATUSES = ['ready', 'ready', 'ready', 'pending', 'pending', 'unknown', 'verifying', 'waiting_upload', 'header_rejected',
        'unrepairable', 'dirty', 'metadata_deferred', 'metadata_review', 'filtered', 'expired', 'Ready', ' ready', ''];

    private const array REASONS = ['', '', 'Declared file inventory is still uploading; publication deferred.',
        'Declared article inventory is still uploading; publication deferred.', 'Invalid or duplicate article reference in candidate headers.',
        'Partial publication deferred: articles still missing', 'Upload stopped growing before it was complete; queued for partial publication.',
        'NNTP 430 no such article', 'Connection reset by peer'];

    private const array GROUPS = ['alt.binaries.teevee', 'alt.binaries.multimedia', 'alt.binaries.hdtv.x264', 'alt.binaries.boneless', 'a.b.moovee',
        'ALT.BINARIES.TEEVEE', ' alt.binaries.mom ', 'alt.binaries.e-book+audio', 'alt', ''];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW63RustFfi();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w63RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W63_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w63', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW63RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length <= 0 ? '' : $random->getBytes($length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 3, 4, 5, 6, 7, 8, 9, 63, 64, 65, 99, 100, 101, 999, 1000, 3599, 3600, 86_399, 86_400, 86_401,
            604_800, 1_000_000, 1_000_001, 0xFFFF, 0x10000, 0xFFFFFF, 0x1000000, 0x7FFFFFFF, 0x80000000, 0x80000001, 31_536_000, 31_536_001,
            self::NOW, self::NOW + 1, self::NOW - 86_400, (1 << 36) - 1, 1 << 36, (1 << 40) - 1, 1 << 40, (1 << 40) + 1, PHP_INT_MAX,
            PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000),
            $random->getInt(0, 1 << 41)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    private static function messageId(Randomizer $random, int $number, int $total): string
    {
        return '<part'.$number.'of'.$total.'.'.bin2hex($random->getBytes(6)).'@'.self::pick($random, ['news.example', 'powerpost2000AA.local', 'JBinUp.local']).'>';
    }

    private static function uuid(Randomizer $random): string
    {
        $hex = bin2hex($random->getBytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** @return list<mixed> */
    private static function admissionItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW + $random->getInt(-3600, 3600);
        $expected = $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 4000);

        return [
            self::spoil($random, bin2hex($random->getBytes(20)), $uncommon),
            self::spoil($random, $random->getInt(0, 9) === 0 ? 'not-a-hash' : hash('sha256', $random->getBytes(8)), $uncommon),
            self::spoil($random, self::pick($random, self::STATUSES), $uncommon),
            self::int($random, $random->getInt(0, 30) === 0 ? 0 : $random->getInt(1, 4000), $uncommon),
            self::int($random, $random->getInt(0, 20) === 0 ? 0 : $random->getInt(1, 6), $uncommon),
            self::int($random, $random->getInt(0, 10), $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? $expected : $random->getInt(0, $expected), $uncommon),
            self::int($random, $expected, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $now - $random->getInt(0, 200_000), $uncommon),
            self::int($random, $now + $random->getInt(-7200, 1800), $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $now + $random->getInt(-600, 600), $uncommon),
            self::int($random, $now, $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function preparationItem(Randomizer $random, bool $uncommon): array
    {
        $hex = bin2hex($random->getBytes(16));
        $path = match ($random->getInt(0, 9)) {
            0 => '',
            1 => '/var/lib/nntmux/'.$hex.'.json',
            2 => '../'.$hex.'.json',
            3 => substr($hex, 0, 2).'/'.$hex.'.json.zst',
            default => substr($hex, 0, 2).'/'.substr($hex, 2, 2).'/'.$hex.'.json.gz',
        };
        $reason = self::pick($random, self::REASONS);
        if ($random->getInt(0, 30) === 0) {
            $reason = str_repeat('é', $random->getInt(990, 1010));
        }

        return [
            self::spoil($random, self::pick($random, ['ready', 'ready', 'ready', 'waiting_upload', 'waiting_upload', 'header_rejected', 'unknown', 'unknown', 'pending']), $uncommon),
            self::spoil($random, $reason, $uncommon),
            self::spoil($random, $path, $uncommon),
            self::int($random, $random->getInt(0, 6) === 0 ? 0 : $random->getInt(10_000, 900_000), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 40), $uncommon),
            self::int($random, $random->getInt(0, 8) === 0 ? 0 : $random->getInt(1, 80), $uncommon),
            self::int($random, $random->getInt(0, 10), $uncommon),
            self::int($random, self::pick($random, [300, 900, 1800, 3600]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function repositoryItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW;
        $status = self::pick($random, self::STATUSES);
        $held = $random->getInt(0, 2) === 0;

        return [
            self::spoil($random, $status, $uncommon),
            self::spoil($random, $status === 'verifying' || $held ? self::uuid($random) : '', $uncommon),
            self::spoil($random, self::pick($random, self::REASONS), $uncommon),
            self::int($random, $random->getInt(1, 600), $uncommon),
            self::int($random, $random->getInt(0, 30) === 0 ? 0 : $random->getInt(1, 5), $uncommon),
            self::int($random, $now + $random->getInt(-7200, 900), $uncommon),
            self::int($random, $held ? $now + $random->getInt(-600, 600) : 0, $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $random->getInt(0, 15) === 0 ? $random->getInt(1, 900_000) : 0, $uncommon),
            self::int($random, 300, $uncommon),
            self::int($random, 900, $uncommon),
            self::int($random, 86_400, $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? 1 : 0, $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function inventoryBatch(Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        $file = 0;
        while (\count($items) < $size) {
            $key = 'file'.$file++.'.'.bin2hex($random->getBytes(3)).'.rar';
            $total = $random->getInt(1, 60);
            for ($number = 1; $number <= $total && \count($items) < $size; $number++) {
                if ($random->getInt(0, 20) === 0) {
                    continue;
                }
                $items[] = [
                    self::spoil($random, $key, $uncommon && $random->getInt(0, 4) === 0),
                    self::spoil($random, $random->getInt(0, 60) === 0 && $items !== [] ? $items[array_key_last($items)][1] : self::messageId($random, $number, $total), $uncommon),
                    self::int($random, $random->getInt(0, 40) === 0 ? $number - 1 : $number, $uncommon),
                    self::int($random, $random->getInt(0, 40) === 0 ? 0 : $random->getInt(300_000, 800_000), $uncommon),
                    self::int($random, $total, $uncommon),
                ];
            }
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function sampleBatch(Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        $claim = $random->getInt(1, 1000);
        while (\count($items) < $size) {
            $claim += $random->getInt(0, 3) === 0 ? 0 : 1;
            $total = $random->getInt(1, 80);
            $group = self::pick($random, self::GROUPS);
            for ($k = $random->getInt(1, 6); $k > 0 && \count($items) < $size; $k--) {
                $number = self::pick($random, [1, $total, $random->getInt(1, $total)]);
                $items[] = [
                    self::spoil($random, $group, $uncommon),
                    self::spoil($random, $random->getInt(0, 25) === 0 && $items !== [] ? $items[array_key_last($items)][1] : self::messageId($random, $number, $total), $uncommon),
                    self::int($random, $claim, $uncommon),
                    self::int($random, $number, $uncommon),
                    self::int($random, $total, $uncommon),
                    self::int($random, $random->getInt(0, 40) === 0 ? 0 : $random->getInt(300_000, 800_000), $uncommon),
                ];
            }
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        if ($key === 'inventory') {
            return self::inventoryBatch($random, $size, $uncommon);
        }
        if ($key === 'sample') {
            return self::sampleBatch($random, $size, $uncommon);
        }
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'admission' => self::admissionItem($random, $uncommon),
                'preparation' => self::preparationItem($random, $uncommon),
                default => self::repositoryItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $now = self::NOW;
        $hex = str_repeat('0123456789abcdef', 4);
        $nul = "a\0b";
        $max = PHP_INT_MAX;
        $min = PHP_INT_MIN;

        return match ($key) {
            'admission' => [
                ['abc', $hex, 'ready', 7, 1, 0, 10, 10, $now - 100, $now, 0, $now],
                ['abc', $hex, 'ready', 8, 1, 0, 10, 10, $now - 100, $now, 0, $now],
                ['def', $hex, 'pending', 9, 1, 0, 5, 10, $now - 86_400, $now, 0, $now],
                ['def0', $hex, 'pending', 10, 1, 0, 5, 10, $now - 86_399, $now, 0, $now],
                ['aa', $hex, 'verifying', 11, 1, 0, 10, 10, 0, $now, $now, $now],
                ['ab', $hex, 'unknown', 12, 1, 8, 10, 10, 0, $now, $now - 1, $now],
                ['ac', $hex, 'unknown', 13, 1, 7, 10, 10, 0, $now + 1, 0, $now],
                ['ad', $hex, 'waiting_upload', 14, 1, 0, 10, 10, 0, $now, 0, $now],
                ['AE', strtoupper($hex), 'ready', 15, 1, 0, 11, 10, 0, $now, 0, $now],
                ['', '', '', 0, 0, 0, 0, 0, 0, 0, 0, 0],
                [str_repeat('f', 70_000), $hex, 'ready', 16, 1, 0, 0, 0, $now + 5, $now, 0, $now],
                ['café', "{$hex}\0", 'ready', $max, $max, $max, $max, $max, $max, $max, $max, $max],
                [$nul, $nul, $nul, $min, $min, $min, $min, $min, $min, $min, $min, $min],
                ['b1', $hex, 'ready', 17, 1, $min, $min, 1, $min, $min, $min, $min],
            ],
            'inventory' => [
                ['f', '<a@b>', 1, 100, 3],
                ['f', '<c@d>', 3, 200, 3],
                ['f', '<c@d>', 3, 200, 3],
                ['f', 'e@f', 2, 0, 4],
                ['g', '<<>>', 0, -1, 0],
                ['', 'a b@c', 5, 1, 4],
                ['g', "x\x7fy", $max, $max, $max],
                ['h', "x\x80y\xff", $min, $min, $min],
                ['h', '<日本@例>', 0x1000000, 1 << 36, 0x7FFFFFFF],
                ['h', '<z@z>', 0x80000001, (1 << 36) + 1, 0x80000002],
                ['i', 'in<ner@x', 1, 1, 1],
                [$nul, $nul, 1, 1, 1],
                ["k\0", "\0<a@b>", 1, 1, 1],
            ],
            'preparation' => [
                ['ready', '', 'ab/cd/x.json.gz', 100, 2, 5, 0, 900],
                ['ready', 'x', '/abs/x.json', 100, 0, 5, 0, 900],
                ['ready', 'x', 'a/../b.json', 100, 0, 5, 0, 900],
                ['ready', 'x', '..x/b.json.zst', 0, 0, 5, 0, 900],
                ['ready', 'x', "a\tb", 1, 1, 0, 0, 900],
                ['ready', 'x', 'ok.txt', 1, 1, 0, 0, 900],
                ['ready', 'x', 'ok.json', 1, 1, 100, 0, 900],
                ['unknown', 'NNTP 430', '', 0, 0, 0, 7, 900],
                ['unknown', 'NNTP 430', '', 0, 0, 0, 8, 900],
                ['unknown', 'NNTP 430', '', 0, 0, 0, 2, 0],
                ['unknown', 'NNTP 430', '', 0, 0, 0, 6, 86_401],
                ['unknown', 'NNTP 430', '', 0, 0, 0, $max, $max],
                ['header_rejected', str_repeat('é', 1001), '', 0, 0, 0, 0, 0],
                ['waiting_upload', str_repeat('a', 2001), 'x.json', $max, $max, $max, $min, $min],
                ['pending', '', '', $min, $min, $min, $min, $min],
                ['', '', '', 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, 1, 1, 1, 1, 1],
                ["ready\0", "a\0", "x\0.json", 1, 1, 1, 1, 1],
            ],
            'repository' => [
                ['ready', '', '', 2, 1, $now - 10, 0, $now, 0, 60, 10, 20, 0],
                ['pending', '', '', 1, 1, $now - 10, 0, $now, 0, 60, 10, 20, 1],
                ['ready', '', '', 3, 1, $now - 20, 0, $now, 0, 60, 10, 20, 0],
                ['ready', '', '', 3, 1, $now - 30, 0, $now, 0, 60, 10, 20, 0],
                ['verifying', self::UUID, '', 4, 1, $now - 10, $now + 5, $now, 0, 60, 10, 20, 0],
                ['verifying', strtoupper(self::UUID), '', 5, 1, $now - 10, $now, $now, 0, 60, 10, 20, 0],
                ['verifying', 'token', '', 6, 1, $now + 10, $now - 1, $now, 0, 60, 10, 20, 0],
                ['verifying', substr(self::UUID, 1).'0', '', 7, 1, $now + 10, $now + 10, $now, 0, 60, 10, 20, 0],
                ['unknown', '', 'Partial publication deferred: x', 8, 0, $now + 10, 0, $now, 0, 60, 10, 20, 0],
                ['ready', '', '', 9, 1, $now, 0, $now, 5, 60, 10, 20, 0],
                ['waiting_upload', '', '', 10, 1, $now, 0, $now, 0, 60, 10, 20, 0],
                ['ready', '', '', 11, 1, $max, $max, $max, $max, $max, $max, $max, $max],
                ['ready', '', '', 12, 1, $min, $min, $min, $min, $min, $min, $min, $min],
                ['pending', str_replace('-', 'x', self::UUID), '', 13, 1, 0, 0, 0, 0, 0, -5, -5, 1],
                ['', '', '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, 14, 1, 0, 0, 0, 0, 0, 0, 0, 0],
            ],
            default => [
                ['alt.binaries.x', '<a@b>', 1, 1, 3, 10],
                ['alt.binaries.x', '<c@d>', 1, 3, 3, 10],
                ['ALT.Binaries.X ', '<e@f>', 1, 2, 3, 10],
                ['alt.binaries.x', '<g@h>', 1, 2, 3, 10],
                ['alt.binaries.x', '<i@j>', 1, 2, 3, 10],
                ['alt.binaries.x', '<a@b>', 2, 1, 3, 10],
                ['alt', '', 3, 1, 1, 10],
                ['.alt.x', 'a b@c', 0, 0, 0, 0],
                ['alt.x.', '<k@l>', 4, 2, 1, 1],
                ['alt.bin aries', '<m@n>', 5, 1, 0x80000000, 1],
                ['alt.日本', '<o@p>', 5, 0x7FFFFFFF, 0x7FFFFFFF, 1 << 36],
                ['', '<q@r>', 6, $max, $max, $max],
                [' ', '<s@t>', $min, $min, $min, $min],
                [$nul, $nul, 7, 1, 1, 1],
                ["alt\0.x", "<u@v>\0", 8, 1, 2, 1],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w63_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W63_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W63_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w634_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W63_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W63_TRAILER_WORDS'));
        foreach (['usesW63RustFfi', 'withW63RustFfi', 'enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w63RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW63RustFfi());
        self::assertFalse($class::withW63RustFfi(false, static fn (): bool => $class::usesW63RustFfi()));
        self::assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW63RustFfi(), 'withW63RustFfi restores the default');

        $class::enableW63RustFfi();
        self::assertTrue($class::usesW63RustFfi());
        $class::disableW63RustFfi();
        self::assertFalse($class::usesW63RustFfi());
        self::assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()));
        self::assertFalse($class::usesW63RustFfi(), 'withW63RustFfi restores an explicit override');
        $class::resetW63RustFfi();
        self::assertSame($constant->getValue(), $class::usesW63RustFfi());

        $nested = $class::withW63RustFfi(false, static fn (): array => [
            $class::usesW63RustFfi(),
            $class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()),
            $class::usesW63RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW63RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW63RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        $readonly = new ReflectionClass(CandidateAdmission::class);
        self::assertTrue($readonly->isReadOnly());
        self::assertSame([], $readonly->getProperties(\ReflectionProperty::IS_STATIC));
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w63', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW63RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW63RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        CandidateAdmission::withW63RustFfi(false, static function (): void {
            foreach ([CandidateHeaderInventory::class, CandidatePreparationService::class, CandidateRepository::class, CandidateSampleBatch::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW63RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W63_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW63RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [CandidateAdmission::usesW49RustFfi(), CandidateHeaderInventory::usesW49RustFfi(),
            CandidatePreparationService::usesRustFfi(), CandidatePreparationService::usesW49RustFfi(), CandidateRepository::usesRustFfi(),
            CandidateRepository::usesW49RustFfi(), CandidateSampleBatch::usesW49RustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW63RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertSame(['candidate_id' => 1, 'identity' => 'a', 'version' => 2, 'manifest_hash' => 'h'], (new CandidateAdmission(1, 'a', 2, 'h'))->toArrayPhp());
                    self::assertSame('ab', CandidatePreparationService::truncateAssessmentReasonPhp('abc', 2));
                    self::assertTrue(CandidateRepository::hasPendingCapacityPhp(0, 100));
                    self::assertSame(2, CandidateSampleBatch::claimBudgetPhp(5, 0, 3, 2));
                    self::assertSame(3, \count(CandidateAdmission::fingerprintAdmissionsPhp([], [], [], [])));
                });
            }
        }
        self::assertSame($before, $snapshot());
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
            [array_map(static fn (mixed $v): mixed => \is_string($v) ? null : $v, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W63', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_use_the_twin_frame(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $edges = self::edgeItems($key);
        $nul = array_values(array_filter($edges, static fn (array $item): bool => str_contains(implode('', array_filter($item, 'is_string')), "\0")));
        self::assertNotSame([], $nul);
        self::assertParity($class, $method, $words, $trailer, $nul, $key.' NUL-bearing values');
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': the 0x01 twin frames NUL-bearing values');
        }
    }

    public function test_hand_checked_rows(): void
    {
        $fnv = static function (string $value): int {
            /** @var array{1: int} $hash */
            $hash = unpack('N', hash('fnv1a32', $value, true));

            return $hash[1];
        };
        $now = self::NOW;
        $hex = str_repeat('0123456789abcdef', 4);

        $admission = CandidateAdmission::w63AdmissionPlanBatchPhp([
            ['abc', $hex, 'ready', 7, 1, 0, 10, 10, $now - 100, $now, 0, $now],
            ['abc', $hex, 'pending', 8, 1, 2, 5, 10, $now - 86_400, $now + 30, 0, $now],
        ]);
        self::assertSame([7 | 1 << 4 | 0x1F << 8 | 3 << 16, $fnv('abc:'.$hex), 1000, 100, 0], \array_slice($admission, 0, 5));
        self::assertSame([2 | 2 << 4 | 0x5F << 8 | 3 << 16 | 2 << 32, $fnv('abc:'.$hex), 500, 86_400, 30], \array_slice($admission, 5, 5));
        self::assertSame([0, 1, 0, 0, 0, 0, 1, 0, 0], \array_slice($admission, 10, 9));

        $inventory = CandidateHeaderInventory::w63HeaderInventoryPlanBatchPhp([
            ['f', '<a@b>', 1, 100, 3],
            ['f', '<c@d>', 3, 200, 3],
            ['g', '<c@d>', 1, 0, 2],
        ]);
        self::assertSame([3 << 8 | 1 << 32, $fnv('a@b'), 0, 0], \array_slice($inventory, 0, 4));
        self::assertSame([3 << 8 | 3 << 32, $fnv('c@d'), 1, 0], \array_slice($inventory, 4, 4));
        self::assertSame([18 | 3 << 8 | 1 << 32, $fnv('c@d'), 0, 1], \array_slice($inventory, 8, 4));
        self::assertSame([3, 2, 5, 0, 1, 300, 1, 1], \array_slice($inventory, 12, 8));

        $preparation = CandidatePreparationService::w63PreparationPlanBatchPhp([
            ['ready', '', 'ab/x.json.gz', 100, 2, 5, 0, 900],
            ['unknown', 'NNTP 430', '', 0, 0, 0, 2, 900],
        ]);
        self::assertSame([8 | 1 << 8 | 2 << 10 | 7 << 12, 0, -1, 155], \array_slice($preparation, 0, 4));
        self::assertSame([3 | 3 << 4 | 8 << 16 | 2 << 32, $fnv('NNTP 430'), 3600, 0], \array_slice($preparation, 4, 4));
        self::assertSame([0, 0, 1, 0, 0, 0, 0, 1, 0, 100, 3600], \array_slice($preparation, 8, 11));

        $repository = CandidateRepository::w63CandidateRepoPlanBatchPhp([
            ['ready', '', '', 2, 1, 50, 0, 100, 0, 60, 10, 20, 0],
            ['pending', '', '', 1, 1, 40, 0, 100, 0, 60, 10, 20, 1],
            ['verifying', self::UUID, '', 3, 1, 10, 150, 100, 0, 60, 10, 20, 0],
            ['unknown', '', '', 4, 1, 130, 0, 100, 0, 60, 10, 20, 0],
        ]);
        self::assertSame([1 | 1 << 4 | (1 | 2 | 16 | 64) << 12, 160, 110, 1, 0], \array_slice($repository, 0, 5));
        self::assertSame([1 | 2 << 4 | (1 | 2 | 16) << 12, 160, 120, 0, 0], \array_slice($repository, 5, 5));
        self::assertSame([2 | 4 << 4 | 1 << 8 | 1 << 12, 160, 110, -1, 0], \array_slice($repository, 10, 5));
        self::assertSame([5 | 3 << 4 | 2 << 12, -1, 110, -1, 30], \array_slice($repository, 15, 5));
        self::assertSame([2, 1, 0, 0, 1, 0, 2, 130], \array_slice($repository, 20, 8));

        $sample = CandidateSampleBatch::w63SampleBatchPlanBatchPhp([
            ['alt.binaries.x', '<a@b>', 1, 1, 4, 10],
            [' ALT.Binaries.X ', '<a@b>', 2, 4, 4, 20],
            ['alt', '', 2, 2, 4, 30],
        ]);
        self::assertSame([1 | 1 << 8 | 1 << 10 | 3 << 12 | 1 << 36, $fnv('a@b'), $fnv('alt.binaries.x'), 0, 250], \array_slice($sample, 0, 5));
        self::assertSame([4 | 1 << 8 | 2 << 10 | 3 << 12, $fnv('a@b'), $fnv('alt.binaries.x'), 0, 1000], \array_slice($sample, 5, 5));
        self::assertSame([8 | 2 << 8 | 3 << 10, 0, $fnv('alt'), 2, 500], \array_slice($sample, 10, 5));
        self::assertSame([1, 1, 1, 2, 2, 10], \array_slice($sample, 15, 6));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('W63_BENCH_ROUNDS') ?: 1000));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            // A default-enabled helper below the gate fails; one below it must retain PHP.
            if ($class::usesW63RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW63.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
