<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\Models\Platform;
use App\Services\InvitationService;
use App\Services\IsbnDbService;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 67.4 batch helpers: IGDB Company plan rows (collapsed name, slug, suffix-stripped
 * canonical slug, known publisher, role class and parent chain), IGDB Game match rows (title
 * and query tokens, exact/prefix match, Jaccard similarity, release year, platform IDs, genres,
 * cover, rating tier and score decision), IGDB Platform rows (alias source, family, generation,
 * portability, NNTmux category and label), InvitationService rows (token shape, role quota,
 * expiry, per-inviter ordinals and email plausibility) and IsbnDbService rows (ISBN-10/13
 * checksums and conversion, authors, publisher suffixes, publish date precision, completeness
 * and edition score). Each export takes a field-major NUL-joined blob (plus a 0x01 twin when a
 * value holds a NUL) and packed int64 columns; Rust writes fixed-width int64 rows and a batch
 * trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w67RustFfiState(); no class declares a new property.
 *
 * The paired benchmark (RUN_RUST_FFI_BENCHMARKS=1) times the PHP and native batch methods,
 * including validation, packing and decoding, over interleaved rounds of 320 records in random
 * order per round (fixed seed). Rounds per workload: W67_BENCH_ROUNDS (default 1000, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Decision: four runs at 3e4315e2d + this change (Apple Silicon, PHP 8.5.12 CLI, release build,
 * 1000 rounds per workload after 10 warmups, load average 5.6-8.2), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): w67IgdbCompanyPlanBatch 4.15/3.40, 3.90/3.80;
 * w67IgdbGamePlanBatch 2.78/2.36, 2.21/2.22; w67IgdbPlatformPlanBatch 3.08/2.74, 2.56/2.58;
 * w67InvitationServicePlanBatch 2.09/1.78, 1.78/1.64; w67IsbnDbServicePlanBatch 4.07/4.07,
 * 2.62/2.49. Rust wins where PHP runs regex collapses and slugs, splits and dedupes tokens,
 * walks parent chains, parses ISBN check digits and hashes labels per record; invitation rows
 * are closest to the gate because PHP's trim(), strspn() and array lookups are native C. Every
 * helper clears 1.5x and defaults to Rust.
 */
final class RustIgdbCompanyGamePlatformInvitationIsbnDbW67ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'company' => [Company::class, 'w67IgdbCompanyPlanBatch', 4, 10],
        'game' => [Game::class, 'w67IgdbGamePlanBatch', 4, 9],
        'platform' => [Platform::class, 'w67IgdbPlatformPlanBatch', 3, 11],
        'invitation' => [InvitationService::class, 'w67InvitationServicePlanBatch', 3, 10],
        'isbndb' => [IsbnDbService::class, 'w67IsbnDbServicePlanBatch', 4, 10],
    ];

    /** W67_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        Company::class => true,
        Game::class => true,
        Platform::class => true,
        InvitationService::class => true,
        IsbnDbService::class => true,
    ];

    private const int SEED = 6742026;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x0c", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', '  ', '"', '.', '..', '/', '\\', '-', '_', '|', ',', ':', '@', '(', ')', 'x', 'X', 'A', '0', '9', '978', 'inc', 'Ltd', 'ps4', '2019-'];

    private const array COMPANIES = ['Sony Interactive Entertainment', 'Nintendo Co., Ltd.', 'Electronic Arts Inc', 'Ubisoft Entertainment SA', 'Bandai Namco Entertainment',
        'Square Enix Holdings Co Ltd', 'CD Projekt RED', 'Valve Corporation', 'Devolver Digital', 'Warner Bros. Games', 'Take-Two Interactive Software, Inc.', 'Capcom',
        'Ünïcödé Studio GmbH', "Indie  Dev\tLLC", 'Sega of America, Inc.', 'Konami Digital Entertainment Co., Ltd.', 'Inc'];

    private const array GAMES = ['The Legend of Zelda: Breath of the Wild', 'Super Mario Odyssey', 'Halo Infinite', 'Grand Theft Auto V', 'The Witcher 3: Wild Hunt',
        'Final Fantasy VII Remake', 'Hollow Knight', 'Stardew Valley', 'Ünïcödé Quest 日本', 'DOOM (2016)', 'Mass Effect 2', 'Red Dead Redemption 2', 'Celeste'];

    private const array PLATFORM_IDS = ['6', '6, 48, 49', '130', '', '6,x,  167', '1234567', '48,48', ' 167 ', ',,', '-1,6'];

    private const array GENRES = ['Adventure, Role-playing (RPG)', 'Shooter', 'Indie,Puzzle,Weird', '', 'Platform', "Hack and slash/Beat 'em up, Fighting", 'RPG,rpg',
        'Sport, Racing, Simulator', 'Real Time Strategy (RTS), Tactical', 'Arcade , '];

    private const array COVERS = ['co3p2d', 'co1wyy', '', 'CO3P2D', 'co-3p2d', 'abcdefghijklmnopqrstuvwxyz0123456', 'abcdefghijklmnopqrstuvwxyz012345'];

    /** [name, abbreviation, slug] triplets. */
    private const array PLATFORMS = [['PlayStation 4', 'PS4', 'ps4--1'], ['PlayStation 5', 'PS5', 'ps5'], ['Xbox One', 'XONE', 'xboxone'], ['Xbox Series X|S', 'Series X|S', 'series-x'],
        ['Nintendo Switch', 'Switch', 'switch'], ['PC (Microsoft Windows)', 'PC', 'win'], ['Mac', 'Mac', 'mac'], ['Android', '', 'android'], ['iOS', '', 'ios'],
        ['Super Nintendo Entertainment System', 'SNES', 'snes'], ['Sega Mega Drive/Genesis', 'Genesis/MegaDrive', 'smd'], ['Game Boy Advance', 'GBA', 'gba'],
        ['Arcade', '', 'arcade'], ['Nintendo 3DS', '3DS', '3ds'], ['PlayStation Vita', 'Vita', 'psvita'], ['Atari 2600', 'Atari2600', 'atari2600'], ['Ünïcödé Console', '', '']];

    private const array EMAILS = ['user@example.com', ' Bob@Example.com', 'a@b.co', 'no-at-sign', '@example.com', 'two@@example.com', 'x@.com', 'x@example.', 'x@ex', 'spa ce@example.com',
        'ÜSER@exämple.com', "tab\t@example.com", 'user+tag@sub.example.org', ''];

    private const array ROLES = ['User', 'user', ' Admin ', 'Moderator', 'friend', 'Disabled', 'guest', ''];

    private const array TITLES = ['A Title', 'The Hobbit', 'Ünïcödé Bøøk 日本', '', '  ', 'Programming Rust, 2nd Edition'];

    private const array AUTHORS = ['Doe, Jane', 'Jane Doe|John Smith', ' Doe, Jane | | Smith ', '', '|', 'Tolkien, J.R.R.', 'Ünïcödé Åuthor'];

    private const array PUBLISHERS = ['Big House Publishing Group, Inc.', "O'Reilly Media", 'Penguin Books', 'Press', 'HarperCollins Publishers', 'No Starch Press', '', 'Books Inc',
        'Ünïcödé Verlag GmbH'];

    private const array DATES = ['2019-07-04', '2019', '2019-07', '2019-13', '0999', '20190', '2019-07-04T00:00:00', '', ' 2001-01-01 ', '2001-02-31', 'n.d.', '1999-00'];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW67RustFfi();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w67RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W67_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w67', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW67RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    private static function fnv(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $value, true));

        return $hash[1];
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length <= 0 ? '' : $random->getBytes($length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15, 16, 30, 31, 49, 50, 99, 100, 101, 255, 999, 1000, 1001, 9999, 10_000, 10_001, 86_399, 86_400, 0xFFFF,
            4_102_444_799, 4_102_444_800, 1 << 40, (1 << 40) + 1, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1,
            $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 10_000)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    /** A check-digit valid ISBN-13 or ISBN-10, sometimes with a wrong check digit or hyphens. */
    private static function isbn(Randomizer $random): string
    {
        if ($random->getInt(0, 1) === 0) {
            $digits = self::pick($random, ['978', '979']).\sprintf('%09d', $random->getInt(0, 999_999_999));
            $sum = 0;
            for ($k = 0; $k < 12; $k++) {
                $sum += (int) $digits[$k] * ($k % 2 === 0 ? 1 : 3);
            }
            $check = (10 - $sum % 10) % 10;
        } else {
            $digits = \sprintf('%09d', $random->getInt(0, 999_999_999));
            $sum = 0;
            for ($k = 0; $k < 9; $k++) {
                $sum += (10 - $k) * (int) $digits[$k];
            }
            $check = (11 - $sum % 11) % 11;
            $check = $check === 10 ? self::pick($random, ['X', 'x']) : $check;
        }
        $isbn = $digits.($random->getInt(0, 4) === 0 ? ($random->getInt(1, 9) + (int) $check) % 10 : $check);

        return $random->getInt(0, 2) === 0 ? implode('-', str_split($isbn, 3)) : $isbn;
    }

    /** @return list<mixed> */
    private static function companyItem(Randomizer $random, bool $uncommon): array
    {
        $name = self::pick($random, self::COMPANIES);
        $slug = match ($random->getInt(0, 3)) {
            0 => '',
            1 => self::pick($random, self::COMPANIES),
            default => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-'),
        };

        return [
            self::spoil($random, $name, $uncommon),
            self::spoil($random, $random->getInt(0, 5) === 0 ? strtoupper($slug).' ' : $slug, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 400), $uncommon),
            self::int($random, $random->getInt(0, 1) === 0 ? 0 : $random->getInt(1, 400), $uncommon),
            self::int($random, $random->getInt(0, 15), $uncommon),
            self::int($random, self::pick($random, [0, 0, 392, 840, 250, 826, 124, 1000, -1]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function gameItem(Randomizer $random, bool $uncommon): array
    {
        $title = self::pick($random, self::GAMES);
        $words = preg_split('/\s+/', $title) ?: [];
        $query = match ($random->getInt(0, 5)) {
            0 => strtolower($title),
            1 => implode(' ', \array_slice($words, 0, max(1, \count($words) - 2))),
            2 => self::pick($random, self::GAMES),
            3 => '',
            default => $title,
        };
        $released = $random->getInt(0, 7) === 0 ? 0 : $random->getInt(631_152_000, 1_893_456_000);
        $year = $released === 0 ? 2015 : (int) gmdate('Y', $released);

        return [
            self::spoil($random, $title, $uncommon),
            self::spoil($random, $query, $uncommon),
            self::spoil($random, self::pick($random, self::PLATFORM_IDS), $uncommon),
            self::spoil($random, self::pick($random, self::GENRES), $uncommon),
            self::spoil($random, self::pick($random, self::COVERS), $uncommon),
            self::int($random, $released, $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? 0 : $year + self::pick($random, [0, 0, 0, 1, -1, 3]), $uncommon),
            self::int($random, self::pick($random, [0, 6, 48, 130, 167]), $uncommon),
            self::int($random, $random->getInt(0, 10_000), $uncommon),
            self::int($random, self::pick($random, [0, 1, 4, 5, 10, 49, 50, 99, 100, 500]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function platformItem(Randomizer $random, bool $uncommon): array
    {
        [$name, $abbreviation, $slug] = self::pick($random, self::PLATFORMS);

        return [
            self::spoil($random, $name, $uncommon),
            self::spoil($random, $random->getInt(0, 5) === 0 ? '' : $abbreviation, $uncommon),
            self::spoil($random, $slug, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 500), $uncommon),
            self::int($random, $random->getInt(0, 6), $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $random->getInt(1, 10), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function invitationItem(Randomizer $random, bool $uncommon): array
    {
        $token = match ($random->getInt(0, 9)) {
            0 => '',
            1 => $random->getBytesFromString('abcdef0123456789', 32),
            2 => $random->getBytesFromString('abcdefABCDEF0123456789-_', 64),
            default => $random->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 64),
        };
        $now = 1_760_000_000;
        $created = $now - $random->getInt(0, 30 * 86_400);
        $expires = $random->getInt(0, 9) === 0 ? 0 : $created + self::pick($random, [86_400, 7 * 86_400, 7 * 86_400, 30 * 86_400, 40 * 86_400]);

        return [
            self::spoil($random, $token, $uncommon),
            self::spoil($random, self::pick($random, self::EMAILS), $uncommon),
            self::spoil($random, self::pick($random, self::ROLES), $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $created, $uncommon),
            self::int($random, $expires, $uncommon),
            self::int($random, $random->getInt(0, 7) === 0 ? $created + 3600 : 0, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : 1, $uncommon),
            self::int($random, $random->getInt(1, 20), $uncommon),
            self::int($random, $random->getInt(0, 12), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function isbndbItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, $random->getInt(0, 9) === 0 ? '' : self::isbn($random), $uncommon),
            self::spoil($random, self::pick($random, self::TITLES), $uncommon),
            self::spoil($random, self::pick($random, self::AUTHORS), $uncommon),
            self::spoil($random, self::pick($random, self::PUBLISHERS), $uncommon),
            self::spoil($random, self::pick($random, self::DATES), $uncommon),
            self::int($random, self::pick($random, [0, 0, 120, 320, 1024]), $uncommon),
            self::int($random, $random->getInt(0, 1), $uncommon),
            self::int($random, self::pick($random, [0, 0, 2019, 2018, 2001, 1990]), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'company' => self::companyItem($random, $uncommon),
                'game' => self::gameItem($random, $uncommon),
                'platform' => self::platformItem($random, $uncommon),
                'invitation' => self::invitationItem($random, $uncommon),
                default => self::isbndbItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $max = PHP_INT_MAX;
        $min = PHP_INT_MIN;
        $nul = "a\0b";
        $token = str_repeat('A', 64);

        return match ($key) {
            'company' => [
                ['Sony Interactive Inc', 'sony-interactive-inc', 1, 0, 3, 392],
                [' Studio  Ltd ', '', 2, 1, 1, 0],
                ['Loop', '', 3, 3, 8, 1000],
                ['Chain A', '', 10, 11, 2, 1],
                ['Chain B', '', 11, 12, 4, 999],
                ['Chain C', '', 12, 13, 0, 0],
                ['Chain D', '', 13, 14, 15, 0],
                ['Chain E', '', 14, 15, 1, 0],
                ['Chain F', '', 15, 16, 1, 0],
                ['Chain G', '', 16, 17, 1, 0],
                ['Chain H', '', 17, 18, 1, 0],
                ['Chain I', '', 18, 19, 1, 0],
                ['Chain J', '', 19, 0, 1, 0],
                ['Duplicate', '', 10, 0, 1, 0],
                ['Inc', 'INC', 20, 999, 16, -1],
                ['Ltd Inc', '', 0, 0, 3, 0],
                ['Sega-Of America GmbH Co Corp', 'sega-of-america-gmbh-co-corp', 21, 0, 2, 840],
                ['Sonyx', '', 22, 0, 2, 0],
                ['Ünïcödé Studio 日本 GmbH', 'nc-d-studio', 23, 0, 0, 0],
                ["  \t\x0c\x0b\0  ", '  ', 24, 0, 0, 0],
                [str_repeat('Studio ', 10_000), '', 25, 0, 0, 0],
                ['Max', 'max', $max, $max, $max, $max],
                ['Min', 'min', $min, $min, $min, $min],
                ['', '', 0, 0, 0, 0],
                [$nul, $nul, 26, 0, 1, 0],
                ["Big\0Studio\0Inc", "big-studio\0", 27, 0, 1, 0],
            ],
            'game' => [
                ['The Legend of Zelda: Breath of the Wild', 'legend of zelda', '130, 41,x', 'Adventure, Role-playing (RPG), Weird', 'co3p2d', 1_488_499_200, 2017, 130, 9_100, 200],
                ['Halo', 'halo', '6', 'Shooter', 'co1', 1_000_000_000, 2001, 6, 8_000, 49],
                ['Halo 2', 'halo', '', '', '', 1_099_000_000, 2004, 0, 0, 0],
                ['Halo', 'Halo 2', '6', '', 'CO1', 1, 1970, 6, 10_000, 4],
                ['DOOM', 'doom', '6', '', '', 4_102_444_799, 2099, 0, 10_001, 5],
                ['DOOM', 'doom', '6', '', '', 4_102_444_800, 2099, 0, -1, 5],
                ['DOOM', 'doom', '1234567,123456', '', str_repeat('a', 33), 951_782_400, 2003, 123456, 5_000, 100],
                ['', 'doom', '', '', '', 0, 0, 0, 0, 0],
                ['doom', '', '', '', '', 0, 0, 0, 0, 0],
                ['a a a b', 'a b', ' , ,', 'rpg,RPG,Rpg', ' co3 ', 978_307_199, 10_000, 0, 1, 1],
                ['Ünïcödé Quest 日本', 'ünïcödé quest', '6', '', '', 978_307_200, 2001, 6, 5_000, 50],
                ['Max', 'max', '9', 'Indie', 'c', $max, $max, $max, $max, $max],
                ['Min', 'min', '9', 'Indie', 'c', $min, $min, $min, $min, $min],
                ['', '', '', '', '', 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, $nul, $nul, 0, 0, 0, 0, 0],
                ["Zelda\0Wild", "zelda\0", "6\0,48", "Indie\0", "co\0", 1_488_499_200, 2017, 6, 1, 1],
            ],
            'platform' => [
                ['PlayStation  4', ' ps4 ', 'ps4', 48, 1, 9],
                ['Arcade', '', 'arcade', 52, 2, 0],
                ['Nintendo Switch', 'Switch', 'switch', 130, 5, 8],
                ['Game', '', 'p-s-p', 1, 5, 0],
                ['Linux', '', '', 3, 4, 0],
                ['Unknown', 'UNK', 'unk', 4, 6, 20],
                ['Unknown', 'unk', 'unk', 4, 3, -5],
                ['Super Nintendo Entertainment System', '', str_repeat('x', 41), 5, 1, 4],
                ['', '', str_repeat('a', 50), 0, 0, 0],
                ['Ünïcödé 日本', 'ü', 'ps-5', 6, 1, 9],
                ['Max', 'max', 'max', $max, $max, $max],
                ['Min', 'min', 'min', $min, $min, $min],
                ['', '', '', 0, 0, 0],
                ['a', 'b', 'c', 1, 1, 0],
                [$nul, $nul, $nul, 1, 1, 0],
                ["a\0b", "ps\x004", "\0", 1, 1, 0],
            ],
            'invitation' => [
                [$token, ' Bob@Example.com', 'user', 1000, 500, 200_000, 0, 1, 7, 1],
                [$token, 'a@b.co', 'user', 1000, 500, 2000, 0, 1, 7, 1],
                ['short', 'x', 'admin', 1000, 500, 2000, 0, 1, 8, 5],
                [$token, 'a@b.co', 'user', 1000, 500, 999, 0, 1, 7, 1],
                [$token, 'a@b.co', 'user', 1000, 500, 2000, 1, 1, 7, 1],
                [$token, 'a@b.co', 'user', 1000, 500, 2000, 0, 0, 7, 1],
                [str_repeat('-', 64), 'a@b.co', 'friend', 1000, 500, 0, 0, 1, 9, 50],
                [$token, 'a@b.co', 'Moderator', 1000, 3000, 2000, 0, 1, 9, -5],
                [$token, 'a@b.co', 'disabled', 1000, 1, 31 * 86_400 + 1, 0, 1, 10, 9],
                [$token, '@b.co', ' ADMIN ', 0, 0, 0, 0, 1, 11, 0],
                [$token, 'a@.co', 'guest', 0, 0, 0, 0, 1, 11, 0],
                [$token, 'a b@c.co', '', 0, 0, 0, 0, 1, 11, 0],
                [$token, 'a@b.', 'user', 0, 0, 0, 0, 1, 11, 0],
                [$token, 'a@bc', 'user', 0, 0, 0, 0, 1, 11, 0],
                [$token, 'Ünïcödé@例え.jp', 'user', 0, 0, 0, 0, 1, 11, 0],
                [$token, "a@b.co\x7f", 'user', $max, $max, $max, 0, $max, $max, $max],
                [$token, 'a@b.co', 'user', $min, $min, $min, $min, $min, $min, $min],
                ['', '', '', 0, 0, 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, 0, 0, 0, 0, 1, 0, 0],
                ["a\0@b.co", "a@b\0.co", "user\0", 0, 0, 0, 0, 1, 0, 0],
            ],
            default => [
                ['0-306-40615-2', 'A Title', ' Doe, Jane | | Smith ', 'Big House Publishing Group, Inc.', '2019-07-04', 320, 1, 2018],
                ['080442957x', 'T', 'A', 'Press', '2019', 1, 0, 2019],
                ['0306406153', '', '', '', '', 0, 0, 0],
                ['978-0-306-40615-7', 'T', 'A', 'Books Inc', '2019-07', 0, 0, 2030],
                ['9790000000001', '', '', '', '', 0, 0, 0],
                ['9780306406158', 'T', '', '', '2019-13', 0, 0, 0],
                ['97803064061570', 'T', '', '', '0999', 0, 0, 0],
                ['X306406152', 'T', '', '', '20190', 0, 0, 0],
                ['9770306406157', 'T', '', '', '2019-07-04T00:00:00', 0, 0, 0],
                ['ISBN: 0 306 40615 2', ' ', '|', 'inc', '2001-02-32', 0, 0, 0],
                ['', '', '', '', '', 0, 0, 0],
                ['Ünïcödé 978030640615７', 'Ünïcödé Bøøk', 'Åuthor', 'Ünïcödé Verlag GmbH', ' 2001-01-01 ', 100, 1, 2001],
                ['0306406152', 'T', 'A', 'P', '2019', $max, $max, $max],
                ['0306406152', 'T', 'A', 'P', '2019', $min, $min, $min],
                [$nul, $nul, $nul, $nul, $nul, 0, 0, 0],
                ["0306\x00406152", "Title\0", "Doe\0|Smith", "Big\0Press", "2019\0", 1, 1, 2019],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w67_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W67_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W67_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w674_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W67_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W67_TRAILER_WORDS'));
        foreach (['usesW67RustFfi', 'withW67RustFfi', 'enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w67RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW67RustFfi());
        self::assertFalse($class::withW67RustFfi(false, static fn (): bool => $class::usesW67RustFfi()));
        self::assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW67RustFfi(), 'withW67RustFfi restores the default');

        $class::enableW67RustFfi();
        self::assertTrue($class::usesW67RustFfi());
        $class::disableW67RustFfi();
        self::assertFalse($class::usesW67RustFfi());
        self::assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()));
        self::assertFalse($class::usesW67RustFfi(), 'withW67RustFfi restores an explicit override');
        $class::resetW67RustFfi();
        self::assertSame($constant->getValue(), $class::usesW67RustFfi());

        $nested = $class::withW67RustFfi(false, static fn (): array => [
            $class::usesW67RustFfi(),
            $class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()),
            $class::usesW67RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW67RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW67RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w67', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW67RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW67RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        Company::withW67RustFfi(false, static function (): void {
            foreach ([Game::class, Platform::class, InvitationService::class, IsbnDbService::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW67RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W67_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW67RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [Company::usesRustFfi(), Company::usesW48CompanyRoleRustFfi(), Company::usesW54RustFfi(), Game::usesRustFfi(),
            Game::usesW48GameQualityRustFfi(), Game::usesW54RustFfi(), Platform::usesRustFfi(), Platform::usesW48PlatformFamilyRustFfi(), Platform::usesW54RustFfi(),
            InvitationService::usesRustFfi(), InvitationService::usesW50RustFfi(), IsbnDbService::usesW394RustFfi(), IsbnDbService::usesW51RustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW67RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                });
            }
        }
        self::assertSame($before, $snapshot());
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
            [array_map(static fn (mixed $v): mixed => \is_string($v) ? null : $v, $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (float) $v : $v, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W67', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_use_the_twin_frame(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $edges = self::edgeItems($key);
        $nul = array_values(array_filter($edges, static fn (array $item): bool => str_contains(implode('', array_filter($item, 'is_string')), "\0")));
        self::assertNotSame([], $nul);
        self::assertParity($class, $method, $words, $trailer, $nul, $key.' NUL-bearing values');
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': the 0x01 twin frames NUL-bearing values');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::HELPERS as $key => [$class, $method]) {
            $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 9, true);
            $php = $class::{$method.'Php'}($items);
            self::state($class, 'ffi', false);
            self::assertSame($php, $class::{$method.'Native'}($items), $key);
            self::assertSame(0, self::state($class, 'misses'), $key.': a missing library is not a declined call');
            $class::resetW67RustFfi();
        }
    }

    public function test_hand_checked_rows(): void
    {
        $company = Company::w67IgdbCompanyPlanBatchPhp([
            ['Sony Interactive Inc', 'sony-interactive-inc', 1, 0, 3, 392],
            [' Studio  Ltd ', '', 2, 1, 1, 0],
            ['Loop', '', 3, 3, 8, 1000],
        ]);
        self::assertSame([3 | 11 << 3 | 1 << 8 | 1 << 9 | 1 << 10 | 1 << 11 | 1 << 12 | 20 << 20 | 20 << 36 | 16 << 52, self::fnv('sony-interactive'), -1,
            self::fnv('Sony Interactive Inc')], \array_slice($company, 0, 4));
        self::assertSame([1 | 1 << 8 | 1 << 11 | 1 << 12 | 1 << 13 | 1 << 16 | 10 << 20 | 10 << 36 | 6 << 52, self::fnv('studio'), 0, self::fnv('Studio Ltd')],
            \array_slice($company, 4, 4));
        self::assertSame([4 | 1 << 11 | 1 << 12 | 3 << 13 | 4 << 20 | 4 << 36 | 4 << 52, self::fnv('loop'), -1, self::fnv('Loop')], \array_slice($company, 8, 4));
        self::assertSame([3, 2, 1, 1, 1, 0, 1, 1, 1], \array_slice($company, 12, 9));

        $game = Game::w67IgdbGamePlanBatchPhp([
            ['The Legend of Zelda: Breath of the Wild', 'legend of zelda', '130, 41,x', 'Adventure, Role-playing (RPG), Weird', 'co3p2d', 1_488_499_200, 2017, 130, 9_100, 200],
        ]);
        $similarity = intdiv(3 * 1000, 6);
        self::assertSame([4 | 2 << 3 | 1 << 6 | 1 << 7 | 3 << 9 | $similarity << 13 | 2017 << 24 | 9_100 << 36 | 8 << 50 | 3 << 58, $similarity + 220,
            129 | 1 << 16 | 2 << 24 | 1 << 32, self::fnv('the legend of zelda breath of the wild')], \array_slice($game, 0, 4));
        self::assertSame([1, 0, 0, 0, 1, 0, 0, 1], \array_slice($game, 4, 8));

        $platform = Platform::w67IgdbPlatformPlanBatchPhp([
            ['PlayStation  4', ' ps4 ', 'ps4', 48, 1, 9],
            ['Arcade', '', 'arcade', 52, 2, 0],
        ]);
        self::assertSame([1 | 1 << 2 | 8 << 5 | 3 << 9 | 2 << 13 | 1 << 15 | 3 << 16 | 13 << 24, 1180, self::fnv('PS4')], \array_slice($platform, 0, 3));
        self::assertSame([1 << 15 | 6 << 16 | 6 << 24, 1999, self::fnv('Arcade')], \array_slice($platform, 3, 3));
        self::assertSame([2, 1, 0, 0, 0, 0, 0, 1, 0, 1], \array_slice($platform, 6, 10));

        $token = str_repeat('A', 64);
        $invitation = InvitationService::w67InvitationServicePlanBatchPhp([
            [$token, ' Bob@Example.com', 'user', 1000, 500, 200_000, 0, 1, 7, 1],
            [$token, 'a@b.co', 'user', 1000, 500, 2000, 0, 1, 7, 1],
            ['short', 'x', 'admin', 1000, 500, 2000, 0, 1, 8, 5],
        ]);
        self::assertSame([1 | 1 << 3 | 3 << 5 | 4 << 9 | 1 << 12 | 1 << 13 | 55 << 14 | 2 << 30 | 2 << 46, self::fnv('bob@example.com'), 0], \array_slice($invitation, 0, 3));
        self::assertSame([6 | 1 << 3 | 2 << 5 | 4 << 9 | 1 << 12 | 2 << 46, self::fnv('a@b.co'), 1], \array_slice($invitation, 3, 3));
        self::assertSame([5 | 2 << 3 | 2 << 5 | 1 << 9, self::fnv('x'), -1], \array_slice($invitation, 6, 3));
        self::assertSame([3, 1, 0, 0, 0, 1, 1, 2, 1], \array_slice($invitation, 9, 9));

        $isbndb = IsbnDbService::w67IsbnDbServicePlanBatchPhp([
            ['0-306-40615-2', 'A Title', ' Doe, Jane | | Smith ', 'Big House Publishing Group, Inc.', '2019-07-04', 320, 1, 2018],
        ]);
        self::assertSame([1 | 2 << 3 | 3 << 7 | 2019 << 10 | 127 << 24 | 100 << 31 | 4 << 38 | 2 << 41 | 1 << 49 | 1 << 50 | 140 << 51, 9_780_306_406_157,
            self::fnv('big house'), self::fnv('doe, jane')], \array_slice($isbndb, 0, 4));
        self::assertSame([1, 1, 0, 0, 0, 0, 1, 1, 1], \array_slice($isbndb, 4, 9));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('W67_BENCH_ROUNDS') ?: 1000));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            // A default-enabled helper below the gate fails; one below it must retain PHP.
            if ($class::usesW67RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW67.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
