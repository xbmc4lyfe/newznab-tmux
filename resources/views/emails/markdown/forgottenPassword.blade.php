@component('mail::message')
# Password reset request

Hello,

We received a request to reset the password associated with this email address.

@component('mail::button', ['url' => $resetLink])
Reset password
@endcomponent

If the button above doesn't work, copy and paste this link into your browser:

<small>{{ $resetLink }}</small>

@component('mail::panel')
**Security notice:** If you didn't request this password reset, you can safely ignore this email — your password will remain unchanged.
@endcomponent

Best regards,<br>
The {{ $site }} Team
@endcomponent
