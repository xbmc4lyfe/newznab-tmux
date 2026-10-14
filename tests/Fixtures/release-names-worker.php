<?php

declare(strict_types=1);

// No Laravel boot, environment file, database or network access in the native worker.
if (! function_exists('nntmux_render_release_names')) {
    throw new RuntimeException('Native wrapper was not preloaded.');
}
$output = nntmux_render_release_names(stream_get_contents(STDIN));
if ($output === null) {
    throw new RuntimeException('Native renderer was unavailable or rejected the input.');
}
echo json_encode($output, JSON_THROW_ON_ERROR);
