<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => __DIR__.'/../app/', 'Tests\\' => __DIR__.'/', 'Database\\Factories\\' => __DIR__.'/../database/factories/', 'Database\\Seeders\\' => __DIR__.'/../database/seeders/'] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    }
}, true, true);

// Install the delegating observer before PHP caches a global gzencode fallback.
require_once __DIR__.'/Fixtures/ManifestCompressionProbe.php';

require_once __DIR__.'/Fixtures/NativeManifestIoProbe.php';
