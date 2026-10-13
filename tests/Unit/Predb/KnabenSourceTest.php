<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\KnabenSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class KnabenSourceTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 3));
        $container->instance('config', new Repository);
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Log::clearResolvedInstance('log');
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        Log::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_captured_movies_preserve_eight_literal_titles_and_unknown_metadata(): void
    {
        $source = new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10);

        $entries = $source->parseJson($this->fixture());

        $this->assertSame([
            'Birds.of.Prey.And.the.Fantabulous.Emancipation.of.One.Harley.Quinn.2020.1080p.MA.WEB-DLRip.x264-ZoroSenpai_EniaHD',
            'Novocaine.2025.1080p.BluRay.x264-playHD_EniaHD',
            'Anaconda.2025.1080p.BluRay.x264-ZoroSenpai_EniaHD',
            'Gladiator.II.2024.1080p.BluRay.x264-HiP_EniaHD',
            'Tom.and.Jerry.2021.1080p.MA.WEB-DLRip.x264-ZoroSenpai_EniaHD',
            'Blue.Beetle.2023.1080p.MA.WEB-DLRip.x264-SbR_EniaHD',
            'Wonka.2023.1080p.BluRay.x264-HiDt_EniaHD',
            'Godzilla.x.Kong.The.New.Empire.2024.1080p.BluRay.x264-HiDt_EniaHD',
        ], array_map(static fn ($entry): string => $entry->title, $entries));
        $this->assertSame(['nonliteral_title' => 2], $source->rejectionCounts());
        $this->assertSame(8, $source->acceptedCount());
        foreach ($entries as $entry) {
            $this->assertSame('knaben.org', $entry->source);
            $this->assertSame('Movies / HD', $entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
        }
    }

    public function test_fetch_requests_only_one_bounded_latest_movie_browse_page(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response($this->fixture())]);
        $source = new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10, userAgent: 'NNTmux-PreDB-Importer/1.0');

        $this->assertCount(8, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && $query === ['c' => '3000000', 'o' => 'date', 'd' => 'desc', 's' => '10', 'f' => '0']
                && $request->hasHeader('User-Agent', 'NNTmux-PreDB-Importer/1.0')
                && $request->hasHeader('Accept-Encoding', 'gzip');
        });
    }

    public function test_later_page_has_no_http_request_and_api_size_is_capped(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $source = new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 999);

        $this->assertSame([], $source->fetch(2));
        Http::assertNothingSent();
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response($this->fixture())]);
        $this->assertCount(8, $source->fetch());
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 's=300&f=0'));
    }

    public function test_gzip_response_uses_the_same_literal_parser(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response(gzencode($this->fixture()), 200, ['Content-Encoding' => 'gzip'])]);

        $this->assertCount(8, (new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10))->fetch());
        Http::assertSentCount(1);
    }

    public function test_http_error_propagates_without_parsing_or_payload_requests(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response('blocked', 403)]);

        try {
            (new KnabenSource('https://api.knaben.org/v2/browse'))->fetch();
            $this->fail('A failed listing request must propagate.');
        } catch (RequestException $exception) {
            $this->assertSame(403, $exception->response->status());
            Http::assertSentCount(1);
        }
    }

    #[DataProvider('nonliteralTitles')]
    public function test_nonliteral_titles_are_skipped_without_reconstruction(string $title, string $reason): void
    {
        $source = new KnabenSource('https://api.knaben.org/v2/browse');

        $this->assertSame([], $source->parseJson($this->response(['title' => $title])));
        $this->assertSame([$reason => 1], $source->rejectionCounts());
    }

    /** @return array<string, array{string, string}> */
    public static function nonliteralTitles(): array
    {
        return [
            'leading whitespace' => [' Release.2026-GROUP', 'nonliteral_title'],
            'editorial spaces' => ['Release 2026-GROUP', 'nonliteral_title'],
            'unicode separator' => ["Release.2026\u{00a0}-GROUP", 'nonliteral_title'],
            'control' => ["Release.2026\0-GROUP", 'nonliteral_title'],
            'unicode ellipsis' => ['Release.2026-GRO…', 'truncated_title'],
            'ascii ellipsis' => ['Release.2026-GRO...', 'truncated_title'],
            'not dotted' => ['Release_2026-GROUP', 'nonliteral_title'],
            'only a dot' => ['.', 'nonliteral_title'],
            'empty dotted token' => ['Release..2026-GROUP', 'nonliteral_title'],
            'overlong' => [str_repeat('x', 256).'.2026-GROUP', 'overlength_title'],
        ];
    }

    public function test_only_publisher_movie_categories_are_used_and_dates_are_not_inferred(): void
    {
        $source = new KnabenSource('https://api.knaben.org/v2/browse');
        $json = $this->response(['category' => 'Movies / Custom', 'date' => '2026-10-11T07:57:00+00:00']);

        $entry = $source->parseJson($json)[0];

        $this->assertSame('Movies / Custom', $entry->category);
        $this->assertNull($entry->listedAt);
        $this->assertNull($entry->predate);
        $this->assertSame([], $source->parseJson($this->response(['categoryId' => [2000000], 'category' => 'TV / HD'])));
        $this->assertSame(['non_movie_category' => 1], $source->rejectionCounts());
    }

    public function test_json_tokens_inside_strings_and_keys_in_distinct_objects_remain_unambiguous(): void
    {
        $document = json_decode($this->response(), false, 64, JSON_THROW_ON_ERROR);
        $document->ignored = ['punctuation' => '\"},[\\', 'objects' => [(object) ['same' => true], (object) ['same' => null]], 'number' => 2.5];
        $source = new KnabenSource('https://api.knaben.org/v2/browse');

        $entries = $source->parseJson(json_encode($document, JSON_THROW_ON_ERROR));

        $this->assertSame('Release.2026.1080p-GROUP_Uploader', $entries[0]->title);
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_or_ambiguous_responses_fail_as_a_whole(string $json): void
    {
        $this->expectException(RuntimeException::class);

        (new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 10))->parseJson($json);
    }

    /** @return array<string, array{string}> */
    public static function malformedResponses(): array
    {
        $valid = '{"hits":[],"total":{"relation":"eq","value":0}}';

        return [
            'invalid JSON' => ['{'],
            'HTML error page' => ['<html>error</html>'],
            'array envelope' => ['[]'],
            'missing hits' => ['{"total":{"relation":"eq","value":0}}'],
            'object hits' => ['{"hits":{},"total":{"relation":"eq","value":0}}'],
            'missing total' => ['{"hits":[]}'],
            'invalid total relation' => ['{"hits":[],"total":{"relation":"maybe","value":0}}'],
            'string total' => ['{"hits":[],"total":{"relation":"eq","value":"0"}}'],
            'negative total' => ['{"hits":[],"total":{"relation":"eq","value":-1}}'],
            'duplicate envelope member' => ['{"hits":[],"hits":[],"total":{"relation":"eq","value":0}}'],
            'escaped equivalent member' => ['{"hits":[],"hi\u0074s":[],"total":{"relation":"eq","value":0}}'],
            'nested duplicate ignored member' => [str_replace('"value":0', '"value":0,"ignored":{"a":1,"\u0061":2}', $valid)],
            'duplicate title member' => ['{"hits":[{"title":"A.2026-G","title":"B.2026-G"}],"total":{"relation":"eq","value":1}}'],
            'excessive JSON depth' => ['{"hits":[],"total":{"relation":"eq","value":0},"ignored":'.str_repeat('[', 65).'0'.str_repeat(']', 65).'}'],
        ];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('malformedHits')]
    public function test_malformed_hit_fields_reject_the_response(array $changes): void
    {
        $this->expectException(RuntimeException::class);

        (new KnabenSource('https://api.knaben.org/v2/browse'))->parseJson($this->response($changes));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedHits(): array
    {
        return [
            'nonstring title' => [['title' => []]],
            'empty title' => [['title' => '']],
            'scalar category IDs' => [['categoryId' => 3000000]],
            'string category ID' => [['categoryId' => ['3000000']]],
            'empty category IDs' => [['categoryId' => []]],
            'object category' => [['category' => ['Movies']]],
            'array date' => [['date' => []]],
        ];
    }

    public function test_hit_count_must_not_exceed_the_requested_page_size(): void
    {
        $this->expectException(RuntimeException::class);

        (new KnabenSource('https://api.knaben.org/v2/browse', pageSize: 9))->parseJson($this->fixture());
    }

    /** @param array<string, string> $headers */
    #[DataProvider('oversizedBodies')]
    public function test_wire_and_decoded_bodies_have_the_shared_one_mib_cap(string $body, array $headers): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response($body, 200, $headers)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('body limit');

        (new KnabenSource('https://api.knaben.org/v2/browse'))->fetch();
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function oversizedBodies(): array
    {
        $body = str_repeat('x', 1048577);

        return ['identity wire' => [$body, []], 'gzip decoded' => [gzencode($body), ['Content-Encoding' => 'gzip']]];
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/knaben-movies.json');
    }

    /** @param array<string, mixed> $changes */
    private function response(array $changes = []): string
    {
        return json_encode(['hits' => [array_replace([
            'title' => 'Release.2026.1080p-GROUP_Uploader',
            'category' => 'Movies / HD',
            'categoryId' => [3001000, 3000000],
            'date' => null,
        ], $changes)], 'total' => ['relation' => 'eq', 'value' => 1]], JSON_THROW_ON_ERROR);
    }
}
