<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

require_once __DIR__.'/CertifiedPredbSqlFixture.php';

use App\Facades\Search;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class NameFixingPredbSearchTextTest extends TestCase
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

    /**
     * Hashed file names with unbalanced parentheses, quotes and operators reached Manticore unescaped
     * and failed with "P08: syntax error, unexpected ')'" (BUGS.md #35).
     */
    #[DataProvider('machineGeneratedNames')]
    public function test_machine_generated_names_become_plain_words(string $fileName, string $expected): void
    {
        $text = NameFixingService::predbSearchText($fileName);

        $this->assertSame($expected, $text);
        $this->assertDoesNotMatchRegularExpression('/[()\[\]"\'|!@~&\/$=\\\\<>:;,#?*+{}^%]/', $text);
        // What the driver builds from it contains no query operators either.
        $this->assertDoesNotMatchRegularExpression('/[()|]/', ManticoreSearchDriver::prepareUserSearchQuery($text));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function machineGeneratedNames(): array
    {
        return [
            'ordinary release name is unchanged' => ['Some.Show.S01E01.1080p.WEB-DL-GRP', 'Some.Show.S01E01.1080p.WEB-DL-GRP'],
            'unbalanced parenthesis' => ['idk_rza-everyone_knows_)-21ccf990', 'idk_rza-everyone_knows_ 21ccf990'],
            'hashed junk from the logs' => [')C<b;<C6.U#sjLBU~~hBk0eQ:@Z0J=qSl?Xu!;Y>g!\'RO]elC0K[(hc!0r?;s.F}< F4;D$sd@GQ;H+>;8<I@[=+52@Tw)', 'C b C6.U sjLBU hBk0eQ Z0J qSl Xu Y g RO elC0K hc 0r s.F F4 D sd GQ H 8 I 52 Tw'],
            'negation and OR operators' => ['-excluded | other !not', 'excluded other not'],
            'only punctuation' => ['())(|', ''],
        ];
    }

    #[DataProvider('updateModes')]
    public function test_same_title_predb_match_attaches_only_when_updates_are_enabled(bool $update): void
    {
        $updates = $this->getMockBuilder(ReleaseUpdateService::class)->disableOriginalConstructor()
            ->onlyMethods(['attachPredbSnapshot', 'updateRelease', 'updateSingleColumn', 'currentPredbTitle'])->getMock();
        $updates->expects($this->exactly($update ? 0 : 1))->method('currentPredbTitle')
            ->with(7, 'Canonical.Scene-GROUP', 'Canonical.Scene-GROUP.mkv')->willReturn('Canonical.Scene-GROUP');
        $updates->expects($this->never())->method('updateRelease');
        $updates->expects($this->never())->method('updateSingleColumn');
        $updates->expects($this->exactly($update ? 1 : 0))->method('attachPredbSnapshot')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 42), 7)
            ->willReturnCallback(static function (object $release, int $predbId) use ($updates): bool {
                $updates->matched = true;
                $updates->done = true;

                return true;
            });

        $service = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        DB::table('predb')->insert(['id' => 7, 'title' => 'Canonical.Scene-GROUP', 'filename' => '']);
        $this->certifySqlFixture();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        (new ReflectionProperty(NameFixingService::class, 'predbMatchSelector'))->setValue($service, new PredbMatchSelector);
        (new ReflectionProperty(NameFixingService::class, 'fileNameCleaner'))->setValue($service, new FileNameCleaner);
        (new ReflectionProperty(NameFixingService::class, 'updateService'))->setValue($service, $updates);
        $release = (object) [
            'releases_id' => 42, 'searchname' => 'canonical.scene-group',
            'textstring' => 'Canonical.Scene-GROUP.mkv',
        ];

        $matched = (new ReflectionMethod(NameFixingService::class, 'preDbFileCheck'))
            ->invoke($service, $release, $update, 'Filenames, ', true, false);

        $this->assertSame($update, $matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame('canonical.scene-group', $release->searchname);
    }

    #[DataProvider('updateModes')]
    public function test_same_title_donor_match_attaches_only_when_updates_are_enabled(bool $update): void
    {
        $updates = $this->getMockBuilder(ReleaseUpdateService::class)->disableOriginalConstructor()
            ->onlyMethods(['attachPredbSnapshot', 'updateRelease', 'updateSingleColumn'])->getMock();
        $updates->expects($this->never())->method('updateRelease');
        $updates->expects($this->never())->method('updateSingleColumn');
        $updates->expects($this->exactly($update ? 1 : 0))->method('attachPredbSnapshot')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 42), 7)
            ->willReturnCallback(static function (object $release, int $predbId) use ($updates): bool {
                $updates->matched = true;
                $updates->done = true;

                return true;
            });

        $service = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NameFixingService::class, 'donorMatchSelector'))->setValue($service, new DonorMatchSelector);
        (new ReflectionProperty(NameFixingService::class, 'updateService'))->setValue($service, $updates);
        $release = (object) [
            'releases_id' => 42, 'searchname' => 'canonical.scene-group', 'relsize' => 5000,
        ];
        $donor = (object) [
            'releases_id' => 99, 'searchname' => 'Canonical.Scene-GROUP', 'relsize' => 5000,
            'fromname' => 'poster@example.test', 'predb_id' => 7,
        ];

        $matched = (new ReflectionMethod(NameFixingService::class, 'applyDonorMatch'))
            ->invoke($service, $release, [$donor], 5, 'hashCheck: 16K Hash', 'PAR2 hash, ', $update, true, false);

        $this->assertSame($update, $matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame('canonical.scene-group', $release->searchname);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function updateModes(): array
    {
        return ['preview' => [false], 'update' => [true]];
    }
}
