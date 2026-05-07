@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@php($logoUrl = config('mail.brand.logo_url'))
@if ($logoUrl)
<img src="{{ $logoUrl }}" class="logo" alt="{{ trim(strip_tags($slot)) }}">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
