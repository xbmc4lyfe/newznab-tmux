@component('mail::message')
# Welcome to {{ $site }}

Dear {{ $username }},

We're thrilled to have you join our community.

@component('mail::panel')
**Next step:** A separate verification email is on its way. Please check your inbox and click the verification link to activate your account.
@endcomponent

If you have any questions or need assistance, just reach out — we're happy to help.

Best regards,<br>
The {{ $site }} Team
@endcomponent
