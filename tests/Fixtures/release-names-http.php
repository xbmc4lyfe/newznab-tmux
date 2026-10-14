<?php

declare(strict_types=1);
use FFI\Exception;

header('Content-Type: application/json');
$blocked = [];
foreach (['cdef', 'load', 'scope'] as $operation) {
    try {
        match ($operation) {
            'cdef' => FFI::cdef('int puts(const char *s);'),
            'load' => FFI::load(is_file('/usr/local/lib/nntmux/release-presentation.h') ? '/usr/local/lib/nntmux/release-presentation.h' : __DIR__.'/../../docker/app/php/release-presentation.h'),
            'scope' => FFI::scope('NNTMUX_RELEASE_PRESENTATION'),
        };
        $blocked[$operation] = false;
    } catch (Exception) {
        $blocked[$operation] = true;
    }
}
echo json_encode([
    'sapi' => PHP_SAPI,
    'blocked' => $blocked,
    'output' => function_exists('nntmux_render_release_names') ? nntmux_render_release_names(file_get_contents('php://input')) : null,
], JSON_THROW_ON_ERROR);
