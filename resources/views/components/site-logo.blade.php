@props([
    'url' => null,
    'fallbackIcon' => 'fas fa-layer-group',
    'imageClass' => 'h-full w-full object-contain p-1.5',
])

@if(is_string($url) && $url !== '')
    <img src="{{ $url }}" alt="" class="{{ $imageClass }}">
@else
    <i class="{{ $fallbackIcon }} text-lg text-primary-300" aria-hidden="true"></i>
@endif
