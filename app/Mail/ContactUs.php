<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactUs extends Mailable
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public string $mailFrom;

    public string $mailBody;

    public string $mailTo;

    public string $site;

    public ?string $preheader;

    public function __construct(mixed $mailTo, mixed $mailFrom, mixed $mailBody)
    {
        $this->mailTo = (string) $mailTo;
        $this->mailFrom = (string) $mailFrom;
        $this->mailBody = (string) $mailBody;
        $this->site = (string) config('app.name');
        $this->preheader = "New contact form submission from {$this->mailFrom}.";
    }

    /**
     * @throws \Exception
     */
    public function build(): static
    {
        return $this->from($this->mailTo)
            ->replyTo($this->mailFrom)
            ->brandedSubject('Contact form submitted')
            ->markdown('emails.markdown.contactUs', [
                'mailBody' => $this->mailBody,
                'mailFrom' => $this->mailFrom,
                'site' => $this->site,
                'preheader' => $this->preheader,
            ]);
    }
}
