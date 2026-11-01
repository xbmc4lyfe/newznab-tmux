<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgottenPassword extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $resetLink;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(string $resetLink)
    {
        $this->resetLink = $resetLink;
        $this->siteEmail = (string) config('mail.from.address');
        $siteName = config('app.name');
        $this->site = is_array($siteName) ? (string) ($siteName[0] ?? '') : (string) ($siteName ?? '');
        $this->preheader = 'Reset your password using the secure link inside.';
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Reset your password')
            ->markdown('emails.markdown.forgottenPassword', [
                'resetLink' => $this->resetLink,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
