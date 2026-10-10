<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PredatabaseSourceTest extends TestCase
{
    private const ENDPOINT = 'https://predataba.se/api/search';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[Test]
    public function rows_are_normalised_and_unknown_values_are_left_empty(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/predb/predatabase.json')), true, flags: JSON_THROW_ON_ERROR);
        $entries = (new PredatabaseSource(self::ENDPOINT))->parseRows($fixture['results']);

        $this->assertCount(3, $entries);
        $this->assertSame('Revival_Season-This_Machine..-16BIT-WEB-FLAC-2026-MEiDUNG', $entries[0]->title);
        $this->assertSame('predataba.se', $entries[0]->source);
        $this->assertSame('FLAC-WEB', $entries[0]->category);
        $this->assertSame('274MB', $entries[0]->size);
        $this->assertSame('20', $entries[0]->files);
        $this->assertSame(1791589642, $entries[0]->predate?->getTimestamp());

        $this->assertNull($entries[2]->category);
        $this->assertNull($entries[2]->size);
        $this->assertNull($entries[2]->files);
        $this->assertNull($entries[2]->predate);
    }

    #[Test]
    public function a_page_chains_requests_through_the_cursor_and_later_pages_continue_it(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->page((int) ($request->data()['page_id'] ?? 0))));

        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 20, apiKey: 'key');

        $first = $source->fetch(1);
        $second = $source->fetch(2);

        $this->assertSame(['R0-GRP', 'R9-GRP', 'R10-GRP', 'R19-GRP'], [$first[0]->title, $first[9]->title, $first[10]->title, $first[19]->title]);
        $this->assertSame('R20-GRP', $second[0]->title);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer key'));
    }

    #[Test]
    public function anonymous_clients_stop_at_the_free_request_limit(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->page((int) ($request->data()['page_id'] ?? 0))));

        $source = new PredatabaseSource(self::ENDPOINT, pageSize: 100);

        $this->assertCount(50, $source->fetch(1));
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(PredatabaseSource::ANONYMOUS_REQUEST_LIMIT);
    }

    #[Test]
    public function forbidden_responses_are_reported_as_rate_limiting(): void
    {
        Http::fake(['predataba.se/*' => Http::response(['code' => 403, 'message' => 'rate limit exceeded'], 403)]);

        $this->expectException(FeedRateLimitedException::class);

        (new PredatabaseSource(self::ENDPOINT))->fetch(1);
    }

    #[Test]
    public function the_factory_builds_the_source_with_the_configured_key(): void
    {
        config(['predb_feeds.endpoints.predatabase' => self::ENDPOINT, 'predb_feeds.predatabase_api_key' => 'secret']);
        Http::fake(['predataba.se/*' => Http::response(['results' => [], 'next_page_id' => null])]);

        $source = app(PredbFeedSourceFactory::class)->makeOne('predatabase');
        $source->fetch(1);

        $this->assertSame('predatabase', $source->key());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
    }

    /**
     * Ten rows starting at $offset, with a cursor pointing at the next ten.
     *
     * @return array<string, mixed>
     */
    private function page(int $offset): array
    {
        $rows = [];
        for ($i = $offset; $i < $offset + PredatabaseSource::ROWS_PER_REQUEST; $i++) {
            $rows[] = ['rlsname' => "R{$i}-GRP", 'section' => 'TV', 'size' => 1, 'files' => 1, 'ctime' => 1791589642 - $i, 'status' => 0];
        }

        return ['results' => $rows, 'next_page_id' => (string) ($offset + PredatabaseSource::ROWS_PER_REQUEST)];
    }
}
