@component('mail::message')
# Account level changed

Dear {{ $username }},

We wanted to let you know that your account level has been updated.

@component('mail::panel')
**New account level:** {{ $account }}
@endcomponent

Your new level is now active and you can start enjoying all the associated features and benefits immediately.

If you have any questions about your new account level or need assistance, please don't hesitate to contact us.

Best regards,<br>
The {{ $site }} Team
@endcomponent
