<?php

declare(strict_types=1);

use App\Services\Releases\Verification\NzbManifestReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NzbPasswordLegacyHeadParityTest extends TestCase
{
    public static function forms(): array
    {
        return [['legacy'], ['default'], ['prefixed'], ['doctype']];
    }

    private function read(string $head, string $form): array
    {
        $xml = '<nzb>'.$head.'<file subject="synthetic (1/1)"><groups><group>alt.synthetic</group></groups><segments><segment number="1" bytes="1">synthetic@invalid</segment></segments></file></nzb>';
        $uri = 'http://www.newzbin.com/DTD/2003/nzb';
        if ($form === 'default') {
            $xml = str_replace('<nzb>', '<nzb xmlns="'.$uri.'">', $xml);
        }
        if ($form === 'prefixed') {
            $xml = preg_replace('~(<\\/?)(nzb|head|meta|file|groups|group|segments|segment)(?=[ />])~', '$1n:$2', $xml);
            $xml = str_replace('<n:nzb>', '<n:nzb xmlns:n="'.$uri.'">', $xml);
        }
        if ($form === 'doctype') {
            $xml = '<!DOCTYPE nzb [<!ENTITY inert "synthetic">]>'.$xml;
        }

        return (new NzbManifestReader)->read($xml, (object) ['searchname' => 'synthetic', 'fromname' => 'synthetic', 'postdate' => '2026-10-01', 'groups_id' => 1, 'metadata_source' => 'import']);
    }

    #[DataProvider('forms')]
    public function test_no_assertion_multiple_heads_preserve_first_head_metadata(string $form): void
    {
        $first = '<head><meta type="nntmux:declared-file-count">2</meta></head>';
        $expected = $this->read($first, $form);
        foreach (['<head/>', '<head><meta type="unused">synthetic</meta></head>', '<head><meta type="nntmux:declared-file-count">9</meta></head>', '<head><f:meta xmlns:f="urn:foreign" type="password">synthetic</f:meta></head>'] as $extra) {
            $this->assertSame($expected, $this->read($first.$extra, $form));
        }
        $this->assertSame($this->read('', $form), $this->read('<head/><head/>', $form));
        $this->assertArrayNotHasKey('passwords', $expected);
    }

    #[DataProvider('forms')]
    public function test_password_in_either_authorized_head_is_rejected(string $form): void
    {
        foreach (['<head><meta type="password">synthetic</meta></head><head/>', '<head/><head><meta type="password">synthetic</meta></head>'] as $heads) {
            try {
                $this->read($heads, $form);
                $this->fail('Ambiguous authorized password metadata was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid NZB password metadata structure.', $exception->getMessage());
            }
        }
    }
}
