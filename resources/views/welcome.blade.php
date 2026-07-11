@extends('layouts.guest')

@section('content')
<div class="auth-page min-h-dvh px-4 py-10 sm:px-6 lg:px-8">
    <div class="mx-auto flex min-h-[calc(100dvh-5rem)] max-w-3xl items-center justify-center">
        <div class="auth-card w-full rounded-xl p-8 text-center shadow-xl">
            <a href="{{ url('/') }}" class="inline-flex items-center justify-center">
                <img src="{{ asset('assets/images/logo.svg') }}" alt="{{ config('app.name') }} Logo" class="h-16 w-16">
            </a>

            <h1 class="mt-6 text-3xl font-bold text-gray-900 dark:text-gray-100">{{ config('app.name') }}</h1>
            <p class="mx-auto mt-3 max-w-xl text-gray-600 dark:text-gray-400">Your Usenet index is ready. Sign in to search releases, manage saved media, and use your download basket.</p>

            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                @auth
                    <x-button-link href="{{ route('search') }}" icon="fas fa-search">Search Releases</x-button-link>
                    <x-button-link href="{{ route('All') }}" variant="secondary" icon="fas fa-list-ul">Browse All</x-button-link>
                @else
                    <x-button-link href="{{ route('login') }}" icon="fas fa-sign-in-alt">Sign In</x-button-link>
                    @if(Route::has('register'))
                        <x-button-link href="{{ route('register') }}" variant="secondary" icon="fas fa-user-plus">Create Account</x-button-link>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</div>
@endsection
