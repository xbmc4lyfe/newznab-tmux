<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbParserService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NzbParserRustParityTest extends TestCase
{
    #[Test]
    public function parse_nzb_file_list_extension_detection_matches_php_across_edge_cases(): void
    {
        $this->assertNotNull(RustPipelineLibrary::resolve());
        $parser = new NzbParserService;

        $subjects = [
            '"Show.S01E01.1080p.WEB-DL.mkv" yEnc (1/25)',
            '"Archive.part01.rar" yEnc (1/50)',
            '"Archive.r00" yEnc (1/50)',
            '"Archive.R125" yEnc (1/50)',
            '"SplitFile.001" yEnc (1/10)',
            '"SplitFile.99" yEnc (1/10)',
            '"Repair.vol00+01.PAR2" yEnc (1/5)',
            '"Info.nfo" yEnc (1/1)',
            '"Fake.rar"-not-a-match "Real.zip" yEnc (1/2)',
            '"Fake.mp4"|still-not-a-match "Real.7z" yEnc (1/2)',
            '"Fake.avi")not-a-match "Real.tar" yEnc (1/2)',
            '"NoExtensionHere" yEnc (1/1)',
            '',
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
        foreach ($subjects as $idx => $subject) {
            $escaped = htmlspecialchars($subject, ENT_QUOTES | ENT_XML1);
            $xml .= "<file poster=\"p\" date=\"1\" subject=\"{$escaped}\"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes=\"100\" number=\"1\">seg{$idx}@x</segment></segments></file>";
        }
        $xml .= '</nzb>';

        $expected = $parser->parseNzbFileList($xml, [], useNative: false);
        $actual = $parser->parseNzbFileList($xml, [], useNative: true);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function parse_nzb_file_list_matches_php_simplexml_in_all_modes(): void
    {
        $nzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/2003/nzb/nzb-1.1.dtd">
<!-- Top level comment -->
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <head>
    <meta type="title">Test.Release.S01E01</meta>
    <meta type="password">secret</meta>
  </head>
  <file poster="poster@example.com (Poster)" date="1700000000" subject="[1/5] - &quot;Test.Release.S01E01.part01.rar&quot; yEnc (1/3)">
    <groups>
      <group>alt.binaries.teevee</group>
      <group>alt.binaries.misc</group>
    </groups>
    <segments>
      <segment bytes="750000" number="3">seg3@example.com</segment>
      <segment bytes="750000" number="1">seg1@example.com</segment>
      <segment bytes="500000" number="2">seg2@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com (Poster)" date="1700000005" subject="[1/5] - &quot;Test.Release.S01E01.part01.rar&quot; yEnc (2/3)">
    <groups>
      <group>alt.binaries.teevee</group>
    </groups>
    <segments>
      <segment bytes="750000" number="2">seg2b@example.com</segment>
      <segment bytes="750000" number="3">seg3b@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com (Poster)" date="1700000010" subject="&quot;Test.Release.S01E01.r00&quot; yEnc (1/12)">
    <groups>
      <group>alt.binaries.teevee</group>
    </groups>
    <segments>
      <segment bytes="123456" number="1">r00-1@example.com</segment>
    </segments>
  </file>
  <file poster="poster@example.com (Poster)" date="1700000015" subject="&quot;Test.Release.S01E01.nfo&quot; yEnc">
    <groups>
      <group>alt.binaries.teevee</group>
    </groups>
    <segments>
      <segment bytes="4096" number="1">nfo-1&amp;2@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $this->assertNotNull(RustPipelineLibrary::resolve());
        $parser = new NzbParserService;

        $expectedDefault = $parser->parseNzbFileList($nzb, [], useNative: false);
        $expectedMerged = $parser->parseNzbFileList($nzb, ['no-file-key' => false, 'strip-count' => true], useNative: false);
        $expectedKeyedUnstripped = $parser->parseNzbFileList($nzb, ['no-file-key' => false, 'strip-count' => false], useNative: false);

        $actualDefault = $parser->parseNzbFileList($nzb, [], useNative: true);
        $actualMerged = $parser->parseNzbFileList($nzb, ['no-file-key' => false, 'strip-count' => true], useNative: true);
        $actualKeyedUnstripped = $parser->parseNzbFileList($nzb, ['no-file-key' => false, 'strip-count' => false], useNative: true);

        $this->assertSame($expectedDefault, $actualDefault);
        $this->assertSame($expectedMerged, $actualMerged);
        $this->assertSame($expectedKeyedUnstripped, $actualKeyedUnstripped);
    }

    #[Test]
    public function parse_nzb_file_list_falls_back_cleanly_on_unusual_xml(): void
    {
        $parser = new NzbParserService;

        $cdataNzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <file poster="p" date="1" subject="&quot;test.mkv&quot; yEnc (1/1)">
    <groups><group><![CDATA[alt.binaries.misc]]></group></groups>
    <segments><segment bytes="100" number="1"><![CDATA[seg1@example.com]]></segment></segments>
  </file>
</nzb>
XML;

        $this->assertSame(
            $parser->parseNzbFileList($cdataNzb, [], useNative: false),
            $parser->parseNzbFileList($cdataNzb, [], useNative: true)
        );

        $edgeXmlInputs = [
            // Empty <group/> and <segment/>
            '<?xml version="1.0" encoding="UTF-8"?><nzb><file subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group/></groups><segments><segment bytes="10" number="1"/></segments></file></nzb>',
            // Mismatched tag case
            '<?xml version="1.0" encoding="UTF-8"?><nzb><file subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" number="1">s@x</segment></segments></FILE></nzb>',
            // Duplicate attributes
            '<?xml version="1.0" encoding="UTF-8"?><nzb><file subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" bytes="20" number="1">s@x</segment></segments></file></nzb>',
            // Child xmlns
            '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file xmlns="http://other" subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" number="1">s@x</segment></segments></file></nzb>',
            // Invalid -- inside comment
            '<?xml version="1.0" encoding="UTF-8"?><!-- bad -- comment --><nzb><file subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" number="1">s@x</segment></segments></file></nzb>',
            // Invalid numeric entity with + sign
            '<?xml version="1.0" encoding="UTF-8"?><nzb><file subject="&#+65;"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" number="1">s@x</segment></segments></file></nzb>',
            // Unescaped & in non-subject attribute
            '<?xml version="1.0" encoding="UTF-8"?><nzb><file poster="a & b" subject="&quot;a.rar&quot; yEnc (1/1)"><groups><group>alt.binaries.misc</group></groups><segments><segment bytes="10" number="1">s@x</segment></segments></file></nzb>',
        ];

        foreach ($edgeXmlInputs as $idx => $edgeXml) {
            $this->assertSame(
                $parser->parseNzbFileList($edgeXml, [], useNative: false),
                $parser->parseNzbFileList($edgeXml, [], useNative: true),
                "Parity mismatch on edge XML input #{$idx}"
            );
        }
    }
}
