<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\DataNode;
use App\Services\IGDB\Models\BaseModel;
use App\Services\IGDB\QueryBuilder;
use App\Services\IRCClient;
use App\Services\IRCScraper;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the FFI domain. */
    private const TARGETS = [
        DataNode::class => ['w63DataNodePlanBatch', 'nntmux_w631_data_node_plan_batch', 'W63_DATA_NODE_FFI_CDEF', 24, 'igdb_data_node_w631'],
        BaseModel::class => ['w63ModelPlanBatch', 'nntmux_w631_model_plan_batch', 'W63_MODEL_FFI_CDEF', 23, 'igdb_model_w631'],
        QueryBuilder::class => ['w63QueryBuilderPlanBatch', 'nntmux_w631_query_builder_plan_batch', 'W63_QUERY_BUILDER_FFI_CDEF', 19, 'igdb_query_builder_w631'],
        IRCClient::class => ['w63IrcClientPlanBatch', 'nntmux_w631_irc_client_plan_batch', 'W63_IRC_CLIENT_FFI_CDEF', 23, 'irc_client_w631'],
        IRCScraper::class => ['w63IrcScraperPlanBatch', 'nntmux_w631_irc_scraper_plan_batch', 'W63_IRC_SCRAPER_FFI_CDEF', 27, 'irc_scraper_w631'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        DataNode::class => true,
        BaseModel::class => true,
        QueryBuilder::class => true,
        IRCClient::class => true,
        IRCScraper::class => true,
    ];

    /** A realistic item per class, with `|`-separated fields where the class has them. */
    private const TEMPLATES = [
        DataNode::class => ['first_release_date', 'i', '1700000000', ''],
        BaseModel::class => ['games', '1942', 'name,cover.url,genres.*', '50', '0'],
        QueryBuilder::class => ['name,cover.url,first_release_date', 'zelda', 'platforms = (6,48) & rating > 70', 'rating desc', '20', '0'],
        IRCClient::class => ['1700000000', ':nick!user@host PRIVMSG #pre :NEW: hello'],
        IRCScraper::class => ['NEW: [DT: 2024-01-01 00:00:00] [TT: Some.Show.S01E01.1080p.WEB-DL-GRP] [SC: #a.b.teevee] [CT: TV-X264] [RQ: 12345:alt.binaries.teevee] [SZ: 1.5 GB] [FL: 50]'],
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r", "\r\n", "\n\r", "\x7f", "\xff", "caf\xc3\xa9", "\xe2\x80\x8b", '|', '||', '|||', '||||',
        '|||||', '||||||', ' | | ', "\t|\t|\t", "\0|\0|\0", 'a|b|c|d|e|f|g|h', '.', '..', '...', '-', '_', ':', '::', ',', ',,', ' , ', '&', '&&', ' & ',
        '*', '**', '*.*', 'a.*', '*.a', 'a.*.b', 'a..b', '.a', 'a.', 'A', 'a', 'Z9_', 'id', 'ID', 'Id', 'game_id', 'gameId', 'x_id', '_id', 'name', 'Name',
        'NAME', 'cover.url', 'cover.URL', 'cover url', 'cover-url', 'genres.*', 'created_at', 'updated_at', 'change_date', 'start_date',
        'published_at', 'first_release_date', 'date', 'Date', 'DATE', 'i', 'f', 's', 'b', 'n', 'l', 'm', 'o', 'x', 'I', 'L', 'M', 'ii', 'i,i', 'i,s,m',
        'm,m', 'l,l', 'l,i', 'i, ,i', ',i', 'i,', 'm,x', 'q', '0', '00', '1', '-', '-0', '-1', '--1', '+1', '1-', ' 1 ', '1 2', '1.', '1.5', '.5', '-1.5',
        '1e3', '0x10', '1,5', '9', '10', '99', '100', '499', '500', '501', '999', '1000', '4999', '5000', '5001', '946684799', '946684800',
        '1700000000', '1893455999', '1893456000', '999999999999999', '1000000000000000', '-999999999999999', '1234567890.123456789012345',
        '1.1234567890123456', 'games', 'Games', 'GAMES', '/games/', '//games//', ' games ', 'ga mes', "ga\tmes", 'games/covers', 'games//covers',
        'GAMES/Covers', 'covers', 'platforms', 'companies', 'genres', 'themes', 'game_modes', 'involved_companies', 'release_dates', 'screenshots',
        'artworks', 'websites', 'franchises', 'age_ratings', 'alternative_names', 'platform_logos', 'game_videos', 'keywords', 'collections',
        'search', 'player_perspectives', 'multiquery', 'unknown', 'game-modes', 'games!', '/', '//', ' / ', 'zelda', 'Zelda "BotW"', 'back\\slash',
        '"', '""', '\\', '\\"', 'id = 1', 'id=1', 'id  =  1', 'id != 1', 'id >= 1', 'id <= 1', 'id < 1', 'id > 1', 'id ~ "a"*', 'id ! 1', 'id =',
        'id = ', '= 1', ' = 1', 'id = (1,2,3)', 'id = (1)', 'id = ()', 'id = (', 'id = )', 'platforms = (6,48)', 'a.b = 1', 'A.B = 1', 'id == 1',
        'id =! 1', 'id = 1 & id = 1', 'id = 1 & & x = 2', 'rating desc', 'rating DESC', 'rating asc', 'rating Asc', 'rating', 'rating  desc',
        'rating sideways', ' desc', 'rating desc extra', 'cover.url desc', 'bad-field asc', "rating\tdesc", 'PING', 'PING :irc.example.net',
        'ping :x', 'PING x', 'PING', 'PONG :x', 'PONG', ':srv PONG srv :x', ':nick!user@host PRIVMSG #pre :hello', ':nick!user@host PRIVMSG me :hi',
        ':nick!user@host NOTICE #c :n', ':nick!user@host privmsg #c :lower', ':nick!user PRIVMSG #c :x', ':nick PRIVMSG #c :x', ': PRIVMSG #c :x',
        ':', ':nick!user@host', ':srv 001 me :Welcome', ':srv 372 me :- motd', ':srv 1234 me :x', ':srv 12 me :x', 'JOIN #a', 'JOIN #a,#b', 'PART #a',
        'QUIT :bye', 'KICK #a b :c', 'NICK x', 'MODE #a +o x', 'TOPIC #a :t', 'ERROR :Closing link', 'CAP LS 302', 'FOO bar', 'foo', 'PRIVMSG',
        'PRIVMSG #'.'c', 'PRIVMSG &local :x', "PRIVMSG #a\x07b :x", 'PRIVMSG :x', 'PRIVMSG  #a   b  :  x  ', "\x02bold\x02", "\x0304,05colored\x03",
        "\x1funder\x1f", "\x1d\x1e\x11\x16\x0f", '1700000000|PING :x', '1700000005|PING :x', '1699999999|PING :x', 'x|PING', '-1|PING', '|PING',
        'NEW: ', 'new: ', 'UPD: ', 'upd: ', 'NUK: ', 'nuk: ', 'NEW:', 'NEW', 'OLD: ', '[DT: x]', '[TT: Title]', '[SC: #a.b]', '[SC: src]',
        '[CT: TV]', '[CT: tv-x264]', '[CT: N/A]', '[CT: n/a]', '[CT: ]', '[CT: Movies]', '[CT: X264]', '[CT: MP3]', '[CT: GAMES]', '[CT: PS4]',
        '[CT: APPS]', '[CT: XXX]', '[CT: EBOOK]', '[CT: AUDIOBOOK]', '[CT: Anime]', '[CT: Other]', '[RQ: 123:alt.binaries.x]', '[RQ: N/A]',
        '[RQ: 123:]', '[RQ: :x]', '[RQ: abc:x]', '[RQ: 1234567890123:x]', '[RQ: 123456789012:x]', '[SZ: 1.5 GB]', '[SZ: 700MB]', '[SZ: 700 mb]',
        '[SZ: 1 TB]', '[SZ: 4.7 GiB]', '[SZ: 512 KB]', '[SZ: 1048576 B]', '[SZ: N/A]', '[SZ: big]', '[SZ: 1.]', '[SZ: .5 GB]', '[SZ: 1.1234567 GB]',
        '[SZ: 1234567890 MB]', '[SZ: 999999999 TB]', '[SZ: 1 PB]', '[FL: 50]', '[FL: N/A]', '[FL: many]', '[FL: 1234567890]', '[FN: x.rar]',
        '[NUKED: bad]', '[UNNUKED: ok]', '[MODNUKED: x]', '[RENUKED: y]', '[OLDNUKE: z]', '[NUKE: x]', '[XYZ: unknown]', '[A: short]',
        '[ABCDEFGHI: long]', '[DT:nospace]', '[DT: unterminated', '[DT: x] [DT: y]', '[[DT: x]]', '[DT: a]b]', 'NEW: [DT: x] [TT: t] [SC: s] [CT: c] [RQ: N/A] [SZ: N/A] [FL: N/A]',
        'UPD: [DT: x] [TT: t] [SC: s] [CT: c] [RQ: N/A] [SZ: N/A] [FL: N/A]', 'NUK: [DT: x] [TT: t] [SC: s] [CT: c] [RQ: N/A] [SZ: N/A] [FL: N/A] [NUKED: r]'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 50), str_repeat('a', 51), '#'.str_repeat('c', 49), '#'.str_repeat('c', 50), str_repeat('a', 255),
            str_repeat('a', 256), str_repeat('a', 511), str_repeat('a', 512), str_repeat('x', 1023), str_repeat('x', 1024), str_repeat('x', 8701),
            str_repeat('x', 8702), str_repeat('y', 16384), str_repeat('.', 300), str_repeat('|', 300), str_repeat(',', 300), str_repeat('&', 70),
            str_repeat('9', 40), str_repeat('i,', 1100), str_repeat('m,', 70), str_repeat('a.', 40).'*', str_repeat('f,', 70).'f', str_repeat('[DT: x]', 40),
            str_repeat('[', 100), str_repeat(']', 100), str_repeat("\x03", 80), str_repeat("\xc3\xa9", 200), str_repeat('"', 70), str_repeat('\\', 70),
            str_repeat('id = 1 & ', 70).'x = 2', str_repeat('PING ', 30), 'PRIVMSG #c :'.str_repeat('z', 9000), str_repeat('(1,', 100).')'];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW63RustFfi();
        }
        parent::tearDown();
    }

    public function test_w63_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW63RustFfi', 'enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', 'withW63RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W63_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W63_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || ! $declaration->isPublic() || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            foreach (['w63RustFfiState', 'w63Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w63RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w63RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW63RustFfi');
            $with = new ReflectionMethod($class, 'withW63RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW63RustFfi/withW63RustFfi signatures';
            }
            foreach (['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
            // Wave 63 state lives in function-local statics only, never in properties.
            foreach ($reflection->getProperties() as $property) {
                if (str_contains(strtolower($property->getName()), 'w63')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
            $this->assertStringContainsString(self::TARGETS[$class][1], \constant($class.'::'.$cdef));
            $this->assertSame($trailer, \constant($class.'::'.str_replace('_FFI_CDEF', '_TRAILER', $cdef)), $class);
            $this->assertSame(array_fill(0, $trailer, 0), $class::{$entry.'Php'}([]), $class);
        }
        // Each class declares its own FFI declaration constant name, so none shadows a parent's or sibling's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w63_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (bool) (new ReflectionClass($class))->getConstant('W63_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class);
            $class::enableW63RustFfi();
            $this->assertTrue($class::usesW63RustFfi(), $class);
            $this->assertFalse($class::withW63RustFfi(false, static fn (): bool => $class::usesW63RustFfi()));
            $this->assertTrue($class::usesW63RustFfi(), $class.' restores the enabled override');
            $class::disableW63RustFfi();
            $this->assertFalse($class::usesW63RustFfi(), $class);
            $this->assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()));
            $this->assertFalse($class::usesW63RustFfi(), $class.' restores the disabled override');
            $class::resetW63RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class);
            $this->assertTrue($class::withW63RustFfi(false, static fn (): bool => $class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi())));
            $this->assertFalse($class::withW63RustFfi(true, static fn (): bool => $class::withW63RustFfi(false, static fn (): bool => $class::usesW63RustFfi())));
            try {
                $class::withW63RustFfi(! $class::usesW63RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class.' restores after an exception');
            // Flipping one class's toggle leaves the others, including parents and subclasses, at their defaults.
            foreach ([true, false] as $enabled) {
                $class::withW63RustFfi($enabled, function () use ($class, $enabled): void {
                    $this->assertSame($enabled, $class::usesW63RustFfi(), $class);
                    foreach (array_keys(self::TARGETS) as $other) {
                        if ($other !== $class) {
                            $this->assertSame(self::DEFAULTS[$other], $other::usesW63RustFfi(), $class.' vs '.$other);
                        }
                    }
                });
            }
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [implode('|', self::TEMPLATES[$class]), 'created_at|i|1700000000|', 'games|1|name|10|0', 'name|zelda|id = 1|rating desc|5|0',
                '1700000000|PING :irc.example.net', self::TEMPLATES[IRCScraper::class][0], "caf\xc3\xa9", ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW63RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW63RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w63_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults and are independent of Wave 63.
        $this->assertFalse(DataNode::usesRustFfi());
        $this->assertFalse(BaseModel::usesRustFfi());
        $this->assertSame(DataNode::W47_FIELD_PATH_RUST_FFI_DEFAULT, DataNode::usesW47FieldPathRustFfi());
        $this->assertSame(BaseModel::W48_ATTRIBUTE_RUST_FFI_DEFAULT, BaseModel::usesW48AttributeRustFfi());
        $this->assertSame(QueryBuilder::W47_QUERY_CLAUSE_RUST_FFI_DEFAULT, QueryBuilder::usesW47QueryClauseRustFfi());
        $this->assertSame(IRCClient::W441_RUST_FFI_DEFAULT, IRCClient::usesW441RustFfi());
        $this->assertSame(IRCScraper::W441_RUST_FFI_DEFAULT, IRCScraper::usesW441RustFfi());
        foreach (array_keys(self::TARGETS) as $class) {
            foreach ([true, false] as $enabled) {
                $class::withW63RustFfi($enabled, function (): void {
                    $this->assertSame(DataNode::W47_FIELD_PATH_RUST_FFI_DEFAULT, DataNode::usesW47FieldPathRustFfi());
                    $this->assertSame(BaseModel::W48_ATTRIBUTE_RUST_FFI_DEFAULT, BaseModel::usesW48AttributeRustFfi());
                    $this->assertSame(QueryBuilder::W47_QUERY_CLAUSE_RUST_FFI_DEFAULT, QueryBuilder::usesW47QueryClauseRustFfi());
                    $this->assertSame(IRCClient::W441_RUST_FFI_DEFAULT, IRCClient::usesW441RustFfi());
                    $this->assertSame(IRCScraper::W441_RUST_FFI_DEFAULT, IRCScraper::usesW441RustFfi());
                });
            }
        }
        $this->assertTrue(DataNode::isDateFieldPhp('created_at', ['created_at']));
        $this->assertSame(1700000000, DataNode::resolveDateTimestampPhp('created_at', '1700000000', ['created_at']));
        $this->assertSame(1, DataNode::summarizeFieldPathBatchPhp(['cover.url'])['nested_path_count']);
        $this->assertSame('games/covers', BaseModel::normalizeEndpointPhp(' /Games//Covers/ '));
        $this->assertSame('first_release_date', BaseModel::normalizeAttributeKeyPhp('firstReleaseDate'));
        $this->assertSame(1942, BaseModel::extractIdentifierPhp(['id' => 1942]));
        $this->assertSame(1, QueryBuilder::summarizeQueryClauseBatchPhp(['fields name; where id = 1;'])['where_query_count']);
        $this->assertSame(1, IRCClient::summarizeInboundLineBatchPhp(['PING :irc.example.net'])['ping_count']);
        $this->assertSame(1, IRCScraper::summarizeBotMessageBatchPhp([self::TEMPLATES[IRCScraper::class][0]])['bot_message_count']);
        $parse = new ReflectionMethod(IRCScraper::class, 'parseBotMessageFieldsPhp');
        $this->assertSame('12345', $parse->invoke(null, self::TEMPLATES[IRCScraper::class][0])['req_id']);
    }

    public function test_w63_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer, $domain]) {
            $ffi = RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef));
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = [implode('|', self::TEMPLATES[$class]), 'x|1|1|3'];
            $blob = implode("\0", $items);
            $words = $trailer + 2;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // An output buffer overlapping the input declines.
            $shared = $ffi->new('int64_t[64]');
            FFI::memcpy($shared, str_repeat('a', 64), 64);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $ffi->cast('char *', FFI::addr($shared[0])), 64, null, 1, $shared, 64), $class);
        }
    }

    public function test_w63_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = [implode('|', self::TEMPLATES[$class]), 'cover|m||i,s,m', 'games||*|600|', 'name||id = (1,2)|rating desc|10|',
                "1700000001|:srv 001 me :Welcome\r\n", 'NUK: [DT: x] [TT: Other] [SC: s] [CT: XXX] [RQ: N/A] [SZ: 10 MB] [FL: 1] [MODNUKED: r]', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w63Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
            // reset drops the cached handle and buffers; the next call resolves them again.
            $class::resetW63RustFfi();
            $this->assertSame($class::{$entry.'Php'}($items), $invoke->invoke(null, $items, $trailer + \count($items)), $class.' after reset');
        }
    }

    public function test_known_case_fields(): void
    {
        $bits = static fn (int $word, int $shift, int $width): int => $word >> $shift & ((1 << $width) - 1);

        $node = DataNode::w63DataNodePlanBatchPhp(['created_at|i|1700000000|', 'cover|m||i,s,m', 'genres|l||i,i,i', 'games|l||m,i', 'Name|s|Zelda|', '|i|1|',
            'name|q|x|', 'name|l||i,zz', 'name|s|x|', 'updated_at|s|-5.9|', 'date|f|0|', 'start_date|i|100|', 'game_id|i|7|', "na\xc3\xa9|s|x\x01|"]);
        $this->assertSame([1, 0, 1, 0, 0, 31, 4, 1, 10], [$bits($node[0], 0, 3), $bits($node[0], 3, 4), $bits($node[0], 7, 8), $bits($node[0], 15, 10),
            $bits($node[0], 25, 10), $bits($node[0], 35, 6), $bits($node[0], 41, 3), $bits($node[0], 44, 2), $bits($node[0], 54, 8)]);
        $this->assertSame([3, 6, 3, 2, 32], [$bits($node[1], 0, 3), $bits($node[1], 3, 4), $bits($node[1], 15, 10), $bits($node[1], 25, 10), $bits($node[1], 7, 8)]);
        $this->assertSame([4, 5, 0], [$bits($node[2], 0, 3), $bits($node[2], 3, 4), $bits($node[2], 25, 10)]);
        $this->assertSame([5, 1, 32], [$bits($node[3], 0, 3), $bits($node[3], 25, 10), $bits($node[3], 7, 8)]);
        $this->assertSame([2, 2, 2], [$bits($node[4], 0, 3), $bits($node[4], 44, 2), $bits($node[4], 7, 8)]);
        $this->assertSame([0, 6, 6, 2, 64], [$node[5] & 7, $bits($node[6], 0, 3), $bits($node[7], 0, 3), $bits($node[8], 0, 3), $bits($node[8], 7, 8) & 64]);
        $this->assertSame([1, 1, 3], [$bits($node[9], 0, 3), $bits($node[9], 41, 3), $bits($node[9], 35, 6)]);
        $this->assertSame([1, 2, 0], [$bits($node[10], 0, 3), $bits($node[10], 41, 3), $bits($node[10], 35, 6)]);
        $this->assertSame([1, 3], [$bits($node[11], 0, 3), $bits($node[11], 41, 3)]);
        $this->assertSame([2, 128], [$bits($node[12], 0, 3), $bits($node[12], 7, 8)]);
        $this->assertSame([3, 4 | 8], [$bits($node[13], 44, 2), $bits($node[13], 7, 8)]);
        $this->assertSame([1, 4, 4, 1, 1, 1, 2], \array_slice($node, 14, 7), 'action counts');
        $this->assertSame([10, 3, 7], \array_slice($node, 14 + 15, 3), 'element, node and scalar sums');
        $this->assertSame([11, 3, 1700000000, 1], \array_slice($node, 14 + 20, 4), 'distinct keys, largest shape, latest timestamp and odd keys');

        $model = BaseModel::w63ModelPlanBatchPhp(['games|1942|name,cover.url|10|0', '/Games/|0|*|600|', 'unknown|1||', 'games|x', 'games||name,,cover.*.url',
            'games|||abc', '', 'games|1942|name,cover.url|10|0', 'games/covers|| genres.* , name ,name|5|9000', 'ga mes']);
        $this->assertSame([5, 1, 0, 11, 2, 0, 1, 0, 0, (int) hexdec(hash('fnv1a32', 'games:1942:name,cover.url:10:0')) & 0x3FFF], [$bits($model[0], 0, 3),
            $bits($model[0], 3, 5), $bits($model[0], 8, 9), $bits($model[0], 17, 6), $bits($model[0], 23, 6), $bits($model[0], 29, 4), $bits($model[0], 33, 6),
            $bits($model[0], 39, 6), $bits($model[0], 45, 4), $bits($model[0], 49, 14)]);
        $this->assertSame([6, 1, 1 | 8 | 64, 1], [$bits($model[1], 0, 3), $bits($model[1], 3, 5), $bits($model[1], 8, 9), $bits($model[1], 29, 4)]);
        $this->assertSame([1, 31], [$bits($model[2], 0, 3), $bits($model[2], 3, 5)]);
        $this->assertSame([2, 3, 2, 3, 4], [$bits($model[3], 0, 3), $bits($model[4], 0, 3), $bits($model[4], 39, 6), $bits($model[4], 23, 6), $bits($model[5], 0, 3)]);
        $this->assertSame((int) hexdec(hash('fnv1a32', '::::')) & 0x3FFF, $bits($model[6], 49, 14));
        $this->assertSame(0, $model[6] & 0x1FFFF);
        $this->assertSame(256, $bits($model[7], 8, 9) & 256);
        $this->assertSame([1, 31, 32 | 128, 3, 1, 2, 1], [$bits($model[8], 0, 3), $bits($model[8], 3, 5), $bits($model[8], 8, 9), $bits($model[8], 23, 6),
            $bits($model[8], 29, 4), $bits($model[8], 33, 6) + 1, $bits($model[8], 45, 4)]);
        $this->assertSame([6, 2], [$bits($model[9], 0, 3), $bits($model[9], 8, 9)], 'inner whitespace is dropped');
        $this->assertSame([1, 2, 1, 1, 1, 2, 2], \array_slice($model, 10, 7), 'decision counts');
        $this->assertSame([10 + 500 + 10 + 10, 9000, 3, 9], [$model[10 + 19], $model[10 + 20], $model[10 + 21], $model[10 + 22]], 'limits, largest offset, endpoints and keys');

        $query = QueryBuilder::w63QueryBuilderPlanBatchPhp(['name,cover.url,name|zel"da|id = (1,2) & rating > 50|rating desc|10|20', '', 'na me|||||', '|||rating sideways||',
            '||x|||', '|||||-5', '||||-7|', '||id = 1 & id = 1|||', "|caf\xc3\xa9|||9999|"]);
        $compiled = "fields name,cover.url;\nsearch \"zel\\\"da\";\nwhere id = (1,2) & rating > 50;\nsort rating desc;\nlimit 10;\noffset 20;";
        $this->assertSame([0, 1 | 2 | 8 | 32 | 128, 2, 2, 6, \strlen($compiled), 4, 5, 2], [$bits($query[0], 0, 3), $bits($query[0], 3, 8), $bits($query[0], 11, 6),
            $bits($query[0], 17, 6), $bits($query[0], 23, 3), $bits($query[0], 26, 16), $bits($query[0], 42, 6), $bits($query[0], 48, 6), $bits($query[0], 54, 8)]);
        $this->assertSame([5, 9, 1], [$bits($query[1], 0, 3), $bits($query[1], 26, 16), $bits($query[1], 23, 3)]);
        $this->assertSame([1, 3, 2, 4], [$bits($query[2], 0, 3), $bits($query[3], 0, 3), $bits($query[4], 0, 3), $bits($query[5], 0, 3)]);
        $this->assertSame([0, 1, \strlen("fields *;\nlimit 1;")], [$bits($query[6], 0, 3), $bits($query[6], 42, 6), $bits($query[6], 26, 16)]);
        $this->assertSame([0, 16, 1], [$bits($query[7], 0, 3), $bits($query[7], 3, 8) & 16, $bits($query[7], 17, 6)]);
        $this->assertSame([0, 1 | 4 | 64], [$bits($query[8], 0, 3), $bits($query[8], 3, 8)]);
        $this->assertSame([4, 1, 1, 1, 1, 1], \array_slice($query, 9, 6), 'status counts');

        $irc = IRCClient::w63IrcClientPlanBatchPhp(["100|PING :irc.example.net\r\n", "101|:nick!user@host PRIVMSG #pre :\x02NEW\x02: hello",
            '102|:irc.example.net 001 me :Welcome', "103|:nick!user@host privmsg me :hi\n", '104|:bad!nohost PRIVMSG #x :y', '', '105|JOIN #a,b', 'x|FOO bar',
            '99|PONG x', '99|PONG x', '99|PONG x', '99|PONG x', '99|PONG x', '99|PONG x', '120|ERROR :Closing link', '121|PRIVMSG &Local :x']);
        $this->assertSame([1, 2, 0, 1, 0, 1, 1, 1, 21], [$bits($irc[0], 0, 3), $bits($irc[0], 3, 4), $bits($irc[0], 7, 2), $bits($irc[0], 9, 10), $bits($irc[0], 19, 4),
            $bits($irc[0], 23, 1), $bits($irc[0], 34, 6), $bits($irc[0], 60, 1), $bits($irc[0], 46, 14)]);
        $this->assertSame([2, 4, 2, 4, 1, 2], [$bits($irc[1], 0, 3), $bits($irc[1], 3, 4), $bits($irc[1], 7, 2), $bits($irc[1], 9, 10), $bits($irc[1], 19, 4),
            $bits($irc[1], 40, 6)]);
        $this->assertSame([4, 1, 1, 1], [$bits($irc[2], 0, 3), $bits($irc[2], 3, 4), $bits($irc[2], 24, 10), $bits($irc[2], 7, 2)]);
        $this->assertSame([3, 2 | 512], [$bits($irc[3], 0, 3), $bits($irc[3], 9, 10)]);
        $this->assertSame([6, 3, 0], [$bits($irc[4], 0, 3), $bits($irc[4], 7, 2), $irc[5]]);
        $this->assertSame([5, 6, 256 | 128], [$bits($irc[6], 0, 3), $bits($irc[6], 3, 4), $bits($irc[6], 9, 10)], 'six lines inside ten seconds');
        $this->assertSame([7, 15, 32, 0], [$bits($irc[7], 0, 3), $bits($irc[7], 3, 4), $bits($irc[7], 9, 10), $bits($irc[7], 34, 6)]);
        $this->assertSame([64, 1], [$bits($irc[8], 9, 10) & 64, $bits($irc[8], 34, 6)]);
        $this->assertSame([6, 128], [$bits($irc[13], 34, 6), $bits($irc[13], 9, 10) & 128]);
        $this->assertSame([6, 1, 0], [$bits($irc[14], 0, 3), $bits($irc[14], 34, 6), $bits($irc[14], 9, 10) & 64], 'entries ten seconds old leave the window');
        $this->assertSame([2, 0], [$bits($irc[15], 0, 3), $bits($irc[15], 9, 10) & 256], 'an & channel target without a prefix');
        $this->assertSame([1, 7, 2, 1, 1, 1, 2, 1], \array_slice($irc, 16, 8), 'class counts');
        $this->assertSame([1, 6, 2, 3, 7], [$irc[16 + 18], $irc[16 + 19], $irc[16 + 20], $irc[16 + 21], $irc[16 + 22]], 'pongs, window, controls, channels and trailing');

        $scraper = IRCScraper::w63IrcScraperPlanBatchPhp([self::TEMPLATES[IRCScraper::class][0],
            'upd: [DT: x] [TT: some.show.s01e01.1080p.web-dl-grp] [SC: src] [CT: N/A] [RQ: N/A] [SZ: N/A] [FL: N/A]',
            'NUK: [DT: x] [TT: Other] [SC: s] [CT: XXX] [RQ: N/A] [SZ: 10 MB] [FL: 1] [MODNUKED: bad.release]',
            'NEW: [DT: x] [TT: Third] [SC: s] [CT: apps] [RQ: abc] [SZ: big] [FL: many]', 'NEW: [DT: x] [TT: Short]', 'hello [DT: x', '',
            'NEW: [DT: x] [TT: Fourth] [SC: s] [CT: Anime] [RQ: 7:a.b] [SZ: 4.7 GiB] [FL: 9] [FN: f.mkv] [XYZ: q] [DT: again]']);
        $this->assertSame([2, 1, 0x7F, 0, 1, 64, 11, 50, 14, 33], [$bits($scraper[0], 0, 3), $bits($scraper[0], 3, 2), $bits($scraper[0], 5, 9), $bits($scraper[0], 14, 3),
            $bits($scraper[0], 17, 4), $bits($scraper[0], 21, 8), $bits($scraper[0], 29, 6), $bits($scraper[0], 35, 12), $bits($scraper[0], 47, 6), $bits($scraper[0], 53, 9)]);
        $this->assertSame([3, 2, 0, 1], [$bits($scraper[1], 0, 3), $bits($scraper[1], 3, 2), $bits($scraper[1], 17, 4), $bits($scraper[1], 21, 8)]);
        $this->assertSame([4, 3, 6, 0x17F], [$bits($scraper[2], 0, 3), $bits($scraper[2], 14, 3), $bits($scraper[2], 17, 4), $bits($scraper[2], 5, 9)]);
        $this->assertSame([2, 128, 5], [$bits($scraper[3], 0, 3), $bits($scraper[3], 21, 8), $bits($scraper[3], 17, 4)]);
        $this->assertSame([1, 0, 4, 0], [$bits($scraper[4], 0, 3), $bits($scraper[5], 0, 3), $bits($scraper[5], 21, 8), $scraper[6]]);
        $this->assertSame([2, 2 | 8, 0xFF, 8, 13, 9, 3], [$bits($scraper[7], 0, 3), $bits($scraper[7], 21, 8), $bits($scraper[7], 5, 9), $bits($scraper[7], 17, 4),
            $bits($scraper[7], 29, 6), $bits($scraper[7], 35, 12), $bits($scraper[7], 47, 6)]);
        $this->assertSame([2, 1, 3, 1, 1], \array_slice($scraper, 8, 5), 'decision counts');
        $this->assertSame([1536 + 4812 + 0, 60, 5, 3, 50], [$scraper[8 + 22], $scraper[8 + 23], $scraper[8 + 24], $scraper[8 + 25], $scraper[8 + 26]],
            'MiB, files, titles, sources and largest file count');
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $edges = self::edgeCases();
        $this->assertGreaterThanOrEqual(300, \count($edges));
        $template = implode('|', self::TEMPLATES[$class]);
        $this->assertParity($class, [$template, ...$edges], 'edge batch');
        foreach ($edges as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$template, $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position of the class template (and of a six-field row).
        $fields = self::TEMPLATES[$class] + array_fill(0, 6, '1');
        for ($slot = 0; $slot < \count($fields); $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $row = $fields;
                $row[$slot] = $edge;
                $rows[] = implode('|', $row);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Edges glued to realistic items with every separator, and inside every bot tag.
        foreach ([' ', '.', '-', '_', ':', '|', ',', '&', ' - ', "\t", '[', ']', ''] as $glue) {
            $this->assertParity($class, array_map(static fn (string $e): string => $template.$glue.$e.$glue.'2019', $edges), 'glued '.bin2hex($glue));
            $this->assertParity($class, array_map(static fn (string $e): string => $e.$glue.$template, $edges), 'prefixed '.bin2hex($glue));
        }
        foreach (['DT', 'TT', 'SC', 'CT', 'RQ', 'SZ', 'FL', 'FN', 'NUKED'] as $tag) {
            $this->assertParity($class, array_map(static fn (string $e): string => 'NEW: [DT: x] [TT: t] [SC: s] [CT: c] [RQ: N/A] [SZ: N/A] [FL: N/A] ['.$tag.': '.$e.']', $edges), 'tag '.$tag);
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'games|1', 7 => '1|PING :x', 'a' => '', 'c' => 'NEW: [DT: x]'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items, 400 token-soup items and 300 random slices per class.
        foreach ([631, 632, 633] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6311));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBdDiIlLmMnNsStTxX0123456789|||..::,,&&*-_ [](\"!@#=<>~/\\\0\t\r\n\x02\x03\x07\x0b\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        $tokens = array_values(array_filter(self::EDGE, static fn (string $s): bool => $s !== ''));
        $tokenSoup = [];
        for ($i = 0; $i < 400; $i++) {
            $parts = [];
            for ($j = 0, $n = $random->getInt(1, 8); $j < $n; $j++) {
                $parts[] = $this->pick($random, $tokens);
            }
            $tokenSoup[] = implode($this->pick($random, [' ', '.', '-', '_', ' - ', '', '|', ':', ',', '&', "\t"]), $parts);
        }
        $this->assertParity($class, $tokenSoup, 'token soup');
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6312)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining, the FFI
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W631_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL,
        // case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~5.8-7.9), common mean / p99, uncommon mean / p99:
        // - DataNode:      7.96x / 7.32x, 5.14x / 4.46x
        // - BaseModel:     4.05x / 3.43x, 4.73x / 3.57x
        // - QueryBuilder:  6.72x / 6.53x, 4.57x / 4.45x
        // - IRCClient:     7.47x / 6.60x, 2.19x / 2.00x
        // - IRCScraper:    6.54x / 5.72x, 5.50x / 5.58x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 63.1 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6316));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W631_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6317)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6318)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW63RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW63RustFfi() : $class::disableW63RustFfi();
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry($items));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW63RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][3] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW63RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW63RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        $clock = 1_700_000_000;
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $clock += $random->getInt(0, 4);
            $row = match ($class) {
                DataNode::class => $this->dataNodeItem($random, $uncommon),
                BaseModel::class => $this->modelItem($random, $uncommon),
                QueryBuilder::class => $this->queryItem($random, $uncommon),
                IRCClient::class => $this->ircClientItem($random, $uncommon, $clock),
                default => $this->scraperItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace(' ', "\t", $row), str_replace('|', '| ', $row), str_replace(',', ',,', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function title(Randomizer $random): string
    {
        return $this->pick($random, ['Some.Show.S01E02', 'The.Movie', 'Artist-Album', 'Great.Documentary', 'Linux.Distro', 'Big.Game', 'Old.Film'])
            .'.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', '2160p', 'WEB-DL', 'BluRay', 'FLAC']).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX', 'CtrlHD']);
    }

    private function dataNodeItem(Randomizer $random, bool $noisy): string
    {
        $key = $this->pick($random, ['id', 'name', 'slug', 'summary', 'storyline', 'rating', 'total_rating', 'cover', 'platforms', 'genres', 'themes',
            'involved_companies', 'release_dates', 'screenshots', 'created_at', 'updated_at', 'first_release_date', 'start_date', 'checksum', 'url', 'game_id']);
        $tag = $this->pick($random, ['i', 'i', 'f', 's', 's', 'b', 'n', 'l', 'l', 'm']);
        $value = match ($tag) {
            'i' => (string) $random->getInt(str_ends_with($key, '_at') || str_contains($key, 'date') ? 800_000_000 : 0, 1_900_000_000),
            'f' => $random->getInt(0, 100).'.'.$random->getInt(0, 999999),
            's' => $this->title($random),
            'b' => $this->pick($random, ['1', '']),
            default => '',
        };
        $shape = '';
        if ($tag === 'l' || $tag === 'm') {
            $elements = [];
            for ($j = 0, $n = $random->getInt(0, 12); $j < $n; $j++) {
                $elements[] = $this->pick($random, ['i', 'i', 'i', 's', 'm', 'm', 'l', 'f', 'n', 'b']);
            }
            $shape = implode(',', $elements);
        }
        if ($noisy) {
            $fields = [$key, $tag, $value, $shape];
            $fields[$random->getInt(0, 3)] = $this->pick($random, ['', ' ', 'x', 'Q', 'i,zz', '-1.5', "a\x01b", "caf\xc3\xa9", str_repeat('n', 300), 'createdAt', 'm,', '1e9']);

            return implode('|', \array_slice($fields, 0, $random->getInt(1, 4)));
        }

        return $key.'|'.$tag.'|'.$value.'|'.$shape;
    }

    private function modelItem(Randomizer $random, bool $noisy): string
    {
        $endpoint = $this->pick($random, ['games', 'platforms', 'companies', 'covers', 'genres', 'themes', 'involved_companies', 'release_dates', 'screenshots',
            'websites', 'search', 'game_videos', '/games/', 'Games', 'platform_logos']);
        $fieldPool = ['name', 'slug', 'summary', 'cover.url', 'cover.image_id', 'genres.name', 'platforms.*', 'involved_companies.company.name', 'first_release_date',
            'rating', 'total_rating', '*', 'screenshots.url', 'release_dates.date'];
        $selected = [];
        for ($j = 0, $n = $random->getInt(0, 8); $j < $n; $j++) {
            $selected[] = $this->pick($random, $fieldPool);
        }
        $fields = [$endpoint, $this->pick($random, ['', (string) $random->getInt(1, 400000)]), implode(',', $selected),
            $this->pick($random, ['', '10', '50', '100', '500', (string) $random->getInt(1, 600)]), $this->pick($random, ['', '0', (string) $random->getInt(0, 6000)])];
        if ($noisy) {
            $fields[$random->getInt(0, 4)] = $this->pick($random, ['', ' ', 'x', '-1', 'a b', 'name,,slug', 'cover.*.url', '//games//covers', 'GAMES ', "caf\xc3\xa9",
                str_repeat('n', 300), '9999999999999999']);

            return implode('|', \array_slice($fields, 0, $random->getInt(1, 5)));
        }

        return implode('|', $fields);
    }

    private function queryItem(Randomizer $random, bool $noisy): string
    {
        $fieldPool = ['name', 'slug', 'summary', 'cover.url', 'cover.image_id', 'genres.name', 'platforms.*', 'first_release_date', 'rating', 'total_rating', 'url'];
        $selected = [];
        for ($j = 0, $n = $random->getInt(0, 8); $j < $n; $j++) {
            $selected[] = $this->pick($random, $fieldPool);
        }
        $conditions = [];
        for ($j = 0, $n = $random->getInt(0, 4); $j < $n; $j++) {
            $conditions[] = $this->pick($random, ['id = '.$random->getInt(1, 400000), 'platforms = ('.$random->getInt(1, 200).','.$random->getInt(1, 200).')',
                'rating > '.$random->getInt(0, 100), 'first_release_date >= '.$random->getInt(800_000_000, 1_800_000_000), 'name ~ "'.$this->title($random).'"*',
                'category != 1', 'version_parent = null']);
        }
        $fields = [implode(',', $selected), $this->pick($random, ['', '', $this->title($random), 'zelda "botw"', 'back\\slash']), implode(' & ', $conditions),
            $this->pick($random, ['', 'rating desc', 'first_release_date asc', 'name', 'total_rating DESC']), $this->pick($random, ['', '1', '10', '50', '500', '501']),
            $this->pick($random, ['', '0', (string) $random->getInt(0, 5000)])];
        if ($noisy) {
            $fields[$random->getInt(0, 5)] = $this->pick($random, ['', ' ', 'x', '-1', 'na me', 'id =', 'id ! 1', 'rating sideways', 'a,,b', "caf\xc3\xa9",
                str_repeat('n', 300), '9999999999999999', '& &']);

            return implode('|', \array_slice($fields, 0, $random->getInt(1, 6)));
        }

        return implode('|', $fields);
    }

    private function ircClientItem(Randomizer $random, bool $noisy, int $clock): string
    {
        $nick = $this->pick($random, ['PreBot', 'pre-bot', 'Announcer', 'srrdb', 'xbot']);
        $line = $this->pick($random, [
            'PING :irc.example.net',
            ':'.$nick.'!~bot@host.example.net PRIVMSG #pre :NEW: [DT: 2024-01-01 00:00:00] [TT: '.$this->title($random).'] [SC: #a.b.x] [CT: TV]',
            ':'.$nick.'!~bot@host.example.net PRIVMSG #pre :'."\x0304".'NUKED'."\x03".' '.$this->title($random),
            ':'.$nick.'!~bot@host.example.net NOTICE me :hello',
            ':irc.example.net '.sprintf('%03d', $random->getInt(1, 499)).' me :Message of the day',
            ':irc.example.net PONG irc.example.net :token',
            ':'.$nick.'!~bot@host JOIN #pre',
            ':'.$nick.'!~bot@host QUIT :Ping timeout',
            'ERROR :Closing Link',
            ':irc.example.net MODE #pre +o '.$nick,
        ]).$this->pick($random, ['', '', "\r\n", "\n"]);
        if ($noisy) {
            $line = $this->pick($random, [$line, strtolower($line), ':bad PRIVMSG', ':x!y PRIVMSG #a :b', 'PRIVMSG #a,b :x', $line.str_repeat('z', 9000), "\xff".$line, '']);
            $clock = $this->pick($random, [$clock, $clock, $clock - 100, -1, 0]);

            return ($clock < 0 ? 'x' : (string) $clock).'|'.$line;
        }

        return $clock.'|'.$line;
    }

    private function scraperItem(Randomizer $random, bool $noisy): string
    {
        $tags = ['DT: '.$random->getInt(2015, 2026).'-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9).' 12:00:00', 'TT: '.$this->title($random),
            'SC: '.$this->pick($random, ['#a.b.teevee', '#alt.binaries.moovee', 'srrdb', 'predb.ovh']), 'CT: '.$this->pick($random, ['TV-X264', 'MOVIE-X265', 'MP3', 'FLAC',
                'GAMES-NSW', 'APPS', 'XXX', 'EBOOK', 'ANIME', 'N/A', 'Other']), 'RQ: '.$this->pick($random, ['N/A', $random->getInt(1, 999999).':alt.binaries.teevee']),
            'SZ: '.$this->pick($random, ['N/A', $random->getInt(1, 9999).' MB', $random->getInt(1, 99).'.'.$random->getInt(0, 99).' GB', $random->getInt(1, 999).' KB']),
            'FL: '.$this->pick($random, ['N/A', (string) $random->getInt(1, 400)])];
        if ($random->getInt(0, 3) === 0) {
            $tags[] = 'FN: '.$this->title($random).'.mkv';
        }
        if ($random->getInt(0, 5) === 0) {
            $tags[] = $this->pick($random, ['NUKED', 'UNNUKED', 'MODNUKED', 'RENUKED', 'OLDNUKE']).': '.$this->pick($random, ['dupe', 'bad.aspect', 'mislabeled']);
        }
        $message = $this->pick($random, ['NEW', 'NEW', 'NEW', 'UPD', 'NUK']).': ['.implode('] [', $tags).']';
        if ($noisy) {
            $message = $this->pick($random, [$message, str_replace('] [', '][', $message), substr($message, 0, -1), str_replace('[SZ: ', '[SZ: x', $message),
                str_replace('NEW', 'OLD', $message), $message.' [XYZ: q]', $message.' [DT: dup]', str_replace('[RQ: ', '[RQ: :', $message)]);
        }

        return $message;
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 63.1 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w63RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI is not available.');
        }
        foreach (self::TARGETS as $class => [, , $cdef, , $domain]) {
            if (RustPipelineLibrary::resolveDomain($domain, \constant($class.'::'.$cdef)) === null) {
                $this->markTestSkipped('libnntmux_pipeline is not available.');
            }
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
