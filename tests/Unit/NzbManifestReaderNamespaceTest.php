<?php

declare(strict_types=1);

use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use PHPUnit\Framework\TestCase;

final class NzbManifestReaderNamespaceTest extends TestCase
{
    private const URI = 'http://www.newzbin.com/DTD/2003/nzb';

    private function release(): object
    {
        return (object) ['searchname' => 'synthetic', 'fromname' => 'synthetic', 'postdate' => '2026-10-01 00:00:00', 'groups_id' => 1];
    }

    private function xml(int $mask = 0, bool $legacy = false): string
    {
        $tags = ['nzb', 'head', 'meta', 'file', 'groups', 'group', 'segments', 'segment'];
        $xml = '<nzb><head><meta type="nntmux:declared-file-count">2</meta><meta type="nntmux:verification-mode">spot-check</meta></head><file subject="synthetic-a (1/2)"><groups><group>alt.synthetic.a</group><group>alt.synthetic.b</group></groups><segments><segment number="2" bytes="13"> second@synthetic </segment><segment number="1" bytes="11">first@synthetic</segment></segments></file><file subject="synthetic-b (1/1)"><groups><group>alt.synthetic.b</group></groups><segments><segment number="1" bytes="17">third@synthetic</segment></segments></file></nzb>';
        if ($legacy) {
            return $xml;
        }
        foreach ($tags as $index => $tag) {
            if (($mask & (1 << $index)) !== 0) {
                $xml = preg_replace('~(<\\/?)(?='.$tag.'(?:[ >]))~', '$1n:', $xml);
            }
        }

        return preg_replace('~^(<[^ >]+)~', '$1 xmlns="'.self::URI.'" xmlns:n="'.self::URI.'"', $xml);
    }

    /** @return array<string, mixed> */
    private function read(string $xml): array
    {
        return (new NzbManifestReader)->read($xml, $this->release());
    }

    /** @param array<string, mixed> $manifest */
    private function identity(array $manifest): ?string
    {
        $identity = new NzbArticleFingerprint;
        foreach ($manifest['files'] as $file) {
            foreach ($file['segments'] as $segment) {
                $identity->add($segment['messageid']);
            }
        }

        return $identity->value();
    }

    public function test_nested_declarations_rebinding_and_first_containers(): void
    {
        $expected = $this->read($this->xml(0, true));
        $xml = str_replace('<nzb>', '<nzb xmlns="'.self::URI.'">', $this->xml(0, true));
        $xml = str_replace('<segment ', '<n:segment xmlns:n="'.self::URI.'" ', $xml);
        $xml = str_replace('</segment>', '</n:segment>', $xml);
        $xml = str_replace('</segments>', '<n:segment xmlns:n="urn:foreign" number="1">decoy@invalid</n:segment></segments><segments><segment number="99">decoy@invalid</segment></segments>', $xml);
        $xml = str_replace('</groups>', '</groups><groups><group>alt.decoy</group></groups>', $xml);
        $this->assertSame($expected, $this->read($xml));
        $this->assertSame($this->identity($expected), $this->identity($this->read($xml)));
    }

    public function test_dtd_utf16_and_hint_false_positives_preserve_full_manifest(): void
    {
        $legacy = $this->xml(0, true);
        $expected = $this->read($legacy);
        $cases = [
            '<!DOCTYPE nzb [<!ATTLIST nzb xmlns CDATA "'.self::URI.'">]>'.$legacy,
            '<!DOCTYPE nzb [<!ENTITY inert "unchanged">]>'.$legacy,
            str_replace('<nzb>', '<nzb><!-- xmlns:hint -->', $legacy),
            str_replace('<head>', '<head><meta type="unused">xmlns:hint</meta>', $legacy),
            mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>'.$legacy, 'UTF-16', 'UTF-8'),
            mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>'.$this->xml(255), 'UTF-16', 'UTF-8'),
        ];
        foreach ($cases as $xml) {
            $actual = $this->read($xml);
            $this->assertSame($expected, $actual);
            $this->assertSame($this->identity($expected), $this->identity($actual));
        }
    }

    public function test_import_fields_conflicts_inferred_parts_and_missing_containers(): void
    {
        $release = $this->release();
        $release->metadata_source = 'import';
        $release->categories_id = 123;
        $release->import_name_override = true;
        $release->original_subject = 'original';
        $legacy = '<nzb><file subject="inferred"><segments><segment number="2" bytes="3">two@invalid</segment><segment number="2" bytes="4">duplicate@invalid</segment></segments></file><file subject="empty"/></nzb>';
        $prefixed = str_replace(['<nzb>', '</nzb>', '<file ', '</file>', '<segments>', '</segments>', '<segment ', '</segment>'], ['<n:nzb xmlns:n="'.self::URI.'">', '</n:nzb>', '<n:file ', '</n:file>', '<n:segments>', '</n:segments>', '<n:segment ', '</n:segment>'], $legacy);
        $reader = new NzbManifestReader;
        $expected = $reader->read(str_replace(['<file subject="inferred">', '<file subject="empty"/>'], ['<file subject="inferred"><groups/>', '<file subject="empty"><groups/><segments/></file>'], $legacy), $release);
        $this->assertSame($expected, $reader->read($prefixed, $release));
        $this->assertSame(123, $expected['categories_id']);
        $this->assertSame('original', $expected['original_subject']);
        $this->assertTrue($expected['import_name_override']);
        $this->assertTrue($expected['conflict']);
        $this->assertSame(2, $expected['files'][0]['totalparts']);
        $this->assertTrue($expected['files'][0]['totalparts_inferred']);
        $this->assertSame([], $expected['groups']);
        $this->assertSame([], $expected['files'][1]['segments']);
    }

    public function test_ordinary_default_namespace_preserves_full_legacy_manifest_and_identity(): void
    {
        $expected = $this->read($this->xml(0, true));
        $actual = $this->read(str_replace(' xmlns:n="'.self::URI.'"', '', $this->xml()));
        $this->assertSame($expected, $actual);
        $this->assertSame($this->identity($expected), $this->identity($actual));
    }

    public function test_all_256_prefix_placements_preserve_complete_manifest_and_order(): void
    {
        $expected = $this->read($this->xml(0, true));
        $this->assertSame(['alt.synthetic.a', 'alt.synthetic.b'], $expected['groups']);
        $this->assertSame(['second@synthetic', 'first@synthetic'], array_column($expected['files'][0]['segments'], 'messageid'));
        $this->assertSame([2, 1], array_column($expected['files'][0]['segments'], 'number'));
        $this->assertSame([13, 11], array_column($expected['files'][0]['segments'], 'bytes'));
        $this->assertSame(2, $expected['expected_files']);
        $this->assertSame('spot_check', $expected['verification_mode']);
        for ($mask = 0; $mask < 256; $mask++) {
            $actual = $this->read($this->xml($mask));
            $this->assertSame($expected, $actual, 'Complete manifest equality for prefix placement '.$mask);
            $this->assertSame($this->identity($expected), $this->identity($actual));
        }
    }

    public function test_fully_prefixed_document_preserves_complete_legacy_manifest(): void
    {
        $this->assertTrue($this->read($this->xml(0, true)) === $this->read($this->xml(255)), 'Complete prefixed manifest equals legacy manifest.');
    }

    public function test_prefixed_meta_under_default_namespace_root_keeps_explicit_metadata(): void
    {
        $this->assertTrue($this->read($this->xml(0, true)) === $this->read($this->xml(4)), 'Prefixed metadata values are preserved.');
    }

    public function test_foreign_extensions_and_nested_lookalikes_never_become_nzb_nodes(): void
    {
        foreach ([true, false] as $legacy) {
            $xml = $this->xml(0, $legacy);
            $xml = str_replace('<nzb', '<nzb xmlns:e="urn:synthetic-extension"', $xml);
            $xml = str_replace('<head>', '<e:head><e:meta type="password">inert-decoy</e:meta></e:head><head><e:meta type="password">inert-decoy</e:meta><e:wrapper><meta type="password">inert-decoy</meta></e:wrapper>', $xml);
            $xml = str_replace('<groups>', '<e:groups><group>alt.synthetic.decoy</group></e:groups><groups><e:group>alt.synthetic.decoy</e:group>', $xml);
            $xml = str_replace('<segments>', '<e:segments><segment number="1" bytes="99">decoy@synthetic</segment></e:segments><segments><e:segment number="1" bytes="99">decoy@synthetic</e:segment>', $xml);
            $xml = str_replace('</nzb>', '<e:file subject="decoy"><groups><group>alt.synthetic.decoy</group></groups></e:file><e:wrapper><file subject="decoy"/></e:wrapper></nzb>', $xml);
            $this->assertTrue($this->read($this->xml(0, $legacy)) === $this->read($xml), 'Extension nodes remain excluded.');
        }
    }

    public function test_empty_namespace_children_under_canonical_root_remain_excluded(): void
    {
        $xml = str_replace('<head>', '<head xmlns=""><meta type="password">inert-decoy</meta></head><head>', $this->xml());
        $xml = str_replace('</nzb>', '<file xmlns="" subject="decoy"/></nzb>', $xml);
        $this->assertTrue($this->read($this->xml()) === $this->read($xml), 'Namespace reset nodes remain excluded.');
    }

    public function test_canonical_children_under_legacy_root_remain_excluded(): void
    {
        $xml = str_replace('<head>', '<n:head xmlns:n="'.self::URI.'"><n:meta type="password">inert-decoy</n:meta></n:head><head>', $this->xml(0, true));
        $xml = str_replace('</nzb>', '<n:file xmlns:n="'.self::URI.'" subject="decoy"/></nzb>', $xml);
        $this->assertTrue($this->read($this->xml(0, true)) === $this->read($xml), 'Namespace mismatches remain excluded.');
    }

    public function test_foreign_root_with_canonical_children_is_rejected(): void
    {
        $xml = str_replace(['<n:nzb ', '</n:nzb>'], ['<e:nzb xmlns:e="urn:synthetic-extension" ', '</e:nzb>'], $this->xml(255));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid NZB.');
        $this->read($xml);
    }

    public function test_writer_roundtrip_keeps_metadata_and_article_order(): void
    {
        $before = $this->read($this->xml(255));
        $writer = (new ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
        $after = $this->read($writer->document($before));
        $this->assertSame($this->identity($before), $this->identity($after));
        // Writer emits all manifest groups on each file and rebuilds file keys.
        foreach (['groups', 'expected_files', 'verification_mode'] as $field) {
            $this->assertTrue($before[$field] === $after[$field], 'Roundtrip '.$field.' is preserved.');
        }
        foreach ($before['files'] as $index => $file) {
            foreach (['subject', 'totalparts', 'totalparts_inferred', 'segments'] as $field) {
                $this->assertTrue($file[$field] === $after['files'][$index][$field], 'Roundtrip file '.$index.' '.$field.' is preserved.');
            }
        }
    }

    public function test_default_namespace_decoys_under_legacy_root_are_excluded_quietly(): void
    {
        $base = $this->xml(0, true);
        $expected = $this->read($base);
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            foreach ([self::URI, 'urn:foreign'] as $namespace) {
                foreach ([
                    ['<head>', '<head xmlns="'.$namespace.'"><meta type="nntmux:declared-file-count">99</meta></head><head>'],
                    ['<meta ', '<meta xmlns="'.$namespace.'" type="nntmux:declared-file-count">99</meta><meta '],
                    ['<file ', '<file xmlns="'.$namespace.'" subject="decoy"/><file '],
                    ['<groups>', '<groups xmlns="'.$namespace.'"><group>alt.decoy</group></groups><groups>'],
                    ['<group>', '<group xmlns="'.$namespace.'">alt.decoy</group><group>'],
                    ['<segments>', '<segments xmlns="'.$namespace.'"><segment number="99">decoy@invalid</segment></segments><segments>'],
                    ['<segment ', '<segment xmlns="'.$namespace.'" number="99">decoy@invalid</segment><segment '],
                ] as [$needle, $replacement]) {
                    $actual = $this->read(str_replace($needle, $replacement, $base));
                    $this->assertSame($expected, $actual);
                    $this->assertSame($this->identity($expected), $this->identity($actual));
                }
            }
            foreach (['<nzb><!-- xmlns:hint --><file subject="empty"/></nzb>', '<n:nzb xmlns:n="'.self::URI.'"><n:file subject="empty"/></n:nzb>'] as $xml) {
                $actual = $this->read($xml);
                $this->assertSame([], $actual['groups']);
                $this->assertSame([], $actual['files'][0]['segments']);
            }
        } finally {
            restore_error_handler();
        }
    }
}
