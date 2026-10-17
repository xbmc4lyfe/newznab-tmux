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
    public function it_spot_checks_an_incomplete_upload_only_when_partial_publication_is_allowed(): void
    {
        $articles = [];
        $files = [];
        foreach (['movie.mkv' => [1, 2, 3], 'extra.rar' => [1, 2]] as $name => $parts) {
            $segments = [];
            foreach ($parts as $part) {
                $id = $name.'-'.$part.'@article';
                $articles[$id] = YencArticles::part(str_repeat((string) $part, 5), $name, 15, $part, ($part - 1) * 5 + 1, 3);
                $segments[] = ['number' => $part, 'messageid' => $id, 'bytes' => 5];
            }
            $files[] = ['key' => $name, 'subject' => 'Release "'.$name.'"', 'totalparts' => 3, 'segments' => $segments];
        }
        // extra.rar is missing its third article and a third file was declared but never seen.
        $manifest = ['identity' => sha1('partial-upload'), 'subject' => 'Movie', 'fromname' => 'poster',
            'date' => '2026-10-12 00:00:00', 'groups_id' => 1, 'groups' => ['alt.test'], 'expected_files' => 3, 'files' => $files];
        $source = new class($articles) implements ArticleSource
        {
            public function __construct(private readonly array $articles) {}

            public function fetch(string $messageId): string
            {
                return $this->articles[$messageId];
            }
        };
        $verifier = new SpotCheckVerifier($source, ['spot_check_articles' => 1, 'spot_check_bytes' => 1024,
            'spot_check_article_bytes' => 1024], new YencService);

        $waiting = $verifier->verify($manifest, static function (): void {});
        self::assertSame('unknown', $waiting->verdict);
        self::assertTrue($waiting->waitingForHeaders);

        $partial = $verifier->verify($manifest, static function (): void {}, allowPartial: true);
        self::assertSame('sampled', $partial->verdict, $partial->reason);
        self::assertSame(['articles_found' => 5, 'articles_expected' => 6, 'files_found' => 2, 'files_expected' => 3],
            $partial->manifest['partial']);
        self::assertStringContainsString('5 of 6 articles found', $partial->reason);
        self::assertSame($files, $partial->manifest['files'], 'Only the articles that exist are published.');

        $manifest['files'][1]['segments'][1]['messageid'] = $manifest['files'][1]['segments'][0]['messageid'];
        $invalid = $verifier->verify($manifest, static function (): void {}, allowPartial: true);
        self::assertSame('unknown', $invalid->verdict, 'Invalid headers are never published, even partially.');
        self::assertFalse($invalid->waitingForHeaders);
    }

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
        self::assertTrue($result->waitingForHeaders);
        $file['totalparts'] = 1;
        $result = $verifier->verify(['expected_files' => 2, 'files' => [$file]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('file inventory is still uploading', $result->reason);
        self::assertTrue($result->waitingForHeaders);
        $duplicate = $file;
        $duplicate['key'] = 'other-file';
        $result = $verifier->verify(['expected_files' => 2, 'files' => [$file, $duplicate]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('duplicate article reference', $result->reason);
        self::assertFalse($result->waitingForHeaders);
        $duplicate['segments'][0]['messageid'] = 'other@article';
        $file['totalparts_inferred'] = $duplicate['totalparts_inferred'] = true;
        $result = $verifier->verify(['expected_files' => 2, 'files' => [$file, $duplicate]], static function (): void {});
        self::assertSame('unknown', $result->verdict);
        self::assertStringContainsString('within the spot-check budget', $result->reason);
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
    public function inferred_totals_without_yenc_total_require_a_proved_terminal_endpoint(): void
    {
        foreach ([15 => 'unknown', 10 => 'sampled'] as $fileSize => $verdict) {
            $raw = str_replace(' total=3', '', YencArticles::part('world', 'movie.mkv', $fileSize, 2, 6, 3));
            $source = new class($raw) implements ArticleSource
            {
                public array $requests = [];

                public function __construct(private readonly string $raw) {}

                public function fetch(string $messageId): string
                {
                    $this->requests[] = $messageId;

                    return $this->raw;
                }
            };
            $manifest = ['identity' => sha1('inferred-no-total'), 'expected_files' => 1, 'files' => [
                ['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 2, 'totalparts_inferred' => true, 'segments' => [
                    ['number' => 1, 'messageid' => 'first@article', 'bytes' => 5],
                    ['number' => 2, 'messageid' => 'last@article', 'bytes' => 5],
                ]],
            ]];
            $result = (new SpotCheckVerifier($source, []))->verify($manifest, static function (): void {});
            self::assertSame($verdict, $result->verdict);
            self::assertSame(['last@article'], $source->requests);
        }
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
    public function only_a_sole_quoted_32_hex_posting_token_has_unknown_filename_identity(): void
    {
        $token = str_repeat('a1', 16);
        foreach (['"'.$token.'"' => 'sampled', "'".strtoupper($token)."'" => 'sampled',
            '"'.substr($token, 1).'"' => 'unknown', '"'.$token.'a"' => 'unknown',
            '"'.str_repeat('g', 32).'"' => 'unknown', '"'.$token.'.rar"' => 'unknown',
            '"literal-extensionless-name"' => 'unknown', '"'.$token.'" "other.rar"' => 'unknown'] as $subject => $verdict) {
            $articles = ['first@article' => YencArticles::part('hello', 'actual.rar', 10, 1, 1, 2),
                'last@article' => YencArticles::part('world', 'actual.rar', 10, 2, 6, 2)];
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
            $files = [['key' => 'file', 'subject' => $subject, 'totalparts' => 2, 'segments' => [
                ['number' => 1, 'messageid' => 'first@article', 'bytes' => 5],
                ['number' => 2, 'messageid' => 'last@article', 'bytes' => 5],
            ]]];
            $manifest = ['identity' => sha1('opaque-subject'), 'expected_files' => 1, 'files' => $files,
                'fromname' => 'poster', 'date' => '2026-10-14', 'groups' => ['alt.test']];
            $result = (new SpotCheckVerifier($source, [], new YencService))->verify($manifest, static function (): void {});

            self::assertSame($verdict, $result->verdict, $result->reason);
            self::assertCount(1, $source->requests);
            if ($result->passed()) {
                self::assertSame($files, $result->manifest['files']);
                $writer = (new \ReflectionClass(VerifiedNzbWriter::class))->newInstanceWithoutConstructor();
                $xml = simplexml_load_string($writer->document($result->manifest));
                $emittedIds = [];
                foreach ($xml->file->segments->segment as $segment) {
                    $emittedIds[] = (string) $segment;
                }
                self::assertSame(['first@article', 'last@article'], $emittedIds);
            }
        }
    }

    #[Test]
    public function opaque_posting_tokens_preserve_article_integrity_and_dimension_checks(): void
    {
        $good = (new YencService)->encode('hello', 'actual.rar');
        $articles = [preg_replace('/crc32=[0-9a-f]+/i', 'crc32=00000000', $good),
            YencArticles::part('hello', 'actual.rar', 5, 2, 1, 2),
            YencArticles::part('hello', 'actual.rar', 5, 1, 1, 2),
            YencArticles::part('hello', 'actual.rar', 10, 1, 6, 1)];
        foreach ($articles as $raw) {
            $source = new class($raw) implements ArticleSource
            {
                public function __construct(private readonly string $raw) {}

                public function fetch(string $messageId): string
                {
                    return $this->raw;
                }
            };
            $manifest = ['identity' => sha1('opaque-invalid'), 'expected_files' => 1, 'files' => [
                ['key' => 'file', 'subject' => '"'.str_repeat('a1', 16).'"', 'totalparts' => 1,
                    'segments' => [['number' => 1, 'messageid' => 'sample@article', 'bytes' => 5]]],
            ]];
            $result = (new SpotCheckVerifier($source, [], new YencService))->verify($manifest, static function (): void {});

            self::assertSame('unknown', $result->verdict);
            self::assertFalse($result->passed());
        }
    }

    #[Test]
    public function opaque_posting_tokens_do_not_allow_conflicting_sampled_file_names(): void
    {
        $articles = ['first@article' => YencArticles::part('hello', 'actual.rar', 10, 1, 1, 2),
            'last@article' => YencArticles::part('world', 'other.rar', 10, 2, 6, 2)];
        $source = new class($articles) implements ArticleSource
        {
            public function __construct(private readonly array $articles) {}

            public function fetch(string $messageId): string
            {
                return $this->articles[$messageId];
            }
        };
        $manifest = ['identity' => sha1('opaque-conflict'), 'expected_files' => 1, 'files' => [
            ['key' => 'file', 'subject' => '"'.str_repeat('a1', 16).'"', 'totalparts' => 2, 'segments' => [
                ['number' => 1, 'messageid' => 'first@article', 'bytes' => 5],
                ['number' => 2, 'messageid' => 'last@article', 'bytes' => 5],
            ]],
        ]];
        $result = (new SpotCheckVerifier($source, ['spot_check_articles' => 2], new YencService))->verify($manifest, static function (): void {});

        self::assertSame('unknown', $result->verdict);
        self::assertSame('Spot-check articles have conflicting file identities.', $result->reason);
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

    public function test_remote_evidence_carries_a_partial_verdict_and_the_primary_recounts_it(): void
    {
        $stored = ['groups_id' => 1, 'expected_files' => 1, 'files' => [['key' => 'movie', 'subject' => 'Movie "movie.mkv"', 'totalparts' => 3,
            'segments' => [['number' => 1, 'messageid' => 'a@b', 'bytes' => 5]]]]];
        $sampled = SpotCheckVerifier::withEvidence($stored, ['articles' => 1, 'bytes' => 5, 'sampled_files' => 1,
            'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv'], 'partial' => true]);
        $evidence = SpotCheckVerifier::evidence($sampled);

        $this->assertTrue($evidence['partial']);
        // A sender's claimed counts never travel; the primary derives them from its stored manifest.
        $this->assertSame(['articles_found' => 1, 'articles_expected' => 3, 'files_found' => 1, 'files_expected' => 1],
            SpotCheckVerifier::withEvidence($stored, $evidence)['partial']);
        $this->assertFalse(SpotCheckVerifier::evidence(
            SpotCheckVerifier::withEvidence($stored, [...$evidence, 'partial' => false]))['partial']);
    }
}
