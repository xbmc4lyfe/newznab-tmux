{{-- Shared color scheme switcher. JS hooks by button class (see alpine/stores/theme.js). Expects: $switcherId, $btnClass, $mobile (bool) --}}
@php
    $currentScheme = $userColorScheme ?? 'blue';
    $colorSchemes = [
        ['value' => 'blue', 'label' => 'Blue', 'swatch' => 'bg-blue-600'],
        ['value' => 'indigo', 'label' => 'Indigo', 'swatch' => 'bg-indigo-600'],
        ['value' => 'cyan', 'label' => 'Cyan', 'swatch' => 'bg-cyan-600'],
        ['value' => 'teal', 'label' => 'Teal', 'swatch' => 'bg-teal-600'],
        ['value' => 'emerald', 'label' => 'Emerald', 'swatch' => 'bg-emerald-600'],
        ['value' => 'violet', 'label' => 'Violet', 'swatch' => 'bg-violet-600'],
        ['value' => 'pink', 'label' => 'Pink', 'swatch' => 'bg-pink-600'],
        ['value' => 'rose', 'label' => 'Rose', 'swatch' => 'bg-rose-600'],
        ['value' => 'red', 'label' => 'Red', 'swatch' => 'bg-red-600'],
        ['value' => 'orange', 'label' => 'Orange', 'swatch' => 'bg-orange-600'],
        ['value' => 'amber', 'label' => 'Amber', 'swatch' => 'bg-amber-600'],
    ];
@endphp
<div class="grid grid-cols-4 place-items-center {{ $mobile ? 'gap-3' : 'gap-2' }}" id="{{ $switcherId }}">
    @foreach($colorSchemes as $scheme)
        <button type="button"
            data-scheme="{{ $scheme['value'] }}"
            title="{{ $scheme['label'] }}"
            aria-pressed="{{ $currentScheme === $scheme['value'] ? 'true' : 'false' }}"
            class="{{ $btnClass }} {{ $mobile ? 'w-9 h-9' : 'w-8 h-8' }} rounded-full {{ $scheme['swatch'] }} transition {{ $mobile ? 'touch-target ' : '' }}ring-offset-2 ring-offset-gray-900 {{ $currentScheme === $scheme['value'] ? 'ring-2 ring-primary-500' : '' }}">
            <span class="sr-only">{{ $scheme['label'] }}</span>
        </button>
    @endforeach
</div>
