<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountChange extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $username;

    public string $account;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(User $user)
    {
        $this->username = (string) $user->username;
        $this->account = (string) ($user->role->name ?? 'User');
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = "Your {$this->site} account level was updated to {$this->account}.";
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Account level changed')
            ->markdown('emails.markdown.accountChange', [
                'username' => $this->username,
                'account' => $this->account,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
