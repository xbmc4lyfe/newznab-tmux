@component('mail::message')
# Account expired

Dear {{ $username }},

We regret to inform you that your account subscription has expired.

@component('mail::panel')
**Account downgraded:** Your account has been automatically downgraded to **{{ $account }}**.
@endcomponent

Don't worry — you can still access the site with your downgraded account level. If you'd like to regain access to all your previous features and benefits, please consider renewing your subscription.

If you have any questions or need help with the renewal process, please don't hesitate to contact us.

Best regards,<br>
The {{ $site }} Team
@endcomponent
