<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbUnicodeIdentityTest extends TestCase
{
    use Par2RenameTestDatabase;

    /** Retained inactive hdencode RSS title/General Filename pair, dated 2026-10-11. */
    private const TITLE = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
    }

    /** @return iterable<string, array{string}> */
    public static function unconfirmedLiteralTitles(): iterable
    {
        yield 'ASCII control' => ['Cafe.Scene.Movie.2026.1080p.WEB-DL-GRP'];
        yield 'Unicode scene title' => ['Café.Scene.Movie.2026.1080p.WEB-DL-GRP'];
        yield 'Unicode literal underscore' => ['Café_Scene.Movie.2026.1080p.WEB-DL-GRP'];
        yield 'multibyte title beyond byte truncation' => [str_repeat('É', 150).'.Movie.2026.1080p.WEB-DL-GRP'];
    }

    #[DataProvider('unconfirmedLiteralTitles')]
    public function test_unconfirmed_literal_rename_can_link_later_authoritative_predb_arrival(string $title): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('like', static function (string $pattern, string $subject, string $escape): int {
            $regex = '';
            $escaped = false;
            foreach (mb_str_split($pattern) as $character) {
                if (! $escaped && $character === substr($escape, 0, 1)) {
                    $escaped = true;

                    continue;
                }
                $regex .= $escaped ? preg_quote($character, '~') : match ($character) {
                    '%' => '.*',
                    '_' => '.',
                    default => preg_quote($character, '~'),
                };
                $escaped = false;
            }

            return (int) preg_match('~^'.$regex.'$~ius', $subject);
        }, 3);
        $release = $this->pendingPar2Release(['name' => 'Opaque.Subject', 'searchname' => 'Hashed.Title']);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $title.'.mkv']);
        [$service, $updates] = $this->services();

        $updates->updateRelease($this->input($release), $title, 'fileCheck: Complete literal basename', true, 'Filenames, ', true, false);
        $renamed = $release->fresh()->searchname;
        self::assertSame(0, $release->fresh()->predb_id);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->never();
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->with($title, 21, 0)
            ->andReturn(['ids' => [$release->id], 'available' => true, 'has_more' => false]);

        $result = $service->matchPredbFulltextWithCompletion((object) ['title' => $title, 'predb_id' => 7, 'source' => 'later-arrival']);

        self::assertSame(['name' => $title, 'predb_id' => 7, 'matched' => true], [
            'name' => $renamed, 'predb_id' => $release->fresh()->predb_id, 'matched' => $result['matched'],
        ]);
        self::assertSame($title, $release->fresh()->searchname);
    }

    /** @return iterable<string, array{string, string}> */
    public static function filenameHandlerEvidence(): iterable
    {
        foreach (['files', 'standard', 'checkName'] as $handler) {
            foreach (['exact', 'stale', 'alias', 'ambiguous', 'duplicate', 'invalid', 'miss'] as $evidence) {
                yield $handler.' '.$evidence => [$handler, $evidence];
            }
        }
    }

    #[DataProvider('filenameHandlerEvidence')]
    public function test_filename_handlers_prioritize_authoritative_evidence_over_lossy_extraction(string $handler, string $evidence): void
    {
        config(['tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        $release = $this->pendingPar2Release([
            'name' => 'Original.Subject', 'searchname' => 'Hashed.Title', 'leftguid' => 'a',
            'passwordstatus' => 0, 'nfostatus' => 1, 'proc_nfo' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1, 'proc_par2' => 1,
        ]);
        $filename = self::TITLE.'.mkv';
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $hits = match ($evidence) {
            'miss' => [],
            'alias' => [['id' => 7, 'title' => str_replace('Rosalía', 'Rosalia', self::TITLE)]],
            'ambiguous' => [
                ['id' => 7, 'title' => 'Different.Movie.2026-GROUP', 'filename' => $filename],
                ['id' => 8, 'title' => 'Different.Movie.2025-OTHER', 'filename' => $filename],
            ],
            'duplicate' => [['id' => 7, 'title' => self::TITLE], ['id' => 8, 'title' => self::TITLE]],
            'invalid' => [['id' => 0, 'title' => self::TITLE]],
            default => [['id' => 7, 'title' => self::TITLE]],
        };
        DB::table('predb')->insert(['id' => 7, 'title' => in_array($evidence, ['exact', 'duplicate'], true) ? self::TITLE : 'Different.Movie.2026-GROUP']);
        Search::shouldReceive('searchPredb')->andReturn($hits);
        [$service] = $this->services();

        if ($handler === 'files') {
            $service->fixNamesWithFiles(0, true, 3, true, false);
        } elseif ($handler === 'standard') {
            $service->processStandardBatch('a', 10, false);
        } else {
            $input = $this->input($release);
            $input->textstring = $filename;
            $service->checkName($input, true, 'Filenames, ', true, false);
        }

        self::assertSame($evidence === 'exact' ? 7 : 0, $release->fresh()->predb_id);
        $expected = match ($evidence) {
            'exact' => self::TITLE,
            'miss' => self::TITLE,
            default => 'Hashed.Title',
        };
        self::assertSame($expected, $release->fresh()->searchname);
        if (! in_array($evidence, ['exact', 'miss'], true)) {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    public function test_cached_rejected_filename_evidence_never_becomes_a_legacy_fallback(): void
    {
        $first = $this->pendingPar2Release(['searchname' => 'Hashed.First']);
        $second = $this->pendingPar2Release(['searchname' => 'Hashed.Second']);
        foreach ([$first, $second] as $release) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => self::TITLE.'.mkv']);
        }
        Search::shouldReceive('searchPredb')->once()->andReturn([
            ['id' => 7, 'title' => str_replace('Rosalía', 'Rosalia', self::TITLE)],
        ]);
        [$service] = $this->services();

        $service->fixNamesWithFiles(0, true, 3, true, false);

        self::assertSame('Hashed.First', $first->fresh()->searchname);
        self::assertSame('Hashed.Second', $second->fresh()->searchname);
        self::assertSame(0, $first->fresh()->predb_id);
        self::assertSame(0, $second->fresh()->predb_id);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{bool}> */
    public static function mediaNamePaths(): iterable
    {
        yield 'canonical rename' => [false];
        yield 'same-name attachment' => [true];
    }

    #[DataProvider('mediaNamePaths')]
    public function test_media_movie_handler_preserves_literal_unicode_predb_identity(bool $sameName): void
    {
        $this->broadDiscoveryCollation();
        $uppercase = mb_strtoupper(self::TITLE, 'UTF-8');
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $release = $this->pendingPar2Release(['searchname' => $sameName ? $uppercase : 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => $uppercase]);
        [$service, $updates] = $this->services();

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($sameName ? $uppercase : self::TITLE, $release->fresh()->searchname);
        self::assertTrue($updates->matched);
        self::assertSame($sameName ? 0 : 1, $updates->fixed);
    }

    #[DataProvider('differentTitles')]
    public function test_media_movie_handler_rejects_collation_alias_evidence(string $different): void
    {
        $this->broadDiscoveryCollation();
        DB::table('predb')->insert(['id' => 7, 'title' => $different]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => self::TITLE]);
        [$service, $updates] = $this->services();

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertFalse($updates->matched);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_media_movie_handler_rejects_duplicate_exact_catalogue_identity(): void
    {
        DB::table('predb')->insert([['id' => 7, 'title' => self::TITLE], ['id' => 8, 'title' => self::TITLE]]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => self::TITLE]);
        [$service] = $this->services();

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_media_movie_handler_does_not_fall_back_after_authoritative_identity_changes(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => self::TITLE]);
        [$service, $updates] = $this->services();
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"') && str_contains($query, '"searchname" =')) {
                $changed = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'Different.Movie.2025-OTHER']);
            }
        });

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertTrue($changed);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertFalse($updates->matched);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMediaNames(): iterable
    {
        yield 'invalid utf8' => ["\xFF"];
        yield 'control bytes' => ["\0"];
        yield 'overlength' => [str_repeat('Long.', 60).'Movie.2026-GRP'];
    }

    #[DataProvider('invalidMediaNames')]
    public function test_media_movie_handler_rejects_invalid_exact_names(string $title): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => $title]);
        [$service] = $this->services();

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_media_movie_handler_retains_legacy_name_recovery_on_true_catalogue_miss(): void
    {
        $title = 'Example.Movie.2026.1080p.WEB-GRP';
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        DB::table('media_infos')->insert(['releases_id' => $release->id, 'movie_name' => $title]);
        [$service] = $this->services();

        $service->fixNamesWithMediaMovieName(0, true, 3, true, false);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame(1, $release->fresh()->proc_uid);
    }

    #[DataProvider('differentTitles')]
    public function test_filename_discovery_cannot_link_a_distinct_literal_identity(string $different): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = $this->input($release);
        $input->filename = self::TITLE.'.mkv';
        Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => $different, 'filename' => $different.'.mkv']]);
        [$service] = $this->services();

        $matched = $service->matchPreDbFiles($input, true, true, false);

        self::assertSame(0, $matched);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string}> */
    public static function differentTitles(): iterable
    {
        yield 'accent loss' => [str_replace('Rosalía', 'Rosalia', self::TITLE)];
        yield 'dropped letter' => [str_replace('Rosalía', 'Rosala', self::TITLE)];
        yield 'decomposed spelling' => [str_replace('í', "i\u{0301}", self::TITLE)];
        yield 'different accent' => [str_replace('í', 'ì', self::TITLE)];
        yield 'different year' => [str_replace('2026', '2025', self::TITLE)];
        yield 'different group' => [str_replace('-None', '-OTHER', self::TITLE)];
        yield 'different separators' => [str_replace('.', '_', self::TITLE)];
    }

    #[DataProvider('caseEquivalentPaths')]
    public function test_unicode_case_equivalent_paths_attach_without_renaming_or_replacing_links(string $path, int $existing): void
    {
        $uppercase = mb_strtoupper(self::TITLE, 'UTF-8');
        $release = $this->pendingPar2Release(['name' => 'Subject', 'searchname' => $uppercase, 'predb_id' => $existing]);
        $input = $this->input($release);
        $input->textstring = self::TITLE.'.mkv';
        $input->filename = $input->textstring;
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        [$service, $updates] = $this->services();
        if (in_array($path, ['filename', 'prefile'], true)) {
            Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => self::TITLE]]);
        }

        if ($path === 'donor') {
            $donor = (object) ['releases_id' => 99, 'relsize' => 5000, 'searchname' => self::TITLE, 'predb_id' => 7];
            $matched = (new ReflectionMethod(NameFixingService::class, 'applyDonorMatch'))->invoke($service, $input, [$donor], 5, 'hashCheck: PAR2', 'PAR2 hash, ', true, true, false);
        } elseif ($path === 'filename') {
            $matched = (new ReflectionMethod(NameFixingService::class, 'preDbFileCheck'))->invoke($service, $input, true, 'Filenames, ', true, false);
        } elseif ($path === 'prefile') {
            $matched = $service->matchPreDbFiles($input, true, true, false) === 1;
        } else {
            $input->textstring = self::TITLE;
            $matched = $service->checkName($input, true, 'PAR2, ', true, false);
        }

        self::assertSame($existing !== 999, $matched);
        self::assertSame($existing === 999 ? 999 : 7, $release->fresh()->predb_id);
        self::assertSame($uppercase, $release->fresh()->searchname);
        self::assertSame(0, $updates->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string, int}> */
    public static function caseEquivalentPaths(): iterable
    {
        foreach (['donor', 'filename', 'prefile', 'name'] as $path) {
            foreach ([0, 7, 999] as $existing) {
                yield $path.' link '.$existing => [$path, $existing];
            }
        }
    }

    public function test_exact_lookup_accepts_utf8_case_and_retains_canonical_database_bytes(): void
    {
        $this->broadDiscoveryCollation();
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        [, $updates] = $this->services();

        $match = $updates->checkPreDbMatch((object) [], mb_strtoupper(self::TITLE, 'UTF-8'));

        self::assertSame(['title' => self::TITLE, 'id' => 7], $match);
    }

    #[DataProvider('differentTitles')]
    public function test_exact_lookup_rejects_collation_aliases(string $different): void
    {
        $this->broadDiscoveryCollation();
        DB::table('predb')->insert(['id' => 7, 'title' => $different]);
        [, $updates] = $this->services();

        $match = $updates->checkPreDbMatch((object) [], self::TITLE);

        self::assertNull($match);
    }

    #[DataProvider('structuralFilenames')]
    public function test_literal_filename_identity_survives_documented_path_and_suffix_cleaning(string $title, string $filename): void
    {

        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = $this->input($release);
        $input->filename = $filename;
        Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => $title]]);
        [$service] = $this->services();

        self::assertSame(1, $service->matchPreDbFiles($input, true, true, false));
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
    }

    /** @return iterable<string, array{string, string}> */
    public static function structuralFilenames(): iterable
    {
        yield 'retained UTF8 publisher pair' => [self::TITLE, self::TITLE.'.mkv'];
        yield 'Unicode case in path' => [self::TITLE, 'Folder/'.mb_strtoupper(self::TITLE, 'UTF-8').'.MKV'];
        yield 'ASCII multipart archive' => ['Exact.Release.2026-GROUP', 'Folder\\Exact.Release.2026-GROUP.part01.rar'];
        $decomposed = str_replace('í', "i\u{0301}", self::TITLE);
        yield 'same decomposed bytes' => [$decomposed, $decomposed.'.mkv'];
        $maximum = str_repeat('É', 244).'.2026-GROUP';
        yield '255 character canonical title' => [$maximum, $maximum.'.mkv'];
    }

    #[DataProvider('invalidCanonicalTitles')]
    public function test_confirmed_predb_rename_rejects_invalid_canonical_titles(string $title): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title']);
        [, $updates] = $this->services();
        $updates->updateRelease($this->input($release), $title, 'preDB: Match', true, 'PAR2, ', true, false, 7);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Original.Title', $release->fresh()->searchname);
        self::assertFalse($updates->matched);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCanonicalTitles(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'overlong unicode' => [str_repeat('É', 256)];
        yield 'invalid UTF8' => ["Exact\xff.Release.2026-GROUP"];
        yield 'control' => ["Exact.\nRelease.2026-GROUP"];
        yield 'format control' => ["Exact.\u{200b}Release.2026-GROUP"];
    }

    #[DataProvider('confirmedWriteRaces')]
    public function test_confirmed_predb_rename_preserves_changed_release_snapshots(string $column, int|string $value): void
    {

        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title']);
        [, $updates] = $this->services();
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use ($release, $column, $value, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                DB::table('releases')->where('id', $release->id)->update([$column => $value]);
            }
        });
        $updates->updateRelease($this->input($release), self::TITLE, 'preDB: Match', true, 'PAR2, ', true, false, 7);

        self::assertTrue($changed);
        self::assertSame($value, $release->fresh()->getAttribute($column));
        self::assertSame($column === 'predb_id' ? $value : 0, $release->fresh()->predb_id);
        self::assertFalse($updates->matched);
        self::assertSame(0, $updates->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function confirmedWriteRaces(): iterable
    {
        yield 'replacement GUID' => ['guid', 'replacement'];
        yield 'subject changed' => ['name', 'replacement subject'];
        yield 'searchname changed' => ['searchname', 'replacement title'];
        yield 'competing link' => ['predb_id', 999];
        yield 'same link already committed' => ['predb_id', 7];
    }

    public function test_distinct_titles_sharing_one_exact_filename_are_ambiguous(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title']);
        $input = $this->input($release);
        $input->filename = 'Archive.File-GROUP.mkv';
        Search::shouldReceive('searchPredb')->once()->andReturn([
            ['id' => 7, 'title' => self::TITLE, 'filename' => $input->filename],
            ['id' => 8, 'title' => str_replace('2026', '2025', self::TITLE), 'filename' => $input->filename],
        ]);
        [$service] = $this->services();

        self::assertSame(0, $service->matchPreDbFiles($input, true, true, false));
        self::assertSame(0, $release->fresh()->predb_id);
    }

    public function test_literal_title_disambiguates_shared_filename_mapping(): void
    {

        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title']);
        $input = $this->input($release);
        $input->filename = self::TITLE.'.mkv';
        Search::shouldReceive('searchPredb')->once()->andReturn([
            ['id' => 8, 'title' => str_replace('2026', '2025', self::TITLE), 'filename' => $input->filename],
            ['id' => 7, 'title' => self::TITLE, 'filename' => $input->filename],
        ]);
        [$service] = $this->services();

        self::assertSame(1, $service->matchPreDbFiles($input, true, true, false));
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame(self::TITLE, $release->fresh()->searchname);
    }

    public function test_confirmed_filename_rename_does_not_report_a_conflicting_link_as_a_match(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title', 'predb_id' => 999]);
        $input = $this->input($release);
        $input->filename = self::TITLE.'.mkv';
        Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => self::TITLE]]);
        [$service] = $this->services();

        self::assertSame(0, $service->matchPreDbFiles($input, true, true, false));
        self::assertSame(999, $release->fresh()->predb_id);
        self::assertSame('Original.Title', $release->fresh()->searchname);
    }

    public function test_confirmed_rename_without_identity_fails_closed(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Original.Title']);
        $input = $this->input($release);
        unset($input->guid);
        [, $updates] = $this->services();

        $updates->updateRelease($input, self::TITLE, 'preDB: Match', true, 'PAR2, ', true, false, 7);

        self::assertSame(0, $release->fresh()->predb_id);
        self::assertFalse($updates->matched);
    }

    private function broadDiscoveryCollation(): void
    {
        DB::statement('DROP TABLE predb');
        DB::connection()->getPdo()->sqliteCreateCollation('DISCOVERY', static fn (string $left, string $right): int => 0);
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT COLLATE DISCOVERY)');
    }

    /** @return array{NameFixingService, ReleaseUpdateService} */
    private function services(): array
    {
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);

        return [new NameFixingService($updates), $updates];
    }

    private function input(Release $release): object
    {
        return (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'relsize' => 5000]);
    }
}
