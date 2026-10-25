<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\Binaries\PerArticleObfuscationRouter;
use App\Services\Predb\Irc\PreAnnounceParser;
use App\Services\Predb\Stream\WebSocketClient;
use App\Support\IrcChannelList;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 45.1 pure CPU IRC pre announce, WebSocket frame, IRC channel list and per-article obfuscation gate and
 * router batch summaries.
 * The opt-in benchmark retains raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W451_BENCH=1 php vendor/bin/phpunit tests/Unit/RustPreAnnounceWebSocketIrcChannelsObfuscationGateRouterW45ParityTest.php
 */
final class RustPreAnnounceWebSocketIrcChannelsObfuscationGateRouterW45ParityTest extends TestCase
{
    /** @var list<array{class-string, string, string, list<string>}> */
    private const array HELPERS = [
        [PreAnnounceParser::class, 'summarizeAnnounceBatch', 'nntmux_w451_pre_announce_batch', ['message', 'line']],
        [WebSocketClient::class, 'summarizeFrameBatch', 'nntmux_w451_websocket_frame_batch', ['frame', 'bytes']],
        [IrcChannelList::class, 'summarizeChannelListBatch', 'nntmux_w451_irc_channel_list_batch', ['channels', 'value']],
        [PerArticleObfuscationGate::class, 'summarizeGateSubjectBatch', 'nntmux_w451_obfuscation_gate_batch', ['Subject', 'subject']],
        [PerArticleObfuscationRouter::class, 'summarizeRouterSubjectBatch', 'nntmux_w451_obfuscation_router_batch', ['Subject', 'subject']],
    ];

    /**
     * Benchmark-qualified defaults. Five paired runs (3000 samples each, Apple M5, PHP 8.5.12 CLI without opcache,
     * rustc 1.99.1 release build) gave min common mean/p99 and uncommon mean/p99 speedups of: announces
     * 4.87/2.97/4.37/3.99, WebSocket frames 3.62/1.67/5.59/4.35, channel lists 4.16/2.34/5.32/4.94 and router
     * subjects 5.02/2.42/6.76/6.09, so those default to Rust. Gate subjects reached only 1.04/0.87/1.33/1.19
     * (max 1.14/1.03/1.37/1.36): one str_contains() per subject costs about what marshaling it does, so the
     * gate stays on PHP.
     */
    private const array DEFAULTS = [true, true, true, false, true];

    /** Earlier public helpers that must stay intact. */
    private const array LEGACY_METHODS = [
        PreAnnounceParser::class => ['parse', 'parseBatch'],
        WebSocketClient::class => ['encodeFrame', 'decodeFrame', 'authority', 'receive'],
        IrcChannelList::class => ['parse', 'parseNative'],
        PerArticleObfuscationGate::class => ['worthNativeMarking', 'sampler', 'summarizeSubjectSample', 'useRustFfi', 'usesRustFfi', 'resetRustFfi'],
        PerArticleObfuscationRouter::class => ['worthNativeMarking', 'summarizeSampleCosts', 'useRustFfi', 'usesRustFfi', 'resetRustFfi'],
    ];

    private const int SEED = 4512026;

    private static ?PreAnnounceParser $parser = null;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW451RustFfi();
        }
        parent::tearDown();
    }

    /** PreAnnounceParser summaries are instance methods bound to a format; the others are static. */
    private static function target(int $index, string $format = 'corruptnet'): object|string
    {
        if ($index !== 0) {
            return self::HELPERS[$index][0];
        }
        if ($format === 'corruptnet') {
            return self::$parser ??= new PreAnnounceParser('corruptnet', 'corruptnet');
        }

        return new PreAnnounceParser($format, $format);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, int>
     */
    private static function call(int $index, string $suffix, array $input, object|string|null $target = null): array
    {
        $target ??= self::target($index);
        $name = self::HELPERS[$index][1].$suffix;
        $result = \is_string($target) ? $target::{$name}($input) : $target->{$name}($input);
        self::assertIsArray($result);

        /** @var array<string, int> $result */
        return $result;
    }

    /** Native prefix: the parser's format index for announces, otherwise none. */
    private static function prefix(int $index, object|string|null $target = null): string
    {
        if ($index !== 0) {
            return '';
        }
        $target ??= self::target(0);
        $format = (new ReflectionProperty(PreAnnounceParser::class, 'nativeFormat'))->getValue($target);
        self::assertIsInt($format);

        return pack('V', $format);
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new \ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW451RustFfi', 'usesW451RustFfi', 'resetW451RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertSame($class, $reflection->getMethod($name)->getDeclaringClass()->getName(), $class.'::'.$name.' must be declared by the class itself.');
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W451_RUST_FFI_DEFAULT'));
            self::assertFalse($reflection->isReadOnly(), $class.' keeps its toggle in static properties.');
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
        $reflection = new \ReflectionClass(IrcChannelList::class);
        self::assertTrue($reflection->hasMethod('w451Entries'));
        self::assertTrue($reflection->hasMethod('w451Invoke'));
    }

    private static function wsFrame(int $first, string $payload, ?string $mask = null, int $lengthCode = -1): string
    {
        $length = \strlen($payload);
        $code = $lengthCode >= 0 ? $lengthCode : ($length < 126 ? $length : ($length < 65536 ? 126 : 127));
        $header = \chr($first).\chr(($mask !== null ? 0x80 : 0) | $code);
        $header .= match ($code) {
            126 => pack('n', $length),
            127 => pack('J', $length),
            default => '',
        };

        return $header.($mask ?? '').($mask !== null ? substr($payload ^ str_repeat($mask, intdiv($length, 4) + 1), 0, $length) : $payload);
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        $subjects = [
            '[1/9] - "file.rar" yEnc (1/50)',
            ['Subject' => 'abcdefghijklmnop1234 (1/2)'],
            'Usenet Index Post abcdefghijklmnopq (1/2)',
            ['subject' => '(1/2) start'],
            'plain subject',
            '',
            null,
            12,
            "tab\t(1/2)",
        ];

        return [
            [[
                'PRE: [FLAC] Some.Album-GRP',
                ['message' => '  INFO: Some.Album-GRP [14F 67.5MB]  '],
                'NUKE: Bad.Release-GRP [dupe]',
                ['line' => 'UNNUKE: Bad.Release-GRP []'],
                'PRE: [FLAC] no_group',
                "PRE: [FLAC] Some.Album-GRP\n",
                '',
                null,
                42,
                'MODNUKE: X.Y-Z [bad] [NUKENET]',
                'PRE: [] A-B',
                'PRE:[TV] A.B-C',
            ], ['total_messages' => 12, 'empty_message_count' => 2, 'entry_count' => 6, 'pre_count' => 2, 'info_count' => 1, 'nuke_count' => 3, 'section_count' => 2, 'reason_count' => 2]],
            [[
                "\x81\x05hello",
                "\x01\x83abcdxyz",
                "\x89\x00",
                ['frame' => "\x88\x02\x03\xe8"],
                "\x83\x00",
                ['bytes' => "\x81\x7e\x00\x02hi"],
                "\x81\x05hel",
                "\x81\x7e\x00",
                "\x81",
                "\x82\x7f".pack('J', 4 * 1024 * 1024 + 1),
                "\x81\x7f\x80".str_repeat("\0", 7),
                '',
                null,
                7,
            ], ['total_frames' => 14, 'empty_frame_count' => 2, 'incomplete_count' => 4, 'complete_count' => 6, 'final_count' => 5, 'masked_count' => 1, 'data_count' => 3, 'control_count' => 2, 'reserved_opcode_count' => 1, 'rejected_count' => 2]],
            [[
                '#a,#b:secret',
                ['channels' => ' a , #a: , '],
                '#,  ,:x',
                '#x:pw,#x',
                '#x,#x:pw',
                'chan',
                '##a,#a',
                '',
                null,
                5,
                ['value' => "#p:\tq\t"],
            ], ['total_lists' => 11, 'empty_list_count' => 2, 'no_channel_count' => 1, 'password_count' => 3, 'unprefixed_count' => 3, 'duplicate_count' => 3, 'multi_channel_count' => 2]],
            [$subjects, ['total_subjects' => 9, 'empty_subject_count' => 2, 'part_marker_count' => 3]],
            [[...$subjects, str_repeat('a', 513), "abcdefghijklmnopqrst\t(2/3)"], ['total_subjects' => 11, 'empty_subject_count' => 2, 'no_part_count' => 5, 'part_count' => 2, 'candidate_count' => 2, 'over_limit_count' => 1]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            $class = self::HELPERS[$i][0];
            self::assertSame($expected, self::call($i, 'Php', $input), $class);
            self::assertSame($expected, self::call($i, 'Native', $input), $class);
            $empty = array_fill_keys(array_keys($expected), 0);
            foreach ([false, true] as $enabled) {
                $class::useW451RustFfi($enabled);
                self::assertSame($expected, self::call($i, '', $input));
                self::assertSame($empty, self::call($i, '', []));
                $single = ['x'];
                self::assertSame(self::call($i, 'Php', $single), self::call($i, '', $single));
            }
        }
    }

    public function test_php_summaries_agree_with_the_existing_parsers(): void
    {
        $parser = new PreAnnounceParser('corruptnet', 'corruptnet');
        self::assertNotNull($parser->parse('PRE: [FLAC] Some.Album-GRP'));
        self::assertNull($parser->parse('PRE: [FLAC] no_group'));
        self::assertSame([true, 2, 'xyz', 9], WebSocketClient::decodeFrame(self::wsFrame(0x82, 'xyz', 'abcd')));
        $this->assertDecodeRejects("\x81\x7f\x80".str_repeat("\0", 7));
        self::assertSame(['#a' => null, '#b' => 'secret'], IrcChannelList::parse('#a,#b:secret'));
        self::assertSame(['#x' => null], IrcChannelList::parse('#x:pw,#x'));
        self::assertSame(['#PreNNTmux' => null], IrcChannelList::parse('#,  ,:x'));
        $subjects = ['[1/9] - "file.rar" yEnc (1/50)', 'abcdefghijklmnop1234 (1/2)', 'Usenet Index Post abcdefghijklmnopq (1/2)', 'plain'];
        self::assertSame(3, PerArticleObfuscationGate::summarizeSubjectSample($subjects)['hits']);
        $costs = PerArticleObfuscationRouter::summarizeSampleCosts(['abcdefghijklmnop1234 (1/2)', "tab\t(1/2)", 'plain']);
        self::assertSame([1, 1, 1], [$costs['no_part_count'], $costs['part_count'], $costs['candidates']]);
    }

    private function assertDecodeRejects(string $bytes): void
    {
        try {
            WebSocketClient::decodeFrame($bytes);
            self::fail('decodeFrame() must reject '.bin2hex($bytes));
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('WebSocket frame', $e->getMessage());
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW451RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW451RustFfi());
            $class::useW451RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW451RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW451RustFfi(), $class.' toggled '.$other);
            }
            $class::useW451RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW451RustFfi());
            $class::useW451RustFfi(false);
            self::assertFalse($class::usesW451RustFfi());
        }
        // The Wave 38.1 gate and router toggles stay separate.
        PerArticleObfuscationGate::useW451RustFfi(true);
        PerArticleObfuscationRouter::useW451RustFfi(true);
        self::assertFalse(PerArticleObfuscationGate::usesRustFfi());
        self::assertTrue(PerArticleObfuscationRouter::usesRustFfi());
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $cache->getValue();
        self::assertIsArray($saved);
        try {
            foreach (self::HELPERS as $i => [$class]) {
                $class::resetW451RustFfi();
                $class::useW451RustFfi(true);
                // A cached failed resolution stands in for a missing or older library.
                $cache->setValue(null, [...$saved, self::cacheKey($class) => false]);
                [$input, $expected] = self::literalCases()[$i];
                self::assertSame($expected, self::call($i, '', $input));
                self::assertSame($expected, self::call($i, 'Native', $input));
                self::assertFalse(self::cachedFfi($class));
            }
        } finally {
            $cache->setValue(null, $saved);
        }
    }

    private static function cacheKey(string $class): string
    {
        return 'cleaning_w451|default|'.hash('xxh3', (string) (new \ReflectionClass($class))->getConstant('W451_FFI_CDEF'));
    }

    private static function cachedFfi(string $class): FFI|false|null
    {
        $value = (new ReflectionProperty($class, 'w451Ffi'))->getValue();
        self::assertTrue($value === null || $value === false || $value instanceof FFI);

        return $value;
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W451_RUST_FFI_DEFAULT') === true;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w451', (string) (new \ReflectionClass($class))->getConstant('W451_FFI_CDEF'));
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W451 symbols.');

        return $ffi;
    }

    /** @param list<string> $entries */
    private static function wire(array $entries, string $prefix = ''): string
    {
        return $prefix.pack('V*', count($entries), ...array_map(strlen(...), $entries)).implode('', $entries);
    }

    /** @param list<string> $entries */
    private static function nulWire(array $entries, string $prefix = ''): string
    {
        return $prefix.pack('VV', count($entries), 0xFFFFFFFF).implode("\0", $entries);
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    /**
     * Raw export result, or null when Rust declines the batch (an announce holding NUL or a non-ASCII
     * title) so the public dispatch keeps the PHP result.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, int>|null
     */
    private static function rawResult(FFI $ffi, int $index, array $input, array $keys, object|string|null $target = null): ?array
    {
        [, , $symbol, $fields] = self::HELPERS[$index];
        $prefix = self::prefix($index, $target);
        $out = $ffi->new('char[256]');
        $entries = IrcChannelList::w451Entries($input, $fields);
        $written = self::export($ffi, $symbol, self::wire($entries, $prefix), $out, 256);
        if ($written === -1) {
            self::assertSame(0, $index, 'Only announces may decline.');
            self::assertMatchesRegularExpression('/[\x00\x80-\xff]/', implode('', $entries), 'Only NUL or non-ASCII announces may decline.');

            return null;
        }
        self::assertSame(count($keys) * 8, $written, 'Native export must execute, not silently fall back.');
        $raw = FFI::string($out, $written);
        if ($entries !== [] && ! str_contains(implode('', $entries), "\0")) {
            self::assertSame(count($keys) * 8, self::export($ffi, $symbol, self::nulWire($entries, $prefix), $out, 256));
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
    private static function assertNativeDispatch(int $index, FFI $ffi, array $input, array $expected, object|string|null $target = null): void
    {
        $class = self::HELPERS[$index][0];
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::useW451RustFfi(true);
        self::assertSame($expected, self::call($index, '', $input, $target));
        $wire = pack('q*', ...array_values($expected));
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, self::cachedFfi($class));
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', "\x01\0\0", "\x01\0\0\0", "\x01\0\0\0\x09\0\0\0abc", "\x02\0\0\0\x01\0\0\0x", self::wire(['entry']).'x', substr(self::wire(['entry']), 0, -1), "\xff\xff\xff\xff",
            pack('VV', 0, 0xFFFFFFFF), self::nulWire(['a', 'b'])."\0c", substr(self::nulWire(['a', 'b', 'c']), 0, -2), pack('VV', 3, 0xFFFFFFFF)];
        foreach (self::HELPERS as $i => [$class, , $symbol]) {
            $keys = count(self::literalCases()[$i][1]);
            $prefix = self::prefix($i);
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            $bad = array_map(fn (string $wire): string => $prefix.$wire, $malformed);
            if ($i === 0) {
                // A missing or unknown format declines as well.
                array_push($bad, "\x01\0\0", pack('V', 6).self::wire(['x']), pack('V', 0xFFFFFFFF).self::wire(['x']));
            }
            foreach ($bad as $wire) {
                FFI::memset($out, 90, 256);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 256), bin2hex($wire));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            $good = self::wire(['entry'], $prefix);
            $required = self::export($ffi, $symbol, $good, $out, 1);
            self::assertSame(-$keys * 8, $required, 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256), 'Insufficient capacity must not write.');
            self::assertSame(-$required, self::export($ffi, $symbol, $good, $out, -$required));
        }
    }

    public function test_declined_entries_keep_the_php_result(): void
    {
        $inputs = [
            ["PRE: [FLAC] A-B\0", 'PRE: [FLAC] Tést-GRP', 'NUKE: A-B [x]'],
            ["\x81\x03a\0b", "\x82\x00\0"],
            ["#a\0,#b:\0", "\0#c:\0pw"],
            ["abcdefghijklmnop\0 (1/2)", "\0 ("],
            ["abcdefghijklmnop\0 (1/2)", "a\0\t(1/2)"],
        ];
        foreach (self::HELPERS as $i => [$class]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $class::useW451RustFfi(true);
            self::assertSame(self::call($i, 'Php', $inputs[$i]), self::call($i, '', $inputs[$i]));
            $raw = self::rawResult($ffi, $i, $inputs[$i], $keys);
            if ($i === 0) {
                self::assertNull($raw, 'The announce grammar declines NUL bytes.');
                self::assertNull(self::rawResult($ffi, 0, [$inputs[0][1]], $keys), 'The announce grammar declines non-ASCII titles.');
            } else {
                self::assertSame(self::call($i, 'Php', $inputs[$i]), $raw, 'Frame, channel and subject scans accept NUL bytes.');
            }
        }
        // Non-ASCII outside a matched title stays on Rust.
        $parser = self::target(0);
        $input = ['PRE: [日本] A.B-C', 'pre ünknown', 'NUKE: A.B-C [ü]'];
        self::assertSame(self::call(0, 'Php', $input), self::rawResult(self::ffi(PreAnnounceParser::class), 0, $input, array_keys(self::literalCases()[0][1]), $parser));
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
        foreach (self::HELPERS as $i => [$class, , , $fields]) {
            $row = array_fill_keys($fields, $object);
            $input = [$object, $row, [$fields[0] => null, $fields[count($fields) - 1] => 'PRE: [X] A-B']];
            $class::useW451RustFfi(true);
            self::assertSame(self::call($i, 'Php', $input), self::call($i, '', $input));
            $class::resetW451RustFfi();
            $class::useW451RustFfi(true);
            $oversized = [$large, array_fill_keys($fields, 'x')];
            self::assertSame(self::call($i, 'Php', $oversized), self::call($i, '', $oversized));
            self::assertSame(self::call($i, 'Php', $oversized), self::call($i, 'Native', $oversized));
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

    private const array ANNOUNCE_PIECES = ['PRE', 'pre', 'INFO', 'info', 'NUKE', 'UNNUKE', 'MODNUKE', 'p2p', ':', ' ', '  ', "\t", '[', ']', '(', ')', '|', ' | ', '#1', 'WIN!', ',', 'F', 'MB', ' MB', 'Files', 'File',
        '.:::', ':::', ':::.', 'FOR', ' with ', 'added.', '[NUKENET]', 'TV-HD', 'FLAC', 'Some.Release-GRP', 'Name-GRP', 'A.B-C', 'no_group', '-', '12', '3.5', "\n", "\x0b", 'x'];

    private const array CHANNEL_PIECES = ['#', '##', 'a', 'chan', '#PreNNTmux', ',', ',', ':', 'pw', ' ', "\t", "\x0b", "\n", "\r", "\0", 'Ab', '#a', '#b', 'x:y', '::'];

    private const array SUBJECT_PIECES = [' (', '(', ')', '/', '1/2)', '(1/1)', '(0/0)', '(00/02)', ' (12/345)', "\t(", "\n(", "\x0b(", "\f(", '1', '0', '99999999999999999999', 'abcdefghijklmnop', 'ABCDEFGH', '12345678',
        'yEnc', ' - ', '"file.rar"', '[1/9]', 'Usenet Index Post', ' ', '.', '-', "\xff", '日本'];

    private static function randomFrame(Randomizer $random): string
    {
        if ($random->getInt(0, 5) === 0) {
            return $random->getBytes($random->getInt(1, 14));
        }
        $first = self::pick($random, ["\x81", "\x82", "\x01", "\x00", "\x80", "\x88", "\x89", "\x8a", "\x83", "\x8b", "\xf1", "\x0f"]);
        $length = $random->getInt(0, 3) === 0 ? $random->getInt(126, 400) : $random->getInt(0, 125);
        $payload = $length === 0 ? '' : $random->getBytes($length);
        $mask = $random->getInt(0, 2) === 0 ? $random->getBytes(4) : null;
        $code = match ($random->getInt(0, 9)) {
            0 => 126,
            1 => 127,
            default => -1,
        };
        $frame = self::wsFrame(ord($first), $payload, $mask, $code);
        if ($code === 127 && $random->getInt(0, 3) === 0) {
            // Reserved bit or an over-limit length.
            $frame = substr_replace($frame, $random->getInt(0, 1) ? "\x80" : "\x00\x00\x00\x00\x00\x40", 2, $random->getInt(0, 1) ? 1 : 6);
        }

        return match ($random->getInt(1, 6)) {
            1 => substr($frame, 0, $random->getInt(0, strlen($frame))),
            2 => $frame.$random->getBytes($random->getInt(1, 8)),
            default => $frame,
        };
    }

    private static function randomEntry(Randomizer $random, int $index): string
    {
        return match ($index) {
            0 => $random->getInt(0, 2) === 0 ? self::sequence($random, self::ANNOUNCE_PIECES, 12) : self::randomAnnounce($random),
            1 => self::randomFrame($random),
            2 => self::sequence($random, self::CHANNEL_PIECES, 16),
            default => self::sequence($random, self::SUBJECT_PIECES, 12),
        };
    }

    /** Near-valid announces in every grammar, with whitespace and case varied around each delimiter. */
    private static function randomAnnounce(Randomizer $random): string
    {
        $ws = fn () => self::pick($random, [' ', ' ', '  ', "\t", '']);
        $title = self::pick($random, ['Some.Release.2024.1080p.WEB-GRP', 'A.B-C', 'Name-GRP', 'no_group', 'Bad-', '-x', 'A(B)&C\'D+E,F-G_1', str_repeat('Long.', 60).'-G']);
        $section = self::pick($random, ['TV-HD', 'FLAC', 'MP3-WEB', '', ' ', 'a b', 'X]']);
        $type = self::pick($random, ['PRE', 'INFO', 'NUKE', 'UNNUKE', 'MODNUKE', 'RENUKE', 'OLDNUKE', 'pre', 'info', 'p2p', 'nuke', 'BOGUS']);
        $reason = self::pick($random, ['dupe', '', '  spaced  ', 'a]b', 'a)b']);
        $files = self::pick($random, ['14', '1', '']);
        $size = self::pick($random, ['67', '158.3', '1.', '']);
        $line = match ($random->getInt(0, 8)) {
            0 => $type.':'.$ws().'['.$section.']'.$ws().$title,
            1 => $type.':'.$ws().$title.$ws().'['.$files.'F'.$ws().$size.'MB]',
            2 => $type.':'.$ws().$title.$ws().'['.$reason.']'.self::pick($random, ['', ' [NUKENET]', '[NUKENET]']),
            3 => '('.$type.')'.$ws().'('.$section.')'.$ws().'('.$title.')',
            4 => '('.$type.')'.$ws().'('.$title.')'.$ws().'('.$files.' '.self::pick($random, ['Files', 'File', 'files']).')'.$ws().'('.$size.' MB)',
            5 => $type.$ws().'|'.$ws().$section.$ws().'|'.$ws().$title,
            6 => $type.$ws().'|'.$ws().$title.' - '.$files.' F & '.$size.' MB',
            7 => '.:::'.$ws().$type.$ws().':::'.$ws().$section.$ws().':::'.$ws().$title.$ws().':::.',
            default => '['.$type.']'.$ws().'['.$section.']'.$ws().$title,
        };

        return self::pick($random, ['', '', ' ', "\t"]).$line.self::pick($random, ['', '', ' ', "\n", 'x']);
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
        foreach (self::HELPERS as $i => [$class, , , $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $targets = $i === 0 ? array_map(fn (string $format): PreAnnounceParser => new PreAnnounceParser($format, $format), PreAnnounceParser::FORMATS) : [self::target($i)];
            foreach ($targets as $target) {
                $cases = [[], self::literalCases()[$i][0]];
                for ($trial = 0; $trial < ($i === 0 ? 400 : 1500); $trial++) {
                    $cases[] = self::randomBatch($random, $fields, $i);
                }
                foreach ($cases as $input) {
                    $expected = self::call($i, 'Php', $input, $target);
                    $raw = self::rawResult($ffi, $i, $input, $keys, $target);
                    if ($raw !== null) {
                        self::assertSame($expected, $raw, $class.' '.json_encode(IrcChannelList::w451Entries($input, $fields), JSON_INVALID_UTF8_SUBSTITUTE));
                    }
                    self::assertSame($expected, self::call($i, 'Native', $input, $target));
                    $class::useW451RustFfi(false);
                    self::assertSame($expected, self::call($i, '', $input, $target));
                    if ($raw !== null) {
                        self::assertNativeDispatch($i, $ffi, $input, $expected, $target);
                    }
                }
            }
        }
    }

    /** Slides every pattern across prefixes and suffixes so boundary and anchor handling match PHP. */
    public function test_patterns_at_every_offset_match_php(): void
    {
        $patterns = [
            ['PRE: [FLAC] Some.Album-GRP', 'INFO: A-B [1F 2MB]', 'NUKE: A-B [x] [NUKENET]', '(PRE) (X) (A-B)', 'pre | X | A-B', '.::: PRE ::: X ::: A-B :::.', '[PRE] [X] A-B'],
            [self::wsFrame(0x81, 'hello'), self::wsFrame(0x82, str_repeat('p', 130), 'abcd'), "\x81\x7f\x80".str_repeat("\0", 7)],
            ['#a,#b:pw', 'a:b,c', '#a:x,#a'],
            ['abcdefghijklmnop (1/2)', 'x (1/2)', '(1/2)'],
            ['abcdefghijklmnop (1/2)', 'x (1/2)', "Usenet Index Post abcdefghijklmnop\t(3/4)"],
        ];
        foreach (self::HELPERS as $i => [$class]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $targets = $i === 0 ? [self::target(0), self::target(0, 'opentrackers'), self::target(0, 'zenet')] : [self::target($i)];
            foreach ($targets as $target) {
                foreach (['a', '.', ' ', ',', "\t", "\n", '('] as $filler) {
                    for ($pad = 0; $pad <= 6; $pad++) {
                        $batch = [];
                        foreach ($patterns[$i] as $pattern) {
                            $batch[] = $pattern;
                            $batch[] = 'q'.str_repeat($filler, $pad).$pattern;
                            $batch[] = $pattern.str_repeat($filler, $pad).'q';
                            $batch[] = $pattern.str_repeat($filler, $pad);
                            $batch[] = str_repeat($filler, $pad).$pattern;
                        }
                        self::assertSame(self::call($i, 'Php', $batch, $target), self::rawResult($ffi, $i, $batch, $keys, $target), $class.' pad '.$pad.' filler '.bin2hex($filler));
                    }
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class]) {
            $class::resetW451RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW451RustFfi());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 256);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, self::call($i, '', $input));
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
            'reproduction_command' => 'NNTMUX_W451_BENCH=1 php vendor/bin/phpunit tests/Unit/RustPreAnnounceWebSocketIrcChannelsObfuscationGateRouterW45ParityTest.php',
        ];
    }

    private static function samples(): int
    {
        $samples = (int) (getenv('NNTMUX_W451_BENCH_SAMPLES') ?: 3000);

        return max(1000, $samples);
    }

    /** A JSON push row as the PreDB stream sends it, framed by the server (unmasked) or a client (masked). */
    private static function jsonFrame(Randomizer $random, int $bytes, bool $masked): string
    {
        $row = json_encode(['action' => 'insert', 'row' => ['name' => 'Some.Show.S01E02.720p.WEB-DL-GRP', 'team' => 'GRP', 'cat' => 'TV-HD', 'pad' => bin2hex($random->getBytes(intdiv($bytes, 2)))]], JSON_THROW_ON_ERROR);

        return self::wsFrame(0x81, $row, $masked ? $random->getBytes(4) : null);
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
            0 => ['PRE: [TV-X264-HD] Some.Show.S01E02.720p.HDTV.x264-GRP', 'INFO: Some.Show.S01E02.720p.HDTV.x264-GRP [35F 1520.4MB]', 'PRE: [FLAC] Artist-Some_Album-WEB-FLAC-2024-GRP',
                'NUKE: Some.Movie.2021.1080p.BluRay.x264-GRP [mislabeled_dupe]', 'PRE: [XXX] Site.24.01.01.Name.XXX.1080p.MP4-GRP'],
            1 => [self::jsonFrame($random, 120, false), self::jsonFrame($random, 300, false), "\x89\x00", self::jsonFrame($random, 60, false), self::jsonFrame($random, 200, true)],
            2 => ['#PreNNTmux', '#PreNNTmux,#alt.binaries.teevee:secret,#scene', '#pre:pw, #nuke ,#info', 'PreNNTmux,#a.b.c,#d:e'],
            default => ['[01/50] - "Some.Show.S01E02.720p.WEB-DL-GRP.part01.rar" yEnc (1/137)', 'a8b7c6d5e4f3a2b1c0d9e8f7 [1/9] - "a8b7c6d5e4f3.par2" yEnc (1/1)',
                'Some.Movie.2021.1080p.BluRay.x264-GRP - [03/90] - "some.movie.r01" yEnc (12/200)', 'Re: question about drivers', '(????) [5/5] - "abc.nzb" yEnc'],
        };
        $source = $typical;
        if ($uncommon) {
            $source = [...$typical, ...self::literalCases()[$index][0], self::randomEntry($random, $index), self::randomEntry($random, $index), null, 7];
            $long = match ($index) {
                0 => ['PRE: [TV-HD] '.str_repeat('Long.Title.', 22).'-GRP', 'MODNUKE: '.str_repeat('Long.', 40).'-GRP ['.str_repeat('reason ', 20).']', str_repeat('PRE: [x] ', 40), ['message' => '  UNNUKE: A.B-C [fixed]  ']],
                1 => [self::jsonFrame($random, 70000, true), self::jsonFrame($random, 9000, false), substr(self::jsonFrame($random, 5000, false), 0, -40), ['frame' => self::jsonFrame($random, 400, true)]],
                2 => [implode(',', array_map(fn (int $n): string => ($n % 3 ? '#' : '').'chan'.($n % 50).($n % 4 ? '' : ':pw'.$n), range(1, 120))), ['channels' => str_repeat(' #a : b ,', 30)]],
                default => [str_repeat('abcdefghijklmnopqrstuvwxyz0123456789 ', 16).'(1/2)', str_repeat('x (', 120), 'Usenet Index Post '.str_repeat('a', 200).' (1/2)', ['Subject' => str_repeat('Long.Subject.', 50).' (01/99)']],
            };
            $source = [...$source, ...$long];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : ($index >= 3 ? 32 : 24); $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W451_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W451_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W451_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w451-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave45.1 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        $samples = self::samples();
        try {
            foreach (self::HELPERS as $index => [$class, $method, , $fields]) {
                $ffi = self::ffi($class);
                $keys = array_keys(self::literalCases()[$index][1]);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = self::call($index, 'Php', $args);
                    self::assertSame($expected, self::rawResult($ffi, $index, $args, $keys));
                    self::assertNativeDispatch($index, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW451RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, self::call($index, '', $args));
                        }
                    }
                    $target = self::target($index);
                    for ($sample = 0; $sample < $samples; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW451RustFfi($native);
                            $start = hrtime(true);
                            $actual = \is_string($target) ? $target::{$method}($args) : $target->{$method}($args);
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
                        'input_sha256' => hash('sha256', self::wire(IrcChannelList::w451Entries($args, $fields), self::prefix($index))),
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
