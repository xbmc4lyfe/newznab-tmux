<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\NativeSevenZipListing;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeSevenZipListingTest extends TestCase
{
    private Container $originalContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => [
            'native_library' => getenv('YENC_NATIVE_LIBRARY') ?: dirname(__DIR__, 3).'/storage/app/rapidyenc/librapidyenc.so',
        ]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public function test_an_unavailable_library_fails_closed(): void
    {
        config(['yenc.native_library' => '/nonexistent/nntmux-seven-zip.so']);

        $this->assertNull((new NativeSevenZipListing)->listing('head', null, 0));
    }

    #[DataProvider('oversizedSamples')]
    public function test_oversized_samples_are_rejected_before_native_allocation(int $headLength, int $tailLength): void
    {
        $this->assertNull((new NativeSevenZipListing)->listing(
            str_repeat('h', $headLength),
            str_repeat('t', $tailLength),
            0,
        ));
    }

    public static function oversizedSamples(): array
    {
        return [
            'head exceeds total sample budget' => [16_777_217, 0],
            'tail exceeds total sample budget' => [0, 16_777_217],
            'pieces individually fit but total exceeds budget' => [8_388_608, 8_388_609],
        ];
    }

    #[DataProvider('realArchives')]
    public function test_native_listing_reads_real_compressed_headers(
        string $fixture,
        int $count,
        string $encryption,
        bool $complete,
    ): void {
        $root = dirname(__DIR__, 2).'/Fixtures/SevenZip/';
        $manifest = json_decode(file_get_contents($root.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $input = $manifest[$fixture];
        $result = (new NativeSevenZipListing)->listing(
            file_get_contents($root.$input['head']),
            $input['tail'] === null ? null : file_get_contents($root.$input['tail']),
            $input['archive_size'],
        );
        if ($result === null) {
            if (getenv('YENC_NATIVE_LIBRARY')) {
                $this->fail('The explicitly configured native library did not provide the 7z listing ABI.');
            }
            $this->markTestSkipped('The Linux native 7z listing library is unavailable.');
        }
        $this->assertCount($count, $result['files']);
        $this->assertSame($encryption, $result['encryption']);
        $this->assertSame($complete, $result['listing_complete']);
        if (in_array($fixture, ['basic', 'lzma2-header'], true)) {
            $files = array_column($result['files'], null, 'name');
            $this->assertSame(300000, $files['Real.Movie.2026.mkv']['size']);
            $this->assertSame(946684800, $files['Real.Movie.2026.mkv']['date']);
            $this->assertSame('69921800', strtolower((string) $files['Real.Movie.2026.mkv']['crc32']));
            $this->assertSame(14, $files['naïve notes.txt']['size']);
            $this->assertSame(12, $files['real.nfo']['size']);
        }
        if ($fixture === 'capped-encrypted') {
            $this->assertNotContains('z-secret.mkv', array_column($result['files'], 'name'));
        }
    }

    public static function realArchives(): array
    {
        return [
            'sparse compressed header' => ['basic', 3, 'unencrypted', true],
            'explicit LZMA2 header' => ['lzma2-header', 3, 'unencrypted', true],
            'compact LZMA header' => ['compact', 2, 'unencrypted', true],
            'many files' => ['many', 100, 'unencrypted', true],
            'encrypted payload' => ['encrypted-files', 3, 'encrypted', true],
            'encrypted header' => ['encrypted-header', 0, 'encrypted', false],
            'encryption after the file cap' => ['capped-encrypted', 1000, 'encrypted', false],
            'unencrypted prefix at the file cap' => ['capped-plain', 1000, 'unknown', false],
        ];
    }
}
