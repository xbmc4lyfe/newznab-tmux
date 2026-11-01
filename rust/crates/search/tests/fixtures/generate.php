<?php

// Regenerates golden.json from the PHP reference. Needs rust/compose.test.yml running:
//   php rust/crates/search/tests/fixtures/generate.php ~/git.xbmc4lyfe/newznab-tmux-php-ref

declare(strict_types=1);

use App\Enums\SecondarySearchIndex;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Support\ReleaseSearchIndexDocument;
use Manticoresearch\Client;
use Manticoresearch\Response;

$root = $argv[1] ?? getenv('HOME').'/git.xbmc4lyfe/newznab-tmux-php-ref';
$here = __DIR__;
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'app.debug' => false]);

/** Records request bodies and answers with no hits. */
final class RecordingClient extends Client
{
    public array $bodies = [];

    public function search(array $params = [], $obj = false)
    {
        $this->bodies[] = $params['body'];

        return new Response(json_encode(['took' => 0, 'timed_out' => false, 'hits' => ['total' => 0, 'total_relation' => 'eq', 'hits' => []]]));
    }
}

function driverWith(array $overrides = []): ManticoreSearchDriver
{
    $config = array_replace_recursive(config('search.drivers.manticore'), $overrides);

    return new ManticoreSearchDriver($config);
}

/** JSON readers sort object keys, and field order matters, so per-field text is written as pairs. */
function ordered(mixed $phrases): mixed
{
    if (! is_array($phrases) || array_is_list($phrases)) {
        return $phrases;
    }

    return ['__fields' => array_map(static fn (string $key, mixed $value): array => [$key, $value], array_keys($phrases), $phrases)];
}

function orderedCriteria(array $criteria): array
{
    if (array_key_exists('phrases', $criteria)) {
        $criteria['phrases'] = ordered($criteria['phrases']);
    }

    return $criteria;
}

function markAvailable(): void
{
    $class = new ReflectionClass(ManticoreSearchDriver::class);
    $class->setStaticPropertyValue('availabilityCache', true);
    $class->setStaticPropertyValue('availabilityCacheTime', time() + 86400);
}

$strings = [
    'dead circus', '!circus', '-circus', 'dead !circus', 'dead -circus', '!foo !bar', '"exact phrase"',
    '!"exact phrase"', '-"exact phrase"', 'a | b', 'word*', '*word', '*word*', '(a | b) -c', '((a))', '()', '(', ')',
    'spider-man', 'WEB-DL.x265', 'S01E02.1080p.WEB-DL', 'foo_bar.baz-qux', "it's", 'a@b', 'a~b', 'a&b', 'a/b', 'a$b',
    'a=b', 'a[b]', 'back\\slash', 'unmatched"quote', '*', '', '   ', '0', '-', '!', '--', '-!', 'abc-', 'abc!', 'a--b', '**', '*-*',
    'Amélie 2001', 'Amélie.2001', "non\u{00A0}breaking", "tab\tsep", "ctrl\x0Bv", "form\x0Cfeed", 'Ünï-çødé', '東京 2020', '-1',
    'foo@bar.baz', 'poster <a@b.c> (x)', 'x|y', 'x^y', 'x?y', 'x%y', 'x<y>z', 'a.b_c-d e', '...', '._-',
];

$pure = ['prepare' => [], 'escape' => [], 'negation' => [], 'fromname' => [], 'scope' => []];
$driverClass = new ReflectionClass(ManticoreSearchDriver::class);
$fromname = $driverClass->getMethod('prepareReleaseFieldSearchQuery');
$scope = $driverClass->getMethod('scopeReleaseSearchQuery');
$fuzzy = $driverClass->getMethod('phrasesToFuzzyString');
$extract = $driverClass->getMethod('extractSuggestion');
$fuzzyDriver = driverWith();
foreach ($strings as $s) {
    $prepared = ManticoreSearchDriver::prepareUserSearchQuery($s);
    $pure['prepare'][] = [$s, $prepared];
    $pure['escape'][] = [$s, ManticoreSearchDriver::escapeString($s)];
    $pure['negation'][] = [$s, ManticoreSearchDriver::queryHasNegation($s)];
    $pure['fromname'][] = [$s, $fromname->invoke(null, 'fromname', $s)];
    $pure['fuzzy'][] = [$s, $fuzzy->invoke($fuzzyDriver, $s)];
    foreach (['', '@searchname', '@name', '@(name,searchname)', '@(title,filename)'] as $selector) {
        $pure['scope'][] = [$s, $selector, $scope->invoke(null, $s, $prepared, $selector)];
    }
}
$pure['field_expressions'] = [];
foreach ([
    [['searchname' => 'foo bar']],
    [['name' => 'a-b', 'searchname' => 'a-b']],
    [['title' => 'x', 'director' => 'y z']],
    [['title' => '0']],
    [['title' => '', 'actors' => '*']],
    [['searchname' => 'a', 'bad' => 'b'], ['searchname']],
] as $case) {
    $pure['field_expressions'][] = [ordered($case[0]), $case[1] ?? null, ManticoreSearchDriver::buildFieldSearchExpression(...$case)];
}
$long = 'The.Quite.Extraordinarily.Long.Release.Name.Of.A.Show.That.Keeps.Going.And.Going.Needle.S01E01.1080p.WEB-DL.DDP5.1.H264-GROUP.mkv';
foreach ([
    ['Show.Name.S01E01.mkv', 'show'], ['file_with_under.scores.NFO', 'file'], ['a.r01', 'a'], ['0', '0'], ['...', 'x'],
    [$long, 'needle'], [$long, 'zzz'], [$long, 'the'], [str_repeat('é', 50).'.Needle.'.str_repeat('é', 50), 'zzz'],
] as [$name, $query]) {
    if (str_contains($name, 'é')) {
        continue; // PHP cuts bytes mid-character here; the port deliberately cuts on a boundary.
    }
    $pure['suggestions'][] = [$name, $query, $extract->invoke($fuzzyDriver, $name, $query)];
}

// Request bodies, by way of a recording client.
markAvailable();
$queries = [
    'browse_defaults' => [[], 50, 0],
    'text' => [['phrases' => 'Dead Circus'], 25, 0],
    'text_offset' => [['phrases' => 'foo', 'try_fuzzy' => false], 100, 9000],
    'punctuated' => [['phrases' => 'WEB-DL.x265'], 10, 0],
    'negated' => [['phrases' => '!harry'], 10, 0],
    'terms' => [['phrases' => ['foo', 'bar']], 10, 0],
    'empty_terms' => [['phrases' => []], 10, 0],
    'fields' => [['phrases' => ['searchname' => 'a', 'name' => 'b-c', 'bogus' => 'x', 'filename' => '']], 10, 0],
    'poster' => [['phrases' => ['fromname' => 'x@y.z (Name)']], 10, 0],
    'poster_fuzzy_only' => [['phrases' => ['fromname' => 'abc'], 'fuzzy_only' => true], 10, 0],
    'media_field' => [['phrases' => ['media_video_codec' => 'h264']], 10, 0],
    'fuzzy_only' => [['phrases' => 'harry poter', 'fuzzy_only' => true], 10, 0],
    'fuzzy_punctuation' => [['phrases' => 'Amélie.2001-x', 'fuzzy_only' => true], 10, 0],
    'filters' => [[
        'category_ids' => [2000, 0, 2040], 'excluded_category_ids' => [5000, 5040], 'release_ids' => [7],
        'min_size' => 10, 'max_size' => 20, 'min_date' => 1000, 'max_date' => 2000, 'groups_id' => 3,
        'password_allow_rar' => true, 'password_status_min' => -1, 'has_media_info' => true, 'media_unique_id' => ' abc ',
        'min_video_width' => 1, 'max_video_width' => 2, 'min_video_height' => 3, 'max_video_height' => 4,
        'sort_field' => 'size', 'sort_dir' => 'ASC',
    ], 10, 0],
    'filters_more' => [[
        'category_ids' => [2000], 'release_ids' => [1, -1, 2], 'has_media_info' => false, 'media_unique_id' => '-1',
        'groups_id' => 0, 'sort_field' => 'bogus', 'sort_dir' => 'sideways',
        'ranges' => ['size' => ['min' => 1, 'max' => 2], 'grabs' => ['min' => -1], 'bogus' => ['min' => 1], 'adddate_ts' => ['max' => '9']],
        'min_id' => 5, 'max_id' => '6',
    ], 10, 0],
    'sort_id' => [['sort_field' => 'id', 'sort_dir' => 'asc'], 0, -5],
];
$requests = [];
foreach ($queries as $name => [$criteria, $limit, $offset]) {
    $driver = driverWith();
    $driver->manticoreSearch = new RecordingClient(['host' => '127.0.0.1', 'port' => 1]);
    $driver->searchReleasePage(ReleaseSearchQuery::fromCriteria($criteria, $limit, $offset));
    $requests[$name] = ['criteria' => orderedCriteria($criteria), 'limit' => $limit, 'offset' => $offset, 'bodies' => $driver->manticoreSearch->bodies];
}
$external = [];
foreach ([
    'one' => [[['imdbid' => '0133093', 'tmdbid' => 603, 'tvdb' => 0, 'bogus' => 1]], 1000],
    'sets' => [[['imdbid' => '1'], ['imdbid' => '1', 'tvmaze' => '5'], ['tvrage' => 6, 'traktid' => 7]], 20000],
] as $name => [$sets, $limit]) {
    $driver = driverWith();
    $driver->manticoreSearch = new RecordingClient(['host' => '127.0.0.1', 'port' => 1]);
    $driver->searchReleasesByMultipleExternalIds($sets, $limit);
    $external[$name] = ['sets' => $sets, 'limit' => $limit, 'bodies' => $driver->manticoreSearch->bodies];
}

// Results from a real Manticore, through the real client.
$documents = json_decode((string) file_get_contents($here.'/documents.json'), true, flags: JSON_THROW_ON_ERROR);
$port = (int) (getenv('TEST_MANTICORE_HTTP_PORT') ?: 39308);
$indexes = [];
foreach (array_keys(config('search.drivers.manticore.indexes')) as $logical) {
    $indexes[$logical] = 'golden_'.$logical;
}
$live = driverWith(['host' => '127.0.0.1', 'port' => $port, 'indexes' => $indexes]);
$registry = App\Services\Search\Support\ManticoreIndexRegistry::definitions();
foreach ($indexes as $logical => $table) {
    $live->manticoreSearch->sql('DROP TABLE IF EXISTS '.$table, true);
    $live->manticoreSearch->tables()->create(['index' => $table, 'body' => $registry[$logical]]);
}
$releases = array_map(static fn (array $row): array => ['id' => $row['id']] + ReleaseSearchIndexDocument::normalize($row), $documents['releases']);
$live->manticoreSearch->table($indexes['releases'])->replaceDocuments($releases);
foreach (['music', 'anime', 'tvshows', 'movies', 'predb'] as $logical) {
    $live->manticoreSearch->table($indexes[$logical])->replaceDocuments($documents[$logical]);
}
$class = new ReflectionClass(ManticoreSearchDriver::class);
$class->setStaticPropertyValue('availabilityCache', null);
$class->setStaticPropertyValue('availabilityCacheTime', null);

$results = ['pages' => [], 'autocomplete' => [], 'suggest' => [], 'external' => [], 'secondary' => [], 'anime' => [], 'ids' => [], 'fuzzy_ids' => [], 'predb' => [], 'tv' => [], 'movies' => [], 'movie_external' => []];
foreach ($documents['queries'] as $name => [$criteria, $limit, $offset]) {
    $page = $live->searchReleasePage(ReleaseSearchQuery::fromCriteria($criteria, $limit, $offset));
    $results['pages'][$name] = [
        'criteria' => orderedCriteria($criteria), 'limit' => $limit, 'offset' => $offset,
        'ids' => $page->ids, 'total' => $page->total, 'fuzzy' => $page->fuzzy, 'available' => $page->available,
        'has_more' => $page->hasMore, 'documents' => $page->documents,
    ];
}
foreach (['dead', 'Dead Ci', 'spider-m', 'zz', 'd', 'nosuchthing'] as $query) {
    $results['autocomplete'][] = [$query, $live->autocomplete($query)];
}
foreach (['circos', 'spidr', 'dead', '!circus', '', 'nosuchthing', 'circus'] as $query) {
    $results['suggest'][] = [$query, $live->suggest($query)];
}
foreach ([[['imdbid' => '0133093']], [['tvdb' => 77]], [['tmdbid' => 603], ['tvdb' => 77]], [['imdbid' => '404']]] as $sets) {
    $results['external'][] = [$sets, $live->searchReleasesByMultipleExternalIds($sets, 100)];
}
foreach ([[SecondarySearchIndex::Music, 'beatles'], [SecondarySearchIndex::Music, 'abbey'], [SecondarySearchIndex::Anime, 'cowboy'], [SecondarySearchIndex::Music, '  ']] as [$index, $query]) {
    $results['secondary'][] = [$index->value, $query, $live->searchSecondary($index, $query)];
}
foreach (['cowboy', 'bebop', 'nosuch'] as $query) {
    $results['anime'][] = [$query, $live->searchAnimeTitle($query)];
}
foreach ([['dead circus', 100], [['name' => 'dead', 'searchname' => 'dead'], 100], [['searchname' => 'dead', 'fromname' => 'poster'], 100], ['0', 100]] as [$phrases, $limit]) {
    $results['ids'][] = [ordered($phrases), $limit, $live->searchReleases($phrases, $limit)];
    $results['fuzzy_ids'][] = [ordered($phrases), $limit, $live->searchReleasesWithFuzzy($phrases, $limit)];
}
$results['fuzzy_ids'][] = ['ciircus', 100, $live->searchReleasesWithFuzzy('ciircus', 100)];
$results['fuzzy_ids'][] = ['!circus', 100, $live->searchReleasesWithFuzzy('!circus', 100)];
foreach (['some release', 'some.release.nfo', 'nosuch'] as $query) {
    $results['predb'][] = [$query, $live->searchPredb($query)];
}
$results['tv'] = [['lost', $live->searchTvShows('lost')], ['nosuch', $live->searchTvShows('nosuch')]];
$results['movies'] = [['matrix', $live->searchMovies('matrix')], ['keanu', $live->searchMovies('keanu')]];
$results['movies_by_fields'] = [[ordered(['title' => 'matrix', 'director' => 'wachowski', 'bogus' => 'x']), $live->searchMoviesByFields(['title' => 'matrix', 'director' => 'wachowski', 'bogus' => 'x'])]];
$results['movie_external'] = [[['imdbid' => '0133093'], $live->searchMovieByExternalIds(['imdbid' => '0133093'])], [['tmdbid' => 404], $live->searchMovieByExternalIds(['tmdbid' => 404])]];
foreach ($indexes as $table) {
    $live->manticoreSearch->sql('DROP TABLE IF EXISTS '.$table, true);
}

file_put_contents($here.'/golden.json', json_encode(
    ['pure' => $pure, 'requests' => $requests, 'external_requests' => $external, 'results' => $results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
)."\n");
echo "wrote golden.json\n";
