<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GoogleBooksService;
use App\Services\ItunesService;
use App\Services\OpenLibraryService;
use App\Support\Data\Factories\SteamGameDataFactory;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Reproduce the paired single-call experiment with benchmark('/tmp/wave11-normalizers.json').
 * Baseline: 8fc66987695e2cc67dc808397ccf2cc94e53be33. Production adoption
 * requires >=1.5x mean AND p99 on common AND uncommon inputs for each method.
 * PHP 8.5.12, rustc 1.99.1 release, Darwin arm64 Apple M5, 10 CPUs/32 GiB;
 * OPcache CLI off; C.UTF-8; host process without imposed limits. 1,024
 * warmups, 20,480 paired single calls per method/workload, five randomized
 * blocks, no Carbon test time. Run 4 (PHP latency / native latency),
 * common then uncommon mean/p99:
 * buildSearchQuery                0.26/0.18   0.19/0.21
 * google normalizeBookResult      0.91/0.89   0.95/0.92
 * openlibrary normalizeIsbnResult 0.78/0.76   0.62/0.66
 * openlibrary normalizeSearch     0.77/0.68   1.77/1.56
 * itunes getHighResCover          0.40/0.44   0.47/0.43
 * itunes normalizeAlbumResult     4.23/3.64   3.91/3.26
 * itunes normalizeTrackResult     4.41/3.36   3.72/2.16
 * itunes normalizeEbookResult     4.43/3.39   3.81/2.64
 * steam parse* (all seven)        0.10-0.33   0.11-0.41
 * Only the three iTunes result normalizers qualify (Rust avoids Carbon for
 * ISO-8601 UTC releaseDate); the rest retain PHP by default. The non-gating
 * fallback workload (non-API date forms, missing date) measured 0.66-0.68
 * mean. Run 1 used Carbon test time, which inflates Carbon parsing, and was
 * discarded; run 2's uncommon iTunes inputs used a non-API date form (1.19x)
 * and became the fallback workload. Raw samples/orders/inputs/source hashes:
 * /tmp/wave11-normalizers-run{1,2,3,4}.json.
 */
final class RustBookItunesSteamNormalizerParityTest extends TestCase
{
    private ?string $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();
        $now = Carbon::getTestNow();
        $this->previousTestNow = $now?->toIso8601String();
        Carbon::setTestNow('2026-10-24 12:34:56');
        self::assertNotNull(GoogleBooksService::normalizerFfi(), 'Build and copy the release pipeline library before running parity tests.');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow($this->previousTestNow);
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** @return array{0: string, 1: mixed} */
    private static function outcome(callable $call): array
    {
        try {
            return ['ok', $call()];
        } catch (Throwable $e) {
            return ['throw', $e::class];
        }
    }

    private static function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** @return list<string> */
    private static function descriptions(): array
    {
        $base = ['', 'Plain text.', '<p>One</p><p>Two <b>bold</b> &amp; <i>it</i></p>', '<br/>Line<br />', 'a < b', 'a > b',
            '<a href="x">link</a>', "<a href='x'>y</a>", '<!-- comment -->text', '<?php echo 1; ?>text', '<!DOCTYPE html>t',
            "nul\0byte", '<b', '<', '1<2', '<>', '< p>', '</p', 'é <p>ü</p>', "bad\xff<i>x</i>", '<p><<b>>x', '<p\n class=x>y</p>'];
        $random = new Randomizer(new Mt19937(1101));
        $chunks = ['<p>', '</p>', '<b>', 'text', ' ', '<', '>', '"', "'", '!', '?', '-', '<!--', '-->', '<br/>', 'é', "\xff", "\0",
            '<a href="x">', '\\', '=', "\t", "\n", 'x', '</', '<?', '/>', '<a', 'b>'];
        for ($i = 0; $i < 2500; $i++) {
            $parts = [];
            for ($n = $random->getInt(1, 12); $n > 0; $n--) {
                $parts[] = $chunks[$random->getInt(0, count($chunks) - 1)];
            }
            $base[] = implode('', $parts);
        }

        return $base;
    }

    /** @return list<mixed> */
    private static function dates(): array
    {
        $dates = [null, '', ' ', '2003-08-30', '2003', '2003-08', '2000-02-29', '1900-02-29', '2020-02-30', '0000-01-01', '2020-1-2',
            '2020-01-01T08:00:00Z', '2020-01-01T23:59:59Z', '2020-01-01T24:00:00Z', '2020-01-01T10:60:00Z', '2020-12-31T23:59:59+05:00',
            'September 1, 2020', 'February 29, 2019', 'May 31, 2021', 'June 31, 2021', 'september 1, 2020', 'Sep 1, 2020', 'August 30,2003',
            'December 01, 1999', 'nonsense', 'next Thursday', 'now', "2020-01-01\0", "bad\xff", 2003, false, 1.5];
        $random = new Randomizer(new Mt19937(1102));
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        for ($i = 0; $i < 400; $i++) {
            $y = sprintf('%04d', $random->getInt(1, 2400));
            $m = sprintf('%02d', $random->getInt(0, 13));
            $d = sprintf('%02d', $random->getInt(0, 32));
            $dates[] = "$y-$m-$d";
            $dates[] = sprintf('%s-%s-%sT%02d:%02d:%02dZ', $y, $m, $d, $random->getInt(0, 24), $random->getInt(0, 60), $random->getInt(0, 60));
            $dates[] = $months[$random->getInt(0, 11)].' '.$random->getInt(0, 32).', '.$y;
        }

        return $dates;
    }

    /** @return list<array<string, mixed>> */
    public static function googleItems(): array
    {
        $info = ['title' => 'Domain-Driven Design', 'authors' => ['Eric Evans'], 'publisher' => 'Addison-Wesley', 'publishedDate' => '2003-08-30',
            'description' => '<p>Practical <b>design</b>.</p>', 'pageCount' => 560, 'categories' => ['Computers'], 'infoLink' => 'https://books.example/1',
            'industryIdentifiers' => [['type' => 'ISBN_10', 'identifier' => '0321125215'], ['type' => 'ISBN_13', 'identifier' => '9780321125217']],
            'imageLinks' => ['smallThumbnail' => 'https://img/s', 'thumbnail' => 'https://img/t']];
        $items = [['id' => 'g1', 'volumeInfo' => $info], [], ['id' => 'x'], ['volumeInfo' => 'bad', 'id' => 7]];
        $items[] = ['id' => 'g2', 'volumeInfo' => array_replace($info, ['industryIdentifiers' => [
            'bad', ['type' => 'OTHER', 'identifier' => 'X'], ['type' => 'ISBN_13', 'identifier' => '978-0-13-235088-4'],
            ['type' => 'ISBN_13', 'identifier' => 9780321125217], ['type' => 'ISBN_10', 'identifier' => '013235088x'], ['type' => null],
            ['type' => 'ISBN_10', 'identifier' => '0000000001'], ['identifier' => '9780132350884'], ['type' => 'ISBN_13', 'identifier' => true],
        ], 'authors' => [null, false, 0, '0', '', ' ', 'García Márquez', true, 3], 'categories' => 'not-array', 'imageLinks' => ['smallThumbnail' => 'https://img/s']])];
        $items[] = ['volumeInfo' => array_replace($info, ['industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => 9.780321125217E12]]])];
        $items[] = ['volumeInfo' => array_replace($info, ['authors' => [1.5], 'description' => 42])];
        $items[] = ['volumeInfo' => array_replace($info, ['authors' => ["A\0B"], 'description' => true])];
        $items[] = ['volumeInfo' => array_replace($info, ['industryIdentifiers' => null, 'description' => null, 'categories' => ['0', 'Fiction', 'Fiction']])];
        foreach (self::descriptions() as $description) {
            $items[] = ['id' => 'd', 'volumeInfo' => array_replace($info, ['description' => $description])];
        }
        foreach (self::dates() as $date) {
            $items[] = ['id' => 'p', 'volumeInfo' => array_replace($info, ['publishedDate' => $date])];
        }

        return $items;
    }

    public function test_google_books_query_and_normalization_match_php(): void
    {
        $service = self::bare(GoogleBooksService::class);
        $values = [null, '', '0', ' ', 'Dune', "A\0B", "bad\xff", 'García Márquez', '9780321125217'];
        foreach ($values as $query) {
            foreach ($values as $title) {
                foreach ($values as $author) {
                    foreach ($values as $isbn) {
                        $expected = $service->buildSearchQueryPhp((string) $query, $title, $author, $isbn);
                        self::assertSame($expected, $service->buildSearchQueryNative((string) $query, $title, $author, $isbn));
                    }
                }
            }
        }
        foreach ([false, true, null] as $toggle) {
            $service->useRustFfi = $toggle;
            self::assertSame('intitle:Dune inauthor:Frank', self::invoke($service, 'buildSearchQuery', 'q', 'Dune', 'Frank', null));
        }
        foreach (self::googleItems() as $i => $item) {
            $expected = $service->normalizeBookResultPhp($item);
            self::assertSame($expected, $service->normalizeBookResultNative($item), "google item $i");
        }
        foreach ([false, true, null] as $toggle) {
            $service->useRustFfi = $toggle;
            $item = self::googleItems()[0];
            self::assertSame($service->normalizeBookResultPhp($item), self::invoke($service, 'normalizeBookResult', $item));
        }
    }

    public function test_open_library_normalization_matches_php(): void
    {
        $service = self::bare(OpenLibraryService::class);
        $doc = ['key' => '/works/OL1W', 'title' => 'Clean Code', 'author_name' => ['Robert C. Martin', '', '0', null, 7, true],
            'isbn' => ['bad', 9780132350884, '0-13-235088-2', '9780132350884', '013235088X', null, true, '978013235088'],
            'publisher' => ['Prentice Hall'], 'first_publish_year' => 2008, 'cover_i' => 123];
        $docs = [$doc, [], ['title' => 'No key'], array_replace($doc, ['isbn' => 'string', 'author_name' => 'string']),
            array_replace($doc, ['isbn' => [1.5]]), array_replace($doc, ['author_name' => [2.5]]), array_replace($doc, ['author_name' => ["A\0B"]]),
            array_replace($doc, ['isbn' => ["9780132350884\0"]]), array_replace($doc, ['isbn' => array_fill(0, 200, '978-0-13-235088-4')])];
        $random = new Randomizer(new Mt19937(1103));
        $chunks = ['978', '0', '13', '235088', '4', '2', 'X', 'x', '-', ' ', '9', '979'];
        for ($i = 0; $i < 400; $i++) {
            $isbns = [];
            for ($n = $random->getInt(0, 12); $n > 0; $n--) {
                $parts = [];
                for ($k = $random->getInt(1, 6); $k > 0; $k--) {
                    $parts[] = $chunks[$random->getInt(0, count($chunks) - 1)];
                }
                $isbns[] = implode('', $parts);
            }
            $docs[] = array_replace($doc, ['isbn' => $isbns]);
        }
        foreach ($docs as $i => $candidate) {
            self::assertSame($service->normalizeSearchResultPhp($candidate), $service->normalizeSearchResultNative($candidate), "search doc $i");
        }
        $book = ['title' => 'Clean Code', 'authors' => [['name' => 'Robert C. Martin'], ['key' => '/a/1'], ['name' => null], ['name' => 7],
            'string', ['name' => ''], ['name' => true]], 'publish_date' => 'August 1, 2008', 'publishers' => ['PH'], 'number_of_pages' => '464',
            'covers' => [55], 'url' => 'https://ol/x'];
        $books = [$book, [], array_replace($book, ['authors' => [['name' => 1.5]]]), array_replace($book, ['authors' => [['name' => "A\0B"]]]),
            array_replace($book, ['authors' => 'string'])];
        foreach (self::dates() as $date) {
            $books[] = array_replace($book, ['publish_date' => $date]);
        }
        foreach ($books as $i => $candidate) {
            foreach (['9780132350884', '0132350882', ''] as $isbn) {
                self::assertSame($service->normalizeIsbnResultPhp($candidate, $isbn), $service->normalizeIsbnResultNative($candidate, $isbn), "isbn book $i");
            }
        }
        foreach ([false, true, null] as $toggle) {
            $service->useRustFfi = $toggle;
            self::assertSame($service->normalizeSearchResultPhp($doc), self::invoke($service, 'normalizeSearchResult', $doc));
            self::assertSame($service->normalizeIsbnResultPhp($book, '0132350882'), self::invoke($service, 'normalizeIsbnResult', $book, '0132350882'));
        }
    }

    /** @return list<string> */
    private static function coverUrls(): array
    {
        $urls = ['', '0', 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/ab/cd/source/100x100bb.jpg', '100x100.jpg', '1x2bb.', '12x34bbb.',
            'x100x100bb.', '100x100bb', 'é100x100bb.jpg', "\xff", "a\0100x100bb.jpg", '1x1.2x2.3x3bb.', '60x60bb.100x100bb.jpg', '00x00.', 'x.', '1x.', 'x1.'];
        $random = new Randomizer(new Mt19937(1104));
        $chunks = ['1', '23', 'x', 'bb', 'b', '.', '/', '100x100', 'jpg', '0', 'X', '²'];
        for ($i = 0; $i < 1500; $i++) {
            $parts = [];
            for ($n = $random->getInt(1, 10); $n > 0; $n--) {
                $parts[] = $chunks[$random->getInt(0, count($chunks) - 1)];
            }
            $urls[] = implode('', $parts);
        }

        return $urls;
    }

    public function test_itunes_cover_and_result_normalization_match_php(): void
    {
        $service = self::bare(ItunesService::class);
        foreach (self::coverUrls() as $url) {
            foreach ([800, 0, -5, 3000, PHP_INT_MAX, PHP_INT_MIN] as $size) {
                self::assertSame($service->getHighResCoverPhp($url, $size), $service->getHighResCoverNative($url, $size), bin2hex($url));
            }
        }
        $album = ['collectionId' => 1, 'collectionName' => 'Album', 'artistName' => 'Artist', 'artistId' => 2, 'primaryGenreName' => 'Rock',
            'releaseDate' => '2020-01-01T08:00:00Z', 'trackCount' => 10, 'artworkUrl100' => 'https://a/100x100bb.jpg', 'collectionViewUrl' => 'u',
            'collectionPrice' => 9.99, 'currency' => 'USD', 'copyright' => 'c', 'collectionExplicitness' => 'explicit', 'trackId' => 3, 'trackName' => 'T',
            'genres' => ['Fiction', 'Books'], 'description' => '<p>x</p>', 'price' => 4.99, 'averageUserRating' => 4.5, 'userRatingCount' => 12];
        $results = [$album, [], ['releaseDate' => null, 'artworkUrl100' => null], ['releaseDate' => 20200101], ['artworkUrl100' => 100],
            ['releaseDate' => "2020-01-01\0"], ['artworkUrl100' => "a\0100x100bb.jpg"], ['genres' => []]];
        foreach (self::dates() as $date) {
            if (is_string($date) || $date === null) {
                $results[] = array_replace($album, ['releaseDate' => $date]);
            }
        }
        foreach (array_slice(self::coverUrls(), 0, 200) as $url) {
            $results[] = array_replace($album, ['artworkUrl100' => $url]);
        }
        foreach (['Album', 'Track', 'Ebook'] as $kind) {
            foreach ($results as $i => $result) {
                self::assertSame(
                    self::outcome(fn (): array => $service->{"normalize{$kind}ResultPhp"}($result)),
                    self::outcome(fn (): array => $service->{"normalize{$kind}ResultNative"}($result)),
                    "$kind $i",
                );
            }
            foreach ([false, true, null] as $toggle) {
                $service->useRustFfi = $toggle;
                self::assertSame($service->{"normalize{$kind}ResultPhp"}($album), self::invoke($service, "normalize{$kind}Result", $album));
            }
        }
        foreach ([false, true, null] as $toggle) {
            $service->useRustFfi = $toggle;
            self::assertSame('https://a/600x600bb.jpg', self::invoke($service, 'getHighResCover', 'https://a/100x100bb.jpg', 600));
        }
    }

    /** @return list<array<string, mixed>> */
    public static function steamPayloads(): array
    {
        $data = [
            'screenshots' => [['path_thumbnail' => 'https://s/t1.jpg', 'path_full' => 'https://s/f1.jpg'], ['path_full' => 'f2'], 'bad', 7, ['path_thumbnail' => 5, 'path_full' => true]],
            'movies' => [
                ['id' => 1, 'name' => 'Trailer', 'thumbnail' => 't', 'webm' => ['480' => 'w480', 'max' => 'wmax'], 'mp4' => ['480' => 'm480']],
                ['id' => '2', 'mp4' => ['max' => null, 480 => 'x']], ['mp4' => 'short'], 'bad', ['mp4' => ['max' => '0']], ['mp4' => ['max' => false, 'x' => 1]],
            ],
            'genres' => [['id' => '1', 'description' => 'Action'], ['description' => '0'], ['description' => ''], ['description' => 5], ['description' => true],
                ['description' => false], ['description' => null], ['description' => []], 'Action', 3],
            'categories' => [['description' => 'Single-player'], ['description' => 'Steam Achievements']],
            'platforms' => ['windows' => true, 'mac' => '0', 'linux' => 1],
            'publishers' => ['Valve', '', '0', null, true, 3, false],
            'developers' => ['Valve', 'Hidden Path', '', '0', 9],
        ];
        $payloads = [$data, [], array_fill_keys(array_keys($data), []), array_fill_keys(array_keys($data), 'string'), array_fill_keys(array_keys($data), null)];
        foreach ([[1.5], [['path_full' => 1.5]], [['description' => 1.5]], [['mp4' => str_repeat('m', 600)]],
            [['mp4' => str_repeat('m', 480)]], [["a\0b"]], [['path_full' => "a\0b", 'description' => "a\0b", 'mp4' => ['max' => "a\0b"]]],
            [['mp4' => ['max' => 1.5]]], [['id' => [1]]], ['windows' => 1.5], ['windows' => [], 'mac' => [0], 'linux' => 'false'],
            ['windows' => '', 'mac' => null], [0], [[]]] as $variant) {
            $payload = array_fill_keys(array_keys($data), $variant);
            if (array_filter($variant, is_array(...)) !== []) {
                // strval() on nested arrays warns; publishers/developers stay scalar.
                unset($payload['publishers'], $payload['developers']);
            }
            $payloads[] = $payload;
        }
        $random = new Randomizer(new Mt19937(1105));
        $values = [null, '', '0', 'x', 0, 1, true, false, [], ['max' => 'm'], [480 => 'n'], 'https://cdn/movie.mp4'];
        for ($i = 0; $i < 300; $i++) {
            $payload = [];
            foreach (['screenshots', 'movies', 'genres', 'categories'] as $key) {
                for ($n = $random->getInt(0, 6); $n > 0; $n--) {
                    $item = [];
                    foreach (['path_thumbnail', 'path_full', 'id', 'name', 'thumbnail', 'webm', 'mp4', 'description'] as $field) {
                        if ($random->getInt(0, 2) > 0) {
                            $value = $values[$random->getInt(0, count($values) - 1)];
                            // Array-to-string casts warn in PHP; keep fixtures warning-free.
                            $item[$field] = $field === 'description' && is_array($value) ? [] : $value;
                        }
                    }
                    $payload[$key][] = $item;
                }
            }
            foreach (['windows', 'mac', 'linux'] as $platform) {
                $payload['platforms'][$platform] = $values[$random->getInt(0, count($values) - 1)];
            }
            $payload['publishers'] = $payload['developers'] = array_map(fn (): mixed => [null, '', '0', 'Studio', 4, true, false][$random->getInt(0, 6)], range(0, $random->getInt(0, 5)));
            $payloads[] = $payload;
        }

        return $payloads;
    }

    public function test_steam_parsers_match_php_and_make(): void
    {
        $factory = new SteamGameDataFactory;
        foreach (self::steamPayloads() as $i => $data) {
            foreach (['Screenshots', 'Genres', 'Categories', 'Platforms', 'Publisher', 'Developers'] as $parser) {
                $expected = self::outcome(fn (): mixed => $factory->{"parse{$parser}Php"}($data));
                self::assertSame($expected, self::outcome(fn (): mixed => $factory->{"parse{$parser}Native"}($data)), "$parser $i");
            }
            $phpTrailer = $nativeTrailer = 'unset';
            self::assertSame($factory->parseMoviesPhp($data, $phpTrailer), $factory->parseMoviesNative($data, $nativeTrailer), "movies $i");
            self::assertSame($phpTrailer, $nativeTrailer, "trailer $i");
        }
        $data = self::steamPayloads()[0] + ['name' => 'Game', 'release_date' => ['date' => 'Nov 1, 2004'], 'is_free' => true];
        $php = new SteamGameDataFactory;
        $php->useRustFfi = false;
        foreach ([true, null] as $toggle) {
            $factory->useRustFfi = $toggle;
            self::assertEquals($php->make($data, 70), $factory->make($data, 70));
        }
    }

    public function test_direct_abi_declines_unsupported_frames(): void
    {
        $ffi = GoogleBooksService::normalizerFfi();
        self::assertInstanceOf(FFI::class, $ffi);
        $cap = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $cap);
        self::assertNotNull($buffer);
        foreach (['', "q\0", "q\0t\0a\0", "q\0t\0a\0i\0x\0", "q\0t\0a\0i"] as $bad) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_google_books_query_nul($bad, strlen($bad), $buffer, $cap));
        }
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_google_books_query_nul("q\0title\0\0\0", 10, $buffer, 2);
        self::assertSame(-13, $needed);
        foreach ([[0, 'N;'], [0, 'a:1:{i:0;N;}'], [1, 'a:0:{}'], [2, 'O:8:"stdClass":0:{}'], [3, serialize([1, 2])], [0, 'a:5:{']] as [$op, $bad]) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_book_provider_fields($op, $bad, strlen($bad), $buffer, $cap));
        }
        $steam = SteamGameDataFactory::normalizerFfi();
        self::assertInstanceOf(FFI::class, $steam);
        foreach ([[6, 'a:0:{}'], [0, 's:1:"x";'], [2, 'a:1:{i:0;a:1:{s:11:"description";d:0.5;}}'], [0, 'a:1:{i:0;r:1;}']] as [$op, $bad]) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $steam->nntmux_steam_list_fields($op, $bad, strlen($bad), $buffer, $cap));
        }
        $itunes = ItunesService::normalizerFfi();
        self::assertInstanceOf(FFI::class, $itunes);
        foreach (['', "1\0x\0", "2\0x\0y\0", "1\0x\0y"] as $bad) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $itunes->nntmux_itunes_result_fields($bad, strlen($bad), 800, $buffer, $cap));
        }
        $input = "1\0September 1, 2020\0https://a/100x100bb.jpg\0";
        // @phpstan-ignore method.notFound
        $written = $itunes->nntmux_itunes_result_fields($input, strlen($input), 600, $buffer, $cap);
        self::assertSame(['1', '2020-09-01', 'https://a/600x600bb.jpg', ''], explode("\0", FFI::string($buffer, $written)));
    }

    public function test_missing_library_and_locale_fallbacks(): void
    {
        $google = self::bare(GoogleBooksService::class);
        $library = self::bare(OpenLibraryService::class);
        $itunes = self::bare(ItunesService::class);
        $steam = new SteamGameDataFactory;
        $properties = [new ReflectionProperty(GoogleBooksService::class, 'normalizerFfi'),
            new ReflectionProperty(ItunesService::class, 'normalizerFfi'), new ReflectionProperty(SteamGameDataFactory::class, 'normalizerFfi')];
        $previous = array_map(static fn (ReflectionProperty $p): mixed => $p->getValue(), $properties);
        $locale = setlocale(LC_CTYPE, '0');
        $assertParity = function () use ($google, $library, $itunes, $steam): void {
            $item = self::googleItems()[0];
            self::assertSame($google->normalizeBookResultPhp($item), $google->normalizeBookResultNative($item));
            self::assertSame($google->buildSearchQueryPhp('q', 'T'), $google->buildSearchQueryNative('q', 'T'));
            $doc = ['isbn' => ['9780132350884'], 'author_name' => ['A']];
            self::assertSame($library->normalizeSearchResultPhp($doc), $library->normalizeSearchResultNative($doc));
            self::assertSame($library->normalizeIsbnResultPhp([], '0132350882'), $library->normalizeIsbnResultNative([], '0132350882'));
            foreach (self::coverUrls() as $url) {
                self::assertSame($itunes->getHighResCoverPhp($url), $itunes->getHighResCoverNative($url));
            }
            $album = ['releaseDate' => '2020-01-01', 'artworkUrl100' => 'https://a/100x100bb.jpg'];
            self::assertSame($itunes->normalizeAlbumResultPhp($album), $itunes->normalizeAlbumResultNative($album));
            $data = self::steamPayloads()[0];
            self::assertSame($steam->parseGenresPhp($data), $steam->parseGenresNative($data));
            self::assertSame($steam->parsePublisherPhp($data), $steam->parsePublisherNative($data));
        };
        try {
            foreach ($properties as $property) {
                $property->setValue(null, false);
            }
            $assertParity();
            foreach ($properties as $i => $property) {
                $property->setValue(null, $previous[$i]);
            }
            foreach (['en_US.ISO8859-1', 'de_DE.ISO8859-1', 'en_US.UTF-8'] as $candidateLocale) {
                if (setlocale(LC_CTYPE, $candidateLocale) !== false) {
                    self::assertFalse(ItunesService::normalizerEnvironmentSupported());
                    $assertParity();
                    break;
                }
            }
        } finally {
            foreach ($properties as $i => $property) {
                $property->setValue(null, $previous[$i]);
            }
            // Reset PCRE character tables to ASCII before restoring the prior locale.
            setlocale(LC_CTYPE, 'C');
            if ($locale !== false) {
                setlocale(LC_CTYPE, $locale);
            }
        }
    }

    /**
     * php -r 'require "vendor/autoload.php"; Tests\Unit\RustBookItunesSteamNormalizerParityTest::benchmark("/tmp/wave11-normalizers.json");'
     * Single calls include serialization, framing, dispatch, FFI and PHP fallback.
     * Keep this executor serial; do not run builds/analysis concurrently.
     */
    public static function benchmark(string $outputPath): void
    {
        // Production parses without Carbon test time; setTestNow adds relative-keyword scans.
        Carbon::setTestNow();
        $coldStart = hrtime(true);
        GoogleBooksService::normalizerFfi();
        ItunesService::normalizerFfi();
        SteamGameDataFactory::normalizerFfi();
        $resolutionNs = hrtime(true) - $coldStart;
        $google = self::bare(GoogleBooksService::class);
        $library = self::bare(OpenLibraryService::class);
        $itunes = self::bare(ItunesService::class);
        $steam = new SteamGameDataFactory;
        $random = new Randomizer(new Mt19937(11010));
        $text = 'An approach to software development that centres the domain model and a ubiquitous language. ';
        $workloads = ['common' => [], 'uncommon' => [], 'fallback' => []];
        foreach ([0, 1, 2, 3] as $i) {
            $workloads['common'][] = [
                'query' => ['', ['Domain-Driven Design', 'Dune', 'Clean Code', 'The Art of War'][$i], ['Eric Evans', 'Frank Herbert', null, 'Sun Tzu'][$i], $i === 2 ? '9780132350884' : null],
                'google' => ['id' => 'g'.$i, 'volumeInfo' => ['title' => 'Book '.$i, 'authors' => ['Eric Evans'], 'publisher' => 'Pub',
                    'publishedDate' => ['2003-08-30', '2003', '2003-08-30', '2011-05-01'][$i], 'description' => ['<p>'.$text.'</p>', $text, '<p><b>'.$text.'</b></p>', $text][$i],
                    'pageCount' => 560, 'categories' => ['Computers'], 'infoLink' => 'https://books.example/'.$i,
                    'industryIdentifiers' => [['type' => 'ISBN_10', 'identifier' => '0321125215'], ['type' => 'ISBN_13', 'identifier' => '9780321125217']],
                    'imageLinks' => ['smallThumbnail' => 'https://img/s', 'thumbnail' => 'https://img/t']]],
                'olSearch' => ['key' => '/works/OL'.$i.'W', 'title' => 'Book', 'author_name' => ['Robert C. Martin'], 'isbn' => ['9780132350884', '0132350882', '9780136083252', '0136083250'],
                    'publisher' => ['Prentice Hall'], 'first_publish_year' => 2008, 'cover_i' => 123],
                'olIsbn' => ['title' => 'Book', 'authors' => [['name' => 'Robert C. Martin']], 'publish_date' => ['August 1, 2008', '2008', 'Aug 1, 2008', 'March 3, 2010'][$i],
                    'publishers' => ['PH'], 'number_of_pages' => 464, 'covers' => [55]],
                'cover' => 'https://is1-ssl.mzstatic.com/image/thumb/Music1'.$i.'/v4/ab/cd/ef/abcdef01-2345/source/100x100bb.jpg',
                'itunes' => ['collectionId' => $i, 'collectionName' => 'Album', 'artistName' => 'Artist', 'artistId' => 2, 'primaryGenreName' => 'Rock',
                    'releaseDate' => '201'.$i.'-01-01T08:00:00Z', 'trackCount' => 10, 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/'.$i.'/source/100x100bb.jpg',
                    'collectionViewUrl' => 'u', 'collectionPrice' => 9.99, 'currency' => 'USD', 'trackId' => 3, 'trackName' => 'T', 'genres' => ['Rock', 'Music'],
                    'description' => $text, 'price' => 4.99, 'averageUserRating' => 4.5, 'userRatingCount' => 12],
                'steam' => [
                    'screenshots' => array_map(fn (int $n): array => ['id' => $n, 'path_thumbnail' => "https://cdn/ss_$n.600x338.jpg", 'path_full' => "https://cdn/ss_$n.1920x1080.jpg"], range(0, 5 + $i)),
                    'movies' => array_map(fn (int $n): array => ['id' => $n, 'name' => 'Trailer', 'thumbnail' => 'https://cdn/t.jpg', 'webm' => ['480' => 'https://cdn/480.webm', 'max' => 'https://cdn/max.webm'], 'mp4' => ['480' => 'https://cdn/480.mp4', 'max' => 'https://cdn/max.mp4'], 'highlight' => true], range(0, $i)),
                    'genres' => [['id' => '1', 'description' => 'Action'], ['id' => '25', 'description' => 'Adventure']],
                    'categories' => array_map(fn (int $n): array => ['id' => $n, 'description' => 'Category '.$n], range(0, 4 + $i)),
                    'platforms' => ['windows' => true, 'mac' => $i % 2 === 0, 'linux' => false], 'publishers' => ['Valve'], 'developers' => ['Valve', 'Hidden Path'],
                ],
            ];
            $long = str_repeat('<p>'.$text.'<b>'.$text.'</b><br/></p>', 12 + $i);
            $workloads['uncommon'][] = [
                'query' => [str_repeat('Extended query term ', 20), str_repeat('A Very Long Title ', 12 + $i), str_repeat('Author Name ', 6), null],
                'google' => ['id' => 'g'.$i, 'volumeInfo' => ['title' => 'Long', 'authors' => array_fill(0, 12, 'Author Name'), 'publisher' => 'Pub', 'publishedDate' => '2003-08-30',
                    'description' => $long, 'pageCount' => 560, 'categories' => array_fill(0, 10, 'Computers / Software'), 'infoLink' => 'https://books.example/'.$i,
                    'industryIdentifiers' => array_merge(array_fill(0, 4, ['type' => 'OTHER', 'identifier' => 'UOM:39015'.$i]), [['type' => 'ISBN_10', 'identifier' => '0321125215'], ['type' => 'ISBN_13', 'identifier' => '9780321125217']])]],
                'olSearch' => ['key' => '/works/OL'.$i.'W', 'title' => 'Book', 'author_name' => array_fill(0, 20, 'Contributor Name'),
                    'isbn' => array_merge(array_fill(0, 120 + $i, '9780000000002'), ['9780132350884', '0132350882']), 'first_publish_year' => 2008],
                'olIsbn' => ['title' => 'Book', 'authors' => array_fill(0, 25, ['name' => 'Contributor Name', 'key' => '/authors/OL1A']), 'publish_date' => 'September 14, 2009', 'covers' => [55]],
                'cover' => 'https://is1-ssl.mzstatic.com/image/thumb/'.str_repeat('Segment'.$i.'/', 20).'60x60bb.100x100bb.jpg',
                'itunes' => ['trackId' => $i, 'trackName' => 'Ebook', 'artistName' => 'Author', 'artistId' => 2, 'genres' => array_fill(0, 12, 'Fiction'),
                    'releaseDate' => '200'.$i.'-09-14T07:00:00Z', 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/thumb/'.str_repeat('Publication/v4/', 8).'100x100bb.jpg',
                    'description' => $long, 'trackViewUrl' => 'u', 'price' => 4.99, 'currency' => 'USD', 'averageUserRating' => 4.5, 'userRatingCount' => 12],
                'steam' => [
                    'screenshots' => array_map(fn (int $n): array => ['id' => $n, 'path_thumbnail' => "https://cdn/ss_$n.600x338.jpg", 'path_full' => "https://cdn/ss_$n.1920x1080.jpg"], range(0, 40 + $i)),
                    'movies' => array_map(fn (int $n): array => ['id' => $n, 'name' => 'Trailer '.$n, 'thumbnail' => 'https://cdn/t.jpg', 'webm' => ['480' => 'https://cdn/480.webm'], 'mp4' => ['480' => 'https://cdn/480.mp4'], 'highlight' => false], range(0, 12 + $i)),
                    'genres' => array_map(fn (int $n): array => ['id' => (string) $n, 'description' => 'Genre '.$n], range(0, 12)),
                    'categories' => array_map(fn (int $n): array => ['id' => $n, 'description' => 'Category '.$n], range(0, 30 + $i)),
                    'platforms' => ['windows' => true, 'mac' => true, 'linux' => true], 'publishers' => array_fill(0, 8, 'Publisher Studio'),
                    'developers' => array_fill(0, 16, 'Developer Studio'),
                ],
            ];
        }
        // Non-gating: date forms outside the iTunes API's ISO-8601 UTC releaseDate.
        foreach ($workloads['uncommon'] as $i => $input) {
            $input['itunes']['releaseDate'] = ['September 14, 2009', null, '2009', '2009-09-14 07:00:00'][$i];
            $workloads['fallback'][] = $input;
        }
        $report = [
            'protocol' => 'seed 11010; 1024 warmups; 5 blocks of 4096 randomized paired single calls; nearest-rank p99; no memoization; output equality before timing',
            'baseline_commit' => trim((string) shell_exec('git rev-parse HEAD')),
            'php' => PHP_VERSION, 'os' => php_uname(), 'ffi' => ini_get('ffi.enable'), 'locale' => setlocale(LC_CTYPE, '0'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'rust_profile' => 'release; opt-level=3; LTO; codegen-units=1', 'rustc' => trim((string) shell_exec('rustc --version')),
            'hardware' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string hw.logicalcpu hw.memsize')),
            'resource_limits' => 'host process; no imposed CPU or memory limit', 'library_resolution_ns' => $resolutionNs, 'hashes' => [], 'results' => [],
        ];
        foreach (['app/Services/GoogleBooksService.php', 'app/Services/OpenLibraryService.php', 'app/Services/ItunesService.php',
            'app/Support/Data/Factories/SteamGameDataFactory.php', 'rust/pipeline-ffi/src/metadata.rs', 'tests/Unit/RustBookItunesSteamNormalizerParityTest.php',
            'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $file) {
            $report['hashes'][$file] = hash_file('sha256', $file);
        }
        $stats = static function (array $ns): array {
            sort($ns);

            return ['mean_ns' => array_sum($ns) / count($ns), 'p99_ns' => $ns[(int) ceil(count($ns) * 0.99) - 1]];
        };
        foreach ($workloads as $workload => $inputs) {
            $count = count($inputs);
            $pairs = [
                'buildSearchQuery' => [fn (int $i): string => $google->buildSearchQueryPhp(...$inputs[$i]['query']), fn (int $i): string => $google->buildSearchQueryNative(...$inputs[$i]['query'])],
                'google.normalizeBookResult' => [fn (int $i): array => $google->normalizeBookResultPhp($inputs[$i]['google']), fn (int $i): array => $google->normalizeBookResultNative($inputs[$i]['google'])],
                'openlibrary.normalizeIsbnResult' => [fn (int $i): array => $library->normalizeIsbnResultPhp($inputs[$i]['olIsbn'], '9780132350884'), fn (int $i): array => $library->normalizeIsbnResultNative($inputs[$i]['olIsbn'], '9780132350884')],
                'openlibrary.normalizeSearchResult' => [fn (int $i): array => $library->normalizeSearchResultPhp($inputs[$i]['olSearch']), fn (int $i): array => $library->normalizeSearchResultNative($inputs[$i]['olSearch'])],
                'itunes.getHighResCover' => [fn (int $i): string => $itunes->getHighResCoverPhp($inputs[$i]['cover']), fn (int $i): string => $itunes->getHighResCoverNative($inputs[$i]['cover'])],
                'itunes.normalizeAlbumResult' => [fn (int $i): array => $itunes->normalizeAlbumResultPhp($inputs[$i]['itunes']), fn (int $i): array => $itunes->normalizeAlbumResultNative($inputs[$i]['itunes'])],
                'itunes.normalizeTrackResult' => [fn (int $i): array => $itunes->normalizeTrackResultPhp($inputs[$i]['itunes']), fn (int $i): array => $itunes->normalizeTrackResultNative($inputs[$i]['itunes'])],
                'itunes.normalizeEbookResult' => [fn (int $i): array => $itunes->normalizeEbookResultPhp($inputs[$i]['itunes']), fn (int $i): array => $itunes->normalizeEbookResultNative($inputs[$i]['itunes'])],
                'steam.parseScreenshots' => [fn (int $i): array => $steam->parseScreenshotsPhp($inputs[$i]['steam']), fn (int $i): array => $steam->parseScreenshotsNative($inputs[$i]['steam'])],
                'steam.parseMovies' => [fn (int $i): array => [$steam->parseMoviesPhp($inputs[$i]['steam'], $t), $t], fn (int $i): array => [$steam->parseMoviesNative($inputs[$i]['steam'], $t), $t]],
                'steam.parseGenres' => [fn (int $i): array => $steam->parseGenresPhp($inputs[$i]['steam']), fn (int $i): array => $steam->parseGenresNative($inputs[$i]['steam'])],
                'steam.parseCategories' => [fn (int $i): array => $steam->parseCategoriesPhp($inputs[$i]['steam']), fn (int $i): array => $steam->parseCategoriesNative($inputs[$i]['steam'])],
                'steam.parsePlatforms' => [fn (int $i): array => $steam->parsePlatformsPhp($inputs[$i]['steam']), fn (int $i): array => $steam->parsePlatformsNative($inputs[$i]['steam'])],
                'steam.parsePublisher' => [fn (int $i): ?string => $steam->parsePublisherPhp($inputs[$i]['steam']), fn (int $i): ?string => $steam->parsePublisherNative($inputs[$i]['steam'])],
                'steam.parseDevelopers' => [fn (int $i): array => $steam->parseDevelopersPhp($inputs[$i]['steam']), fn (int $i): array => $steam->parseDevelopersNative($inputs[$i]['steam'])],
            ];
            foreach ($pairs as $method => $calls) {
                if ($workload === 'fallback' && ! str_starts_with($method, 'itunes.normalize')) {
                    continue;
                }
                for ($i = 0; $i < $count; $i++) {
                    self::assertSame($calls[0]($i), $calls[1]($i), $method.' '.$workload.' '.$i);
                }
                $coldStart = hrtime(true);
                $calls[1](0);
                $firstCall = hrtime(true) - $coldStart;
                for ($warm = 0; $warm < 1024; $warm++) {
                    $calls[0]($warm % $count);
                    $calls[1]($warm % $count);
                }
                $samples = [[], []];
                $orders = [];
                $indices = [];
                for ($sample = 0; $sample < 20480; $sample++) {
                    $i = $random->getInt(0, $count - 1);
                    $first = $random->getInt(0, 1);
                    $indices[] = $i;
                    $orders[] = $first;
                    foreach ([$first, 1 - $first] as $side) {
                        $start = hrtime(true);
                        $result = $calls[$side]($i);
                        $samples[$side][] = hrtime(true) - $start;
                        unset($result);
                    }
                }
                $php = $stats($samples[0]);
                $rust = $stats($samples[1]);
                $blocks = [];
                for ($block = 0; $block < 5; $block++) {
                    $p = $stats(array_slice($samples[0], $block * 4096, 4096));
                    $r = $stats(array_slice($samples[1], $block * 4096, 4096));
                    $blocks[] = ['mean_speedup' => $p['mean_ns'] / $r['mean_ns'], 'p99_speedup' => $p['p99_ns'] / $r['p99_ns']];
                }
                $key = $method.'/'.$workload;
                $report['results'][$key] = ['php' => $php, 'rust' => $rust, 'mean_speedup' => $php['mean_ns'] / $rust['mean_ns'],
                    'p99_speedup' => $php['p99_ns'] / $rust['p99_ns'], 'blocks' => $blocks, 'samples' => $samples, 'orders' => $orders,
                    'input_indices' => $indices, 'first_warm_call_ns' => $firstCall];
                file_put_contents($outputPath, json_encode($report + ['inputs_base64' => base64_encode(serialize($workloads))], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                printf("%-40s mean %.2fx p99 %.2fx (php %.0f/%.0f ns, rust %.0f/%.0f ns)\n", $key, $php['mean_ns'] / $rust['mean_ns'], $php['p99_ns'] / $rust['p99_ns'], $php['mean_ns'], $php['p99_ns'], $rust['mean_ns'], $rust['p99_ns']);
            }
        }
    }
}
