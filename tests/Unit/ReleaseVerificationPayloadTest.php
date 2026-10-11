<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\YencService;
use dariusiii\rarinfo\Par2Info;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;

class ReleaseVerificationPayloadTest extends TestCase
{
    public function test_decodes_validated_single_article_with_identity_and_offsets(): void
    {
        $raw = (new YencService)->encode('hello', 'movie.mkv');
        $article = VerifiedArticle::decode($raw);
        $this->assertSame('movie.mkv', $article->name);
        $this->assertSame(5, $article->size);
        $this->assertSame(0, $article->offset);
        $this->assertSame('hello', $article->bytes);
    }

    public function test_payload_requires_every_declared_file(): void
    {
        [$verifier, $directory] = $this->verifier(['a@b' => (new YencService)->encode('hello', 'movie.mkv')]);
        try {
            $result = $verifier->verify($this->manifest(2), $directory, static function (): void {});
            $this->assertFalse($result->passed());
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_full_download_is_required_and_unavailable_articles_remain_unknown(): void
    {
        [$verifier, $directory] = $this->verifier([]);
        try {
            $result = $verifier->verify($this->manifest(1), $directory, static function (): void {});
            $this->assertSame('unknown', $result->verdict);
            $this->assertArrayNotHasKey('identity_hints', $result->manifest);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_verified_download_passes_and_resumes_from_checksum_checked_article_cache(): void
    {
        [$verifier, $directory] = $this->verifier(['a@b' => (new YencService)->encode('hello', 'movie.mkv')]);
        try {
            $this->assertTrue($verifier->verify($this->manifest(1), $directory, static function (): void {})->passed());
            [$offline] = $this->verifier([]);
            $this->assertTrue($offline->verify($this->manifest(1), $directory, static function (): void {})->passed());
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_actual_par2_reconstructs_a_missing_file_before_passing(): void
    {
        $dir = sys_get_temp_dir().'/par2-fixture-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/movie.mkv', str_repeat('movie-data', 64));
        $process = new Process(['par2', 'c', '-q', '-t1', '-s64', '-r100', '-n1', '--', 'set.par2', 'movie.mkv'], $dir);
        $process->mustRun();
        $articles = [];
        $files = [['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
            'segments' => [['number' => 1, 'messageid' => 'missing@article', 'bytes' => 640]]]];
        foreach (glob($dir.'/*.par2') as $path) {
            $name = basename($path);
            $id = $name.'@article';
            $articles[$id] = (new YencService)->encode(file_get_contents($path), $name);
            $files[] = ['key' => $name, 'subject' => $name, 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => $id, 'bytes' => filesize($path)]]];
        }
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('repaired', $result->verdict, $result->reason);
            $this->assertContains(['name' => 'movie.mkv', 'source' => 'par2'], $result->manifest['identity_hints']);
            $this->assertSame(str_repeat('movie-data', 64), file_get_contents($scratch.'/data/movie.mkv'));
            $this->assertSame([], $result->manifest['files'][0]['segments']);
        } finally {
            $this->removeDirectory($dir);
            $this->removeDirectory($scratch);
        }
    }

    public function test_partial_redundant_recovery_volume_is_retained_as_available_repair_input(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.mkv', str_repeat('movie-data', 64), 'set');
        $volume = glob($fixture.'/*.vol*.par2')[0];
        $bytes = file_get_contents($volume);
        $partialName = 'set.vol99+1.par2';
        $articles['partial@article'] = YencArticles::part(substr($bytes, 0, -1), $partialName, strlen($bytes), 1, 1, 2);
        $files[] = ['key' => 'partial', 'subject' => $partialName, 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'partial@article', 'bytes' => strlen($bytes) - 1],
                ['number' => 2, 'messageid' => 'missing-parity@article', 'bytes' => 1]]];
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('repaired', $result->verdict, $result->reason);
            $this->assertContains($partialName, array_column($result->manifest['files'], 'subject'));
            $this->assertSame(count($files), $result->manifest['expected_files']);
            $this->assertNotContains($partialName, array_column($result->manifest['identity_hints'], 'name'));
            $this->assertSame(str_repeat('movie-data', 64), file_get_contents($scratch.'/data/movie.mkv'));
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    public function test_par2_inventory_can_prove_a_whole_source_missing_from_observed_headers(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.mkv', str_repeat('movie-data', 64), 'set');
        $expected = count($files);
        $files = array_slice($files, 1);
        foreach ($files as $index => &$file) {
            $file['subject'] = '['.($index + 2).'/'.$expected.'] - "'.$file['subject'].'"';
        }
        unset($file);
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => $expected, 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('repaired', $result->verdict, $result->reason);
            $this->assertContains(['name' => 'movie.mkv', 'source' => 'par2'], $result->manifest['identity_hints']);
            $this->assertCount(count($files), $result->manifest['files']);
            $this->assertSame(str_repeat('movie-data', 64), file_get_contents($scratch.'/data/movie.mkv'));
            $this->assertPublishedArticlesCanRepair($result->manifest, $articles, 'movie.mkv', str_repeat('movie-data', 64));
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    public function test_inferred_par2_sources_cannot_cover_a_larger_unproved_declared_inventory(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.mkv', str_repeat('movie-data', 64), 'set');
        $expected = count($files) + 1;
        $files = array_slice($files, 1);
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => $expected, 'files' => $files], $scratch, static function (): void {});
            $this->assertFalse($result->passed());
            $this->assertStringContainsString('inventory', $result->reason);
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    public function test_partial_recovery_articles_required_for_repair_remain_in_published_nzb(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.mkv', str_repeat('movie-data', 64), 'set');
        $volume = glob($fixture.'/*.vol*.par2')[0];
        $name = basename($volume);
        $bytes = file_get_contents($volume);
        unset($articles[$name.'@article']);
        $files = array_values(array_filter($files, static fn (array $file): bool => $file['subject'] !== $name));
        $articles['partial@article'] = YencArticles::part(substr($bytes, 0, -1), $name, strlen($bytes), 1, 1, 2);
        $files[] = ['key' => 'partial', 'subject' => $name, 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'partial@article', 'bytes' => strlen($bytes) - 1],
                ['number' => 2, 'messageid' => 'missing-parity@article', 'bytes' => 1]]];
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('repaired', $result->verdict, $result->reason);
            $this->assertSame(640 + filesize($fixture.'/set.par2') + strlen($bytes) - 1, $result->manifest['verified_bytes']);
            $this->assertPublishedArticlesCanRepair($result->manifest, $articles, 'movie.mkv', str_repeat('movie-data', 64));
            $this->assertContains('partial@article', array_merge(...array_map(static fn (array $file): array => array_column($file['segments'], 'messageid'), $result->manifest['files'])));
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $articles
     */
    private function assertPublishedArticlesCanRepair(array $manifest, array $articles, string $sourceName, string $sourceBytes): void
    {
        $manifest += ['fromname' => 'fixture', 'date' => '2026-10-11 12:00:00', 'groups' => ['alt.test']];
        $writer = (new \ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
        $xml = (new \ReflectionMethod($writer, 'document'))->invoke($writer, $manifest);
        $parsed = (new NzbManifestReader)->read($xml, (object) ['searchname' => 'fixture', 'fromname' => 'fixture',
            'postdate' => '2026-10-11 12:00:00', 'groups_id' => 1]);
        $publishedArticles = [];
        foreach ($parsed['files'] as $file) {
            foreach ($file['segments'] as $segment) {
                $this->assertArrayHasKey($segment['messageid'], $articles, 'Publication must never invent article IDs.');
                $publishedArticles[$segment['messageid']] = $articles[$segment['messageid']];
            }
        }
        [$verifier, $scratch] = $this->verifier($publishedArticles);
        try {
            $result = $verifier->verify($parsed, $scratch, static function (): void {});
            $this->assertTrue($result->passed(), 'Published NZB must independently reproduce repair: '.$result->reason);
            $this->assertSame($sourceBytes, file_get_contents($scratch.'/data/'.$sourceName));
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    public function test_checksum_pass_renews_lease_between_bounded_reads(): void
    {
        [$verifier, $scratch] = $this->verifier([]);
        $path = $scratch.'/large-source';
        file_put_contents($path, str_repeat('x', 3 * 1048576));
        $beats = 0;
        try {
            $checksum = (new \ReflectionMethod($verifier, 'checksum'))->invoke($verifier, $path, 'md5', static function () use (&$beats): void {
                $beats++;
            });
            $this->assertSame(md5(str_repeat('x', 3 * 1048576)), $checksum);
            $this->assertGreaterThanOrEqual(3, $beats);
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    public function test_foreign_creator_packet_cannot_authorize_omitting_foreign_recovery(): void
    {
        [$articles, $files, $primary] = $this->par2Upload('movie.mkv', str_repeat('movie-data', 64), 'primary');
        [$unused, $unusedFiles, $foreign] = $this->par2Upload('other.bin', str_repeat('other-data', 64), 'foreign');
        $parser = new Par2Info;
        $this->assertTrue($parser->open($foreign.'/foreign.par2'));
        $foreignId = hex2bin($parser->getPackets(true)[0]['head_set_id']);
        $body = $foreignId.Par2Info::PACKET_CREATOR."foreign creator\0";
        $creator = "PAR2\0PKT".pack('VV', 32 + strlen($body), 0).md5($body, true).$body;
        $articles['primary.par2@article'] = (new YencService)->encode(file_get_contents($primary.'/primary.par2').$creator, 'primary.par2');
        $bytes = file_get_contents(glob($foreign.'/*.vol*.par2')[0]);
        $articles['partial@article'] = YencArticles::part(substr($bytes, 0, -1), 'primary.vol99+1.par2', strlen($bytes), 1, 1, 2);
        $files[] = ['key' => 'partial', 'subject' => 'primary.vol99+1.par2', 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'partial@article', 'bytes' => strlen($bytes) - 1],
                ['number' => 2, 'messageid' => 'missing-parity@article', 'bytes' => 1]]];
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertFalse($result->passed(), $result->reason);
        } finally {
            $this->removeDirectory($primary);
            $this->removeDirectory($foreign);
            $this->removeDirectory($scratch);
        }
    }

    public function test_multipart_whole_file_checksum_is_verified(): void
    {
        $raw = (new YencService)->encode('hello', 'movie.mkv');
        $raw = str_replace('=ybegin line=', '=ybegin part=1 total=1 line=', $raw);
        $raw = str_replace("name=movie.mkv\r\n", "name=movie.mkv\r\n=ypart begin=1 end=5\r\n", $raw);
        $raw = str_replace('=yend size=5 crc32=3610a686', '=yend size=5 part=1 pcrc32=3610a686 crc32=00000000', $raw);
        [$verifier, $scratch] = $this->verifier(['a@b' => $raw]);
        try {
            $result = $verifier->verify($this->manifest(1), $scratch, static function (): void {});
            $this->assertFalse($result->passed());
            $this->assertStringContainsString('Whole-file', $result->reason);
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    public function test_unrelated_par2_cannot_replace_a_missing_required_file(): void
    {
        $dir = sys_get_temp_dir().'/par2-unrelated-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/other.bin', str_repeat('other', 64));
        (new Process(['par2', 'c', '-q', '-t1', '-s64', '-r100', '-n1', '--', 'set.par2', 'other.bin'], $dir))->mustRun();
        $articles = [];
        $files = [['key' => 'required', 'subject' => 'required.bin', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'missing@article', 'bytes' => 320]]]];
        foreach (glob($dir.'/*.par2') as $path) {
            $name = basename($path);
            $id = $name.'@article';
            $articles[$id] = (new YencService)->encode(file_get_contents($path), $name);
            $files[] = ['key' => $name, 'subject' => $name, 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id, 'bytes' => filesize($path)]]];
        }
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $this->assertFalse($verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {})->passed());
        } finally {
            $this->removeDirectory($dir);
            $this->removeDirectory($scratch);
        }
    }

    public function test_retained_other_candidate_counts_against_scratch_capacity(): void
    {
        [$verifier, $scratch] = $this->verifier(['a@b' => (new YencService)->encode('hello', 'movie.mkv')]);
        mkdir(dirname($scratch).'/old');
        file_put_contents(dirname($scratch).'/old/retained.bin', str_repeat('x', 1048576));
        try {
            $this->assertFalse($verifier->verify($this->manifest(1), $scratch, static function (): void {})->passed());
        } finally {
            $this->removeDirectory(dirname($scratch));
        }
    }

    public function test_declared_missing_segment_requires_par2_proof_even_if_bytes_cover_file(): void
    {
        [$verifier, $scratch] = $this->verifier(['a@b' => (new YencService)->encode('hello', 'movie.mkv')]);
        $manifest = $this->manifest(1);
        $manifest['files'][0]['totalparts'] = 2;
        try {
            $this->assertFalse($verifier->verify($manifest, $scratch, static function (): void {})->passed());
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    public function test_unsupported_encoding_stays_unknown_even_when_par2_cannot_repair(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.bin', str_repeat('A', 256), 'set-a');
        foreach (array_keys($articles) as $id) {
            if (str_contains($id, '.vol')) {
                unset($articles[$id]);
            }
        }
        $articles['source@article'] = 'unsupported plain text';
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('unknown', $result->verdict);
            $this->assertStringContainsString('Unsupported', $result->reason);
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    public function test_corrupt_source_checksum_can_be_repaired_by_actual_par2(): void
    {
        [$articles, $files, $fixture] = $this->par2Upload('movie.bin', str_repeat('A', 256), 'set-a');
        $articles['source@article'] = preg_replace('/crc32=[0-9a-f]+/', 'crc32=00000000', (new YencService)->encode(str_repeat('A', 256), 'movie.bin'));
        [$verifier, $scratch] = $this->verifier($articles);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertSame('repaired', $result->verdict, $result->reason);
        } finally {
            $this->removeDirectory($fixture);
            $this->removeDirectory($scratch);
        }
    }

    public function test_conflicting_par2_sets_cannot_replace_previously_verified_bytes(): void
    {
        [$articlesA, $filesA, $fixtureA] = $this->par2Upload('movie.bin', str_repeat('A', 256), 'set-a');
        [$articlesB, $filesB, $fixtureB] = $this->par2Upload('movie.bin', str_repeat('B', 256), 'set-b');
        $files = array_merge($filesA, array_slice($filesB, 1));
        [$verifier, $scratch] = $this->verifier($articlesA + $articlesB);
        try {
            $result = $verifier->verify(['expected_files' => count($files), 'files' => $files], $scratch, static function (): void {});
            $this->assertFalse($result->passed());
            $this->assertStringContainsString('Conflicting PAR2', $result->reason);
        } finally {
            $this->removeDirectory($fixtureA);
            $this->removeDirectory($fixtureB);
            $this->removeDirectory($scratch);
        }
    }

    /** @return array{array<string, string>, list<array<string, mixed>>, string} */
    private function par2Upload(string $name, string $bytes, string $set): array
    {
        $directory = sys_get_temp_dir().'/par2-test-'.bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory.'/'.$name, $bytes);
        (new Process(['par2', 'c', '-q', '-t1', '-s64', '-r100', '-n1', '--', $set.'.par2', $name], $directory))->mustRun();
        $articles = [];
        $files = [['key' => 'source', 'subject' => $name, 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'source@article', 'bytes' => strlen($bytes)]]]];
        foreach (glob($directory.'/*.par2') as $path) {
            $filename = basename($path);
            $id = $filename.'@article';
            $articles[$id] = (new YencService)->encode(file_get_contents($path), $filename);
            $files[] = ['key' => $filename, 'subject' => $filename, 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id, 'bytes' => filesize($path)]]];
        }

        return [$articles, $files, $directory];
    }

    public function test_capacity_limit_does_not_turn_into_a_success(): void
    {
        [$unused, $scratch] = $this->verifier([]);
        $source = new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                return (new YencService)->encode(str_repeat('x', 10000), 'movie.mkv');
            }
        };
        $verifier = new PayloadVerifier($source,
            ['scratch_bytes' => 100, 'free_bytes' => 0, 'article_bytes' => 16777216, 'bytes_per_second' => 0, 'par2_binary' => 'par2']);
        try {
            $this->assertFalse($verifier->verify($this->manifest(1), $scratch, static function (): void {})->passed());
        } finally {
            $this->removeDirectory($scratch);
        }
    }

    /** @return array<string, mixed> */
    private function manifest(int $expected): array
    {
        return ['expected_files' => $expected, 'files' => [['key' => '1', 'subject' => 'movie.mkv', 'totalparts' => 1,
            'segments' => [['number' => 1, 'messageid' => 'a@b', 'bytes' => 5]]]]];
    }

    /** @param array<string, string> $articles
     * @return array{0: PayloadVerifier, 1: string}
     */
    private function verifier(array $articles): array
    {
        $source = new class($articles) implements ArticleSource
        {
            /** @param array<string, string> $articles */
            public function __construct(private array $articles) {}

            public function fetch(string $messageId): string
            {
                return $this->articles[$messageId] ?? throw new \RuntimeException('Unavailable');
            }
        };
        $dir = sys_get_temp_dir().'/verify-'.bin2hex(random_bytes(8)).'/candidate';
        mkdir($dir, 0700, true);

        return [new PayloadVerifier($source,
            ['scratch_bytes' => 1048576, 'free_bytes' => 0, 'article_bytes' => 16777216,
                'bytes_per_second' => 0, 'par2_binary' => 'par2']), $dir];
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

    public function test_missing_checksum_cannot_prove_integrity(): void
    {
        $this->expectException(\RuntimeException::class);
        VerifiedArticle::decode((new YencService)->encode('hello', 'movie.mkv', 128, false));
    }

    public function test_unsafe_filename_is_never_written_to_disk(): void
    {
        $this->expectException(\RuntimeException::class);
        VerifiedArticle::decode((new YencService)->encode('hello', '../movie.mkv'));
    }

    public function test_corrupt_bytes_cannot_pass(): void
    {
        $this->expectException(\RuntimeException::class);
        VerifiedArticle::decode(str_replace('crc32=3610a686', 'crc32=00000000', (new YencService)->encode('hello', 'movie.mkv')));
    }
}
