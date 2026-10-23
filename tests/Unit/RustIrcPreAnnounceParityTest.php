<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\IRCClient;
use App\Services\IRCScraper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RustIrcPreAnnounceParityTest extends TestCase
{
    private const CDEF = <<<'C'
        ssize_t nntmux_irc_strip_control_chars(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_irc_parse_inbound_line(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_irc_parse_bot_message(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    public function test_native_symbols_are_available(): void
    {
        self::assertNotNull(RustPipelineLibrary::resolveDomain('irc_pre_announce', self::CDEF));
    }

    private function native(string $function, string $input): string|int
    {
        $ffi = RustPipelineLibrary::resolveDomain('irc_pre_announce', self::CDEF);
        self::assertNotNull($ffi);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) * 2 + 128, $capacity);
        self::assertNotNull($buffer);
        $written = $ffi->$function($input, strlen($input), $buffer, $capacity);
        self::assertIsInt($written);

        return $written < 0 ? $written : FFI::string($buffer, $written);
    }

    /** @return list<string> */
    public static function controlCorpus(): array
    {
        $cases = ['', "  untouched \t\r\n\0", "\x031,2red\x03", "\x03123,456", "\x03,12", "\x04abcdef,123ABCx", "\x04abcdeX", "\x04abc\x0312def", "\x04abc\x02def", "\x03\x0312", "\x03\x0312,34", "\x04\x04abcdef", "\xff\x0312\x80"];
        for ($i = 0; $i < 256; $i++) {
            $cases[] = 'a'.chr($i).'12,34b';
        }
        foreach (["\x03", "\x0312", "\x0312,34", "\x02", "\x0f", "\x04"] as $insert) {
            for ($position = 0; $position <= 13; $position++) {
                $cases[] = substr_replace("\x04abcdef,123456", $insert, $position, 0);
            }
        }
        $cases[] = str_repeat("plain\x0312,34colour\x03\x04abc\x0312def\x02bold\x0f", 512);
        mt_srand(3042);
        for ($i = 0; $i < 1500; $i++) {
            $value = '';
            for ($j = 0, $n = mt_rand(0, 150); $j < $n; $j++) {
                $value .= chr(mt_rand(0, 255));
            }
            $cases[] = $value;
        }

        return $cases;
    }

    public function test_control_stripping_matches_php_byte_for_byte(): void
    {
        $client = (new ReflectionClass(IRCClient::class))->newInstanceWithoutConstructor();
        $php = new ReflectionMethod(IRCClient::class, '_stripControlCharactersPhp');
        $actual = new ReflectionMethod(IRCClient::class, '_stripControlCharacters');
        foreach (self::controlCorpus() as $input) {
            $expected = $php->invoke($client, $input);
            self::assertSame($expected, $this->native('nntmux_irc_strip_control_chars', $input), bin2hex($input));
            self::assertSame($expected, $actual->invoke($client, $input));
        }
    }

    /** @return list<string> */
    public static function botCorpus(): array
    {
        $base = 'NEW: [DT: 2026-10-24 00:00:00] [TT: Some.Release-GRP] [SC: test] [CT: TV] [RQ: 123:alt.binaries.test] [SZ: 1.5GB] [FL: 12]';
        $cases = ['', $base, strtolower($base), $base."\n", $base."\r\n", $base.' ', $base.' [FN: file.nzb]', $base.' [NUKED: reason]', $base.' [FN: file.nzb] [UNNUKED: fixed]', str_replace('] [', '][', $base), str_replace('] [', "]\t[", $base), str_replace('] [', ']  [', $base)];
        foreach (['NUKE', 'NUKED', 'UNNUKE', 'UNNUKED', 'MODNUKE', 'MODNUKED', 'RENUKE', 'RENUKED', 'OLDNUKE', 'OLDNUKED', 'nuked', 'BAD'] as $type) {
            $cases[] = $base.' ['.$type.': bad]';
        }
        foreach (['NEW', 'UPD', 'NUK', 'bad'] as $type) {
            foreach (['', 'N/A', 'x] [TT: y', "\xff", "\0", "\r", "\n", str_repeat('a', 20000)] as $value) {
                $cases[] = str_replace(['NEW', 'Some.Release-GRP'], [$type, $value], $base);
            }
        }
        foreach (['nested] [TT: title', '[nested] title', 'title] [SC: decoy'] as $title) {
            foreach (['a] [FN: b', '[a]b', 'a] [NUKED: decoy'] as $file) {
                $cases[] = str_replace('Some.Release-GRP', $title, $base).' [FN: '.$file.'] [NUKED: why]';
            }
        }
        foreach (['123:group', '001:group:more', '123:', ':group', 'N/A', '123:group'."\r", '123:group'."\n", '１２:group', '1:'.str_repeat('g', 150000)] as $req) {
            $cases[] = str_replace('123:alt.binaries.test', $req, $base);
        }
        mt_srand(3043);
        $alphabet = "[]: \t\r\n012FNnuKEabc\0\xff";
        for ($i = 0; $i < 2500; $i++) {
            $value = $cases[$i % 12];
            for ($j = 0; $j < 3; $j++) {
                $position = mt_rand(0, strlen($value));
                $value = substr_replace($value, $alphabet[mt_rand(0, strlen($alphabet) - 1)], $position, mt_rand(0, 1));
            }
            $cases[] = $value;
        }

        return $cases;
    }

    public function test_bot_fields_and_optional_capture_presence_match_php(): void
    {
        $php = new ReflectionMethod(IRCScraper::class, 'parseBotMessagePhp');
        $actual = new ReflectionMethod(IRCScraper::class, 'parseBotMessage');
        foreach (self::botCorpus() as $input) {
            $expected = $php->invoke(null, $input);
            $expected = self::botExpected($expected);
            $gotDefault = $actual->invoke(null, $input);
            $gotRust = $actual->invoke(null, $input, true);
            self::assertSame($expected, $gotDefault, bin2hex($input));
            self::assertSame($expected, $gotRust, bin2hex($input));
            // Assert that ordinary traffic actually exercises native parsing, without fallback.
            if (! str_contains($input, "\0")) {
                self::assertNotSame(-1, $this->native('nntmux_irc_parse_bot_message', $input), bin2hex($input));
            }
        }
    }

    /** @param array<int|string, string>|null $hits
     * @return array<string, string>|null
     */
    public static function botExpected(?array $hits): ?array
    {
        if ($hits === null) {
            return null;
        }
        $hits = array_filter($hits, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        $hits['req_id'] = '';
        $hits['req_group'] = '';
        if ($hits['req'] !== 'N/A' && preg_match('/^(?P<req>\\d+):(?P<group>.+)$/i', $hits['req'], $req)) {
            $hits['req_id'] = $req['req'];
            $hits['req_group'] = $req['group'];
        }
        $hits['nuke_status'] = match ($hits['nuked'] ?? '') {
            'NUKED' => '2', 'UNNUKED' => '1', 'MODNUKED' => '3', 'RENUKED' => '4', 'OLDNUKE' => '5', default => '',
        };

        return $hits;
    }

    /** @return list<string> */
    public static function inboundCorpus(): array
    {
        $cases = ['', 'PING:server', "PING \t:server\n", "PING:server\r\n", "PING:\n", "PING:a\nb", 'ping:server',
            ':nick!user@host PRIVMSG #pre : message ', ':n!u PRIVMSG #p :   ', ":n!u PRIVMSG #p :\n", ":n!u PRIVMSG #p : \n",
            ':n!u PRIVMSG #p :a PRIVMSG #q :b', ':a!b!c PRIVMSG #p :message', ':n! PRIVMSG #p :m', ':n!  PRIVMSG #p :m',
            ":n!u\n PRIVMSG #p : m", ":n!u PRIVMSG #p :a\nb", ':n!u PRIVMSG # :m', ':n!u PRIVMSG #  :m'];
        foreach (self::controlCorpus() as $value) {
            $cases[] = ':nick!user@host PRIVMSG #pre :'.$value;
            $cases[] = ":ni\x0312ck!u\x02ser PRIVMSG #p\x04abc\x0312defre :".$value;
        }
        mt_srand(3045);
        $alphabet = ":!# PRIVMSGabc\t\r\n\0";
        for ($i = 0; $i < 2500; $i++) {
            $value = $cases[$i % 18];
            for ($j = 0; $j < 3; $j++) {
                $value = substr_replace($value, $alphabet[mt_rand(0, strlen($alphabet) - 1)], mt_rand(0, strlen($value)), mt_rand(0, 1));
            }
            $cases[] = $value;
        }

        return $cases;
    }

    public function test_fused_inbound_matches_legacy_classification_and_captures(): void
    {
        $client = (new ReflectionClass(IRCClient::class))->newInstanceWithoutConstructor();
        $php = new ReflectionMethod(IRCClient::class, 'parseInboundLinePhp');
        $native = new ReflectionMethod(IRCClient::class, 'parseInboundLine');
        foreach (self::inboundCorpus() as $input) {
            $expected = $php->invoke($client, $input);
            self::assertSame($expected, $native->invoke($client, $input), bin2hex($input));
            if (! str_contains($input, "\0")) {
                $wire = $this->native('nntmux_irc_parse_inbound_line', $input);
                self::assertIsString($wire);
                self::assertSame($expected, $wire === '' ? null : explode("\0", $wire), bin2hex($input));
            }
        }
    }

    public function test_read_incoming_dispatches_fused_ping_and_privmsg_frames(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance(ProcessingRuntimeStateRepository::class, new class
        {
            public function stopRequested(): bool
            {
                return false;
            }
        });
        Container::setInstance($container);
        try {
            $client = new class extends IRCClient
            {
                /** @var list<string> */
                private array $observed = [];

                public function __construct() {}

                /** @return list<string> */
                public function capture(string $line): array
                {
                    $this->_buffer = $line;
                    $this->_remote_host_received = 'server';
                    try {
                        $this->readIncoming();
                    } catch (RuntimeException $exception) {
                        if ($exception->getMessage() !== 'frame-captured') {
                            throw $exception;
                        }
                    }

                    return $this->observed;
                }

                protected function _readSocket(): void {}

                protected function _pong(string $host): void
                {
                    $this->observed = ['P', $host];
                    throw new RuntimeException('frame-captured');
                }

                protected function processChannelMessages(): void
                {
                    $this->observed = ['M', $this->_channelData['nickname'], $this->_channelData['channel'], $this->_channelData['message']];
                    throw new RuntimeException('frame-captured');
                }
            };
            self::assertSame(['P', 'server'], $client->capture('PING:server'));
            self::assertSame(['M', 'bot', '#pre', 'release'], $client->capture(":bot!user@host PRIVMSG #pre :\x0312release\x03"));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_channel_processing_consumes_native_request_and_nuke_fields(): void
    {
        $scraper = new class extends IRCScraper
        {
            public function __construct()
            {
                $this->_ignoredChannels = [];
                $this->_debug = false;
                $this->_silent = true;
            }

            /** @return array<string, mixed> */
            public function process(string $message): array
            {
                $this->_resetPreVariables();
                $this->_channelData = ['nickname' => 'bot', 'channel' => '#pre', 'message' => $message];
                $this->processChannelMessages();

                return $this->_curPre + ['nuke_seen' => $this->_nuked];
            }

            protected function _getGroupID(string $groupName): mixed
            {
                return 'group:'.$groupName;
            }

            protected function _checkForDupe(): void {}
        };
        foreach (['NUKED' => 2, 'UNNUKED' => 1, 'MODNUKED' => 3, 'RENUKED' => 4, 'OLDNUKE' => 5, 'NUKE' => '', 'OLDNUKED' => '', 'nuked' => ''] as $nuke => $status) {
            $result = $scraper->process(self::botCorpus()[1].' [FN: file[1].nzb] ['.$nuke.': '.str_repeat('why', 100).']');
            self::assertSame('123', $result['reqid']);
            self::assertSame('group:alt.binaries.test', $result['group_id']);
            self::assertSame($status, $result['nuked']);
            self::assertTrue($result['nuke_seen']);
            self::assertSame('file[1].nzb', $result['filename']);
            self::assertSame(substr(str_repeat('why', 100), 0, 255), $result['reason']);
        }
    }

    public function test_candidates_fall_back_when_ffi_is_disabled(): void
    {
        $code = <<<'CODE'
            require getcwd().'/tests/bootstrap.php';
            $client = (new ReflectionClass(App\Services\IRCClient::class))->newInstanceWithoutConstructor();
            $input = " \x0312,4release\x0f \0";
            $php = new ReflectionMethod($client, '_stripControlCharactersPhp');
            $rust = new ReflectionMethod($client, '_stripControlCharacters');
            if ($php->invoke($client, $input) !== $rust->invoke($client, $input)) { exit(1); }
            $input = 'NEW: [DT: now] [TT: Name-GRP] [SC: test] [CT: TV] [RQ: N/A] [SZ: N/A] [FL: 2]';
            $php = new ReflectionMethod(App\Services\IRCScraper::class, 'parseBotMessagePhp');
            $rust = new ReflectionMethod(App\Services\IRCScraper::class, 'parseBotMessage');
            $expected = Tests\Unit\RustIrcPreAnnounceParityTest::botExpected($php->invoke(null, $input));
            if ($expected !== $rust->invoke(null, $input)) { exit(2); }
            $php = new ReflectionMethod($client, 'parseInboundLinePhp');
            $rust = new ReflectionMethod($client, 'parseInboundLine');
            foreach (Tests\Unit\RustIrcPreAnnounceParityTest::inboundCorpus() as $input) {
                if ($php->invoke($client, $input) !== $rust->invoke($client, $input)) { exit(3); }
            }
            echo 'fallback-ok';
            CODE;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertSame('fallback-ok', $process->getOutput());
    }

    public function test_bot_capacity_and_unsupported_inputs(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('irc_pre_announce', self::CDEF);
        self::assertNotNull($ffi);
        $input = self::botCorpus()[1];
        $small = $ffi->new('char[1]');
        // @phpstan-ignore method.notFound
        $required = $ffi->nntmux_irc_parse_bot_message($input, strlen($input), $small, 1);
        self::assertLessThan(-1, $required);
        $buffer = $ffi->new('char['.(-$required).']');
        // @phpstan-ignore method.notFound
        self::assertSame(-$required, $ffi->nntmux_irc_parse_bot_message($input, strlen($input), $buffer, -$required));
        self::assertSame($this->native('nntmux_irc_parse_bot_message', $input), FFI::string($buffer, -$required));
        self::assertSame(-1, $this->native('nntmux_irc_parse_bot_message', $input."\0"));
        // A bounded scan requests PCRE fallback instead of doing unbounded backtracking.
        $input = 'NEW: [DT: '.str_repeat('x', 1_000_001);
        self::assertSame(-1, $this->native('nntmux_irc_parse_bot_message', $input));
        self::assertNull((new ReflectionMethod(IRCScraper::class, 'parseBotMessage'))->invoke(null, $input));
    }
}
