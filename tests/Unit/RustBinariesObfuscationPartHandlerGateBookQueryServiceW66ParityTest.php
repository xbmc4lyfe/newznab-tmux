<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\NativePerArticleObfuscation;
use App\Services\Binaries\PartHandler;
use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\BookProcessingCandidateQuery;
use App\Services\BookService;
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

final class RustBinariesObfuscationPartHandlerGateBookQueryServiceW66ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, its trailer constant and the trailer word count. */
    private const TARGETS = [
        NativePerArticleObfuscation::class => ['w66PerArticleObfuscationPlanBatch', 'nntmux_w663_per_article_obfuscation_batch', 'W66_PER_ARTICLE_OBFUSCATION_FFI_CDEF', 'W66_PER_ARTICLE_OBFUSCATION_TRAILER', 17],
        PartHandler::class => ['w66PartHandlerPlanBatch', 'nntmux_w663_part_handler_batch', 'W66_PART_HANDLER_FFI_CDEF', 'W66_PART_HANDLER_TRAILER', 16],
        PerArticleObfuscationGate::class => ['w66ObfuscationGatePlanBatch', 'nntmux_w663_obfuscation_gate_batch', 'W66_OBFUSCATION_GATE_FFI_CDEF', 'W66_OBFUSCATION_GATE_TRAILER', 18],
        BookProcessingCandidateQuery::class => ['w66BookCandidateQueryPlanBatch', 'nntmux_w663_book_candidate_query_batch', 'W66_BOOK_CANDIDATE_QUERY_FFI_CDEF', 'W66_BOOK_CANDIDATE_QUERY_TRAILER', 24],
        BookService::class => ['w66BookServicePlanBatch', 'nntmux_w663_book_service_batch', 'W66_BOOK_SERVICE_FFI_CDEF', 'W66_BOOK_SERVICE_TRAILER', 27],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        NativePerArticleObfuscation::class => true,
        PartHandler::class => true,
        PerArticleObfuscationGate::class => true,
        BookProcessingCandidateQuery::class => true,
        BookService::class => true,
    ];

    /** One well-formed NUL-free item per class, in the order of TARGETS. */
    private const SAMPLES = [
        '[01/12] - "0123456789abcdef0123.part01.rar" yEnc (1/50)|abcdef0123@ngPost.com|<Part1of50.0123456789abcdefABCD@powerpost2000AA.local>',
        '42|3|50|<part3of50@news.example>|768000',
        'alt.binaries.test|poster@example.com|"file.rar" yEnc (1/50)|20000',
        '7020|1|600|0|pending|',
        'Jane Doe - The Long Road (2019) [Retail EPUB] 978-0-306-40615-7',
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\r", "\x0B", "\x0C", "\r\n", " \t\n\t ", "\x01", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||',
        '||||', '|||||', '||||||', '|||||||', ' | | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g|h|i', '.', '-', ' - ', '-- ', '_', '0', '00',
        '01', '1', '2', '9', '10', '99', '100', '999', '1000', '9999', '15999', '16000', '16001', '65535', '65536', '999999', '1000000', '999999999',
        '1000000000', '9999999999', '99999999999', '999999999999', '1000000000000', '999999999999999', '1000000000000000', '-1', '+1', '+', ' 12 ',
        '1e3', '0x10', '0.5', '123456789012345678', '7000', '7010', '7020', '7030', '7040', '7060', '7999', '8000', '6999', 'pending', 'PENDING',
        ' Matched ', 'failed', 'skipped', 'unknown', 'alt.binaries.', 'ALT.BINARIES.X', 'alt.', 'alt', 'alt.misc', 'a.b.teevee', 'poster@x', '@',
        'a@', '@b.c', 'abcdef01@', 'ABCDEF0123', 'abcdef0', '<a@b>', '<@b>', '<a@>', '<>', '<a>', 'a@b', '<0123456789abcdef@x>', '<0123456789abcde@x>',
        '<0123456789abcdef!@x>', ' (', '(1/1)', '(0/1)', '(2/1)', '(1/0)', '(1234567/1)', '(1/1234567)', '(123456/123456)', 'x (07/09)', '(1/2) (3/4)',
        '((1/2)', '(1/2', '(a/2)', 'yEnc', 'YENC', 'yenc (1/1)', '""', '"a"', '"', '" "', 'x "" y"', '0123456789abcdef', '0123456789abcdef0123456789abcdef',
        '0123456789ABCDEF', '0123456789abcdeg', '123e4567-e89b-12d3-a456-426614174000', '123E4567-E89B-12D3-A456-426614174000',
        '123e4567-e89b-12d3-a456-42661417400', 'q7Zx9Kp2Lm4Rt8', 'bcdfghjklmnp12', 'aB1cD2eF3gH4', 'aaaaaaaaaaaa1', 'epub', 'EPUB', 'mobi', 'azw3', 'azw',
        'pdf', 'cbr', 'cbz', 'djvu', 'm4b', 'mp3', 'retail', 'Illustrated', 'revised', 'unabridged', 'abridged', 'annotated', '2nd ed', '3rd Ed', '1899',
        '1900', '1999', '2000', '2099', '2100', '12019', '978-0-306-40615-7', '9780306406157', '9780306406158', '979-10-90636-07-1', 'x9780306406157',
        '9780306406157x', '-9780306406157-', '0-306-40615-2', '0306406152', '0306406153', '080442957X', '080442957x', '0-8044-2957-X', 'X0-8044-2957-X',
        '978030640615', '97803064061577', 'A - B', 'A - B - C', ' - Title', 'Author - ', 'A - (2019)', 'Title [x]', 'T.i.t.l.e_x', "Caf\xc3\xa9 - Th\xc3\xa9",
        "\xe4\xb9\x85 - \xe4\xb9\x85\xe4\xb9\x85 (2001)", ...self::SAMPLES, '7|1|3|<a@b>|1000', '7|3|3|<c@d>|2048', '7|3|3|<e@f>|4096', '7|2|3|<g@h>|10',
        '7|0|3|<g@h>|10', "7|4|3|\x01|10", '7|4|x|<a@b>|1', '0|1|1|<a@b>|1', '7|1|1|<a@b>|x',
        'Alt.Binaries.Test|p@x|"f" yEnc (1/2)|70000', 'alt.misc||x (1/2)|20000', 'news||x|x', '7030|2|100|||',
        '7010|1|1000|||', '2000|0|0|||', '7020|5|99999|||', '7020|0|5|||12', '7020|1|10|99999|failed|0', '7020|1|10||matched|', '7020|0|0||skipped|',
        '0123456789abcdef0123|abcdef0123@x.y|<a@b>', 'Plain Name (2/3)|joe@example.com|<0123456789abcdefXYZ@host>'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a ', 300), str_repeat('.', 600), str_repeat('|', 300),
            str_repeat('9', 40), str_repeat('9', 15), str_repeat('9', 16), str_repeat('9', 9), str_repeat('9', 10), str_repeat("\xc3\xa9", 200),
            str_repeat("\n", 300), str_repeat('f', 5000), str_repeat('0123456789abcdef', 300), str_repeat('(1/2)', 100), str_repeat('"', 300),
            str_repeat(' - ', 100), str_repeat('978-0-306-40615-8 ', 20).'978-0-306-40615-7', str_repeat('-', 200), str_repeat('x', 4096).'|p|<a@b>', '7|1|1|'.str_repeat('m', 256).'|1', '7|1|1|'.str_repeat('m', 255).'|1',
            str_repeat("\xff", 300)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $class::resetW66RustFfi();
        }
        parent::tearDown();
    }

    public function test_w66_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef, $trailerConstant, $trailer]) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi', 'usesW66RustFfi', 'withW66RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W66_RUST_FFI_DEFAULT', $cdef, $trailerConstant] as $constant) {
                $declared = $reflection->getReflectionConstant($constant);
                if ($declared === false || ! $declared->isPublic() || $declared->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$constant;
                }
            }
            if (! \is_bool($reflection->getConstant('W66_RUST_FFI_DEFAULT')) || $reflection->getConstant($trailerConstant) !== $trailer) {
                $missing[] = $class.' constant values';
            }
            foreach (['w66RustFfiState', 'w66Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w66RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w66RustFfiState signature';
            }
            $uses = new ReflectionMethod($class, 'usesW66RustFfi');
            $with = new ReflectionMethod($class, 'withW66RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' usesW66RustFfi/withW66RustFfi signatures';
            }
            foreach (['enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi'] as $toggle) {
                $method = new ReflectionMethod($class, $toggle);
                if ($method->getNumberOfParameters() !== 0 || (string) $method->getReturnType() !== 'void') {
                    $missing[] = $class.'::'.$toggle.' signature';
                }
            }
        }
        $this->assertSame(array_column(array_values(self::TARGETS), 1), array_values(array_unique(array_column(array_values(self::TARGETS), 1))));
        $this->assertSame([], $missing);
    }

    public function test_w66_adds_no_properties(): void
    {
        // Property sets as of Wave 65 (static and instance), so Wave 66 cannot add state outside w66RustFfiState().
        $expected = [
            NativePerArticleObfuscation::class => ['libraries', 'library', 'libraryPath', 'resolved'],
            PartHandler::class => ['addToPartRepair', 'chunkSize', 'failedPartNumbers', 'ffi', 'insertedPartNumbers', 'lastException', 'parts',
                'touchedBinaryIds', 'useRustFfi', 'w421Ffi', 'w421UseRustFfi'],
            PerArticleObfuscationGate::class => ['ffi', 'useRustFfi', 'w451Ffi', 'w451UseRustFfi'],
            BookProcessingCandidateQuery::class => ['ffi', 'useRustFfi', 'useW50RustFfi', 'w50Ffi', 'w50Out', 'w50OutCap'],
            BookService::class => ['bookBuffer', 'bookCapacity', 'bookFfi', 'bookqty', 'echooutput', 'googleBooks', 'imgSavePath', 'isbnDb', 'itunes',
                'lookupMode', 'lookupSearchname', 'obfuscatedSubjectExtractor', 'openLibrary', 'parsedBookResult', 'parsedIsbn', 'providerRetryAfter',
                'releaseImageService', 'scorer', 'sleeptime', 'useRustFfi', 'w404Ffi', 'w404UseRustFfi'],
        ];
        foreach ($expected as $class => $names) {
            $actual = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
            sort($actual);
            sort($names);
            $this->assertSame($names, $actual, $class);
            $this->assertSame([], preg_grep('/w66/i', $actual), $class);
        }
    }

    public function test_w66_defaults_and_toggles(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], (new ReflectionClass($class))->getConstant('W66_RUST_FFI_DEFAULT'), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class);
            $this->assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()));
            $this->assertFalse($class::withW66RustFfi(false, static fn (): bool => $class::usesW66RustFfi()));
            $this->assertTrue($class::withW66RustFfi(false, static fn (): bool => $class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi())));
            $this->assertFalse($class::withW66RustFfi(true, static fn (): bool => $class::withW66RustFfi(false, static fn (): bool => $class::usesW66RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class);
            try {
                $class::withW66RustFfi(! $class::usesW66RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class.' restores after an exception');
            $class::disableW66RustFfi();
            $this->assertFalse($class::usesW66RustFfi(), $class);
            $this->assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()));
            $this->assertFalse($class::usesW66RustFfi(), $class.' scoped toggle restores an explicit disable');
            $class::enableW66RustFfi();
            $this->assertTrue($class::usesW66RustFfi(), $class);
            $class::resetW66RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class.' reset restores the default');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW66RustFfi();
            $class::withW66RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW66RustFfi(), $class.' vs '.$other);
                }
            });
            $class::disableW66RustFfi();
            foreach (array_keys(self::TARGETS) as $other) {
                $this->assertSame($other === $class ? false : self::DEFAULTS[$other], $other::usesW66RustFfi(), $class.' disable vs '.$other);
            }
            $class::resetW66RustFfi();
        }
        // Both dispatch paths return the PHP reference words, also after a reset drops the cached library.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = [...self::SAMPLES, ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW66RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW66RustFfi(false, static fn (): array => $class::$entry($items)), $class);
            $class::resetW66RustFfi();
            $this->assertSame($php, $class::withW66RustFfi(true, static fn (): array => $class::$entry($items)), $class.' after reset');
            $this->assertSame($class::{$entry.'Php'}([]), $class::withW66RustFfi(true, static fn (): array => $class::$entry([])), $class);
        }
    }

    public function test_w66_existing_public_api_is_unchanged(): void
    {
        // Earlier-wave toggles keep their own defaults.
        $this->assertTrue(PartHandler::usesRustFfi());
        $this->assertFalse(PartHandler::usesW421RustFfi());
        $this->assertTrue(PartHandler::usesW52RustFfi());
        $this->assertFalse(PerArticleObfuscationGate::usesRustFfi());
        $this->assertFalse(PerArticleObfuscationGate::usesW451RustFfi());
        $this->assertTrue(PerArticleObfuscationGate::usesW53RustFfi());
        $this->assertFalse(BookProcessingCandidateQuery::usesRustFfi());
        $this->assertTrue(BookProcessingCandidateQuery::usesW50RustFfi());
        $this->assertFalse(BookService::usesW404RustFfi());
        $this->assertTrue(BookService::usesW53RustFfi());
        $this->assertSame(HeaderParser::NATIVE_MIN_HEADERS, NativePerArticleObfuscation::MIN_HEADERS);
        foreach ([true, false] as $enabled) {
            foreach (array_keys(self::TARGETS) as $class) {
                $class::withW66RustFfi($enabled, function (): void {
                    $this->assertSame([], (new NativePerArticleObfuscation('/nonexistent/library'))->markWithoutSampling([['Number' => 1, 'Subject' => 'x']], 0) ?? []);
                    $this->assertSame('Invalid part number', PartHandler::validationErrorPhp(['matches' => [2 => 0], 'Message-ID' => '<a@b>']));
                    $this->assertSame('Invalid message ID', PartHandler::validationErrorPhp(['matches' => [2 => 1], 'Message-ID' => "\x01"]));
                    $this->assertNull(PartHandler::validationErrorPhp(['matches' => [2 => 1], 'Message-ID' => '<a@b>']));
                    $this->assertTrue((new PartHandler)->shouldPreferPhp(['binaries_id' => 1, 'number' => 1, 'messageid' => '<a@b>', 'partnumber' => 1, 'size' => 9],
                        ['binaries_id' => 1, 'number' => 2, 'messageid' => '<c@d>', 'partnumber' => 1, 'size' => 5]));
                    $this->assertSame(PerArticleObfuscationGate::summarizePartCountsPhp(['a (1/2)', 'b']), PerArticleObfuscationGate::summarizePartCounts(['a (1/2)', 'b']));
                    $this->assertSame([300, 900, 3600, 21600, 7200], [BookProcessingCandidateQuery::retryDelayPhp(1, 0), BookProcessingCandidateQuery::retryDelayPhp(2, 0),
                        BookProcessingCandidateQuery::retryDelayPhp(3, 0), BookProcessingCandidateQuery::retryDelayPhp(4, 0), BookProcessingCandidateQuery::retryDelayPhp(1, 7200)]);
                    $this->assertSame(BookService::w53SubjectIsbnBatchPhp(['Book 9780306406157', 'x']), BookService::w53SubjectIsbnBatch(['Book 9780306406157', 'x']));
                });
            }
        }
    }

    public function test_w66_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, , $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('binaries_w663', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = self::SAMPLES;
            $blob = implode("\0", $items);
            $words = $trailer + \count($items);
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'x|y', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 3, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 128), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 128), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob with a length and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 128), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, \count($items), null, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 128), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 128), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 128), $class);
            // Output overlapping the length column declines.
            $shared = $ffi->new('int64_t[64]');
            FFI::memcpy($shared, pack('P', 1), 8);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, FFI::addr($shared[0]), 1, $shared, 64), $class.' overlap');
        }
    }

    public function test_w66_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , , $trailer]) {
            $items = [...self::SAMPLES, "Caf\xc3\xa9 - Th\xc3\xa9 (2001)", "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w66Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", '7|1|3|<a@b>|1000', "Jane\0Doe - Title 978-0-306-40615-7", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  array<int, int>  $expected  Expected words by position.
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $words = $class::{self::TARGETS[$class][0].'Php'}($items);
        $this->assertSame($expected, array_intersect_key($words, $expected));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, array<int, int>}>
     */
    public static function knownCases(): array
    {
        return [
            'obfuscation: hex core with hex poster, plain subject with random message-id, empty' => [NativePerArticleObfuscation::class,
                ['[1/5] "0123456789abcdef0123.rar" yEnc (1/50)|abcdef0123@x.y|<a@b>', 'Plain Name (2/3)|joe@example.com|<0123456789abcdefXYZ@host>', ''],
                [0 => 44 | 20 << 12 | 28 << 20 | 1 << 28 | 1 << 31 | 1 << 33 | 3 << 35 | 1 << 37 | 65 << 40 | 1 << 48,
                    1 => 16 | 5 << 12 | 14 << 20 | 1 << 31 | 1 << 35 | 1 << 37 | 1 << 39 | 5 << 40, 2 => 0,
                    3 => 2, 4 => 1, 5 => 0, 6 => 1, 7 => 0, 8 => 0, 9 => 1, 10 => 1, 11 => 0, 12 => 1, 13 => 1, 14 => 2, 15 => 0, 16 => 70, 17 => 65, 18 => 1, 19 => 2]],
            'obfuscation: uuid, gibberish and invalid part markers' => [NativePerArticleObfuscation::class,
                ['123e4567-e89b-12d3-a456-426614174000 (0/3)||', 'q7Zx9Kp2Lm4Rt8 (5/3)|nobody|x'],
                [0 => 42 | 12 << 12 | 19 << 20 | 1 << 29 | 2 << 31 | 55 << 40 | 1 << 48, 1 => 20 | 14 << 12 | 20 << 20 | 1 << 30 | 2 << 31 | 2 << 35 | 2 << 37 | 40 << 40 | 2 << 48,
                    2 => 0, 3 => 1, 4 => 1, 5 => 0, 6 => 1, 7 => 1, 8 => 1, 9 => 0, 10 => 1, 11 => 0, 12 => 1, 13 => 0, 14 => 1, 15 => 95, 16 => 55, 17 => 1, 18 => 1]],
            'part handler: final, gap, replace, out-of-order and errors' => [PartHandler::class,
                ['7|1|3|<a@b>|1000', '7|3|3|<c@d>|2048', '7|3|3|<e@f>|4096', '7|2|3|<g@h>|10', '7|0|3|<g@h>|10', "7|4|3|\x01|10"],
                [0 => 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 18 | 1 << 28, 1 => 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 14 | 1 << 15 | 2 << 18 | 3 << 28 | 2 << 48,
                    2 => 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 11 | 1 << 15 | 2 << 18 | 3 << 28 | 4 << 48,
                    3 => 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 13 | 3 << 18 | 2 << 28, 4 => 1 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 3 << 18,
                    6 => 4, 7 => 1, 8 => 1, 9 => 0, 10 => 0, 11 => 3, 12 => 1, 13 => 0, 14 => 1, 15 => 1, 16 => 0, 17 => 3, 18 => 7154, 19 => 1, 20 => 0, 21 => 1]],
            'part handler: repair, overflow and malformed fields' => [PartHandler::class, ['9|1|5|<a@b>|1', '9|7|5|<a@b>|1', '0|1|1|<a@b>|1', '9|2|x|<a@b>|1'],
                [1 => 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 1 << 14 | 1 << 16 | 2 << 18 | 7 << 28, 2 => 3 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 9 | 2 << 18 | 1 << 28,
                    3 => 4 | 1 << 3 | 1 << 5 | 2 << 7 | 1 << 9 | 2 << 18 | 2 << 28, 4 => 2, 5 => 0, 8 => 1, 17 => 1, 18 => 1, 19 => 0]],
            'obfuscation gate: native, sampled, PHP and invalid routes' => [PerArticleObfuscationGate::class,
                ['Alt.Binaries.Test|p@x|"f" yEnc (1/2)|70000', 'alt.misc||x (1/2)|20000', 'news||x|x', 'alt.binaries.x|me|plain|16000', '|p|x (1/2)|9'],
                [0 => 1 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 7 | 1 << 8 | 1 << 9 | 3 << 10 | 70000 << 23 | 17 << 53,
                    1 => 2 | 1 << 4 | 1 << 6 | 1 << 9 | 2 << 10 | 20000 << 23 | 8 << 53, 2 => 3 | 2 << 4 | 4 << 10 | 4 << 53,
                    3 => 1 | 2 << 2 | 1 << 4 | 1 << 10 | 1 << 13 | 16000 << 23 | 14 << 53, 4 => 2 << 2 | 1 << 4 | 1 << 6 | 1000 << 13 | 9 << 23,
                    5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 2, 12 => 1, 13 => 1, 14 => 2, 15 => 1, 16 => 2, 17 => 3, 18 => 1, 19 => 1,
                    20 => 106009, 21 => 4, 22 => 2]],
            'book candidate query: ranks, waits, settled and exhausted' => [BookProcessingCandidateQuery::class,
                ['7020|0|0||pending|', '7030|2|100|||', '7010|1|1000|||', '2000|0|0|||', '7020|5|99999|||', '7020|0|5|||12', '7999|1|300|600||'],
                [0 => 1 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 12 | 1 << 19 | 4 << 20 | 1 << 23, 1 => 2 | 1 << 4 | 1 << 6 | 1 << 8 | 3 << 20 | 800 << 43,
                    2 => 3 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 19 | 4 << 20 | 2 << 23, 3 => 7 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 19,
                    6 => 6 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 3 << 20 | 300 << 43,
                    7 => 1, 8 => 1, 9 => 1, 10 => 2, 11 => 2, 12 => 0, 28 => 300, 29 => 9, 30 => 1]],
            'book candidate query: invalid fields and statuses' => [BookProcessingCandidateQuery::class, ['x|0|0|||', '7020|0|0||weird|', '7020|0|0||matched|', '7020|0|0||SKIPPED|'],
                [0 => 8 | 2 << 4 | 1 << 6 | 1 << 8 | 1 << 19 | 5 << 20, 1 => 1 | 1 << 4 | 1 << 6 | 1 << 8 | 5 << 12 | 1 << 19 | 5 << 20,
                    2 => 1 | 1 << 4 | 1 << 6 | 1 << 8 | 2 << 12 | 1 << 19 | 1 << 20, 3 => 1 | 1 << 4 | 1 << 6 | 1 << 8 | 4 << 12 | 1 << 19 | 2 << 20]],
            'book service: full match, obfuscated title and ISBN-10' => [BookService::class,
                ['Jane Doe - The Long Road (2019) [Retail EPUB] 978-0-306-40615-7', '0123456789abcdef0123', 'Some.Book_Title.0-8044-2957-X.pdf'],
                [0 => 1 | 1 << 4 | 2 << 8 | 1 << 12 | 100 << 13 | 120 << 20 | 306_406_157 << 28, 1 => 1 << 11, 2 => 5 | 1 << 8 | 1 << 10 | 60 << 13 | 80_442_957 << 28,
                    3 => 1, 4 => 1, 8 => 1, 14 => 2, 15 => 1, 23 => 1, 24 => 1, 25 => 1, 26 => 1, 27 => 160, 28 => 1, 29 => 3]],
            'obfuscation empty batch' => [NativePerArticleObfuscation::class, [], array_fill(0, 17, 0)],
            'book service empty batch' => [BookService::class, [], array_fill(0, 27, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, [...self::SAMPLES, ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $label = bin2hex(substr($item, 0, 64));
            $this->assertParity($class, [$item, $item], 'edge item '.$label);
            $this->assertParity($class, [self::SAMPLES[0], $item], 'edge item vs work item '.$label);
            $this->assertParity($class, [$item], 'single edge item '.$label);
        }
        // Edge values in every pipe field of every class's sample.
        $edges = self::edgeCases();
        foreach (self::SAMPLES as $sample) {
            $fields = explode('|', $sample);
            for ($slot = 0; $slot < \count($fields); $slot++) {
                $rows = [];
                foreach ($edges as $edge) {
                    $row = $fields;
                    $row[$slot] = $edge;
                    $rows[] = implode('|', $row);
                }
                $this->assertParity($class, $rows, 'edge field '.$slot.' of '.$sample);
            }
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => self::SAMPLES[1], 7 => self::SAMPLES[3], 'a' => '', 'c' => self::SAMPLES[4]];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][4] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([661, 662, 663] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6633));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 64);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAfFxXeE0123456789||||(/)\"@<>.-_ []yEnc  \n\r+\0\t\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6634)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
        // Long part streams cross the flush threshold several times.
        $stream = [];
        for ($i = 0; $i < 3; $i++) {
            array_push($stream, ...$this->benchItems(PartHandler::class, new Randomizer(new Mt19937(6635 + $i)), false));
        }
        $this->assertParity($class, $stream, 'long part stream');
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
        // call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W663_BENCH_ROUNDS interleaved paired
        // rounds per helper and workload (default 301, minimum 60), 5 iterations per round). Common = one batch of 320
        // well-formed items of the class's format; uncommon = 320 items mixing edge cases, malformed, long, NUL, CR/LF,
        // case-changed and non-ASCII items. Each helper defaults to Rust only when it clears 1.5x on both workloads'
        // mean and p99, per .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5 NTS, release dylib, Apple
        // Silicon, shared host at load average ~6.3-7.4), common mean / p99, uncommon mean / p99:
        // - NativePerArticleObfuscation:  4.93x / 3.94x, 5.40x / 4.52x
        // - PartHandler:                  8.71x / 7.03x, 6.47x / 4.40x
        // - PerArticleObfuscationGate:    4.36x / 3.82x, 2.16x / 1.91x
        // - BookProcessingCandidateQuery: 13.82x / 9.09x, 9.32x / 6.09x
        // - BookService:                  2.91x / 2.55x, 1.90x / 1.78x
        // Every helper clears every gate in all three runs and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // RUN_RUST_FFI_BENCHMARKS=1 php artisan test --compact tests/Unit/RustBinariesObfuscationPartHandlerGateBookQueryServiceW66ParityTest.php --filter benchmark
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 66.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6636));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W663_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6637)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6638)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW66RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $useRust ? $class::enableW66RustFfi() : $class::disableW66RustFfi();
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
        fwrite(STDERR, "\nRustBinariesObfuscationPartHandlerGateBookQueryServiceW66 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW66RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][4] + \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
            $this->assertGreaterThanOrEqual(0, $word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW66RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW66RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
        $state = ['binary' => 0, 'part' => 0, 'total' => 0];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                NativePerArticleObfuscation::class => $this->obfuscationItem($random, $uncommon),
                PartHandler::class => $this->partItem($random, $uncommon, $state),
                PerArticleObfuscationGate::class => $this->gateItem($random, $uncommon),
                BookProcessingCandidateQuery::class => $this->candidateItem($random, $uncommon),
                default => $this->bookItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row), "\x01".$row, $row."\r\n", str_replace('-', '', $row),
                    str_replace(' ', '.', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function hex(Randomizer $random, int $bytes): string
    {
        return bin2hex($random->getBytes($bytes));
    }

    private function obfuscationItem(Randomizer $random, bool $noisy): string
    {
        $total = $random->getInt(1, 999);
        $part = $random->getInt(1, $total);
        $name = match ($random->getInt(0, 5)) {
            0 => $this->hex($random, 16),
            1 => $this->hex($random, 4).'-'.$this->hex($random, 2).'-'.$this->hex($random, 2).'-'.$this->hex($random, 2).'-'.$this->hex($random, 6),
            2 => $random->getBytesFromString('bcdfghjklmnpqrstvwxzBCDFGHJKLMNPQRSTVWXZ0123456789', $random->getInt(12, 40)),
            default => $this->pick($random, ['Some.Show.S01E02.1080p.WEB.x264-GRP', 'The.Film.2019.720p.BluRay', 'Concert Live 2024', "Caf\xc3\xa9.Film.2001"]),
        };
        $subject = $this->pick($random, ['', '[01/12] - ', 'Re: ', '(????) ']).'"'.$name.$this->pick($random, ['.rar', '.part01.rar', '.mkv', '.par2', '.vol01+02.par2', ''])
            .'" yEnc ('.($noisy && $random->getInt(0, 9) === 0 ? $total + 1 : $part).'/'.$total.')'.$this->pick($random, ['', ' 768000', ' [1/5]']);
        $poster = $this->pick($random, [$this->hex($random, 6).'@ngPost.com', 'Poster <poster@example.com>', 'anonymous@nowhere', 'Yenc@power-post.org', 'nobody', '']);
        $mid = '<'.$this->pick($random, [$this->hex($random, 12), 'part'.$part.'of'.$total.'.'.$this->hex($random, 4), 'x']).'@'
            .$this->pick($random, ['powerpost2000AA.local', 'news.example', 'ngPost', '']).'>';

        return implode('|', [$subject, $poster, $noisy && $random->getInt(0, 9) === 0 ? trim($mid, '<>') : $mid]);
    }

    /**
     * @param  array{binary: int, part: int, total: int}  $state
     */
    private function partItem(Randomizer $random, bool $noisy, array &$state): string
    {
        if ($state['binary'] === 0 || $state['part'] >= $state['total'] || $random->getInt(0, 30) === 0) {
            $state = ['binary' => $random->getInt(1, 40), 'part' => 0, 'total' => $random->getInt(1, 120)];
        }
        $roll = $random->getInt(0, 19);
        $part = match (true) {
            $roll === 0 => max(1, $state['part']),
            $roll === 1 => max(1, $state['part'] - $random->getInt(1, 3)),
            $roll === 2 => $state['part'] + 2,
            default => $state['part'] + 1,
        };
        $state['part'] = max($state['part'], $part);
        $mid = $noisy && $random->getInt(0, 9) === 0 ? $this->pick($random, ['', "\x01bad@x", str_repeat('m', 300)]) : '<'.$this->hex($random, 6).'@news.example>';

        return implode('|', [$state['binary'], $part, $random->getInt(0, 9) === 0 ? '' : $state['total'], $mid, $random->getInt(0, 900_000)]);
    }

    private function gateItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, ['alt.binaries.teevee', 'alt.binaries.boneless', 'Alt.Binaries.Mom', 'alt.misc', 'a.b.hdtv', '', $noisy ? 'ALT.' : 'alt.binaries.x']),
            $this->pick($random, ['poster@example.com', 'Yenc@power-post.org', 'nobody', '']),
            $this->pick($random, ['[01/12] - "file.rar" yEnc (1/50)', 'Plain subject', '"'.$this->hex($random, 16).'" yEnc (3/9)', 'x (1/2) yenc', 'Re: (no part)']),
            $this->pick($random, [$random->getInt(0, 15_999), $random->getInt(16_000, 65_535), $random->getInt(65_536, 5_000_000), '', $noisy ? 'x' : '20000'])]);
    }

    private function candidateItem(Randomizer $random, bool $noisy): string
    {
        return implode('|', [$this->pick($random, [7010, 7020, 7020, 7030, 7040, 7060, 7999, 7000, 2040, '']), $this->pick($random, [0, 0, 0, 1, 2, 3, 4, 5, 6]),
            $random->getInt(0, 100_000), $this->pick($random, ['', 0, 120, 3600, 86_400]),
            $this->pick($random, ['', 'pending', 'Pending', 'matched', 'failed', 'skipped', $noisy ? 'weird' : 'pending']), $this->pick($random, ['', '', 0, $random->getInt(1, 99_999)])]);
    }

    private function bookItem(Randomizer $random, bool $noisy): string
    {
        $isbn = '978'.str_pad((string) $random->getInt(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($k = 0; $k < 12; $k++) {
            $sum += (int) $isbn[$k] * ($k % 2 === 0 ? 1 : 3);
        }
        $isbn .= (10 - $sum % 10) % 10;
        if ($noisy && $random->getInt(0, 4) === 0) {
            $isbn[12] = (string) (((int) $isbn[12] + 1) % 10);
        }
        $name = $this->pick($random, ['Jane Doe', 'J.R.R. Tolkien', 'Stephen King', "Ren\xc3\xa9e Smith", '']);
        $title = $this->pick($random, ['The Long Road', 'Dune', 'A_Game_of_Thrones', 'It', 'The.Silmarillion', $this->hex($random, 10)]);

        return ($name === '' ? '' : $name.' - ').$title.' ('.$random->getInt(1950, 2025).') ['.$this->pick($random, ['Retail EPUB', 'MOBI', 'azw3', 'PDF', 'cbr', 'Unabridged M4B', 'mp3', 'txt']).']'
            .$this->pick($random, ['', ' '.$isbn, ' ISBN '.substr($isbn, 0, 3).'-'.substr($isbn, 3, 1).'-'.substr($isbn, 4, 3).'-'.substr($isbn, 7, 5).'-'.substr($isbn, 12), ' 2nd Ed']);
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
     * Calls a Wave 66.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w66RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('binaries_w663', BookService::W66_BOOK_SERVICE_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
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
