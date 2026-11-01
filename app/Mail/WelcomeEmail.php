<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $username;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(User $user)
    {
        $this->username = (string) $user->username;
        $this->site = (string) config('app.name');
        $this->siteEmail = (string) config('mail.from.address');
        $this->preheader = "Welcome to {$this->site} — your account is ready.";
    }

    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Welcome to '.$this->site)
            ->markdown('emails.markdown.welcome', [
                'username' => $this->username,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
