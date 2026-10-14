<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreAnnounceParserStrictTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidMessages(): iterable
    {
        $lines = [
            'corruptnet' => [
                'PRE: Synthetic.Release-GRP [chat]',
                'INFO: Synthetic.Release-GRP [2F 1.2.3MB]',
                'PRE: [TV] Synthetic.Release-GRP,',
                'INFO: Synthetic.Release-GRP [2F 1.2MB] chatter',
                'NUKE: Synthetic.Release-GRP [bad] chatter',
                'P2P: Synthetic.Release-GRP [chat]',
            ],
            'zenet' => [
                '(INFO) (Synthetic.Release-GRP) (2 Files) (1.2.3 MB)',
                '(PRE) (TV) (Synthetic.Release-GRP,)',
                '(INFO) (Synthetic.Release-GRP) (2 Files) (1.2 MB) chatter',
                '(NUKE) (Synthetic.Release-GRP) (bad) chatter',
                '(P2P) (Synthetic.Release-GRP) (chat)',
            ],
            'predatabase' => [
                'info | Synthetic.Release-GRP - 2 F & 1.2.3 MB',
                'pre | TV | Synthetic.Release-GRP,',
                'p2p | #1 | SITE | Synthetic.Release-GRP,, WIN!',
            ],
            'ngp' => [
                '[INFO] FOR Synthetic.Release-GRP with 2F 1.2.3MB added.',
                '.::: PRE ::: TV ::: Synthetic.Release-GRP, :::.',
            ],
            'rizon' => [
                '[PRE] Synthetic.Release-GRP [chat]',
                '[PRE] [TV] Synthetic.Release-GRP,',
                '[NUKE] Synthetic.Release-GRP [bad] chatter',
                '[P2P] Synthetic.Release-GRP [chat]',
            ],
        ];
        foreach ($lines as $format => $messages) {
            foreach ($messages as $index => $message) {
                yield "$format-$index" => [$format, $message];
                if ($format !== 'ngp') {
                    yield "relay-$format-$index" => ['opentrackers', $message];
                }
            }
        }
        foreach (self::validMessages() as $name => [$format, $message]) {
            yield "$name-trailing-text" => [$format, $message.' chatter'];
            yield "$name-newline" => [$format, $message."\n"];
            yield "$name-carriage-return" => [$format, $message."\r"];
        }
    }

    #[DataProvider('invalidMessages')]
    public function test_malformed_messages_are_rejected_without_an_entry(string $format, string $message): void
    {
        self::assertNull((new PreAnnounceParser($format, 'synthetic'))->parse($message));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validMessages(): iterable
    {
        $lines = [
            ['corruptnet', 'PRE: [TV] Synthetic.Release-GRP', 'PRE'],
            ['corruptnet', 'INFO: Synthetic.Release-GRP [2F 1.2MB]', 'INFO'],
            ['corruptnet', 'NUKE: Synthetic.Release-GRP [bad] [NUKENET]', 'NUKE'],
            ['zenet', '(PRE) (TV) (Synthetic.Release-GRP)', 'PRE'],
            ['zenet', '(INFO) (Synthetic.Release-GRP) (2 Files) (1.2 MB)', 'INFO'],
            ['zenet', '(UNNUKE) (Synthetic.Release-GRP) (fixed)', 'UNNUKE'],
            ['predatabase', 'pre | TV | Synthetic.Release-GRP', 'PRE'],
            ['predatabase', 'info | Synthetic.Release-GRP - 2 F & 1.2 MB', 'INFO'],
            ['predatabase', 'p2p | #1 | SITE | Synthetic.Release-GRP, WIN!', 'P2P'],
            ['ngp', '.::: PRE ::: TV ::: Synthetic.Release-GRP :::.', 'PRE'],
            ['ngp', '[INFO] FOR Synthetic.Release-GRP with 2F 1.2MB added.', 'INFO'],
            ['rizon', '[PRE] [TV] Synthetic.Release-GRP', 'PRE'],
            ['rizon', '[NUKE] Synthetic.Release-GRP [bad] [NUKENET]', 'NUKE'],
        ];
        foreach ($lines as $index => [$format, $message, $type]) {
            yield "$format-$index" => [$format, $message, $type];
            if ($format !== 'ngp') {
                yield "relay-$format-$index" => ['opentrackers', $message, $type];
            }
        }
    }

    #[DataProvider('validMessages')]
    public function test_valid_announcements_preserve_literal_identity_and_time_semantics(string $format, string $message, string $type): void
    {
        $at = CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC');
        $entry = (new PreAnnounceParser($format, 'synthetic'))->parse($message, $at);
        self::assertNotNull($entry);
        self::assertSame('Synthetic.Release-GRP', $entry->title);
        $timed = in_array($type, ['PRE', 'P2P'], true);
        self::assertEquals($format !== 'opentrackers' && $timed ? $at : null, $entry->predate);
        self::assertEquals($format === 'opentrackers' && $timed ? $at : null, $entry->listedAt);
        self::assertSame($type === 'INFO', $entry->enrichOnly);
        if ($type === 'INFO') {
            self::assertSame(['2', '1.2MB'], [$entry->files, $entry->size]);
        }
        if (in_array($type, ['NUKE', 'UNNUKE'], true)) {
            self::assertSame($type === 'NUKE' ? Predb::PRE_NUKED : Predb::PRE_UNNUKED, $entry->nuked);
            self::assertSame($type === 'NUKE' ? 'bad' : 'fixed', $entry->nukeReason);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function punctuationMessages(): iterable
    {
        foreach (self::validMessages() as $name => [$format, $message]) {
            yield $name => [$format, $message];
        }
    }

    #[DataProvider('punctuationMessages')]
    public function test_legal_title_punctuation_is_preserved(string $format, string $message): void
    {
        $title = 'Synthetic,(Literal).Release-GRP';
        $message = str_replace('Synthetic.Release-GRP', $title, $message);
        self::assertSame($title, (new PreAnnounceParser($format, 'synthetic'))->parse($message)?->title);
    }
}
