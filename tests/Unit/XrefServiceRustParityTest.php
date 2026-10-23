<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\XrefService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;

final class XrefServiceRustParityTest extends TestCase
{
    public function test_rust_pipeline_library_is_available(): void
    {
        $this->assertTrue(RustPipelineLibrary::available(), 'Rust pipeline FFI library must be available.');
    }

    public function test_xref_service_methods_match_php_reference_across_corpus(): void
    {
        $native = new XrefService(useNative: true);
        $php = new XrefService(useNative: false);

        $corpus = [
            null,
            '',
            '0',
            '   ',
            'news.example.com',
            'news.example.com alt.binaries.teevee:1001',
            'news.example.com alt.binaries.teevee:1001 alt.binaries.hdtv:2002 alt.binaries.teevee:1002',
            'de.binaer.misc:42 es.binarios.cine:99 uk.binaries.movies:777',
            'alt.binaries.multimedia alt.binaries.x264:55555',
            'alt.binaries.:123 alt.binaries.test:123foo alt.binaries.valid:789',
            "alt.binaries.line1:111\nignored alt.binaries.line2:222",
            "alt.binaries.tv\tshow:123 alt.binaries.other:456",
            'ab.binaries.a:1 abc.binaries.b:2 abcd.binaries.c:3 a.binaries.d:4',
        ];

        foreach ($corpus as $xref) {
            $this->assertSame(
                $php->extractTokens($xref),
                $native->extractTokens($xref),
                'extractTokens mismatch for: '.var_export($xref, true)
            );
            $this->assertSame(
                $php->extractGroupNames($xref),
                $native->extractGroupNames($xref),
                'extractGroupNames mismatch for: '.var_export($xref, true)
            );
        }

        foreach ($corpus as $existing) {
            foreach ($corpus as $incoming) {
                $this->assertSame(
                    $php->diffNewTokens($existing, $incoming),
                    $native->diffNewTokens($existing, $incoming),
                    'diffNewTokens mismatch'
                );
            }
        }

        $stringCorpus = array_values(array_map(static fn (?string $s): string => $s ?? '', $corpus));
        $this->assertSame(
            $php->extractGroupNamesBatch($stringCorpus),
            $native->extractGroupNamesBatch($stringCorpus)
        );
        $this->assertSame(
            $php->extractTokensAndGroupNamesBatch($stringCorpus),
            $native->extractTokensAndGroupNamesBatch($stringCorpus)
        );
    }
}
