<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

// Install the delegating observer before PHP caches a global gzencode fallback.
require_once __DIR__.'/Fixtures/ManifestCompressionProbe.php';
