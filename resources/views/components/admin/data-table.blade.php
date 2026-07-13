{{-- Admin data table with consistent thead styling --}}
@props([
    'dense' => true,
    'sticky' => false,
    'striped' => false,
])

<div class="overflow-x-auto overscroll-x-contain">
    <table {{ $attributes->merge(['class' => 'min-w-full table-auto divide-y divide-gray-200 dark:divide-gray-700']) }}>
        @if(isset($head))
            <thead @class([
                'bg-gray-50 dark:bg-gray-900',
                'sticky top-0 z-10' => $sticky,
            ])>
                <tr>
                    {{ $head }}
                </tr>
            </thead>
        @endif
        <tbody @class([
            'bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700',
            '[&_tr:nth-child(even)]:bg-gray-50/50 dark:[&_tr:nth-child(even)]:bg-gray-900/25' => $striped,
            '[&_td]:px-4 [&_td]:py-3 [&_td]:text-sm' => $dense,
            '[&_td]:px-6 [&_td]:py-4' => ! $dense,
        ])>
            {{ $slot }}
        </tbody>
    </table>
</div>
