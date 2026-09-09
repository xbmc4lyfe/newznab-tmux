<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\NativePayloadDecoder;
use App\Services\YencService;

final class YencNativeDecoderTest extends YencDecoderTest
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('YENC_NATIVE_LIBRARY') || ! extension_loaded('FFI') || in_array(strtolower((string) ini_get('ffi.enable')), ['', '0', 'false', 'off'], true)) {
            $this->markTestSkipped('Set YENC_NATIVE_LIBRARY and enable CLI FFI to run native parity tests.');
        }
    }

    protected function service(): YencService
    {
        return new YencService(new NativePayloadDecoder((string) getenv('YENC_NATIVE_LIBRARY')));
    }

    public function test_reports_rapidyenc_decoder(): void
    {
        $this->assertSame('RapidYenc', $this->service()->decoderName());
    }
}
