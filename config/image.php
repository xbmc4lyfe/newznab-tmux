<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Image Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default image processing driver that will be
    | used when manipulating or converting images. This driver is always
    | utilized unless another driver is explicitly specified instead.
    |
    | Supported: "gd", "imagick"
    |
    */

    'default' => env('IMAGE_DRIVER', 'imagick'),

    /*
    |--------------------------------------------------------------------------
    | Stored Image Processing
    |--------------------------------------------------------------------------
    |
    | These limits apply to covers, previews, and image samples. Destination
    | directories remain owned by their callers; this configuration controls
    | only decoding, remote fetching, and the encoded output.
    |
    */

    'output_format' => env('IMAGE_OUTPUT_FORMAT', 'webp'),
    'output_quality' => (int) env('IMAGE_OUTPUT_QUALITY', 82),
    'max_source_bytes' => (int) env('IMAGE_MAX_SOURCE_BYTES', 20 * 1024 * 1024),
    'max_source_pixels' => (int) env('IMAGE_MAX_SOURCE_PIXELS', 40_000_000),
    'fetch_connect_timeout' => (int) env('IMAGE_FETCH_CONNECT_TIMEOUT', 5),
    'fetch_timeout' => (int) env('IMAGE_FETCH_TIMEOUT', 30),
    'fetch_max_redirects' => (int) env('IMAGE_FETCH_MAX_REDIRECTS', 5),

];
