<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CollectSystemMetrics;
use App\Console\Commands\DeployInitialize;
use App\Console\Commands\ExportMonitoringMetrics;
use App\Console\Commands\InstallMonitoring;
use App\Console\Commands\TmuxAttach;
use FFI;
use Tests\TestCase;
use Throwable;

final class RustMonitoringMetricsDeployTmuxAttachCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_install_monitoring_is_valid_web_server(const char *value_ptr, size_t value_len);
int32_t nntmux_install_monitoring_is_supported_ubuntu_version(const char *value_ptr, size_t value_len);
ssize_t nntmux_install_monitoring_project_relative_path(
    const char *base_ptr,
    size_t base_len,
    const char *path_ptr,
    size_t path_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_collect_system_metrics_format_deleted_message(int64_t deleted, uint8_t *out_buf, size_t out_cap);
ssize_t nntmux_collect_system_metrics_format_error_message(
    const char *message_ptr,
    size_t message_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_export_monitoring_metrics_resolve_export_mode(
    uint8_t print_only,
    uint8_t enabled,
    const char *url_ptr,
    size_t url_len,
    uint8_t has_pcntl
);
ssize_t nntmux_export_monitoring_metrics_format_failure_message(
    const char *message_ptr,
    size_t message_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_deploy_initialize_is_valid_search_driver(const char *driver_ptr, size_t driver_len);
ssize_t nntmux_deploy_initialize_decode_app_key(
    const char *key_ptr,
    size_t key_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_deploy_initialize_search_index_command(
    const char *driver_ptr,
    size_t driver_len,
    uint8_t recreate
);
ssize_t nntmux_tmux_attach_resolve_session_name(
    uint8_t has_option,
    const char *option_ptr,
    size_t option_len,
    const char *default_ptr,
    size_t default_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_attach_format_attaching_message(
    const char *name_ptr,
    size_t name_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_attach_format_session_not_found_message(
    const char *name_ptr,
    size_t name_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * @var list<class-string<InstallMonitoring|CollectSystemMetrics|ExportMonitoringMetrics|DeployInitialize|TmuxAttach>>
     */
    private const COMMANDS = [
        InstallMonitoring::class,
        CollectSystemMetrics::class,
        ExportMonitoringMetrics::class,
        DeployInitialize::class,
        TmuxAttach::class,
    ];

    /**
     * Strings covering UTF-8, NUL bytes, PHP `trim()` whitespace, and lookalike values.
     */
    private const STRINGS = [
        '',
        ' ',
        "\0",
        " \t\n\r\0\x0B",
        "\x0C",
        'nginx',
        'apache',
        'Nginx',
        'nginx ',
        "nginx\0",
        '22.04',
        '24.04',
        '20.04',
        '22.04 ',
        'manticore',
        'elasticsearch',
        'ElasticSearch',
        ' manticore',
        'http://pushgateway:9091',
        '  http://x  ',
        'é😀',
        "a\0é\"b",
        "line\nbreak",
        '-1',
        '0',
    ];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }

        parent::tearDown();
    }

    public function test_rust_ffi_toggles_default_to_php(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP");
            $class::useRustFfi();
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_native_paths_fall_back_to_php_without_the_library(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertTrue(InstallMonitoring::isValidWebServer('nginx'));
        $this->assertFalse(InstallMonitoring::isSupportedUbuntuVersion('20.04'));
        $this->assertSame('storage/x', InstallMonitoring::formatProjectRelativePath('/app/', '/app/storage/x'));
        $this->assertSame('Deleted 7 old metric records.', CollectSystemMetrics::formatDeletedMetricsMessage(7));
        $this->assertSame('Failed to collect system metrics: boom', CollectSystemMetrics::formatErrorMessage('boom'));
        $this->assertSame('disabled', ExportMonitoringMetrics::resolveExportMode(false, true, ' ', true));
        $this->assertSame('Monitoring metrics export failed: x', ExportMonitoringMetrics::formatExportFailureMessage('x'));
        $this->assertTrue(DeployInitialize::isValidSearchDriver('manticore'));
        $this->assertSame('ABC', DeployInitialize::decodeAppKeyValue('base64:QUJD'));
        $this->assertSame(['manticore:create-indexes', ['--no-interaction' => true]], DeployInitialize::resolveSearchIndexCommand('manticore', false));
        $this->assertSame('main', TmuxAttach::resolveSessionName(null, 'main'));
        $this->assertSame('Attaching to tmux session: s', TmuxAttach::formatAttachingMessage('s'));
        $this->assertSame('Tmux session "s" does not exist.', TmuxAttach::formatSessionNotFoundMessage('s'));
    }

    public function test_install_monitoring_helpers_parity(): void
    {
        $this->useLibrary();

        foreach (self::STRINGS as $value) {
            $this->assertSame(InstallMonitoring::isValidWebServerPhp($value), InstallMonitoring::isValidWebServerNative($value), $value);
            $this->assertSame(InstallMonitoring::isSupportedUbuntuVersionPhp($value), InstallMonitoring::isSupportedUbuntuVersionNative($value), $value);
        }

        $bases = ['', '/', '//', '/app', '/app/', '/app///', "/é\0", 'relative', '/var/www/nntmux'];
        $paths = ['', '/', '/app', '/app/', '/app/storage/app/monitoring/grafana-jwt.key', '/application/x', "/é\0/ü", 'relative/x', '/var/www/nntmux/storage', '//x'];
        foreach ($bases as $base) {
            foreach ($paths as $path) {
                $this->assertSame(
                    InstallMonitoring::formatProjectRelativePathPhp($base, $path),
                    InstallMonitoring::formatProjectRelativePathNative($base, $path),
                    "{$base} | {$path}"
                );
            }
        }

        $this->assertSame('', InstallMonitoring::formatProjectRelativePathNative('/app', '/app/'));
        $this->assertSame('/app', InstallMonitoring::formatProjectRelativePathNative('/app', '/app'));
    }

    public function test_metrics_and_export_helpers_parity(): void
    {
        $this->useLibrary();

        foreach ([0, 1, -1, 42, PHP_INT_MAX, PHP_INT_MIN] as $deleted) {
            $this->assertSame(CollectSystemMetrics::formatDeletedMetricsMessagePhp($deleted), CollectSystemMetrics::formatDeletedMetricsMessageNative($deleted));
        }

        foreach ([...self::STRINGS, str_repeat('é', 5000)] as $message) {
            $this->assertSame(CollectSystemMetrics::formatErrorMessagePhp($message), CollectSystemMetrics::formatErrorMessageNative($message));
            $this->assertSame(ExportMonitoringMetrics::formatExportFailureMessagePhp($message), ExportMonitoringMetrics::formatExportFailureMessageNative($message));
        }

        foreach ([true, false] as $printOnly) {
            foreach ([true, false] as $enabled) {
                foreach ([true, false] as $hasPcntl) {
                    foreach (self::STRINGS as $url) {
                        $this->assertSame(
                            ExportMonitoringMetrics::resolveExportModePhp($printOnly, $enabled, $url, $hasPcntl),
                            ExportMonitoringMetrics::resolveExportModeNative($printOnly, $enabled, $url, $hasPcntl),
                            json_encode([$printOnly, $enabled, bin2hex($url), $hasPcntl], JSON_THROW_ON_ERROR)
                        );
                    }
                }
            }
        }

        $this->assertSame('push', ExportMonitoringMetrics::resolveExportModeNative(false, true, "\x0C", false));
        $this->assertSame('disabled', ExportMonitoringMetrics::resolveExportModeNative(false, true, " \t\n\r\0\x0B", true));
    }

    public function test_deploy_initialize_helpers_parity(): void
    {
        $this->useLibrary();

        foreach (self::STRINGS as $driver) {
            $this->assertSame(DeployInitialize::isValidSearchDriverPhp($driver), DeployInitialize::isValidSearchDriverNative($driver), $driver);
            foreach ([true, false] as $recreate) {
                $this->assertSame(
                    DeployInitialize::resolveSearchIndexCommandPhp($driver, $recreate),
                    DeployInitialize::resolveSearchIndexCommandNative($driver, $recreate),
                    $driver
                );
            }
        }

        $keys = [
            ...self::STRINGS,
            'base64:',
            'base64:'.base64_encode(str_repeat('k', 32)),
            'base64:'.base64_encode(random_bytes(32)),
            'base64:QUJD',
            'base64:QUI=',
            'base64:QQ==',
            'base64:QQ',
            'base64:QR==',
            "base64: Q Q\t=\n=\r",
            'base64:QQ==QQ',
            'base64:QQ=',
            'base64:Q',
            'base64:QQ===',
            'base64:=',
            'base64:==',
            "base64:QQ\x0B",
            "base64:QQ\x0C",
            "base64:QQ\0",
            'base64:QQ-_',
            'base64:AA==',
            'base64:é',
            'Base64:QUJD',
            'base64',
        ];
        $alphabet = str_split("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=  \t\n\r\x0B-_\0é");
        mt_srand(316);
        for ($i = 0; $i < 2000; $i++) {
            $payload = '';
            $length = mt_rand(0, 24);
            for ($j = 0; $j < $length; $j++) {
                $payload .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $keys[] = 'base64:'.$payload;
        }

        foreach ($keys as $key) {
            $this->assertSame(DeployInitialize::decodeAppKeyValuePhp($key), DeployInitialize::decodeAppKeyValueNative($key), bin2hex($key));
        }
    }

    public function test_tmux_attach_helpers_parity(): void
    {
        $this->useLibrary();

        foreach ([null, ...self::STRINGS] as $option) {
            foreach (['nntmux', '', "d\0é"] as $default) {
                $this->assertSame(TmuxAttach::resolveSessionNamePhp($option, $default), TmuxAttach::resolveSessionNameNative($option, $default));
            }
        }

        foreach (self::STRINGS as $name) {
            $this->assertSame(TmuxAttach::formatAttachingMessagePhp($name), TmuxAttach::formatAttachingMessageNative($name));
            $this->assertSame(TmuxAttach::formatSessionNotFoundMessagePhp($name), TmuxAttach::formatSessionNotFoundMessageNative($name));
        }
    }

    public function test_dispatchers_follow_the_toggle(): void
    {
        $this->useLibrary();

        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(true);
        }

        $this->assertTrue(InstallMonitoring::isValidWebServer('apache'));
        $this->assertTrue(InstallMonitoring::isSupportedUbuntuVersion('24.04'));
        $this->assertSame('storage', InstallMonitoring::formatProjectRelativePath('/app//', '/app/storage'));
        $this->assertSame('Deleted -2 old metric records.', CollectSystemMetrics::formatDeletedMetricsMessage(-2));
        $this->assertSame("Failed to collect system metrics: \0", CollectSystemMetrics::formatErrorMessage("\0"));
        $this->assertSame('push_with_alarm', ExportMonitoringMetrics::resolveExportMode(false, true, 'http://x', true));
        $this->assertSame('print', ExportMonitoringMetrics::resolveExportMode(true, false, '', false));
        $this->assertSame(
            ['nntmux:create-es-indexes', ['--no-interaction' => true, '--create-missing' => true]],
            DeployInitialize::resolveSearchIndexCommand('elasticsearch', false)
        );
        $this->assertSame(
            ['manticore:create-indexes', ['--no-interaction' => true, '--drop' => true]],
            DeployInitialize::resolveSearchIndexCommand('manticore', true)
        );
        $this->assertSame('', DeployInitialize::decodeAppKeyValue('base64:Q'));
        $this->assertSame('opt', TmuxAttach::resolveSessionName('opt', 'default'));
        $this->assertSame('', TmuxAttach::resolveSessionName('', 'default'));
    }

    public function test_rust_entrypoints_evaluate_without_falling_back(): void
    {
        $ffi = $this->loadFfi();
        $out = $ffi->new('uint8_t[128]');

        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_install_monitoring_is_valid_web_server('nginx', 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_install_monitoring_is_supported_ubuntu_version("22.04\0", 6));
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_export_monitoring_metrics_resolve_export_mode(0, 1, 'u', 1, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(2, $ffi->nntmux_deploy_initialize_search_index_command('elasticsearch', 13, 0));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_deploy_initialize_decode_app_key('base64:AA==', 11, $out, 128);
        $this->assertSame(1, $written);
        $this->assertSame("\0", FFI::string($out, $written));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_attach_format_session_not_found_message("a\0", 2, $out, 128);
        $this->assertSame("Tmux session \"a\0\" does not exist.", FFI::string($out, $written));
        // @phpstan-ignore method.notFound
        $this->assertSame(-28, $ffi->nntmux_tmux_attach_format_attaching_message('x', 1, $out, 4));
    }

    private function useLibrary(): void
    {
        $ffi = $this->loadFfi();
        foreach (self::COMMANDS as $class) {
            $class::setFfiForTesting($ffi);
        }
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }
        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                try {
                    return FFI::cdef(self::FFI_CDEF, $path);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library with the wave 31.6 exports not found.');
    }
}
