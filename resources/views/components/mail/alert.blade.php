@props([
    'type' => 'info',
])
@php
    $typeClass = in_array($type, ['info', 'success', 'warning', 'danger'], true) ? $type : 'info';
@endphp
<div class="alert-box alert-{{ $typeClass }}">{{ $slot }}</div>
