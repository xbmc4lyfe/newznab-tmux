<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Par2RenameOutcome;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionProperty;
use SimpleXMLElement;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class NzbContentsPar2RecoveryTest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tmux.fix_names.cache_store' => 'array', 'tmux.fix_names.par2_max_probes' => 4]);
        Cache::store('array')->flush();
        $this->createPar2RenameSchema();
        $this->pendingPar2Release(['id' => 123, 'guid' => 'guid']);
    }

    public function test_it_skips_archive_articles_before_downloading_par2(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->once())->method('applyInventoryForRename')
            ->with($this->isInstanceOf(Par2PacketAccumulator::class), 123, 1, 0)
            ->willReturnCallback(fn (): Par2RenameOutcome => $this->renamed());
        $service = $this->service([
            ['subject' => '"hashed.part01.rar" yEnc (1/800)', 'id' => 'archive@example'],
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'par2@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(1, DB::table('releases')->value('proc_par2'));
    }

    public function test_it_recognizes_success_from_the_first_article_of_a_multipart_par2(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->once())->method('applyInventoryForRename')->with(
            $this->isInstanceOf(Par2PacketAccumulator::class), 123, 1, 0
        )->willReturnCallback(fn (): Par2RenameOutcome => $this->renamed());
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/20)', 'id' => 'par2@example', 'second_id' => 'second@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
    }

    public function test_it_tries_another_par2_after_the_first_fails(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $messages = [];
        $processor->expects($this->exactly(2))->method('applyInventoryForRename')
            ->willReturnCallback(function (Par2PacketAccumulator $inventory) use (&$messages): Par2RenameOutcome {
                $message = array_values(array_values($inventory->filesBySet())[0])[0]['name'];
                $messages[] = $message;

                return $message === 'second-index@example' ? $this->renamed() : Par2RenameOutcome::NoMatch;
            });
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'index@example'],
            ['subject' => '"hashed.part01.rar" yEnc (1/800)', 'id' => 'archive@example'],
            ['subject' => '"second-index.par2" yEnc (1/20)', 'id' => 'second-index@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(['index@example', 'second-index@example'], $messages);
    }

    public function test_it_skips_files_with_no_segments(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->never())->method('applyInventoryForRename');
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => ''],
        ], $processor);

        $this->assertFalse($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(0, DB::table('releases')->value('proc_par2'));
    }

    public function test_it_keeps_single_segment_numbered_hidden_par2_candidates(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->once())->method('applyInventoryForRename')->with(
            $this->isInstanceOf(Par2PacketAccumulator::class), 123, 1, 0
        )->willReturnCallback(fn (): Par2RenameOutcome => $this->renamed());
        $service = $this->service([
            ['subject' => '"hashed.001" yEnc (1/1)', 'id' => 'hidden@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
    }

    public function test_disabled_naming_does_not_download_or_mark_processed(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->never())->method('applyInventoryForRename');
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'par2@example'],
        ], $processor, loadsNzb: false);

        $this->assertFalse($service->checkPar2('guid', 123, 1, 0, 0));
        $this->assertSame(0, DB::table('releases')->value('proc_par2'));
    }

    public function test_it_recovers_hidden_par2_after_explicit_candidates_fail(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $messages = [];
        $processor->expects($this->exactly(2))->method('applyInventoryForRename')
            ->willReturnCallback(function (Par2PacketAccumulator $inventory) use (&$messages): Par2RenameOutcome {
                $message = array_values(array_values($inventory->filesBySet())[0])[0]['name'];
                $messages[] = $message;

                return $message === 'hidden@example' ? $this->renamed() : Par2RenameOutcome::NoMatch;
            });
        $service = $this->service([
            ['subject' => '"hashed.bin" yEnc (1/1)', 'id' => 'hidden@example'],
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'index@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(['index@example', 'hidden@example'], $messages);
    }

    public function test_it_marks_processed_only_after_all_candidates_fail(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->exactly(2))->method('applyInventoryForRename')->willReturn(Par2RenameOutcome::NoMatch);
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'index@example'],
            ['subject' => '"second-index.par2" yEnc (1/20)', 'id' => 'second-index@example'],
        ], $processor);

        $this->assertFalse($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(1, DB::table('releases')->value('proc_par2'));
    }

    public function test_malformed_excluded_archive_segments_do_not_block_par2_completion(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->once())->method('applyInventoryForRename')
            ->willReturn(Par2RenameOutcome::NoMatch);
        $service = $this->service([
            ['subject' => '"hashed.part01.rar" yEnc (1/800)', 'id' => 'archive@example', 'second_id' => 'archive-gap@example', 'second_number' => 3],
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'par2@example'],
        ], $processor);

        $this->assertFalse($service->checkPar2('guid', 123, 1, 1, 0));
        $this->assertSame(1, DB::table('releases')->value('proc_par2'));
    }

    public function test_it_stops_after_a_successful_candidate(): void
    {
        $processor = $this->createMock(Par2Processor::class);
        $processor->expects($this->once())->method('applyInventoryForRename')->willReturnCallback(fn (): Par2RenameOutcome => $this->renamed());
        $service = $this->service([
            ['subject' => '"hashed.par2" yEnc (1/1)', 'id' => 'index@example'],
            ['subject' => '"second-index.par2" yEnc (1/20)', 'id' => 'second-index@example'],
        ], $processor);

        $this->assertTrue($service->checkPar2('guid', 123, 1, 1, 0));
    }

    private function renamed(): Par2RenameOutcome
    {
        DB::table('releases')->where('id', 123)->update(['proc_par2' => 1, 'isrenamed' => 1, 'searchname' => 'Recovered.Title']);

        return Par2RenameOutcome::Renamed;
    }

    private function descriptor(string $name): string
    {
        $body = md5($name, true).md5('whole', true).md5('first', true).pack('V2', 9, 0).$name;
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = md5('set', true).Par2Info::PACKET_FILEDESC.$body;

        return Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
    }

    /**
     * @param  list<array{subject: string, id: string, second_id?: string, second_number?: int}>  $files
     */
    private function service(array $files, Par2Processor $processor, bool $loadsNzb = true): NzbContentsService
    {
        $xml = new SimpleXMLElement('<nzb/>');
        foreach ($files as $file) {
            $element = $xml->addChild('file');
            $element->addAttribute('subject', $file['subject']);
            $segments = $element->addChild('segments');
            if ($file['id'] !== '') {
                $segment = $segments->addChild('segment', $file['id']);
                $segment->addAttribute('number', '1');
                $segment->addAttribute('bytes', '4096');
                if (isset($file['second_id'])) {
                    $second = $segments->addChild('segment', $file['second_id']);
                    $second->addAttribute('number', (string) ($file['second_number'] ?? 2));
                    $second->addAttribute('bytes', '4096');
                }
            }
        }
        $service = $this->getMockBuilder(NzbContentsService::class)
            ->disableOriginalConstructor()->onlyMethods(['loadNzb'])->getMock();
        $service->expects($loadsNzb ? $this->once() : $this->never())->method('loadNzb')->willReturn($xml);
        $postProcess = (new ReflectionClass(PostProcessService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PostProcessService::class, 'par2Processor'))->setValue($postProcess, $processor);
        (new ReflectionProperty(NzbContentsService::class, 'postProcessService'))->setValue($service, $postProcess);
        (new ReflectionProperty(NzbContentsService::class, 'parserService'))->setValue($service, new NzbParserService);
        $nntp = $this->createStub(NNTPService::class);
        $nntp->method('getMessageBounded')->willReturnCallback(fn (string $group, string $id): BoundedArticleResult => BoundedArticleResult::success($this->descriptor($id)));
        (new ReflectionProperty(NzbContentsService::class, 'nntp'))->setValue($service, $nntp);

        return $service;
    }
}
