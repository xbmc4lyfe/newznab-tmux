<?php

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\PreparedManifest;
use PHPUnit\Framework\TestCase;

final class NzbPasswordEntityReferenceTest extends TestCase
{
    public function test_entity_reference_cannot_survive_reader_roundtrip(): void
    {
        foreach (['', ' xmlns="http://www.newzbin.com/DTD/2003/nzb"', ' xmlns:n="http://www.newzbin.com/DTD/2003/nzb"'] as $namespace) {
            $xml = '<nzb'.$namespace.'><head><meta type="password">&privateFixture;</meta></head><file subject="fixture"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="5">fixture@example.test</segment></segments></file></nzb>';
            if (str_contains($namespace, 'xmlns:n')) {
                $xml = preg_replace('~(<\/?)(nzb|head|meta|file|groups|group|segments|segment)(?=[ >])~', '$1n:$2', $xml);
            }
            $xml = '<!DOCTYPE nzb [<!ENTITY privateFixture "<child/>">]>'.$xml;
            $previous = libxml_use_internal_errors(true);
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            try {
                new PreparedManifest((new NzbManifestReader)->read($document->asXML(), (object) ['searchname' => 'fixture', 'fromname' => 'fixture', 'postdate' => '2026-10-01 00:00:00', 'groups_id' => 1]));
                self::fail('Entity reference accepted.');
            } catch (NzbPasswordMetadataRejected) {
                self::assertTrue(true);
            }
        }
    }

    public function test_whitespace_between_cdata_sections_is_part_of_the_assertion(): void
    {
        $xml = '<nzb><head><meta type="password"><![CDATA[first]]> '."\t\n".' <![CDATA[second]]></meta></head><file subject="fixture"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="5">fixture@example.test</segment></segments></file></nzb>';
        $manifest = (new NzbManifestReader)->read($xml, (object) ['searchname' => 'fixture', 'fromname' => 'fixture', 'postdate' => '2026-10-01 00:00:00', 'groups_id' => 1]);
        self::assertSame(["first \t\n second"], $manifest['passwords']);
        self::assertSame($manifest, json_decode(gzdecode((new PreparedManifest($manifest))->compressed), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_text_cdata_escapes_comments_and_empty_repeats_remain_supported(): void
    {
        $xml = '<nzb><head><meta type="password">a&amp;<![CDATA[b]]><!-- fixture -->c</meta><meta type="password"/><meta type="password"/></head><file subject="fixture"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="5">fixture@example.test</segment></segments></file></nzb>';
        $manifest = (new NzbManifestReader)->read($xml, (object) ['searchname' => 'fixture', 'fromname' => 'fixture', 'postdate' => '2026-10-01 00:00:00', 'groups_id' => 1]);
        self::assertSame(['a&bc', '', ''], $manifest['passwords']);
    }
}
