<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewAccountCreatedEmail extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $username;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(mixed $user)
    {
        $this->username = (string) $user->username;
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = "New user registered on {$this->site}.";
    }

    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('New account registered')
            ->markdown('emails.markdown.newAccountCreated', [
                'username' => $this->username,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
