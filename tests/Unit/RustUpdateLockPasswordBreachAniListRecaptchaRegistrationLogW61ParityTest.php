<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NntmuxUpdateLock;
use App\Services\PasswordBreachService;
use App\Services\PopulateAniListService;
use App\Services\RecaptchaService;
use App\Services\RegistrationFailureLogService;
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
 * Wave 61.4 batch helpers: NntmuxUpdateLock plan rows (command, hostname, pid, start, ttl and
 * contention/expiry status), PasswordBreachService k-anonymity plan rows (SHA-1 prefix/suffix
 * validation, range line matching and breach counts), PopulateAniListService population plan
 * rows (title, status, format and id normalization with a completeness score),
 * RecaptchaService verification plan rows (token, secret, address, action, error code and
 * score checks) and RegistrationFailureLogService failure plan rows (reason, address,
 * username, email, agent and invite classification with an abuse score). Each export takes a
 * field-major blob plus packed int64 words; Rust writes fixed-width int64 rows and a batch
 * trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call
 * count in the function static of w61RustFfiState().
 *
 * The paired benchmark (NNTMUX_W614_BENCH=1) times the PHP and native batch methods, including
 * validation, packing and decoding, over interleaved rounds of 320 records in random order per
 * round (fixed seed). Rounds per workload: NNTMUX_W614_BENCH_ROUNDS (default 600, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Four runs at 569b6f3a2 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release build,
 * one at 300 and three at 600 rounds per workload after 10 warmups, load average 7-8), minimum
 * speedup per gate (common mean/p99, uncommon mean/p99): updateLockPlanRowBatch 2.25/2.07,
 * 1.92/1.83; breachCheckPlanRowBatch 2.49/2.26, 2.18/2.11; aniListPopulationPlanRowBatch
 * 1.75/1.60, 1.63/1.66; verificationPlanRowBatch 1.14/1.19, 1.07/1.14 (best 1.39);
 * failureLogPlanRowBatch 2.48/2.34, 2.07/2.04. Common batches hold realistic commands, hosts,
 * SHA-1 range bodies (8-41 lines), AniList titles, 300-600 byte tokens, reasons, addresses and
 * agents; uncommon batches mix mutated, truncated, random-byte (often NUL-bearing, so the 0x01
 * twin is sent) and empty strings with extreme ints. The update lock, breach, AniList and
 * registration helpers default to Rust; reCAPTCHA defaults to PHP because packing its long
 * tokens across FFI costs about as much as PHP's C-level strspn() validation.
 */
final class RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words, CDEF constant]. */
    private const array HELPERS = [
        'lock' => [NntmuxUpdateLock::class, 'updateLockPlanRowBatch', 6, 8, 'W61_UPDATE_LOCK_FFI_CDEF'],
        'breach' => [PasswordBreachService::class, 'breachCheckPlanRowBatch', 5, 8, 'W61_PASSWORD_BREACH_FFI_CDEF'],
        'anilist' => [PopulateAniListService::class, 'aniListPopulationPlanRowBatch', 7, 8, 'W61_ANILIST_FFI_CDEF'],
        'recaptcha' => [RecaptchaService::class, 'verificationPlanRowBatch', 5, 7, 'W61_RECAPTCHA_FFI_CDEF'],
        'registration' => [RegistrationFailureLogService::class, 'failureLogPlanRowBatch', 5, 8, 'W61_REGISTRATION_FAILURE_FFI_CDEF'],
    ];

    /** W61_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        NntmuxUpdateLock::class => true,
        PasswordBreachService::class => true,
        PopulateAniListService::class => true,
        RecaptchaService::class => false,
        RegistrationFailureLogService::class => true,
    ];

    private const int SEED = 6142026;

    private const int NOW = 1_793_000_000;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', ':', '::', '.', '..', '-', '--', ' --', '_', '/', ',', '@', 'A', 'Z', 'z', '0', '9', 'f', 'F'];

    private const array COMMANDS = ['nntmux:all', 'nntmux:all --skip-git --skip-npm', 'nntmux:git --branch=master', 'nntmux:git', 'migrate --force',
        'optimize:clear', 'tmux:drain --timeout=900', 'nntmux:all --skip-composer', 'queue:restart', ''];

    private const array HOSTS = ['m5.local', 'nntmux-web-1', 'indexer.internal.example.com', 'localhost', '', 'Mac-Studio.local', '192.168.1.113',
        ' scheduler ', 'web-7f9c8d6b5-x2k4p'];

    private const array ROMAJI = ['Shingeki no Kyojin', 'Kimetsu no Yaiba', 'Sousou no Frieren', 'Boku no Hero Academia', 'Cowboy Bebop',
        'Fullmetal Alchemist: Brotherhood', 'Spy x Family', 'Jujutsu Kaisen 2nd Season', 'Kono Subarashii Sekai ni Shukufuku wo!', ''];

    private const array ENGLISH = ['Attack on Titan', 'Demon Slayer: Kimetsu no Yaiba', 'Frieren: Beyond Journey’s End', 'My Hero Academia',
        'Cowboy Bebop', 'Fullmetal Alchemist: Brotherhood', 'SPY x FAMILY', '', '', 'KonoSuba: God’s Blessing on This Wonderful World!'];

    private const array NATIVE = ['進撃の巨人', '鬼滅の刃', '葬送のフリーレン', '僕のヒーローアカデミア', 'カウボーイビバップ', '鋼の錬金術師', 'SPY×FAMILY', '', 'Cowboy Bebop'];

    private const array MEDIA_STATUSES = ['FINISHED', 'RELEASING', 'NOT_YET_RELEASED', 'CANCELLED', 'HIATUS', 'finished', ' Releasing ', ''];

    private const array FORMATS = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC', 'tv', 'Movie ', ''];

    private const array ACTIONS = ['login', 'register', 'homepage', 'submit/form', 'contact_us', ''];

    private const array ERRORS = ['', '', '', '', 'timeout-or-duplicate', 'invalid-input-response', 'missing-input-secret,invalid-input-secret',
        'bad-request', ' internal-error , browser-error', 'missing-input-response'];

    private const array REASONS = ['captcha_failed', 'Captcha failed', 'invalid-invite', 'email_taken', 'Username taken!', 'disposable_email',
        'registration closed', 'proxy_detected', 'RATE_LIMITED', 'validation_failed', 'password_breached', 'unknown failure', ''];

    private const array USERNAMES = ['alice', 'bob_99', 'Admin', 'sysadmin2', '12345678', 'x', 'nntmux.user', 'a-very-long-username-that-overflows-32', '',
        ' carol ', 'dave!'];

    private const array EMAILS = ['alice@example.com', 'BOB@Example.COM', 'spam@mailinator.com', 'temp@yopmail.com', 'user@sub.domain.co.uk', 'bad@', '@nope.com',
        'two@@at.com', 'user@localhost', 'a b@example.com', '', ' carol@example.org '];

    private const array AGENTS = ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'curl/8.7.1',
        'python-requests/2.32.3', 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'Go-http-client/1.1', 'okhttp/4.12.0', '', 'SomeClient/1.0'];

    private const array INVITES = ['a1b2c3d4e5f6', '0f9e8d7c-6b5a-4321-8765-0123456789ab', 'short', '', 'bad invite!', 'ABCDEFGH'];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
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
        return (new ReflectionMethod($class, 'w61RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $constant = null;
        foreach (self::HELPERS as [$helperClass, , , , $name]) {
            if ($helperClass === $class) {
                $constant = $name;
            }
        }
        self::assertIsString($constant);
        $cdef = (new ReflectionClass($class))->getConstant($constant);
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w61', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW61RustFfi($native, static fn (): array => $class::{$method}($items));
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

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 99, 100, 120, 121, 300, 301, 600, 1940, 2100, 2101, 3600, 3601, 10_000, 10_001, 86_400,
            604_800, 604_801, 999_999, 1_000_000, 1_000_001, 4_194_304, 4_194_305, 2_147_483_647, 2_147_483_648, self::NOW, self::NOW + 1,
            self::NOW - 1, (1 << 40) - 1, 1 << 40, (1 << 40) + 1, 1 << 31, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1,
            $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000), $random->getInt(0, 1 << 41)]);
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

    private static function ip(Randomizer $random): string
    {
        return match ($random->getInt(0, 6)) {
            0, 1, 2 => implode('.', [$random->getInt(1, 255), $random->getInt(0, 255), $random->getInt(0, 255), $random->getInt(0, 255)]),
            3 => self::pick($random, ['10.0.0.7', '192.168.1.20', '172.20.3.4', '127.0.0.1', '100.72.1.1', '169.254.9.9']),
            4 => \sprintf('2001:db8:%x::%x', $random->getInt(0, 0xFFFF), $random->getInt(1, 0xFFFF)),
            5 => self::pick($random, ['::1', 'fe80::1', 'fd00::abcd']),
            default => '',
        };
    }

    /** @return list<mixed> */
    private static function lockItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW + $random->getInt(-86_400, 86_400);
        $pid = $random->getInt(1, 99_999);
        $started = match ($random->getInt(0, 9)) {
            0 => $now + $random->getInt(1, 600),
            1 => $now - $random->getInt(3000, 90_000),
            default => $now - $random->getInt(0, 3600),
        };

        return [
            self::spoil($random, self::pick($random, self::COMMANDS), $uncommon),
            self::spoil($random, self::pick($random, self::HOSTS), $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? self::pick($random, [0, -1, 5_000_000]) : $pid, $uncommon),
            self::int($random, $random->getInt(0, 12) === 0 ? 0 : $started, $uncommon),
            self::int($random, self::pick($random, [0, 0, 3600, 600, 7200, 86_400, 604_800, 604_801, -1]), $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? $pid : $random->getInt(1, 99_999), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function breachItem(Randomizer $random, bool $uncommon): array
    {
        $hash = sha1($random->getBytes(12));
        $hash = $random->getInt(0, 1) === 0 ? strtoupper($hash) : $hash;
        $prefix = $random->getInt(0, 9) === 0 ? strtoupper(sha1($random->getBytes(4))) : substr($hash, 0, 5);
        $prefix = substr($prefix, 0, 5);
        $lines = [];
        for ($i = $random->getInt(8, 40); $i > 0; $i--) {
            $lines[] = strtoupper(substr(sha1($random->getBytes(8)), 0, 35)).':'.($random->getInt(0, 7) === 0 ? 0 : $random->getInt(1, 50_000));
        }
        if ($random->getInt(0, 2) === 0) {
            array_splice($lines, $random->getInt(0, \count($lines)), 0, [strtoupper(substr($hash, 5)).':'.$random->getInt(0, 9_000_000)]);
        }
        $body = implode($random->getInt(0, 3) === 0 ? "\n" : "\r\n", $lines);
        if ($uncommon && $random->getInt(0, 2) === 0) {
            $body = self::mutate($random, $body);
        }

        return [
            self::spoil($random, $hash, $uncommon),
            self::spoil($random, $prefix, $uncommon),
            $uncommon && $random->getInt(0, 9) === 0 ? self::bytes($random, $random->getInt(0, 200)) : $body,
            self::int($random, self::pick($random, [0, 0, 1, 10, 100]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function anilistItem(Randomizer $random, bool $uncommon): array
    {
        $title = static fn (string $value): string => $random->getInt(0, 7) === 0 ? ' '.$value."\t" : $value;

        return [
            self::spoil($random, $title(self::pick($random, self::ROMAJI)), $uncommon),
            self::spoil($random, $title(self::pick($random, self::ENGLISH)), $uncommon),
            self::spoil($random, $title(self::pick($random, self::NATIVE)), $uncommon),
            self::spoil($random, self::pick($random, self::MEDIA_STATUSES), $uncommon),
            self::spoil($random, self::pick($random, self::FORMATS), $uncommon),
            self::int($random, $random->getInt(0, 15) === 0 ? 0 : $random->getInt(1, 200_000), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 60_000), $uncommon),
            self::int($random, $random->getInt(0, 1100), $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $random->getInt(1960, 2027), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function recaptchaItem(Randomizer $random, bool $uncommon): array
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        $action = self::pick($random, self::ACTIONS);
        $now = self::NOW + $random->getInt(-86_400, 86_400);

        return [
            self::spoil($random, $random->getInt(0, 20) === 0 ? '' : self::bytes($random, $random->getInt(300, 600), $alphabet), $uncommon),
            self::spoil($random, $random->getInt(0, 30) === 0 ? '' : self::bytes($random, 40, $alphabet), $uncommon),
            self::spoil($random, self::ip($random), $uncommon),
            self::spoil($random, $action, $uncommon),
            self::spoil($random, $random->getInt(0, 4) === 0 ? self::pick($random, self::ACTIONS) : $action, $uncommon),
            self::spoil($random, self::pick($random, self::ERRORS), $uncommon),
            self::int($random, $random->getInt(0, 6) === 0 ? -1 : $random->getInt(0, 1_000_000), $uncommon),
            self::int($random, self::pick($random, [500_000, 300_000, 700_000, 0]), $uncommon),
            self::int($random, $random->getInt(0, 12) === 0 ? 0 : $now - $random->getInt(-5, 200), $uncommon),
            self::int($random, $now, $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function registrationItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW + $random->getInt(-86_400, 86_400);
        $agent = self::pick($random, self::AGENTS);
        if ($random->getInt(0, 9) === 0) {
            $agent = str_repeat($agent, $random->getInt(2, 4));
        }

        return [
            self::spoil($random, self::pick($random, self::REASONS), $uncommon),
            self::spoil($random, self::ip($random), $uncommon),
            self::spoil($random, self::pick($random, self::USERNAMES), $uncommon),
            self::spoil($random, self::pick($random, self::EMAILS), $uncommon),
            self::spoil($random, $agent, $uncommon),
            self::spoil($random, self::pick($random, self::INVITES), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 25), $uncommon),
            self::int($random, $now - $random->getInt(-60, 7200), $uncommon),
            self::int($random, $now, $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'lock' => self::lockItem($random, $uncommon),
                'breach' => self::breachItem($random, $uncommon),
                'anilist' => self::anilistItem($random, $uncommon),
                'recaptcha' => self::recaptchaItem($random, $uncommon),
                default => self::registrationItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $now = self::NOW;
        $nul = "a\0b";
        $hash = strtoupper(sha1('password'));
        $suffix = substr($hash, 5);
        $token = str_repeat('Ab0_-', 40);
        $secret = str_repeat('s', 40);

        return match ($key) {
            'lock' => [
                ['nntmux:all', 'm5.local', 42, $now - 10, 0, $now, 42],
                ['nntmux:all --skip-git', 'M5.LOCAL', 42, $now - 10, 0, $now, 43],
                ['nntmux:git', '', 42, $now - 3600, 0, $now, 1],
                ['nntmux:git', '', 42, $now - 3601, 0, $now, 1],
                ['migrate', 'a', 42, $now - 3240, 0, $now, 1],
                ['optimize:clear', '-a', 1, $now + 300, 1, $now, 1],
                ['x', 'a-', 1, $now + 301, 1, $now, 1],
                ['Nntmux:all', '.a', 4_194_304, $now + 1, 604_800, $now, 4_194_304],
                [' nntmux:all', 'a..b', 4_194_305, $now, 604_801, $now, 1],
                [str_repeat('a', 64), str_repeat('h', 253), 7, 1, 1, 1 << 40, 7],
                [str_repeat('a', 65), str_repeat('h', 254), 7, 1 << 40, -1, PHP_INT_MAX, 7],
                ['a --b --c --d', " \t\0host\0", 7, (1 << 40) + 1, 0, $now, 7],
                [$nul, $nul, 0, $now, 0, $now, 0],
                ['', '', -1, 0, 0, 0, -1],
                ['nntmux:all', 'héllo.local', PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN],
                ['nntmux:all', 'web', 9, 1, 3600, 0, 9],
            ],
            'breach' => [
                [$hash, substr($hash, 0, 5), $suffix.":3861493\r\n".str_repeat('0', 35).':0', 0],
                [strtolower($hash), substr($hash, 0, 5), strtolower($suffix).':10', 10],
                [$hash, strtolower(substr($hash, 0, 5)), $suffix.':11', 10],
                [$hash, substr($hash, 0, 5), $suffix.':0', 0],
                [$hash, substr($hash, 0, 5), "\n\n  \n", 0],
                [$hash, 'ABCDE', $suffix.':5', 0],
                [$hash, 'ABCD', $suffix.':5', 0],
                [$hash, '', $suffix.':5', 0],
                [substr($hash, 1), substr($hash, 0, 5), $suffix.':5', 0],
                [str_repeat('G', 40), 'GGGGG', '', 0],
                ['', '', '', 0],
                [$hash, substr($hash, 0, 5), $suffix.':12345678901', -5],
                [$hash, substr($hash, 0, 5), $suffix.':1:2', PHP_INT_MIN],
                [$hash, substr($hash, 0, 5), $suffix.':9999999999'."\n".$suffix.':1', PHP_INT_MAX],
                [$hash, substr($hash, 0, 5), ' '.$suffix.":7\0\n".substr($suffix, 1).':1', 0],
                [$nul, $nul, $nul, 0],
                [$hash, substr($hash, 0, 5), ':5'."\n".$suffix."\n".$suffix.':', 0],
            ],
            'anilist' => [
                ['Cowboy Bebop', 'Cowboy Bebop', 'カウボーイビバップ', 'FINISHED', 'TV', 1, 1, 26, 1998],
                ['Cowboy Bebop', 'COWBOY BEBOP', '', 'finished', 'movie', 5, 0, 1, 2001],
                ['', '', '', 'RELEASING', 'TV', 9, 0, 0, 0],
                ['Kimi', '', '', 'HIATUS', 'MOVIE', 9, 0, 2, 0],
                ['', "\xff", '', 'CANCELLED', 'OVA', 9, 0, 0, 0],
                ['a', '', '', 'BOGUS', 'TV', 9, 0, 0, 0],
                ['a', '', '', '', 'NOPE', 2_147_483_647, 2_147_483_647, 10_000, 2100],
                ['a', '', '', '', '', 2_147_483_648, 2_147_483_648, 10_001, 2101],
                ['a', '', '', 'NOT_YET_RELEASED', 'MUSIC', 1, -1, -1, 1939],
                ["  ti\x01tle ", '', '', 'FINISHED', 'SPECIAL', 1, 0, 0, 1940],
                ['', '', 'Native ASCII', ' hiatus ', ' ona ', 1, 0, 0, 0],
                ['', '', "\xed\xa0\x80", 'FINISHED', 'TV_SHORT', 1, 0, 0, 0],
                [$nul, $nul, $nul, $nul, $nul, 0, 0, 0, 0],
                ['', '', '', '', '', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['x', 'y', 'z', 'FINISHED', 'TV', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
            ],
            'recaptcha' => [
                [$token, $secret, '10.1.2.3', 'login', 'login', '', 900_000, 500_000, $now - 10, $now],
                [$token, $secret, '10.1.2.3', 'login', 'register', '', 900_000, 500_000, $now - 10, $now],
                [$token, $secret, '', 'login', '', '', 100_000, 500_000, $now - 120, $now],
                [$token, $secret, '::1', '', '', '', -1, 500_000, $now - 121, $now],
                [$token, $secret, '1.2.3', 'a/b_c', '', '', 1_000_001, 0, $now + 1, $now],
                [$token, $secret, 'g::1', str_repeat('a', 101), '', '', -2, -5, 0, $now],
                [$token, $secret, '', 'login', 'login', 'missing-input-secret', 900_000, 500_000, $now, $now],
                [$token, $secret, '', 'login', 'login', 'timeout-or-duplicate,invalid-input-response', 900_000, 500_000, $now, $now],
                [$token, $secret, '', 'login', 'login', ' , ,', 900_000, 500_000, $now, $now],
                [$token, $secret, '', 'login', 'login', 'bad-request,internal-error,x', 0, 2_000_000, $now, $now],
                [str_repeat('a', 19), $secret, '', '', '', '', -1, 0, 0, 0],
                [str_repeat('a', 20), str_repeat('s', 39), '', '', '', '', -1, 0, 0, 0],
                [str_repeat('a', 4096), $secret, '', '', '', '', -1, 0, 0, 0],
                [str_repeat('a', 4097), $secret, '', '', '', '', -1, 0, 0, 0],
                ['', '', '', '', '', '', PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX],
                [$token.'!', $secret, '1.2.3.4', 'login', 'login', '', 0, 0, PHP_INT_MAX, PHP_INT_MAX],
                [$nul, $nul, $nul, $nul, $nul, $nul, 0, 0, 0, 0],
            ],
            default => [
                ['captcha_failed', '10.0.0.1', 'alice', 'alice@example.com', self::AGENTS[0], '', 0, $now - 10, $now],
                ['  Captcha -- FAILED!! ', '172.16.0.1', 'Admin', 'x@mailinator.com', 'curl/8', 'a1b2c3d4', 20, $now, $now],
                ['proxy_detected', 'not-an-ip', 'ab', 'bad@', 'Googlebot', 'short', 5, $now - 3600, $now],
                ['rate_limited', '100.64.0.1', '12345', 'a@b.co', '', 'bad invite!', PHP_INT_MAX, $now - 3601, $now],
                ['disposable_email', '192.169.1.1', str_repeat('u', 32), 'temp@YOPMAIL.COM', 'Mozilla/5.0 '.str_repeat('x', 300), '', -5, $now + 1, $now],
                ['validation_failed', '169.254.1.1', str_repeat('u', 33), str_repeat('l', 64).'@example.com', "Mozilla/5.0\x01", str_repeat('A', 64), 0, 0, 0],
                ['x', '127.0.0.1', 'sysadmin', str_repeat('l', 65).'@example.com', 'Java/17', str_repeat('A', 65), 0, PHP_INT_MIN, PHP_INT_MAX],
                [str_repeat('reason_', 6), '172.32.0.1', '', 'a@-b.com', 'BOT', '', 1, PHP_INT_MAX, PHP_INT_MIN],
                ['registration_closed', '::1', '.', 'a@b..com', 'mozilla/', '', 0, $now, $now],
                ['password breached', 'fe80::1', 'abc', 'a@.com', 'x', '', 0, $now, $now],
                ['email taken', '01.2.3.4', 'abc', 'a@b.c.', 'x', '', 0, $now, $now],
                ['', '', '', '', '', '', 0, 0, 0],
                [$nul, $nul, $nul, $nul, $nul, $nul, 0, 0, 0],
                ['é', '256.1.1.1', 'é', 'é@é.com', 'é', 'é', 0, 0, 0],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w61_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W61_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant(self::HELPERS[$key][4]);
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertStringContainsString('nntmux_w614_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W61_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W61_TRAILER_WORDS'));
        foreach (['usesW61RustFfi', 'withW61RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w61RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW61RustFfi());
        self::assertFalse($class::withW61RustFfi(false, static fn (): bool => $class::usesW61RustFfi()));
        self::assertTrue($class::withW61RustFfi(true, static fn (): bool => $class::usesW61RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW61RustFfi(), 'withW61RustFfi restores the default');

        $nested = $class::withW61RustFfi(false, static fn (): array => [
            $class::usesW61RustFfi(),
            $class::withW61RustFfi(true, static fn (): bool => $class::usesW61RustFfi()),
            $class::usesW61RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW61RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW61RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w61', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW61RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW61RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        NntmuxUpdateLock::withW61RustFfi(true, static function (): void {
            foreach ([PasswordBreachService::class, PopulateAniListService::class, RecaptchaService::class, RegistrationFailureLogService::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W61_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW61RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [NntmuxUpdateLock::usesRustFfi(), NntmuxUpdateLock::usesW48LockRustFfi(), PasswordBreachService::usesRustFfi(),
            PasswordBreachService::usesW48BreachRangeRustFfi(), PopulateAniListService::usesW47MediaRustFfi(), RecaptchaService::usesRustFfi(),
            RecaptchaService::usesW48AssessmentRustFfi(), RegistrationFailureLogService::usesRustFfi(), RegistrationFailureLogService::usesW48FailureDigestRustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW61RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertSame('Waiting for nntmux:all (1.5s elapsed)...', NntmuxUpdateLock::formatWaitingMessagePhp('nntmux:all', 1.5));
                    self::assertSame(0, PasswordBreachService::normalizeThresholdPhp(-3));
                    self::assertTrue(RecaptchaService::verificationSucceededPhp(['success' => true]));
                    self::assertSame(1, NntmuxUpdateLock::evaluateLockBatchPhp(['{"pid":5,"started_at":10}'], 5, 20)['own']);
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
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W61', $e->getMessage());
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
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    public function test_hand_checked_rows(): void
    {
        $now = self::NOW;
        $lock = NntmuxUpdateLock::updateLockPlanRowBatchPhp([
            ['nntmux:all --skip-git', ' M5.Local ', 42, $now - 100, 0, $now, 7],
            ['migrate', '', 42, $now - 3500, 0, $now, 42],
            ['bad name!', '-x', 0, $now, 0, $now, 0],
        ]);
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', 'm5.local', true));
        self::assertSame([6 | 1 << 4 | 1 << 8 | 2 << 12 | 1 << 16 | 10 << 24, 42, 100, 3500, $now + 3500, $hash[1]], \array_slice($lock, 0, 6));
        self::assertSame([5 | 3 << 4 | 4 << 12 | 7 << 24, 42, 3500, 100, $now + 100, 0], \array_slice($lock, 6, 6));
        self::assertSame(1 | 14 << 4 | 2 << 8 | 3 << 24, $lock[12]);
        self::assertSame([-1, 0, -1, -1], \array_slice($lock, 13, 4));
        self::assertSame([1, 0, 0, 1, 1, 3500, 3500], \array_slice($lock, 18, 7));

        $hash = strtoupper(sha1('password'));
        $breach = PasswordBreachService::breachCheckPlanRowBatchPhp([
            [strtolower($hash), substr($hash, 0, 5), "junk\r\n".substr($hash, 5).":42\r\n".str_repeat('A', 35).':0', 0],
            [$hash, substr($hash, 0, 5), str_repeat('A', 35).':3', 0],
            ['xyz', '', '', 0],
        ]);
        self::assertSame([1 | 1 << 4 | 1 << 6 | 256 | 1024, 42, (int) hexdec(substr($hash, 0, 5)), 2 | 1 << 20 | 1 << 40, 83], \array_slice($breach, 0, 5));
        self::assertSame([0 | 1 << 4 | 1 << 6, 0, (int) hexdec(substr($hash, 0, 5)), 1, 37], \array_slice($breach, 5, 5));
        self::assertSame([2 | 2 << 4, 0, -1, 0, 0], \array_slice($breach, 10, 5));
        self::assertSame([1, 1, 1, 0, 0, 42, 42], \array_slice($breach, 15, 7));

        $anilist = PopulateAniListService::aniListPopulationPlanRowBatchPhp([
            ['Cowboy Bebop', ' cowboy bebop ', 'カウボーイビバップ', 'finished', 'TV', 1, 1, 26, 1998],
            ['', '', '', 'BOGUS', '', 0, 0, 0, 0],
        ]);
        self::assertSame([0 | 1 << 4 | 1 << 8 | 1 << 12 | (1 | 2 | 4 | 8 | 16) << 16, 1, 1, 26, 1998, 12, 100], \array_slice($anilist, 0, 7));
        self::assertSame([1 | 15 << 8, -1, 0, 0, 0, 0, 0], \array_slice($anilist, 7, 7));
        self::assertSame([1, 1, 0, 0, 0, 100, 12], \array_slice($anilist, 14, 7));

        $token = str_repeat('t', 30);
        $secret = str_repeat('s', 40);
        $recaptcha = RecaptchaService::verificationPlanRowBatchPhp([
            [$token, $secret, '10.1.2.3', 'login', 'login', '', 900_000, 500_000, $now - 5, $now],
            [$token, $secret, '', 'login', '', 'timeout-or-duplicate, nope', 900_000, 500_000, $now - 5, $now],
            [$token, $secret, '::1', 'login', '', '', 100_000, 500_000, $now - 5, $now],
        ]);
        self::assertSame([0 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 1 << 15 | 1 << 32, 900_000, 5, 30, 10 << 16 | 1 << 8 | 2], \array_slice($recaptcha, 0, 5));
        self::assertSame([3 | 1 << 4 | 1 << 6 | 1 << 10 | 3 << 12 | (32 | 128) << 16 | 2 << 24 | 1 << 32, 900_000, 5, 30, -1], \array_slice($recaptcha, 5, 5));
        self::assertSame([6 | 1 << 4 | 1 << 6 | 2 << 8 | 1 << 10 | 1 << 32, 100_000, 5, 30, 2], \array_slice($recaptcha, 10, 5));
        self::assertSame([1, 2, 1, 0, 1, 0], \array_slice($recaptcha, 15, 6));

        $registration = RegistrationFailureLogService::failureLogPlanRowBatchPhp([
            ['Captcha failed', '192.168.1.5', 'alice', 'Alice@Example.com', 'Mozilla/5.0 Firefox', '', 0, $now - 60, $now],
            ['disposable email', 'nope', 'SuperAdmin', 'x@mailinator.com', 'curl/8.7.1', 'bad!', 10, $now - 7200, $now],
        ]);
        self::assertSame([1 | 1 << 4 | 1 << 6 | 1 << 8 | 1 << 10 | 3 << 12 | 1 << 18 | 32 << 20, 15, 60, 192 << 16 | 168 << 8 | 1, 0], \array_slice($registration, 0, 5));
        self::assertSame([5 | 3 << 4 | 1 << 8 | 1 << 10 | 2 << 12 | 2 << 16 | 3 << 18 | (1 | 4) << 20, 100, 7200, -1, 10], \array_slice($registration, 5, 5));
        self::assertSame([1, 0, 1, 1, 1, 1, 115], \array_slice($registration, 10, 7));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W614_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W614_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W614_BENCH_ROUNDS') ?: 600));
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
            if ($class::usesW61RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW61.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
