@extends('layouts.main')

@section('content')
<x-panel class="mx-auto max-w-4xl">
    <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome back</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">Jump back into browsing releases, searching the index, or managing your account.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-button-link href="{{ route('search') }}" icon="fas fa-search">Search</x-button-link>
            <x-button-link href="{{ route('All') }}" variant="secondary" icon="fas fa-list-ul">Browse</x-button-link>
        </div>
    </div>
</x-panel>
@endsection
