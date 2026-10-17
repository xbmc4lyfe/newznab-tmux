<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseFingerprintEmptyPar2SetTest extends TestCase
{
    private const NZB = '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file subject="movie.rar (1/1)"><groups><group>alt.binaries.test</group></groups><segments>'
        .'<segment number="1" bytes="10">one@test</segment>'
        .'</segments></file></nzb>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux_nntp.server' => 'news.primary.invalid', 'nntmux_nntp.alternate_server' => '']);
    }

    #[Test]
    public function a_release_without_par2_sets_keeps_empty_par2_metadata_without_a_null_offset_deprecation(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andThrow(new \RuntimeException('Article unavailable.'));
        $nzb = $this->mock(NzbService::class);
        $nzb->shouldReceive('readNzbContents')->once()->with('empty-par2-fixture')->andReturn(self::NZB);
        $yenc = new YencService;
        $release = new Release;
        $release->guid = 'empty-par2-fixture';
        $collector = new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc);

        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);
        try {
            $result = $collector->collect($release);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result['data']['par2']['present']);
        $this->assertSame([], $result['data']['par2']['set_ids']);
        $this->assertSame([], $result['data']['par2']['files']);
        $this->assertSame([], $deprecations);
    }
}
