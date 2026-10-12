<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpentrackersRelayParserTest extends TestCase
{
    public function test_captured_mixed_announcements_preserve_literals_and_relay_provenance(): void
    {
        $parser = new PreAnnounceParser('opentrackers', 'pretend-upstream');
        $at = CarbonImmutable::parse('2026-10-11 23:52:00', 'UTC');
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/opentrackers-2026-10-11.json'), true, 512, JSON_THROW_ON_ERROR);
        $titles = [];
        foreach ($fixture['records'] as $record) {
            $entry = $parser->parse($record['message'], $at);
            self::assertNotNull($entry, $record['message']);
            $direct = null;
            foreach (['rizon', 'corruptnet', 'predatabase', 'zenet'] as $format) {
                $direct ??= (new PreAnnounceParser($format, 'direct'))->parse($record['message'], $at);
            }
            self::assertNotNull($direct);
            self::assertSame($direct->title, $entry->title);
            self::assertStringContainsString($entry->title, $record['message']);
            self::assertSame($direct->category, $entry->category);
            self::assertSame('opentrackers-relay', $entry->source);
            self::assertNull($entry->predate);
            self::assertEquals($at, $entry->listedAt);
            self::assertFalse($entry->enrichOnly);
            $titles[$entry->title] = true;
        }
        self::assertCount(17, $fixture['records']);
        self::assertCount(5, $titles);
    }

    public function test_default_receipt_time_is_an_observation_only(): void
    {
        $entry = (new PreAnnounceParser('opentrackers', 'ignored'))->parse('[PRE] [TV-HD] Name-GRP');
        self::assertNotNull($entry);
        self::assertNull($entry->predate);
        self::assertNotNull($entry->listedAt);
    }

    #[DataProvider('rejectedMessages')]
    public function test_noise_and_ambiguous_or_malformed_announcements_are_rejected(string $message): void
    {
        self::assertNull((new PreAnnounceParser('opentrackers', 'ignored'))->parse($message));
    }

    /** @return list<array{string}> */
    public static function rejectedMessages(): array
    {
        return [
            ['hello channel'],
            ['[PRE] [TV-HD] incomplete'],
            ['[PRE] [TV-HD] Name-GRP extra'],
            ["PRE: [TV-HD] Name-GRP\n(PRE) (TV-HD) (Other-GRP)"],
            ['pre | TV-HD | Name-GRP (PRE) (TV-HD) (Other-GRP)'],
            ['p2p | #2 | BTN | Name-GRP, 2nd!'],
            ['.::: PRE ::: TV-HD ::: Name-GRP :::.'],
            ['PRE: Name-GRP [not-a-section]'],
            ['(PRE) (TV-HD) (Name-GRP'],
        ];
    }

    public function test_unique_detail_and_nuke_entries_keep_supplied_metadata_without_observed_pre_time(): void
    {
        $parser = new PreAnnounceParser('opentrackers', 'ignored');
        $info = $parser->parse('info | Name-GRP - 14 F & 67 MB');
        self::assertNotNull($info);
        self::assertSame(['14', '67MB', true], [$info->files, $info->size, $info->enrichOnly]);
        self::assertNull($info->predate);
        self::assertNull($info->listedAt);
        $nuke = $parser->parse('NUKE: Name-GRP [bad.release]');
        self::assertNotNull($nuke);
        self::assertSame('bad.release', $nuke->nukeReason);
        self::assertSame(Predb::PRE_NUKED, $nuke->nuked);
        self::assertNull($nuke->predate);
        self::assertNull($nuke->listedAt);
    }

    public function test_profile_is_public_verified_tls_and_default_off_without_changing_existing_defaults(): void
    {
        $previous = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['irc_settings' => require dirname(__DIR__, 3).'/config/irc_settings.php']));
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        try {
            $profile = IrcNetworks::resolve('opentrackers');
            self::assertNotContains('opentrackers', IrcNetworks::enabled());
            self::assertSame('irc.opentrackers.org', $profile['server']);
            self::assertSame([6697, true, null], [$profile['port'], $profile['tls'], $profile['tls_verify_peer_name']]);
            self::assertSame('opentrackers', $profile['format']);
            self::assertSame('opentrackers-relay', $profile['source']);
            self::assertSame(['#pre-all' => null, '#p2p-pre' => null], $profile['channels']);
            self::assertSame('nntmux', IrcNetworks::resolve('synirc')['format']);
            self::assertSame([6667, false], [IrcNetworks::resolve('efnet')['port'], IrcNetworks::resolve('efnet')['tls']]);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Container::setInstance($previous);
        }
    }
}
