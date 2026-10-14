<?php

declare(strict_types=1);

/**
 * Only this preloaded function can access the narrowly declared native scope.
 *
 * @return list<string>|null
 */
function nntmux_render_release_names(string $input): ?array
{
    if (strlen($input) > 8 * 1024 * 1024) {
        return null;
    }
    try {
        static $scope = null;
        $scope ??= FFI::scope('NNTMUX_RELEASE_PRESENTATION');
        $length = strlen($input);
        $buffer = $scope->new('unsigned char['.max(1, $length).']');
        FFI::memcpy($buffer, $input, $length);
        $written = $scope->new('size_t');
        $output = $scope->nntmux_release_names_html($buffer, $length, FFI::addr($written));
        if ($output === null || FFI::isNull($output)) {
            return null;
        }
        $outputLength = $written->cdata;
        try {
            // 0xFF cannot appear inside the UTF-8 HTML emitted by Rust.
            return $outputLength === 0 ? [] : explode("\xff", FFI::string($output, $outputLength));
        } finally {
            $scope->nntmux_release_names_free($output, $outputLength);
        }
    } catch (Throwable) {
        return null;
    }
}
