{{-- Dense action/filter bar for admin list pages --}}
<div {{ $attributes->merge(['class' => 'surface-panel-alt border-b px-4 py-3 sm:px-6']) }}>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {{ $slot }}
    </div>
</div>
