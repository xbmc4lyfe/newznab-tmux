@extends('layouts.main')

@section('content')
<x-page-header title="Welcome back" eyebrow="Your index workspace" description="Jump back into browsing releases, searching the index, or managing your account." icon="fas fa-house">
    <x-slot:actions>
        <a href="{{ route('search') }}" class="workspace-hero__action"><i class="fas fa-search" aria-hidden="true"></i>Search</a>
        <a href="{{ route('All') }}" class="workspace-hero__action"><i class="fas fa-list-ul" aria-hidden="true"></i>Browse</a>
    </x-slot:actions>
</x-page-header>
@endsection
