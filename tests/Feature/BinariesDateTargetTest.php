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

    public function test_an_unknown_midpoint_defers_instead_of_using_a_neighbor_date(): void
    {
        $nntp = new DateTargetNntpFixture(100, 1100, static fn (int $number, array $header): array => $number === 600 ? [] : [$header]);
        $service = new BinariesService(new BinariesConfig, nntp: $nntp);
        try {
            $service->daytopost(3, $nntp->groupData());
            $this->fail('An unknown midpoint must not admit a date-based range.');
        } catch (\RuntimeException) {
            $this->assertSame([100, 1100, 600], $nntp->requests);
        }
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
            'neighbor number' => [[['Number' => '101', 'Date' => '2026-10-04 12:00:00 UTC']]],
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
    public array $requests = [];

    private bool $selected = false;

    public function __construct(private readonly int $first, private readonly int $last, private readonly ?\Closure $response = null) {}

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
        if (! $this->selected || ! ctype_digit($range)) {
            throw new \RuntimeException('Expected a selected-group single-article probe.');
        }
        $number = (int) $range;
        $this->requests[] = $number;
        $header = ['Number' => $range, 'Subject' => 'date fixture', 'From' => 'fixture@example.invalid',
            'Date' => gmdate('r', strtotime('2026-10-04 12:00:00 UTC') + ($number - $this->first) * 864),
            'Message-ID' => '<'.$range.'@example.invalid>', 'References' => '', 'Bytes' => '1', 'Lines' => '1', 'Xref' => 'fixture alt.fixture:'.$range];

        return $this->response === null ? [$header] : ($this->response)($number, $header);
    }
}
