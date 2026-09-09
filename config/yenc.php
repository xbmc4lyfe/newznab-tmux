<?php

return [
    // Auto uses the configured native library in Linux CLI and falls back to PHP.
    'decoder' => env('YENC_DECODER', 'auto'),
    // The build script writes here. A non-empty value overrides the default path.
    'native_library' => env('YENC_NATIVE_LIBRARY') ?: base_path('storage/app/rapidyenc/librapidyenc.so'),
];
