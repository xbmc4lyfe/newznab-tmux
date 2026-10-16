<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

require_once __DIR__.'/CertifiedPredbSqlFixture.php';

use App\Facades\Search;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class OriginalFilenameSuffixCompatibilityTest extends TestCase
{
    use CertifiedPredbSqlFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCertifiedSqlFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownCertifiedSqlFixture();
        parent::tearDown();
    }

    public static function supportedWrappers(): array
    {
        return array_map(fn ($s) => [$s], ['.epub', '.iso', '.srs', '.mkv.001', '.7z.001', '.r100', '.z01', '.rar.par2', '.vol00+01.par2', '.m4v', '.tar.zst', '.part01.rar', '.part12345.rar', '.part12345678901234567890.rar', '.part12345', '.part12345678901234567890', '.r00', '.rar', '.mkv']);
    }

    #[DataProvider('supportedWrappers')]
    public function test_canonical_outvotes_alias_in_actual_public_dispatch(string $suffix): void
    {
        $title = 'Exact.Release.2026-GRP';
        $raw = $title.$suffix;
        $aliasTitle = 'Other.Canonical.2026-GRP';
        $rows = [['id' => 7, 'title' => $title], ['id' => 8, 'title' => $aliasTitle, 'filename' => $raw]];
        self::assertSame([$raw], (new FilePrioritizer)->prioritizeForPreDb([$raw]));
        $this->assertPublicDispatch($raw, $title, $rows, 7, $title);
    }

    public function test_preserves_literal_codec_before_payload_and_archive(): void
    {
        $title = 'Exact.Release.2026-GRP';
        foreach (['.DTS.rar' => '.DTS', '.DTS.part12345.rar' => '.DTS', '.flac.mkv' => '.flac'] as $suffix => $canonicalTail) {
            $e = new OriginalFilenameEvidence($title.$suffix);
            self::assertSame($title.$canonicalTail, $e->stem);
            self::assertTrue($e->accepts($title.$canonicalTail));
            self::assertFalse($e->accepts($title));
            $this->assertPublicDispatch($title.$suffix, $title.$canonicalTail, [['id' => 7, 'title' => $title.$canonicalTail], ['id' => 8, 'title' => $title]], 7, $title.$canonicalTail);
        }
    }

    public function test_disc1_control_space_and_unknown_suffix_cannot_authorize_shorter_title(): void
    {
        $title = 'Exact.Release.2026-GRP';
        foreach (['disc1.mkv', "\x01.mkv", ' .mkv', '.unknown'] as $suffix) {
            self::assertFalse((new OriginalFilenameEvidence($title.$suffix))->accepts($title));
            $this->assertPublicDispatch($title.$suffix, $title, [['id' => 7, 'title' => $title]], null, null);
        }
    }

    private function assertPublicDispatch(string $raw, string $searchname, array $rows, ?int $id, ?string $canonical): void
    {
        DB::table('predb')->delete();
        foreach ($rows as $row) {
            DB::table('predb')->insert(['id' => $row['id'], 'title' => $row['title'], 'filename' => $row['filename'] ?? '']);
        }
        $this->certifySqlFixture();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $updates = $this->createMock(ReleaseUpdateService::class);
        if ($id === null) {
            $updates->expects(self::never())->method('currentPredbTitle');
        } else {
            $updates->expects(self::once())->method('currentPredbTitle')->with($id, $canonical, $raw)->willReturn($canonical);
        }
        $service = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        foreach (['updateService' => $updates, 'fileNameCleaner' => new FileNameCleaner, 'filePrioritizer' => new FilePrioritizer, 'predbMatchSelector' => new PredbMatchSelector] as $p => $v) {
            (new ReflectionProperty($service, $p))->setValue($service, $v);
        }
        self::assertSame($id === null ? 0 : 1, $service->matchPreDbFileNames((object) ['searchname' => $searchname], [$raw], false, false, false));
    }
}
