<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Http\Controllers\Api\XML_Response;
use PHPUnit\Framework\TestCase;

final class XMLResponseTest extends TestCase
{
    public function test_api_xml_removes_invalid_xml_control_characters_from_release_fields(): void
    {
        $release = $this->release(['searchname' => "Clean\x1fTitle"]);
        $response = $this->response([$release]);

        $xml = $response->returnXML();

        self::assertIsString($xml);
        self::assertStringNotContainsString("\x1f", $xml);
        self::assertStringContainsString('CleanTitle', $xml);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertNotFalse($parsed);
    }

    public function test_api_xml_handles_single_stdclass_release_payload(): void
    {
        $response = $this->response($this->release([
            'searchname' => 'Single Release',
            '_totalrows' => null,
        ]));

        $xml = $response->returnXML();

        self::assertIsString($xml);
        self::assertStringContainsString('total="1"', $xml);
        self::assertStringContainsString('Single Release', $xml);
    }

    public function test_api_array_handles_single_stdclass_release_payload(): void
    {
        $array = $this->response($this->release([
            'searchname' => 'Single Release',
            '_totalrows' => null,
        ]))->returnArray();

        self::assertIsArray($array);
        self::assertSame(1, $array['total']);
        self::assertCount(1, $array['item']);
        self::assertSame('Single Release', $array['item'][0]['title']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function release(array $overrides = []): object
    {
        return (object) array_merge([
            'searchname' => 'Clean Title',
            'guid' => 'release-guid',
            'adddate' => '2026-05-29 12:00:00',
            'category_name' => 'Movies > HD',
            'categories_id' => 2040,
            'size' => 123456789,
            '_totalrows' => 1,
        ], $overrides);
    }

    private function response(mixed $data): XML_Response
    {
        return new XML_Response([
            'Parameters' => [
                'extended' => '0',
                'del' => '0',
                'token' => 'test-token',
                'requests' => 1,
                'apilimit' => 100,
                'grabs' => 0,
                'downloadlimit' => 100,
                'oldestapi' => '',
                'oldestgrab' => '',
            ],
            'Data' => $data,
            'Server' => [
                'server' => [
                    'title' => 'NNTmux Tests',
                    'strapline' => 'Testing',
                    'email' => 'noreply@example.test',
                    'meta' => 'usenet',
                    'url' => 'https://indexer.example.test',
                ],
            ],
            'Offset' => 0,
            'Type' => 'api',
        ]);
    }
}
