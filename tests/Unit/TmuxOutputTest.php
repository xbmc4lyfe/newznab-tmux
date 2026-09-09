<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tmux\TmuxOutput;
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class TmuxOutputTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function processingModes(): iterable
    {
        yield 'parallel' => [3, 0];
        yield 'postprocessing disabled' => [0, 0];
        yield 'stripped' => [3, 2];
    }

    #[DataProvider('processingModes')]
    public function test_decoder_line_follows_postprocessing_when_present(int $post, int $sequential): void
    {
        $header = $this->header(new YencService, $post, $sequential);
        $this->assertStringContainsString("yEnc Decoder: PHP\n", $header);
        if ($post === 3 && $sequential !== 2) {
            $this->assertMatchesRegularExpression('/Postprocess: [^\n]*\nyEnc Decoder: PHP\n/', $header);
        } else {
            $this->assertStringNotContainsString('Postprocess:', $header);
        }
    }

    public function test_auto_fallback_displays_php_instead_of_requested_mode(): void
    {
        $decoder = (new DecoderFactory(new NullLogger))->make('auto', '/missing/monitor-yenc.so');
        $this->assertStringContainsString("yEnc Decoder: PHP\n", $this->header(new YencService($decoder)));
    }

    public function test_native_backend_displays_rapidyenc(): void
    {
        $library = (string) getenv('YENC_NATIVE_LIBRARY');
        if ($library === '' || ! extension_loaded('FFI') || in_array(strtolower((string) ini_get('ffi.enable')), ['', '0', 'false', 'off'], true)) {
            $this->markTestSkipped('Set YENC_NATIVE_LIBRARY and enable CLI FFI to test native monitor output.');
        }
        $decoder = (new DecoderFactory)->make('native', $library);
        $this->assertMatchesRegularExpression('/Postprocess: [^\n]*\nyEnc Decoder: RapidYenc\n/', $this->header(new YencService($decoder)));
    }

    private function header(YencService $service, int $post = 3, int $sequential = 0): string
    {
        $previous = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance(YencService::class, $service);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance(YencService::class);
        try {
            $reflection = new ReflectionClass(TmuxOutput::class);
            $output = $reflection->newInstanceWithoutConstructor();
            $values = [
                'gitVersion' => 'test', 'gitBranch' => 'test',
                'tmpMasks' => [1 => "%s %s\n", 2 => "%s %s\n"],
                'runVar' => [
                    'settings' => ['is_running' => 1, 'post' => $post],
                    'constants' => ['alternate_nntp' => '0', 'delaytime' => 2, 'sequential' => $sequential],
                    'timers' => ['timer1' => time(), 'timer3' => time(), 'newOld' => ['newestrelname' => 'test release']],
                    'conncounts' => ['primary' => ['active' => 1, 'total' => 2]],
                    'connections' => ['host' => 'news.example.test', 'port' => 119],
                    'counts' => ['now' => []],
                ],
            ];
            foreach ($values as $name => $value) {
                (new ReflectionProperty(TmuxOutput::class, $name))->setValue($output, $value);
            }

            return (new ReflectionMethod(TmuxOutput::class, '_getHeader'))->invoke($output);
        } finally {
            Facade::clearResolvedInstance(YencService::class);
            Facade::setFacadeApplication($previous);
        }
    }
}
