<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Console\Commands\PredbImportFeed;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class PredatabaseRetryCoverageTest extends TestCase
{
    private const ENDPOINT = 'https://predataba.se/api/search';

    #[Test]
    #[DataProvider('throttleStatuses')]
    public function cli_retries_keep_prior_chunks_and_the_next_page_contiguous(int $status): void
    {
        $this->fakeThrottleAt(30, $status);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20, apiKey: 'test-only');

        $first = $source->fetch(1);
        $second = $this->fetchWithCliBackoff($source, 2);
        $third = $source->fetch(3);

        $this->assertSame($this->titles(0, 19), array_column($first, 'title'));
        $this->assertSame($this->titles(20, 39), array_column($second, 'title'));
        $this->assertSame($this->titles(40, 59), array_column($third, 'title'));
        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-only'));
    }

    /** @return array<string, array{int}> */
    public static function throttleStatuses(): array
    {
        return ['HTTP 429' => [429], '403 mapped to rate-limit envelope' => [403]];
    }

    #[Test]
    public function retrying_the_first_logical_page_keeps_successful_chunks(): void
    {
        $this->fakeThrottleAt(10, 429);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20, apiKey: 'test-only');

        $entries = $this->fetchWithCliBackoff($source, 1);

        $this->assertSame($this->titles(0, 19), array_column($entries, 'title'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function repeating_a_completed_page_returns_its_same_rows_without_extra_requests(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::ENDPOINT.'*' => Http::sequence()->push($this->page(0))->push($this->page(10))]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20, apiKey: 'test-only');

        $first = $source->fetch(1);
        $repeated = $source->fetch(1);

        $this->assertSame(array_column($first, 'title'), array_column($repeated, 'title'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function anonymous_throttle_attempts_count_toward_the_five_request_budget(): void
    {
        $this->fakeThrottleAt(30, 429);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20);

        $first = $source->fetch(1);
        $second = $this->fetchWithCliBackoff($source, 2);
        $third = $source->fetch(3);

        $this->assertSame($this->titles(0, 19), array_column($first, 'title'));
        $this->assertSame($this->titles(20, 39), array_column($second, 'title'));
        $this->assertSame([], $third);
        Http::assertSentCount(PredatabaseSource::ANONYMOUS_REQUEST_LIMIT);
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
    }

    #[Test]
    public function anonymous_retry_at_the_budget_boundary_returns_buffered_rows_without_an_extra_call(): void
    {
        $this->fakeThrottleAt(40, 403);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 100);

        $entries = $this->fetchWithCliBackoff($source, 1);

        $this->assertSame($this->titles(0, 39), array_column($entries, 'title'));
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(PredatabaseSource::ANONYMOUS_REQUEST_LIMIT);
    }

    #[Test]
    public function an_overlapping_publisher_row_is_not_multiplied_by_retry(): void
    {
        $this->fakeThrottleAt(30, 429, overlap: true);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20, apiKey: 'test-only');
        $source->fetch(1);

        $entries = $this->fetchWithCliBackoff($source, 2);

        $expected = $this->titles(20, 39);
        $expected[10] = 'Exact.Release.29-GRP';
        $this->assertSame($expected, array_column($entries, 'title'));
        $this->assertSame(2, count(array_filter($entries, static fn (PredbFeedEntry $entry): bool => $entry->title === 'Exact.Release.29-GRP')));
        Http::assertSentCount(5);
    }

    private function fakeThrottleAt(int $throttleOffset, int $status, bool $overlap = false): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $throttled = false;
        Http::fake(function (Request $request) use ($throttleOffset, $status, $overlap, &$throttled) {
            $this->assertSame(self::ENDPOINT, strtok($request->url(), '?'));
            $offset = (int) ($request->data()['page_id'] ?? 0);
            if ($offset === $throttleOffset && ! $throttled) {
                $throttled = true;

                return Http::response(['message' => 'rate limit'], $status, ['Retry-After' => '0']);
            }
            $page = $this->page($offset);
            if ($overlap && $offset === 30) {
                $page['results'][0]['rlsname'] = 'Exact.Release.29-GRP';
            }

            return Http::response($page);
        });
    }

    /** @return array{results: list<array{rlsname: string, ctime: int}>, next_page_id: string} */
    private function page(int $offset): array
    {
        $rows = [];
        for ($index = $offset; $index < $offset + 10; $index++) {
            $rows[] = ['rlsname' => "Exact.Release.{$index}-GRP", 'ctime' => 1791589642 - $index];
        }

        return ['results' => $rows, 'next_page_id' => (string) ($offset + 10)];
    }

    /** @return list<string> */
    private function titles(int $first, int $last): array
    {
        return array_map(static fn (int $index): string => "Exact.Release.{$index}-GRP", range($first, $last));
    }

    /** @return list<PredbFeedEntry> */
    private function fetchWithCliBackoff(PredatabaseSource $source, int $page): array
    {
        $originalContainer = Container::getInstance();
        $originalFacades = Facade::getFacadeApplication();
        $container = new Application;
        $container->instance('config', new Repository(['predb_feeds' => [
            'rate_limit_retries' => 1,
            'rate_limit_wait_seconds' => 0,
            'rate_limit_max_wait_seconds' => 0,
        ]]));
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        try {
            return (new ReflectionMethod(PredbImportFeed::class, 'fetchWithBackoff'))->invoke(new PredbImportFeed, $source, $page);
        } finally {
            Facade::clearResolvedInstance('log');
            Facade::setFacadeApplication($originalFacades);
            Container::setInstance($originalContainer);
        }
    }
}
