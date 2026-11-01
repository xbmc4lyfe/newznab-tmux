<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ViteDockerConfigurationTest extends TestCase
{
    public function test_production_image_sets_a_utf8_locale(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');

        $this->assertSame(1, preg_match('/^FROM[^\r\n]+ AS production\R(.*)\z/sm', $dockerfile, $matches));
        $this->assertMatchesRegularExpression('/^ENV LANG=C\.UTF-8$/m', $matches[1]);
    }

    public function test_cloud_indexer_image_bundles_the_native_yenc_decoder(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');

        $this->assertMatchesRegularExpression('/^FROM rust:1\.98-bookworm AS rust-toolchain$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^FROM dunglas\/frankenphp:1-php8\.5-bookworm AS yenc$/m', $dockerfile);
        $this->assertStringContainsString('cargo test --release --locked', $dockerfile);
        $this->assertStringContainsString('COPY --from=yenc /out/libnzbyenc.so /usr/local/lib/nntmux/libnzbyenc.so', $dockerfile);
        $this->assertMatchesRegularExpression('/install-php-extensions[^\n]+\bffi\b/', $dockerfile);
        $this->assertStringContainsString(
            'COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini',
            $dockerfile,
        );
        $this->assertStringContainsString('ffi.enable = false', $this->projectFile('docker/8.5/php.ini'));
        $this->assertSame("ffi.enable = true\n", $this->projectFile('docker/app/php/yenc-ffi.ini'));

        $cloudCompose = Yaml::parse($this->projectFile('deploy/cloud/compose.yaml'));
        $environment = $cloudCompose['services']['indexer']['environment'];

        $this->assertSame('auto', $environment['YENC_DECODER']);
        $this->assertSame('/usr/local/lib/nntmux/libnzbyenc.so', $environment['YENC_NATIVE_LIBRARY']);
        $this->assertSame(
            '/usr/local/etc/php/conf.d:/usr/local/etc/php/indexer-conf.d',
            $environment['PHP_INI_SCAN_DIR'],
        );
    }

    public function test_self_hosted_shutdown_signals_and_grace_allow_active_workers_to_finish(): void
    {
        $services = Yaml::parse($this->projectFile('docker/docker-compose.yml'))['services'];
        foreach (['web', 'horizon', 'scheduler', 'indexer'] as $service) {
            $this->assertTrue($services[$service]['init']);
            $this->assertSame('SIGTERM', $services[$service]['stop_signal']);
        }
        $this->assertSame('120s', $services['indexer']['stop_grace_period']);
        $this->assertSame('${INDEXER_DRAIN_TIMEOUT:-90}', $services['indexer']['environment']['INDEXER_DRAIN_TIMEOUT']);
        $this->assertStringContainsString('INDEXER_DRAIN_TIMEOUT=90', $this->projectFile('docker/.env.example'));
        $this->assertStringContainsString('INDEXER_DRAIN_TIMEOUT=90', $this->projectFile('.env.example'));

        preg_match_all("/'timeout' => ([0-9]+)/", $this->projectFile('config/horizon.php'), $timeouts);
        $this->assertNotEmpty($timeouts[1]);
        $this->assertGreaterThan(max(array_map('intval', $timeouts[1])), (int) $services['horizon']['stop_grace_period']);
    }

    public function test_self_hosted_indexer_requires_completed_startup_before_reporting_healthy(): void
    {
        $service = Yaml::parse($this->projectFile('docker/docker-compose.yml'))['services']['indexer'];
        $this->assertSame([
            'CMD-SHELL',
            'test -f /var/tmp/nntmux/indexer.ready && php artisan tmux:health-check --session=nntmux --require-session --quiet',
        ], $service['healthcheck']['test']);
    }

    public function test_compose_uses_latest_tags_for_services_that_publish_them(): void
    {
        $this->assertServicesUseLatestTags('docker-compose.yml.prod-dist');
    }

    public function test_self_hosted_stack_enables_ffi_only_for_the_indexer(): void
    {
        $compose = Yaml::parse($this->projectFile('docker/docker-compose.yml'));

        $this->assertSame(
            '/usr/local/etc/php/conf.d:/usr/local/etc/php/indexer-conf.d',
            $compose['services']['indexer']['environment']['PHP_INI_SCAN_DIR'],
        );
        foreach (['web', 'horizon', 'scheduler'] as $service) {
            $this->assertArrayNotHasKey('PHP_INI_SCAN_DIR', $compose['services'][$service]['environment']);
        }
        $this->assertDoesNotMatchRegularExpression(
            '~^COPY[^\n]+yenc-ffi\.ini[^\n]+/conf\.d/~m',
            $this->projectFile('Dockerfile'),
        );
        $this->assertFileDoesNotExist(__DIR__.'/../../docker/app/Dockerfile');
    }

    public function test_dockerfile_separates_cached_base_isolated_builders_and_per_service_targets(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');
        $compose = Yaml::parse($this->projectFile('docker/docker-compose.yml'))['services'];

        $basePos = strpos($dockerfile, 'FROM base-os AS base');
        $firstCopyPos = strpos($dockerfile, 'COPY docker/app/nzb-yenc-ffi/');
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($firstCopyPos);
        $this->assertLessThan($firstCopyPos, $basePos, 'OS base stage must precede repository COPY layers');

        // A shared Cargo target cache trusts COPY-preserved mtimes and can ship another checkout's artifacts.
        $this->assertStringNotContainsString('--mount=type=cache,target=/src/docker/app/nzb-yenc-ffi/target', $dockerfile);
        $this->assertStringContainsString('--mount=type=cache,target=/usr/local/cargo/registry', $dockerfile);
        $this->assertStringContainsString('COPY app/Support/AdminReleaseNames.php /app/app/Support/AdminReleaseNames.php', $dockerfile);
        $this->assertStringNotContainsString('resources/forum', $dockerfile);
        $this->assertMatchesRegularExpression('/^FROM base-os AS vendor$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^FROM node AS frontend$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^FROM selfhost-runtime AS app-core$/m', $dockerfile);
        foreach (['selfhosted-web', 'selfhosted-horizon', 'selfhosted-scheduler', 'selfhosted-indexer', 'selfhosted', 'production'] as $target) {
            $this->assertMatchesRegularExpression('/^FROM [^\r\n]+ AS '.preg_quote($target, '/').'$/m', $dockerfile);
        }

        $this->assertSame('${WEB_IMAGE:-nntmux/web:local}', $compose['web']['image']);
        $this->assertSame('${HORIZON_IMAGE:-nntmux/horizon:local}', $compose['horizon']['image']);
        $this->assertSame('${SCHEDULER_IMAGE:-nntmux/scheduler:local}', $compose['scheduler']['image']);
        $this->assertSame('${INDEXER_IMAGE:-nntmux/indexer:local}', $compose['indexer']['image']);
    }

    /**
     * docker-compose.yml is the developer's local Sail file and is gitignored, so it only exists outside CI.
     */
    public function test_local_sail_compose_file_matches_the_expected_configuration(): void
    {
        if (! file_exists(__DIR__.'/../../docker-compose.yml')) {
            $this->markTestSkipped('docker-compose.yml is a gitignored local Sail file.');
        }

        $this->assertServicesUseLatestTags('docker-compose.yml');

        $composeConfig = $this->projectFile('docker-compose.yml');
        $localServices = Yaml::parse($composeConfig)['services'];
        $this->assertSame(
            ['CMD', 'mariadb-admin', 'ping', '-p${DB_PASSWORD}'],
            $localServices['mariadb']['healthcheck']['test'],
        );
        $this->assertStringContainsString("'\${VITE_PORT:-5173}:\${VITE_PORT:-5173}'", $composeConfig);
    }

    public function test_docker_build_targets_always_pull_images_before_building(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../Makefile');
        $this->assertFileDoesNotExist(__DIR__.'/../../docker/Makefile');
        $this->assertFileExists(__DIR__.'/../../Justfile');
        $this->assertFileExists(__DIR__.'/../../docker/Justfile');

        foreach (['build', 'rebuild', 'update', 'fresh'] as $target) {
            $process = new Process([
                'just', '--dry-run', 'SAIL=mock-sail', 'DOCKER_COMPOSE=mock-compose', $target,
            ], __DIR__.'/../..');
            $process->mustRun();

            $commands = $process->getOutput().$process->getErrorOutput();
            $pullPosition = strpos($commands, 'mock-compose pull --ignore-buildable --policy always');
            $buildCommand = in_array($target, ['rebuild', 'fresh'], true)
                ? 'mock-sail build --no-cache --pull'
                : 'mock-sail build --pull';
            $buildPosition = strpos($commands, $buildCommand);

            $this->assertNotFalse($pullPosition, "{$target} must refresh service images.");
            $this->assertNotFalse($buildPosition, "{$target} must refresh build base images.");
            $this->assertLessThan($buildPosition, $pullPosition, "{$target} must pull before building.");
        }
    }

    public function test_every_sail_or_compose_recipe_gets_the_profiles_and_monitoring_overlay(): void
    {
        $root = __DIR__.'/../..';
        $summary = (new Process(['just', '--summary'], $root))->mustRun()->getOutput();
        $checked = 0;
        foreach (preg_split('/\s+/', trim($summary)) as $recipe) {
            if (str_contains($recipe, '::')) {
                continue;
            }
            $process = new Process(['just', '--dry-run', $recipe], $root);
            $process->run();
            $commands = $process->getOutput().$process->getErrorOutput();
            if (! preg_match('/\$SAIL|\.\/sail|\$DOCKER_COMPOSE|docker compose/', $commands)) {
                continue;
            }
            $checked++;
            $this->assertStringContainsString('export COMPOSE_PROFILES=', $commands, "{$recipe} must export the Compose profiles like the old Makefile.");
        }
        $this->assertGreaterThan(30, $checked);
    }

    public function test_root_env_values_are_read_like_compose_and_make(): void
    {
        $directory = sys_get_temp_dir().'/dotenv-get-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/.env', "SEARCH_DRIVER=custom # engine\nMONITORING_ENABLED=\"true\" # on\nWWWUSER = 501\nWWWUSER=502\nCOMPOSE_PROFILES=\n");
        try {
            $read = fn (string $key, string $default = ''): string => (new Process([__DIR__.'/../../scripts/dotenv-get', $key, $default], $directory))->mustRun()->getOutput();
            $this->assertSame('custom', $read('SEARCH_DRIVER', 'manticore'));
            $this->assertSame('true', $read('MONITORING_ENABLED'));
            $this->assertSame('502', $read('WWWUSER'));
            $this->assertSame('9090', $read('FORWARD_PROMETHEUS_PORT', '9090'));
            $this->assertSame('', $read('COMPOSE_PROFILES'));
            // make's `?=`: an empty COMPOSE_PROFILES line still counts as set; only a missing key falls back.
            $raw = fn (string $key, string $default): string => (new Process([__DIR__.'/../../scripts/dotenv-get', '--raw', $key, $default], $directory))->mustRun()->getOutput();
            $this->assertSame('', $raw('COMPOSE_PROFILES', '__absent__'));
            $this->assertSame('__absent__', $raw('MISSING_KEY', '__absent__'));
        } finally {
            unlink($directory.'/.env');
            rmdir($directory);
        }
    }

    public function test_role_targets_copy_the_files_only_their_role_runs(): void
    {
        $dockerfile = $this->projectFile('Dockerfile');
        $stage = function (string $name) use ($dockerfile): string {
            $start = strpos($dockerfile, " AS {$name}\n");
            $this->assertNotFalse($start, $name);
            $next = strpos($dockerfile, "\nFROM ", $start);

            return substr($dockerfile, $start, $next === false ? null : $next - $start);
        };
        $this->assertStringContainsString('COPY docker/app/Caddyfile /etc/frankenphp/nntmux.Caddyfile', $stage('selfhosted-web'));
        $this->assertStringContainsString('COPY --from=frontend --chown=www-data:www-data /app/public/build /app/public/build', $stage('selfhosted-web'));
        $this->assertStringContainsString('docker/app/bin/scheduler.py /app/docker/app/bin/scheduler.py', $stage('selfhosted-scheduler'));
        $this->assertStringContainsString('COPY docker/app/php/yenc-ffi.ini /usr/local/etc/php/indexer-conf.d/99-yenc-ffi.ini', $stage('selfhosted-indexer'));
        $this->assertStringContainsString('docker/app/bin/indexer.sh /usr/local/bin/nntmux-indexer', $stage('selfhosted-indexer'));
        foreach (['selfhosted-horizon', 'selfhosted-scheduler', 'selfhosted-indexer'] as $role) {
            $this->assertStringNotContainsString('public/build', $stage($role), "{$role} must not depend on Vite output");
        }
        $allInOne = $stage('selfhosted');
        foreach (['scheduler.py', 'yenc-ffi.ini', 'indexer.sh'] as $file) {
            $this->assertStringContainsString($file, $allInOne);
        }
        $this->assertStringContainsString('FROM selfhosted-web AS selfhosted', $dockerfile);
        $this->assertStringContainsString('/app/public/build', $stage('production'));
    }

    public function test_container_quality_and_dependency_recipes_still_run_through_sail(): void
    {
        foreach (['test', 'pint', 'pint-all', 'phpstan', 'rector', 'rector-fix', 'npm-build', 'npm-dev', 'ts-types', 'ts-types-check', 'data-cache', 'composer-install', 'composer-update'] as $recipe) {
            $process = new Process(['just', '--dry-run', $recipe], __DIR__.'/../..');
            $process->mustRun();
            $this->assertMatchesRegularExpression('/\$SAIL"? /', $process->getOutput().$process->getErrorOutput(), "{$recipe} must run inside the Sail container.");
        }

        $process = new Process(['just', '--dry-run', 'optimize-deploy'], __DIR__.'/../..');
        $process->mustRun();
        $this->assertStringContainsString('only run on the deploy host', $process->getOutput().$process->getErrorOutput());
    }

    public function test_vite_uses_the_docker_published_port_without_fallback(): void
    {
        $viteConfig = $this->projectFile('vite.config.js');

        $this->assertStringContainsString("loadEnv(mode, process.cwd(), '')", $viteConfig);
        $this->assertStringContainsString("environment.VITE_PORT || '5173'", $viteConfig);
        $this->assertStringContainsString("environment.VITE_DEV_SERVER_HOST || 'localhost'", $viteConfig);
        $this->assertStringContainsString("host: '0.0.0.0'", $viteConfig);
        $this->assertStringContainsString('strictPort: true', $viteConfig);
        $this->assertStringContainsString('origin: `http://${viteDevServerHost}:${vitePort}`', $viteConfig);
        $this->assertStringContainsString('VITE_DEV_SERVER_HOST=localhost', $this->projectFile('.env.example'));
    }

    public function test_container_startup_clears_stale_vite_hot_state(): void
    {
        $startContainer = $this->projectFile('docker/8.5/start-container');

        $unlinkPosition = strpos($startContainer, 'unlink /var/www/html/public/hot');
        $supervisorPosition = strpos($startContainer, 'exec /usr/bin/supervisord');

        $this->assertNotFalse($unlinkPosition);
        $this->assertNotFalse($supervisorPosition);
        $this->assertLessThan($supervisorPosition, $unlinkPosition);
    }

    public function test_container_startup_aligns_runtime_directory_ownership_with_the_host_user(): void
    {
        $startContainer = $this->projectFile('docker/8.5/start-container');

        $this->assertStringContainsString('usermod -u "$WWWUSER" -o sail', $startContainer);
        $this->assertStringContainsString('storage/framework storage/logs bootstrap/cache', $startContainer);
        $this->assertStringContainsString('chown -R sail:"${SAIL_GROUP}"', $startContainer);
        $this->assertStringContainsString('chmod -R ug+rwX', $startContainer);

        $permissionRepairPosition = strpos($startContainer, 'chown -R sail:"${SAIL_GROUP}"');
        $supervisorPosition = strpos($startContainer, 'exec /usr/bin/supervisord');

        $this->assertNotFalse($permissionRepairPosition);
        $this->assertNotFalse($supervisorPosition);
        $this->assertLessThan($supervisorPosition, $permissionRepairPosition);
    }

    public function test_nginx_serves_laravel_public_storage_without_a_host_symlink(): void
    {
        $nginxConfig = $this->projectFile('docker/8.5/nginx.conf');

        $this->assertStringContainsString('location ^~ /storage/', $nginxConfig);
        $this->assertStringContainsString('alias /var/www/html/storage/app/public/;', $nginxConfig);
    }

    public function test_cursor_registers_nntmux_mcp_through_sail_alongside_boost(): void
    {
        $configuration = json_decode(
            $this->projectFile('.cursor/mcp.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('./vendor/bin/sail', $configuration['mcpServers']['laravel-boost']['command']);
        $this->assertSame(['artisan', 'boost:mcp'], $configuration['mcpServers']['laravel-boost']['args']);
        $this->assertSame('./vendor/bin/sail', $configuration['mcpServers']['newznab-tmux']['command']);
        $this->assertSame(
            ['artisan', 'mcp:start', 'newznab-tmux'],
            $configuration['mcpServers']['newznab-tmux']['args'],
        );
    }

    private function assertServicesUseLatestTags(string $path): void
    {
        $services = Yaml::parse($this->projectFile($path))['services'];

        $this->assertSame('mariadb:latest', $services['mariadb']['image'], $path);
        $this->assertSame('redis:latest', $services['redis']['image'], $path);
        $this->assertSame('manticoresearch/manticore:latest', $services['manticore']['image'], $path);
    }

    private function projectFile(string $path): string
    {
        return (string) file_get_contents(__DIR__."/../../{$path}");
    }
}
