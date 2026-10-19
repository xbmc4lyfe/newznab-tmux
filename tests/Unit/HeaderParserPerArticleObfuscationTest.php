<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use PHPUnit\Framework\TestCase;

class HeaderParserPerArticleObfuscationTest extends TestCase
{
    private function parser(): HeaderParser
    {
        return new HeaderParser(new class extends BlacklistService
        {
            public function __construct() {}

            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        });
    }

    /** @return array<string, mixed> */
    private function header(int $number, string $subject): array
    {
        return ['Number' => $number, 'Subject' => $subject, 'From' => 'p'.$number.'@example.com', 'Date' => 0, 'Bytes' => 100];
    }

    private function token(int $seed): string
    {
        return substr(str_replace(['+', '/', '='], '', base64_encode(hash('sha256', (string) $seed, true))), 0, 32);
    }

    /**
     * Random quoted name per article with a constant file counter, as seen in alt.binaries.kenpsx.
     *
     * @return list<array<string, mixed>>
     */
    private function quotedObfuscated(int $first, int $count, int $totalParts = 687): array
    {
        $headers = [];
        for ($i = 0; $i < $count; $i++) {
            $headers[] = $this->header($first + $i, '[40/62] "'.$this->token($first + $i).'" yEnc ('.($i + 1).'/'.$totalParts.')');
        }

        return $headers;
    }

    public function test_random_subject_per_article_is_marked_and_skipped(): void
    {
        $parser = $this->parser();
        $headers = $this->quotedObfuscated(1000, 16);

        $this->assertSame(16, $parser->markPerArticleObfuscated($headers, 16));
        $result = $parser->parse($headers, 'alt.binaries.kenpsx');

        $this->assertSame([], $result['headers']);
        $this->assertSame(16, $result['obfuscated']);
        $this->assertCount(16, $result['received']);
    }

    public function test_bracketed_prefix_obfuscation_without_file_counter_is_marked(): void
    {
        $headers = [];
        for ($i = 0; $i < 20; $i++) {
            $prefix = substr($this->token($i + 500), 0, 2);
            $headers[] = $this->header(2000 + $i, '['.$prefix.'] - '.$this->token($i).'abcdefgh yEnc ('.($i + 3).'/1250)');
        }

        $this->assertSame(20, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_group_below_threshold_is_kept(): void
    {
        $parser = $this->parser();
        $headers = $this->quotedObfuscated(1000, 15);

        $this->assertSame(0, $parser->markPerArticleObfuscated($headers, 16));
        $this->assertCount(15, $parser->parse($headers, 'alt.binaries.kenpsx')['headers']);
    }

    public function test_a_stray_duplicate_does_not_shield_the_group(): void
    {
        $headers = $this->quotedObfuscated(1000, 30);
        $headers[] = $this->header(1100, $headers[0]['Subject']);

        $this->assertSame(29, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_a_real_upload_sharing_an_obfuscated_group_keeps_its_parts(): void
    {
        $parser = $this->parser();
        $headers = $this->quotedObfuscated(1000, 400);
        for ($part = 1; $part <= 20; $part++) {
            $headers[] = $this->header(2000 + $part, '[40/62] "'.$this->token(99999).'" yEnc ('.$part.'/687)');
        }

        $this->assertSame(400, $parser->markPerArticleObfuscated($headers, 16));
        $this->assertCount(20, $parser->parse($headers, 'alt.binaries.kenpsx')['headers']);
    }

    public function test_hex_tokens_stay_distinct_across_a_large_range(): void
    {
        $headers = [];
        for ($i = 0; $i < 20000; $i++) {
            $headers[] = $this->header(10000 + $i, '[40/62] "'.md5((string) $i).'" yEnc ('.($i % 687 + 1).'/687)');
        }

        $this->assertSame(20000, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_mostly_repeating_names_keep_the_group(): void
    {
        $headers = [];
        for ($i = 0; $i < 40; $i++) {
            // Twenty files posted with two articles each in the range.
            $headers[] = $this->header(7000 + $i, '[40/62] "'.md5((string) intdiv($i, 2)).'" yEnc ('.($i % 2 + 1).'/687)');
        }

        $this->assertSame(0, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_legitimate_uploads_with_the_same_shape_are_kept(): void
    {
        $headers = [];
        $number = 3000;
        // Twenty rar volumes with identical part totals: each base name repeats per part.
        for ($volume = 1; $volume <= 20; $volume++) {
            for ($part = 1; $part <= 3; $part++) {
                $headers[] = $this->header($number++, sprintf('[%02d/20] - "Some.Show.S01E01.part%02d.rar" yEnc (%d/50)', $volume, $volume, $part));
            }
        }

        $this->assertSame(0, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_round_robin_volumes_with_one_article_each_are_kept(): void
    {
        $plain = [];
        $hashed = [];
        $token = md5('upload');
        for ($volume = 1; $volume <= 20; $volume++) {
            $plain[] = $this->header(5000 + $volume, sprintf('"Some.Show.S01E01.part%02d.rar" yEnc (7/120)', $volume));
            $hashed[] = $this->header(6000 + $volume, sprintf('Show - "%s.part%02d.rar" yEnc (7/120)', $token, $volume));
        }

        $this->assertSame(0, $this->parser()->markPerArticleObfuscated($plain, 16));
        $this->assertSame(0, $this->parser()->markPerArticleObfuscated($hashed, 16));
    }

    public function test_configured_threshold_is_disabled_by_zero_and_floored_at_eight(): void
    {
        $this->assertSame(0, BinariesConfig::obfuscationMinArticles(0));
        $this->assertSame(0, BinariesConfig::obfuscationMinArticles(-3));
        $this->assertSame(8, BinariesConfig::obfuscationMinArticles(1));
        $this->assertSame(16, BinariesConfig::obfuscationMinArticles(16));
    }

    public function test_single_part_files_are_never_marked(): void
    {
        $headers = [];
        for ($i = 0; $i < 40; $i++) {
            $headers[] = $this->header(4000 + $i, '"'.$this->token($i).'.jpg" yEnc (1/1)');
        }

        $this->assertSame(0, $this->parser()->markPerArticleObfuscated($headers, 16));
    }

    public function test_zero_threshold_disables_and_clears_earlier_marks(): void
    {
        $parser = $this->parser();
        $headers = $this->quotedObfuscated(1000, 16);
        $parser->markPerArticleObfuscated($headers, 16);

        $this->assertSame(0, $parser->markPerArticleObfuscated($headers, 0));
        $this->assertCount(16, $parser->parse($headers, 'alt.binaries.kenpsx')['headers']);
    }

    public function test_mixed_range_skips_only_the_obfuscated_articles(): void
    {
        $parser = $this->parser();
        $headers = $this->quotedObfuscated(1000, 16);
        $headers[] = $this->header(1500, '[01/02] - "Real.Release.part1.rar" yEnc (1/2)');
        $headers[] = $this->header(1501, '[01/02] - "Real.Release.part1.rar" yEnc (2/2)');

        $parser->markPerArticleObfuscated($headers, 16);
        $result = $parser->parse($headers, 'alt.test');

        $this->assertSame([1500, 1501], array_column($result['headers'], 'Number'));
        $this->assertSame(16, $result['obfuscated']);
    }
}
