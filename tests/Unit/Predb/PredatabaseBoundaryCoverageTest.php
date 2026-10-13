<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Console\Commands\PredbImportFeed;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class PredatabaseBoundaryCoverageTest extends TestCase
{
    private const ENDPOINT = 'https://predataba.se/api/search';

    #[Test]
    public function anonymous_transport_retries_count_as_wire_attempts(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $failed = false;
        Http::fake(function (Request $request) use (&$failed) {
            if (! $failed) {
                $failed = true;

                return Http::response(['message' => 'temporary failure'], 500);
            }

            return Http::response($this->page((int) ($request->data()['page_id'] ?? 0)));
        });
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 100);

        $entries = $source->fetch(1);

        $this->assertSame($this->titles(0, 39), array_column($entries, 'title'));
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(5);
    }

    #[Test]
    public function the_last_anonymous_wire_attempt_cannot_start_an_internal_retry(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) {
            $offset = (int) ($request->data()['page_id'] ?? 0);

            return $offset === 40 ? Http::response(['message' => 'temporary failure'], 500) : Http::response($this->page($offset));
        });
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 100);

        try {
            $source->fetch(1);
            $this->fail('A terminal server error must propagate.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        Http::assertSentCount(5);
    }

    #[Test]
    public function keyed_clients_keep_transient_retries_without_an_anonymous_cap(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $failed = false;
        Http::fake(function (Request $request) use (&$failed) {
            if (! $failed) {
                $failed = true;

                return Http::response(['message' => 'temporary failure'], 500);
            }

            return Http::response($this->page((int) ($request->data()['page_id'] ?? 0)));
        });

        $entries = (new PredatabaseSource(self::ENDPOINT, pageSize: 100, apiKey: 'test-only'))->fetch(1);

        $this->assertSame($this->titles(0, 99), array_column($entries, 'title'));
        Http::assertSentCount(11);
    }

    #[Test]
    public function short_nonterminal_responses_preserve_the_next_cursor(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $first = $this->page(0);
        $first['results'] = array_slice($first['results'], 0, 3);
        Http::fake([self::ENDPOINT.'*' => Http::sequence()->push($first)->push($this->page(10))]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');

        $firstEntries = $source->fetch(1);
        $secondEntries = $source->fetch(2);

        $this->assertSame($this->titles(0, 2), array_column($firstEntries, 'title'));
        $this->assertSame($this->titles(10, 19), array_column($secondEntries, 'title'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function an_empty_nonterminal_response_does_not_signal_end_of_history(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::ENDPOINT.'*' => Http::sequence()->push(['results' => [], 'next_page_id' => '10'])->push($this->page(10))]);

        $entries = (new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only'))->fetch(1);

        $this->assertSame($this->titles(10, 19), array_column($entries, 'title'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function repeated_cursors_fail_before_a_logical_page_can_repeat(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) {
            $offset = (int) ($request->data()['page_id'] ?? 0);
            $page = $this->page($offset);
            if ($offset === 10) {
                $page['next_page_id'] = '10';
            }

            return Http::response($page);
        });
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');
        $source->fetch(1);

        $this->expectException(RuntimeException::class);
        $source->fetch(2);
    }

    #[Test]
    public function a_cursor_cycle_across_multiple_pages_is_rejected(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $third = $this->page(20);
        $third['next_page_id'] = '10';
        Http::fake([self::ENDPOINT.'*' => Http::sequence()->push($this->page(0))->push($this->page(10))->push($third)]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');
        $source->fetch(1);
        $source->fetch(2);

        $this->expectException(RuntimeException::class);
        $source->fetch(3);
    }

    #[Test]
    public function reversed_chronology_fails_the_actual_cli_before_it_claims_a_cutoff(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $page = $this->page(0);
        $page['results'][0]['ctime'] = 1788220800;
        Http::fake([self::ENDPOINT.'*' => Http::response($page)]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');
        $originalContainer = Container::getInstance();
        $originalFacades = Facade::getFacadeApplication();
        $app = new Application;
        $app->instance('config', new Repository(['predb_feeds' => ['single_page_sources' => [], 'request_delay_ms' => 0]]));
        $app->instance('log', new NullLogger);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstance('log');
        $factory = $this->createMock(PredbFeedSourceFactory::class);
        $factory->expects($this->once())->method('make')->willReturn([$source]);
        $importer = $this->createMock(PredbFeedImporter::class);
        $importer->expects($this->never())->method('import');
        $command = new class extends PredbImportFeed
        {
            /** @var array<int, array<int, int|string>> */
            public array $rows = [];

            /** @return list<string>|bool|int|string|null */
            public function option($key = null)
            {
                return ['source' => ['predatabase'], 'since' => '2026-10-01', 'max-pages' => 2, 'dry-run' => true, 'quiet' => false][$key] ?? null;
            }

            /**
             * @param  array<string>  $headers
             * @param  array<int, array<int, int|string>>|Arrayable<int, array<int, int|string>>  $rows
             * @param  array<string>  $columnStyles
             */
            public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
            {
                $this->rows = $rows instanceof Arrayable ? $rows->toArray() : $rows;
            }

            public function comment($string, $verbosity = null) {}
        };

        try {
            $status = $command->handle($factory, $importer);

            $this->assertSame(1, $status);
            $this->assertStringContainsString('failed:', (string) $command->rows[0][4]);
        } finally {
            Facade::clearResolvedInstance('log');
            Facade::setFacadeApplication($originalFacades);
            Container::setInstance($originalContainer);
        }
    }

    #[Test]
    public function discarded_names_cannot_hide_reversed_page_dates(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $page = $this->page(0);
        $page['results'][0]['ctime'] = 1788220800;
        foreach (array_slice(array_keys($page['results']), 1) as $index) {
            $page['results'][$index]['rlsname'] = '';
        }
        Http::fake([self::ENDPOINT.'*' => Http::response($page)]);

        $this->expectException(RuntimeException::class);
        (new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only'))->fetch(1);
    }

    #[Test]
    public function dates_cannot_reverse_between_successive_chunks(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $second = $this->page(10);
        $second['results'][0]['ctime'] = 1791589742;
        Http::fake([self::ENDPOINT.'*' => Http::sequence()->push($this->page(0))->push($second)]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');
        $source->fetch(1);

        $this->expectException(RuntimeException::class);
        $source->fetch(2);
    }

    #[Test]
    public function anonymous_redirect_sends_cannot_bypass_the_wire_budget(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) {
            $hop = (int) ($request->data()['hop'] ?? 0);

            return Http::response('', 302, ['Location' => self::ENDPOINT.'?hop='.($hop + 1)]);
        });

        try {
            (new PredatabaseSource(self::ENDPOINT))->fetch(1);
            $this->fail('A redirect loop must fail.');
        } catch (RuntimeException|ConnectionException) {
        }

        Http::assertSentCount(5);
    }

    #[Test]
    public function malformed_nonstring_cursors_cannot_be_mistaken_for_end_of_history(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $page = $this->page(0);
        $page['next_page_id'] = 10;
        Http::fake([self::ENDPOINT.'*' => Http::response($page)]);

        $this->expectException(RuntimeException::class);
        (new PredatabaseSource(self::ENDPOINT))->fetch(1);
    }

    #[Test]
    public function terminal_short_pages_still_end_the_catalogue(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $page = $this->page(0);
        $page['results'] = array_slice($page['results'], 0, 3);
        $page['next_page_id'] = null;
        Http::fake([self::ENDPOINT.'*' => Http::response($page)]);
        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only');

        $entries = $source->fetch(1);

        $this->assertSame($this->titles(0, 2), array_column($entries, 'title'));
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
    }

    #[Test]
    public function unknown_and_tied_dates_are_preserved_without_invented_ordering(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $page = $this->page(0);
        foreach ($page['results'] as &$row) {
            $row['ctime'] = 1791589642;
        }
        unset($row);
        unset($page['results'][1]['ctime']);
        Http::fake([self::ENDPOINT.'*' => Http::response($page)]);

        $entries = (new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only'))->fetch(1);

        $this->assertCount(10, $entries);
        $this->assertNull($entries[1]->predate);
        $this->assertSame($entries[0]->predate?->getTimestamp(), $entries[2]->predate?->getTimestamp());
    }

    #[Test]
    public function filtered_nonterminal_pages_have_a_bounded_incomplete_failure(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) {
            $offset = (int) ($request->data()['page_id'] ?? 0);

            return Http::response(['results' => [['rlsname' => '']], 'next_page_id' => (string) ($offset + 10)]);
        });

        try {
            (new PredatabaseSource(self::ENDPOINT, pageSize: 10, apiKey: 'test-only'))->fetch(1);
            $this->fail('Nonterminal filtered pages cannot silently signal complete history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }

        Http::assertSentCount(6);
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
}
