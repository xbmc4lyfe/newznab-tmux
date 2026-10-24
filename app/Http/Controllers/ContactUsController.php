<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ContactContactURequest;
use App\Jobs\SendContactUsEmail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContactUsController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param array<array-key, mixed> $fields
     * @return array{mailBody: string, send: bool, msg: string}
     */
    public static function contactSubmission(array $fields, string $email, string $appName, string $eol = PHP_EOL): array
    {
        return self::$useRustFfi ? self::contactSubmissionNative($fields, $email, $appName, $eol) : self::contactSubmissionPhp($fields, $email, $appName, $eol);
    }

    /** @param array<array-key, mixed> $fields
     * @return array{mailBody: string, send: bool, msg: string}
     */
    public static function contactSubmissionPhp(array $fields, string $email, string $appName, string $eol = PHP_EOL): array
    {
        $mailBody = 'Values submitted from contact form: ';
        foreach ($fields as $key => $value) {
            if (! in_array($key, ['submit', '_token', 'g-recaptcha-response', 'cf-turnstile-response'], true)) {
                $mailBody .= "$key : $value".$eol;
            }
        }

        return ['mailBody' => $mailBody, 'send' => self::canSendContactEmailPhp($email), 'msg' => self::thankYouHtmlPhp($appName)];
    }

    /** @param array<array-key, mixed> $fields
     * @return array{mailBody: string, send: bool, msg: string}
     */
    public static function contactSubmissionNative(array $fields, string $email, string $appName, string $eol = PHP_EOL): array
    {
        $rows = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, ['submit', '_token', 'g-recaptcha-response', 'cf-turnstile-response'], true)) {
                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                return self::contactSubmissionPhp($fields, $email, $appName, $eol);
            }
            $rows[] = [(string) $key, (string) $value];
        }
        $result = BrowseController::transformNative(3, [$rows, $email, $appName, $eol]);
        if (is_array($result) && array_keys($result) === ['mailBody', 'send', 'msg'] && is_string($result['mailBody']) && is_bool($result['send']) && is_string($result['msg'])) {
            return $result;
        }

        return self::contactSubmissionPhp($fields, $email, $appName, $eol);
    }

    public static function canSendContactEmail(string $email): bool
    {
        return self::$useRustFfi ? self::canSendContactEmailNative($email) : self::canSendContactEmailPhp($email);
    }

    public static function canSendContactEmailPhp(string $email): bool
    {
        return ! preg_match("/\n/i", $email);
    }

    public static function canSendContactEmailNative(string $email): bool
    {
        $result = BrowseController::transformNative(4, [$email]);

        return is_bool($result) ? $result : self::canSendContactEmailPhp($email);
    }

    public static function thankYouHtml(string $appName): string
    {
        return self::$useRustFfi ? self::thankYouHtmlNative($appName) : self::thankYouHtmlPhp($appName);
    }

    public static function thankYouHtmlPhp(string $appName): string
    {
        return "<h2 style='text-align:center;'>Thank you for getting in touch with ".$appName.'.</h2>';
    }

    public static function thankYouHtmlNative(string $appName): string
    {
        $result = BrowseController::transformNative(5, [$appName]);

        return is_string($result) ? $result : self::thankYouHtmlPhp($appName);
    }

    /**
     * @throws ValidationException
     */
    public function contact(ContactContactURequest $request): mixed
    {

        $msg = '';

        if ($request->has('useremail')) {
            $email = $request->input('useremail');
            $mailTo = config('mail.from.address');
            $submission = self::contactSubmission($request->all(), $email, (string) config('app.name'));

            if ($submission['send']) {
                SendContactUsEmail::dispatch($email, $mailTo, $submission['mailBody'])->onQueue('contactemail');
            }
            $msg = $submission['msg'];
        }

        return $this->showContactForm($msg);
    }

    /**
     * @return View
     *
     * @throws \Exception
     */
    public function showContactForm(string $msg = '')
    {
        return view('contact.index', [
            'title' => 'Contact '.config('app.name'),
            'meta_title' => 'Contact '.config('app.name'),
            'meta_keywords' => 'contact us,contact,get in touch,email',
            'meta_description' => 'Contact us at '.config('app.name').' and submit your feedback',
            'msg' => $msg,
        ]);
    }
}
