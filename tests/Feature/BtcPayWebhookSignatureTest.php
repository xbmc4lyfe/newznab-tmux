<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BtcPayWebhookSignatureTest extends TestCase
{
    private const string BODY = '{"type":"InvoiceCreated"}';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'nntmux.btcpay_webhook_secret' => 'test-secret']);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error', 'info', 'warning', 'debug');
    }

    #[Test]
    public function a_matching_signature_is_accepted(): void
    {
        $signature = 'sha256='.hash_hmac('sha256', self::BODY, 'test-secret');

        $this->postWebhook(['HTTP_BTCPAY_SIG' => $signature])->assertOk();
    }

    #[Test]
    public function a_wrong_signature_is_rejected(): void
    {
        $this->postWebhook(['HTTP_BTCPAY_SIG' => 'sha256=wrong'])->assertNotFound();
    }

    #[Test]
    public function a_missing_signature_is_rejected(): void
    {
        $this->postWebhook([])->assertNotFound();
    }

    /**
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function postWebhook(array $server): TestResponse
    {
        return $this->call('POST', '/btcpay/webhook', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], self::BODY);
    }
}
