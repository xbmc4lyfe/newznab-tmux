<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $username;

    public string $newPass;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(User $user, string $newPass)
    {
        $this->username = (string) $user->username;
        $this->newPass = $newPass;
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = 'Your password has been reset — temporary credentials inside.';
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Your password has been reset')
            ->markdown('emails.markdown.passwordReset', [
                'username' => $this->username,
                'newPass' => $this->newPass,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
