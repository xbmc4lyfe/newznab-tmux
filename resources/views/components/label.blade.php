@blaze(fold: true)
<label {{ $attributes->merge(['class' => 'mb-1.5 block w-full text-sm font-semibold text-gray-700 dark:text-gray-300']) }}>
    {{ $slot }}
</label>
