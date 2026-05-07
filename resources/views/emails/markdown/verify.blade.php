@component('mail::message')
# Verify your email address

@if ($username !== '')
Hi {{ $username }},
@else
Hi there,
@endif

Thanks for signing up for **{{ $site }}**! Please confirm your email address by clicking the button below.

@component('mail::button', ['url' => $url])
Verify email address
@endcomponent

If you did not create an account, no further action is required — you can safely ignore this email.

If you're having trouble clicking the verify button, copy and paste this URL into your browser:

<small>{{ $url }}</small>

Best regards,<br>
The {{ $site }} Team
@endcomponent
