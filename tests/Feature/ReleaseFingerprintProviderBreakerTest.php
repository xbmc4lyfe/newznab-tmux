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
use Illuminate\Support\Facades\Cache;
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
        config([
            'cache.default' => 'array',
            'nntmux_nntp.server' => 'news.primary.invalid',
            'nntmux_nntp.alternate_server' => 'news.alternate.invalid',
            'nntmux_nntp.fingerprint_provider_breaker_seconds' => 900,
        ]);
    }

    #[Test]
    public function a_later_job_skips_an_alternate_that_an_earlier_job_could_not_reach(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $data = $this->collect()['data'];

        $alternateProfile = $this->alternateProfile($data);
        $this->assertSame('NntpProviderUnavailableException', $alternateProfile['profile_error_type']);
        $this->assertSame('recent_provider_failure', $alternateProfile['profile_skipped_reason']);
        $this->assertCount(3, array_filter($data['errors'], fn (array $error): bool => $error['server'] === 'news.alternate.invalid'
            && $error['error_type'] === 'NntpProviderUnavailableException'));
    }

    #[Test]
    public function an_alternate_lost_mid_job_is_skipped_by_the_next_job(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->once()
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));

        $this->collect();

        $this->assertSame('recent_provider_failure', $this->alternateProfile($this->collect()['data'])['profile_skipped_reason']);
    }

    #[Test]
    public function the_alternate_is_probed_again_once_the_breaker_expires(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(9)->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->travel(901)->seconds();

        $this->assertArrayHasKey('profile', $this->alternateProfile($this->collect()['data']));
    }

    #[Test]
    public function only_one_job_reprobes_an_alternate_after_its_breaker_expires(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->travel(901)->seconds();
        // Another worker has already claimed the re-probe.
        $this->assertTrue(Cache::add($this->breakerKey('probe'), true, 240));

        $this->assertSame('provider_probe_in_progress', $this->alternateProfile($this->collect()['data'])['profile_skipped_reason']);
    }

    #[Test]
    public function a_successful_reprobe_lets_every_job_probe_again(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->times(3)->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(15)->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->travel(901)->seconds();
        $this->assertArrayHasKey('profile', $this->alternateProfile($this->collect()['data']));
        // A leftover probe claim no longer matters once the provider is known to be reachable.
        Cache::put($this->breakerKey('probe'), true, 240);

        $this->assertArrayHasKey('profile', $this->alternateProfile($this->collect()['data']));
    }

    #[Test]
    public function a_failed_reprobe_reopens_the_breaker(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->times(3)->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(9)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->travel(901)->seconds();
        $this->collect();

        $this->assertSame('recent_provider_failure', $this->alternateProfile($this->collect()['data'])['profile_skipped_reason']);
    }

    #[Test]
    public function a_zero_breaker_window_probes_the_alternate_in_every_job(): void
    {
        config(['nntmux_nntp.fingerprint_provider_breaker_seconds' => 0]);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(6)
            ->withArgs(fn (string $id, int $body, int $header, bool $alternate): bool => $alternate === false)
            ->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->collect();
    }

    #[Test]
    public function disabling_the_breaker_ignores_a_failure_cached_before(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->once()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(9)->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        config(['nntmux_nntp.fingerprint_provider_breaker_seconds' => 0]);

        $this->assertArrayHasKey('profile', $this->alternateProfile($this->collect()['data']));
    }

    #[Test]
    public function an_unreachable_primary_does_not_open_the_breaker(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(false)
            ->andThrow(new NntpProviderUnavailableException('Unable to connect to the requested NNTP provider.'));
        $nntp->shouldReceive('getFingerprintServerProfile')->twice()->with(true)->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->times(12)->andThrow(new \RuntimeException('Article unavailable.'));

        $this->collect();
        $this->collect();
    }

    private function breakerKey(string $state): string
    {
        return 'release-fingerprint:provider-unavailable:'.$state.':'.sha1('news.alternate.invalid||plain');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function alternateProfile(array $data): array
    {
        $profiles = array_values(array_filter($data['collection_policy']['server_profiles'], fn (array $row): bool => $row['alternate'] === true));
        $this->assertCount(1, $profiles);

        return $profiles[0];
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
