<?php

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Services\Releases\Verification\NzbManifestReader;
use PHPUnit\Framework\TestCase;

final class NzbHiddenEntityReaderTest extends TestCase
{
    private function read(string $body, string $declaration = ''): array
    {
        $file = '<file subject="fixture (1/1)"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="1">fixture@invalid</segment></segments></file>';
        $xml = $declaration.'<nzb xmlns:f="urn:foreign">'.$body.$file.'</nzb>';

        return (new NzbManifestReader)->read($xml, (object) ['searchname' => 'fixture', 'fromname' => 'fixture', 'postdate' => '2026-10-01', 'groups_id' => 1, 'metadata_source' => 'import']);
    }

    public function test_direct_hidden_structure_rejects(): void
    {
        $declaration = '<!DOCTYPE nzb [<!ENTITY hidden \'<meta type="password">synthetic</meta>\'>]>';
        foreach (['<head>&hidden;</head>', '<head/><head>&hidden;</head>', '&hidden;'] as $body) {
            try {
                $this->read($body, $declaration);
                self::fail('Hidden accepted structure was accepted.');
            } catch (NzbPasswordMetadataRejected) {
                self::assertTrue(true);
            }
        }
    }

    public function test_foreign_and_nested_containers_stay_excluded(): void
    {
        $declaration = '<!DOCTYPE nzb [<!ENTITY hidden \'<meta type="password">synthetic</meta>\'>]>';
        foreach (['<f:head>&hidden;</f:head>', '<f:container>&hidden;</f:container>', '<f:container><head>&hidden;</head></f:container>', '<head><f:container>&hidden;</f:container></head>'] as $body) {
            self::assertArrayNotHasKey('passwords', $this->read($body, $declaration));
        }
    }

    public function test_title_entity_and_public_external_doctype_remain_accepted(): void
    {
        self::assertArrayNotHasKey('passwords', $this->read('<head><meta type="title">&title;</meta></head>', '<!DOCTYPE nzb [<!ENTITY title "fixture">]>'));
        self::assertArrayNotHasKey('passwords', $this->read('<head/>', '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'));
    }
}
