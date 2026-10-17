<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BinariesDateTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        ConfigurationTestBuilder::installDefaults();
        $this->travelTo(new \DateTimeImmutable('2026-10-14 12:00:00 UTC'));
    }

    #[DataProvider('articleRanges')]
    public function test_date_search_keeps_large_article_numbers_exact(int $first, int $last, int $expected): void
    {
        $nntp = new DateTargetNntpFixture($first, $last);
        $service = new BinariesService(new BinariesConfig(echoCli: true), nntp: $nntp);
        $this->assertSame((string) $expected, $service->daytopost(3, $nntp->groupData()));
        $this->assertLessThanOrEqual(66, count($nntp->requests));
        foreach ($nntp->requests as $number) {
            $this->assertGreaterThanOrEqual($first, $number);
            $this->assertLessThanOrEqual($last, $number);
        }
    }

    public static function articleRanges(): array
    {
        return ['ordinary' => [100, 1100, 800],
            'observed scale' => [160000000000, 160000001000, 160000000700],
            'above float precision' => [9007199254740992, 9007199254741992, 9007199254741692],
            'near signed maximum' => [PHP_INT_MAX - 1000, PHP_INT_MAX, PHP_INT_MAX - 300]];
    }

    public function test_a_missing_midpoint_continues_from_the_nearest_existing_article(): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number < 600 || $number > 640);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->assertSame('800', $service->daytopost(3, $nntp->groupData()));
        $this->assertSame([100, 1100, 600, '601-700'], array_slice($nntp->requests, 0, 4));
        $this->assertNotContains(600, $nntp->dated, 'A missing number never receives a neighbor date.');
    }

    public function test_watermarks_that_name_no_article_use_the_nearest_existing_bounds(): void
    {
        // The reported low and high watermarks are gaps, as servers commonly report.
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number >= 150 && $number <= 1080);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->assertSame('800', $service->daytopost(3, $nntp->groupData()));
        $this->assertSame([100, '101-200', 1100, '1000-1099'], array_slice($nntp->requests, 0, 4));
    }

    public function test_a_target_older_than_the_oldest_existing_article_returns_that_article(): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number >= 950);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->assertSame('950', $service->daytopost(3, $nntp->groupData()));
    }

    /** @return array<string, array{int, string}> */
    public static function gapsBeforeTheUpperBound(): array
    {
        return ['gap after 610' => [610, '611'], 'gap after 609 converges on adjacent bounds' => [609, '610']];
    }

    #[DataProvider('gapsBeforeTheUpperBound')]
    public function test_a_gap_up_to_the_upper_bound_narrows_the_search_below_it(int $lastBeforeGap, string $expected): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number <= $lastBeforeGap || $number === 1100);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->assertSame($expected, $service->daytopost(3, $nntp->groupData()));
        $this->assertLessThan(64 * 4, count($nntp->requests));
    }

    public function test_an_unconfirmed_missing_watermark_defers_instead_of_using_a_neighbor(): void
    {
        // A protocol error is not a confirmed absence, even when the following range would succeed.
        $nntp = new DateTargetNntpFixture(100, 1100, singleError: static fn (int $number): ?int => $number === 100 ? 500 : null);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        try {
            $service->daytopost(3, $nntp->groupData());
            $this->fail('An unconfirmed missing article must defer.');
        } catch (\RuntimeException) {
            $this->assertSame([100], $nntp->requests);
        }
    }

    public function test_the_nearest_existing_article_with_an_unknown_date_defers(): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number !== 100,
            badDate: static fn (int $number): bool => $number === 101);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->expectException(\RuntimeException::class);
        $service->daytopost(3, $nntp->groupData());
    }

    /** @return array<string, array{\Closure(int): bool}> */
    public static function undeterminedGaps(): array
    {
        return ['no article at all' => [static fn (int $number): bool => false],
            'gap wider than the bounded windows' => [static fn (int $number): bool => $number === 20000]];
    }

    #[DataProvider('undeterminedGaps')]
    public function test_a_gap_the_bounded_windows_cannot_resolve_defers(\Closure $exists): void
    {
        $nntp = new DateTargetNntpFixture(100, 20000, exists: $exists);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->expectException(\RuntimeException::class);
        $service->daytopost(3, $nntp->groupData());
    }

    public function test_an_overview_range_error_other_than_no_article_defers(): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, exists: static fn (int $number): bool => $number !== 100, rangeError: 500);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->expectException(\RuntimeException::class);
        $service->daytopost(3, $nntp->groupData());
    }

    #[DataProvider('invalidProbeResponses')]
    public function test_a_probe_requires_the_exact_requested_number_and_a_parseable_date(mixed $response): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, static fn (): mixed => $response);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        $this->expectException(\RuntimeException::class);
        $service->daytopost(3, $nntp->groupData());
    }

    public static function invalidProbeResponses(): array
    {
        return ['protocol error' => [false],
            'missing number' => [[['Date' => '2026-10-04 12:00:00 UTC']]],
            'neighbor number outside every range' => [[['Number' => '99', 'Date' => '2026-10-04 12:00:00 UTC']]],
            'malformed number' => [[['Number' => [], 'Date' => '2026-10-04 12:00:00 UTC']]],
            'invalid date' => [[['Number' => '100', 'Date' => 'not-a-date']]],
            'malformed response' => ['invalid']];
    }

    public function test_adjacent_bounds_do_not_require_a_midpoint_date_for_diagnostics(): void
    {
        $nntp = new DateTargetNntpFixture(100, 101, static function (int $number, array $header): array {
            $header['Date'] = $number === 100 ? '2026-10-10 12:00:00 UTC' : '2026-10-12 12:00:00 UTC';

            return [$header];
        });
        $service = new BinariesService(new BinariesConfig(echoCli: true), nntp: $nntp);
        $this->assertSame('101', $service->daytopost(3, $nntp->groupData()));
    }

    #[DataProvider('invalidBounds')]
    public function test_invalid_group_bounds_defer_without_probing(mixed $first, mixed $last): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        try {
            $service->daytopost(3, ['group' => 'alt.fixture', 'first' => $first, 'last' => $last]);
            $this->fail('Invalid bounds must defer before target probing.');
        } catch (\RuntimeException) {
            $this->assertSame([], $nntp->requests);
        }
    }

    public static function invalidBounds(): array
    {
        return ['reversed' => [1100, 100], 'empty group' => [0, 0], 'negative' => [-1, 100],
            'noninteger' => ['1.5', 100], 'overflow' => ['9223372036854775808', '9223372036854775809']];
    }
}

final class DateTargetNntpFixture extends NNTPService
{
    /** @var list<int|string> single-article probes as integers, overview ranges as "low-high" */
    public array $requests = [];

    /** @var list<int> article numbers whose dates were returned */
    public array $dated = [];

    private bool $selected = false;

    public function __construct(private readonly int $first, private readonly int $last, private readonly ?\Closure $response = null,
        private readonly ?\Closure $exists = null, private readonly ?int $rangeError = null,
        private readonly ?\Closure $singleError = null, private readonly ?\Closure $badDate = null) {}

    public function groupData(): array
    {
        return ['group' => 'alt.fixture', 'first' => (string) $this->first, 'last' => (string) $this->last];
    }

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        if ($group !== 'alt.fixture') {
            throw new \LogicException('Wrong fixture group.');
        }
        $this->selected = true;

        return $this->groupData();
    }

    public function getXOVER(string $range): mixed
    {
        if (! $this->selected) {
            throw new \RuntimeException('Expected a selected-group probe.');
        }
        if (preg_match('/\A(\d+)-(\d+)\z/', $range, $bounds) === 1) {
            $this->requests[] = $range;
            if ($this->response !== null) {
                return ($this->response)((int) $bounds[1], $this->header((int) $bounds[1]));
            }
            if ($this->rangeError !== null) {
                return $this->error($this->rangeError);
            }
            $rows = [];
            for ($number = (int) $bounds[1]; $number <= (int) $bounds[2]; $number++) {
                if ($this->exists === null || ($this->exists)($number)) {
                    $rows[] = $this->header($number);
                    $this->dated[] = $number;
                }
            }

            return $rows === [] ? $this->error(423) : $rows;
        }
        if (! ctype_digit($range)) {
            throw new \RuntimeException('Expected a selected-group single-article probe.');
        }
        $number = (int) $range;
        $this->requests[] = $number;
        if ($this->response !== null) {
            return ($this->response)($number, $this->header($number));
        }
        if ($this->singleError !== null && ($code = ($this->singleError)($number)) !== null) {
            return $this->error($code);
        }
        if ($this->exists !== null && ! ($this->exists)($number)) {
            return $this->error(420);
        }
        $this->dated[] = $number;

        return [$this->header($number)];
    }

    /** @return array<string, string> */
    private function header(int $number): array
    {
        return ['Number' => (string) $number, 'Subject' => 'date fixture', 'From' => 'fixture@example.invalid',
            'Date' => $this->badDate !== null && ($this->badDate)($number) ? 'not-a-date' : gmdate('r', strtotime('2026-10-04 12:00:00 UTC') + ($number - $this->first) * 864),
            'Message-ID' => '<'.$number.'@example.invalid>', 'References' => '', 'Bytes' => '1', 'Lines' => '1', 'Xref' => 'fixture alt.fixture:'.$number];
    }

    private function error(int $code): object
    {
        return $this->throwError('fixture overview error', $code);
    }
}
