<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseFingerprintProviderBreakerTest extends TestCase
{
    private const NZB = '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file subject="movie.rar (1/3)"><groups><group>alt.binaries.test</group></groups><segments>'
        .'<segment number="1" bytes="10">one@test</segment><segment number="2" bytes="10">two@test</segment><segment number="3" bytes="10">three@test</segment>'
        .'</segments></file></nzb>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux_nntp.server' => 'news.primary.invalid', 'nntmux_nntp.alternate_server' => 'news.alternate.invalid']);
    }

    #[Test]
    public function a_rejected_alternate_profile_skips_every_alternate_sample(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));

        $errors = $this->collect()['data']['errors'];

        $alternate = array_values(array_filter($errors, fn (array $error): bool => $error['server'] === 'news.alternate.invalid'));
        $this->assertCount(3, $alternate);
        $this->assertSame(['NntpProviderUnavailableException'], array_values(array_unique(array_column($alternate, 'error_type'))));
    }

    #[Test]
    public function an_alternate_lost_mid_job_is_not_queried_again(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->once()
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));

        $errors = $this->collect()['data']['errors'];

        $this->assertCount(3, array_filter($errors, fn (array $error): bool => $error['server'] === 'news.alternate.invalid'
            && $error['error_type'] === 'NntpProviderUnavailableException'));
    }

    #[Test]
    public function an_unavailable_primary_is_still_retried_for_every_sample(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(false)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(3)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->once()
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));

        $this->collect();
    }

    /** @return array{status: string, data: array<string, mixed>} */
    private function collect(): array
    {
        $nzb = $this->mock(NzbService::class);
        $nzb->shouldReceive('readNzbContents')->once()->with('breaker-fixture')->andReturn(self::NZB);
        $yenc = new YencService;
        $release = new Release;
        $release->guid = 'breaker-fixture';

        return (new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc))->collect($release);
    }
}
