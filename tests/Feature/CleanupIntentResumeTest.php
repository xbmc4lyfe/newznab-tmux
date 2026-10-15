<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\NfoService;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapturedUploadFixture;

class CleanupIntentResumeTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public static function boundaries(): array
    {
        return array_combine(['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir'], array_map(static fn ($v) => [$v], ['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir']));
    }

    private function upload(bool $companion): array
    {
        Schema::create('releases', static function (Blueprint $t): void {
            $t->id();
            $t->string('guid');
            $t->integer('nfostatus')->default(-1);
        });
        $dir = config('release_verification.storage_path').'/origin';
        app('files')->ensureDirectoryExists($dir);
        $source = $dir.'/incoming.nzb';
        file_put_contents($source, '<nzb><file subject="fixture.bin"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">intent@example.test</segment></segments></file></nzb>');
        if ($companion) {
            file_put_contents($dir.'/info.nfo', 'synthetic companion');
        }
        $uploads = app(NzbUploadManifestService::class);
        $uploads->create($dir, 'intent-origin', 'incoming.nzb', $companion ? 'info.nfo' : null, $companion ? 'synthetic companion' : null);

        return [$source, $dir.'/'.NzbUploadManifestService::FILENAME, $uploads];
    }

    public static function sourceBoundaries(): array
    {
        $cases = [];
        foreach (self::boundaries() as $name => [$boundary]) {
            foreach ([false, true] as $replacement) {
                $cases[$name.($replacement ? ' replacement' : ' absent')] = [$boundary, $replacement];
            }
        }

        return $cases;
    }

    #[DataProvider('sourceBoundaries')]
    public function test_source_only_publication_resumes_after_private_payload_disappears(string $boundary, bool $replacement): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $this->capturedFixtureImporter('intent-only')->beginImport([$source], delete: true);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'intent-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        DirectorySyncBridge::$fail = $boundary;
        try {
            $uploads->reconcilePending($mp);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        self::assertArrayHasKey('delete_nzb_after_publication', $uploads->read($mp));
        if ($replacement) {
            file_put_contents($source, 'replacement generation');
        }
        $result = $uploads->reconcilePending($mp);
        if ($replacement) {
            self::assertSame('replacement generation', file_get_contents($source));
        }
        self::assertSame(NzbUploadManifestService::STATE_NZB_IMPORTED, $result['state']);
        self::assertSame(7, $result['release_id']);
        self::assertArrayNotHasKey('delete_nzb_after_publication', $result);
        self::assertSame($result['accepted_capture']['attempt'], $result['relinquished_attempt']);
    }

    public function test_replacement_paths_cannot_replace_pending_cleanup_authority(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$fail = 'rename_custody';
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $capture = $uploads->read($mp)['delete_input_pending'];
        file_put_contents($source, 'replacement generation');
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
        self::assertSame($capture, $uploads->read($mp)['delete_input_pending']);
        $result = $uploads->reconcilePending($mp);
        self::assertSame('replacement generation', file_get_contents($source));
        self::assertArrayNotHasKey('delete_input_pending', $result);
        self::assertSame(NzbUploadManifestService::STATE_NZB_FAILED, $result['state']);
    }

    public function test_intent_without_owned_move_checkpoint_cannot_retire_replacement(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$fail = 'unlink_dir';
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $markers = glob(dirname($source).'/.custody-*/moved.json');
        self::assertCount(1, $markers);
        unlink($markers[0]);
        file_put_contents($source, 'replacement without relocation proof');
        $result = $uploads->reconcilePending($mp);
        self::assertArrayHasKey('delete_input_pending', $result);
        self::assertArrayNotHasKey('relinquished_attempt', $result);
        self::assertSame('replacement without relocation proof', file_get_contents($source));
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
    }

    #[DataProvider('sourceBoundaries')]
    public function test_failed_input_and_companion_resume_from_manifest_without_original_paths(string $boundary, bool $replacement): void
    {
        [$source, $mp, $uploads] = $this->upload(true);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        $nfos = new NfoImportService(new Filesystem, $uploads, new NfoService);
        DirectorySyncBridge::$fail = $boundary;
        try {
            $importer->beginImport([$source], deleteFailed: true);
            $nfos->importManifest($mp, deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        self::assertFileDoesNotExist(dirname($source).'/info.nfo');
        self::assertArrayHasKey('delete_input_pending', $uploads->read($mp));
        self::assertArrayHasKey('delete_terminal_nfo', $uploads->read($mp));
        if ($replacement) {
            file_put_contents($source, 'replacement NZB');
            file_put_contents(dirname($source).'/info.nfo', 'replacement NFO');
        }
        $result = $uploads->reconcilePending($mp);
        self::assertSame(NzbUploadManifestService::STATE_NZB_FAILED, $result['state']);
        self::assertArrayNotHasKey('delete_input_pending', $result);
        self::assertArrayNotHasKey('delete_terminal_nfo', $result);
        self::assertSame($result['outcome_capture']['attempt'], $result['relinquished_attempt']);
        self::assertSame(0, DB::table('releases')->count());
        if ($replacement) {
            self::assertSame('replacement NZB', file_get_contents($source));
            self::assertSame('replacement NFO', file_get_contents(dirname($source).'/info.nfo'));
            self::assertArrayNotHasKey('deferred', $uploads->reserveInput($source));
        }
    }
}
