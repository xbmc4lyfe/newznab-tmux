<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\YencService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
