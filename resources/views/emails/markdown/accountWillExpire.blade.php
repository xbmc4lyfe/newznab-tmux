@component('mail::message')
# Account expiration notice

Dear {{ $username }},

@component('mail::panel')
**Heads up:** Your **{{ $account }}** role expires in less than **{{ $days }} day(s)**.
@endcomponent

To continue enjoying uninterrupted access to all your current features and benefits, please take action before your subscription expires.

If you have any questions about renewing your account, please don't hesitate to reach out.

Best regards,<br>
The {{ $site }} Team
@endcomponent
