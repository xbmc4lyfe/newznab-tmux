<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\ArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerifiedArticle;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\YencArticles;

final class SpotCheckVerifierTest extends TestCase
{
    #[Test]
    public function it_fetches_one_article_but_emits_every_article_for_every_file(): void
    {
        $files = [];
        $articles = [];
        $allIds = [];
        foreach (['movie.mkv', 'extra.rar'] as $name) {
            $segments = [];
            for ($part = 1; $part <= 3; $part++) {
                $id = $name.'-'.$part.'@article';
                $articles[$id] = YencArticles::part(str_repeat((string) $part, 5), $name, 15, $part, ($part - 1) * 5 + 1, 3);
                $segments[] = ['number' => $part, 'messageid' => $id, 'bytes' => 5];
                $allIds[] = $id;
            }
            $files[] = ['key' => $name, 'subject' => 'Release [1/2] "'.$name.'"', 'totalparts' => 3, 'segments' => $segments];
        }
        $manifest = ['identity' => sha1('complete-multipart'), 'subject' => 'Movie', 'fromname' => 'poster',
            'date' => '2026-10-12 00:00:00', 'groups_id' => 1, 'groups' => ['alt.test'],
            'expected_files' => 2, 'declared_size' => 999, 'files' => $files];
        $source = new class($articles) implements ArticleSource
        {
            public array $requests = [];

            public function __construct(private readonly array $articles) {}

            public function fetch(string $messageId): string
            {
                $this->requests[] = $messageId;

                return $this->articles[$messageId];
            }
        };
        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 1024,
            'spot_check_article_bytes' => 1024], new YencService))->verify($manifest, static function (): void {});

        self::assertSame('sampled', $result->verdict, $result->reason);
        self::assertCount(1, $source->requests);
        self::assertSame($files, $result->manifest['files']);
        self::assertSame(30, $result->manifest['publication_size'], 'Size comes from all stored article headers.');
        $writer = (new \ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
        $xml = simplexml_load_string($writer->document($result->manifest));
        $emittedIds = [];
        $rebuilt = [];
        foreach ($xml->file as $file) {
            $body = '';
            foreach ($file->segments->segment as $segment) {
                $id = (string) $segment;
                $emittedIds[] = $id;
                $article = VerifiedArticle::decode($articles[$id]);
                self::assertSame((int) $segment['number'], $article->part);
                self::assertSame((int) $segment['bytes'], strlen($article->bytes));
                $body .= $article->bytes;
            }
            $rebuilt[] = $body;
        }
        self::assertSame($allIds, $emittedIds);
        self::assertSame(['111112222233333', '111112222233333'], $rebuilt);
        self::assertCount(1, $source->requests, 'Fresh fixture reconstruction never fetches unsampled bodies.');
    }

    #[Test]
    public function incomplete_uploads_are_deferred_before_any_body_is_fetched(): void
    {
        $source = new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                TestCase::fail('Incomplete article headers must defer before fetching.');
            }
        };
        $file = ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 2,
            'segments' => [['number' => 1, 'messageid' => 'first@article', 'bytes' => 5]]];
        $verifier = new SpotCheckVerifier($source, []);
        $result = $verifier->verify(['expected_files' => 1, 'files' => [$file]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('article inventory is still uploading', $result->reason);
        $file['totalparts'] = 1;
        $result = $verifier->verify(['expected_files' => 2, 'files' => [$file]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('file inventory is still uploading', $result->reason);
        $duplicate = $file;
        $duplicate['key'] = 'other-file';
        $result = $verifier->verify(['expected_files' => 2, 'files' => [$file, $duplicate]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('duplicate article reference', $result->reason);
    }

    #[Test]
    public function a_sample_cannot_certify_inferred_headers_when_yenc_declares_more_parts(): void
    {
        $source = new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                return YencArticles::part('hello', 'movie.mkv', 500, 1, 1, 100);
            }
        };
        $manifest = ['identity' => sha1('inferred'), 'expected_files' => 1, 'files' => [
            ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1, 'totalparts_inferred' => true,
                'segments' => [['number' => 1, 'messageid' => 'first@article', 'bytes' => 5]]],
        ]];
        $result = (new SpotCheckVerifier($source, []))->verify($manifest, static function (): void {});

        self::assertSame('unknown', $result->verdict);
        self::assertFalse($result->passed());
    }

    #[Test]
    public function first_and_last_sample_offsets_must_cover_the_declared_file_endpoints(): void
    {
        foreach ([YencArticles::part('hello', 'movie.mkv', 100000, 1, 1, 1),
            YencArticles::part('hello', 'movie.mkv', 10, 1, 6, 1)] as $raw) {
            $source = new class($raw) implements ArticleSource
            {
                public function __construct(private readonly string $raw) {}

                public function fetch(string $messageId): string
                {
                    return $this->raw;
                }
            };
            $manifest = ['identity' => sha1('endpoint'), 'expected_files' => 1, 'files' => [
                ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
                    'segments' => [['number' => 1, 'messageid' => 'first@article', 'bytes' => 5]]],
            ]];
            $result = (new SpotCheckVerifier($source, []))->verify($manifest, static function (): void {});
            self::assertSame('unknown', $result->verdict);
        }
    }

    #[Test]
    public function sampled_payload_must_fit_header_octets_which_can_include_encoding_overhead(): void
    {
        $raw = (new YencService)->encode('hello', 'movie.mkv');
        $source = new class($raw) implements ArticleSource
        {
            public function __construct(private readonly string $raw) {}

            public function fetch(string $messageId): string
            {
                return $this->raw;
            }
        };
        foreach ([4 => 'unknown', strlen($raw) => 'sampled'] as $headerBytes => $verdict) {
            $manifest = ['identity' => sha1('octets'), 'expected_files' => 1, 'files' => [
                ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
                    'segments' => [['number' => 1, 'messageid' => 'first@article', 'bytes' => $headerBytes]]],
            ]];
            $result = (new SpotCheckVerifier($source, []))->verify($manifest, static function (): void {});
            self::assertSame($verdict, $result->verdict);
        }
    }

    #[Test]
    public function multiple_samples_reject_conflicting_identity_and_known_gaps_without_more_fetches(): void
    {
        foreach ([[10, 20, 16, 'unknown'], [20, 20, 16, 'unknown'], [10, 10, 6, 'sampled']] as [$firstSize, $lastSize, $lastBegin, $verdict]) {
            $articles = ['first@article' => YencArticles::part('hello', 'movie.mkv', $firstSize, 1, 1, 2),
                'last@article' => YencArticles::part('world', 'movie.mkv', $lastSize, 2, $lastBegin, 2)];
            $source = new class($articles) implements ArticleSource
            {
                public int $fetches = 0;

                public function __construct(private readonly array $articles) {}

                public function fetch(string $messageId): string
                {
                    $this->fetches++;

                    return $this->articles[$messageId];
                }
            };
            $manifest = ['identity' => sha1('neighbors'), 'expected_files' => 1, 'files' => [
                ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 2, 'segments' => [
                    ['number' => 1, 'messageid' => 'first@article', 'bytes' => 5],
                    ['number' => 2, 'messageid' => 'last@article', 'bytes' => 5],
                ]],
            ]];
            $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 2]))->verify($manifest, static function (): void {});
            self::assertSame($verdict, $result->verdict);
            self::assertLessThanOrEqual(2, $source->fetches);
        }
    }

    #[Test]
    public function it_passes_after_a_bounded_yenc_checked_sample_and_reports_the_limited_verdict(): void
    {
        $raw = YencArticles::part('hello', 'movie.mkv', 5, 1, 1, 1);
        $source = new class($raw) implements ArticleSource
        {
            public int $fetches = 0;

            public function __construct(private readonly string $raw) {}

            public function fetch(string $messageId): string
            {
                $this->fetches++;

                return $this->raw;
            }
        };
        $manifest = ['identity' => str_repeat('a', 40), 'subject' => 'Movie', 'fromname' => 'poster',
            'date' => '2026-10-12 00:00:00', 'groups_id' => 1, 'groups' => ['alt.test'],
            'expected_files' => 1, 'declared_size' => 100000,
            'files' => [['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'sample@article', 'bytes' => 5]]]]];

        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 1024,
            'spot_check_article_bytes' => 1024], new YencService))->verify($manifest, static function (): void {});

        self::assertTrue($result->passed());
        self::assertSame('sampled', $result->verdict);
        self::assertSame(1, $source->fetches);
        self::assertSame('spot_check', $result->manifest['verification_mode']);
        self::assertSame(1, $result->manifest['spot_check']['articles']);
        self::assertLessThanOrEqual(1024, $result->manifest['spot_check']['bytes']);
        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_recovers_explicit_spot_check_and_declared_file_count_metadata_from_nzb(): void
    {
        $xml = '<nzb><head><meta type="nntmux:verification-mode">spot-check</meta>'
            .'<meta type="nntmux:declared-file-count">2</meta></head>'
            .'<file subject="part.mkv"><groups><group>alt.test</group></groups>'
            .'<segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';

        $manifest = (new NzbManifestReader)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Movie',
            'fromname' => 'poster', 'postdate' => '2026-10-12 00:00:00']);

        self::assertSame('spot_check', $manifest['verification_mode']);
        self::assertSame(2, $manifest['expected_files']);
    }

    #[Test]
    public function payload_verifier_dispatches_to_spot_check_mode_without_creating_full_payload_scratch(): void
    {
        $raw = (new YencService)->encode('hello', 'movie.mkv');
        $source = new class($raw) implements ArticleSource
        {
            public function __construct(private readonly string $raw) {}

            public function fetch(string $messageId): string
            {
                return $this->raw;
            }
        };
        $manifest = ['identity' => str_repeat('d', 40), 'expected_files' => 1, 'declared_size' => 5,
            'files' => [['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'sample@article', 'bytes' => 5]]]]];
        $directory = sys_get_temp_dir().'/spot-check-dispatch-'.bin2hex(random_bytes(6));

        try {
            $result = (new PayloadVerifier($source, ['mode' => 'spot_check', 'spot_check_articles' => 1,
                'spot_check_bytes' => 1024, 'spot_check_article_bytes' => 1024], decoder: new YencService))
                ->verify($manifest, $directory, static function (): void {});

            self::assertSame('sampled', $result->verdict);
            self::assertDirectoryDoesNotExist($directory);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    #[Test]
    public function it_fails_when_a_sample_fails_yenc_integrity(): void
    {
        $raw = str_replace('pcrc32=', 'pcrc32=deadbeef', YencArticles::part('hello', 'movie.mkv', 5, 1, 1));
        $source = new class($raw) implements ArticleSource
        {
            public function __construct(private readonly string $raw) {}

            public function fetch(string $messageId): string
            {
                return $this->raw;
            }
        };
        $manifest = ['identity' => str_repeat('b', 40), 'expected_files' => 1,
            'files' => [['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'bad@article', 'bytes' => 5]]]]];

        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 1024,
            'spot_check_article_bytes' => 1024], new YencService))->verify($manifest, static function (): void {});

        self::assertFalse($result->passed());
        self::assertSame('unknown', $result->verdict);
    }

    #[Test]
    public function it_rejects_quoted_subject_filename_mismatch(): void
    {
        $raw = (new YencService)->encode('hello', 'unexpected.mkv');
        $source = new class($raw) implements ArticleSource
        {
            public function __construct(private readonly string $raw) {}

            public function fetch(string $messageId): string
            {
                return $this->raw;
            }
        };
        $manifest = ['identity' => str_repeat('e', 40), 'declared_size' => 5,
            'files' => [['key' => 'movie', 'subject' => '"expected.mkv" (1/1)', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'sample@article', 'bytes' => 5]]]]];

        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 1024,
            'spot_check_article_bytes' => 1024], new YencService))->verify($manifest, static function (): void {});

        self::assertSame('unknown', $result->verdict);
    }

    #[Test]
    public function it_returns_unknown_without_fetching_when_no_article_fits_the_budget(): void
    {
        $source = new class implements ArticleSource
        {
            public function fetch(string $messageId): string
            {
                self::fail('An article outside the budget must not be fetched.');
            }
        };
        $manifest = ['identity' => str_repeat('c', 40), 'files' => [['key' => 'movie', 'totalparts' => 1,
            'segments' => [['number' => 1, 'messageid' => 'large@article', 'bytes' => 1000]]]]];

        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 100,
            'spot_check_article_bytes' => 100], new YencService))->verify($manifest, static function (): void {});

        self::assertFalse($result->passed());
    }
}
