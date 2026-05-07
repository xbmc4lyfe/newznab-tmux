<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    public function test_welcome_email_renders_with_branded_subject_and_content(): void
    {
        config([
            'app.name' => 'NNTmux',
            'mail.from.address' => 'noreply@example.test',
            'mail.brand.subject_prefix' => '[NNTmux] ',
        ]);

        $user = new User;
        $user->username = 'tester';

        $mailable = new WelcomeEmail($user);
        $mailable->assertHasSubject('[NNTmux] Welcome to NNTmux');
        $mailable->assertSeeInHtml('Welcome to NNTmux');
        $mailable->assertSeeInHtml('Dear tester');
        $mailable->assertDontSeeInHtml('#667eea');
        $mailable->assertDontSeeInHtml('#764ba2');
        $mailable->assertSeeInText('Welcome to NNTmux');
        $mailable->assertSeeInText('Dear tester');
    }

    public function test_welcome_email_implements_should_queue_via_queueable_trait(): void
    {
        $this->assertContains(
            Queueable::class,
            class_uses_recursive(WelcomeEmail::class),
            'WelcomeEmail must use Queueable so SendWelcomeEmail jobs can dispatch it on a queue.'
        );
    }
}
