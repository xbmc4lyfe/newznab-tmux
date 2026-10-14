@props(['release'])

{{ app(\App\Support\AdminReleaseNames::class)->render([$release])[0] }}
