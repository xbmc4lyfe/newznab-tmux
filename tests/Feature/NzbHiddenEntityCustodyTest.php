<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class NzbHiddenEntityCustodyTest extends ReleaseVerificationCandidatesTest
{
    public static function cases(): array
    {
        $cases = [];
        foreach (['legacy', 'default', 'prefix'] as $form) {
            foreach (['meta', 'head', 'head-files', 'second', 'external'] as $mode) {
                foreach ([false, true] as $delete) {
                    foreach ([false, true] as $failed) {
                        $cases[] = [$form, $mode, $delete, $failed];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_public_custody(string $form, string $mode, bool $delete, bool $failed): void
    {
        $stub = $this->createStub(BlacklistService::class);
        $stub->method('isBlackListed')->willReturn(false);
        $stub->method('getAndClearIdsToUpdate')->willReturn([]);
        $service = new class($stub) extends NzbImportService
        {
            public function __construct($stub)
            {
                parent::__construct(['Browser' => true]);
                $this->blacklistService = $stub;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }
        };
        $file = '<file poster="poster" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file>';
        $meta = '<meta type="password">synthetic fixture</meta>';
        [$entity,$body] = match ($mode) {
            'meta' => [$meta, '<head>&hidden;</head>'.$file],
            'head' => ['<head>'.$meta.'</head>', '&hidden;'.$file],
            'head-files' => ['<head>'.$meta.'</head>'.$file, '&hidden;'],
            'second' => [$meta, '<head/><head>&hidden;</head>'.$file],
            'external' => ['', '<head>&hidden;</head>'.$file],
        };
        $xml = '<nzb>'.$body.'</nzb>';
        if ($form === 'default') {
            $xml = str_replace('<nzb>', '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">', $xml);
        }
        if ($form === 'prefix') {
            $xml = preg_replace('~(<\\/?)(nzb|head|meta|file|groups|group|segments|segment)(?=[ />])~', '$1n:$2', $xml);
            $entity = preg_replace('~(<\\/?)(head|meta|file|groups|group|segments|segment)(?=[ />])~', '$1n:$2', $entity);
            $xml = str_replace('<n:nzb>', '<n:nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb">', $xml);
        }
        $declaration = $mode === 'external' ? '<!ENTITY hidden SYSTEM "https://example.invalid/entity">' : '<!ENTITY hidden \''.$entity.'\'>';
        $xml = '<!DOCTYPE nzb ['.$declaration.']>'.$xml;
        $path = config('release_verification.storage_path').'/fixture.nzb';
        app('files')->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $xml);
        $statuses = [];
        $service->beginImport([$path], delete: $delete, deleteFailed: $failed, resultCallback: static function (array $r) use (&$statuses) {
            $statuses[] = $r['status'];
        });
        self::assertFileExists($path);
        self::assertSame($xml, file_get_contents($path));
        self::assertSame([NzbImportStatus::MetadataIgnored], $statuses);
        self::assertSame(0, DB::table('release_verification_candidates')->count());
    }
}
