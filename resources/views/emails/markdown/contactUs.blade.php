@component('mail::message')
# Contact form submission

A new contact form has been submitted on **{{ $site }}**.

@component('mail::panel')
**From:** {{ $mailFrom }}

**Message:**

{!! nl2br(e($mailBody)) !!}
@endcomponent

Reply directly to this email to respond — the reply-to header is set to the sender.

— The {{ $site }} System
@endcomponent
