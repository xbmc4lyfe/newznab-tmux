<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\PayloadIdentityResolver;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VerifiedPayloadMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        Schema::create('predb', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('filename')->default('');
        });
        DB::table('predb')->insert(['id' => 7, 'title' => 'Known.Movie.2026.1080p.WEB-DL-GRP']);
    }

    public function test_masked_subject_recovers_verified_filename_and_corroborated_predb_identity(): void
    {
        $cleaning = $this->createPartialMock(ReleaseCleaningService::class, ['releaseCleaner']);
        $cleaning->expects($this->once())->method('releaseCleaner')->willReturn(['cleansubject' => 'masked', 'properlynamed' => false]);
        $category = $this->createMock(CategorizationService::class);
        $category->expects($this->once())->method('determineCategory')->with(1, 'Known.Movie.2026.1080p.WEB-DL-GRP', 'poster')->willReturn(['categories_id' => 2000]);
        Search::shouldReceive('matchPredbExact')->once()->with('masked')->andReturnNull();
        Search::shouldReceive('matchPredbExact')->once()->with('Known.Movie.2026.1080p.WEB-DL-GRP')->andReturn(['id' => 7]);
        $resolver = new ReleaseMetadataResolver($cleaning, new PayloadIdentityResolver($cleaning), $category);
        $metadata = $resolver->resolve($this->manifest());
        $this->assertSame('masked', $metadata['name']);
        $this->assertSame('Known.Movie.2026.1080p.WEB-DL-GRP', $metadata['searchname']);
        $this->assertSame(7, $metadata['predb_id']);
        $this->assertSame(1, $metadata['isrenamed']);
    }

    public function test_properly_named_subject_wins_over_conflicting_payload_hint(): void
    {
        $cleaning = $this->createPartialMock(ReleaseCleaningService::class, ['releaseCleaner']);
        $cleaning->expects($this->once())->method('releaseCleaner')->willReturn(['cleansubject' => 'Already.Named.2026.1080p-GRP', 'properlynamed' => true]);
        $category = $this->createMock(CategorizationService::class);
        $category->expects($this->once())->method('determineCategory')->willReturn(['categories_id' => 2000]);
        Search::shouldReceive('matchPredbExact')->once()->with('Already.Named.2026.1080p-GRP')->andReturnNull();
        $metadata = (new ReleaseMetadataResolver($cleaning, new PayloadIdentityResolver($cleaning), $category))->resolve($this->manifest());
        $this->assertSame('Already.Named.2026.1080p-GRP', $metadata['searchname']);
    }

    public function test_filename_alias_for_a_different_stored_title_cannot_assign_predb_id(): void
    {
        DB::table('predb')->where('id', 7)->update(['title' => 'Different.Movie.2026.1080p.WEB-DL-GRP', 'filename' => 'Known.Movie.2026.1080p.WEB-DL-GRP']);
        $cleaning = $this->createPartialMock(ReleaseCleaningService::class, ['releaseCleaner']);
        $cleaning->expects($this->once())->method('releaseCleaner')->willReturn(['cleansubject' => 'masked', 'properlynamed' => false]);
        $category = $this->createMock(CategorizationService::class);
        $category->expects($this->once())->method('determineCategory')->willReturn(['categories_id' => 2000]);
        Search::shouldReceive('matchPredbExact')->once()->with('masked')->andReturnNull();
        Search::shouldReceive('matchPredbExact')->once()->with('Known.Movie.2026.1080p.WEB-DL-GRP')->andReturn(['id' => 7]);
        $metadata = (new ReleaseMetadataResolver($cleaning, new PayloadIdentityResolver($cleaning), $category))->resolve($this->manifest());
        $this->assertSame('Known.Movie.2026.1080p.WEB-DL-GRP', $metadata['searchname']);
        $this->assertSame(0, $metadata['predb_id']);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return ['subject' => 'masked', 'fromname' => 'poster', 'groups' => ['alt.test'], 'groups_id' => 1,
            'identity_hints' => [['name' => 'Known.Movie.2026.1080p.WEB-DL-GRP.mkv', 'source' => 'yenc']]];
    }
}
