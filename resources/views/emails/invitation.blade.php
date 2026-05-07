<x-mail.layout
    :title="'You\'re invited to ' . $siteName"
    :preheader="$preheader"
    :site-name="$siteName">

    <p class="greeting">Hello!</p>

    <p>Great news! <strong>{{ $invitedByName }}</strong> has invited you to join <strong>{{ $siteName }}</strong>, our exclusive community.</p>

    <x-mail.button :url="$registerUrl">Accept invitation</x-mail.button>

    <p>If the button above doesn't work, copy and paste this link into your browser:</p>
    <span class="link-text">{{ $registerUrl }}</span>

    <x-mail.info-box variant="warning">
        <strong>Heads up:</strong> This invitation expires on
        <strong>{{ $expiresAt->format('F j, Y \a\t g:i A') }}</strong>.
        Please complete your registration before then.
    </x-mail.info-box>

    <x-mail.info-box>
        <strong>What's next?</strong> Click the button above to create your account and start exploring everything {{ $siteName }} has to offer.
    </x-mail.info-box>

    <p>If you have any questions, feel free to contact us once you've registered.</p>

    <div class="signature">
        <p>Welcome aboard,</p>
        <p><strong>The {{ $siteName }} Team</strong></p>
    </div>
</x-mail.layout>
