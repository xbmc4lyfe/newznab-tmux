<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Mail\AccountChange;
use App\Mail\AccountDeleted;
use App\Mail\AccountExpired;
use App\Mail\AccountWillExpire;
use App\Mail\ContactUs;
use App\Mail\ForgottenPassword;
use App\Mail\IncidentDetected;
use App\Mail\InvitationMail;
use App\Mail\NewAccountCreatedEmail;
use App\Mail\WelcomeEmail;
use App\Models\Invitation;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Notifications\VerifyEmailBranded;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Generates static HTML previews for every transactional and notification email
 * the application can send. Useful for spot-checking the themed templates
 * across mail clients without needing to wire up Mailpit or trigger real
 * application events. Output lands in `storage/app/mail-previews/*.html`.
 */
class MailPreview extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_mail_preview_resolve_output_dir(
    uint8_t has_out,
    const char *out_ptr,
    size_t out_len,
    const char *default_ptr,
    size_t default_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mail_preview_format_byte_summary(
    const char *name_ptr,
    size_t name_len,
    int64_t byte_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mail_preview_fixture_attributes_nul(
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'mail:preview
                            {--out= : Override the output directory (defaults to storage/app/mail-previews)}';

    protected $description = 'Render every mailable to static HTML files for visual QA across email clients.';

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public static function resolveOutputDirectory(?string $outOption, string $defaultDirectory): string
    {
        if (self::$useRustFfi) {
            return self::resolveOutputDirectoryNative($outOption, $defaultDirectory);
        }

        return self::resolveOutputDirectoryPhp($outOption, $defaultDirectory);
    }

    public static function resolveOutputDirectoryPhp(?string $outOption, string $defaultDirectory): string
    {
        return (string) ($outOption ?: $defaultDirectory);
    }

    public static function resolveOutputDirectoryNative(?string $outOption, string $defaultDirectory): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveOutputDirectoryPhp($outOption, $defaultDirectory);
        }

        try {
            $outStr = $outOption ?? '';
            $cap = max(128, strlen($outStr) + strlen($defaultDirectory) + 32);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mail_preview_resolve_output_dir(
                $outOption !== null ? 1 : 0,
                $outStr,
                strlen($outStr),
                $defaultDirectory,
                strlen($defaultDirectory),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written < 0) {
                return self::resolveOutputDirectoryPhp($outOption, $defaultDirectory);
            }
            if ($written === 0) {
                return '';
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::resolveOutputDirectoryPhp($outOption, $defaultDirectory);
        }
    }

    public static function formatPreviewByteSummary(string $name, int $byteCount): string
    {
        if (self::$useRustFfi) {
            return self::formatPreviewByteSummaryNative($name, $byteCount);
        }

        return self::formatPreviewByteSummaryPhp($name, $byteCount);
    }

    public static function formatPreviewByteSummaryPhp(string $name, int $byteCount): string
    {
        return "Wrote {$name}.html (".number_format($byteCount).' bytes)';
    }

    public static function formatPreviewByteSummaryNative(string $name, int $byteCount): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatPreviewByteSummaryPhp($name, $byteCount);
        }

        try {
            $cap = max(96, strlen($name) + 64);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mail_preview_format_byte_summary(
                $name,
                strlen($name),
                $byteCount,
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatPreviewByteSummaryPhp($name, $byteCount);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatPreviewByteSummaryPhp($name, $byteCount);
        }
    }

    /**
     * @return array{
     *     user: array{id: int, username: string, email: string, role_name: string},
     *     verify_notifiable: array{id: int, username: string, email: string},
     *     invitation: array{inviter_id: int, inviter_username: string, token: string, email: string, expires_in_days: int},
     *     incident: array{title: string, description: string, service_names: array<int, string>, started_minutes_ago: int}
     * }
     */
    public static function buildPreviewFixtureAttributes(): array
    {
        if (self::$useRustFfi) {
            return self::buildPreviewFixtureAttributesNative();
        }

        return self::buildPreviewFixtureAttributesPhp();
    }

    /**
     * @return array{
     *     user: array{id: int, username: string, email: string, role_name: string},
     *     verify_notifiable: array{id: int, username: string, email: string},
     *     invitation: array{inviter_id: int, inviter_username: string, token: string, email: string, expires_in_days: int},
     *     incident: array{title: string, description: string, service_names: array<int, string>, started_minutes_ago: int}
     * }
     */
    public static function buildPreviewFixtureAttributesPhp(): array
    {
        return [
            'user' => [
                'id' => 99,
                'username' => 'tester',
                'email' => 'tester@example.test',
                'role_name' => 'Silver',
            ],
            'verify_notifiable' => [
                'id' => 7,
                'username' => 'tester',
                'email' => 'tester@example.test',
            ],
            'invitation' => [
                'inviter_id' => 1,
                'inviter_username' => 'mod_alice',
                'token' => 'preview-token-abc123',
                'email' => 'newuser@example.test',
                'expires_in_days' => 7,
            ],
            'incident' => [
                'title' => 'API latency spike',
                'description' => "p99 latency exceeded thresholds.\nFollow status page for updates.",
                'service_names' => ['NZB Search', 'API'],
                'started_minutes_ago' => 15,
            ],
        ];
    }

    /**
     * @return array{
     *     user: array{id: int, username: string, email: string, role_name: string},
     *     verify_notifiable: array{id: int, username: string, email: string},
     *     invitation: array{inviter_id: int, inviter_username: string, token: string, email: string, expires_in_days: int},
     *     incident: array{title: string, description: string, service_names: array<int, string>, started_minutes_ago: int}
     * }
     */
    public static function buildPreviewFixtureAttributesNative(): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildPreviewFixtureAttributesPhp();
        }

        try {
            $cap = 256;
            $outScalars = $ffi->new('int64_t[5]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mail_preview_fixture_attributes_nul($outScalars, $outBuf, $cap);
            $userId = $outScalars[0];
            $verifyId = $outScalars[1];
            $inviterId = $outScalars[2];
            $expiresInDays = $outScalars[3];
            $startedMinutesAgo = $outScalars[4];
            if (
                ! is_int($written)
                || $written <= 0
                || ! is_int($userId)
                || ! is_int($verifyId)
                || ! is_int($inviterId)
                || ! is_int($expiresInDays)
                || ! is_int($startedMinutesAgo)
            ) {
                return self::buildPreviewFixtureAttributesPhp();
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 12) {
                return self::buildPreviewFixtureAttributesPhp();
            }

            return [
                'user' => [
                    'id' => $userId,
                    'username' => $parts[0],
                    'email' => $parts[1],
                    'role_name' => $parts[2],
                ],
                'verify_notifiable' => [
                    'id' => $verifyId,
                    'username' => $parts[3],
                    'email' => $parts[4],
                ],
                'invitation' => [
                    'inviter_id' => $inviterId,
                    'inviter_username' => $parts[5],
                    'token' => $parts[6],
                    'email' => $parts[7],
                    'expires_in_days' => $expiresInDays,
                ],
                'incident' => [
                    'title' => $parts[8],
                    'description' => $parts[9],
                    'service_names' => [$parts[10], $parts[11]],
                    'started_minutes_ago' => $startedMinutesAgo,
                ],
            ];
        } catch (Throwable) {
            return self::buildPreviewFixtureAttributesPhp();
        }
    }

    public function handle(): int
    {
        $rawOut = $this->option('out');
        $directory = self::resolveOutputDirectory(
            is_string($rawOut) ? $rawOut : null,
            storage_path('app/mail-previews')
        );
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create output directory: {$directory}");

            return self::FAILURE;
        }

        $user = $this->fakeUser();
        $invitation = $this->fakeInvitation();
        [$activeIncident, $resolvedIncident] = $this->fakeIncidents();

        /** @var array<string, callable(): Mailable> $mailables */
        $mailables = [
            'welcome' => fn (): Mailable => new WelcomeEmail($user),
            'forgotten_password' => fn (): Mailable => new ForgottenPassword('https://example.test/reset/abc123'),
            'new_account_created' => fn (): Mailable => new NewAccountCreatedEmail($user),
            'account_change' => fn (): Mailable => new AccountChange($user),
            'account_will_expire' => fn (): Mailable => new AccountWillExpire($user, 5),
            'account_expired' => fn (): Mailable => new AccountExpired($user),
            'account_deleted' => fn (): Mailable => new AccountDeleted($user),
            'contact_us' => fn (): Mailable => new ContactUs(
                'admin@example.test',
                'visitor@example.test',
                "Hello!\nQuestion inside.",
            ),
            'invitation' => fn (): Mailable => new InvitationMail($invitation),
            'incident_detected' => fn (): Mailable => new IncidentDetected($activeIncident, false),
            'incident_resolved' => fn (): Mailable => new IncidentDetected($resolvedIncident, true),
        ];

        foreach ($mailables as $name => $factory) {
            $html = $factory()->render();
            file_put_contents("{$directory}/{$name}.html", $html);
            $this->components->info(self::formatPreviewByteSummary($name, strlen($html)));
        }

        $this->writeVerifyPreview($directory);

        $this->newLine();
        $this->components->info("All previews written to: {$directory}");

        return self::SUCCESS;
    }

    private function writeVerifyPreview(string $directory): void
    {
        $verifyAttrs = self::buildPreviewFixtureAttributes()['verify_notifiable'];

        $notifiable = new class($verifyAttrs['id'], $verifyAttrs['email'], $verifyAttrs['username'])
        {
            public function __construct(
                public int $id = 7,
                public string $email = 'tester@example.test',
                public string $username = 'tester',
            ) {}

            public function getKey(): int
            {
                return $this->id;
            }

            public function getEmailForVerification(): string
            {
                return $this->email;
            }
        };

        $message = (new VerifyEmailBranded)->toMail($notifiable);
        $markdown = app(Markdown::class);
        $html = (string) $markdown->render($message->markdown, $message->data());
        file_put_contents("{$directory}/verify_email.html", $html);
        $this->components->info(self::formatPreviewByteSummary('verify_email', strlen($html)));
    }

    private function fakeUser(): User
    {
        $attrs = self::buildPreviewFixtureAttributes()['user'];
        $roleName = $attrs['role_name'];

        $user = new User;
        $user->id = $attrs['id'];
        $user->username = $attrs['username'];
        $user->email = $attrs['email'];
        $user->setRelation('role', new class($roleName)
        {
            public function __construct(public string $name = 'Silver') {}
        });

        return $user;
    }

    private function fakeInvitation(): Invitation
    {
        $attrs = self::buildPreviewFixtureAttributes()['invitation'];

        $inviter = new User;
        $inviter->id = $attrs['inviter_id'];
        $inviter->username = $attrs['inviter_username'];

        $invitation = new Invitation;
        $invitation->token = $attrs['token'];
        $invitation->email = $attrs['email'];
        $invitation->expires_at = Carbon::now()->addDays($attrs['expires_in_days']);
        $invitation->setRelation('invitedBy', $inviter);

        return $invitation;
    }

    /**
     * @return array{0: ServiceIncident, 1: ServiceIncident}
     */
    private function fakeIncidents(): array
    {
        $attrs = self::buildPreviewFixtureAttributes()['incident'];

        $services = collect(array_map(
            static fn (string $name): object => (object) ['name' => $name],
            $attrs['service_names']
        ));

        $active = new ServiceIncident;
        $active->title = $attrs['title'];
        $active->description = $attrs['description'];
        $active->status = IncidentStatusEnum::Identified;
        $active->impact = IncidentImpactEnum::Critical;
        $active->started_at = Carbon::now()->subMinutes($attrs['started_minutes_ago']);
        $active->setRelation('services', $services);

        $resolved = clone $active;
        $resolved->status = IncidentStatusEnum::Resolved;
        $resolved->resolved_at = Carbon::now();

        return [$active, $resolved];
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
