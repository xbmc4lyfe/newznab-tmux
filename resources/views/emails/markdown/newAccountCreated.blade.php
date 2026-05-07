@component('mail::message')
# New user registration

A new user has registered on **{{ $site }}**.

@component('mail::panel')
**New user:** {{ $username }}
@endcomponent

This is an automated notification — no action is required unless you need to review the new registration.

— The {{ $site }} System
@endcomponent
