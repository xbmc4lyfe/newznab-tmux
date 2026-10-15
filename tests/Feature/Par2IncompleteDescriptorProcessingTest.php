<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Par2FileList;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Support\Par2RenameProgress;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleXMLElement;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class Par2IncompleteDescriptorProcessingTest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tmux.fix_names.cache_store' => 'array', 'tmux.fix_names.par2_max_probes' => 2]);
        Cache::store('array')->flush();
        $this->createPar2RenameSchema();
    }

    #[DataProvider('partialDescriptorFragments')]
    public function test_incomplete_actual_descriptor_inventory_cannot_complete_an_unrenamed_release(bool $retainOrphanChecksums): void
    {
        $fragment = $this->partialFixture($retainOrphanChecksums);
        $info = new Par2Info;
        $this->assertTrue($info->setData($fragment));
        $this->assertSame('', $info->error);
        $this->assertTrue((new Par2FileList)->hasUnresolvedDescriptors($info));
        $release = $this->pendingPar2Release();
        $nntp = $this->createPartialMock(NNTPService::class, ['getMessageBounded']);
        $nntp->expects($this->once())->method('getMessageBounded')->willReturn(BoundedArticleResult::success($fragment));
        $names = $this->createStub(NameFixingService::class);
        $names->method('checkName')->willReturn(false);
        $post = new PostProcessService(nameFixingService: $names, par2Processor: new Par2Processor($names, new Par2Info, false, false));
        $service = $this->service($nntp, $post);

        $outcome = $service->checkPar2Result($release->guid, $release->id, 1, 1, 0);

        $this->assertSame([
            'outcome' => Par2RenameOutcome::Retryable,
            'isrenamed' => 0,
            'proc_par2' => 0,
            'checkpoint_retained' => true,
        ], [
            'outcome' => $outcome,
            'isrenamed' => (int) $release->fresh()->isrenamed,
            'proc_par2' => (int) $release->fresh()->proc_par2,
            'checkpoint_retained' => Par2RenameProgress::get($release->id) !== null,
        ]);
    }

    /** @return array<string, array{bool}> */
    public static function partialDescriptorFragments(): array
    {
        return [
            'one description plus nine orphan IFSC entries' => [true],
            'Main declares a file with neither description nor IFSC' => [false],
        ];
    }

    private function partialFixture(bool $retainOrphanChecksums): string
    {
        $data = (string) file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2'));
        $fragment = '';
        $descriptionCount = 0;
        $missingId = null;
        for ($offset = 0; $offset < strlen($data); $offset += $length) {
            $length = unpack('V', substr($data, $offset + 8, 4))[1];
            $type = substr($data, $offset + 48, 16);
            $id = substr($data, $offset + 64, 16);
            if ($type === Par2Info::PACKET_FILEDESC) {
                $descriptionCount++;
                if (($retainOrphanChecksums && $descriptionCount > 1) || (! $retainOrphanChecksums && $descriptionCount === 1)) {
                    $missingId = $id;

                    continue;
                }
            }
            if (! $retainOrphanChecksums && $type === Par2Info::PACKET_FILEVER && $id === $missingId) {
                continue;
            }
            $fragment .= substr($data, $offset, $length);
        }

        return $fragment;
    }

    private function service(NNTPService $nntp, PostProcessService $post): NzbContentsService
    {
        return new class($nntp, $post) extends NzbContentsService
        {
            public function __construct(NNTPService $nntp, PostProcessService $post)
            {
                $this->nntp = $nntp;
                $this->nfo = new NfoService;
                $this->postProcessService = $post;
                $this->parserService = new NzbParserService;
            }

            public function loadNzb(string $guid): SimpleXMLElement
            {
                return new SimpleXMLElement('<nzb><file subject="partial.par2"><segments><segment number="1" bytes="1000">partial@example.test</segment></segments></file></nzb>');
            }
        };
    }
}
