<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IrcChannelList;
use App\Support\Par2FileHashEvidence;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use App\Support\Utf8;
use FFI;
use JsonException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Parity and boundary suite for Rust FFI support helpers in `rust/pipeline-ffi/src/predb.rs`:
 * - `Par2FileHashEvidence`: `sizeDecimal` / `littleEndianSize`
 * - `ReleaseArchiveNamingEvidence`: `boundedNames` / `decodeNames`
 * - `Utf8`: `clean` / `isValidNative`
 * - `IrcChannelList`: `parse` / `parseNative`
 *
 * Paired randomized benchmark summary (macOS Darwin arm64, `cargo build --release`, N=40 interleaved pairs):
 * - `Par2FileHashEvidence::littleEndianSize` (common 8-byte): PHP 1145.7 ns mean / 1324.1 ns p99 vs Rust 635.3 ns mean / 791.5 ns p99 -> 1.80x mean, 1.67x p99 (adopted in production).
 * - `Par2FileHashEvidence::littleEndianSize` (p99 large u64): PHP 2657.0 ns mean / 2841.2 ns p99 vs Rust 1375.2 ns mean / 1600.5 ns p99 -> 1.93x mean, 1.78x p99 (adopted in production).
 * - `ReleaseArchiveNamingEvidence::decodeNames` (common N=32, 2.7 KB JSON): PHP 5197.6 ns mean / 8598.8 ns p99 vs Rust 2625.4 ns mean / 5360.8 ns p99 -> 1.98x mean, 1.60x p99 (adopted for JSON >= 256 bytes).
 * - `ReleaseArchiveNamingEvidence::decodeNames` (common N=64, 5.4 KB JSON): PHP 10272.4 ns mean / 14770.8 ns p99 vs Rust 4743.0 ns mean / 7486.2 ns p99 -> 2.17x mean, 1.97x p99 (adopted for JSON >= 256 bytes).
 * - `ReleaseArchiveNamingEvidence::decodeNames` (uncommon p99 N=256, 21.8 KB JSON): PHP 45885.1 ns mean / 196404.2 ns p99 vs Rust 20560.2 ns mean / 50485.4 ns p99 -> 2.23x mean, 3.89x p99 (adopted for JSON >= 256 bytes).
 * - `Par2FileHashEvidence::sizeDecimal`: PHP 275.4 ns mean / 302.2 ns p99 vs Rust 461.8 ns mean / 489.5 ns p99 -> 0.60x mean, 0.62x p99 (retained native PHP in production; `sizeDecimalNative` tested).
 * - `ReleaseArchiveNamingEvidence::boundedNames` (N=32..300): PHP 1228.2-5245.0 ns vs Rust 1604.5-11796.2 ns -> 0.44x-0.77x due to array implode/explode marshaling (retained native PHP in production; `boundedNamesNative` tested).
 * - `Utf8::clean` (fresh 45..32737 bytes): PHP 36.2-2827.3 ns vs Rust 81.8-3531.0 ns -> 0.44x-0.80x because Zend caches `IS_STR_VALID_UTF8` on `zend_string` (retained native PHP in production; `cleanNative` / `isValidNative` tested).
 * - `IrcChannelList::parse` (N=2..64): PHP 299.9-9308.1 ns vs Rust 277.0-8996.2 ns -> 1.03x-1.49x due to PHP associative array reconstruction (retained native PHP in production; `parseNative` tested).
 */
final class RustSupportHelpersParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int32_t nntmux_par2_size_decimal_valid(const char *s, size_t len);
        ssize_t nntmux_par2_le_u64_to_decimal(const char *bytes, size_t len, char *out_buf, size_t out_cap);
        ssize_t nntmux_archive_witness_bounded_names_nul(const char *input, size_t input_len, size_t count, uint8_t *overflow_out, char *out_buf, size_t out_cap);
        ssize_t nntmux_archive_witness_decode_names_json(const char *json, size_t json_len, char *out_buf, size_t out_cap);
        int32_t nntmux_utf8_is_valid(const char *input, size_t len);
        ssize_t nntmux_irc_channel_list_parse(const char *input, size_t len, char *out_buf, size_t out_cap);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        Par2FileHashEvidence::setUseRustFfi(true);
        ReleaseArchiveNamingEvidence::setUseRustFfi(true);
        $ffi = RustPipelineLibrary::resolveDomain('support_helpers_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
    }

    protected function tearDown(): void
    {
        Par2FileHashEvidence::setUseRustFfi(true);
        ReleaseArchiveNamingEvidence::setUseRustFfi(true);
        parent::tearDown();
    }

    public function test_c_abi_resize_and_error_contracts(): void
    {
        $tiny = $this->ffi->new('char[2]');
        $overflow = $this->ffi->new('uint8_t');

        $le = pack('P', 123456);
        // @phpstan-ignore method.notFound
        $needed = $this->ffi->nntmux_par2_le_u64_to_decimal($le, 8, $tiny, 2);
        self::assertSame(-6, $needed);

        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_par2_le_u64_to_decimal('short', 5, $tiny, 2));

        $nulNames = "Episode1.mkv\0Episode2.mkv";
        // @phpstan-ignore method.notFound
        $neededNames = $this->ffi->nntmux_archive_witness_bounded_names_nul(
            $nulNames,
            strlen($nulNames),
            2,
            FFI::addr($overflow),
            $tiny,
            2,
        );
        self::assertSame(-strlen($nulNames), $neededNames);

        $json = '["Episode1.mkv","Episode2.mkv"]';
        // @phpstan-ignore method.notFound
        $neededJson = $this->ffi->nntmux_archive_witness_decode_names_json($json, strlen($json), $tiny, 2);
        self::assertSame(-strlen($nulNames), $neededJson);

        $irc = '#chan1:secret1,#chan2:secret2';
        // @phpstan-ignore method.notFound
        $neededIrc = $this->ffi->nntmux_irc_channel_list_parse($irc, strlen($irc), $tiny, 2);
        self::assertLessThan(-2, $neededIrc);
    }

    public function test_par2_size_decimal_parity(): void
    {
        $cases = [
            0,
            1,
            42,
            PHP_INT_MAX,
            -1,
            PHP_INT_MIN,
            null,
            true,
            false,
            12.5,
            [],
            '0',
            '1',
            '768000',
            '4294967295',
            '4294967296',
            '9223372036854775807',
            '9223372036854775808',
            '18446744073709551614',
            '18446744073709551615',
            '18446744073709551616',
            '18446744073709551619',
            '18446744073709551620',
            '99999999999999999999',
            '100000000000000000000',
            '',
            '00',
            '01',
            '007',
            '-0',
            '-1',
            '+1',
            "42\n",
            "\n42",
            ' 42',
            '42 ',
            "42\0",
            '123a456',
        ];

        mt_srand(20261024);
        $alphabet = "0123456789-+ \n\0a";
        for ($i = 0; $i < 500; $i++) {
            $len = mt_rand(1, 22);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $cases[] = $s;
        }

        foreach ($cases as $idx => $case) {
            self::assertSame(
                Par2FileHashEvidence::sizeDecimal($case),
                Par2FileHashEvidence::sizeDecimalNative($case),
                'sizeDecimal mismatch at case #'.$idx,
            );
        }
    }

    public function test_par2_little_endian_size_parity_and_exceptions(): void
    {
        $bytesList = [
            "\x00\x00\x00\x00\x00\x00\x00\x00",
            "\x01\x00\x00\x00\x00\x00\x00\x00",
            "\xff\xff\xff\x7f\x00\x00\x00\x00",
            "\x00\x00\x00\x80\x00\x00\x00\x00",
            "\xff\xff\xff\xff\x00\x00\x00\x00",
            "\x00\x00\x00\x00\x01\x00\x00\x00",
            "\xff\xff\xff\xff\xff\xff\xff\x7f",
            "\x00\x00\x00\x00\x00\x00\x00\x80",
            "\x01\x00\x00\x00\x00\x00\x00\x80",
            "\xfe\xff\xff\xff\xff\xff\xff\xff",
            "\xff\xff\xff\xff\xff\xff\xff\xff",
        ];

        mt_srand(20261025);
        for ($i = 0; $i < 500; $i++) {
            $b = '';
            for ($j = 0; $j < 8; $j++) {
                $b .= chr(mt_rand(0, 255));
            }
            $bytesList[] = $b;
        }

        foreach ($bytesList as $idx => $bytes) {
            Par2FileHashEvidence::setUseRustFfi(false);
            $expected = Par2FileHashEvidence::littleEndianSize($bytes);

            Par2FileHashEvidence::setUseRustFfi(true);
            $actual = Par2FileHashEvidence::littleEndianSize($bytes);
            $native = Par2FileHashEvidence::littleEndianSizeNative($bytes);

            self::assertSame($expected, $actual, 'littleEndianSize mismatch at #'.$idx);
            self::assertSame($expected, $native, 'littleEndianSizeNative mismatch at #'.$idx);
        }

        foreach (['', '1234567', '123456789'] as $bad) {
            self::assertNull(Par2FileHashEvidence::littleEndianSizeNative($bad));
            try {
                Par2FileHashEvidence::littleEndianSize($bad);
                self::fail('Expected RuntimeException for invalid byte length.');
            } catch (RuntimeException $e) {
                self::assertSame('A PAR2 file size requires eight bytes.', $e->getMessage());
            }
        }
    }

    public function test_release_archive_naming_evidence_bounded_names_parity(): void
    {
        $phpMethod = new ReflectionMethod(ReleaseArchiveNamingEvidence::class, 'boundedNamesPhp');

        $validList = [];
        for ($i = 0; $i < 40; $i++) {
            $validList[] = sprintf('Show.S01E%02d.1080p.WEB-DL-GRP.mkv', $i % 25);
        }

        $overCountList = [];
        for ($i = 0; $i < 260; $i++) {
            $overCountList[] = sprintf('File_%03d.rar', $i);
        }

        // Cumulative bytes saturation test: 16 names of 2048 bytes = 32768 bytes, plus 1 name of 1 byte
        $exact32k = [];
        for ($i = 0; $i < 16; $i++) {
            $exact32k[] = sprintf('%04d_', $i).str_repeat('a', 2043);
        }
        $over32kThenSmall = [...$exact32k, 'x'];
        $largePushOver32kThenSmall = [
            ...array_slice($exact32k, 0, 15),
            str_repeat('b', 3000), // pushes $bytes from 30720 to 33720 (> 32768)
            'tiny_after_overflow.mkv', // must also be rejected because $bytes stays > 32768
        ];

        $cases = [
            [],
            ['Single.File.mkv'],
            $validList,
            ['Valid.mkv', '', 'AfterEmpty.mkv'],
            ['Valid.mkv', str_repeat('z', 4096), str_repeat('y', 4097), 'AfterTooLong.mkv'],
            ['Valid.MÖVIE-映画.mkv', "Invalid.\xff.mkv", 'AfterInvalidUtf8.mkv'],
            ['01', '1', '01', '001'],
            $overCountList,
            $exact32k,
            $over32kThenSmall,
            $largePushOver32kThenSmall,
        ];

        foreach ($cases as $idx => $names) {
            $expected = $phpMethod->invoke(null, $names);
            $actual = ReleaseArchiveNamingEvidence::boundedNamesNative($names);
            self::assertSame($expected, $actual, 'boundedNames mismatch at case #'.$idx);
        }

        // Embedded NUL declines to null in boundedNamesNative
        self::assertNull(ReleaseArchiveNamingEvidence::boundedNamesNative([" Embedded\0Nul.mkv ", 'Other.mkv']));
    }

    public function test_release_archive_naming_evidence_decode_names_parity_and_exceptions(): void
    {
        $service = (new ReflectionClass(ReleaseArchiveNamingEvidence::class))->newInstanceWithoutConstructor();
        $decodeMethod = new ReflectionMethod(ReleaseArchiveNamingEvidence::class, 'decodeNames');

        $smallList = ['Show.S01E01.mkv', 'Show.S01E01.nfo'];
        $mediumList = [];
        for ($i = 0; $i < 32; $i++) {
            $mediumList[] = sprintf('Show.Name.2026.S01E%02d.1080p.WEB-DL.H264-GRP/Show.S01E%02d.mkv', $i, $i);
        }
        $maxList = [];
        for ($i = 0; $i < 256; $i++) {
            $maxList[] = sprintf('Archive/Part_%03d_Payload.rar', $i);
        }
        $escapedList = [
            "Folder\\Subfolder/Quote\"Name\"\tTab\nNewline\rCR\x08BS\x0cFF/Slash.mkv",
            'Second.File.MÖVIE-映画.mkv',
            'Padding.'.str_repeat('x', 300).'.mkv',
        ];

        $validJsons = [
            '[]',
            "  [ \n\t ]  ",
            json_encode($smallList, JSON_THROW_ON_ERROR),
            json_encode($mediumList, JSON_THROW_ON_ERROR),
            json_encode($maxList, JSON_THROW_ON_ERROR),
            json_encode($escapedList, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            // JSON with \uXXXX escapes (declined by Rust, handled transparently by PHP fallback)
            '["Show.\u0041\u0042.S01E01.mkv","'.str_repeat('p', 300).'"]',
        ];

        foreach ($validJsons as $idx => $json) {
            ReleaseArchiveNamingEvidence::setUseRustFfi(false);
            $expected = $decodeMethod->invoke($service, $json);

            ReleaseArchiveNamingEvidence::setUseRustFfi(true);
            $actual = $decodeMethod->invoke($service, $json);

            self::assertSame($expected, $actual, 'decodeNames mismatch at valid JSON #'.$idx);
            if (! str_contains($json, '\\u')) {
                self::assertSame($expected, ReleaseArchiveNamingEvidence::decodeNamesNative($json));
            }
        }

        $invalidJsons = [
            '',
            '{}',
            '"not-an-array"',
            '123',
            '[123]',
            '["ok", null]',
            '["ok", true]',
            '["ok", []]',
            '["dup.mkv", "dup.mkv"]',
            '[""]',
            '["'.str_repeat('a', 4097).'"]',
            json_encode(array_map(static fn (int $i): string => "f_{$i}.mkv", range(1, 257)), JSON_THROW_ON_ERROR),
            '["unclosed"',
            '["trailing",]',
            '["extra"] trailing',
        ];

        foreach ($invalidJsons as $idx => $badJson) {
            self::assertNull(
                ReleaseArchiveNamingEvidence::decodeNamesNative($badJson),
                'Expected decodeNamesNative to return null on invalid JSON #'.$idx,
            );

            ReleaseArchiveNamingEvidence::setUseRustFfi(false);
            $phpEx = null;
            try {
                $decodeMethod->invoke($service, $badJson);
            } catch (RuntimeException|JsonException $e) {
                $phpEx = $e::class.':'.$e->getMessage();
            }

            ReleaseArchiveNamingEvidence::setUseRustFfi(true);
            $rustEx = null;
            try {
                $decodeMethod->invoke($service, $badJson);
            } catch (RuntimeException|JsonException $e) {
                $rustEx = $e::class.':'.$e->getMessage();
            }

            self::assertNotNull($phpEx, 'Expected exception on invalid JSON #'.$idx);
            self::assertSame($phpEx, $rustEx, 'Exception mismatch on invalid JSON #'.$idx);
        }
    }

    public function test_utf8_clean_and_is_valid_native_parity(): void
    {
        $samples = [
            '',
            'Pure ASCII Subject Line (1/25)',
            'Valid UTF-8: MÖVIE Фильм 映画 🎵',
            "\xc3\xb6", // valid 2-byte
            "\xe6\x98\xa0", // valid 3-byte
            "\xf0\x9f\x8e\xb5", // valid 4-byte
            "\x80", // lone continuation byte
            "\xff", // invalid byte
            "\xc0\xaf", // overlong 2-byte
            "\xe0\x80\xaf", // overlong 3-byte
            "\xf0\x80\x80\xaf", // overlong 4-byte
            "\xed\xa0\x80", // UTF-16 high surrogate U+D800
            "\xed\xbf\xbf", // UTF-16 low surrogate U+DFFF
            "\xf4\x90\x80\x80", // > U+10FFFF
            "Truncated 2-byte \xc3",
            "Truncated 3-byte \xe6\x98",
            "Truncated 4-byte \xf0\x9f\x8e",
            "Latin-1 \xe9\xe8\xfc text",
        ];

        mt_srand(20261026);
        for ($i = 0; $i < 500; $i++) {
            $len = mt_rand(1, 120);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= chr(mt_rand(0, 255));
            }
            $samples[] = $s;
        }

        foreach ($samples as $idx => $sample) {
            self::assertSame(
                mb_check_encoding($sample, 'UTF-8'),
                Utf8::isValidNative($sample),
                'Utf8::isValidNative mismatch at sample #'.$idx,
            );
            self::assertSame(
                Utf8::clean($sample),
                Utf8::cleanNative($sample),
                'Utf8::cleanNative mismatch at sample #'.$idx,
            );
        }
    }

    public function test_irc_channel_list_parse_parity(): void
    {
        $inputs = [
            null,
            '',
            '   ',
            ',,,',
            '# , : , #: , # : pass , :onlypass',
            '#PreNNTmux',
            'PreNNTmux',
            '##doublehash:secret',
            '#chan1,#chan2:pass2,chan3:pass3',
            " \t #chan1 : pass1 \n , \r chan2 :   , #chan3 : pass:with:colons ",
            '0,1:0,#0:override0',
            '#dup:first,other,dup:second,#dup:',
            '#dup:first,other,dup:second',
        ];

        mt_srand(20261027);
        $alphabet = "abc0123#, :;\t\n\r";
        for ($i = 0; $i < 300; $i++) {
            $len = mt_rand(1, 80);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $inputs[] = $s;
        }

        foreach ($inputs as $idx => $input) {
            foreach (['#PreNNTmux', '#CustomDefault'] as $default) {
                $expected = IrcChannelList::parse($input, $default);
                $actual = IrcChannelList::parseNative($input, $default);
                self::assertSame($expected, $actual, "IrcChannelList mismatch at #{$idx} (default={$default})");
            }
        }

        // Embedded NUL inside a channel name declines to null in parseNative
        self::assertNull(IrcChannelList::parseNative("#chan\0bad:pass"));
    }
}
