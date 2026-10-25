<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\NNTP\RustVerificationFramer;
use App\Services\RegexService;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 46.1 pure CPU collection file count, NNTP verification message-id, framer article-id, yEnc CRC32 and
 * yEnc decoder mode batch summaries.
 * The opt-in benchmark retains raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W461_BENCH=1 php vendor/bin/phpunit tests/Unit/RustRegexNntpFramerYencPartDecoderW46ParityTest.php
 */
final class RustRegexNntpFramerYencPartDecoderW46ParityTest extends TestCase
{
    /** @var list<array{class-string, string, string, list<string>}> */
    private const array HELPERS = [
        [RegexService::class, 'summarizeFileCountBatch', 'nntmux_w461_regex_file_count_batch', ['name', 'subject']],
        [NNTPService::class, 'summarizeMessageIdBatch', 'nntmux_w461_nntp_message_id_batch', ['message_id', 'id']],
        [RustVerificationFramer::class, 'summarizeArticleIdBatch', 'nntmux_w461_framer_article_id_batch', ['id', 'message_id']],
        [YencPart::class, 'summarizeCrcBatch', 'nntmux_w461_yenc_crc_batch', ['crc32', 'pcrc32']],
        [DecoderFactory::class, 'summarizeModeBatch', 'nntmux_w461_yenc_decoder_mode_batch', ['mode', 'decoder']],
    ];

    /** Extra arguments after the rows: the NNTP byte budget and the decoder library, SAPI and OS family. */
    private const array DEFAULT_ARGS = [[], [512], [], [], ['/usr/lib/libnntmux_yenc.so', 'cli', 'Linux']];

    /**
     * Benchmark-qualified defaults. Five paired runs (3000 samples each, Apple M5, PHP 8.5.12 CLI without opcache,
     * PCRE2 10.49, rustc 1.99.1 release build, host load average about 4) gave min common mean/p99 and uncommon
     * mean/p99 speedups of: message-ids 4.19/2.69/5.64/4.32, CRC32 values 2.50/1.75/2.87/2.57 and decoder modes
     * 2.21/1.50/2.24/2.03, so those default to Rust. File counts reached 2.19/1.40/2.21/1.95 and article IDs
     * 2.13/1.46/2.47/2.31: the 32-row common p99 is mostly fixed marshaling cost and fell below 1.5x in one run
     * each, so both stay on PHP.
     */
    private const array DEFAULTS = [false, true, false, true, true];

    /** Earlier public helpers that must stay intact. */
    private const array LEGACY_METHODS = [
        RegexService::class => ['collectionFileCountPhp', 'collectionFileCountNative', 'collectionHash', 'groupQueryStringPhp'],
        NNTPService::class => ['planVerificationMessageId', 'verificationMessageId', 'useRustFfi', 'usesRustFfi', 'resetRustFfi'],
        RustVerificationFramer::class => ['feed', 'close'],
        YencPart::class => ['normalizeCrc32', 'normalizeCrc32Php', 'normalizeCrc32Native', 'setUseRustFfi', 'validSpan'],
        DecoderFactory::class => ['make', 'validMode', 'validModePhp', 'selectsPhp', 'selectsPhpPhp', 'setUseRustFfi'],
    ];

    private const int SEED = 4612026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW461RustFfi();
        }
        parent::tearDown();
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<int|string>|null  $args
     * @return array<string, int>
     */
    private static function call(int $index, string $suffix, array $input, ?array $args = null): array
    {
        $class = self::HELPERS[$index][0];
        $name = self::HELPERS[$index][1].$suffix;
        $result = $class::{$name}($input, ...($args ?? self::DEFAULT_ARGS[$index]));
        self::assertIsArray($result);

        /** @var array<string, int> $result */
        return $result;
    }

    /**
     * Native prefix: the `i64` byte budget, the `u32` decoder environment bits, otherwise none.
     *
     * @param  list<int|string>|null  $args
     */
    private static function prefix(int $index, ?array $args = null): string
    {
        $args ??= self::DEFAULT_ARGS[$index];

        return match ($index) {
            1 => pack('q', $args[0]),
            4 => pack('V', ($args[0] !== '' ? 1 : 0) | ($args[1] === 'cli' ? 2 : 0) | ($args[2] === 'Linux' ? 4 : 0)),
            default => '',
        };
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new \ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW461RustFfi', 'usesW461RustFfi', 'resetW461RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertSame($class, $reflection->getMethod($name)->getDeclaringClass()->getName(), $class.'::'.$name.' must be declared by the class itself.');
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W461_RUST_FFI_DEFAULT'));
            self::assertSame($class === YencPart::class, $reflection->isReadOnly());
            self::assertSame($class === YencPart::class, $reflection->hasMethod('w461State'), $class.' keeps readonly-safe state in a static local.');
            self::assertSame($class !== YencPart::class, $reflection->hasProperty('w461UseRustFfi'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
        $reflection = new \ReflectionClass(RegexService::class);
        self::assertTrue($reflection->hasMethod('w461Entries'));
        self::assertTrue($reflection->hasMethod('w461Invoke'));
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[
                '[01/20] - "Some.Release.part01.rar" yEnc (1/137)',
                ['name' => 'Some.Album [3 of 12] - "track.flac"'],
                ['subject' => 'x_[3_OF_1:'],
                'c 3-00 ',
                'Release (123456/7) none',
                'Release (1/2',
                '(9/9x (1/1)',
                "tab\t2/0\$",
                '',
                null,
                12,
            ], ['total_subjects' => 11, 'empty_subject_count' => 2, 'counted_count' => 6, 'slash_count' => 3, 'of_count' => 2, 'dash_count' => 1, 'single_total_count' => 2, 'zero_total_count' => 2]],
            [[
                '<part1of2.abc@news.example>',
                'part1of2.abc@news.example',
                '<<>>',
                ['message_id' => '<a b>'],
                ['id' => '<a'],
                "\xffok>",
                str_repeat('x', 600),
                "<a\x7fb>",
                '',
                null,
                42,
            ], ['total_ids' => 11, 'empty_id_count' => 2, 'valid_count' => 6, 'invalid_budget_count' => 0, 'empty_after_trim_count' => 1, 'invalid_byte_count' => 2, 'bracketed_count' => 6, 'within_budget_count' => 5, 'needs_format_count' => 5]],
            [[
                'part1of2.abc@news.example',
                'été@x',
                'a b',
                ['id' => "a\tb"],
                "a\x1fb",
                ['message_id' => '<id>'],
                "a\u{3000}b",
                "a\u{85}b",
                "a\u{9f}b",
                "a\xffb",
                str_repeat('a', 131073),
                '',
                null,
                7,
            ], ['total_ids' => 14, 'empty_id_count' => 2, 'valid_count' => 3, 'oversize_count' => 1, 'invalid_utf8_count' => 1, 'space_count' => 4, 'control_count' => 4, 'bracket_count' => 1]],
            [[
                'DEADBEEF',
                '0',
                ['crc32' => 'abc'],
                '123456789',
                ['pcrc32' => 'xyz'],
                '00000000',
                '',
                null,
                1234,
            ], ['total_crcs' => 9, 'empty_crc_count' => 2, 'valid_count' => 5, 'padded_count' => 3, 'uppercase_count' => 1, 'zero_count' => 2, 'too_long_count' => 1, 'non_hex_count' => 1]],
            [[
                'auto',
                'php',
                ['mode' => 'native'],
                'AUTO',
                ['decoder' => ' auto'],
                '',
                null,
                0,
            ], ['total_modes' => 8, 'empty_mode_count' => 2, 'valid_count' => 3, 'auto_count' => 1, 'php_count' => 1, 'native_count' => 1, 'selects_php_count' => 1, 'invalid_count' => 3]],
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
                $class::useW461RustFfi($enabled);
                self::assertSame($expected, self::call($i, '', $input));
                self::assertSame($empty, self::call($i, '', []));
                $single = ['x'];
                self::assertSame(self::call($i, 'Php', $single), self::call($i, '', $single));
            }
        }
    }

    public function test_php_summaries_agree_with_the_existing_helpers(): void
    {
        $regex = new RegexService('collection_regexes');
        self::assertSame('20', $regex->collectionFileCountPhp('[01/20] - "Some.Release.part01.rar" yEnc (1/137)'));
        self::assertSame('0', $regex->collectionFileCountPhp('Release (123456/7) none'));
        self::assertSame('1', $regex->collectionFileCountPhp('x_[3_OF_1:'));
        self::assertSame('ok', NNTPService::planVerificationMessageId('<a@b>', 512)['reason']);
        self::assertSame('a@b', NNTPService::verificationMessageId('<a@b>', 512));
        self::assertSame('invalid_byte', NNTPService::planVerificationMessageId('<a b>', 512)['reason']);
        self::assertSame('0000abcd', YencPart::normalizeCrc32Php('ABCD'));
        self::assertNull(YencPart::normalizeCrc32Php('123456789'));
        self::assertTrue(DecoderFactory::validModePhp('native'));
        self::assertFalse(DecoderFactory::validModePhp('AUTO'));
        self::assertTrue(DecoderFactory::selectsPhpPhp('auto', '', 'cli', 'Linux'));
        self::assertFalse(DecoderFactory::selectsPhpPhp('auto', '/x.so', 'cli', 'Linux'));
        // The article-id summary counts exactly the IDs the framer's constructor validation accepts.
        $validate = new ReflectionMethod(RustVerificationFramer::class, 'validateConnection');
        $framer = (new \ReflectionClass(RustVerificationFramer::class))->newInstanceWithoutConstructor();
        foreach (self::literalCases()[2][0] as $id) {
            if (! \is_string($id) || $id === '') {
                continue;
            }
            $accepted = true;
            try {
                $validate->invoke($framer, ['ids' => [$id], 'mode' => 'body']);
            } catch (\RuntimeException) {
                $accepted = false;
            }
            self::assertSame($accepted ? 1 : 0, RustVerificationFramer::summarizeArticleIdBatchPhp([$id])['valid_count'], bin2hex(substr($id, 0, 16)));
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW461RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW461RustFfi());
            $class::useW461RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW461RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW461RustFfi(), $class.' toggled '.$other);
            }
            $class::useW461RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW461RustFfi());
            $class::useW461RustFfi(false);
            self::assertFalse($class::usesW461RustFfi());
        }
        // Earlier toggles stay separate in both directions.
        $regex = new RegexService;
        NNTPService::resetRustFfi();
        foreach (self::HELPERS as [$class]) {
            $class::useW461RustFfi(true);
        }
        self::assertFalse($regex->useRustFfi);
        self::assertFalse(NNTPService::usesRustFfi());
        self::assertSame('0000abcd', YencPart::normalizeCrc32('ABCD'));
        NNTPService::useRustFfi(true);
        YencPart::setUseRustFfi(true);
        DecoderFactory::setUseRustFfi(true);
        try {
            NNTPService::resetW461RustFfi();
            YencPart::resetW461RustFfi();
            DecoderFactory::resetW461RustFfi();
            self::assertTrue(NNTPService::usesRustFfi());
            self::assertSame(self::DEFAULTS[1], NNTPService::usesW461RustFfi());
            self::assertTrue((new ReflectionProperty(DecoderFactory::class, 'useRustFfi'))->getValue());
        } finally {
            NNTPService::resetRustFfi();
            YencPart::setUseRustFfi(false);
            DecoderFactory::setUseRustFfi(false);
        }
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $cache->getValue();
        self::assertIsArray($saved);
        try {
            foreach (self::HELPERS as $i => [$class]) {
                $class::resetW461RustFfi();
                $class::useW461RustFfi(true);
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
        return 'cleaning_w461|default|'.hash('xxh3', (string) (new \ReflectionClass($class))->getConstant('W461_FFI_CDEF'));
    }

    private static function cachedFfi(string $class): FFI|false|null
    {
        if ($class === YencPart::class) {
            $state = (new ReflectionMethod(YencPart::class, 'w461State'))->invoke(null);
            self::assertIsArray($state);
            $value = $state['ffi'];
        } else {
            $value = (new ReflectionProperty($class, 'w461Ffi'))->getValue();
        }
        self::assertTrue($value === null || $value === false || $value instanceof FFI);

        return $value;
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W461_RUST_FFI_DEFAULT') === true;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w461', (string) (new \ReflectionClass($class))->getConstant('W461_FFI_CDEF'));
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W461 symbols.');

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
     * Raw export result; none of the W461 exports declines a well-formed frame.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $keys
     * @param  list<int|string>|null  $args
     * @return array<string, int>
     */
    private static function rawResult(FFI $ffi, int $index, array $input, array $keys, ?array $args = null): array
    {
        [, , $symbol, $fields] = self::HELPERS[$index];
        $prefix = self::prefix($index, $args);
        $out = $ffi->new('char[256]');
        $entries = RegexService::w461Entries($input, $fields);
        $written = self::export($ffi, $symbol, self::wire($entries, $prefix), $out, 256);
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
     * @param  list<int|string>|null  $args
     */
    private static function assertNativeDispatch(int $index, FFI $ffi, array $input, array $expected, ?array $args = null): void
    {
        $class = self::HELPERS[$index][0];
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::useW461RustFfi(true);
        self::assertSame($expected, self::call($index, '', $input, $args));
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
            if ($i === 1) {
                // A missing or short budget declines as well.
                array_push($bad, "\x01\0\0\0\0\0\0");
            }
            if ($i === 4) {
                // Unknown environment bits or a missing prefix decline.
                array_push($bad, "\x01\0\0", pack('V', 8).self::wire(['auto']), pack('V', 0xFFFFFFFF).self::wire(['auto']));
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
            $input = [$object, $row, [$fields[0] => null, $fields[count($fields) - 1] => 'auto']];
            $class::useW461RustFfi(true);
            self::assertSame(self::call($i, 'Php', $input), self::call($i, '', $input));
            $class::resetW461RustFfi();
            $class::useW461RustFfi(true);
            $oversized = [$large, array_fill_keys($fields, 'x')];
            self::assertSame(self::call($i, 'Php', $oversized), self::call($i, '', $oversized));
            self::assertSame(self::call($i, 'Php', $oversized), self::call($i, 'Native', $oversized));
            if ($class === RustVerificationFramer::class) {
                // An ID over MAX_INIT_BYTES travels as a length marker without its bytes, so Rust still runs.
                self::assertInstanceOf(FFI::class, self::cachedFfi($class));
                self::assertSame(1, self::call($i, 'Native', $oversized)['oversize_count']);
                $ffi = self::ffi($class);
                self::assertNativeDispatch($i, $ffi, $oversized, self::call($i, 'Php', $oversized));
                $edge = [str_repeat('a', 131072), str_repeat('a', 131073), str_repeat("\u{3000}", 43691), 'a'];
                self::assertNativeDispatch($i, $ffi, $edge, self::call($i, 'Php', $edge));
                self::assertSame(2, self::call($i, 'Php', $edge)['oversize_count']);

                continue;
            }
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

    private const array SUBJECT_PIECES = ['(', '[', ' ', "\t", "\n", "\x0b", "\x0c", "\r", '_', '1', '01', '0', '00', '12345', '123456', '/', '-', 'of', 'OF', 'Of', ' of ', '_of_', ')', ']', '$', ':',
        'x', 'yEnc', '"file.rar"', "\xa0", "\x85", "\0", 'é'];

    private const array MESSAGE_ID_PIECES = ['<', '>', '<>', 'a', 'part1of2', '@', 'news.example', '.', ' ', "\t", "\x7f", "\0", "\x1f", "\x20", "\xff", 'é', '$', '-', '%', "\x80"];

    private const array ARTICLE_ID_PIECES = ['part', '@', 'news.example', '.', ' ', "\t", "\n", "\x0b", "\x0c", "\r", "\x1c", "\x1f", "\x7f", '<', '>', "\0", "\xc2\x85", "\xc2\xa0", "\xc2\x9f", "\xc2\xa1",
        "\xe3\x80\x80", "\xe1\xa0\x8e", "\xe1\x9a\x80", "\xe2\x80\x8a", "\xe2\x80\x8b", "\xe2\x80\xa8", "\xe2\x80\xa9", "\xe2\x80\xaf", "\xe2\x81\x9f", "\xef\xbb\xbf", "\xff", "\xc0\xaf", "\xed\xa0\x80",
        "\xf4\x90\x80\x80", "\xf0\x9f\x98\x80", 'é', '日本', "\xe2\x80"];

    private const array CRC_PIECES = ['0', '1', '9', 'a', 'f', 'A', 'F', 'g', 'G', 'x', ' ', '00', 'deadbeef', 'DEADBEEF', '-', 'é', "\0", '+'];

    private const array MODE_PIECES = ['auto', 'php', 'native', 'AUTO', 'Php', ' auto', 'auto ', 'nat', 'nativex', "auto\0", '0', "php\n"];

    private static function randomEntry(Randomizer $random, int $index): string
    {
        return match ($index) {
            0 => self::sequence($random, self::SUBJECT_PIECES, 14),
            1 => self::sequence($random, self::MESSAGE_ID_PIECES, 10),
            2 => self::sequence($random, self::ARTICLE_ID_PIECES, 10),
            3 => self::sequence($random, self::CRC_PIECES, 10),
            default => $random->getInt(0, 3) === 0 ? self::sequence($random, self::MODE_PIECES, 2) : self::pick($random, self::MODE_PIECES),
        };
    }

    /** @return list<int|string> */
    private static function randomArgs(Randomizer $random, int $index): array
    {
        return match ($index) {
            1 => [[-1, 0, 1, 2, 3, 5, 10, 24, 64, 512, PHP_INT_MAX, PHP_INT_MIN][$random->getInt(0, 11)]],
            4 => [self::pick($random, ['', '/usr/lib/x.so']), self::pick($random, ['cli', 'fpm-fcgi', 'CLI']), self::pick($random, ['Linux', 'Darwin', 'linux'])],
            default => [],
        };
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
            $cases = [[[], self::DEFAULT_ARGS[$i]], [self::literalCases()[$i][0], self::DEFAULT_ARGS[$i]]];
            for ($trial = 0; $trial < 1500; $trial++) {
                $cases[] = [self::randomBatch($random, $fields, $i), self::randomArgs($random, $i)];
            }
            foreach ($cases as [$input, $args]) {
                $expected = self::call($i, 'Php', $input, $args);
                self::assertSame($expected, self::rawResult($ffi, $i, $input, $keys, $args), $class.' '.json_encode([RegexService::w461Entries($input, $fields), $args], JSON_INVALID_UTF8_SUBSTITUTE));
                self::assertSame($expected, self::call($i, 'Native', $input, $args));
                $class::useW461RustFfi(false);
                self::assertSame($expected, self::call($i, '', $input, $args));
                self::assertNativeDispatch($i, $ffi, $input, $expected, $args);
            }
        }
    }

    /** Slides every pattern across prefixes and suffixes so boundary and anchor handling match PHP. */
    public function test_patterns_at_every_offset_match_php(): void
    {
        $patterns = [
            ['(1/2)', '[03 of 12]', '_4_OF_5:', ' 6-7$', '(123456/1)', '(1/123456)', "\t1/2\r"],
            ['<a@b>', 'a@b', '<<x>>', '<a b>'],
            ['a@b', "\u{3000}", "\u{85}", '<x>', "\xff"],
            ['abc', 'DEADBEEF', '0'],
            ['auto', 'php', 'native'],
        ];
        foreach (self::HELPERS as $i => [$class]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            foreach (['a', '.', ' ', '<', "\t", "\n", '(', '0'] as $filler) {
                for ($pad = 0; $pad <= 6; $pad++) {
                    $batch = [];
                    foreach ($patterns[$i] as $pattern) {
                        $batch[] = $pattern;
                        $batch[] = 'q'.str_repeat($filler, $pad).$pattern;
                        $batch[] = $pattern.str_repeat($filler, $pad).'q';
                        $batch[] = $pattern.str_repeat($filler, $pad);
                        $batch[] = str_repeat($filler, $pad).$pattern;
                    }
                    self::assertSame(self::call($i, 'Php', $batch), self::rawResult($ffi, $i, $batch, $keys), $class.' pad '.$pad.' filler '.bin2hex($filler));
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class]) {
            $class::resetW461RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW461RustFfi());
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
            'pcre' => PCRE_VERSION,
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
            'reproduction_command' => 'NNTMUX_W461_BENCH=1 php vendor/bin/phpunit tests/Unit/RustRegexNntpFramerYencPartDecoderW46ParityTest.php',
        ];
    }

    private static function samples(): int
    {
        $samples = (int) (getenv('NNTMUX_W461_BENCH_SAMPLES') ?: 3000);

        return max(1000, $samples);
    }

    /**
     * Common batches hold typical production rows for each helper; uncommon batches mix array rows, missing
     * fields, malformed and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + $index));
        $typical = match ($index) {
            0 => ['[01/50] - "Some.Show.S01E02.720p.WEB-DL-GRP.part01.rar" yEnc (1/137)', 'a8b7c6d5e4f3a2b1c0d9e8f7 [1/9] - "a8b7c6d5e4f3.par2" yEnc (1/1)',
                'Some.Movie.2021.1080p.BluRay.x264-GRP - [03/90] - "some.movie.r01" yEnc (12/200)', 'Re: question about drivers', 'Artist - Album [3 of 12] "03 - Track.flac" yEnc'],
            1, 2 => ['part1of137.5f3a9c2e1b@news.example.com', '<Ab3dE5fG7hI9jK1l$mN3oP5@nyuu>', 'part12of200.a8b7c6d5e4f3@powerpost2000AA.local', '<1a2b3c4d5e6f7a8b9c0d@JBinUp.local>',
                '<f0e1d2c3b4a5.968778@reader.example.org>'],
            3 => ['5f3a9c2e', 'DEADBEEF', '0a1b2c3d', 'c0ffee', 'F00DBABE', '12345678'],
            default => ['auto', 'auto', 'native', 'php', 'auto', 'native'],
        };
        $source = $typical;
        if ($uncommon) {
            $source = [...$typical, ...self::literalCases()[$index][0], self::randomEntry($random, $index), self::randomEntry($random, $index), null, 7];
            $long = match ($index) {
                0 => [str_repeat('Long.Subject.Name.', 30).' (1/2)', str_repeat('(1/', 80), str_repeat('x', 300).' [99999 of 99999]', ['subject' => str_repeat('a-1-', 100)]],
                1 => [str_repeat('a', 300).'@b', '<<<'.str_repeat('x', 200).'>>>', str_repeat('<a ', 50), ['id' => str_repeat('é', 120)]],
                2 => [str_repeat('é', 200).'@x', str_repeat('part', 100).'@news.example', str_repeat('日本', 80)."\u{3000}", ['message_id' => str_repeat('a', 600).'<']],
                3 => [str_repeat('f', 40), 'abcdefgh', ['pcrc32' => 'ABCDEF01'], 'xyz'],
                default => ['AUTO', ['decoder' => 'native'], str_repeat('auto', 20), 'php '],
            };
            $source = [...$source, ...$long];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 32; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W461_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W461_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W461_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w461-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave46.1 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        $samples = self::samples();
        try {
            foreach (self::HELPERS as $index => [$class, $method, , $fields]) {
                $ffi = self::ffi($class);
                $keys = array_keys(self::literalCases()[$index][1]);
                $extra = self::DEFAULT_ARGS[$index];
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = self::call($index, 'Php', $args);
                    self::assertSame($expected, self::rawResult($ffi, $index, $args, $keys));
                    self::assertNativeDispatch($index, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW461RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, self::call($index, '', $args));
                        }
                    }
                    for ($sample = 0; $sample < $samples; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW461RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}($args, ...$extra);
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
                        'input_sha256' => hash('sha256', self::wire(RegexService::w461Entries($args, $fields), self::prefix($index))),
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
