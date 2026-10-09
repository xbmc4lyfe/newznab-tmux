<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NntpAlternateFallbackConfigTest extends TestCase
{
    private const array KEYS = ['NNTP_ALTERNATE_FALLBACK', 'USE_ALTERNATE_NNTP_SERVER'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{?string, ?string, bool}>
     */
    public static function environments(): array
    {
        return [
            'unset follows the alternate-server switch (off)' => [null, null, false],
            'blank follows the alternate-server switch (on)' => ['', 'true', true],
            'explicit fallback without moving connections' => ['true', 'false', true],
            'explicit off overrides the alternate-server switch' => ['false', 'true', false],
        ];
    }

    #[Test]
    #[DataProvider('environments')]
    public function the_fallback_setting_resolves_from_the_environment(?string $fallback, ?string $useAlternate, bool $expected): void
    {
        foreach (array_combine(self::KEYS, [$fallback, $useAlternate]) as $key => $value) {
            if ($value !== null) {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        $config = require config_path('nntmux_nntp.php');

        $this->assertSame($expected, $config['alternate_article_fallback']);
    }

    #[Test]
    public function article_downloads_use_the_fallback_setting_not_the_alternate_server_switch(): void
    {
        config(['nntmux_nntp.use_alternate_nntp_server' => false, 'nntmux_nntp.alternate_article_fallback' => true]);

        $this->assertTrue((new ProcessingConfiguration)->alternateNNTP);

        config(['nntmux_nntp.use_alternate_nntp_server' => true, 'nntmux_nntp.alternate_article_fallback' => false]);

        $this->assertFalse((new ProcessingConfiguration)->alternateNNTP);
    }
}
