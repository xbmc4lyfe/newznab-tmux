@props([
    'categoryName' => null,
    'parentCategory' => null,
    'subCategory' => null,
])

@php
    $categoryLabel = trim((string) ($categoryName ?: 'Other'));
    $resolvedParentCategory = trim((string) ($parentCategory ?? ''));
    $resolvedSubCategory = trim((string) ($subCategory ?? ''));

    if ($resolvedParentCategory === '' || $resolvedSubCategory === '') {
        $categoryParts = preg_split('/\s*>\s*/u', $categoryLabel, 2);

        if (is_array($categoryParts) && count($categoryParts) === 2) {
            $resolvedParentCategory = trim($categoryParts[0]);
            $resolvedSubCategory = trim($categoryParts[1]);
        }
    }

    $categoryUrl = $resolvedParentCategory !== '' && $resolvedSubCategory !== ''
        ? route('browse', [
            'parentCategory' => $resolvedParentCategory,
            'id' => $resolvedSubCategory,
        ])
        : null;

    $categoryClasses = 'inline-flex items-center rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-medium text-primary-800 transition dark:bg-primary-900/50 dark:text-primary-200';
@endphp

@if($categoryUrl !== null)
    <a href="{{ $categoryUrl }}"
       class="{{ $categoryClasses }} hover:bg-primary-200 hover:text-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:hover:bg-primary-800 dark:hover:text-white"
       title="Browse releases in {{ $categoryLabel }}"
       aria-label="Browse releases in {{ $categoryLabel }}">
        {{ $categoryLabel }}
    </a>
@else
    <span class="{{ $categoryClasses }}">{{ $categoryLabel }}</span>
@endif
