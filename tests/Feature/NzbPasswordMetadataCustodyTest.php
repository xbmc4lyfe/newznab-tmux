<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class NzbPasswordMetadataCustodyTest extends ReleaseVerificationCandidatesTest
{
    /** @return list<array{string, bool, bool}> */
    public static function scenarios(): array
    {
        $cases = [];
        foreach (['ambiguous', 'nested', 'too-many', 'too-long', 'invalid', 'entity-legacy', 'entity-default', 'entity-prefixed'] as $mode) {
            foreach ([false, true] as $delete) {
                foreach ([false, true] as $deleteFailed) {
                    $cases[] = [$mode, $delete, $deleteFailed];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_rejected_metadata_retains_source_without_changing_ordinary_failure_deletion(string $mode, bool $delete, bool $deleteFailed): void
    {
        config(['logging.default' => 'stderr']);
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn(false);
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($blacklist) extends NzbImportService
        {
            public function __construct($blacklist)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        $path = config('release_verification.storage_path').'/incoming.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        $xml = '<nzb><head>%s</head><file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';
        file_put_contents($path, sprintf($xml, ''));
        $statuses = [];
        $callback = static function (array $r) use (&$statuses) {
            $statuses[] = $r['status'];
        };
        $service->beginImport([$path], resultCallback: $callback);
        $head = match ($mode) {
            'nested' => '<meta type="password"><child/></meta>',
            'too-many' => str_repeat('<meta type="password">synthetic assertion</meta>', 65),
            'too-long' => '<meta type="password">'.str_repeat('x', 4097).'</meta>',
            default => '<meta type="password">synthetic assertion</meta>',
        };
        $contents = sprintf($xml, $head);
        if ($mode === 'ambiguous') {
            $contents = str_replace('</head>', '</head><head/>', $contents);
        } elseif ($mode === 'invalid') {
            $contents = 'invalid fixture XML';
        }
        if (str_starts_with($mode, 'entity-')) {
            $contents = sprintf($xml, '<meta type="password">&privateFixture;</meta>');
            if ($mode === 'entity-default') {
                $contents = str_replace('<nzb>', '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">', $contents);
            } elseif ($mode === 'entity-prefixed') {
                $contents = preg_replace('~(<\/?)(nzb|head|meta|file|groups|group|segments|segment)(?=[ >])~', '$1n:$2', $contents);
                $contents = str_replace('<n:nzb>', '<n:nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb">', $contents);
            }
            $contents = '<!DOCTYPE nzb [<!ENTITY privateFixture "<child/>">]>'.$contents;
        }
        file_put_contents($path, $contents);
        $before = (array) DB::table('release_verification_candidates')->first();
        $sourceHash = hash_file('sha256', $path);
        $service->beginImport([$path], delete: $delete, deleteFailed: $deleteFailed, resultCallback: $callback);
        if ($mode === 'invalid') {
            self::assertSame(NzbImportStatus::Failed, end($statuses));
            self::assertSame(! $deleteFailed, is_file($path));

            return;
        }
        self::assertFileExists($path, 'Assertion-bearing rejected input must remain in uploader custody.');
        self::assertSame($sourceHash, hash_file('sha256', $path));
        self::assertSame(NzbImportStatus::MetadataIgnored, end($statuses));
        self::assertSame($before, (array) DB::table('release_verification_candidates')->first());
    }
}
