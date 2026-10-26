<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\Categorizers\ConsoleCategorizer;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\ReleaseContext;
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

final class RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant and the trailer word count. */
    private const TARGETS = [
        AbstractCategorizer::class => ['w60CategorizerGateBatch', 'nntmux_w603_abstract_categorizer_gate_batch', 'W60_ABSTRACT_CATEGORIZER_FFI_CDEF', 34],
        BookCategorizer::class => ['w60BookTokenBatch', 'nntmux_w603_book_token_batch', 'W60_BOOK_CATEGORIZER_FFI_CDEF', 28],
        ConsoleCategorizer::class => ['w60ConsolePlatformBatch', 'nntmux_w603_console_platform_batch', 'W60_CONSOLE_CATEGORIZER_FFI_CDEF', 36],
        GroupNameCategorizer::class => ['w60GroupNameBatch', 'nntmux_w603_group_name_batch', 'W60_GROUP_NAME_CATEGORIZER_FFI_CDEF', 19],
        ReleaseContext::class => ['w60ReleaseContextBatch', 'nntmux_w603_release_context_batch', 'W60_RELEASE_CONTEXT_FFI_CDEF', 44],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        AbstractCategorizer::class => true,
        BookCategorizer::class => true,
        ConsoleCategorizer::class => true,
        GroupNameCategorizer::class => true,
        ReleaseContext::class => true,
    ];

    /** Subclasses that declare their own Wave 60 toggle instead of inheriting AbstractCategorizer's. */
    private const OWN_TOGGLE_SUBCLASSES = [BookCategorizer::class, ConsoleCategorizer::class, GroupNameCategorizer::class];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x0B", "\r\n", "\x7f", "\xff", "caf\xc3\xa9", '|', '||', '|||', '||||', '|||||', '||||||',
        ' | | | | | ', "\t|\t|\t", "\0|\0|\0|\0|\0|\0", 'a|b|c|d|e|f|g', '.', '..', '...', '-', '--', '_', '[', '(', 'ab', 'abc', 'a.b', 'a b',
        'a_b', 'x-GRP', 'x-G', 'x-ABCDEFGHIJKLMNO', 'x-ABCDEFGHIJKLMNOP', '-AB', 'AB', 'x-AB ', '1899', '1900', '1999', '2000', '2099', '2100',
        '12019', '20191', 'x2019x', '0', '00', '000', '50', '100', '101', '0100', '999', '1000', '-1', '+1', '1e3', '0x10', '0.5', '0.49', '0.499999',
        '0.5000001', '0.7', '0.85', '1', '1.0', '1.0005', '1.001', '2', '999.999', '1000.0', '.5', '0.', '0..5', '1.2.3', '0,5', ' 0.5', '0999',
        '8999', '9000', '123456789', '1234567890', '1048575', '1048576', '52428799', '52428800', '524287999', '524288000', '999999999999',
        '1000000000000', 'epub', 'EPUB', '.epub', 'x.epub', 'x.EPUB', 'x.pdf', 'x.cbz', 'x.m4b', 'x.mp3', 'x.zip', 'x.toolong', 'x.', 'x.e-b',
        'ISBN', '9781234567890', '9791234567890', '9771234567890', '978123456789', 'retail', 'comic', 'manga', 'magazine', 'issue', 'python',
        'audiobook', 'unabridged', 'kindle', 'PS5', 'ps4', 'PS3', 'vita', 'psp', 'xbox', 'XBOX ONE', 'xbox series', 'xbox 360', 'xbox.x',
        'x360', 'switch', 'NSP', 'xci', 'wii', 'wii u', 'wiiu', 'wbfs', '3ds', 'cia', 'nds', 'snes', 'gba', 'CUSA12345', 'cusa1234',
        'CUSA123456', 'PPSA0123', 'PPSA012345', 'PPSA0123456', 'PPSA012', 'pal', 'NTSC', 'usa', 'us', 'eur', 'jpn', 'asia', 'kor', 'region',
        'region free', 'regionfree', 'multi', 'multi5', 'MULTI12', 'multi123', 'multix', 'dlc', 'update', 'patch', 'pkg', 'iso', 'rom', 'roms',
        'alt.binaries.', 'alt.binaries.x', 'ALT.BINARIES.TV', 'alt.binaries.anime', 'alt.binaries.multimedia.tv', 'alt.binaries.movies.x264',
        'alt.binaries.erotica', 'alt.binaries.sounds.mp3', 'alt.binaries.games', 'alt.binaries.warez', 'alt.binaries.e-book', 'alt.x',
        'alt.', 'comp.os', 'misc', '.alt', 'alt..x', 'alt.x.', 'a.b.teevee', 'A.B.', 'alt.binaries.c++', 'x+y_z-w', 'x y', 'x/y',
        'x.alt.binaries.tv', 'S01', 's1', 'S123', 'S1234', 'S01E02', 'Show.S01.Complete', 'Show S1', 'Show-s001', 'XS01', 'S', 's.',
        'OF.', 'of.x', 'OnlyFans', 'Only-Fans', 'only_fans', 'Only Fans', 'Onlyfans.x', 'web-dl', 'WEBRip', 'web.dl', 'AMZN', 'german',
        'MULTi', 'proper', 'RePack', 'rerip', 'xxx', 'XXX', '2160p', '1080P', '720p', '480p', '4k', 'true', 'TRUE', 'yes', 'on', 'false',
        'No', 'OFF', '01', 'maybe', 'a@b', '@b', 'a@', 'a@@b', 'a@b@c', '@', 'poster@example.com', 'Poster <x@y.z>'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 1100), str_repeat('.', 300), str_repeat('a.', 20),
            str_repeat('alt.binaries.', 30), str_repeat('S01 ', 50), str_repeat('|', 300), str_repeat('9', 40), str_repeat('x', 16),
            str_repeat('x', 17), 'tok'.str_repeat('e', 20).'n', str_repeat("\xc3\xa9", 200), str_repeat('epub ', 100), str_repeat('a@', 50),
            'alt.binaries.'.str_repeat('x', 300), str_repeat('-', 40).'AB'];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w60_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW60RustFfi', 'withW60RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W60_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->isFinal() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W60_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            foreach (['w60RustFfiState', 'w60Invoke'] as $private) {
                $method = new ReflectionMethod($class, $private);
                if (! $method->isPrivate() || ! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$private;
                }
            }
            $state = new ReflectionMethod($class, 'w60RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if ($params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w60RustFfiState signature';
            }
            $this->collectToggleSignatureErrors($class, 'usesW60RustFfi', 'withW60RustFfi', $missing);
            // Toggle state lives in function-local statics only, never in static properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w60')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
        }
        // AbstractCategorizer also exposes class-named toggles that subclass overrides of usesW60RustFfi() cannot shadow.
        foreach (['usesAbstractCategorizerW60RustFfi', 'withAbstractCategorizerW60RustFfi'] as $method) {
            $reflection = new ReflectionMethod(AbstractCategorizer::class, $method);
            if (! $reflection->isPublic() || ! $reflection->isStatic() || $reflection->getDeclaringClass()->getName() !== AbstractCategorizer::class) {
                $missing[] = AbstractCategorizer::class.'::'.$method;
            }
        }
        $this->collectToggleSignatureErrors(AbstractCategorizer::class, 'usesAbstractCategorizerW60RustFfi', 'withAbstractCategorizerW60RustFfi', $missing);
        // Each class declares its own FFI declaration constant name, so none shadows a sibling's or a subclass's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w60_parent_and_child_toggles_are_independent(): void
    {
        $subclasses = $this->categorizerSubclasses();
        $this->assertNotEmpty($subclasses);
        foreach (self::OWN_TOGGLE_SUBCLASSES as $class) {
            $this->assertContains($class, $subclasses);
            $this->assertSame(AbstractCategorizer::class, get_parent_class($class));
        }
        $inheriting = array_values(array_filter($subclasses, static fn (string $class): bool => (new ReflectionMethod($class, 'usesW60RustFfi'))->getDeclaringClass()->getName() === AbstractCategorizer::class));
        $this->assertNotEmpty($inheriting, 'other categorizers inherit the AbstractCategorizer toggle');
        foreach ($inheriting as $class) {
            $this->assertSame(AbstractCategorizer::class, (new ReflectionMethod($class, 'withW60RustFfi'))->getDeclaringClass()->getName(), $class);
            $this->assertSame(AbstractCategorizer::class, (new ReflectionClass($class))->getReflectionConstant('W60_RUST_FFI_DEFAULT')?->getDeclaringClass()->getName(), $class);
        }

        foreach ([true, false] as $enabled) {
            // Flipping the parent leaves every own-toggle child at its default; inheriting categorizers follow the parent.
            AbstractCategorizer::withW60RustFfi($enabled, function () use ($enabled, $inheriting): void {
                $this->assertSame($enabled, AbstractCategorizer::usesW60RustFfi());
                $this->assertSame($enabled, AbstractCategorizer::usesAbstractCategorizerW60RustFfi());
                foreach (self::OWN_TOGGLE_SUBCLASSES as $class) {
                    $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class);
                    $this->assertSame($enabled, $class::usesAbstractCategorizerW60RustFfi(), $class.' still reports the parent through the class-named toggle');
                }
                foreach ($inheriting as $class) {
                    $this->assertSame($enabled, $class::usesW60RustFfi(), $class);
                }
            });
            // Flipping a child leaves the parent, its siblings and the inheriting categorizers alone.
            foreach (self::OWN_TOGGLE_SUBCLASSES as $child) {
                $child::withW60RustFfi($enabled, function () use ($child, $enabled, $inheriting): void {
                    $this->assertSame($enabled, $child::usesW60RustFfi(), $child);
                    $this->assertSame(self::DEFAULTS[AbstractCategorizer::class], AbstractCategorizer::usesW60RustFfi(), $child);
                    $this->assertSame(self::DEFAULTS[AbstractCategorizer::class], $child::usesAbstractCategorizerW60RustFfi(), $child);
                    foreach (self::OWN_TOGGLE_SUBCLASSES as $sibling) {
                        if ($sibling !== $child) {
                            $this->assertSame(self::DEFAULTS[$sibling], $sibling::usesW60RustFfi(), $child.' vs '.$sibling);
                        }
                    }
                    foreach ($inheriting as $class) {
                        $this->assertSame(self::DEFAULTS[AbstractCategorizer::class], $class::usesW60RustFfi(), $child.' vs '.$class);
                    }
                });
            }
            // The class-named parent toggle and its usesW60RustFfi() alias share one override.
            AbstractCategorizer::withAbstractCategorizerW60RustFfi($enabled, function () use ($enabled): void {
                $this->assertSame($enabled, AbstractCategorizer::usesW60RustFfi());
                foreach (self::OWN_TOGGLE_SUBCLASSES as $class) {
                    $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class);
                }
            });
        }
        // The parent's batch dispatches on the parent toggle even when called through a child.
        $items = ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', ''];
        $php = AbstractCategorizer::w60CategorizerGateBatchPhp($items);
        $this->assertSame($php, BookCategorizer::withW60RustFfi(false, static fn (): array => BookCategorizer::w60CategorizerGateBatch($items)));
        $this->assertSame($php, BookCategorizer::withW60RustFfi(true, static fn (): array => BookCategorizer::w60CategorizerGateBatch($items)));
    }

    public function test_w60_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class);
            $this->assertTrue($class::withW60RustFfi(true, static fn (): bool => $class::usesW60RustFfi()));
            $this->assertFalse($class::withW60RustFfi(false, static fn (): bool => $class::usesW60RustFfi()));
            $this->assertTrue($class::withW60RustFfi(false, static fn (): bool => $class::withW60RustFfi(true, static fn (): bool => $class::usesW60RustFfi())));
            $this->assertFalse($class::withW60RustFfi(true, static fn (): bool => $class::withW60RustFfi(false, static fn (): bool => $class::usesW60RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class);
            try {
                $class::withW60RustFfi(! $class::usesW60RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class.' restores after an exception');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW60RustFfi();
            $class::withW60RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW60RustFfi(), $class.' vs '.$other);
                }
            });
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', 'Author - Title (2019) Retail.epub|2048', 'Game.PAL.XBOX.360-GRP|alt.binaries.games',
                ' Alt.Binaries.Multimedia.Anime ', 'Show.S01.1080p.WEB-DL-GRP|alt.binaries.tv|poster@x.y|1048576|5040|no', ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW60RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW60RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w60_existing_public_api_is_unchanged(): void
    {
        $this->assertSame(['total' => 2, 'empty' => 1, 'dotted' => 1, 'spaced' => 0, 'underscored' => 0, 'bracketed' => 0, 'group_suffix' => 1, 'year' => 1],
            AbstractCategorizer::summarizeReleaseNameShapeBatchPhp(['Some.Movie.2019-GRP', '']));
        $this->assertSame(['total' => 1, 'comic' => 0, 'audiobook' => 0, 'magazine' => 0, 'ebook' => 1, 'unknown' => 0, 'retail' => 1],
            BookCategorizer::summarizeBookFormatBatchPhp(['Title.Retail.epub']));
        $this->assertSame(['total' => 1, 'playstation' => 1, 'xbox' => 0, 'nintendo' => 0, 'unknown' => 0, 'region' => 1],
            ConsoleCategorizer::summarizeConsolePlatformBatchPhp(['Game.PAL.PS4-GRP']));
        $this->assertSame(1, GroupNameCategorizer::summarizeGroupNameBatchPhp(['alt.binaries.anime'])['anime']);
        $this->assertSame(1, ReleaseContext::summarizeContextMarkerBatchPhp(['Show.Season.1.Complete'])['pack']);
        // Earlier-wave toggles keep their own defaults.
        $this->assertSame(AbstractCategorizer::W463_RUST_FFI_DEFAULT, AbstractCategorizer::usesW463RustFfi());
        $this->assertSame(BookCategorizer::W423_RUST_FFI_DEFAULT, BookCategorizer::usesW423RustFfi());
        $this->assertSame(ConsoleCategorizer::W423_RUST_FFI_DEFAULT, ConsoleCategorizer::usesW423RustFfi());
        $this->assertSame(GroupNameCategorizer::W423_RUST_FFI_DEFAULT, GroupNameCategorizer::usesW423RustFfi());
        $this->assertSame(ReleaseContext::W443_RUST_FFI_DEFAULT, ReleaseContext::usesW443RustFfi());
        $context = new ReleaseContext('Some.Book.2019.EPUB', 1, 'alt.binaries.e-book');
        $this->assertSame('ebook_format', (new BookCategorizer)->categorizePhp($context)->matchedBy);
        $this->assertSame('group_name_book', (new GroupNameCategorizer)->categorizePhp($context)->matchedBy);
        $this->assertSame('ps4', (new ConsoleCategorizer)->categorizePhp(new ReleaseContext('Game.PS4-GRP', 1))->matchedBy);
        $this->assertTrue((new ReleaseContext('Show.S01.Complete', 1))->hasStandaloneSeasonTokenPhp());
    }

    public function test_w60_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('categorize_w603', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            $items = ['Game.PAL.PS4-GRP|alt.binaries.games|40|0.9|1180', 'alt.binaries.anime'];
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
        }
    }

    public function test_w60_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', 'Author - Title (2019) Retail.epub|2048', 'X.PS4.CUSA12345.Region.Free|a.b.games',
                'alt.binaries.sounds.mp3', 'Show.S01.1080p.WEB-DL-GRP|alt.binaries.tv|poster@x.y|1048576|5040|no', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w60Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, list<int>}>
     */
    public static function knownCases(): array
    {
        $fill = static fn (int $n, array $set): array => array_replace(array_fill(0, $n, 0), $set);

        return [
            'abstract: matched, malformed confidence and empty name' => [AbstractCategorizer::class,
                ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', 'ab|g|200|x|', '|||'],
                [1 | 1 << 2 | 40 << 4 | 1 << 11 | 3 << 13 | 900 << 15 | 1 << 35 | 2 << 37 | 1 << 41 | 1 << 45 | 1 << 46 | 2 << 50 | 19 << 52,
                    2 | 1 << 2 | 50 << 4 | 3 << 11 | 2 << 47 | 2 << 52, 1 << 2 | 50 << 4 | 1 << 47,
                    2, 0, 1, 1, 1, 1, 0, 0, 0, 3, 0, 0, 2, 0, 0, 1, ...$fill(9, [0 => 2, 2 => 1]), 1, 0, 0, 0, 1, 1, 50, 900, 1]],
            'book: retail ebook, comic issue and isbn pdf' => [BookCategorizer::class,
                ['Author - Title (2019) Retail.epub|2048', 'Marvel.Comics.Issue.12.cbz', 'Book.9781234567890.pdf|abc'],
                [5 | 1 << 3 | (1 | 32 | 128) << 6 | 7020 << 14 | 900 << 28 | 1 << 38 | 1 << 40 | 5 << 43 | 33 << 49,
                    1 | 3 << 3 | (4 | 8) << 6 | 7030 << 14 | 900 << 28 | 5 << 43 | 26 << 49,
                    5 | 2 << 3 | 64 << 6 | 7020 << 14 | 750 << 28 | 2 << 38 | 3 << 43 | 22 << 49,
                    0, 1, 0, 0, 0, 2, 0, 1, 1, 1, 0, 0, 1, 0, 1, 1, 0, 1, 1, 1, 2, 1, 0, 0, 0, 2048, 5, 3]],
            'console: xbox 360 pal, ps4 region free and none' => [ConsoleCategorizer::class,
                ['Game.PAL.XBOX.360-GRP|alt.binaries.games.xbox360', 'X.PS4.CUSA12345.Region.Free.Update', 'Nothing here|misc'],
                [7 | 1 << 4 | 1 << 16 | 1 << 17 | 1050 << 18 | 900 << 29 | 5 << 39 | 21 << 45,
                    2 | 8 << 4 | 2 << 8 | 1180 << 18 | 900 << 29 | 6 << 39 | 34 << 45, 2 << 39 | 12 << 45,
                    ...$fill(15, [0 => 1, 2 => 1, 7 => 1]), ...$fill(10, [0 => 1, 1 => 1, 8 => 1]), ...$fill(8, [1 => 1]), 1, 1, 2]],
            'group: anime, bare prefix and abbreviation' => [GroupNameCategorizer::class,
                [' Alt.Binaries.Multimedia.Anime ', 'alt.binaries.', 'a.b.teevee'],
                [1 | 4 << 3 | 1 << 7 | 5070 << 11 | 800 << 24 | 29 << 35, 5 | 13 << 35, 3 | 3 << 3 | 1 << 34 | 10 << 35,
                    0, 1, 0, 1, 0, 1, 2, 1, 0, 0, 0, 0, 0, 0, 0, 7, 4, 3, 1]],
            'release context: season web-dl with shouldCategorize off' => [ReleaseContext::class,
                ['Show.S01.1080p.WEB-DL-GRP|alt.binaries.tv|poster@x.y|1048576|5040|no'],
                [1 | 1 << 2 | 1 << 4 | 1 << 6 | 2 << 8 | 1 << 11 | 5 << 13 | 2 << 17 | (1 | 4) << 20 | 3 << 28 | 25 << 31 | 15 << 41,
                    0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 1, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0,
                    1048576, 1, 1]],
            'abstract empty batch' => [AbstractCategorizer::class, [], array_fill(0, 34, 0)],
            'release context empty batch' => [ReleaseContext::class, [], array_fill(0, 44, 0)],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['Some.Movie.2019-GRP|alt.binaries.x|40|0.9|2040', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['Game.PAL.PS4-GRP|alt.binaries.games|40|0.9|1180', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Edge values in every field position.
        $edges = self::edgeCases();
        for ($slot = 0; $slot < 6; $slot++) {
            $rows = [];
            foreach ($edges as $edge) {
                $fields = ['Some.Release.2019-GRP', 'alt.binaries.x', '40', '0.9', '2040', 'yes'];
                $fields[$slot] = $edge;
                $rows[] = implode('|', $fields);
            }
            $this->assertParity($class, $rows, 'edge field '.$slot);
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'Game.PS4|x', 7 => 'alt.binaries.tv', 'a' => '', 'c' => 'Book.epub|10'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([601, 602, 603] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(6033));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAbBsSxXuU0123456789|||..,-_ @[(+eEpPdDfFoOnNtTlL\0\t\n\x0b\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(6034)), true), 0, 80));
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
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W603_BENCH_ROUNDS
        // interleaved paired rounds per helper and workload (default 301, minimum 60), 5 iterations per round).
        // Common = one batch of 320 well-formed items of the class's format; uncommon = 320 items mixing edge
        // cases, malformed, long, NUL, case-changed and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1 release
        // dylib, Apple Silicon, shared host at load average ~10.5-14.4), common mean / p99, uncommon mean / p99:
        // - AbstractCategorizer:  5.48x / 4.96x, 4.37x / 3.88x
        // - BookCategorizer:      7.29x / 6.20x, 6.30x / 5.06x
        // - ConsoleCategorizer:   7.36x / 6.78x, 7.73x / 5.21x
        // - GroupNameCategorizer: 5.60x / 4.51x, 3.02x / 2.64x
        // - ReleaseContext:       5.52x / 4.10x, 4.81x / 4.09x
        // Every helper clears every gate in all three runs and defaults to Rust. GroupNameCategorizer and ReleaseContext
        // first measured 1.75x and 1.51x uncommon p99 with per-needle scans; one-pass first-byte dispatch replaced them.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W603_BENCH=1 php artisan test --compact tests/Unit/RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W603_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W603_BENCH=1 to run the Wave 60.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(6036));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W603_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(6037)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(6038)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW60RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
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
        fwrite(STDERR, "\nRustCategorizersAbstractBookConsoleGroupNameReleaseContextW60 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW60RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $missing
     */
    private function collectToggleSignatureErrors(string $class, string $usesName, string $withName, array &$missing): void
    {
        $uses = new ReflectionMethod($class, $usesName);
        $with = new ReflectionMethod($class, $withName);
        $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
        if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
            || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
            $missing[] = $class.' '.$usesName.'/'.$withName.' signatures';
        }
    }

    /**
     * Concrete AbstractCategorizer subclasses under app/Services/Categorization/Categorizers.
     *
     * @return list<class-string<AbstractCategorizer>>
     */
    private function categorizerSubclasses(): array
    {
        $subclasses = [];
        foreach (glob(\dirname(__DIR__, 2).'/app/Services/Categorization/Categorizers/*.php') ?: [] as $file) {
            $class = 'App\\Services\\Categorization\\Categorizers\\'.basename($file, '.php');
            if ($class !== AbstractCategorizer::class && is_subclass_of($class, AbstractCategorizer::class)) {
                $subclasses[] = $class;
            }
        }
        sort($subclasses);

        return $subclasses;
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
        $this->assertSame($php, $class::withW60RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW60RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
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
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                AbstractCategorizer::class => $this->gateItem($random, $uncommon),
                BookCategorizer::class => $this->bookItem($random, $uncommon),
                ConsoleCategorizer::class => $this->consoleItem($random, $uncommon),
                GroupNameCategorizer::class => $this->groupItem($random, $uncommon),
                default => $this->contextItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.'|x', $row.'||',
                    str_repeat($row, 4), str_replace('.', ' ', $row), str_replace('|', ' | ', $row)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function releaseName(Randomizer $random): string
    {
        return $this->pick($random, [
            'The.Expanse.S0'.$random->getInt(1, 6).'E'.str_pad((string) $random->getInt(1, 13), 2, '0', STR_PAD_LEFT).'.1080p.WEB-DL.DDP5.1.H.264-NTb',
            'Dune.Part.Two.'.$random->getInt(1990, 2026).'.2160p.UHD.BluRay.x265-TERMiNAL', 'Severance.S02.Complete.720p.AMZN.WEBRip-GRP',
            'Pink.Floyd-The.Dark.Side.Of.The.Moon-'.$random->getInt(1970, 2024).'-FLAC', 'Some.Movie.'.$random->getInt(1950, 2026).'.German.DL.1080p.BluRay-GRP',
            'Brandon Sanderson - Wind and Truth ('.$random->getInt(2000, 2026).') Retail.epub', 'Show.Name.S1.PROPER.480p.HDTV.x264-LOL',
            '[SubGroup] Anime Title - '.$random->getInt(1, 24).' (1080p)', 'Microsoft.Office.2024.x64-AiO', 'Linux_Mint_22_iso',
        ]);
    }

    private function gateItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->releaseName($random);
        $group = $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.sounds.flac', 'alt.binaries.e-book', 'alt.binaries.games', '']);
        $priority = $this->pick($random, ['', (string) $random->getInt(0, 100), '10', '35', '45', '50', '75']);
        $confidence = $this->pick($random, ['', '0.'.$random->getInt(0, 99), '0.85', '0.9', '1', '1.0', '0.6', '0.5']);
        $category = $this->pick($random, ['', (string) ($random->getInt(1, 8) * 1000 + $random->getInt(0, 99) * 10), '5040', '2040', '7020', '0']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'ab', str_repeat($name.' ', 6), "caf\xc3\xa9 ".$name, $name.'-ABCDEFGHIJKLMNOP']);
            $priority = $this->pick($random, [$priority, '101', '-5', 'high', '0050']);
            $confidence = $this->pick($random, [$confidence, '1.5', '.9', '0.', '0.1234567', 'abc', '85%']);
            $category = $this->pick($random, [$category, '9000', '999', 'x', '1234567890', '0999']);
        }

        return implode('|', [$name, $group, $priority, $confidence, $category]);
    }

    private function bookItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            'Brandon Sanderson - Wind and Truth ('.$random->getInt(2000, 2026).') Retail.epub', 'Marvel.Comics.Spider-Man.Issue.'.$random->getInt(1, 900).'.cbz',
            'OReilly.Learning.Python.6th.Edition.'.$random->getInt(2010, 2026).'.pdf', 'Wired.Magazine.Monthly.'.$random->getInt(2000, 2026).'.pdf',
            'Stephen.King-The.Stand.Unabridged.Audiobook.m4b', 'One Piece v'.$random->getInt(1, 110).' (Digital) Manga.cbr', 'Kindle.Unlimited.Pack.mobi',
            'Book.ISBN.978'.$random->getInt(1_000_000_000, 9_999_999_999).'.pdf', 'Random.Novel.azw3', 'Some.Report.pdf', 'Packt.Cookbook.Django.epub',
        ]);
        $size = $this->pick($random, ['', (string) $random->getInt(10_000, 900_000), (string) $random->getInt(1_048_576, 50_000_000), (string) $random->getInt(60_000_000, 2_000_000_000)]);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'x.', 'x.toolong', str_repeat('epub ', 30), 'tok'.str_repeat('n', 30).'.pdf', "Livre.Fran\xc3\xa7ais.epub"]);
            $size = $this->pick($random, [$size, 'abc', '-1', str_repeat('9', 13), '1e9']);
        }

        return $name.'|'.$size;
    }

    private function consoleItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->pick($random, [
            'Elden.Ring.PS4.CUSA'.$random->getInt(10000, 99999).'-DUPLEX', 'Astro.Bot.PS5.PPSA'.$random->getInt(1000, 99999).'.EUR-BigBlueBox',
            'Halo.Infinite.XBOX.ONE-iMARS', 'Gears.of.War.XBOX.360.PAL-COMPLEX', 'Mario.Kart.World.Switch.NSP.Update-VENOM', 'Zelda.Wii.U.USA-LoCAL',
            'Super.Mario.Galaxy.WII.PAL.WBFS', 'Pokemon.X.3DS.CIA.Region.Free', 'Castlevania.NDS.JPN', 'Sonic.SEGA.Genesis.ROM.USA', 'Gran.Turismo.7.PS5.MULTi5',
            'Forza.Horizon.5.Xbox.Series.X-iND', 'Persona.5.PS3.ASIA.PKG.DLC',
        ]);
        $group = $this->pick($random, ['alt.binaries.games.xbox360', 'alt.binaries.console.ps3', 'alt.binaries.games.nintendo', 'alt.binaries.sony.psp', 'alt.binaries.misc', '']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', 'XBOX.Series', 'Wii.U', 'ppsa01', 'CUSA1234567', 'multi123', 'region.free.region', str_repeat('PS4 ', 30)]);
            $group = $this->pick($random, [$group, 'ALT.BINARIES.GAMES', 'a.b.games', 'xbox']);
        }

        return $name.'|'.$group;
    }

    private function groupItem(Randomizer $random, bool $noisy): string
    {
        $group = $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.multimedia.anime.highspeed', 'alt.binaries.moovee', 'alt.binaries.hdtv.x264',
            'alt.binaries.erotica', 'alt.binaries.sounds.mp3.complete_cd', 'alt.binaries.games.xbox360', 'alt.binaries.warez.0-day', 'alt.binaries.e-book.technical',
            'alt.binaries.comics.dcp', 'alt.binaries.boneless', 'alt.binaries.misc', 'alt.binaries.mom', 'alt.binaries.pictures', 'alt.binaries.cd.image',
            'alt.binaries.dvd', 'alt.binaries.apps.'.$random->getInt(1, 9), 'alt.binaries.music.flac', 'comp.os.linux', 'alt.test']);
        if ($noisy) {
            $group = $this->pick($random, [$group, '', 'a.b.teevee', '.alt.binaries.x', 'alt..binaries', 'alt.binaries.', 'ALT.BINARIES.TV', 'misc', 'alt binaries tv',
                str_repeat('alt.binaries.', 10).'anime', 'alt.binaries.c++', "alt.binaries.caf\xc3\xa9"]);
        }

        return $group;
    }

    private function contextItem(Randomizer $random, bool $noisy): string
    {
        $name = $this->releaseName($random);
        $group = $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.erotica', 'alt.binaries.e-book', '']);
        $poster = $this->pick($random, ['', 'poster'.$random->getInt(1, 50).'@example.com', 'Yenc@power-post.org', 'anonymous']);
        $size = $this->pick($random, ['', (string) $random->getInt(1_000, 900_000), (string) $random->getInt(1_048_576, 900_000_000), (string) $random->getInt(1_000_000_000, 90_000_000_000)]);
        $category = $this->pick($random, ['', '5040', '2040', '3040', '7020', '6010', '0']);
        $should = $this->pick($random, ['', '1', '0', 'true', 'false']);
        if ($noisy) {
            $name = $this->pick($random, [$name, '', "Bad\x01Name", str_repeat('x', 300), 'OF.Some.Clip.XXX.1080p', 'Only Fans Pack 2023', "caf\xc3\xa9 ".$name]);
            $group = $this->pick($random, [$group, 'bad group', 'ALT.BINARIES.X', 'alt.binaries.c++']);
            $poster = $this->pick($random, [$poster, '@x', 'x@', 'a@@b', 'Name <a@b.c>']);
            $size = $this->pick($random, [$size, 'abc', str_repeat('9', 13), '-1']);
            $category = $this->pick($random, [$category, '9000', 'x', '1234567890']);
            $should = $this->pick($random, [$should, 'YES', 'Off', 'maybe', '01']);
        }

        return implode('|', [$name, $group, $poster, $size, $category, $should]);
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
     * Calls a Wave 60.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W60_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w603', ReleaseContext::W60_RELEASE_CONTEXT_FFI_CDEF) === null) {
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
