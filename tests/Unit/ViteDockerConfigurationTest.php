<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ViteDockerConfigurationTest extends TestCase
{
    public function test_vite_uses_the_docker_published_port_without_fallback(): void
    {
        $viteConfig = $this->projectFile('vite.config.js');
        $composeConfig = $this->projectFile('docker-compose.yml');

        $this->assertStringContainsString("loadEnv(mode, process.cwd(), '')", $viteConfig);
        $this->assertStringContainsString("environment.VITE_PORT || '5173'", $viteConfig);
        $this->assertStringContainsString("environment.VITE_DEV_SERVER_HOST || 'localhost'", $viteConfig);
        $this->assertStringContainsString("host: '0.0.0.0'", $viteConfig);
        $this->assertStringContainsString('strictPort: true', $viteConfig);
        $this->assertStringContainsString('origin: `http://${viteDevServerHost}:${vitePort}`', $viteConfig);
        $this->assertStringContainsString("'\${VITE_PORT:-5173}:\${VITE_PORT:-5173}'", $composeConfig);
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

    private function projectFile(string $path): string
    {
        return (string) file_get_contents(__DIR__."/../../{$path}");
    }
}
