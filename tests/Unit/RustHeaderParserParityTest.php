<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BlacklistConstants;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RustHeaderParserParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int64_t nntmux_header_parse_subject(const char *input, size_t len);
        int64_t nntmux_header_parse_batch(const char *input, size_t len, size_t count, char *out, size_t cap);
        int64_t nntmux_blacklist_compile(const char *input, size_t len, char *out, size_t cap);
        int32_t nntmux_blacklist_match(const char *rules, size_t rules_len, const char *subject, size_t subject_len, const char *poster, size_t poster_len, const char *message_id, size_t message_id_len);
        C;

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('header_parser', self::CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library before running native parity tests.');

        return $ffi;
    }

    /**
     * @return array<int, string>|null
     *
     * @phpstan-impure PCRE results depend on mutable process limits and locale.
     */
    private static function phpSubject(string $subject): ?array
    {
        if (! preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches)) {
            return null;
        }
        if (stripos($subject, 'yEnc') === false) {
            $matches[1] .= ' yEnc';
        }

        return $matches;
    }

    /** Exercise the ABI even for short subjects deliberately routed to PHP in production.
     * @return array<int, string>|null
     */
    private static function rawSubject(string $subject): ?array
    {
        $ffi = self::ffi();
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        $packed = $ffi->nntmux_header_parse_subject($subject, \strlen($subject));
        self::assertIsInt($packed);
        if ($packed < 0) {
            return self::phpSubject($subject);
        }
        $decode = new \ReflectionMethod(HeaderParser::class, 'subjectCaptures');

        return $decode->invoke(null, $subject, $packed);
    }

    /** @return iterable<string, array{string}> */
    public static function subjects(): iterable
    {
        foreach ([
            'file.rar yEnc (12/45)', '[03/62] "file.part03.rar" yenc (00012/00045) trailing',
            '(3 of 62) file YENC (3/62)', '[3-62] file (0/0)', 'file (12/45) yEnC',
            'file (1/2) (3/4)', 'file [1/2]', 'file (1 of 2)', 'file yEnc', '', 'plain text',
            'Usenet Index Post file (1/2)', ' Usenet Index Post file (1/2)',
            'usenet Index Post file (1/2)', "\t\n file (1/2)", '  (1/2)', " \n(1/2)",
            "file\n (1/2)", "file\nother (1/2)", "file\r\n(1/2)", "file\x0b(1/2)",
            "file\x0c(1/2)", "file\0name yEnc (1/2)", "\xff\xfe (01/02)",
            'ファイル名 é yEnc (01/99)', "file\xc2\xa0(1/2)", 'file (١/٢)',
            "file (1/2\0)", 'file (1/2', 'file(1/2)', 'file (1//2)',
            'file ('.str_repeat('9', 30).'/'.str_repeat('0', 30).')',
            str_repeat('x', 1800).' yEnc (1/2)', str_repeat(' ', 300).'file (1/2)',
            'file (1/'.str_repeat('0', 5000).'x)',
        ] as $index => $subject) {
            yield 'subject '.$index => [$subject];
        }
    }

    #[DataProvider('subjects')]
    public function test_subject_captures_are_byte_exact(string $subject): void
    {
        self::ffi();
        $native = new HeaderParser(useRust: true);
        $php = new HeaderParser(useRust: false);
        $missing = new HeaderParser(libraryPath: '/nonexistent/header-parser.so');
        $expected = self::phpSubject($subject);
        self::assertSame($expected, $native->parseSubject($subject));
        self::assertSame($expected, self::rawSubject($subject));
        self::assertSame($expected, $php->parseSubject($subject));
        self::assertSame($expected, $missing->parseSubject($subject));
    }

    public function test_native_subject_export_is_packed_and_declines_unrepresentable_spans(): void
    {
        $ffi = self::ffi();
        $subject = 'file yEnc (001/009)';
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        $packed = $ffi->nntmux_header_parse_subject($subject, strlen($subject));
        self::assertIsInt($packed);
        self::assertGreaterThan(0, $packed);
        self::assertSame(9, ($packed >> 8) & 65535);
        self::assertSame(3, ($packed >> 32) & 255);
        self::assertSame(3, ($packed >> 40) & 255);
        self::assertSame(2, $packed >> 48);
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(0, $ffi->nntmux_header_parse_subject('plain text', 10));
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(-1, $ffi->nntmux_header_parse_subject(str_repeat('x', 70000), 70000));
    }

    public function test_batch_export_validates_framing_capacity_and_binary_subjects(): void
    {
        $ffi = self::ffi();
        $subjects = ["file\0name (1/2)", 'plain', ' Usenet Index Post (03/05)'];
        $framed = '';
        foreach ($subjects as $subject) {
            $framed .= pack('V', strlen($subject)).$subject;
        }
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 24);
        self::assertNotNull($scratch);
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(-24, $ffi->nntmux_header_parse_batch($framed, strlen($framed), 3, $scratch, 1));
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(24, $ffi->nntmux_header_parse_batch($framed, strlen($framed), 3, $scratch, 24));
        $packed = $ffi->cast('int64_t *', FFI::addr($scratch[0]));
        foreach ($subjects as $index => $subject) {
            // @phpstan-ignore method.notFound (domain-scoped C ABI)
            self::assertSame($ffi->nntmux_header_parse_subject($subject, strlen($subject)), $packed[$index]);
        }
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(-1, $ffi->nntmux_header_parse_batch($framed, strlen($framed) - 1, 3, $scratch, 24));
        // @phpstan-ignore method.notFound (domain-scoped C ABI)
        self::assertSame(-1, $ffi->nntmux_header_parse_batch($framed, strlen($framed), 2, $scratch, 24));
    }

    public function test_randomized_subjects_and_batches_preserve_captures_order_counters_and_repair(): void
    {
        self::ffi();
        mt_srand(42173);
        $headers = [['Subject' => 'missing number']];
        $prefixes = ['', ' ', "\t", "\n", 'Usenet Index Post ', '[03/62] ', '(3 of 62) '];
        $names = ['file.rar', 'abc yEnc', 'ファイル', "a\0b", "\xff\xfe", 'a  b', "a\nb", ''];
        $separators = [' ', '  ', "\n", "\r\n", "\t", "\x0b", "\x0c", ''];
        $native = new HeaderParser(useRust: true);
        for ($i = 0; $i < 4000; $i++) {
            $subject = $prefixes[mt_rand(0, count($prefixes) - 1)].$names[mt_rand(0, count($names) - 1)]
                .$separators[mt_rand(0, count($separators) - 1)]
                .(mt_rand(0, 5) ? '('.mt_rand(0, 200).'/'.mt_rand(0, 200).')' : '[01/99]')
                .(mt_rand(0, 3) ? '' : ' yEnC suffix');
            self::assertSame(self::phpSubject($subject), $native->parseSubject($subject), bin2hex($subject));
            self::assertSame(self::phpSubject($subject), self::rawSubject($subject), bin2hex($subject));
            $headers[2 * $i + 1] = ['Number' => (string) ($i + 1), 'Subject' => $subject, ':bytes' => $i, 'From' => 'poster', 'Message-ID' => '<id>'];
        }
        $blacklist = new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return str_contains($msg['Subject'], 'abc');
            }
        };
        $native = new HeaderParser($blacklist, false, useRust: true);
        $php = new HeaderParser($blacklist, false, useRust: false);
        foreach ([[false, null], [true, [1, 3, 7, 100]], [true, [2 => true, 6 => true]], [true, []]] as [$repair, $missing]) {
            self::assertSame($php->parse($headers, 'alt.test', $repair, $missing), $native->parse($headers, 'alt.test', $repair, $missing));
        }
        $native->reset();
        $php->reset();
        self::assertSame($php->parse($headers, 'alt.test'), $native->parse($headers, 'alt.test'));
    }

    public function test_long_native_batches_keep_binary_captures_and_shared_scratch_ownership(): void
    {
        self::ffi();
        $rules = [];
        for ($i = 0; $i < 40; $i++) {
            $rules[] = (object) ['id' => $i + 1, 'msgcol' => ($i % 3) + 1, 'regex' => $i === 39 ? 'blocked' : 'absent_'.$i];
        }
        $native = new HeaderParser(self::blacklist($rules, [], true), false);
        $php = new HeaderParser(self::blacklist($rules, [], false), false, useRust: false);
        $headers = [];
        for ($i = 0; $i < 64; $i++) {
            $headers[$i * 2] = ['Number' => (string) ($i + 1), 'Subject' => str_repeat("ファイル.\0", 70).($i % 7 === 0 ? 'blocked' : 'file')."\0\xff yEnc (00".$i.'/0099)', 'Bytes' => $i % 2 ? 0 : 123, ':bytes' => 456];
        }
        self::assertSame($php->parse($headers, 'alt.test'), $native->parse($headers, 'alt.test'));
        self::assertSame(10, $native->getBlacklistedCount());
        self::assertCount(64, (new \ReflectionMethod(HeaderParser::class, 'batchSubjects'))->invoke($native, $headers));
        self::assertSame($php->parse($headers, 'alt.test', true, [2, 4, 16]), $native->parse($headers, 'alt.test', true, [2, 4, 16]));
        $headers[0]['Subject'] = str_repeat('a', 1024).' malformed (1/2';
        self::assertSame($php->parse($headers, 'alt.test'), $native->parse($headers, 'alt.test'));
    }

    public function test_pcre_resource_limits_are_respected_after_native_initialization(): void
    {
        self::ffi();
        $native = new HeaderParser;
        $subject = str_repeat('name.', 210).' yEnc (1/2)';
        self::assertSame(self::phpSubject($subject), $native->parseSubject($subject));
        $previous = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            self::assertSame(self::phpSubject($subject), $native->parseSubject($subject));
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    /**
     * @param  list<object>  $black
     * @param  list<object>  $white
     */
    private static function blacklist(array $black, array $white, bool $rust): BlacklistService
    {
        return new class($black, $white, $rust) extends BlacklistService
        {
            /**
             * @param  list<object>  $black
             * @param  list<object>  $white
             */
            public function __construct(private array $black, private array $white, bool $rust)
            {
                parent::__construct(useRust: $rust);
            }

            /** @return list<object> */
            public function getBlacklist(bool $activeOnly = true, int|string $opType = -1, string $groupName = '', bool $groupRegex = false): array
            {
                return $opType === BlacklistConstants::OPTYPE_BLACKLIST ? $this->black : $this->white;
            }
        };
    }

    public function test_blacklist_rule_order_whitelist_priority_and_activity_ids_match_php(): void
    {
        self::ffi();
        foreach (['needle', '^needle', 'needle$', '^needle$', 'a\\.b', '(a)\\1', '(?<=x)y', '[', 'a/b', '\\w+', 'ファイル', '^$', 'a|b'] as $pattern) {
            $black = [];
            for ($i = 0; $i < 32; $i++) {
                $black[] = (object) ['id' => $i + 1, 'msgcol' => ($i % 3) + 1, 'regex' => $i === 29 ? $pattern : 'absent_'.$i];
            }
            foreach ([[], [(object) ['id' => 90, 'msgcol' => 1, 'regex' => 'allow']]] as $white) {
                $php = self::blacklist($black, $white, false);
                $rust = self::blacklist($black, $white, true);
                foreach (['NEEDLE', "needle\n", "needle\r\n", 'xneedle', 'a.b', 'aa', 'xy', 'allow needle', 'ファイル', "\xffNEEDLE\0", ''] as $subject) {
                    $msg = ['Subject' => $subject, 'From' => $subject, 'Message-ID' => $subject];
                    self::assertSame($php->isBlackListed($msg, 'alt.test'), $rust->isBlackListed($msg, 'alt.test'), $pattern.' '.bin2hex($subject));
                    self::assertSame($php->getAndClearIdsToUpdate(), $rust->getAndClearIdsToUpdate());
                }
            }
        }
        $rule = (object) ['id' => 1, 'msgcol' => 1, 'regex' => 'needle'];
        self::assertTrue(self::blacklist([$rule], [], true)->isBlackListed(['Subject' => 'NEEDLE'], 'alt.test'));
    }

    public function test_native_whitelists_and_blacklists_record_both_first_matching_ids(): void
    {
        self::ffi();
        $black = $white = [];
        for ($i = 0; $i < 40; $i++) {
            $white[] = (object) ['id' => 100 + $i, 'msgcol' => 2, 'regex' => $i === 38 ? '^allowed$' : 'absent_'.$i];
            $black[] = (object) ['id' => 200 + $i, 'msgcol' => 3, 'regex' => $i === 30 ? 'blocked' : 'absent_'.$i];
        }
        $service = self::blacklist($black, $white, true);
        self::assertTrue($service->isBlackListed(['From' => "ALLOWED\n", 'Message-ID' => '<BLOCKED>'], 'alt.test'));
        self::assertSame([138, 230], $service->getAndClearIdsToUpdate());
        self::assertSame([], $service->getAndClearIdsToUpdate());
        self::assertFalse($service->isBlackListed(['From' => 'allowed', 'Message-ID' => '<clean>'], 'alt.test'));
        self::assertSame([138], $service->getAndClearIdsToUpdate());
        self::assertTrue($service->isBlackListed(['From' => 'denied', 'Message-ID' => '<clean>'], 'alt.test'));
        self::assertSame([], $service->getAndClearIdsToUpdate());
    }
}
