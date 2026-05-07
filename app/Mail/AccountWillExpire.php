<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountWillExpire extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public int $days;

    public string $username;

    public string $account;

    public string $site;

    public ?string $preheader;

    private string $siteEmail;

    public function __construct(User $user, int $days)
    {
        $this->days = $days;
        $this->username = (string) $user->username;
        $this->account = (string) ($user->role->name ?? 'User');
        $this->siteEmail = (string) config('mail.from.address');
        $this->site = (string) config('app.name');
        $this->preheader = "Your {$this->account} role expires in {$this->days} day(s).";
    }

    public function build(): static
    {
        return $this->from($this->siteEmail)
            ->brandedSubject('Your account is about to expire')
            ->markdown('emails.markdown.accountWillExpire', [
                'username' => $this->username,
                'account' => $this->account,
                'days' => $this->days,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
