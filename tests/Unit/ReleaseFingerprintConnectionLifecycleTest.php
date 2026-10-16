<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FingerprintCollectionNntpHarness;
use Tests\Fixtures\FingerprintCollectionReuseNntpHarness;
use Tests\Fixtures\YencArticles;

final class ReleaseFingerprintConnectionLifecycleTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['nntmux_nntp' => [
            'server' => 'primary.fixture.invalid', 'port' => 119, 'ssl' => false,
            'alternate_server' => 'alternate.fixture.invalid',
            'alternate_server_port' => 563, 'alternate_server_ssl' => true,
        ]]));
        Container::setInstance($container);
        Carbon::setTestNow(Carbon::parse('2026-10-16T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance($this->previousContainer);
    }

    public function test_baseline_collection_opens_three_fresh_alternate_clients_for_profile_and_two_samples(): void
    {
        $this->assertHealthyCollectionLifecycle(3);
    }

    public function test_empty_par2_set_does_not_emit_a_null_offset_deprecation(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== E_DEPRECATED) {
                return false;
            }
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);
        try {
            $result = $this->assertHealthyCollectionLifecycle(3);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result['data']['par2']['present']);
        $this->assertSame([], $result['data']['par2']['set_ids']);
        $this->assertSame([], $result['data']['par2']['files']);
        $this->assertSame([], $deprecations, 'An empty PAR2 set must preserve its metadata without emitting a null-offset deprecation.');
    }

    public function test_collection_reuses_one_alternate_client_for_profile_and_two_samples(): void
    {
        $baseline = $this->assertHealthyCollectionLifecycle(3);
        $candidate = $this->assertHealthyCollectionLifecycle(1);
        $reports = [$baseline, $candidate];
        foreach ($reports as &$result) {
            foreach ($result['data']['article_observations'] as &$observation) {
                unset($observation['retrieval_duration_ms']);
            }
            unset($observation);
        }
        unset($result);
        $this->assertSame($reports[0], $reports[1]);
    }

    /** @return array{status: string, data: array<string, mixed>} */
    private function assertHealthyCollectionLifecycle(int $expectedAlternateClients): array
    {
        $owned = [];
        try {
            $primaryWire = $this->profileWire().$this->articleWire('first', 'primary').$this->articleWire('second', 'primary');
            if ($expectedAlternateClients === 1) {
                $owned[] = $primary = new FingerprintCollectionReuseNntpHarness($primaryWire);
                $owned[] = $profile = new FingerprintCollectionReuseNntpHarness(
                    $this->profileWire().$this->articleWire('first', 'alternate').$this->articleWire('second', 'alternate'), true,
                );
                $primary->queueAlternates([$profile]);
                $result = $primary->withFingerprintConnectionReuse(
                    fn (): array => $this->collector($this->manifest())->collect($this->release(), $primary),
                );
            } else {
                $owned[] = $primary = new FingerprintCollectionNntpHarness($primaryWire);
                $owned[] = $profile = new FingerprintCollectionNntpHarness($this->profileWire(), true);
                $owned[] = $first = new FingerprintCollectionNntpHarness($this->articleWire('first', 'alternate'), true);
                $owned[] = $second = new FingerprintCollectionNntpHarness($this->articleWire('second', 'alternate'), true);
                $primary->queueAlternates([$profile, $first, $second]);
                $result = $this->collector($this->manifest())->collect($this->release(), $primary);
            }
            $data = $result['data'];
            $this->assertContains($result['status'], ['complete', 'partial']);
            $this->assertSame([], $data['errors']);
            $this->assertSame(2, $data['collection_policy']['article_samples_selected']);
            $this->assertSame(0, $data['collection_policy']['article_samples_omitted_due_to_limit']);
            $this->assertCount(4, $data['article_observations']);
            $this->assertCount(2, $data['collection_policy']['server_profiles']);
            foreach ($data['collection_policy']['server_profiles'] as $index => $row) {
                $this->assertSame($index === 1, $row['alternate']);
                $this->assertSame($index === 1 ? 'alternate.fixture.invalid' : 'primary.fixture.invalid', $row['name']);
                $this->assertArrayNotHasKey('profile_error_type', $row);
                $this->assertSame(101, $row['profile']['capabilities_response_code']);
                $this->assertNull($row['profile']['capabilities_error_type']);
                $this->assertSame(['VERSION 2', 'IMPLEMENTATION lifecycle-fixture', 'OVER MSGID'], $row['profile']['capabilities_raw']);
                $this->assertSame(215, $row['profile']['overview_format_response_code']);
                $this->assertNull($row['profile']['overview_format_error_type']);
                $this->assertSame($this->overviewFormat(), $row['profile']['overview_format_raw']);
                $this->assertSame('2026-10-16T12:00:00+00:00', $row['profile']['profiled_at_utc']);
            }
            foreach ($data['article_observations'] as $index => $observation) {
                $name = $index < 2 ? 'first' : 'second';
                $role = $index % 2 === 0 ? 'primary' : 'alternate';
                $payload = $name.'-'.$role;
                $this->assertSame($name.'@fixture.invalid', $observation['message_id']);
                $this->assertSame($role, $observation['server_role']);
                $this->assertSame($role.'.fixture.invalid', $observation['server']);
                $this->assertSame($role.'.fixture.invalid', $observation['provider_label']);
                $this->assertSame('configured hostname', $observation['provider_label_basis']);
                $this->assertSame($role === 'alternate' ? '563' : '119', $observation['server_port']);
                $this->assertSame($role === 'alternate', $observation['server_tls']);
                $this->assertSame('first', $observation['sample_role']);
                $this->assertSame(1, $observation['segment_number']);
                $this->assertSame('2026-10-16T12:00:00+00:00', $observation['queried_at_utc']);
                $this->assertIsNumeric($observation['retrieval_duration_ms']);
                $this->assertGreaterThanOrEqual(0, $observation['retrieval_duration_ms']);
                $this->assertTrue($observation['overview_attempted']);
                $this->assertSame(224, $observation['overview_response_code']);
                $this->assertSame([$this->overviewRow($name, $role)], $observation['overview_rows']);
                $this->assertSame([224, 221, 222], array_column($observation['command_results'], 'response_code'));
                $this->assertSame([true, true, true], array_column($observation['command_results'], 'available'));
                foreach ($observation['command_results'] as $command) {
                    $this->assertNull($command['error_type']);
                }
                $this->assertSame($this->headers($name, $role), $observation['metadata']['raw_headers']);
                $this->assertSame(hash('sha256', YencArticles::article($payload)."\r\n"), $observation['metadata']['article_body_sha256']);
                $this->assertSame(strlen($payload), $observation['metadata']['yenc']['decoded_segment_size']);
                $this->assertSame(hash('sha256', $payload), $observation['metadata']['yenc']['decoded_segment_sha256']);
                $this->assertTrue($observation['metadata']['yenc']['decoded_size_matches_yend_size']);
            }

            $profileCommands = ['CAPABILITIES', 'LIST OVERVIEW.FMT'];
            $this->assertSame([...$profileCommands, ...$this->articleCommands('first'), ...$this->articleCommands('second')], $primary->observedCommands());
            $this->assertSame($expectedAlternateClients, $primary->alternateFactoryCalls,
                'Profile collection and both sampled articles must share the collection-owned alternate client.');
            if ($expectedAlternateClients === 1) {
                $this->assertSame([...$profileCommands, ...$this->articleCommands('first'), ...$this->articleCommands('second')], $profile->observedCommands());
                $this->assertSame([['compression' => true, 'alternate' => false]], $primary->connections);
                $this->assertSame([['compression' => false, 'alternate' => true]], $profile->connections);
                $this->assertSame(0, $profile->quitCalls);
                $this->assertSame(1, $primary->quitCalls, 'The real primary reconnect calls doQuit before its initial admission.');
                $this->assertSame(1, $profile->ownedResourceCount());
                $this->assertSame(1, $primary->ownedResourceCount());
            } else {
                $this->assertSame($profileCommands, $profile->observedCommands());
                $this->assertSame($this->articleCommands('first'), $first->observedCommands());
                $this->assertSame($this->articleCommands('second'), $second->observedCommands());
                $this->assertSame([['compression' => false, 'alternate' => false]], $primary->connections);
                foreach ([$profile, $first, $second] as $alternate) {
                    $this->assertSame([['compression' => false, 'alternate' => true]], $alternate->connections);
                    $this->assertSame(1, $alternate->quitCalls);
                    $this->assertSame(1, $alternate->ownedResourceCount(), 'Logical quit closed the client; the peer remains owned for inspection.');
                }
                $this->assertSame(0, $primary->quitCalls);
                $this->assertSame(2, $primary->ownedResourceCount());
            }

            return $result;
        } finally {
            foreach ($owned as $connection) {
                $connection->closeFixture();
            }
            foreach ($owned as $connection) {
                $this->assertSame(0, $connection->ownedResourceCount());
            }
        }
    }

    public function test_unavailable_nzb_does_not_admit_any_provider_connection(): void
    {
        $primary = new FingerprintCollectionNntpHarness('');
        try {
            $this->assertSame(['status' => 'unavailable', 'data' => ['reason' => 'The release NZB is not available locally.']],
                $this->collector('')->collect($this->release(), $primary));
            $this->assertSame([], $primary->connections);
            $this->assertSame([], $primary->observedCommands());
            $this->assertSame(0, $primary->alternateFactoryCalls);
            $this->assertSame(0, $primary->quitCalls);
        } finally {
            $primary->closeFixture();
            $this->assertSame(0, $primary->ownedResourceCount());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function responseFaults(): iterable
    {
        foreach (['lf-status', 'malformed-success-status', 'overlong-status', 'invalid-status', 'head430',
            'body430', 'body400', 'over-unsupported', 'truncated-header', 'truncated-body', 'incomplete-status'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('responseFaults')]
    public function test_uncertain_responses_preserve_current_result_then_replace_the_alternate(string $fault): void
    {
        $valid = $this->plainArticleWire('first');
        $wire = match ($fault) {
            'lf-status' => preg_replace('/\r\n/', "\n", $valid, 1),
            'malformed-success-status' => '221x'.substr($valid, 4),
            'overlong-status' => '221 '.str_repeat('x', 520).substr($valid, strpos($valid, "\r\n")),
            'invalid-status' => "not a status\r\n",
            'head430' => "430 missing\r\n",
            'body430' => str_replace('222 0 <first@fixture.invalid> body', '430 missing', $valid),
            'body400' => str_replace('222 0 <first@fixture.invalid> body', '400 closed', $valid),
            'over-unsupported' => "500 unsupported\r\n".$valid,
            'truncated-header' => "221 0 <first@fixture.invalid> headers\r\nSubject: unfinished\r\n",
            'truncated-body' => substr($valid, 0, -3),
            'incomplete-status' => '221 incomplete',
            default => throw new \LogicException('Unexpected fixture response fault.'),
        };
        $owned = [];
        try {
            $owned[] = $baseline = new FingerprintCollectionNntpHarness('');
            $owned[] = $legacyChild = new FingerprintCollectionNntpHarness($wire, true);
            $baseline->queueAlternates([$legacyChild]);
            $expected = $this->capture(fn (): array => $baseline->getFingerprintArticleByMessageID(
                'first@fixture.invalid', alternate: true, includeOverview: $fault === 'over-unsupported',
            ));
            $owned[] = $candidate = new FingerprintCollectionReuseNntpHarness('');
            $owned[] = $first = new FingerprintCollectionReuseNntpHarness($wire, true);
            $first->endResponseStream = str_starts_with($fault, 'truncated-') || $fault === 'incomplete-status';
            $owned[] = $next = new FingerprintCollectionReuseNntpHarness($this->plainArticleWire('second'), true);
            $candidate->queueAlternates([$first, $next]);
            $candidate->withFingerprintConnectionReuse(function () use ($candidate, $fault, $expected): void {
                $this->assertSame($expected, $this->capture(fn (): array => $candidate->getFingerprintArticleByMessageID(
                    'first@fixture.invalid', alternate: true, includeOverview: $fault === 'over-unsupported',
                )));
                $second = $candidate->getFingerprintArticleByMessageID('second@fixture.invalid', alternate: true);
                $this->assertSame(YencArticles::article('second-alternate')."\r\n", $second['body']);
            });
            $this->assertSame(2, $candidate->alternateFactoryCalls);
            $this->assertSame($legacyChild->observedCommands(), $first->observedCommands());
            foreach ([$first, $next] as $child) {
                $this->assertSame([['compression' => false, 'alternate' => true]], $child->connections);
                $this->assertSame(1, $child->ownedResourceCount());
            }
            $this->assertSame(['HEAD <second@fixture.invalid>', 'BODY <second@fixture.invalid>'], $next->observedCommands());
        } finally {
            $this->closeOwned($owned);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function writeFaults(): iterable
    {
        foreach (['short', 'zero', 'false'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('writeFaults')]
    public function test_partial_writes_never_resend_or_change_the_current_legacy_path(string $fault): void
    {
        $owned = [];
        try {
            $owned[] = $baseline = new FingerprintCollectionNntpHarness('');
            $owned[] = $legacyChild = new FingerprintCollectionNntpHarness($this->plainArticleWire('first'), true);
            $baseline->queueAlternates([$legacyChild]);
            $expected = $baseline->getFingerprintArticleByMessageID('first@fixture.invalid', alternate: true);
            $owned[] = $candidate = new FingerprintCollectionReuseNntpHarness('');
            $owned[] = $first = new FingerprintCollectionReuseNntpHarness($this->plainArticleWire('first'), true);
            $first->writeFaults = [$fault];
            $owned[] = $next = new FingerprintCollectionReuseNntpHarness($this->plainArticleWire('second'), true);
            $candidate->queueAlternates([$first, $next]);
            $candidate->withFingerprintConnectionReuse(function () use ($candidate, $fault, $expected): void {
                $actual = $this->capture(fn (): array => $candidate->getFingerprintArticleByMessageID('first@fixture.invalid', alternate: true));
                $this->assertSame($fault === 'false'
                    ? ['exception' => \RuntimeException::class, 'message' => 'Article headers are unavailable.']
                    : ['result' => $expected], $actual);
                $this->assertSame(YencArticles::article('second-alternate')."\r\n",
                    $candidate->getFingerprintArticleByMessageID('second@fixture.invalid', alternate: true)['body']);
            });
            $this->assertSame($fault === 'false' ? ["HEAD <first@fixture.invalid>\r\n"]
                : ["HEAD <first@fixture.invalid>\r\n", "BODY <first@fixture.invalid>\r\n"], $first->writes);
            $this->assertSame($fault === 'false' ? 0 : 2, $first->statusReads);
            $this->assertSame(match ($fault) {
                'short' => "HEABODY <first@fixture.invalid>\r\n",
                'zero' => "BODY <first@fixture.invalid>\r\n",
                'false' => '',
                default => throw new \LogicException('Unexpected fixture write fault.'),
            }, $first->observedBytes());
            $this->assertCount(1, $first->connections);
            $this->assertCount(1, $next->connections);
            $this->assertSame(2, $candidate->alternateFactoryCalls);
        } finally {
            $this->closeOwned($owned);
        }
    }

    public function test_unsupported_profile_is_preserved_and_not_reused(): void
    {
        $owned = [];
        try {
            $owned[] = $baseline = new FingerprintCollectionNntpHarness('');
            $owned[] = $legacyChild = new FingerprintCollectionNntpHarness("500 unsupported\r\n", true);
            $baseline->queueAlternates([$legacyChild]);
            $expected = $baseline->getFingerprintServerProfile(true);
            $owned[] = $candidate = new FingerprintCollectionReuseNntpHarness('');
            $owned[] = $profile = new FingerprintCollectionReuseNntpHarness("500 unsupported\r\n", true);
            $owned[] = $article = new FingerprintCollectionReuseNntpHarness($this->plainArticleWire('first'), true);
            $candidate->queueAlternates([$profile, $article]);
            $candidate->withFingerprintConnectionReuse(function () use ($candidate, $expected): void {
                $this->assertSame($expected, $candidate->getFingerprintServerProfile(true));
                $this->assertSame(YencArticles::article('first-alternate')."\r\n",
                    $candidate->getFingerprintArticleByMessageID('first@fixture.invalid', alternate: true)['body']);
            });
            $this->assertSame(2, $candidate->alternateFactoryCalls);
            $this->assertSame(['CAPABILITIES'], $profile->observedCommands());
        } finally {
            $this->closeOwned($owned);
        }
    }

    public function test_successful_connect_followed_by_eof_does_not_reconnect_inside_the_request(): void
    {
        $owner = new FingerprintCollectionReuseNntpHarness('');
        $child = new FingerprintCollectionReuseNntpHarness('', true);
        $child->eofAfterConnect = true;
        $owner->queueAlternates([$child]);
        try {
            $owner->withFingerprintConnectionReuse(function () use ($owner): void {
                $this->assertSame(['exception' => \RuntimeException::class, 'message' => 'Article headers are unavailable.'],
                    $this->capture(fn (): array => $owner->getFingerprintArticleByMessageID('first@fixture.invalid', alternate: true)));
            });
            $this->assertCount(1, $child->connections);
            $this->assertSame([], $child->writes);
            $this->assertSame(0, $child->statusReads);
        } finally {
            $this->closeOwned([$owner, $child]);
        }
    }

    public function test_false_connect_keeps_the_original_exception_and_single_admission(): void
    {
        $owner = new FingerprintCollectionReuseNntpHarness('');
        $child = new FingerprintCollectionReuseNntpHarness('', true);
        $child->connectionFails = true;
        $owner->queueAlternates([$child]);
        try {
            $this->assertSame(['exception' => \RuntimeException::class, 'message' => 'Unable to connect to the requested NNTP provider.'],
                $this->capture(fn () => $owner->withFingerprintConnectionReuse(
                    fn (): array => $owner->getFingerprintServerProfile(true),
                )));
            $this->assertCount(1, $child->connections);
            $this->assertSame(0, $child->ownedResourceCount());
        } finally {
            $this->closeOwned([$owner, $child]);
        }
    }

    public function test_scope_rejects_an_existing_socket_even_after_eof(): void
    {
        foreach ([false, true] as $eof) {
            $owner = new FingerprintCollectionReuseNntpHarness('');
            $owner->eofAfterConnect = $eof;
            try {
                $owner->doConnect(false, false);
                $this->assertSame(['exception' => \LogicException::class, 'message' => 'Fingerprint reuse requires a fresh primary owner.'],
                    $this->capture(fn () => $owner->withFingerprintConnectionReuse(static fn (): string => 'unexpected')));
                $this->assertSame(2, $owner->ownedResourceCount(), 'A rejected scope does not own the existing socket.');
            } finally {
                $this->closeOwned([$owner]);
            }
        }
    }

    public function test_scope_closes_both_saved_sockets_when_both_reset_hooks_throw(): void
    {
        foreach ([false, true] as $throw) {
            $owner = new FingerprintCollectionReuseNntpHarness($this->profileWire());
            $child = new FingerprintCollectionReuseNntpHarness($this->profileWire(), true);
            $owner->queueAlternates([$child]);
            $sockets = [];
            $failure = new \RuntimeException('Original collection failure.');
            try {
                try {
                    $result = $owner->withFingerprintConnectionReuse(function () use ($owner, $child, &$sockets, $throw, $failure): string {
                        $owner->getFingerprintServerProfile(false);
                        $owner->getFingerprintServerProfile(true);
                        $sockets = [$owner->fixtureSocket(), $child->fixtureSocket()];
                        $owner->throwOnReset = $child->throwOnReset = true;
                        if ($throw) {
                            throw $failure;
                        }

                        return 'original result';
                    });
                    $this->assertFalse($throw);
                    $this->assertSame('original result', $result);
                } catch (\Throwable $exception) {
                    $this->assertTrue($throw);
                    $this->assertSame($failure, $exception);
                }
                $this->assertCount(2, $sockets);
                foreach ($sockets as $socket) {
                    $this->assertFalse(is_resource($socket));
                }
                $this->assertSame(1, $owner->ownedResourceCount());
                $this->assertSame(1, $child->ownedResourceCount());
            } finally {
                $this->closeOwned([$owner, $child]);
            }
        }
    }

    public function test_scope_rejects_nesting_and_factory_aliases_without_owning_borrowed_sockets(): void
    {
        $owner = new FingerprintCollectionReuseNntpHarness('');
        $borrowed = new FingerprintCollectionReuseNntpHarness('', true);
        try {
            $owner->withFingerprintConnectionReuse(function () use ($owner): void {
                $this->assertSame(['exception' => \LogicException::class, 'message' => 'Fingerprint reuse requires a fresh primary owner.'],
                    $this->capture(fn () => $owner->withFingerprintConnectionReuse(static fn (): string => 'nested')));
            });
            $owner->queueAlternates([$owner]);
            $expected = ['exception' => \LogicException::class, 'message' => 'Fingerprint reuse requires a distinct fresh alternate client.'];
            $this->assertSame($expected, $this->capture(fn () => $owner->withFingerprintConnectionReuse(
                fn (): array => $owner->getFingerprintServerProfile(true),
            )));
            $borrowed->doConnect(false, true);
            $owner->queueAlternates([$borrowed]);
            $this->assertSame($expected, $this->capture(fn () => $owner->withFingerprintConnectionReuse(
                fn (): array => $owner->getFingerprintServerProfile(true),
            )));
            $this->assertSame(2, $borrowed->ownedResourceCount());
        } finally {
            $this->closeOwned([$owner, $borrowed]);
        }
    }

    public function test_same_hostname_keeps_explicit_alternate_role_and_invalid_ids_admit_nothing(): void
    {
        $owner = new FingerprintCollectionReuseNntpHarness('');
        $child = new FingerprintCollectionReuseNntpHarness($this->profileWire().$this->plainArticleWire('first'), true);
        $owner->useSameHostForBothRoles();
        $child->useSameHostForBothRoles();
        $owner->queueAlternates([$child]);
        try {
            $owner->withFingerprintConnectionReuse(function () use ($owner): void {
                $this->assertSame(['exception' => \RuntimeException::class, 'message' => 'Invalid verification article request.'],
                    $this->capture(fn (): array => $owner->getFingerprintArticleByMessageID("bad\r\nHEAD other", alternate: true)));
                $this->assertSame(0, $owner->alternateFactoryCalls);
                $owner->getFingerprintServerProfile(true);
                $this->assertSame(YencArticles::article('first-alternate')."\r\n",
                    $owner->getFingerprintArticleByMessageID('first@fixture.invalid', alternate: true)['body']);
            });
            $this->assertSame([['compression' => false, 'alternate' => true]], $child->connections);
            $this->assertSame('primary.fixture.invalid', $child->getCurrentServer());
            $this->assertSame(563, $child->getCurrentPort());
            $this->assertSame(1, $owner->alternateFactoryCalls);
        } finally {
            $this->closeOwned([$owner, $child]);
        }
    }

    public function test_primary_only_scope_keeps_default_public_reader_path(): void
    {
        config(['nntmux_nntp.alternate_server' => '']);
        $owner = new FingerprintCollectionReuseNntpHarness(
            $this->profileWire().$this->articleWire('first', 'primary').$this->articleWire('second', 'primary'),
        );
        try {
            $result = $owner->withFingerprintConnectionReuse(
                fn (): array => $this->collector($this->manifest())->collect($this->release(), $owner),
            );
            $this->assertCount(2, $result['data']['article_observations']);
            $this->assertSame([], $result['data']['errors']);
            $this->assertSame(0, $owner->alternateFactoryCalls);
            $this->assertSame([], $owner->writes, 'Primary commands use the parent writer, without reuse observation.');
            $this->assertSame(0, $owner->statusReads);
            $this->assertSame(['CAPABILITIES', 'LIST OVERVIEW.FMT', ...$this->articleCommands('first'), ...$this->articleCommands('second')], $owner->observedCommands());
            $this->assertSame(1, $owner->ownedResourceCount());
        } finally {
            $this->closeOwned([$owner]);
        }
    }

    /** @return array<string, mixed> */
    private function capture(callable $read): array
    {
        try {
            return ['result' => $read()];
        } catch (\Throwable $exception) {
            return ['exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    }

    /** @param list<FingerprintCollectionNntpHarness|FingerprintCollectionReuseNntpHarness> $owned */
    private function closeOwned(array $owned): void
    {
        foreach ($owned as $connection) {
            $connection->closeFixture();
        }
        foreach ($owned as $connection) {
            $this->assertSame(0, $connection->ownedResourceCount());
        }
    }

    private function plainArticleWire(string $name): string
    {
        return '221 0 <'.$name."@fixture.invalid> headers\r\n".implode('', $this->headers($name, 'alternate'))
            .'.'."\r\n222 0 <".$name."@fixture.invalid> body\r\n".YencArticles::article($name.'-alternate')."\r\n.\r\n";
    }

    private function collector(string $xml): ReleaseFingerprintCollector
    {
        $nzb = $this->createMock(NzbService::class);
        $nzb->expects($this->once())->method('readNzbContents')->with('lifecycle-fixture')->willReturn($xml);
        $yenc = new YencService;

        return new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc);
    }

    private function release(): Release
    {
        $release = new Release;
        $release->guid = 'lifecycle-fixture';

        return $release;
    }

    private function manifest(): string
    {
        return '<?xml version="1.0"?><nzb><file subject="first.bin" poster="fixture" date="1760000000"><groups><group>alt.test</group></groups><segments><segment bytes="13" number="1">first@fixture.invalid</segment></segments></file>'
            .'<file subject="second.bin" poster="fixture" date="1760000000"><groups><group>alt.test</group></groups><segments><segment bytes="14" number="1">second@fixture.invalid</segment></segments></file></nzb>';
    }

    /** @return list<string> */
    private function overviewFormat(): array
    {
        return ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines'];
    }

    private function profileWire(): string
    {
        return "101 capabilities\r\nVERSION 2\r\nIMPLEMENTATION lifecycle-fixture\r\nOVER MSGID\r\n.\r\n215 overview format\r\n"
            .implode("\r\n", $this->overviewFormat())."\r\n.\r\n";
    }

    /** @return list<string> */
    private function headers(string $name, string $role): array
    {
        return ['Subject: '.$name.'.bin'."\r\n", "From: fixture@example.invalid\r\n",
            "Date: Fri, 16 Oct 2026 10:00:00 +0000\r\n", 'Message-ID: <'.$name."@fixture.invalid>\r\n",
            'X-Provider-Fixture: '.$role."\r\n", "User-Agent: Nyuu fixture\r\n"];
    }

    private function overviewRow(string $name, string $role): string
    {
        return "0\t".$name.".bin\tfixture@example.invalid\tFri, 16 Oct 2026 10:00:00 +0000\t<".$name."@fixture.invalid>\t\t".strlen($name.'-'.$role)."\t3";
    }

    private function articleWire(string $name, string $role): string
    {
        return "224 overview\r\n".$this->overviewRow($name, $role)."\r\n.\r\n221 0 <".$name."@fixture.invalid> headers\r\n"
            .implode('', $this->headers($name, $role)).".\r\n222 0 <".$name."@fixture.invalid> body\r\n"
            .YencArticles::article($name.'-'.$role)."\r\n.\r\n";
    }

    /** @return list<string> */
    private function articleCommands(string $name): array
    {
        return ['OVER <'.$name.'@fixture.invalid>', 'HEAD <'.$name.'@fixture.invalid>', 'BODY <'.$name.'@fixture.invalid>'];
    }
}
