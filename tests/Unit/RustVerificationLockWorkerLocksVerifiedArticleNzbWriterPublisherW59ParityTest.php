<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\VerificationLock;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Services\Yenc\ArticleFrame;
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
 * Wave 59.4 batch helpers: VerificationLock lock state rows (name/owner/token validation, lease
 * renewal and expiry, fencing), VerificationWorkerLocks worker lock inventory rows (slot range,
 * heartbeat staleness, duplicate slots and scoring), VerifiedArticle article rows (filename,
 * dimension, checksum and message-id checks with part ordering and completeness),
 * VerifiedNzbWriter segment plan rows (XML attribute/text escaping, segment line lengths and
 * per-file ordering) and VerifiedReleasePublisher publication plan rows (claim guard, name,
 * category, size and completion gates). Each export takes a field-major blob plus packed int64
 * words; Rust writes fixed-width int64 rows and a batch trailer that PHP decodes with one
 * unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call
 * count in the function static of w59RustFfiState().
 *
 * The paired benchmark (NNTMUX_W594_BENCH=1) times the PHP and native batch methods, including
 * validation, packing and decoding, over interleaved rounds of 320 records in random order per
 * round (fixed seed). Rounds per workload: NNTMUX_W594_BENCH_ROUNDS (default 200, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * and skips without the native library.
 *
 * Four runs at b7c37a17f + this change (Apple M5, PHP 8.5.12 CLI, rustc 1.99.1 release build,
 * 200 rounds per workload after 10 warmups, load average 5.5-10), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): lockStateRowBatch 2.69/2.20, 1.74/1.58;
 * workerLockRowBatch 3.12/1.71, 2.21/2.01; articleRowBatch 1.93/1.70, 1.28/1.36;
 * segmentPlanRowBatch 1.88/1.81, 1.69/1.67; publicationPlanRowBatch 1.20/0.91, 0.98/0.99.
 * Common batches hold realistic lock names, holders, tokens and lease windows, posting-order
 * article runs, NZB segment runs and publication claims; uncommon batches mix mutated,
 * truncated, random-byte (often NUL-bearing, so the 0x01 twin is sent) and empty strings with
 * extreme ints. The lock, worker lock and NZB segment helpers default to Rust;
 * articleRowBatch (rejected rows fail fast in PHP) and publicationPlanRowBatch (validating and
 * packing sixteen fields costs about as much as the PHP gates) default to PHP.
 */
final class RustVerificationLockWorkerLocksVerifiedArticleNzbWriterPublisherW59ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'lock' => [VerificationLock::class, 'lockStateRowBatch', 6, 8],
        'worker' => [VerificationWorkerLocks::class, 'workerLockRowBatch', 6, 7],
        'article' => [VerifiedArticle::class, 'articleRowBatch', 6, 8],
        'nzb' => [VerifiedNzbWriter::class, 'segmentPlanRowBatch', 6, 7],
        'publisher' => [VerifiedReleasePublisher::class, 'publicationPlanRowBatch', 7, 8],
    ];

    /** W59_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        VerificationLock::class => true,
        VerificationWorkerLocks::class => true,
        VerifiedArticle::class => false,
        VerifiedNzbWriter::class => true,
        VerifiedReleasePublisher::class => false,
    ];

    private const int SEED = 5942026;

    private const array SPICE = ['é', 'ß', '日本', "\t", "\n", "\r", "\0", "\x01", "\x0b", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', ':', '.', '-', '_', '/', '\\', '<', '>', '&', '"', "'", '@', 'A', 'z', '0', '9', 'f', 'F'];

    private const string HEX = '0123456789abcdef';

    private const string WORD = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
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
        return (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W59_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w59', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW59RustFfi($native, static fn (): array => $class::{$method}($items));
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

    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 9, 10, 20, 63, 64, 65, 255, 256, 999, 1000, 9999, 10_000, 1_000_000, 1_000_001, 67_108_864,
            67_108_865, 1 << 32, (1 << 40) + 1, (1 << 42) + 1, 999_999_999_999_999_999, 1_000_000_000_000_000_000, PHP_INT_MAX, PHP_INT_MIN,
            PHP_INT_MAX - 1, PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000), $random->getInt(0, 1 << 43)]);
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

    private static function lockName(Randomizer $random): string
    {
        return match ($random->getInt(0, 6)) {
            0 => 'worker',
            1, 2 => 'worker-'.$random->getInt(1, 256),
            3 => 'candidate-'.$random->getInt(1, 50_000_000),
            4 => 'publication-forwarder-'.self::bytes($random, 12, self::HEX).'-'.$random->getInt(0, 9),
            5 => self::pick($random, ['gc', 'expiry', 'preparation']),
            default => 'release:'.$random->getInt(1, 9_999_999).'.verify_'.self::bytes($random, 6, 'abcxyz'),
        };
    }

    private static function holder(Randomizer $random): string
    {
        return 'idx-'.$random->getInt(1, 40).'.lan:'.$random->getInt(1, 4_000_000);
    }

    private static function token(Randomizer $random): string
    {
        $hex = self::bytes($random, 32, self::HEX);

        return $random->getInt(0, 1) === 0 ? $hex
            : substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** @return array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int} */
    private static function lockItem(Randomizer $random, bool $uncommon): array
    {
        $now = 1_793_000_000_000 + $random->getInt(0, 86_400_000);
        $acquired = $now - $random->getInt(0, 90_000);
        $expires = $random->getInt(0, 9) === 0 ? 0 : $acquired + $random->getInt(1_000, 120_000);
        $fence = $random->getInt(1, 1_000_000);
        $held = $random->getInt(0, 7) === 0 ? $fence - $random->getInt(1, 3) : $fence;
        if ($uncommon) {
            [$acquired, $expires, $now, $held, $fence] = array_map(
                static fn (int $v): int => $random->getInt(0, 2) === 0 ? self::extremeInt($random) : $v, [$acquired, $expires, $now, $held, $fence]);
        }

        return [self::spoil($random, self::lockName($random), $uncommon), self::spoil($random, self::holder($random), $uncommon),
            self::spoil($random, self::token($random), $uncommon), $acquired, $expires, $now, $held, $fence];
    }

    /** @return array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int} */
    private static function workerItem(Randomizer $random, bool $uncommon): array
    {
        $now = 1_793_000_000_000 + $random->getInt(0, 86_400_000);
        $stale = self::pick($random, [30_000, 60_000, 120_000, 300_000]);
        $heartbeat = $random->getInt(0, 12) === 0 ? 0 : $now - $random->getInt(0, 2 * $stale);
        $ints = [$random->getInt(1, 8), self::pick($random, [0, 0, 8, 16, 32]), $heartbeat, $now, $stale];
        if ($uncommon) {
            $ints = array_map(static fn (int $v): int => $random->getInt(0, 2) === 0 ? self::extremeInt($random) : $v, $ints);
        }
        $name = $random->getInt(0, 2) === 0 ? self::lockName($random) : 'worker-'.$random->getInt(1, 40);

        return [self::spoil($random, $name, $uncommon), self::spoil($random, self::holder($random), $uncommon), ...$ints];
    }

    /**
     * Article runs in posting order: each file's parts 1..total (occasionally skipped, repeated
     * or swapped), with realistic yEnc dimensions.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>
     */
    private static function articleBatch(Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        while (\count($items) < $size) {
            $total = $random->getInt(1, 60);
            $segment = self::pick($random, [384_000, 716_800, 768_000]);
            $fileSize = $total * $segment - $random->getInt(0, $segment - 1);
            $name = self::pick($random, ['Some.Show.S01E0', 'Movie.2026.2160p.', 'Album-', 'é-文件 ']).$random->getInt(1, 99)
                .self::pick($random, ['.mkv', '.part01.rar', '.r00', '.flac', '.nfo', '.vol00+01.par2']);
            for ($part = 1; $part <= $total && \count($items) < $size; $part++) {
                $p = $random->getInt(0, 30) === 0 ? $random->getInt(1, $total + 2) : $part;
                $begin = ($p - 1) * $segment + 1;
                $end = min($p * $segment, $fileSize);
                $ints = [$p, $random->getInt(0, 20) === 0 ? 0 : $total, $begin, $end, $fileSize];
                if ($uncommon) {
                    $ints = array_map(static fn (int $v): int => $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $v, $ints);
                }
                $crc = self::bytes($random, $random->getInt(0, 9) === 0 ? $random->getInt(1, 7) : 8, '0123456789abcdefABCDEF');
                $id = '<'.self::bytes($random, $random->getInt(8, 40), self::WORD).'$'.$p.'@'.self::pick($random, ['nntmux', 'news.example.com', 'ngPost']).'>';
                $items[] = [self::spoil($random, $name, $uncommon), self::spoil($random, $random->getInt(0, 4) === 0 ? trim($id, '<>') : $id, $uncommon),
                    self::spoil($random, $crc, $uncommon), ...$ints];
            }
        }

        return $items;
    }

    /**
     * NZB segment runs: per file a subject and total parts with segments 1..n in order
     * (occasionally out of order), as VerifiedNzbWriter::documentPhp() writes them.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: int}>
     */
    private static function nzbBatch(Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        while (\count($items) < $size) {
            $segments = $random->getInt(1, 40);
            $total = $random->getInt(1, 99);
            $subject = self::pick($random, ['[01/', '"Release.Name.2026.1080p-GRP" ', 'Re: <Album> & "Live" ', 'Café '.self::bytes($random, 4, self::WORD).' '])
                .$random->getInt(1, 99).self::pick($random, ['] - "file.part01.rar" yEnc', ' - "track.flac"', " yEnc\t(1/9)", '']);
            for ($n = 1; $n <= $segments && \count($items) < $size; $n++) {
                $number = $random->getInt(0, 25) === 0 ? $random->getInt(1, $segments + 3) : $n;
                $id = '<'.self::bytes($random, $random->getInt(10, 48), self::WORD.'.$-').'@'.self::pick($random, ['nntmux', 'a&b.example', 'x']).'>';
                $ints = [$total, $number, $random->getInt(300_000, 800_000)];
                if ($uncommon) {
                    $ints = array_map(static fn (int $v): int => $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $v, $ints);
                }
                $items[] = [self::spoil($random, $subject, $uncommon), self::spoil($random, $id, $uncommon), ...$ints];
            }
        }

        return $items;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int, 13: int, 14: int, 15: int} */
    private static function publisherItem(Randomizer $random, bool $uncommon): array
    {
        $hash = hash('sha256', (string) $random->getInt(0, PHP_INT_MAX));
        $token = self::token($random);
        $now = 1_793_000_000 + $random->getInt(0, 86_400);
        $lease = $random->getInt(0, 15) === 0 ? '' : gmdate('Y-m-d H:i:s', $now + $random->getInt(-120, 900));
        $version = $random->getInt(1, 50);
        $name = match ($random->getInt(0, 5)) {
            0 => self::bytes($random, self::pick($random, [16, 32, 40]), self::HEX),
            1 => 'Some.Show.S0'.$random->getInt(1, 9).'E'.$random->getInt(10, 30).'.1080p.WEB.h264-GRP',
            2 => 'Artist - Album (2026) [FLAC] 日本',
            3 => 'Movie.Title.2026.2160p.UHD.BluRay.x265-'.self::bytes($random, 4, self::WORD),
            default => self::bytes($random, $random->getInt(5, 60), self::WORD.'. -_()[]'),
        };
        $expected = $random->getInt(0, 20) === 0 ? 0 : $random->getInt(1, 400);
        $strings = [$name, $hash, $random->getInt(0, 12) === 0 ? strrev($hash) : $hash, $token, $random->getInt(0, 12) === 0 ? self::token($random) : $token,
            $lease, gmdate('Y-m-d H:i:s', $now)];
        $ints = [$version, $random->getInt(0, 12) === 0 ? $version + 1 : $version,
            self::pick($random, [2040, 2045, 5030, 5040, 3010, 4050, 7020, 1999, 2000, 10, 20, 0, 6040, 8010]),
            $random->getInt(1, 80_000_000_000), self::pick($random, [0, 0, 1_048_576]), self::pick($random, [0, 0, 0, 50_000_000_000]),
            max(0, $expected - $random->getInt(0, 3)), $expected, self::pick($random, [10_000, 10_000, 9_500, 0])];
        if ($uncommon) {
            $strings = array_map(static fn (string $s): string => self::spoil($random, $s, true), $strings);
            $ints = array_map(static fn (int $v): int => $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $v, $ints);
        }

        return [...$strings, ...$ints];
    }

    /** @return list<mixed> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        if ($key === 'article') {
            return self::articleBatch($random, $size, $uncommon);
        }
        if ($key === 'nzb') {
            return self::nzbBatch($random, $size, $uncommon);
        }
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'lock' => self::lockItem($random, $uncommon),
                'worker' => self::workerItem($random, $uncommon),
                default => self::publisherItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<mixed> */
    private static function edgeItems(string $key): array
    {
        $hex = str_repeat('a1', 16);
        $uuid = '0123abcd-0123-4567-89ab-0123456789ab';
        $long = str_repeat('a', 191);
        $max = 1 << 42;

        return match ($key) {
            'lock' => [
                ['worker', 'h:1', $hex, 0, 0, 0, 0, 0],
                ['worker-1', 'h:1', $uuid, 1000, 2000, 1999, 5, 5],
                ['worker-1', 'h:1', $uuid, 1000, 2000, 2000, 5, 5],
                ['worker-1', 'h:1', $uuid, 1000, 2000, 1667, 5, 5],
                ['worker-1', 'h:1', $uuid, 1000, 2000, 1666, 5, 5],
                ['worker-1', 'h:1', $hex, 3000, 2000, 1000, 5, 5],
                ['worker-1', 'h:1', $hex, 1000, 2000, 1500, 4, 5],
                ['worker-1', 'h:1', $hex, 1000, 2000, 1500, 6, 5],
                ['worker-1', 'h:1', $hex, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX],
                ['worker-1', 'h:1', $hex, $max + 5, $max + 9, $max - 1, PHP_INT_MAX, PHP_INT_MAX],
                [$long, 'h:1', $hex, 1, 2, 1, 0, 0],
                [$long.'a', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['work er', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['worker-', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['worker-123456789', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['worker-1234567890', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['candidate-0', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['publication-forwarder-0123456789ab-1', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['publication-forwarder-0123456789AB-1', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['publication-forwarder-0123456789ab-', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['publication-forwarder-0123456789ab1', 'h:1', $hex, 1, 2, 1, 0, 0],
                ['expiry', '', $hex, 1, 2, 1, 0, 0],
                ['gc', ':1', $hex, 1, 2, 1, 0, 0],
                ['gc', 'h:', $hex, 1, 2, 1, 0, 0],
                ['gc', 'a:b:1', $hex, 1, 2, 1, 0, 0],
                ['gc', str_repeat('h', 253).':1', $hex, 1, 2, 1, 0, 0],
                ['gc', str_repeat('h', 254).':1', $hex, 1, 2, 1, 0, 0],
                ['gc', 'h_1:1', $hex, 1, 2, 1, 0, 0],
                ['gc', 'h:1234567890', $hex, 1, 2, 1, 0, 0],
                ['preparation', 'h:1', '', 1, 2, 1, 0, 0],
                ['preparation', 'h:1', strtoupper($hex), 1, 2, 1, 0, 0],
                ['preparation', 'h:1', strtoupper($uuid), 1, 2, 1, 0, 0],
                ['preparation', 'h:1', str_replace('-', 'a', $uuid), 1, 2, 1, 0, 0],
                ['preparation', 'h:1', '-'.substr($uuid, 1), 1, 2, 1, 0, 0],
                ["worker\0", "h\0:1", "\0".substr($hex, 1), 1, 2, 1, 0, 0],
            ],
            'worker' => [
                ['worker-1', 'h:1', 2, 0, 100, 150, 60],
                ['worker-1', 'h:1', 2, 0, 100, 1000, 60],
                ['worker-2', 'h:1', 2, 0, 100, 160, 60],
                ['worker-3', 'h:1', 2, 0, 100, 161, 60],
                ['worker-3', 'h:1', 2, 1, 0, 161, 60],
                ['worker-256', 'h:1', 64, 1000, 200, 100, 60],
                ['worker-193', 'h:1', 64, 1000, 200, 100, 60],
                ['worker-192', 'h:1', 64, PHP_INT_MAX, 200, 100, 60],
                ['worker-1', 'h:1', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['worker-1', 'h:1', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                ['worker-1', 'h:1', 0, -5, 1, 1 << 43, 9],
                ['worker-1', 'bad', 2, 0, 100, 150, 60],
                ['worker', 'h:1', 2, 0, 1, 1_000_000, 60],
                ['candidate-9', 'h:1', 2, 0, 1, 1_000_000, 60],
                ['publication-forwarder-0123456789ab-2', 'h:2', 2, 0, 1, 1_000_000, 1],
                ['gc', 'h:3', 2, 0, 0, 1_000_000, 1],
                ['unknown', 'h:4', 2, 0, 1, 2, 1],
                ['', '', 0, 0, 0, 0, 0],
                ["worker-1\0", "h:1\0", 2, 0, 100, 150, 60],
            ],
            'article' => [
                ['a.bin', '<p1@x>', 'deadbeef', 1, 2, 1, 10, 20],
                ['a.bin', '<p2@x>', 'DEADBEEF', 2, 2, 11, 20, 20],
                ['a.bin', '<p2@x>', '0', 2, 2, 11, 20, 20],
                ['a.bin', '<p1@x>', '1', 1, 0, 1, 20, 20],
                ['a.bin', 'p@x', 'f', 1, 0, 1, 20, 20],
                ['a.bin', '<<p@x>>', 'f', 3, 2, 1, 20, 20],
                ['a.bin', '<>', 'f', 1, 1, 1, 1, 1],
                ['a.bin', '@xy', 'f', 1, 1, 1, 1, 1],
                ['a.bin', 'xy@', 'f', 1, 1, 1, 1, 1],
                ['a.bin', 'x y@z', 'f', 1, 1, 1, 1, 1],
                ['a.bin', 'x<y@z', 'f', 1, 1, 1, 1, 1],
                ['a.bin', str_repeat('m', 248).'@z', 'f', 1, 1, 1, 1, 1],
                ['a.bin', str_repeat('m', 249).'@z', 'f', 1, 1, 1, 1, 1],
                ['a.bin', '<p@x>', '', 1, 1, 1, 1, 1],
                ['a.bin', '<p@x>', '123456789', 1, 1, 1, 1, 1],
                ['a.bin', '<p@x>', 'xyz', 1, 1, 1, 1, 1],
                ['a.bin', '<p@x>', 'f', 0, 1, 1, 1, 1],
                ['a.bin', '<p@x>', 'f', 1, -1, 1, 1, 1],
                ['a.bin', '<p@x>', 'f', 1, 1, 2, 1, 1],
                ['a.bin', '<p@x>', 'f', 1, 1, 1, 2, 1],
                ['a.bin', '<p@x>', 'f', 999_999_999_999_999_999, 999_999_999_999_999_999, 1, 999_999_999_999_999_999, 999_999_999_999_999_999],
                ['a.bin', '<p@x>', 'f', 1_000_000_000_000_000_000, 0, 1, 1, 1],
                ['a.bin', '<p@x>', 'f', PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX],
                ['', '<p@x>', 'f', 1, 1, 1, 1, 1],
                ['.hidden', '<p@x>', 'f', 1, 1, 1, 1, 1],
                ['-flag', '<p@x>', 'f', 1, 1, 1, 1, 1],
                ['a/b', '<p@x>', 'f', 1, 1, 1, 1, 1],
                ['a\\b', '<p@x>', 'f', 1, 1, 1, 1, 1],
                ["a\x7fb", '<p@x>', 'f', 1, 1, 1, 1, 1],
                ["a\xc3", '<p@x>', 'f', 1, 1, 1, 1, 1],
                ["a\xed\xa0\x80", '<p@x>', 'f', 1, 1, 1, 1, 1],
                ["a\xc0\xaf", '<p@x>', 'f', 1, 1, 1, 1, 1],
                ["a\xf4\x90\x80\x80", '<p@x>', 'f', 1, 1, 1, 1, 1],
                ['日本語.mkv', '<p@x>', 'f', 1, 1, 1, 1, 1],
                [str_repeat('n', 200), '<p@x>', 'f', 1, 1, 1, 1, 1],
                [str_repeat('n', 201), '<p@x>', 'f', 1, 1, 1, 1, 1],
            ],
            'nzb' => [
                ['s', '<a@b>', 1, 1, 1],
                ['s', '<a@b>', 1, 2, 67_108_864],
                ['s', '<a@b>', 1, 2, 67_108_865],
                ['s', '<a@b>', 1, 1_000_000, 5],
                ['s', '<a@b>', 1, 1_000_001, 5],
                ['s', '<a@b>', 1, 1_000_002, 5],
                ['s', '<a@b>', 0, 1, 5],
                ['s', '<a@b>', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['s', '<a@b>', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                ['t&<>"', "<a&b<c>\r>", 3, 1, 9],
                ["t\t\n\r", '<x>', 3, 2, 9],
                ["t\x01", '<x>', 3, 3, 9],
                ["t\x7f", "<x\x0b>", 3, 4, 9],
                ["t\xff", "<x\xc3>", 3, 5, 9],
                ['', '', 3, 1, 9],
                ['', '<<>>', 3, 2, 9],
                ['Café', '<é@文>', 3, 1, 9],
                ["\0", "\0", 1, 1, 1],
            ],
            'publisher' => [
                ['Name', 'h', 'h', 't', 't', '2026-01-01 00:00:01', '2026-01-01 00:00:00', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', '2025-12-31 23:59:59', '2026-01-01 00:00:00', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', '', '', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', '10', '9', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', '9', '10', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'H', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 'T', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 2, 2040, 10, 1, 0, 1, 1, 10_000],
                ['', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ['   ', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('n', 255), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('n', 256), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ["N\x7f", 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                ["N\xc3", 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('aF', 8), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('aF', 16), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 10, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('aF', 20), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 20, 10, 1, 0, 1, 1, 10_000],
                [str_repeat('aF', 32), 'h', 'h', 't', 't', 'z', 'a', 1, 1, 1999, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2000, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 999, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 10_000, 10, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 0, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 11, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 9, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, PHP_INT_MAX, PHP_INT_MIN, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, PHP_INT_MAX, 1, 0, 1, 1, 10_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 0, 0, 0],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 9, 10, 9_000],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 9, 10, 9_001],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, 50, 10, PHP_INT_MAX],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN],
                ['Name', 'h', 'h', 't', 't', 'z', 'a', 1, 1, 2040, 10, 1, 0, PHP_INT_MIN, 3, 1],
                ["\0", "\0", "\0", "\0", "\0", "\0", "\0", 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ],
            default => [],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w59_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertNotSame('', $key);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        self::assertSame($words, $reflection->getConstant('W59_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W59_TRAILER_WORDS'));
        foreach (['usesW59RustFfi', 'withW59RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w59RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW59RustFfi());
        self::assertFalse($class::withW59RustFfi(false, static fn (): bool => $class::usesW59RustFfi()));
        self::assertTrue($class::withW59RustFfi(true, static fn (): bool => $class::usesW59RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW59RustFfi(), 'withW59RustFfi restores the default');

        $nested = $class::withW59RustFfi(false, static fn (): array => [
            $class::usesW59RustFfi(),
            $class::withW59RustFfi(true, static fn (): bool => $class::usesW59RustFfi()),
            $class::usesW59RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW59RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW59RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        self::assertTrue((new ReflectionClass(VerifiedArticle::class))->isReadOnly());
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w59', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW59RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        VerifiedArticle::withW59RustFfi(true, static function (): void {
            self::assertTrue(VerifiedArticle::usesW59RustFfi());
            foreach ([VerificationLock::class, VerificationWorkerLocks::class, VerifiedNzbWriter::class, VerifiedReleasePublisher::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W59_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW59RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $before = [VerificationLock::usesRustFfi(), VerificationWorkerLocks::usesRustFfi()];
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW59RustFfi($enabled, static function () use ($before): void {
                    self::assertSame($before, [VerificationLock::usesRustFfi(), VerificationWorkerLocks::usesRustFfi()]);
                    self::assertSame(['name' => 'gc', 'timeout_seconds' => 0], VerificationLock::normalizeLockSpecPhp('gc', -5));
                    self::assertSame(80.0, VerifiedReleasePublisher::completionPercentagePhp(4, 5));
                });
            }
        }
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
        self::assertIsArray($first);
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W59', $e->getMessage());
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

    public function test_hand_checked_rows(): void
    {
        $token = str_repeat('f', 32);
        $lock = VerificationLock::lockStateRowBatchPhp([
            ['worker-3', 'h:1', $token, 1000, 2000, 1900, 4, 4],
            ['bad name', 'h:1', $token, 0, 5, 0, 4, 4],
        ]);
        self::assertSame([1 | 2 << 5 | 3 << 12, 3, 1, 100, 1, 8], \array_slice($lock, 0, 6));
        self::assertSame(4, $lock[6] & 7);
        self::assertSame([0, 1, 0, 0, 1, 1, 5], \array_slice($lock, 12, 7));

        $worker = VerificationWorkerLocks::workerLockRowBatchPhp([
            ['worker-1', 'h:9', 2, 0, 100, 150, 60],
            ['worker-1', 'h:9', 2, 0, 100, 1000, 60],
        ]);
        self::assertSame([2 | 1 << 5 | 1 << 6, 1, 9, 50, 1 << 16 | 2, 100], \array_slice($worker, 0, 6));
        self::assertSame(2 | 1 << 3 | 1 << 5 | 1 << 6 | 1 << 7 | 1 << 8, $worker[6]);
        self::assertSame([2, 2, 1, 1, 0, 1, 100], \array_slice($worker, 12));

        $article = VerifiedArticle::articleRowBatchPhp([
            ['a.bin', '<p1@x>', 'ABC', 1, 2, 1, 10, 20],
            ['a.bin', '<p2@x>', '1', 2, 2, 11, 20, 20],
        ]);
        self::assertSame([0, 0, 10, 0xABC, 1 << 32 | 4, 1 | 8], \array_slice($article, 0, 6));
        self::assertSame([0, 10, 10, 1, 1 << 32 | 4, 1 | 8 | 16], \array_slice($article, 6, 6));
        self::assertSame([2, 0, 20, 2, 2, 2, 1], \array_slice($article, 12, 7));

        $nzb = VerifiedNzbWriter::segmentPlanRowBatchPhp([['s&t', '<a@b>', 2, 1, 500], ['s&t', 'c@d', 2, 3, 700]]);
        self::assertSame([1023 & ~8, 0, 45, 13, 3, 500], \array_slice($nzb, 0, 6));
        self::assertSame(1 | 2 | 4 | 16 | 32 | 512, $nzb[6]);
        self::assertSame([2, 1, 1200, 0, 1, 45 + 13 + 64 + 45], \array_slice($nzb, 12, 6));
        // The segment line is exactly what documentPhp() writes for the segment.
        self::assertSame(45, \strlen('<segment bytes="500" number="1">a@b</segment>'));

        $publication = VerifiedReleasePublisher::publicationPlanRowBatchPhp([
            ['Some.Release', 'h', 'h', 't', 't', '2026-10-26 04:00:00', '2026-10-26 03:00:00', 3, 3, 2040, 100, 1, 0, 9, 10, 9000],
        ]);
        self::assertSame([0, 31, 0, 9000, 1, 12, 165], \array_slice($publication, 0, 7));
        self::assertSame([1, 0, 0, 0, 0, 0, 100], \array_slice($publication, 7, 7));
        // The guard agrees with publicationGuardPhp() on the same claim.
        self::assertTrue(VerifiedReleasePublisher::publicationGuardPhp(
            ['version' => 3, 'manifest_hash' => 'h', 'lease_token' => 't', 'lease_until' => '2026-10-26 04:00:00'],
            ['version' => 3, 'manifest_hash' => 'h', 'lease_token' => 't'], '2026-10-26 03:00:00'));
    }

    public function test_slot_ranges_follow_the_slot_formula(): void
    {
        foreach ([[2, 0], [64, 0], [8, 250], [100, 300], [0, -4], [3, 17], [PHP_INT_MAX, PHP_INT_MIN]] as [$configured, $offset]) {
            // slotsPhp(), firstSlotPhp() and lastSlotPhp() for the same configuration.
            $slots = max(1, min(VerificationWorkerLocks::MAX_SLOTS, $configured));
            $first = max(0, min(VerificationWorkerLocks::MAX_SLOT_NUMBER - $slots, $offset)) + 1;
            $row = VerificationWorkerLocks::workerLockRowBatchPhp([['worker-1', 'h:1', $configured, $offset, 1, 1, 1]]);
            self::assertSame($first << 16 | ($first + $slots - 1), $row[4]);
        }
    }

    public function test_article_rows_follow_the_framing_rule(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $names = ['a.bin', '.a', '-a', 'a/b', 'a\\b', "a\x1f", "a\x7f", "a\xc3", "a\xed\xa0\x80", str_repeat('n', 200), str_repeat('n', 201), '日本.mkv'];
        for ($i = 0; $i < 200; $i++) {
            $names[] = self::mutate($random, 'Some.File.part0'.$i.'.rar');
        }
        $checked = 0;
        foreach ($names as $name) {
            foreach ([['1', '2', '00000abc'], ['3', '2', '1'], ['0', '2', '1'], ['1', '', '1'], ['1', '2', '']] as [$part, $total, $crc]) {
                $raw = '=ybegin part='.$part.($total === '' ? '' : ' total='.$total)." line=128 size=10 name={$name}\r\n=ypart begin=1 end=10\r\n"
                    ."0123456789\r\n=yend size=10 part={$part}".($crc === '' ? '' : ' pcrc32='.$crc)."\r\n";
                $frame = ArticleFrame::parsePhp($raw);
                if ($frame === null || $frame->header['name'] !== $name) {
                    continue;
                }
                $status = match (VerifiedArticle::framingRejectionPhp($frame)) {
                    null => 0,
                    'Unsafe article filename.' => 1,
                    'Invalid article dimensions.' => 2,
                    'Article part number exceeds its declared total.' => 3,
                    default => 4,
                };
                $row = VerifiedArticle::articleRowBatchPhp([[$name, '<p@x>', $crc, (int) $part, $total === '' ? 0 : (int) $total, 1, 10, 10]]);
                self::assertSame($status, $row[0], json_encode([$name, $part, $total, $crc], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
                $checked++;
            }
        }
        self::assertGreaterThan(500, $checked);
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W594_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W594_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W594_BENCH_ROUNDS') ?: 200));
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
            if ($class::W59_RUST_FFI_DEFAULT && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2);
            }
            if (min($ratios) < 1.5) {
                self::assertFalse($class::usesW59RustFfi(), $key.' must retain PHP by default below 1.5x');
            }
        }
        fwrite(STDERR, "\nW59.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
