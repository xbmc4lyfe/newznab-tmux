@component('mail::message')
# Password reset successful

Dear {{ $username }},

Your password has been successfully reset.

@component('mail::panel')
**Your new temporary password:**

`{{ $newPass }}`
@endcomponent

For your security, we strongly recommend logging in and changing this password to something memorable as soon as possible.

If you did not request this reset, please contact us immediately.

Best regards,<br>
The {{ $site }} Team
@endcomponent
