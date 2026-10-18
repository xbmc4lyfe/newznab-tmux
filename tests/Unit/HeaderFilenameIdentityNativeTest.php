<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\NativeHeaderFilenameIdentity;
use PHPUnit\Framework\TestCase;
use Tests\Support\HeaderFilenameIdentityCorpus;

/**
 * Verdict parity between the PHP rule and its Rust candidate. The golden-fixture test runs
 * everywhere; the native tests need a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.
 */
final class HeaderFilenameIdentityNativeTest extends TestCase
{
    private const string GOLDEN = __DIR__.'/../../docker/app/nzb-yenc-ffi/fixtures/header-filename-identity-v1.json';

    public function test_golden_fixture_matches_the_live_php_rule(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('header-filename-identity-v1', $golden['format']);
        $pairs = HeaderFilenameIdentityCorpus::pairs($golden['generated'], $golden['seed']);
        self::assertCount(count($pairs), $golden['cases'], 'Regenerate with scripts/generate-header-filename-golden.php.');
        self::assertSame(count(HeaderFilenameIdentityTest::subjects()), $golden['unit_cases'], 'Regenerate with scripts/generate-header-filename-golden.php.');
        foreach ($pairs as $index => [$subject, $actual]) {
            [$goldenSubject, $goldenActual, $verdict] = $golden['cases'][$index];
            self::assertSame($goldenSubject, base64_encode($subject), 'Corpus drifted at '.$index);
            self::assertSame($goldenActual, base64_encode($actual), 'Corpus drifted at '.$index);
            self::assertSame($verdict, HeaderFilenameIdentityCorpus::php($subject, $actual), 'Golden verdict stale at '.$index);
        }
    }

    public function test_corpus_is_deterministic_and_exercises_both_verdicts(): void
    {
        $first = HeaderFilenameIdentityCorpus::pairs(300, 7);
        self::assertSame($first, HeaderFilenameIdentityCorpus::pairs(300, 7));
        self::assertNotSame($first, HeaderFilenameIdentityCorpus::pairs(300, 8));
        $accepted = 0;
        foreach ($first as [$subject, $actual]) {
            $accepted += (int) HeaderFilenameIdentityCorpus::php($subject, $actual);
        }
        self::assertGreaterThan(50, $accepted);
        self::assertLessThan(count($first) - 50, $accepted);
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $native = new NativeHeaderFilenameIdentity('/nonexistent/libnzbyenc.so');
        self::assertFalse($native->available());
        self::assertNull($native->matches('"a.mkv"', 'b.mkv'));
    }

    public function test_library_without_the_export_is_rejected(): void
    {
        $libm = array_values(array_filter(['/lib/aarch64-linux-gnu/libm.so.6', '/lib/x86_64-linux-gnu/libm.so.6', '/usr/lib/libm.so.6'], 'is_file'))[0] ?? null;
        if ($libm === null || PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('FFI')) {
            self::markTestSkipped('Requires a Linux CLI with FFI and a system libm.');
        }
        self::assertNull((new NativeHeaderFilenameIdentity($libm))->matches('"a.mkv"', 'b.mkv'));
    }

    public function test_native_verdicts_match_php_over_the_corpus_and_adversarial_bytes(): void
    {
        $native = $this->native();
        $pairs = [...HeaderFilenameIdentityCorpus::pairs(20000, 20261018), ...HeaderFilenameIdentityCorpus::pairs(20000, 7)];
        // Random bytes in either position, including invalid UTF-8 inside Unicode names.
        $state = 99;
        $bytes = static function (int $length) use (&$state): string {
            $out = '';
            while ($length-- > 0) {
                $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
                $out .= chr(($state >> 16) & 0xFF);
            }

            return $out;
        };
        foreach (['"Фильм Релиз Один.mkv"', '"ΟΔΟΣ Release.mkv"', '"Readable Release.mkv"', '"abcdefgh.mkv"'] as $subject) {
            for ($index = 0; $index < 1500; $index++) {
                $pairs[] = [$subject, $bytes(1 + $index % 24)];
                $pairs[] = [$subject, "фильм релиз один\u{436}".$bytes(1 + $index % 6).'.mkv'];
                $pairs[] = [$bytes(1 + $index % 24), 'abcdefgh.mkv'];
                $pairs[] = ["\"abcdefgh\"{$bytes(1 + $index % 3)}", 'abcdefgh.mkv'];
            }
        }
        // Shape fuzz: unquoted subjects built from extension, marker and Unicode fragments, and
        // quoted random tokens that exercise the title and opaque classification.
        $fragments = ['.', '+', 'a', 'B', '1', '_', ' ', '-', 'yEnc', 'YENC', 'par2', 'mkv', 'exe', 'rar', '.mkv', '.par2', '.exe', "\u{e9}", '9', 'xyz', 'Abc', 'tar', '.gz', ':s', "\u{661}", "'", "'s", '"', "N'", '(1/2)', '[1/2]', ' - ', '++', '#', '&', 'S01', 'E02', 'K', 'lz4', 'tar', 'so', "\u{301}"];
        $pick = static function (array $values) use (&$state) {
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

            return $values[($state >> 8) % count($values)];
        };
        $tokenParts = ['A', 'B', 'BB', 'BBC', 'Abc', 'abc', 'xK', 'Qz', 'Doc', 'ument', 'ary', '1', '29', '2026', 'q', 'W', 'zx', 'iPhone', 'Go', 'On', 'Th'];
        for ($index = 0; $index < 8000; $index++) {
            $subject = '';
            for ($i = 1 + $index % 9; $i > 0; $i--) {
                $subject .= $pick($fragments);
            }
            $actual = '';
            for ($i = 1 + $index % 5; $i > 0; $i--) {
                $actual .= $pick($fragments);
            }
            $pairs[] = [$subject, $actual];
            $pairs[] = [$subject, $pick(['setup.exe', 'a.par2', 'payload.mkv', 'x.rar', 'run.py', 'b.deb', 'a.tar.gz', 'e.exe.', 'x.exe:s', 'a.par2:b.mkv'])];
            $token = '';
            for ($i = 2 + $index % 5; $i > 0; $i--) {
                $token .= $pick($tokenParts);
            }
            $pairs[] = ['"'.$token.$pick(['', '.mkv', '.wmv', '.tar.gz', '.par2', '.part01.rar']).'" yEnc', $pick(['x.mkv', $token.'.mkv', strtolower($token).'x.mkv', 'b7Qe1ZrT9wXy.mkv', 'payload.par2', 'Totally.Different.mkv'])];
        }
        $accepted = 0;
        $mismatches = [];
        $declined = 0;
        foreach ($pairs as [$subject, $actual]) {
            $php = HeaderFilenameIdentityCorpus::php($subject, $actual);
            $rust = $native->matches($subject, $actual);
            $accepted += (int) $php;
            if ($rust === null) {
                $declined++;
                // Only non-ASCII input is ever declined (case folding and NFC stay in PHP).
                if (mb_check_encoding($subject.$actual, 'ASCII') && count($mismatches) < 8) {
                    $mismatches[] = 'declined ASCII pair '.bin2hex($subject).' / '.bin2hex($actual);
                }

                continue;
            }
            if ($php !== $rust && count($mismatches) < 8) {
                $mismatches[] = bin2hex($subject).' / '.bin2hex($actual).' php='.var_export($php, true).' rust='.var_export($rust, true);
            }
        }
        fwrite(STDERR, sprintf("\nnative parity: %d pairs, %d accepted, %d rejected, declined-to-PHP=%d, mismatches=%d\n",
            count($pairs), $accepted, count($pairs) - $accepted, $declined, count($mismatches)));
        self::assertLessThan(count($pairs) / 4, $declined);
        self::assertSame([], $mismatches);
        self::assertGreaterThan(5000, $accepted);
        self::assertGreaterThan(5000, count($pairs) - $accepted);
    }

    /**
     * Every Unicode scalar next to a single-quoted name or a yEnc marker must classify exactly like PCRE's
     * \p{L}\p{M}\p{N}_ lookarounds and \s class. The wrapper only loads a library whose tables were generated
     * from this runtime's PCRE, so there is no tolerated difference: a mismatch of any kind fails, and a
     * Rust decline (null) where PHP decides is not an accept.
     */
    public function test_single_quote_lookarounds_agree_for_every_unicode_scalar(): void
    {
        $native = $this->native();
        $declined = 0;
        $mismatches = [];
        for ($code = 0; $code <= 0x10FFFF; $code++) {
            if ($code >= 0xD800 && $code <= 0xDFFF) {
                continue;
            }
            $character = mb_chr($code, 'UTF-8');
            // Before the opening quote a letter, mark, number or underscore blocks the quote, leaving an unquoted
            // subject that names the body. After the closing quote whitespace or a quote keeps the field boundary,
            // and a character glued to the yEnc marker changes the marker's own boundary.
            $subjects = [
                ['other.mkv '.$character."'abc.mkv' yEnc", 'other.mkv'],
                ["other.mkv 'abc.mkv'".$character.'yEnc', 'other.mkv'],
                ["other.mkv x'abc.mkv'".$character.'yEnc', 'other.mkv'],
                ["other.mkv 'abc.mkv' (1/2)".$character.'yEnc', 'other.mkv'],
            ];
            foreach ($subjects as [$subject, $actual]) {
                $php = HeaderFilenameIdentityCorpus::php($subject, $actual);
                $rust = $native->matches($subject, $actual);
                if ($rust === null) {
                    $declined++;

                    continue;
                }
                if ($php === $rust) {
                    continue;
                }
                $mismatches[] = sprintf('U+%04X php=%s rust=%s', $code, var_export($php, true), var_export($rust, true));
            }
        }
        fwrite(STDERR, sprintf("\nlookaround scalars compared; declined=%d (PCRE %s)\n", $declined, PCRE_VERSION));
        self::assertSame([], array_slice($mismatches, 0, 10));
        self::assertSame(0, $declined, 'These subjects are ASCII-named and must never be declined.');
    }

    public function test_a_library_built_from_another_pcre_version_is_refused(): void
    {
        $library = (string) getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        $this->native();
        $other = new NativeHeaderFilenameIdentity($library, '0.0 not this PCRE');
        self::assertFalse($other->available());
        self::assertNull($other->matches('"a.mkv"', 'b.mkv'));
    }

    public function test_oversized_input_is_declined_so_php_decides(): void
    {
        $native = $this->native();
        self::assertNull($native->matches(str_repeat('a', 4097), 'a.mkv'));
        self::assertNull($native->matches('"a.mkv"', str_repeat('a', 4097)));
        self::assertFalse($native->matches(str_repeat('a', 4096), 'a.mkv'));
    }

    public function test_a_valid_non_ascii_executable_name_against_an_unquoted_subject_is_declined(): void
    {
        $native = $this->native();
        self::assertNull($native->matches("Game \u{e9}.exe yEnc", "\u{e9}.exe"));
        // Invalid UTF-8 there is a PCRE compilation failure in PHP, which the port reproduces.
        self::assertFalse($native->matches('Game yEnc', "a\xff.exe"));
        self::assertTrue($native->matches("Game \u{212a}.exe yEnc", 'k.exe'));
    }

    private function native(): NativeHeaderFilenameIdentity
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (! is_string($library) || $library === '' || PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('FFI')) {
            self::markTestSkipped('Requires a Linux CLI with FFI and NNTMUX_TEST_NATIVE_LIBRARY.');
        }
        $native = new NativeHeaderFilenameIdentity($library);
        self::assertTrue($native->available(), 'Library failed its known-answer probe or was built from another PCRE\'s Unicode tables.');

        return $native;
    }
}
