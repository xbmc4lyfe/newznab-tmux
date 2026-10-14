<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\YencService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;

class Par2ProofCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/par2-proof-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function test_equivalent_volumes_share_one_real_proof_and_replay_reproves(): void
    {
        [$verifier, $manifest] = $this->fixture();
        $first = $verifier->verify($manifest, $this->root.'/scratch', static function (): void {});
        $this->assertSame('complete', $first->verdict, $first->reason);
        $this->assertSame(['v'], $this->operations());
        $second = $verifier->verify($manifest, $this->root.'/scratch', static function (): void {});
        $this->assertSame($first->manifest, $second->manifest);
        $this->assertSame(['v', 'v'], $this->operations());
    }

    public function test_repair_still_requires_verify_repair_verify_before_reusing_proof(): void
    {
        [$verifier, $manifest] = $this->fixture(missing: true);
        $result = $verifier->verify($manifest, $this->root.'/scratch', static function (): void {});
        $this->assertSame('repaired', $result->verdict, $result->reason);
        $this->assertSame(['v', 'r', 'v'], $this->operations());
        $this->assertSame(str_repeat('payload-data', 128), file_get_contents($this->root.'/scratch/data/payload.bin'));
    }

    public function test_later_corrupt_volume_is_not_hidden_by_a_cached_proof(): void
    {
        [$verifier, $manifest] = $this->fixture(corruptLast: true);
        $result = $verifier->verify($manifest, $this->root.'/scratch', static function (): void {});
        $this->assertFalse($result->passed());
        $this->assertSame('Invalid PAR2 metadata.', $result->reason);
    }

    public function test_an_intervening_repair_invalidates_previously_proved_sets(): void
    {
        [$verifier, $manifest] = $this->fixture(repairBetween: true);
        $result = $verifier->verify($manifest, $this->root.'/scratch', static function (): void {});
        $this->assertSame('repaired', $result->verdict, $result->reason);
        $this->assertSame(['v', 'v', 'r', 'v', 'v'], $this->operations());
    }

    public function test_final_integrity_check_rejects_mutation_after_a_successful_proof(): void
    {
        [$verifier, $manifest] = $this->fixture(multipart: true);
        $mutated = false;
        $result = $verifier->verify($manifest, $this->root.'/scratch', function () use (&$mutated): void {
            if (! $mutated && is_file($this->root.'/operations') && $this->operations() === ['v']) {
                $path = $this->root.'/scratch/data/payload.bin';
                foreach (get_resources('stream') as $handle) {
                    // Mutate after the first proof's read, before its hash is returned.
                    if ((stream_get_meta_data($handle)['uri'] ?? null) === $path && ftell($handle) === filesize($path)) {
                        file_put_contents($path, str_repeat('changed-data', 128));
                        $mutated = true;
                        break;
                    }
                }
            }
        });
        $this->assertTrue($mutated);
        $this->assertFalse($result->passed());
        $this->assertSame('Final PAR2 integrity check failed.', $result->reason);
    }

    /** @return array{PayloadVerifier, array<string, mixed>} */
    private function fixture(bool $missing = false, bool $corruptLast = false, bool $repairBetween = false, bool $multipart = false): array
    {
        $bytes = str_repeat('payload-data', 128);
        file_put_contents($this->root.'/payload.bin', $bytes);
        (new Process(['par2', 'c', '-q', '-t1', '-s64', '-r100', '-n4', '--', 'set.par2', 'payload.bin'], $this->root))->mustRun();
        if ($repairBetween) {
            file_put_contents($this->root.'/other.bin', 'other-source');
            (new Process(['par2', 'c', '-q', '-t1', '-s4', '-r100', '-n1', '--', 'other.par2', 'other.bin'], $this->root))->mustRun();
        }
        $binary = trim((new Process(['sh', '-c', 'command -v par2']))->mustRun()->getOutput());
        $wrapper = $this->root.'/par2-wrapper';
        file_put_contents($wrapper, "#!/bin/sh\nprintf '%s\\n' \"\$1\" >> ".escapeshellarg($this->root.'/operations')."\nexec ".escapeshellarg($binary).' "$@"'."\n");
        chmod($wrapper, 0700);
        $articles = $missing ? [] : ['payload@article' => $multipart
            ? YencArticles::part($bytes, 'payload.bin', strlen($bytes), 1, 1, 1)
            : (new YencService)->encode($bytes, 'payload.bin')];
        $files = [['subject' => 'payload.bin', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'payload@article']]]];
        $parity = glob($this->root.'/*.par2');
        if ($repairBetween) {
            $files[] = ['subject' => 'other.bin', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'missing-other']]];
            $parity = [$this->root.'/set.par2', $this->root.'/other.par2', ...glob($this->root.'/set.vol*.par2'), ...glob($this->root.'/other.vol*.par2')];
        }
        foreach ($parity as $index => $path) {
            $raw = file_get_contents($path);
            if ($corruptLast && $index === count($parity) - 1) {
                $raw[16] = chr(ord($raw[16]) ^ 1);
            }
            $name = basename($path);
            $articles[$name] = (new YencService)->encode($raw, $name);
            $files[] = ['subject' => $name, 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $name]]];
        }
        $source = new class($articles) implements ArticleSource
        {
            /** @param array<string, string> $articles */
            public function __construct(private readonly array $articles) {}

            public function fetch(string $messageId): string
            {
                return $this->articles[$messageId] ?? throw new \RuntimeException('Unavailable');
            }
        };

        return [new PayloadVerifier($source, ['scratch_bytes' => 1048576, 'free_bytes' => 0, 'article_bytes' => 16777216,
            'bytes_per_second' => 0, 'par2_binary' => $wrapper]), ['expected_files' => count($files), 'files' => $files]];
    }

    /** @return list<string> */
    private function operations(): array
    {
        return file($this->root.'/operations', FILE_IGNORE_NEW_LINES) ?: [];
    }
}
