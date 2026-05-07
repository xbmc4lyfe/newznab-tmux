@component('mail::message')
# User account deleted

This is a notification that a user has deleted their account from **{{ $site }}**.

@component('mail::panel')
**Deleted account:** {{ $username }}
@endcomponent

No further action is required. This email is for informational purposes only.

— The {{ $site }} System
@endcomponent
