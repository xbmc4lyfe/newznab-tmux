<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Predb;
use App\Services\NntmuxUpdateLock;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Feeds\Sources\BoundedHttpFeedSource;
use App\Services\Predb\Feeds\Sources\HttpFeedSource;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest extends TestCase
{
    /** Helper per case: [class, batch method, toggle infix, export, slots]. */
    private const HELPERS = [
        'entry' => [PredbFeedEntry::class, 'summarizeEntryBatch', 'W48Entry', 'nntmux_w48_predb_entry_batch', 30],
        'source' => [PredbFeedSourceFactory::class, 'classifySourceKeyBatch', 'W48SourceKey', 'nntmux_w48_predb_source_key_batch', 26],
        'bounded' => [BoundedHttpFeedSource::class, 'planBoundedResponseBatch', 'W48Bounded', 'nntmux_w48_bounded_response_batch', 29],
        'http' => [HttpFeedSource::class, 'preflightRequestBatch', 'W48Http', 'nntmux_w48_http_preflight_batch', 30],
        'lock' => [NntmuxUpdateLock::class, 'evaluateLockBatch', 'W48Lock', 'nntmux_w48_update_lock_batch', 17],
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure of the benchmark below. */
    private const DEFAULTS = [
        'entry' => true,
        'source' => true,
        'bounded' => true,
        'http' => true,
        'lock' => true,
    ];

    /** Default constant per helper. */
    private const CONSTANTS = [
        'entry' => 'W48_ENTRY_RUST_FFI_DEFAULT',
        'source' => 'W48_SOURCE_KEY_RUST_FFI_DEFAULT',
        'bounded' => 'W48_BOUNDED_RUST_FFI_DEFAULT',
        'http' => 'W48_HTTP_RUST_FFI_DEFAULT',
        'lock' => 'W48_LOCK_RUST_FFI_DEFAULT',
    ];

    /** Strings that exercise trim sets, separators, case, quoting, JSON, header and URL syntax and invalid UTF-8 (no NUL bytes). */
    private const TEXTS = [
        '', ' ', "\t", "\n", "\r", "\r\n", "\x0B", "\x0C", "\x7F", '-', '_', '.', '/', ':', '@', '[', ']', '?', '#', '"', 'W/', '\\', '{', '}',
        '0', '00', '9', '123', '1.5', '1.55', '1.555', '999999', '1234567', 'MB', 'kb', 'Gb', 'TB', 'TV', 'tv-hd', 'X264/HD', 'BLURAYS', 'Flac',
        'predb_me', 'XREL-P2P', 'Scene Archive Sql', '3dsdb', 'srrdb ', 'HTTP/1.1 200 OK', 'HTTP/2 429', 'Content-Length: 5', 'retry-after: 120',
        'Transfer-Encoding: chunked', 'Content-Encoding: br', 'https://', 'http://', 'ftp://', 'u:p@', 'Example.COM', ':8443', ':0', ':65536',
        '[::1]', '"abc"', 'W/"abc"', '"a b"', '{"pid":1}', '[[1]]', '"\\u00e9"', '"\\ud800"', '1e3', 'null', 'true', 'é', "\xc3\xa9t\xc3\xa9",
        "bad\xff", "\xc3", 'The.Show.S01E01.720p.HDTV.x264-GRP', 'Plain Title_Here',
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class, , $toggle]) {
            $class::{'reset'.$toggle.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::HELPERS as $helper => [$class, $method, $toggle]) {
            $reflection = new ReflectionClass($class);
            foreach (['use'.$toggle.'RustFfi', 'uses'.$toggle.'RustFfi', 'reset'.$toggle.'RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
                $this->assertTrue((new ReflectionClass($class))->hasMethod($name), $class.'::'.$name);
            }
            $this->assertTrue($reflection->hasConstant('W48_FFI_CDEF'), $class);
            $this->assertStringContainsString(self::HELPERS[$helper][3], (string) $reflection->getConstant('W48_FFI_CDEF'), $class);
            $default = self::DEFAULTS[$helper];
            $this->assertSame($default, $reflection->getConstant(self::CONSTANTS[$helper]), $class);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}();
            $this->assertTrue($class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(false);
            $this->assertFalse($class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(null);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $class::{'reset'.$toggle.'RustFfi'}();
            $property = lcfirst($toggle);
            $this->assertNull((new ReflectionProperty($class, $property.'RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, $property.'RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
        }

        // The W48 toggles leave the existing toggles of these classes untouched.
        foreach (self::HELPERS as [$class, , $toggle]) {
            $class::{'use'.$toggle.'RustFfi'}(true);
        }
        $this->assertFalse(PredbFeedEntry::usesRustFfi());
        $this->assertFalse(PredbFeedSourceFactory::usesRustFfi());
        $this->assertSame(BoundedHttpFeedSource::RUST_FFI_DEFAULT, BoundedHttpFeedSource::usesRustFfi());
        $this->assertSame(HttpFeedSource::HTTP_FEED_RUST_FFI_DEFAULT, HttpFeedSource::usesHttpFeedRustFfi());
        $this->assertFalse(NntmuxUpdateLock::usesRustFfi());
        $this->assertSame('1MB', PredbFeedEntry::sizeFromMegabytesPhp(1));
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(-3750763034362895579, PredbFeedEntry::w48Fnv(''));
        $this->assertSame(-8821353812377114648, PredbFeedEntry::w48Fnv('foobar'));

        // The vectors of the Rust unit tests, through the PHP reference implementations.
        $entries = $this->entries([' The.Show.S01E01-GRP ', 'the show s01e01-grp', '', 'Plain Title'], ['PreDB', 'predb ', '', 'xrel'],
            ['TV-HD', 'tv', '', 'Apps'], ['1.5MB', '700KB', '', 'bad'], ['12', '0', '', 'x1'], [0, 2, 9, 3]);
        $this->assertSame([4, 3, 1, 1, 1, 2, 49, 2, 1, 1, 2, 0, 0, 0, 1, 0, 0, 0, 1, 1, 2236, 1536, 2, 1, 12, 1, 1, 1, 1],
            array_slice(array_values(PredbFeedEntry::summarizeEntryBatchPhp($entries)), 0, 29));
        $sizes = PredbFeedEntry::summarizeEntryBatchPhp($this->entries(array_fill(0, 5, 'a-b'), array_fill(0, 5, 's'), array_fill(0, 5, null),
            ['1.05GB', '512KB', '999999.99GB', '1.234MB', '1 MB'], array_fill(0, 5, null), array_fill(0, 5, Predb::PRE_NONUKE)));
        $this->assertSame([0, 2, 1101004 + 512 + 1048575989514, 1048575989514], [$sizes['size_missing'], $sizes['size_invalid'], $sizes['size_kb'],
            $sizes['max_size_kb']]);
        $categories = PredbFeedEntry::summarizeEntryBatchPhp($this->entries(array_fill(0, 6, 'x'), array_fill(0, 6, ''),
            ['', 'tv-hd', 'X264/HD', 'Flac', '-TV', 'BLURAYS'], array_fill(0, 6, ''), array_fill(0, 6, ''), array_fill(0, 6, 0)));
        $this->assertSame([1, 1, 1, 1, 0, 0, 0, 0, 2], array_slice(array_values($categories), 9, 9));

        $source = PredbFeedSourceFactory::classifySourceKeyBatchPhp(['srrdb', ' XREL-P2P ', 'srrdb', '', 'nope', 'Scene Archive Sql']);
        $this->assertSame([6, 1, 4, 1, 2, 2, 1, 3, 2, 0, 1, 0, 0, 0, 0, 0, 2, 1, 1, 0, 0, 2, 4, (1 << 5) | (1 << 7) | (1 << 33), 39],
            array_slice(array_values($source), 0, 25));
        $this->assertSame(-1, PredbFeedSourceFactory::classifySourceKeyBatchPhp([])['first_unknown_index']);
        // Every key make() builds resolves exactly, in availableKeysPhp() order.
        $all = PredbFeedSourceFactory::classifySourceKeyBatchPhp((new PredbFeedSourceFactory)->availableKeysPhp());
        $this->assertSame([34, 34, 34, 0, (1 << 34) - 1], [$all['exact'], $all['distinct'], $all['resolved'], $all['unknown'], $all['source_mask']]);

        $bounded = BoundedHttpFeedSource::planBoundedResponseBatchPhp([
            "HTTP/1.1 200 OK\r\nContent-Length: 10\r\nContent-Encoding: GZIP\r\n",
            "HTTP/1.1 503\r\nRetry-After: 120\r\nbroken\r\n: x\r\n",
            "HTTP/1.1 200\r\nTransfer-Encoding: Chunked\r\nContent-Encoding: br",
            'garbage',
            "HTTP/1.1 200\nContent-Length: 5\nContent-Length: 6",
        ], [4, 0, 0, 0, 200], 100);
        $this->assertSame([5, 1, 1, 1, 1, 0, 1, 0, 0, 3, 0, 0, 1, 1, 1, 1, 1, 0, 1, 10, 97, 96, 1, 120, 120, 0, 7, 2],
            array_slice(array_values($bounded), 0, 28));

        $http = HttpFeedSource::preflightRequestBatchPhp(
            ['https://u:p@Example.COM:8443/a?b', 'http://[::1]:80/', 'http://10.0.0.1', 'ftp://x', 'http://-a.b', 'https://example.com/feed'],
            ['W/"abc"', '"x"', '"a b"', '', '', '"x"'], ['"abc"', '"x"', '', '', '', null], [200, 304, 500, 200, 200, 200], [50, 0, 0, 1, 1, 0], 100);
        $this->assertSame([6, 4, 1, 1, 2, 1, 1, 1, 1, 1, 3, 35, 2, 1, 0, 1, 0, 0, 2, 1, 1, 2, 2, 1, 0, 1, 1, 0, 50],
            array_slice(array_values($http), 0, 29));

        $lock = NntmuxUpdateLock::evaluateLockBatchPhp([
            '{"pid":10,"started_at":1000}',
            '{"pid":11,"started_at":100,"ttl":50}',
            '{"pid":12,"started_at":1100,"host":"a"}',
            '{"pid":13,"started_at":1500}',
            '{"pid":0}',
            '{"host":"a"}',
            'nope',
        ], 12, 1100, 600);
        $this->assertSame([7, 1, 1, 1, 0, 0, 1, 1, 1, 1, 1, 2, 1, 1000, 500], array_slice(array_values($lock), 0, 15));
        $depth = NntmuxUpdateLock::evaluateLockBatchPhp([str_repeat('[', 15).'1'.str_repeat(']', 15), str_repeat('[', 16).'1'.str_repeat(']', 16),
            '{"p\u0069d":1,"pid":7,"host":" h\u00e9 ","started_at":9223372036854775808}'], 1, 2000, 60);
        $this->assertSame([3, 1, 1, 0, 0, 1], array_slice(array_values($depth), 0, 6));

        foreach (array_keys(self::HELPERS) as $helper) {
            $summary = $this->callHelper($helper, 'Php', $this->emptyArgs($helper));
            $this->assertSame(0, $summary['total'], $helper);
        }
    }

    public function test_empty_common_uncommon_and_edge_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        foreach (array_keys(self::HELPERS) as $helper) {
            $this->assertParity($helper, $this->emptyArgs($helper));
            $this->assertParity($helper, $this->commonArgs($helper, new Randomizer(new Mt19937(4821))));
            $this->assertParity($helper, $this->uncommonArgs($helper, new Randomizer(new Mt19937(4822))));
        }

        $count = count(self::TEXTS);
        $nukes = [0, 1, 2, 3, 4, 5, 6, -1, PHP_INT_MAX, PHP_INT_MIN];
        $texts = self::TEXTS;
        $shifted = [...array_slice($texts, 7), ...array_slice($texts, 0, 7)];
        // Entries: every text in every field, plus sizes, counts and categories at their boundaries.
        $this->assertParity('entry', [$this->entries($texts, $shifted, $texts, $texts, $texts, array_map(static fn (int $i): int => $nukes[$i % 10],
            range(0, $count - 1)))]);
        $sizes = ['1MB', '1.5mb', '1.05GB', '999999.99GB', '0KB', '000001.0Mb', '1.MB', '.5MB', '1 MB', '1TB', '1M', 'MB', '1MBx', "1MB\n", ' 2GB ',
            '1234567MB', '1.234MB', '1e3MB', '+1MB', '-1MB'];
        $files = ['0', '000000000', '999999999', '1000000000', '12a', ' 7 ', '-1', '+1', '1.0', "\t3\n", '0x1', '00012'];
        $categories = ['TV', 'tv-hd', 'MOVIES/x', 'X265.HDR', 'ebook_pdf', 'abook:mp3', '0DAY', 'iso', 'XXX-IMGSET', 'Games', 'nsw', 'GAME2', '_TV',
            'BLURAYS', 'Wii U', 'pc:iso', 'mvid', 'BD-25', 'é'];
        $n = max(count($sizes), count($files), count($categories));
        $this->assertParity('entry', [$this->entries(array_map(static fn (int $i): string => 'Title '.($i % 4).'-g', range(1, $n)),
            array_map(static fn (int $i): string => ['a', 'A', ' a ', ''][$i % 4], range(1, $n)),
            array_map(static fn (int $i): string => $categories[$i % count($categories)], range(1, $n)),
            array_map(static fn (int $i): string => $sizes[$i % count($sizes)], range(1, $n)),
            array_map(static fn (int $i): string => $files[$i % count($files)], range(1, $n)), array_fill(0, $n, 2))]);

        // Source keys: every text, every available key in variants, and overlong normalized keys.
        $this->assertParity('source', [self::TEXTS]);
        $variants = [];
        foreach ((new PredbFeedSourceFactory)->availableKeysPhp() as $key) {
            array_push($variants, $key, strtoupper($key), ' '.$key."\t", str_replace('_', '-', $key), str_replace('_', ' ', ucwords($key, '_')), $key.'x');
        }
        $this->assertParity('source', [$variants]);
        $this->assertParity('source', [[str_repeat('a', 32), str_repeat('A', 33), ' seriousaboutsound ', 'SERIOUS-ABOUT-SOUND', '__', 'predb__club']]);

        // Bounded: every text as a status line and a header, with boundary caps and received counts.
        $blocks = [];
        foreach (self::TEXTS as $i => $text) {
            $blocks[] = $text;
            $blocks[] = "HTTP/1.1 200 OK\r\n".$text."\r\nContent-Length: ".$i;
            $blocks[] = 'HTTP/1.0 '.[200, 204, 301, 404, 429, 503, 100][$i % 7]."\r\nRetry-After: ".$text."\r\nContent-Encoding: ".$text;
        }
        foreach ([1, 100, 1048576, 1 << 40, (1 << 40) + 1, PHP_INT_MAX, 0, -5] as $maximum) {
            $received = array_map(static fn (int $i): int => [0, 1, 99, 100, 101, -1, PHP_INT_MAX, PHP_INT_MIN][$i % 8], range(0, count($blocks) - 1));
            $this->assertParity('bounded', [$blocks, $received, $maximum]);
        }
        $this->assertParity('bounded', [[
            'HTTP/1.1 200', 'HTTP/1.1 200 ', 'HTTP/1.1  200', 'HTTP/ 200', 'HTTP/1.1 600', 'HTTP/1.1 2000', 'http/1.1 200', 'HTTP/1.1 099', "HTTP/1.1 200\r\r\r",
            "HTTP/2 200\nContent-Length: 0016\ncontent-length: 16", "HTTP/2 200\nContent-Length: 1234567890123456", "HTTP/2 200\nContent-Length: -1",
            "HTTP/2 200\nTransfer-Encoding: gzip, chunked\nTransfer-Encoding: gzip", "HTTP/2 429\nRetry-After: 1234567890\nRetry-After:", "HTTP/2 503\nRetry-After: 5\nRetry-After: Wed, 21 Oct",
            "HTTP/2 200\n \t: x\n:\nno colon\n\n\n", "HTTP/2 200\nContent-Encoding:  Identity \t", "HTTP/1.1 200 OK\r\nContent-Length: 101",
        ], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 100]);

        // Preflight: every text as a URL suffix, ETag and cached ETag, with every status class and payload boundary.
        $urls = $etags = $cached = $statuses = $payloads = [];
        foreach (self::TEXTS as $i => $text) {
            array_push($urls, $text, 'https://'.$text, 'http://host'.$text.'/p');
            array_push($etags, $text, '"'.$text.'"', 'W/"'.$text.'"');
            array_push($cached, '"'.$text.'"', $text, null);
            array_push($statuses, ...array_slice([200, 304, 429, 0, 503, 404, 302, 600, -1, 299, 500, 599], $i % 10, 3));
            array_push($payloads, $i - 3, 100, 101);
        }
        foreach ([1, 100, 1 << 40, PHP_INT_MAX, -1] as $maximum) {
            $this->assertParity('http', [$urls, $etags, $cached, $statuses, $payloads, $maximum]);
        }
        $edge = ['http://a', 'HTTPS://A.B:443', 'https://a.b:443', 'http://a.b:80', 'http://a.b:', 'http://a.b:00080', 'http://a.b:123456', 'http://[::1]',
            'http://[::1]:', 'http://[::ffff:1.2.3.4]:8080/x?y', 'http://[g::1]', 'http://[]', 'http://[::1', 'http://[::1]x', 'http://u@[::1]', 'http://a@b@c',
            'http://a.b.', 'http://.a', 'http://a-', 'http://'.str_repeat('a', 253), 'http://'.str_repeat('a', 254), 'http://1.2.3.4', 'http://1.2.3.4.5',
            'http://a#?x', 'http://a/?x', 'http://a?', 'x://a://b', '://a', ' http://A.B ', 'http://a b', "http://a\xff"];
        $m = count($edge);
        $this->assertParity('http', [$edge, array_fill(0, $m, '"x"'), array_fill(0, $m, '"x"'), array_fill(0, $m, 200), array_fill(0, $m, 5), 10]);
        $etagEdge = ['"', '""', 'W/""', 'w/"a"', 'W/', 'W/W/"a"', '"a"b"', "\t\"a\" ", "\"\x7f\"", "\"\x80\"", "\"\x21\"", '"a', 'a"', ' ', 'W/ "a"'];
        $k = count($etagEdge);
        $this->assertParity('http', [array_fill(0, $k * 2, 'http://h'), [...$etagEdge, ...array_reverse($etagEdge)], [...$etagEdge, ...$etagEdge],
            [...array_fill(0, $k, 304), ...array_fill(0, $k, 200)], array_fill(0, $k * 2, 1)]);

        // Lock payloads: JSON syntax, depth, escapes, numbers and field types.
        $json = ['{}', '[]', ' [ ] ', "\r\n{}\n", '1', '"x"', 'null', '', '[1,]', '{"a"}', '[01]', '[1.]', '["\ud800"]', '["\udc00"]', "[\"\t\"]", '[TRUE]',
            '{}{}', "[\"\xff\"]", '["\x"]', '{"a":"\ud83d\ude00"}', '["\u00e9\/\b\f\n\r\t\"\\\\"]', '[-0]', '[-]', '[1e5,1E+5,1e-5,-1.5e3]', '[1e]', '{"a":1,}',
            str_repeat('[', 15).str_repeat(']', 15), str_repeat('[', 16).str_repeat(']', 16), str_repeat('{"a":', 15).'1'.str_repeat('}', 15),
            str_repeat('{"a":', 16).'1'.str_repeat('}', 16), '{"pid":"5","started_at":1000}', '{"pid":5.0,"started_at":1000}', '{"pid":true}',
            '{"pid":null,"host":"h"}', '{"pid":-3}', '{"pid":9223372036854775807,"started_at":1}', '{"pid":9223372036854775808}', '{"pid":-9223372036854775808}',
            '{"pid":5,"started_at":0}', '{"pid":5,"started_at":1099511627776}', '{"pid":5,"started_at":1099511627777}', '{"pid":5,"started_at":"1000"}',
            '{"pid":5,"started_at":1000,"ttl":0}', '{"pid":5,"started_at":1000,"ttl":604800}', '{"pid":5,"started_at":1000,"ttl":604801}',
            '{"pid":5,"started_at":1000,"ttl":"60"}', '{"pid":5,"started_at":1000,"ttl":null}', '{"pid":5,"pid":6,"started_at":1000}',
            '{"pid":5,"started_at":1000,"pid":null}', '{"host":["a"]}', '{"host":"  "}', '{"host":"\u0000x"}', '{"ho\u0073t":"B"}', '{"host":"b","host":1}',
            '{"\u0000":1,"":2,"0":3}', '{"pid":5,"started_at":1400}', '{"pid":5,"started_at":1401}', '[{"pid":5}]', '{"x":{"pid":5}}', "{\"pid\":5,\"started_at\":1000\x0B}",
            '{"pid" : 5 , "started_at" : 900 , "host" : "web-1" }', '{"pid":7,"started_at":1100}', '{"pid":7,"started_at":200}'];
        foreach ([[7, 1100, 600], [5, 0, 0], [5, PHP_INT_MAX, PHP_INT_MAX], [5, -1, -1], [8, 1 << 40, 1], [7, 1100, 1 << 32]] as [$pid, $now, $ttl]) {
            $this->assertParity('lock', [$json, $pid, $now, $ttl]);
        }
        $this->assertParity('lock', [self::TEXTS, 1, 1000, 60]);
        $this->assertParity('entry', [['k' => $this->entries(['a-b'], ['s'], [null], [null], [null], [0])[0]]]);
        $this->assertParity('source', [['k' => 'srrdb', 'm' => 'eztv']]);
        $this->assertParity('bounded', [['k' => 'HTTP/1.1 200'], ['z' => 5], 10]);
        $this->assertParity('http', [['a' => 'http://h'], ['b' => '"x"'], ['c' => '"x"'], ['d' => 304], ['e' => 0], 10]);
        $this->assertParity('lock', [['k' => '{"pid":1,"started_at":1}'], 1, 2, 3]);
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4823));
        for ($i = 0; $i < 300; $i++) {
            foreach (array_keys(self::HELPERS) as $helper) {
                $args = $random->getInt(0, 1) === 0 ? $this->commonArgs($helper, $random, $random->getInt(0, 24))
                    : $this->uncommonArgs($helper, $random, $random->getInt(0, 24));
                $this->assertParity($helper, $args);
            }
        }
    }

    public function test_declined_and_malformed_batches_fall_back_to_php(): void
    {
        $this->ffiOrSkip();
        // A NUL byte inside a NUL-joined string no longer splits into the batch, so Rust declines it.
        $declined = [
            'entry' => [$this->entries(['a', "b\0c"], ['s', 's'], [null, null], [null, null], [null, null], [0, 0])],
            'source' => [["srr\0db", 'eztv']],
            'bounded' => [["HTTP/1.1 200\0", 'HTTP/1.1 200'], [0, 0], 100],
            'http' => [['http://a', 'http://b'], ['"x"', "\0"], [null, null], [200, 200], [1, 1], 100],
            'lock' => [['{"pid":1}', "{\"pid\":\0}"], 1, 100, 60],
        ];
        foreach ($declined as $helper => $args) {
            [$class, $method, $toggle] = self::HELPERS[$helper];
            $this->assertNull($class::{$method.'Native'}(...$args), $helper);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $helper);
        }

        // Mismatched parallel lists are rejected identically by every path.
        foreach (['planBoundedResponseBatch', 'planBoundedResponseBatchPhp', 'planBoundedResponseBatchNative'] as $method) {
            $this->assertRejectsMismatch(fn () => BoundedHttpFeedSource::$method(['HTTP/1.1 200'], [1, 2]), $method);
            $this->assertRejectsMismatch(fn () => BoundedHttpFeedSource::$method(['HTTP/1.1 200', 'x'], []), $method);
        }
        foreach (['preflightRequestBatch', 'preflightRequestBatchPhp', 'preflightRequestBatchNative'] as $method) {
            $this->assertRejectsMismatch(fn () => HttpFeedSource::$method(['http://a'], [], [null], [200], [1]), $method);
            $this->assertRejectsMismatch(fn () => HttpFeedSource::$method(['http://a'], [null], [null], [200], [1, 2]), $method);
        }

        // Batches over the native item cap stay in PHP.
        $over = PredbFeedEntry::W48_MAX_ITEMS + 1;
        $this->assertNull(PredbFeedSourceFactory::classifySourceKeyBatchNative(array_fill(0, $over, 'srrdb')));
        $this->assertNull(BoundedHttpFeedSource::planBoundedResponseBatchNative(array_fill(0, $over, 'x'), array_fill(0, $over, 0)));
        $this->assertNull(HttpFeedSource::preflightRequestBatchNative(array_fill(0, $over, 'x'), array_fill(0, $over, null), array_fill(0, $over, null),
            array_fill(0, $over, 200), array_fill(0, $over, 1)));
        $this->assertNull(NntmuxUpdateLock::evaluateLockBatchNative(array_fill(0, $over, '{}'), 1, 1, 1));
        $entry = new PredbFeedEntry('a', 'b');
        $this->assertNull(PredbFeedEntry::summarizeEntryBatchNative(array_fill(0, $over, $entry)));
        // The full native cap still runs natively.
        $this->assertSame(PredbFeedEntry::W48_MAX_ITEMS, PredbFeedSourceFactory::classifySourceKeyBatchNative(
            array_fill(0, PredbFeedEntry::W48_MAX_ITEMS, 'srrdb'))['total'] ?? null);
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (array_keys(self::HELPERS) as $helper) {
            [$class, $method, $toggle] = self::HELPERS[$helper];
            $args = $this->commonArgs($helper, new Randomizer(new Mt19937(4824)), 12);
            (new ReflectionProperty($class, lcfirst($toggle).'RustFfiHandle'))->setValue(null, false);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $this->assertNull($class::{$method.'Native'}(...$args), $helper);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $helper);
            $class::{'reset'.$toggle.'RustFfi'}();
            $this->assertNull((new ReflectionProperty($class, lcfirst($toggle).'RustFfiHandle'))->getValue(), $helper);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        $lockAux = pack('q2', 1, 0);
        // Every export reports -required without writing when the output is too small, then fills it.
        foreach (self::HELPERS as $helper => [$class, , , $symbol, $slots]) {
            $handle = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, constant($class.'::W48_FFI_CDEF'));
            $this->assertInstanceOf(FFI::class, $handle, $helper);
            $aux = $helper === 'lock' ? $lockAux : '';
            FFI::memset($small, ord('Z'), 8);
            $this->assertSame(-$slots * 8, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, 0, $small, 8), $helper);
            $this->assertSame('ZZZZZZZZ', FFI::string($small, 8), $helper);
            $this->assertSame($slots * 8, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, 0, $out, 256), $helper);
            $this->assertSame(-1, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, 0, null, 256), $helper);
            $this->assertSame(-1, $this->callFfi($handle, $symbol, null, 0, $aux, strlen($aux), 0, 0, $out, 256), $helper);
        }
        $entry = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, PredbFeedEntry::W48_FFI_CDEF);
        $this->assertNotNull($entry);
        $symbol = 'nntmux_w48_predb_entry_batch';
        // Reserved parameters, ragged aux tables and short column sets decline.
        $this->assertSame(-1, $this->callFfi($entry, $symbol, '', 0, '', 0, 1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($entry, $symbol, '', 0, '', 0, 0, 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($entry, $symbol, "a\0b\0c\0d\0e", 9, pack('q', 0).'x', 9, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($entry, $symbol, "a\0b\0c\0d", 7, pack('q', 0), 8, 0, 0, $out, 256));
        $this->assertSame(240, $this->callFfi($entry, $symbol, "a\0b\0c\0d\0e", 9, pack('q', 0), 8, 0, 0, $out, 256));
        $source = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, PredbFeedSourceFactory::W48_FFI_CDEF);
        $this->assertNotNull($source);
        $keys = 'nntmux_w48_predb_source_key_batch';
        $this->assertSame(-1, $this->callFfi($source, $keys, 'a', 1, 'x', 1, 1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($source, $keys, 'a', 1, '', 0, -1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($source, $keys, 'a', 1, '', 0, 2, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($source, $keys, 'a', 1, '', 0, 1, 1, $out, 256));
        $lock = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, NntmuxUpdateLock::W48_FFI_CDEF);
        $this->assertNotNull($lock);
        $this->assertSame(-1, $this->callFfi($lock, 'nntmux_w48_update_lock_batch', '{}', 2, pack('q', 1), 8, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($lock, 'nntmux_w48_update_lock_batch', '{}', 2, pack('q3', 1, 1, 1), 24, 0, 0, $out, 256));
        $this->assertSame(136, $this->callFfi($lock, 'nntmux_w48_update_lock_batch', '{}', 2, pack('q2', 1, 1), 16, 0, 0, $out, 256));
        $http = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, HttpFeedSource::W48_FFI_CDEF);
        $this->assertNotNull($http);
        $this->assertSame(-1, $this->callFfi($http, 'nntmux_w48_http_preflight_batch', "a\0b\0c", 5, pack('q', 200), 8, 1, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($http, 'nntmux_w48_http_preflight_batch', "a\0b\0c", 5, pack('q2', 200, 1), 16, 1, 1, $out, 256));
        $this->assertSame(240, $this->callFfi($http, 'nntmux_w48_http_preflight_batch', "a\0b\0c", 5, pack('q2', 200, 1), 16, 1, 0, $out, 256));
        // An output overlapping an input declines without writing to it.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, 'srrdb', 5);
        $this->assertSame(-1, $this->callFfi($source, $keys, FFI::addr($shared[0]), 5, FFI::addr($shared[0]), 0, 1, 0, FFI::addr($shared[4]), 208));
        $this->assertSame('srrdb', FFI::string($shared, 5));
        $this->assertSame(208, $this->callFfi($source, $keys, FFI::addr($shared[0]), 5, FFI::addr($shared[0]), 0, 1, 0, FFI::addr($shared[8]), 208));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including the PHP-side type
        // checks, packing, FFI call and counter decoding (release dylib, 5 warmups, 601 paired samples per helper and
        // workload, 20 iterations per common sample and 10 per uncommon sample).
        // Common = one 64-96 item batch of typical inputs; uncommon = 128 mixed, long, malformed and duplicate inputs.
        // Speedup = PHP latency / Rust latency, ranges over 5 rounds (PHP 8.5.12 NTS, release dylib, Apple Silicon, shared host at
        // load average ~9.5-11):
        // - entry:   common mean 2.21-2.31x / p99 1.70-2.17x, uncommon mean 2.05-2.14x / p99 1.85-2.04x
        // - source:  common mean 5.78-6.14x / p99 4.13-5.23x, uncommon mean 6.08-7.21x / p99 4.80-10.45x
        // - bounded: common mean 6.29-7.08x / p99 5.67-6.62x, uncommon mean 7.90-8.51x / p99 6.71-8.64x
        // - http:    common mean 5.33-5.51x / p99 4.26-4.93x, uncommon mean 4.49-4.90x / p99 3.53-4.99x
        // - lock:    common mean 1.97-2.17x / p99 1.97-2.61x, uncommon mean 2.75-3.05x / p99 2.50-3.24x
        // With 201 samples the p99 (third-slowest sample) was too noisy on this host: entry's uncommon p99 fell below 1.5x
        // in 3 of 10 rounds while its mean stayed ~2x, so the benchmark uses 601 samples.
        // Every helper clears 1.5x on every measure in every round and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4825));
        $warmup = 5;
        $samples = 601;
        $sink = 0;
        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method, $toggle]) {
            $workloads = [
                'common' => [$this->commonArgs($helper, new Randomizer(new Mt19937(4826))), 20],
                'uncommon' => [$this->uncommonArgs($helper, new Randomizer(new Mt19937(4827))), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $native = $class::{$method.'Native'}(...$args);
                $this->assertNotNull($native, $helper.' '.$name.' must run natively');
                $this->assertSame($class::{$method.'Php'}(...$args), $native, $helper.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($helper, $args, false) + $this->runWorkload($helper, $args, true);
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $class::{'use'.$toggle.'RustFfi'}($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$method(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::{'reset'.$toggle.'RustFfi'}();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustPredbEntryFactoryBoundedHttpUpdateLockW48 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class, , $toggle]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            $default = (new ReflectionClass($class))->getConstant(self::CONSTANTS[$helper]);
            if (! $qualifies) {
                $this->assertFalse($default, $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::{'uses'.$toggle.'RustFfi'}(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if ($default === true) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $helper, array $args): void
    {
        [$class, $method, $toggle] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}(...$args);
        $native = $class::{$method.'Native'}(...$args);
        $context = $helper.': '.json_encode($this->describe($args));
        $this->assertNotNull($native, $helper.' declined: '.$context);
        $this->assertSame($expected, $native, $context);
        $class::{'use'.$toggle.'RustFfi'}(true);
        $this->assertSame($expected, $class::$method(...$args), $helper.' rust dispatcher');
        $class::{'use'.$toggle.'RustFfi'}(false);
        $this->assertSame($expected, $class::$method(...$args), $helper.' php dispatcher');
        $class::{'reset'.$toggle.'RustFfi'}();
    }

    private function assertRejectsMismatch(callable $call, string $context): void
    {
        try {
            $call();
            $this->fail($context.' accepted mismatched lists');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('same length', $exception->getMessage(), $context);
        }
    }

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $sources
     * @param  list<string|null>  $categories
     * @param  list<string|null>  $sizes
     * @param  list<string|null>  $files
     * @param  list<int>  $nuked
     * @return list<PredbFeedEntry>
     */
    private function entries(array $titles, array $sources, array $categories, array $sizes, array $files, array $nuked): array
    {
        return array_map(static fn (string $title, string $source, ?string $category, ?string $size, ?string $count, int $nuke): PredbFeedEntry => new PredbFeedEntry(
            $title, $source, $category, $size, $count, nuked: $nuke), $titles, $sources, $categories, $sizes, $files, $nuked);
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, int>
     */
    private function callHelper(string $helper, string $variant, array $args): array
    {
        [$class, $method] = self::HELPERS[$helper];

        return $class::{$method.$variant}(...$args);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function runWorkload(string $helper, array $args, bool $useRust): int
    {
        [$class, $method, $toggle] = self::HELPERS[$helper];
        $class::{'use'.$toggle.'RustFfi'}($useRust);

        return count($class::$method(...$args));
    }

    /**
     * @return list<mixed>
     */
    private function emptyArgs(string $helper): array
    {
        return match ($helper) {
            'bounded' => [[], [], 1048576],
            'http' => [[], [], [], [], [], 1048576],
            'lock' => [[], 1, 1_700_000_000, 3600],
            default => [[]],
        };
    }

    /**
     * Typical batches: 96 scene entries from one feed page, 64 configured feed keys, 64 feed response headers, 96 feed
     * requests and 64 update-lock payloads.
     *
     * @return list<mixed>
     */
    private function commonArgs(string $helper, Randomizer $random, ?int $count = null): array
    {
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $hex = static fn (int $bytes): string => bin2hex($random->getBytes($bytes));
        if ($helper === 'entry') {
            $count ??= 96;
            $titles = $sources = $categories = $sizes = $files = $nuked = [];
            for ($i = 0; $i < $count; $i++) {
                $titles[] = $pick(['The.Show', 'Some.Movie.2026', 'Artist-Album', 'Game.Title', 'App.v1.2'])
                    .'.S0'.$random->getInt(1, 9).'E'.$random->getInt(10, 24).'.1080p.WEB.H264-'.strtoupper($hex(3));
                $sources[] = $pick(['predb.net', 'predb.club', 'xrel', 'srrdb']);
                $categories[] = $pick(['TV-HD', 'TV-SD', 'X264-HD', 'MP3', 'FLAC', 'GAMES-PC', 'APPS', 'EBOOK', null]);
                $sizes[] = $random->getInt(0, 4) === 0 ? null : $random->getInt(1, 9999).'.'.$random->getInt(10, 99).$pick(['MB', 'GB', 'KB']);
                $files[] = $random->getInt(0, 3) === 0 ? null : (string) $random->getInt(1, 120);
                $nuked[] = $random->getInt(0, 9) === 0 ? Predb::PRE_NUKED : Predb::PRE_NONUKE;
            }

            return [$this->entries($titles, $sources, $categories, $sizes, $files, $nuked)];
        }
        if ($helper === 'source') {
            $keys = (new PredbFeedSourceFactory)->availableKeysPhp();

            return [array_map(static fn (): string => $pick($keys), array_fill(0, $count ?? 64, 0))];
        }
        if ($helper === 'bounded') {
            $count ??= 64;
            $blocks = $received = [];
            for ($i = 0; $i < $count; $i++) {
                $length = $random->getInt(1000, 900_000);
                $blocks[] = 'HTTP/1.1 '.$pick([200, 200, 200, 200, 304, 503])." OK\r\nDate: Sun, 25 Oct 2026 12:00:00 GMT\r\nContent-Type: application/rss+xml; charset=UTF-8\r\n"
                    .'Content-Length: '.$length."\r\n".($random->getInt(0, 1) === 0 ? "Content-Encoding: gzip\r\n" : '')
                    ."Cache-Control: max-age=60\r\nETag: \"".$hex(8)."\"\r\nServer: nginx\r\nVary: Accept-Encoding\r\nConnection: keep-alive\r\n";
                $received[] = $random->getInt(0, $length);
            }

            return [$blocks, $received, 1048576];
        }
        if ($helper === 'http') {
            $count ??= 96;
            $urls = $etags = $cached = $statuses = $payloads = [];
            for ($i = 0; $i < $count; $i++) {
                $urls[] = $pick(['https://predb.net/api/', 'https://api.predb.club/v1/', 'https://www.srrdb.com/api/search/', 'https://api.xrel.to/v2/release/latest.json',
                    'https://predb.me/?rss=1', 'https://eztvx.to/ezrss.xml']).'?page='.$random->getInt(1, 50);
                $tag = '"'.$hex(6).'"';
                $statuses[] = $pick([200, 200, 200, 304, 429, 503]);
                $etags[] = $tag;
                $cached[] = $random->getInt(0, 2) === 0 ? $tag : ($random->getInt(0, 1) === 0 ? null : '"'.$hex(6).'"');
                $payloads[] = $random->getInt(0, 600_000);
            }

            return [$urls, $etags, $cached, $statuses, $payloads, 1048576];
        }
        $count ??= 64;
        $payloads = [];
        for ($i = 0; $i < $count; $i++) {
            $lock = ['pid' => $random->getInt(100, 65000), 'started_at' => 1_700_000_000 - $random->getInt(0, 7200), 'host' => 'web-'.$random->getInt(1, 4)];
            if ($random->getInt(0, 3) === 0) {
                $lock['ttl'] = 1800;
            }
            $payloads[] = (string) json_encode($lock + ['command' => 'nntmux:update', 'version' => '2.'.$random->getInt(0, 9)]);
        }

        return [$payloads, $random->getInt(100, 65000), 1_700_000_000, 3600];
    }

    /**
     * Unusual batches of 128 items: blank, spaced, duplicate and long titles with odd categories, sizes and counts;
     * cased, padded, dashed and unknown feed keys; malformed, chunked, conflicting and throttled responses; invalid,
     * IPv6, userinfo and custom-port URLs with weak or broken ETags; and malformed, nested, escaped and mistyped JSON.
     *
     * @return list<mixed>
     */
    private function uncommonArgs(string $helper, Randomizer $random, ?int $count = null): array
    {
        $count ??= 128;
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $text = static function () use ($random, $pick): string {
            $out = '';
            for ($i = $random->getInt(0, 6); $i > 0; $i--) {
                $out .= $random->getInt(0, 1) === 0 ? $pick(self::TEXTS) : $pick(['a', 'Z', '9', ' ', "\t", '/', '-', '.', ':', '"', "\xc3\xa9", "\xff"]);
            }

            return $out;
        };
        if ($helper === 'entry') {
            $titles = $sources = $categories = $sizes = $files = $nuked = [];
            for ($i = 0; $i < $count; $i++) {
                $titles[] = match ($i % 6) {
                    0 => '',
                    1 => ' Spaced Title_'.($i % 5).' ',
                    2 => $titles[$random->getInt(0, max(0, $i - 1))] ?? 'dup',
                    3 => str_repeat('Long.Name.', $random->getInt(10, 40)).'-GRP',
                    4 => $text(),
                    default => 'Title.'.$i.'-G',
                };
                $sources[] = $pick(['', ' ', 'PreDB', 'predb', $text()]);
                $categories[] = $pick([null, '', 'tv', 'BLURAYS', '-x', 'Wii', 'MOVIES_x', $text()]);
                $sizes[] = $pick([null, '', '1MB', '1.5GB', '999999.99GB', '1.234MB', '1 MB', '1TB', $text()]);
                $files[] = $pick([null, '', '0', '000', '999999999', '1000000000', '12a', ' 7 ', $text()]);
                $nuked[] = $pick([0, 1, 2, 3, 4, 5, 6, -1, PHP_INT_MAX]);
            }

            return [$this->entries($titles, $sources, $categories, $sizes, $files, $nuked)];
        }
        if ($helper === 'source') {
            $keys = (new PredbFeedSourceFactory)->availableKeysPhp();
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $key = $pick($keys);
                $out[] = match ($i % 6) {
                    0 => strtoupper($key),
                    1 => ' '.str_replace('_', '-', $key)."\t",
                    2 => '',
                    3 => $text(),
                    4 => str_repeat($key, $random->getInt(2, 4)),
                    default => $key,
                };
            }

            return [$out];
        }
        if ($helper === 'bounded') {
            $blocks = $received = [];
            for ($i = 0; $i < $count; $i++) {
                $blocks[] = match ($i % 7) {
                    0 => 'HTTP/2 '.$pick([429, 503, 500])."\r\nRetry-After: ".$pick(['120', '0', 'Wed, 21 Oct 2026 07:28:00 GMT', '', '1234567890'])."\r\nContent-Length: 0\r\n",
                    1 => "HTTP/1.1 200 OK\r\nTransfer-Encoding: ".$pick(['chunked', 'gzip, chunked', 'identity'])."\r\nContent-Encoding: ".$pick(['br', 'gzip', 'Identity', ''])."\r\n",
                    2 => "HTTP/1.1 200 OK\r\nContent-Length: 10\r\nContent-Length: ".$pick(['10', '11', 'x', '-1'])."\r\n",
                    3 => $text(),
                    4 => "HTTP/1.1 200 OK\r\nbroken header\r\n: empty\r\n\r\n".str_repeat('X-Pad: '.$text()."\r\n", $random->getInt(1, 20)),
                    5 => 'HTTP/1.0 '.$pick([100, 204, 301, 404, 418])." Status\nContent-Length: ".$random->getInt(0, 5_000_000),
                    default => "HTTP/1.1 200 OK\r\nContent-Length: ".$random->getInt(0, 2_000_000)."\r\nContent-Encoding: gzip\r\n",
                };
                $received[] = $pick([0, -1, 1048576, 1048577, $random->getInt(0, 2_000_000), PHP_INT_MAX]);
            }

            return [$blocks, $received, $pick([1048576, 1, 100, 1 << 40])];
        }
        if ($helper === 'http') {
            $urls = $etags = $cached = $statuses = $payloads = [];
            for ($i = 0; $i < $count; $i++) {
                $urls[] = match ($i % 8) {
                    0 => 'https://user:pass@Feed.Example.COM:8443/rss?x=1',
                    1 => 'http://[2001:db8::'.$random->getInt(1, 9).']:'.$pick(['80', '8080', '0', '']).'/p',
                    2 => $pick(['ftp://x', 'x', '', '://a', 'https://', 'http://-bad.example', 'http://a_b']),
                    3 => 'http://10.0.0.'.$random->getInt(1, 9).':'.$random->getInt(1, 70000),
                    4 => $text(),
                    5 => ' HTTPS://'.str_repeat('a', $random->getInt(1, 300)).'.example ',
                    default => 'https://predb.net/api/?page='.$i,
                };
                $etags[] = $pick([null, '', '"x"', 'W/"x"', '"a b"', '"', $text()]);
                $cached[] = $pick([null, '"x"', 'W/"x"', '"y"', $text()]);
                $statuses[] = $pick([0, 200, 204, 299, 304, 404, 429, 500, 503, 600, -1]);
                $payloads[] = $pick([0, -5, 1, 1048576, 1048577, PHP_INT_MAX, $random->getInt(1, 2_000_000)]);
            }

            return [$urls, $etags, $cached, $statuses, $payloads, $pick([1048576, 1, 1 << 40])];
        }
        $payloads = [];
        for ($i = 0; $i < $count; $i++) {
            $payloads[] = match ($i % 8) {
                0 => $text(),
                1 => '{"pid":'.$pick(['"5"', '5.0', 'true', 'null', '-3', '0', '9223372036854775808', '77']).',"started_at":'.$pick(['1', '"1"', '1699999000', '1700000400', '1700000301', '0']).'}',
                2 => str_repeat('[', $random->getInt(10, 20)).str_repeat(']', $random->getInt(10, 20)),
                3 => '{"pid":42,"started_at":1699990000,"ttl":'.$pick(['0', '60', '604800', '604801', '"60"', 'null']).',"host":'.$pick(['"h\u00e9"', '" "', '["a"]', '"\ud800"', '"a"']).'}',
                4 => '{"p\u0069d":1,"pid":'.$random->getInt(1, 9).',"started_at":1699999999,"nested":{"a":[1,2,{"b":null}]},"s":"'.str_repeat('\\n', $random->getInt(0, 30)).'"}',
                5 => '{"pid":7,"started_at":1700000000,}',
                6 => " \r\n{\"host\":\"web\",\"pid\":7,\"started_at\":1699996400}\t",
                default => (string) json_encode(['pid' => $random->getInt(1, 99), 'started_at' => 1_700_000_000 - $random->getInt(-500, 9000), 'host' => 'h'.$i % 3]),
            };
        }

        return [$payloads, 7, 1_700_000_000, $pick([3600, 1, 600])];
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_array($value) => array_map($this->describe(...), $value),
            $value instanceof PredbFeedEntry => $this->describe([$value->title, $value->source, $value->category, $value->size, $value->files, $value->nuked]),
            default => $value,
        };
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, PredbFeedEntry::W48_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 48.2 exports is not available.');
        }

        return $ffi;
    }
}
