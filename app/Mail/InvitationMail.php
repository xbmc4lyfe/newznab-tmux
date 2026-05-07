<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable implements ShouldQueue
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public Invitation $invitation;

    public function __construct(Invitation $invitation)
    {
        $this->invitation = $invitation;
        $this->onQueue((string) config('mail.brand.queue', 'emails'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('mail.from.address'),
            subject: (string) config('mail.brand.subject_prefix', '').'You\'re invited to join '.config('app.name'),
        );
    }

    public function content(): Content
    {
        $siteName = (string) config('app.name');
        $invitedByName = (string) ($this->invitation->invitedBy->username ?? 'A member');

        return new Content(
            view: 'emails.invitation',
            text: 'emails.text.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'invitedByName' => $invitedByName,
                'registerUrl' => route('register', ['token' => $this->invitation->token]),
                'expiresAt' => $this->invitation->expires_at,
                'siteName' => $siteName,
                'preheader' => "{$invitedByName} has invited you to join {$siteName}.",
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
