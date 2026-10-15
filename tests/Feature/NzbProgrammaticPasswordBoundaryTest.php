<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\PreparedManifest;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Reuses the isolated SQLite/storage fixture; all values are synthetic. */
class NzbProgrammaticPasswordBoundaryTest extends ReleaseVerificationCandidatesTest
{
    /** @return array<string, array{mixed}> */
    public static function invalidProgrammaticValues(): array
    {
        return [
            'null' => [null], 'scalar' => ['fixture'], 'integer' => [42],
            'sparse' => [[1 => 'fixture']], 'associative' => [['named' => 'fixture']],
            'nonstring integer' => [[42]], 'nonstring null' => [[null]],
            'nonstring boolean' => [[false]], 'nonstring array' => [[[]]],
            '65 entries' => [array_fill(0, 65, 'fixture')],
            '4097 ASCII bytes' => [[str_repeat('x', 4097)]],
            '4097 Unicode bytes' => [[str_repeat('é', 2048).'x']],
            'invalid UTF8' => [["fixture\xFFvalue"]], 'surrogate' => [["\xED\xA0\x80"]],
            'NUL' => [["fixture\0value"]], 'XML control' => [["fixture\x01value"]],
            'FFFE' => [["\u{FFFE}"]], 'FFFF' => [["\u{FFFF}"]],
        ];
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidProgrammaticAdmissionContexts(): array
    {
        $cases = [];
        foreach (self::invalidProgrammaticValues() as $name => [$value]) {
            foreach (['empty', 'existing', 'other candidate', 'deleted publication'] as $context) {
                $cases[$name.' '.$context] = [$value, $context];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidProgrammaticAdmissionContexts')]
    public function test_programmatic_admission_rejects_without_mutation(mixed $values, string $context): void
    {
        $repository = app(CandidateRepository::class);
        $manifest = $this->programmaticManifest();
        if ($context !== 'empty') {
            $seed = $manifest;
            if ($context === 'other candidate') {
                $seed['identity'] = sha1('unrelated fixture');
            }
            $this->assertTrue($repository->enqueue($seed));
            if ($context === 'deleted publication') {
                Schema::create('releases', static fn (Blueprint $table) => $table->id());
                DB::table('release_verification_candidates')->update(['release_id' => 42, 'status' => 'complete']);
            }
        }
        $state = DB::table('release_verification_state')->get()->all();
        $rows = DB::table('release_verification_candidates')->get()->all();
        $artifacts = $this->programmaticArtifacts();
        $this->assertFalse($repository->enqueue($manifest + ['passwords' => $values]));
        $this->assertEquals($state, DB::table('release_verification_state')->get()->all());
        $this->assertEquals($rows, DB::table('release_verification_candidates')->get()->all());
        $this->assertSame($artifacts, $this->programmaticArtifacts());
    }

    #[DataProvider('invalidProgrammaticValues')]
    public function test_programmatic_preparation_rejects_before_json_substitution(mixed $values): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid candidate password metadata.');
        new PreparedManifest(['passwords' => $values]);
    }

    #[DataProvider('invalidProgrammaticValues')]
    public function test_programmatic_writer_rejects_before_serializing(mixed $values): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid verified NZB password metadata.');
        (new VerifiedNzbWriter(app(NzbService::class)))->document(['files' => [], 'passwords' => $values]);
    }

    /** @return array<string, array{list<string>}> */
    public static function validProgrammaticValues(): array
    {
        $values = ['', " \t\n\r ", '<fixture> & "quote"', "fixture\u{1F680}", str_repeat('é', 2048), str_repeat('x', 4096)];

        return ['empty list' => [[]], '64 exact entries' => [array_pad($values, 64, 'repeated fixture')]];
    }

    #[DataProvider('validProgrammaticValues')]
    public function test_programmatic_valid_boundaries_round_trip_exactly(array $values): void
    {
        $manifest = $this->programmaticManifest() + ['passwords' => $values];
        $prepared = new PreparedManifest($manifest);
        $this->assertSame($manifest, json_decode(gzdecode($prepared->compressed), true, flags: JSON_THROW_ON_ERROR));
        $this->assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        $stored = app(ManifestStore::class)->read(DB::table('release_verification_candidates')->value('manifest_path'));
        $this->assertSame($manifest, $stored);
        $xml = (new VerifiedNzbWriter(app(NzbService::class)))->document($stored);
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document);
        $actual = [];
        foreach ($document->head->meta ?? [] as $metadata) {
            if ((string) $metadata['type'] === 'password') {
                $actual[] = (string) $metadata;
            }
        }
        $this->assertSame($values, $actual);
        $roundTrip = app(NzbManifestReader::class)->read($xml, $this->programmaticRelease());
        $this->assertSame($values, $roundTrip['passwords'] ?? []);
    }

    public static function suppliedPasswordInventoryModes(): array
    {
        return ['full verification' => [false], 'spot check' => [true]];
    }

    #[DataProvider('suppliedPasswordInventoryModes')]
    public function test_writer_preserves_articles_and_exclusive_inventory_labels_with_supplied_passwords(bool $spot): void
    {
        $passwords = ['first assertion', '', 'first assertion', 'second assertion'];
        $manifest = $this->programmaticManifest() + ['passwords' => $passwords];
        $manifest['expected_files'] = 3;
        $manifest['verified_file_count'] = 2;
        if ($spot) {
            $manifest['verification_mode'] = 'spot_check';
        }
        self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        $stored = app(ManifestStore::class)->read(DB::table('release_verification_candidates')->value('manifest_path'));
        $xml = app(VerifiedNzbWriter::class)->document($stored);
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        $document->registerXPathNamespace('n', 'http://www.newzbin.com/DTD/2003/nzb');
        $declared = $document->xpath('/n:nzb/n:head/n:meta[@type="nntmux:declared-file-count"]');
        $verified = $document->xpath('/n:nzb/n:head/n:meta[@type="nntmux:verified-file-count"]');
        $mode = $document->xpath('/n:nzb/n:head/n:meta[@type="nntmux:verification-mode"]');
        self::assertCount($spot ? 1 : 0, $declared);
        self::assertCount($spot ? 0 : 1, $verified);
        self::assertSame('3', (string) ($spot ? $declared[0] : $verified[0]));
        self::assertSame($spot ? ['spot-check'] : [], array_map(strval(...), $mode));
        $roundTrip = app(NzbManifestReader::class)->read($xml, $this->programmaticRelease());
        self::assertSame($passwords, $roundTrip['passwords']);
        $ids = static fn (array $value): array => array_merge(...array_map(
            static fn (array $file): array => array_column($file['segments'], 'messageid'), $value['files']));
        self::assertSame($ids($manifest), $ids($roundTrip));
    }

    private function programmaticManifest(): array
    {
        $xml = '<nzb><file subject="Fixture [1/1] &quot;fixture.rar&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@example.test</segment></segments></file></nzb>';

        return app(NzbManifestReader::class)->read($xml, $this->programmaticRelease()) + ['identity' => sha1('fixture import articles')];
    }

    private function programmaticRelease(): object
    {
        return (object) ['groups_id' => 1, 'searchname' => 'Fixture', 'fromname' => 'fixture poster',
            'postdate' => '2026-10-11 12:00:00', 'metadata_source' => 'import'];
    }

    private function programmaticArtifacts(): array
    {
        $artifacts = [];
        foreach (glob(config('release_verification.storage_path').'/manifests/*') ?: [] as $path) {
            $artifacts[basename($path)] = hash_file('sha256', $path);
        }
        ksort($artifacts);

        return $artifacts;
    }
}
