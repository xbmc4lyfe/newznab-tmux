<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IRCClient;
use App\Services\IRCScraper;
use App\Services\XrefService;
use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 44.1 pure CPU IRC line, bot message, Xref, yEnc frame and yEnc metadata batch summaries.
 * The opt-in benchmark retains raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W441_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIrcClientScraperXrefYencFrameServiceW44ParityTest.php
 */
final class RustIrcClientScraperXrefYencFrameServiceW44ParityTest extends TestCase
{
    /** @var list<array{class-string, string, string, list<string>}> */
    private const array HELPERS = [
        [IRCClient::class, 'summarizeInboundLineBatch', 'nntmux_w441_irc_client_line_batch', ['line', 'message']],
        [IRCScraper::class, 'summarizeBotMessageBatch', 'nntmux_w441_irc_scraper_bot_batch', ['message', 'line']],
        [XrefService::class, 'summarizeXrefBatch', 'nntmux_w441_xref_batch', ['xref', 'Xref']],
        [ArticleFrame::class, 'summarizeArticleFrameBatch', 'nntmux_w441_yenc_article_frame_batch', ['body', 'text']],
        [YencService::class, 'summarizeArticleMetadataBatch', 'nntmux_w441_yenc_metadata_batch', ['body', 'text']],
    ];

    /**
     * Benchmark-qualified defaults. Nine paired runs (3000 samples each, Apple M5, PHP 8.5 CLI without opcache,
     * rustc 1.99.1 release build) gave min common mean/p99 and uncommon mean/p99 speedups of: IRC lines
     * 4.24/2.61/2.32/2.34, bot messages 5.57/3.12/4.91/4.16, Xref 4.59/2.66/2.52/2.43, article frames
     * 5.54/3.27/4.83/3.37 and yEnc metadata 5.13/3.53/4.80/3.98, so every helper defaults to Rust.
     */
    private const array DEFAULTS = [true, true, true, true, true];

    /** Earlier public and protected helpers that must stay intact. */
    private const array LEGACY_METHODS = [
        IRCClient::class => ['parseInboundLine', 'parseInboundLinePhp', '_stripControlCharacters', '_stripControlCharactersPhp', '_readFrameTooLong'],
        IRCScraper::class => ['parseBotMessage', 'parseBotMessagePhp', 'parseBotMessageFieldsPhp'],
        XrefService::class => ['extractTokens', 'extractTokensPhp', 'extractGroupNamesPhp', 'diffNewTokensPhp', 'extractGroupNamesBatch'],
        ArticleFrame::class => ['parse', 'parsePhp'],
        YencService::class => ['decode', 'decodeIgnore', 'extractMetadata', 'isYencEncoded'],
    ];

    private const int SEED = 4412026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW441RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new \ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW441RustFfi', 'usesW441RustFfi', 'resetW441RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertSame($class, $reflection->getMethod($name)->getDeclaringClass()->getName(), $class.'::'.$name.' must be declared by the class itself.');
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W441_RUST_FFI_DEFAULT'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
        self::assertTrue((new \ReflectionClass(ArticleFrame::class))->isReadOnly(), 'ArticleFrame stays a readonly value object.');
    }

    private const string SINGLE = "=ybegin line=128 size=3 name=a b.bin\r\nabc\r\n=yend size=3 crc32=352441C2\r\n";

    private const string MULTI = "=ybegin part=1 total=2 line=128 size=9 name=x\r\n=ypart begin=1 end=3\r\nab=\r\n=yend size=3 part=1 pcrc32=zz\r\n";

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        $articles = [
            self::SINGLE,
            ['body' => self::MULTI],
            "=ybegin line=1 size=99999999999999999999 name=n part=4\nx\n=yend\n",
            ['text' => "=ybegin line=1 size=1\nx\n=yend crc32=1\n"],
            "=ybegin line=1 size=1 name=a\n=ybegin line=2 size=2 name=b part=1\nyy\n=yend PCRC32=ABC\n",
            'not yenc',
            '',
            null,
            5,
        ];

        return [
            [[
                'PING :irc.example.net',
                ['line' => "PING\t :x\n"],
                'PING :',
                ":nick!user@host PRIVMSG #pre :\x0304NEW: [DT: x]\x0f",
                ['message' => ':nick!user@host NOTICE #pre :hi'],
                '',
                null,
                ['line' => null, 'message' => str_repeat('x', 8702)],
                ":a!b PRIVMSG #c :\x02bold\x02",
                42,
                "\x03",
            ], ['total_lines' => 11, 'empty_line_count' => 2, 'ping_count' => 2, 'privmsg_count' => 2, 'control_char_count' => 3, 'oversized_line_count' => 1]],
            [[
                'NEW: [DT: 2024-01-01 00:00:00] [TT: Some.Title-GRP] [SC: src] [CT: TV] [RQ: 123:alt.binaries.x] [SZ: 1GB] [FL: 10]',
                ['message' => 'upd: [DT: t] [TT: T] [SC: s] [CT: c] [RQ: N/A] [SZ: 1] [FL: 2] [FN: a.nfo]'],
                'NUK: [DT: t] [TT: T] [SC: s] [CT: c] [RQ: N/A] [SZ: 1] [FL: 2] [NUKED: bad]',
                'NUK: [DT: t] [TT: T] [SC: s] [CT: c] [RQ: N/A] [SZ: 1] [FL: 2] [nuke: odd]',
                'NEW: nothing',
                '',
                null,
                ['line' => 'UPD: [DT: t] [TT: T] [SC: s] [CT: c] [RQ: 7:g] [SZ: 1] [FL: 2] [FN: f.rar] [MODNUKED: r]'],
                'NEW: [DT: t] [TT: '.str_repeat('Long.', 120).'] [SC: s] [CT: c] [RQ: N/A] [SZ: 1] [FL: 2]',
            ], ['total_messages' => 9, 'empty_message_count' => 2, 'bot_message_count' => 6, 'request_id_count' => 2, 'filename_count' => 2, 'nuke_tag_count' => 3, 'nuke_status_count' => 2, 'update_count' => 2]],
            [[
                'news.example.com alt.binaries.teevee:123 alt.binaries.hdtv:5',
                ['xref' => 'alt.binaries.teevee alt.binaries.teevee:9'],
                '0',
                '',
                null,
                'junk',
                ['Xref' => 'alt.binaer.x'],
                'a.binarios.foo:12  alt.binaries.bar',
                7,
            ], ['total_xrefs' => 9, 'empty_xref_count' => 3, 'tokenized_count' => 4, 'numbered_token_count' => 2, 'multi_group_count' => 1, 'rejected_token_count' => 4]],
            [$articles, ['total_articles' => 9, 'empty_article_count' => 2, 'frame_count' => 4, 'part_line_count' => 1, 'header_part_count' => 1, 'crc32_trailer_count' => 1, 'pcrc32_trailer_count' => 2]],
            [$articles, ['total_articles' => 9, 'empty_article_count' => 2, 'frame_count' => 4, 'valid_size_count' => 3, 'valid_line_count' => 4, 'multipart_count' => 1, 'valid_checksum_count' => 1, 'dangling_escape_count' => 1, 'split_escape_count' => 1]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            [$class, $method] = self::HELPERS[$i];
            self::assertSame($expected, $class::{$method.'Php'}($input), $class);
            self::assertSame($expected, $class::{$method.'Native'}($input), $class);
            $empty = array_fill_keys(array_keys($expected), 0);
            foreach ([false, true] as $enabled) {
                $class::useW441RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
    }

    public function test_php_summaries_agree_with_the_existing_parsers(): void
    {
        $articles = self::literalCases()[3][0];
        $service = new YencService;
        foreach ($articles as $article) {
            if (! is_string($article) || $article === '') {
                continue;
            }
            // The production parse() (Rust for long texts, PHP otherwise) and parsePhp() must agree.
            self::assertEquals(ArticleFrame::parse($article), ArticleFrame::parsePhp($article));
            self::assertSame(ArticleFrame::parse($article) !== null, $service->isYencEncoded($article));
        }
        $xref = new XrefService(false);
        self::assertSame(['alt.binaries.teevee:123', 'alt.binaries.hdtv:5'], $xref->extractTokensPhp('news.example.com alt.binaries.teevee:123 alt.binaries.hdtv:5'));
        self::assertSame(['alt.binaries.teevee'], $xref->extractGroupNamesPhp('alt.binaries.teevee alt.binaries.teevee:9'));
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW441RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW441RustFfi());
            $class::useW441RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW441RustFfi();
            foreach (self::HELPERS as [$other]) {
                // IRCScraper extends IRCClient yet keeps its own toggle.
                self::assertSame($class === $other, $other::usesW441RustFfi(), $class.' toggled '.$other);
            }
            $class::useW441RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW441RustFfi());
            $class::useW441RustFfi(false);
            self::assertFalse($class::usesW441RustFfi());
        }
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $cache->getValue();
        self::assertIsArray($saved);
        try {
            foreach (self::HELPERS as $i => [$class, $method]) {
                $class::resetW441RustFfi();
                $class::useW441RustFfi(true);
                // A cached failed resolution stands in for a missing or older library.
                $cache->setValue(null, [...$saved, self::cacheKey($class) => false]);
                [$input, $expected] = self::literalCases()[$i];
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                self::assertFalse(self::cachedFfi($class));
            }
        } finally {
            $cache->setValue(null, $saved);
        }
    }

    private static function cacheKey(string $class): string
    {
        return 'cleaning_w441|default|'.hash('xxh3', (string) (new \ReflectionClass($class))->getConstant('W441_FFI_CDEF'));
    }

    /** ArticleFrame is readonly and keeps its handle in a static local; the others use a static property. */
    private static function cachedFfi(string $class): FFI|false|null
    {
        if ($class === ArticleFrame::class) {
            return ArticleFrame::w441Ffi();
        }
        $value = (new ReflectionProperty($class, 'w441Ffi'))->getValue();
        self::assertTrue($value === null || $value === false || $value instanceof FFI);

        return $value;
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W441_RUST_FFI_DEFAULT') === true;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w441', (string) (new \ReflectionClass($class))->getConstant('W441_FFI_CDEF'));
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W441 symbols.');

        return $ffi;
    }

    /** @param list<string> $entries */
    private static function wire(array $entries): string
    {
        return pack('V*', count($entries), ...array_map(strlen(...), $entries)).implode('', $entries);
    }

    /** @param list<string> $entries */
    private static function nulWire(array $entries): string
    {
        return pack('VV', count($entries), 0xFFFFFFFF).implode("\0", $entries);
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    /**
     * Raw export result, or null when Rust declines the batch (an entry holding NUL, or an exhausted
     * backtracking budget) so the public dispatch keeps the PHP result.
     *
     * @param  list<string>  $fields
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, int>|null
     */
    private static function rawResult(FFI $ffi, string $symbol, array $fields, array $input, array $keys): ?array
    {
        $out = $ffi->new('char[256]');
        $entries = XrefService::w441Entries($input, $fields);
        $written = self::export($ffi, $symbol, self::wire($entries), $out, 256);
        if ($written === -1) {
            self::assertStringContainsString("\0", implode('', $entries), 'Only NUL entries may decline in these inputs.');

            return null;
        }
        self::assertSame(count($keys) * 8, $written, 'Native export must execute, not silently fall back.');
        $raw = FFI::string($out, $written);
        if ($entries !== [] && ! str_contains(implode('', $entries), "\0")) {
            self::assertSame(count($keys) * 8, self::export($ffi, $symbol, self::nulWire($entries), $out, 256));
            self::assertSame($raw, FFI::string($out, $written), 'NUL-separated and length frames must agree.');
        }
        $values = unpack('q*', $raw);
        self::assertIsArray($values);

        return array_combine($keys, array_values($values));
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::useW441RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = pack('q*', ...array_values($expected));
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, self::cachedFfi($class));
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $good = self::wire(['entry']);
        $malformed = ['', "\x01\0\0", "\x01\0\0\0", "\x01\0\0\0\x09\0\0\0abc", "\x02\0\0\0\x01\0\0\0x", $good.'x', substr($good, 0, -1), "\xff\xff\xff\xff",
            pack('VV', 0, 0xFFFFFFFF), self::nulWire(['a', 'b'])."\0c", substr(self::nulWire(['a', 'b', 'c']), 0, -2), pack('VV', 3, 0xFFFFFFFF)];
        foreach (self::HELPERS as $i => [$class, , $symbol]) {
            $keys = count(self::literalCases()[$i][1]);
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            foreach ($malformed as $wire) {
                FFI::memset($out, 90, 256);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 256), bin2hex($wire));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            $required = self::export($ffi, $symbol, $good, $out, 1);
            self::assertSame(-$keys * 8, $required, 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256), 'Insufficient capacity must not write.');
            self::assertSame(-$required, self::export($ffi, $symbol, $good, $out, -$required));
        }
    }

    public function test_declined_entries_keep_the_php_result(): void
    {
        $inputs = [
            ["PING :a\0b", ":n!u PRIVMSG #c :\0x"],
            ["NEW: [DT: t] [TT: \0] [SC: s] [CT: c] [RQ: N/A] [SZ: 1] [FL: 2]"],
            ["alt.binaries.x\0:5 alt.binaries.y"],
            ["=ybegin line=1 size=1 name=\0\nx\n=yend crc32=0\n"],
            ["=ybegin line=1 size=1 name=a\n\0=\n=yend crc32=0\n"],
        ];
        foreach (self::HELPERS as $i => [$class, $method, $symbol, $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $class::useW441RustFfi(true);
            self::assertSame($class::{$method.'Php'}($inputs[$i]), $class::{$method}($inputs[$i]));
            $raw = self::rawResult($ffi, $symbol, $fields, $inputs[$i], $keys);
            if ($i <= 1) {
                self::assertNull($raw, 'The IRC parsers decline NUL bytes.');
            } else {
                self::assertSame($class::{$method.'Php'}($inputs[$i]), $raw, 'Xref and yEnc grammars accept NUL bytes.');
            }
        }
    }

    public function test_objects_are_not_serialized_and_oversized_inputs_fall_back(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('Caller-owned objects must not be serialized.');
            }

            public function __toString(): string
            {
                throw new \LogicException('Caller-owned objects must not be cast.');
            }
        };
        $large = str_repeat('x', 16777217);
        foreach (self::HELPERS as [$class, $method, , $fields]) {
            $row = array_fill_keys($fields, $object);
            $input = [$object, $row, [$fields[0] => null, $fields[count($fields) - 1] => 'PING :ok']];
            $class::useW441RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW441RustFfi();
            $class::useW441RustFfi(true);
            $oversized = [$large, array_fill_keys($fields, 'x')];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull(self::cachedFfi($class), 'Size guard must run before resolving FFI.');
        }
    }

    /** @param list<string> $pieces */
    private static function pick(Randomizer $random, array $pieces): string
    {
        return $pieces[$random->getInt(0, count($pieces) - 1)];
    }

    /** @param list<string> $pieces */
    private static function sequence(Randomizer $random, array $pieces, int $max): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, $max); $k < $n; $k++) {
            $entry .= self::pick($random, $pieces);
        }

        return $entry;
    }

    private const array IRC_PIECES = ['PING', 'PING ', ' ', "\t", ':', 'PING :', ':nick!user@host', ':n!', '!', ' PRIVMSG ', 'PRIVMSG', '#chan', '#', ' :', "\x03", "\x0304,05", "\x0399", "\x03,1", "\x02", "\x0f",
        "\x1d", "\x1e", "\x1f", "\x11", "\x16", "\x04ff00aa", "\x04ff00aa,00ff00", "\x04xyz", "\n", "\r", 'NEW: ', 'msg', "\xff", '日本語', '  ', 'x', '12'];

    private const array BOT_PIECES = ['NEW: ', 'UPD: ', 'NUK: ', 'new: ', 'nuk:', '[DT: ', '[TT: ', '[SC: ', '[CT: ', '[RQ: ', '[SZ: ', '[FL: ', '[FN: ', '[NUKED: ', '[nuke: ', '[MODNUKED: ', '[UNNUKED: ',
        '[RENUKED: ', '[OLDNUKE: ', '[OLDNUKED: ', ']', '] ', ']]', ' ', "\n", 'N/A', '123:alt.binaries.x', '7:', ':g', 'Title.2024-GRP', 'x', "\xff"];

    private const array XREF_PIECES = ['alt.binaries.teevee:123', 'a.bin.x', 'alt.binarios.x', 'alt.binaer.x:9', 'news.host', 'ab.binaries.', 'ab.binaries.x', 'abc.binaries.x:', 'alt.binaries.x:12:34', 'Alt.binaries.y',
        "alt.binaries.z\n:5", "alt.binaries.\nz", '0', '', 'x', 'alt.binaries.a.b.c:00', 'ALT.BINARIES.X', 'alt.binaries.teevee', 'abcd.binaries.x', 'alt.binaries.x:9a', 'alt.binaries.:1', "alt.binaries.x:5\n", '日本.binaries.x'];

    private const array YENC_PIECES = ['abc', 'x', '=', '==', "=\r\n", "x=\n", "\r\n", "\n", "\r", '=yend', '=YEND', '=yendx', '=ybegin line=1 size=1 name=z', '=ypart begin=1 end=1', '=y', "\t", ' ', "\xff", '.'];

    private const array HEADER_FIELDS = [' line=128', ' size=3', ' part=1', ' total=2', ' name=a b', ' NAME=x', ' size=00', ' size=99999999999999999999', "\tline=1", ' x=1', ' size=', ' line=x', 'line=1', ' Part=2', ' name=', "\r"];

    private const array TRAILER_FIELDS = [' size=3', ' crc32=352441C2', ' pcrc32=abc', ' crc32=xyz', ' part=1', ' CRC32=1', ' pcrc32=', ' crc32=123456789', "\tpcrc32=0", ' crc32=0 crc32=g', "\r"];

    private static function randomArticle(Randomizer $random): string
    {
        $article = $random->getInt(0, 4) === 0 ? self::sequence($random, self::YENC_PIECES, 3)."\n" : '';
        $article .= self::pick($random, ['=ybegin', '=ybegin', '=YBEGIN', '=ybeginx', '=ybegin'])
            .self::sequence($random, self::HEADER_FIELDS, 5)
            .($random->getInt(0, 3) === 0 ? '' : ' line=1 size=2 name=f.bin')
            .self::pick($random, ["\r\n", "\n", "\n", '']);
        if ($random->getInt(0, 2) === 0) {
            $article .= self::pick($random, ['=ypart', '=YPART', '=ypartx'])
                .self::pick($random, [' ', "\t", ''])
                .self::pick($random, ['begin=1 end=3', 'begin=1', '']).self::pick($random, ["\r\n", "\n", '']);
        }
        $article .= self::sequence($random, self::YENC_PIECES, 10);
        if ($random->getInt(0, 5) !== 0) {
            $article .= self::pick($random, ["\r\n", "\n"]).self::pick($random, ['=yend', '=yend', '=YEND', '=yendx'])
                .self::sequence($random, self::TRAILER_FIELDS, 4).self::pick($random, ["\r\n", '', "\n=ybegin"]);
        }

        return $article;
    }

    private static function randomEntry(Randomizer $random, int $index): string
    {
        return match ($index) {
            0 => $random->getInt(0, 2) === 0 ? self::sequence($random, self::IRC_PIECES, 10) : self::randomPrivmsg($random),
            1 => self::randomBotMessage($random),
            2 => implode(self::pick($random, [' ', ' ', '  ']), array_map(fn () => self::pick($random, self::XREF_PIECES), range(0, $random->getInt(0, 5)))),
            default => self::randomArticle($random),
        };
    }

    /** Near-valid PRIVMSG lines with control codes and whitespace placed around every delimiter. */
    private static function randomPrivmsg(Randomizer $random): string
    {
        $ws = fn () => self::pick($random, [' ', ' ', ' ', '  ', "\t", " \x02", "\n", '']);

        return self::pick($random, [':', ':', ':', 'x:', "\x02:"]).self::pick($random, ['nick', 'n', "ni\x03ck", '', 'a!b', "n\n"])
            .self::pick($random, ['!', '!', '!', '']).self::pick($random, ['user@host', 'u', '', "h\x0f", ' x'])
            .$ws().self::pick($random, ['PRIVMSG', 'PRIVMSG', 'PRIVMSG', 'privmsg', 'NOTICE', "PRIV\x02MSG"])
            .$ws().self::pick($random, ['#chan', '#chan', '#', '#a b', 'chan', "#\x0304x"])
            .$ws().self::pick($random, [':', ':', ':', '', '::'])
            .$ws().self::sequence($random, self::IRC_PIECES, 6).$ws();
    }

    private static function randomBotMessage(Randomizer $random): string
    {
        if ($random->getInt(0, 4) === 0) {
            return self::sequence($random, self::BOT_PIECES, 14);
        }
        $message = self::pick($random, ['NEW: ', 'UPD: ', 'NUK: ', 'upd: ', 'NEW:']);
        foreach (['DT', 'TT', 'SC', 'CT', 'RQ', 'SZ', 'FL'] as $label) {
            if ($random->getInt(0, 60) !== 0) {
                $value = $label === 'RQ' ? self::pick($random, ['N/A', 'N/A', '123:alt.binaries.x', '123:alt.binaries.x', '7:', ':g', '12:a:b', "1:g\n", 'n/a']) : self::pick($random, ['x', 'x', 'Title.2024-GRP', 'Title.2024-GRP', '2024-01-01 00:00:00', 'a] [b', '日本語', "a\nb", '', "\xff"]);
                $message .= '['.$label.': '.$value.']'.self::pick($random, [' ', ' ', '', '  ', "\t"]);
            }
        }
        if ($random->getInt(0, 2) === 0) {
            $message .= '[FN: '.self::pick($random, ['a.nfo', 'b]c', '']).']'.self::pick($random, [' ', '']);
        }
        if ($random->getInt(0, 2) === 0) {
            $message .= self::pick($random, ['[NUKED: ', '[nuke: ', '[MODNUKED: ', '[UNNUKED: ', '[RENUKED: ', '[OLDNUKE: ', '[OLDNUKED: ', '[NUKES: '])
                .self::pick($random, ['bad', 'a] b', '']).']'.self::pick($random, ['', "\n", ' ', ']']);
        }

        return $message;
    }

    /**
     * @param  list<string>  $fields
     * @return array<array-key, mixed>
     */
    private static function randomBatch(Randomizer $random, array $fields, int $index): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $entry = self::randomEntry($random, $index);
            $field = [...$fields, 'other'][$random->getInt(0, count($fields))];
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => [$field => $entry],
                1 => [$field => $entry, $fields[0] => 'ignored'],
                2 => [$fields[0] => null, $fields[count($fields) - 1] => $entry],
                3 => [null, true, false, 1.5, -7, PHP_INT_MAX, [], new \stdClass][$random->getInt(0, 7)],
                4 => [$field => [$entry]],
                default => $entry,
            };
        }

        return $rows;
    }

    public function test_raw_native_and_real_dispatch_randomized_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $i => [$class, $method, $symbol, $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $cases = [[], self::literalCases()[$i][0]];
            for ($trial = 0; $trial < 1500; $trial++) {
                $cases[] = self::randomBatch($random, $fields, $i);
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($ffi, $symbol, $fields, $input, $keys), $class.' '.json_encode(XrefService::w441Entries($input, $fields), JSON_INVALID_UTF8_SUBSTITUTE));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                $class::useW441RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Slides every pattern across prefixes and suffixes so boundary and anchor handling match PHP. */
    public function test_patterns_at_every_offset_match_php(): void
    {
        $patterns = [
            ['PING :x', "PING :x\n", "PING :\n", 'PING:', ':a!b PRIVMSG #c :m', ":a!b PRIVMSG #c :m \n", ":a!\nb PRIVMSG #c :m", "\x0312,34x", "\x04abcdef,012345", "\x1f"],
            ['NEW: [DT: a] [TT: b] [SC: c] [CT: d] [RQ: 1:g] [SZ: e] [FL: f]', 'UPD: [DT: a][TT: b][SC: c][CT: d][RQ: N/A][SZ: e][FL: f][FN: g][NUKED: h]', "NUK: [DT: a] [TT: b] [SC: c] [CT: d] [RQ: x] [SZ: e] [FL: f]\n"],
            ['alt.binaries.x:1', 'alt.binaries.x', 'ab.binaer.y:22', 'alt.binarios.z:3:4', 'x.binaries.y'],
            [self::SINGLE, self::MULTI, "=ybegin line=1 size=1 name=a\nx=\n=yend pcrc32=1"],
            [self::SINGLE, self::MULTI, "=ybegin line=1 size=1 name=a\nx=\n=yend pcrc32=1"],
        ];
        foreach (self::HELPERS as $i => [$class, $method, $symbol, $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            foreach (['a', '.', ' ', '=', "\n", "\r\n"] as $filler) {
                for ($pad = 0; $pad <= 6; $pad++) {
                    $batch = [];
                    foreach ($patterns[$i] as $pattern) {
                        $batch[] = $pattern;
                        $batch[] = 'q'.str_repeat($filler, $pad).$pattern;
                        $batch[] = $pattern.str_repeat($filler, $pad).'q';
                        $batch[] = $pattern.str_repeat($filler, $pad);
                        $batch[] = str_repeat($filler, $pad).$pattern;
                    }
                    self::assertSame($class::{$method.'Php'}($batch), self::rawResult($ffi, $symbol, $fields, $batch, $keys), $class.' pad '.$pad.' filler '.bin2hex($filler));
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW441RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW441RustFfi());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 256);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class)) {
                $wire = pack('q*', ...array_values($expected));
                self::assertSame($wire, FFI::string($out, strlen($wire)));
                self::assertInstanceOf(FFI::class, self::cachedFfi($class));
            } else {
                self::assertNull(self::cachedFfi($class));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
        }
    }

    /**
     * @param  list<int>  $samples
     * @return array{mean_ns: float, p99_ns: int, p99_approx_95pct_rank_interval_ns: array{int, int}}
     */
    private static function statistics(array $samples): array
    {
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $radius = 1.96 * sqrt($n * 0.99 * 0.01);

        return [
            'mean_ns' => array_sum($samples) / $n,
            'p99_ns' => $samples[(int) ceil(0.99 * $n) - 1],
            'p99_approx_95pct_rank_interval_ns' => [$samples[max(0, (int) floor(0.99 * $n - $radius) - 1)], $samples[min($n - 1, (int) ceil(0.99 * $n + $radius) - 1)]],
        ];
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $hashes = [];
        foreach (self::HELPERS as [$class]) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $hashes[$path] = hash_file('sha256', $path);
        }
        foreach ([__FILE__, $root.'/rust/pipeline-ffi/src/cleaning.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock'] as $path) {
            $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $native = [];
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix, '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $path) {
            if (is_file($path)) {
                $native = ['path' => realpath($path), 'sha256' => hash_file('sha256', $path), 'profile' => 'release'];
                break;
            }
        }
        $git = 'git -C '.escapeshellarg($root).' ';

        return [
            'utc' => gmdate(DATE_ATOM),
            'head' => trim((string) shell_exec($git.'rev-parse HEAD 2>&1')),
            'base_origin_master' => trim((string) shell_exec($git.'rev-parse origin/master 2>&1')),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')),
            'native_library' => $native,
            'php' => PHP_VERSION,
            'platform' => php_uname(),
            'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl machdep.cpu.brand_string hw.memsize hw.logicalcpu 2>&1' : 'lscpu 2>&1')),
            'ffi_enable' => ini_get('ffi.enable'),
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'),
            'seed' => self::SEED,
            'warmups_per_mode' => 64,
            'paired_samples_per_workload' => self::samples(),
            'order' => 'fixed-seed randomized paired; timings include PHP entry extraction, framing, FFI call and decoding',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W441_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIrcClientScraperXrefYencFrameServiceW44ParityTest.php',
        ];
    }

    private static function samples(): int
    {
        $samples = (int) (getenv('NNTMUX_W441_BENCH_SAMPLES') ?: 3000);

        return max(1000, $samples);
    }

    /** One encoded yEnc article; multipart frames carry an `=ypart` line and a part checksum. */
    private static function article(Randomizer $random, int $bytes, bool $multipart): string
    {
        $data = $random->getBytes($bytes);
        $encoded = (new YencService)->encode($data, 'Some.Movie.2021.1080p.part01.rar', 128);
        if (! $multipart) {
            return $encoded."\r\n";
        }
        $payload = substr($encoded, strpos($encoded, "\r\n") + 2, strrpos($encoded, "\r\n=yend") - strpos($encoded, "\r\n") - 2);

        return '=ybegin part=3 total=40 line=128 size='.($bytes * 40)." name=Some.Movie.2021.1080p.part01.rar\r\n=ypart begin="
            .($bytes * 2 + 1).' end='.($bytes * 3)."\r\n".$payload."\r\n=yend size=".$bytes.' part=3 pcrc32='.hash('crc32b', $data)."\r\n";
    }

    /**
     * Common batches hold typical rows for each helper; uncommon batches mix array rows, missing fields,
     * malformed and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + $index));
        $typical = match ($index) {
            0 => ['PING :irc.example.net', ":PreBot!bot@pre.example PRIVMSG #PreNNTmux :\x0304NEW:\x0f [DT: 2024-01-01 00:00:00] [TT: Some.Show.S01E02.720p.WEB-DL-GRP] [SC: src]",
                ':irc.example.net 372 nntmux :- Message of the day', ':nick!user@host.example JOIN #PreNNTmux', ':nick!user@host PRIVMSG #chan :hello there'],
            1 => ['NEW: [DT: 2024-01-01 00:00:00] [TT: Some.Show.S01E02.720p.WEB-DL-GRP] [SC: srrdb] [CT: TV-HD] [RQ: N/A] [SZ: 1.2GB] [FL: 30]',
                'UPD: [DT: 2024-01-01 00:00:00] [TT: Some.Movie.2021.1080p.BluRay.x264-GRP] [SC: x] [CT: MOVIE] [RQ: 12345:alt.binaries.moovee] [SZ: 8GB] [FL: 90] [FN: some-movie.nfo]',
                'NUK: [DT: 2024-01-01 00:00:00] [TT: Some.Movie.2021.1080p.BluRay.x264-GRP] [SC: x] [CT: MOVIE] [RQ: N/A] [SZ: 8GB] [FL: 90] [NUKED: mislabeled]'],
            2 => ['news.example.com alt.binaries.teevee:123456789 alt.binaries.hdtv:987654', 'news.example.com alt.binaries.moovee:4242424', 'news.example.com alt.binaries.boneless:1 alt.binaries.cores:2 alt.binaries.mom:3'],
            default => [self::article($random, 3000, false), self::article($random, 3000, true), self::article($random, 2200, true)],
        };
        $source = $typical;
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long.Entry.", 40);
            $source = [...$typical, ...self::literalCases()[$index][0], $long, [self::HELPERS[$index][3][0] => $long."\n"], null, 7, self::randomEntry($random, $index), self::randomEntry($random, $index)];
            if ($index >= 3) {
                $source[] = self::article($random, 40000, true);
                $source[] = substr(self::article($random, 6000, false), 0, -40);
            }
            if ($index === 1) {
                $source[] = 'NEW: [DT: t] [TT: '.str_repeat('Long.Title.', 60).'] [SC: s] [CT: c] [RQ: 1:alt.binaries.x] [SZ: 1] [FL: 2] [FN: f]';
            }
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? ($index >= 3 ? 96 : 1024) : ($index >= 3 ? 16 : 24); $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W441_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W441_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W441_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w441-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave44.1 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        $samples = self::samples();
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol, $fields]) {
                $ffi = self::ffi($class);
                $keys = array_keys(self::literalCases()[$index][1]);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($ffi, $symbol, $fields, $args, $keys));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW441RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < $samples; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW441RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}($args);
                            $pair[$native ? 'rust_ns' : 'php_ns'] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                        $pairs[] = $pair;
                    }
                    $php = self::statistics(array_column($pairs, 'php_ns'));
                    $rust = self::statistics(array_column($pairs, 'rust_ns'));
                    $report['raw_pairs'][$method][$workload] = $pairs;
                    $report['summary'][$method][$workload] = [
                        'rows' => count($args),
                        'input_sha256' => hash('sha256', self::wire(XrefService::w441Entries($args, $fields))),
                        'php' => $php,
                        'rust' => $rust,
                        'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']),
                        'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']),
                        'conservative_p99_speedup' => $php['p99_approx_95pct_rank_interval_ns'][0] / max(1, $rust['p99_approx_95pct_rank_interval_ns'][1]),
                    ];
                }
                $ratios = [];
                foreach (['common', 'uncommon'] as $workload) {
                    $ratios[$workload.'_mean_speedup'] = $report['summary'][$method][$workload]['mean_speedup'];
                    $ratios[$workload.'_p99_speedup'] = $report['summary'][$method][$workload]['p99_speedup'];
                }
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
                fwrite(STDERR, sprintf("%s: %s\n", $method, json_encode($ratios, JSON_THROW_ON_ERROR)));
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
            foreach (self::HELPERS as [$class, $method]) {
                if (self::defaultEnabled($class)) {
                    self::assertTrue($report['helper_qualification'][$method]['qualified'], $method.' enabled without qualifying every mean/p99 gate.');
                }
            }
        } catch (\Throwable $failure) {
            $report['status'] = 'invalid';
            $report['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
