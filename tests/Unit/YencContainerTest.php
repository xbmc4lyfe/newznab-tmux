<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Yenc;
use App\Providers\AppServiceProvider;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

final class YencContainerTest extends TestCase
{
    public function test_provider_and_facade_use_the_configured_shared_service(): void
    {
        $previous = Facade::getFacadeApplication();
        $previousContainer = Application::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['yenc' => ['decoder' => 'php']]));
        $app->instance('log', new NullLogger);
        (new AppServiceProvider($app))->register();
        Facade::clearResolvedInstance(YencService::class);
        Facade::setFacadeApplication($app);
        try {
            $service = $app->make(YencService::class);
            $this->assertInstanceOf(YencService::class, $service);
            $this->assertSame($service, $app->make(YencService::class));
            $this->assertSame($service, Yenc::getFacadeRoot());
            $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3";
            $this->assertSame('ABC', Yenc::decode($article));
        } finally {
            Facade::clearResolvedInstance(YencService::class);
            Facade::setFacadeApplication($previous);
            Application::setInstance($previousContainer);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function libraryOverrides(): iterable
    {
        yield 'unset uses local build' => [null];
        yield 'empty uses local build' => [''];
        yield 'explicit path is preserved' => ['/custom/rapidyenc/librapidyenc.so'];
    }

    #[DataProvider('libraryOverrides')]
    public function test_config_defaults_to_build_location_and_preserves_overrides(?string $override): void
    {
        $previousContainer = Application::getInstance();
        $previousEnv = $_ENV['YENC_NATIVE_LIBRARY'] ?? null;
        $previousServer = $_SERVER['YENC_NATIVE_LIBRARY'] ?? null;
        $previousProcess = getenv('YENC_NATIVE_LIBRARY');
        $root = dirname(__DIR__, 2);
        new Application($root);
        unset($_ENV['YENC_NATIVE_LIBRARY'], $_SERVER['YENC_NATIVE_LIBRARY']);
        putenv('YENC_NATIVE_LIBRARY');
        if ($override !== null) {
            $_ENV['YENC_NATIVE_LIBRARY'] = $override;
            $_SERVER['YENC_NATIVE_LIBRARY'] = $override;
            putenv('YENC_NATIVE_LIBRARY='.$override);
        }
        try {
            $config = require $root.'/config/yenc.php';
            $expected = $override ?: $root.'/storage/app/rapidyenc/librapidyenc.so';
            $this->assertSame($expected, $config['native_library']);
        } finally {
            unset($_ENV['YENC_NATIVE_LIBRARY'], $_SERVER['YENC_NATIVE_LIBRARY']);
            if ($previousEnv !== null) {
                $_ENV['YENC_NATIVE_LIBRARY'] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER['YENC_NATIVE_LIBRARY'] = $previousServer;
            }
            putenv($previousProcess === false ? 'YENC_NATIVE_LIBRARY' : 'YENC_NATIVE_LIBRARY='.$previousProcess);
            Application::setInstance($previousContainer);
        }
    }

    public function test_auto_and_php_do_not_require_a_native_library(): void
    {
        $factory = new DecoderFactory;
        $this->assertInstanceOf(PhpPayloadDecoder::class, $factory->make());
        $this->assertInstanceOf(PhpPayloadDecoder::class, $factory->make('php', '/missing/library.so'));
    }

    public function test_auto_reports_a_configured_failure_only_once(): void
    {
        $logger = new class extends AbstractLogger
        {
            public int $warnings = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->warnings++;
            }
        };
        $path = '/missing/yenc-'.getmypid().'.so';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertInstanceOf(PhpPayloadDecoder::class, (new DecoderFactory($logger))->make('auto', $path));
        }
        $this->assertSame(1, $logger->warnings);
    }

    public function test_forced_native_fails_when_unavailable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Native yEnc is unavailable');
        (new DecoderFactory)->make('native', '/missing/library.so');
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new DecoderFactory)->make('typo');
    }

    public function test_native_selection_respects_ffi_availability(): void
    {
        $library = (string) getenv('YENC_NATIVE_LIBRARY');
        if ($library === '') {
            $this->markTestSkipped('Set YENC_NATIVE_LIBRARY to test actual FFI selection.');
        }
        $factory = new DecoderFactory(new NullLogger);
        if (in_array(strtolower((string) ini_get('ffi.enable')), ['', '0', 'false', 'off'], true) || ! extension_loaded('FFI')) {
            $this->assertInstanceOf(PhpPayloadDecoder::class, $factory->make('auto', $library));
            $this->expectException(RuntimeException::class);
            $factory->make('native', $library);
        } else {
            $this->assertInstanceOf(NativePayloadDecoder::class, $factory->make('auto', $library));
            $this->assertInstanceOf(NativePayloadDecoder::class, $factory->make('native', $library));
        }
    }
}
