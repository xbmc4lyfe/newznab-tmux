<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\NfoService;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class NzbEarlyPasswordCustodyTest extends ReleaseVerificationCandidatesTest
{
    public static function terminalCases(): array
    {
        $cases = [];
        foreach (['nested', 'too-long', 'too-many', 'ordinary'] as $mode) {
            foreach (['blacklisted', 'missing-group'] as $terminal) {
                foreach ([false, true] as $manifested) {
                    $cases[$mode.' '.$terminal.($manifested ? ' paired' : ' raw')] = [$mode, $terminal, $manifested];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('terminalCases')]
    public function test_complete_password_validation_precedes_real_terminal_scanner(string $mode, string $terminal, bool $manifested): void
    {
        $blacklist = $this->createStub(BlacklistService::class);
        $blacklist->method('isBlackListed')->willReturn($terminal === 'blacklisted');
        $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($blacklist, $terminal !== 'missing-group') extends NzbImportService
        {
            public int $scans = 0;

            public function __construct($blacklist, private bool $knownGroup)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = $this->knownGroup ? ['alt.test' => 1] : [];

                return true;
            }

            protected function scanNZBFile(mixed &$xml, mixed $name = '', mixed $source = ''): NzbImportStatus
            {
                $this->scans++;

                return parent::scanNZBFile($xml, $name, $source);
            }
        };
        $head = match ($mode) {
            'nested' => '<meta type="password"><child/></meta>',
            'too-long' => '<meta type="password">'.str_repeat('x', 4097).'</meta>',
            'too-many' => str_repeat('<meta type="password">synthetic assertion</meta>', 65),
            default => '',
        };
        $xml = '<nzb><head>'.$head.'</head><file poster="fixture" date="1" subject="fixture.bin"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">early@example.test</segment></segments></file></nzb>';
        if ($terminal === 'missing-group') {
            $xml = str_replace('<group>alt.test</group>', '<group>!invalid!</group>', $xml);
        }
        $dir = config('release_verification.storage_path').'/early';
        app('files')->ensureDirectoryExists($dir);
        $source = $dir.'/incoming.nzb';
        file_put_contents($source, $xml);
        $uploads = app(NzbUploadManifestService::class);
        if ($manifested) {
            file_put_contents($dir.'/info.nfo', 'synthetic companion');
            $uploads->create($dir, 'early-fixture', 'incoming.nzb', 'info.nfo', 'synthetic companion');
        }
        $statuses = [];
        $service->beginImport([$source], delete: true, deleteFailed: true, resultCallback: static function (array $r) use (&$statuses): void {
            $statuses[] = $r['status'];
        });
        if ($mode === 'ordinary') {
            self::assertSame($terminal === 'blacklisted' ? NzbImportStatus::Blacklisted : NzbImportStatus::NoGroup, end($statuses));
            self::assertSame(1, $service->scans);
            self::assertFileDoesNotExist($source);
        } else {
            self::assertSame(NzbImportStatus::MetadataIgnored, end($statuses));
            self::assertSame(0, $service->scans);
            self::assertSame($xml, file_get_contents($source));
        }
        self::assertSame(0, DB::table('release_verification_candidates')->count());
        if ($manifested) {
            $nfos = new NfoImportService(new Filesystem, $uploads, new NfoService);
            self::assertSame('skipped', $nfos->importManifest($dir.'/'.NzbUploadManifestService::FILENAME, true, true)['status']);
            if ($mode !== 'ordinary') {
                self::assertSame('synthetic companion', file_get_contents($dir.'/info.nfo'));
            }
        }
    }
}
